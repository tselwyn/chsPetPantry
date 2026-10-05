<?php
declare(strict_types=1);

namespace Pfpms\Mail;

use PHPMailer\PHPMailer\PHPMailer;
use Pfpms\Clock;
use Pfpms\Config;
use Pfpms\Db;
use Pfpms\Http\ErrorHandler;
use Pfpms\Security\Crypto;
use RuntimeException;
use Throwable;

/**
 * Outbound email through the one Composer PHPMailer (plan R14).
 *
 * Mail is sent immediately after the business transaction commits. If sending fails it is
 * queued in outbound_message (body encrypted) and retried by the mail cron job.
 *
 * config 'mail' => [
 *   'transport' => 'smtp' | 'log' | 'array',   // log: writes .eml files to storage/mail (dev)
 *   'from_email' => ..., 'from_name' => ...,
 *   'smtp' => ['host' => ..., 'port' => 587, 'user' => ..., 'pass' => ..., 'secure' => 'tls' | 'ssl' | ''],
 * ]
 */
final class Mailer
{
    /** @var list<array{to:string,subject:string,body:string}> messages captured by the 'array' transport (tests) */
    public static array $sent = [];

    /** @var list<array> messages to send once the response has gone out */
    private static array $deferred = [];

    /**
     * Send now; on failure queue for retry. Returns true if sent immediately.
     * @param array{user_id?: ?int, participant_id?: ?int} $refs who the message is about (for erasure)
     */
    public static function send(string $to, string $subject, string $body, string $templateKey, array $refs = []): bool
    {
        try {
            self::deliver($to, $subject, $body);
            return true;
        } catch (Throwable $e) {
            try {
                self::queue($to, $subject, $body, $templateKey, $refs, mb_substr($e->getMessage(), 0, 255));
            } catch (Throwable $queueError) {
                ErrorHandler::log(strtoupper(bin2hex(random_bytes(4))), $queueError); // e.g. no crypto key configured
            }
            return false;
        }
    }

    /**
     * Send after the HTTP response has been delivered. Used where sending time would reveal
     * something: a password-reset request must take as long for an unknown address as for
     * a known one (UC-01 §3.2.1), and SMTP can take seconds.
     */
    public static function sendAfterResponse(string $to, string $subject, string $body, string $templateKey, array $refs = []): void
    {
        if (!self::$deferred) {
            register_shutdown_function(static function (): void {
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }
                foreach (self::$deferred as $m) {
                    self::send($m['to'], $m['subject'], $m['body'], $m['templateKey'], $m['refs']);
                }
                self::$deferred = [];
            });
        }
        self::$deferred[] = compact('to', 'subject', 'body', 'templateKey', 'refs');
    }

    public static function deliver(string $to, string $subject, string $body): void
    {
        $transport = (string) Config::get('mail.transport', 'log');
        if ($transport === 'array') {
            self::$sent[] = compact('to', 'subject', 'body');
            return;
        }
        if ($transport === 'log') {
            $dir = APP_ROOT . '/storage/mail';
            if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new RuntimeException("Cannot create $dir");
            }
            $name = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.eml';
            file_put_contents("$dir/$name", "To: $to\nSubject: $subject\nDate: " . gmdate('r') . "\n\n$body\n");
            return;
        }
        if (!class_exists(PHPMailer::class)) {
            throw new RuntimeException('PHPMailer is not installed (run composer install)');
        }
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = (string) Config::require('mail.smtp.host');
        $mail->Port = (int) Config::get('mail.smtp.port', 587);
        $mail->SMTPAuth = Config::get('mail.smtp.user') !== null;
        $mail->Username = (string) Config::get('mail.smtp.user', '');
        $mail->Password = (string) Config::get('mail.smtp.pass', '');
        $secure = (string) Config::get('mail.smtp.secure', 'tls');
        $mail->SMTPSecure = $secure === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : ($secure === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : '');
        $mail->CharSet = 'UTF-8';
        $mail->setFrom((string) Config::require('mail.from_email'), (string) Config::get('mail.from_name', 'CHS Pet Pantry'));
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->isHTML(false);
        $mail->send();
    }

    private static function queue(string $to, string $subject, string $body, string $templateKey, array $refs, string $error): void
    {
        Db::pdo()->prepare(
            "INSERT INTO outbound_message (channel, recipient, user_id, participant_id, template_key, subject, body_ciphertext, status, attempts, last_error, not_before, created_at)
             VALUES ('Email', ?, ?, ?, ?, ?, ?, 'Queued', 1, ?, ?, ?)"
        )->execute([$to, $refs['user_id'] ?? null, $refs['participant_id'] ?? null, $templateKey, $subject,
            Crypto::encrypt($body, 'outbound_message'), $error, Clock::db(Clock::now()->modify('+5 minutes')), Clock::db()]);
    }
}

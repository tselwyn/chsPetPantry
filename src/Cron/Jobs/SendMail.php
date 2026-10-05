<?php
declare(strict_types=1);

namespace Pfpms\Cron\Jobs;

use Pfpms\Clock;
use Pfpms\Cron\Job;
use Pfpms\Db;
use Pfpms\Mail\Mailer;
use Pfpms\Notify\Notifications;
use Pfpms\Security\Crypto;
use Throwable;

/**
 * Retry queued mail (outbound_message). A message is claimed by switching it to 'Sending',
 * so two runs never send it twice. Failures back off (15 min, 1 h, 4 h, 12 h); after the last
 * attempt the message is marked Failed and Administrators are told. Sent bodies are wiped:
 * the outbox is not an archive of what was said to participants.
 */
final class SendMail implements Job
{
    public const MAX_ATTEMPTS = 5;
    private const BACKOFF_MINUTES = [1 => 15, 2 => 60, 3 => 240, 4 => 720];
    private const BATCH = 50;

    public function name(): string
    {
        return 'mail:send';
    }

    public function description(): string
    {
        return 'Retry queued email; give up after ' . self::MAX_ATTEMPTS . ' attempts and notify Administrators';
    }

    public function run(): string
    {
        $pdo = Db::pdo();
        // Messages stuck in 'Sending' (the process died mid-send) go back to the queue.
        $pdo->prepare("UPDATE outbound_message SET status = 'Queued' WHERE status = 'Sending' AND not_before < ?")
            ->execute([Clock::db(Clock::now()->modify('-1 hour'))]);

        $st = $pdo->prepare("SELECT message_id FROM outbound_message WHERE status = 'Queued' AND not_before <= ? ORDER BY message_id LIMIT " . self::BATCH);
        $st->execute([Clock::db()]);
        $sent = $retry = $failed = 0;
        foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            $claim = $pdo->prepare("UPDATE outbound_message SET status = 'Sending', not_before = ? WHERE message_id = ? AND status = 'Queued'");
            $claim->execute([Clock::db(), $id]);
            if ($claim->rowCount() !== 1) {
                continue; // another run took it
            }
            $row = $pdo->prepare('SELECT message_id, recipient, subject, body_ciphertext, attempts FROM outbound_message WHERE message_id = ?');
            $row->execute([$id]);
            $m = $row->fetch();
            try {
                Mailer::deliver($m['recipient'], (string) $m['subject'], Crypto::decrypt($m['body_ciphertext'], 'outbound_message'));
                $pdo->prepare("UPDATE outbound_message SET status = 'Sent', sent_at = ?, body_ciphertext = '', last_error = NULL WHERE message_id = ?")
                    ->execute([Clock::db(), $id]);
                $sent++;
            } catch (Throwable $e) {
                $attempts = (int) $m['attempts'] + 1;
                $error = mb_substr($e->getMessage(), 0, 255);
                if ($attempts >= self::MAX_ATTEMPTS) {
                    $pdo->prepare("UPDATE outbound_message SET status = 'Failed', attempts = ?, last_error = ? WHERE message_id = ?")
                        ->execute([$attempts, $error, $id]);
                    Notifications::toRoleOnce('Administrator', null, 'mail_failed',
                        "An email to {$m['recipient']} could not be delivered after $attempts attempts: $error", 'outbound_message', (int) $id);
                    $failed++;
                } else {
                    $delay = self::BACKOFF_MINUTES[$attempts] ?? 720;
                    $pdo->prepare("UPDATE outbound_message SET status = 'Queued', attempts = ?, last_error = ?, not_before = ? WHERE message_id = ?")
                        ->execute([$attempts, $error, Clock::db(Clock::now()->modify("+$delay minutes")), $id]);
                    $retry++;
                }
            }
        }
        return "sent $sent, will retry $retry, gave up on $failed";
    }
}

<?php
declare(strict_types=1);

namespace Pfpms\Tests\Support;

use Pfpms\Audit\Audit;
use Pfpms\Auth\PasswordPolicy;
use Pfpms\Auth\Pin;
use Pfpms\Auth\Policy;
use Pfpms\Auth\SessionStore;
use Pfpms\Auth\Tokens;
use Pfpms\Auth\WebSession;
use Pfpms\Clock;
use Pfpms\Config;
use Pfpms\Db;
use Pfpms\Device\DeviceProof;
use Pfpms\Device\DeviceRepository;
use Pfpms\Http\HttpException;
use Pfpms\Http\Request;
use Pfpms\Security\Crypto;
use Pfpms\Security\Csrf;
use Pfpms\Station\OfflineGrants;

/**
 * What every S3 Station test needs (S3 spec §4.1): tablets in service with their vault and proof keys, requests as the
 * Station sends them (Authorization, X-PFPMS-Client, a JSON body and a PFPMS-Proof signed over it), the Station's PHP
 * session, the agreement, site access, grants and the rows a test reads back. Patterns from DeviceGuardTest and
 * ApiSessionTest. Use it in a Pfpms\Tests\TestCase: call stationSetUp() from setUp() after parent::setUp(), and
 * stationTearDown() from tearDown() before parent::tearDown().
 */
trait StationFixture
{
    /** @var list<string> the request keys a Station test sets; saved and cleared in stationSetUp(), restored in stationTearDown() */
    private static array $stationServerKeys = ['REQUEST_METHOD', 'SCRIPT_NAME', 'REMOTE_ADDR', 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION',
        'HTTP_PFPMS_PROOF', 'HTTP_X_PFPMS_CLIENT', 'HTTP_X_CSRF_TOKEN', 'HTTP_ORIGIN', 'HTTP_SEC_FETCH_SITE', 'CONTENT_TYPE', 'CONTENT_LENGTH'];

    private array $stationSavedServer = [];
    private array $stationSavedConfig = [];
    private bool $stationSavedFlag = false;
    private string $stationSessionDir = '';
    /** The newest audit and notification rows before the test: durable or committed rows from other tests may exist. */
    private int $stationAuditMark = 0;
    private int $stationNotificationMark = 0;
    /** Milliseconds added to "now" for each signed request, so two requests of one test never share a proof (they are single-use). */
    private int $stationProofSeq = 0;

    protected function stationSetUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close(); // left open by an earlier test class
        }
        $this->stationSavedServer = [];
        foreach (self::$stationServerKeys as $key) {
            if (array_key_exists($key, $_SERVER)) {
                $this->stationSavedServer[$key] = $_SERVER[$key];
            }
            unset($_SERVER[$key]);
        }
        $this->stationSavedFlag = (bool) $this->stationFlag()->getValue();
        $this->stationSavedConfig = Config::snapshot();
        $this->stationSessionDir = sys_get_temp_dir() . '/pfpms-s3-test';
        Config::override(['app' => ['base_path' => '/', 'session_path' => $this->stationSessionDir] + (array) ($this->stationSavedConfig['app'] ?? [])]
            + $this->stationSavedConfig);
        Request::useBody(null);
        $this->stationAuditMark = (int) $this->scalar('SELECT COALESCE(MAX(audit_id), 0) FROM audit_log');
        $this->stationNotificationMark = (int) $this->scalar('SELECT COALESCE(MAX(notification_id), 0) FROM notification');
        $this->stationProofSeq = 0;
        OfflineGrants::$beforeIssue = null;
        Pin::$afterReserve = null;
        Pin::$beforeVerify = null;
        PasswordPolicy::$afterHash = null;
    }

    protected function stationTearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }
        foreach (glob($this->stationSessionDir . '/sess_*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->stationSessionDir);
        Request::useBody(null);
        OfflineGrants::$beforeIssue = null;
        Pin::$afterReserve = null;
        Pin::$beforeVerify = null;
        PasswordPolicy::$afterHash = null;
        $this->stationFlag()->setValue(null, $this->stationSavedFlag);
        foreach (self::$stationServerKeys as $key) {
            unset($_SERVER[$key]);
        }
        foreach ($this->stationSavedServer as $key => $value) {
            $_SERVER[$key] = $value;
        }
        if ($this->stationSavedConfig !== []) {
            Config::override($this->stationSavedConfig);
        }
        Audit::setActor(null);
    }

    /** WebSession's private "is this the Station's session" flag (restart() reuses it). */
    private function stationFlag(): \ReflectionProperty
    {
        return new \ReflectionProperty(WebSession::class, 'station');
    }

    /**
     * A tablet in service at $siteId (token_hash, is_site_registered 1, offline_enabled 1, pbkdf2_iterations 600000 unless
     * overridden), with its vault key and proof key stored as registration stores them (each AAD names the row).
     * @return array{id: int, credential: string, proofKey: ?string, dvk: ?string}
     */
    protected function stationTablet(int $siteId, array $overrides = [], bool $vaultKey = true, bool $proofKey = true): array
    {
        static $n = 0;
        $n++;
        $credential = 'pfd1_' . Crypto::b64url(random_bytes(32));
        $id = $this->makeDevice($siteId, $overrides + ['token_hash' => Tokens::hash($credential), 'is_site_registered' => 1, 'offline_enabled' => 1,
            'pbkdf2_iterations' => 600000, 'label' => "Station $n"]);
        $dvk = $vaultKey ? random_bytes(32) : null;
        $key = $proofKey ? random_bytes(32) : null;
        Db::pdo()->prepare('UPDATE device SET vault_key_ciphertext = ?, proof_key_ciphertext = ? WHERE device_id = ?')->execute([
            $dvk === null ? null : Crypto::encrypt($dvk, "device:$id:dvk"),
            $key === null ? null : Crypto::encrypt($key, "device:$id:proof"),
            $id,
        ]);
        return ['id' => $id, 'credential' => $credential, 'proofKey' => $key, 'dvk' => $dvk];
    }

    /** The guard's row for the tablet (DeviceRepository::byCredentialHash) plus proof 'valid', or 'none' without a proof key. */
    protected function stationDevice(array $tablet): array
    {
        $d = DeviceRepository::byCredentialHash(Tokens::hash((string) $tablet['credential']));
        $this->assertNotNull($d, 'the tablet is known');
        return $d + ['proof' => $tablet['proofKey'] === null ? 'none' : 'valid'];
    }

    /**
     * The request the Station sends: $method to $endpoint (e.g. 'api/auth/login.php'), Authorization and X-PFPMS-Client, the
     * JSON body (none when null) and PFPMS-Proof signed now over that exact body (true; nothing when the tablet has no proof
     * key), no proof (false) or the given header. Each signed request is 1 ms after the one before (a proof is single-use).
     */
    protected function stationRequest(array $tablet, string $endpoint, ?array $body = null, string $method = 'POST', bool|string $proof = true): void
    {
        $raw = $body === null ? '' : (string) json_encode($body);
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['SCRIPT_NAME'] = '/' . $endpoint;
        $_SERVER['REMOTE_ADDR'] = '203.0.113.30';
        $_SERVER['HTTP_AUTHORIZATION'] = 'PFPMS-Device ' . $tablet['credential'];
        $_SERVER['HTTP_X_PFPMS_CLIENT'] = 'station';
        unset($_SERVER['HTTP_PFPMS_PROOF']);
        Request::useBody($raw, 'application/json');
        if (is_string($proof)) {
            $_SERVER['HTTP_PFPMS_PROOF'] = $proof;
        } elseif ($proof && $tablet['proofKey'] !== null) {
            $_SERVER['HTTP_PFPMS_PROOF'] = $this->stationProof($tablet, $endpoint, $raw, $method, (int) Clock::now()->format('Uv') + $this->stationProofSeq++);
        }
    }

    /** A PFPMS-Proof header value signed with the tablet's proof key (now by default), as proof.js makes it. */
    protected function stationProof(array $tablet, string $endpoint, string $rawBody, string $method = 'POST', ?int $tsMs = null): string
    {
        $ts = $tsMs ?? (int) Clock::now()->format('Uv');
        return "v1 $ts " . Crypto::b64url(Crypto::hmac((string) $tablet['proofKey'], DeviceProof::message($method, $endpoint, $ts, hash('sha256', $rawBody))));
    }

    /** The Station's PHP session (WebSession::start(true)) carrying $ids, and the request's X-CSRF-Token set to its token. */
    protected function stationSession(?array $ids = null): void
    {
        WebSession::start(true);
        $_SESSION = $ids ?? [];
        $_SERVER['HTTP_X_CSRF_TOKEN'] = Csrf::token();
    }

    /** A $method session of $user on the tablet at the tablet's site, carried by the Station's PHP session; returns its id. */
    protected function stationSignedIn(array $user, array $tablet, string $method = 'Password'): string
    {
        $siteId = (int) $this->scalar('SELECT site_id FROM device WHERE device_id = ?', [$tablet['id']]);
        $sid = SessionStore::create((int) $user['user_id'], $siteId, $method, (int) $tablet['id']);
        $this->stationSession(['uid' => (int) $user['user_id'], 'sid' => $sid, 'site_id' => $siteId]);
        return $sid;
    }

    /**
     * A Confidentiality Agreement in English in force today, version $version (the existing one when that version exists), and,
     * for $user with $accept, their acceptance (Policy::acknowledge). @return array the policy_document row
     */
    protected function agreement(?array $user = null, string $version = '1', bool $accept = true): array
    {
        $find = Db::pdo()->prepare("SELECT * FROM policy_document WHERE doc_type = ? AND version = ? AND language_code = 'en'");
        $find->execute([Policy::CONFIDENTIALITY, $version]);
        $doc = $find->fetch();
        if (!$doc) {
            Db::pdo()->prepare("INSERT INTO policy_document (doc_type, version, language_code, body, effective_from) VALUES (?, ?, 'en', ?, ?)")
                ->execute([Policy::CONFIDENTIALITY, $version, "Keep what you learn about the people we serve confidential.\n\nVersion $version.", Clock::orgToday()]);
            $find->execute([Policy::CONFIDENTIALITY, $version]);
            $doc = $find->fetch();
        }
        if ($user !== null && $accept) {
            Policy::acknowledge((int) $user['user_id'], (int) $doc['document_id']);
        }
        return $doc;
    }

    /** A user_site_access row (granted by the person themselves: the column needs someone). */
    protected function grantSite(int $userId, int $siteId, string $startsAt = '2026-01-01 00:00:00', ?string $endsAt = null): void
    {
        Db::pdo()->prepare('INSERT INTO user_site_access (user_id, site_id, starts_at, ends_at, granted_by) VALUES (?, ?, ?, ?, ?)')
            ->execute([$userId, $siteId, $startsAt, $endsAt, $userId]);
    }

    protected function refusal(callable $fn): HttpException
    {
        try {
            $fn();
        } catch (HttpException $e) {
            return $e;
        }
        $this->fail('expected an HttpException');
    }

    protected function assertRefused(int $status, string $code, callable $fn, string $message = ''): HttpException
    {
        $e = $this->refusal($fn);
        $this->assertSame([$status, $code], [$e->status, $e->code()], $message !== '' ? $message : $e->getMessage());
        return $e;
    }

    /** @return list<array> the audit rows of $action written during this test, oldest first, details decoded and ksorted */
    protected function auditRows(string $action): array
    {
        $st = Db::pdo()->prepare('SELECT * FROM audit_log WHERE action = ? AND audit_id > ? ORDER BY audit_id');
        $st->execute([$action, $this->stationAuditMark]);
        return array_map(static function (array $row): array {
            $details = $row['details'] === null ? null : json_decode((string) $row['details'], true);
            if (is_array($details)) {
                ksort($details);
            }
            $row['details'] = $details;
            return $row;
        }, $st->fetchAll());
    }

    /** @return list<array> the notifications of $kind made during this test, oldest first */
    protected function notificationRows(string $kind): array
    {
        $st = Db::pdo()->prepare('SELECT * FROM notification WHERE kind = ? AND notification_id > ? ORDER BY notification_id');
        $st->execute([$kind, $this->stationNotificationMark]);
        return $st->fetchAll();
    }

    /** OfflineGrants::issue() for $user on the tablet (its row's device_id and site_id); the grant must be issued. */
    protected function stationGrant(array $user, array $tablet, bool $offlineAllowed = true): array
    {
        $siteId = (int) $this->scalar('SELECT site_id FROM device WHERE device_id = ?', [$tablet['id']]);
        $grant = OfflineGrants::issue($user, ['device_id' => (int) $tablet['id'], 'site_id' => $siteId], $offlineAllowed);
        $this->assertNotNull($grant, 'a grant was issued');
        return $grant;
    }

    /** The user_session row, or null. */
    protected function sessionRow(string $sid): ?array
    {
        $st = Db::pdo()->prepare('SELECT * FROM user_session WHERE session_id = ?');
        $st->execute([$sid]);
        return $st->fetch() ?: null;
    }

    /** @return list<array> the tablet's user_session rows, oldest first */
    protected function sessionsOn(int $deviceId): array
    {
        $st = Db::pdo()->prepare('SELECT * FROM user_session WHERE device_id = ? ORDER BY started_at, session_id');
        $st->execute([$deviceId]);
        return $st->fetchAll();
    }

    /** @return list<array> the person's Offline Grant rows, oldest first */
    protected function grantsOf(int $userId): array
    {
        $st = Db::pdo()->prepare("SELECT * FROM auth_token WHERE user_id = ? AND purpose = 'Offline Grant' ORDER BY token_id");
        $st->execute([$userId]);
        return $st->fetchAll();
    }
}

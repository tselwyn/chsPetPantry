<?php
declare(strict_types=1);

namespace Pfpms\Device;

use DateTimeImmutable;
use Pfpms\Http\ErrorHandler;
use Pfpms\Security\Crypto;
use RuntimeException;

/**
 * The tablet's proof signature (50-design X-1, §3.2). At registration the Station makes a 32-byte HMAC key,
 * sends it once and keeps it non-extractable; the server keeps it encrypted (device.proof_key_ciphertext).
 * Every device call then carries
 *
 *     PFPMS-Proof: v1 <ts_ms> <b64url HMAC-SHA256(key, "pfpms/v1/proof\n<METHOD>\n<endpoint>\n<ts_ms>\n<hex SHA-256(body)>")>
 *
 * so a copy of the credential alone (devtools, an exported store) cannot confirm a wipe, open a session or get
 * keys. The tests pin the layout against tests/fixtures (the §3.9 vector).
 */
final class DeviceProof
{
    public const HEADER = 'PFPMS-Proof';

    /** A signature older or newer than this (by the server's clock) is stale. */
    public const WINDOW_SECONDS = 900;

    /** The signed message: "pfpms/v1/proof\n<METHOD>\n<endpoint>\n<ts_ms>\n<hex sha256(body)>". */
    public static function message(string $method, string $endpoint, int $tsMs, string $bodySha256): string
    {
        return "pfpms/v1/proof\n" . strtoupper($method) . "\n" . $endpoint . "\n" . $tsMs . "\n" . $bodySha256;
    }

    /** @return ?array{ts: int, mac: string} from "v1 <13 digits> <43 b64url>", else null */
    public static function parse(#[\SensitiveParameter] ?string $header): ?array
    {
        if ($header === null || !preg_match('/^v1 (\d{13}) ([A-Za-z0-9_-]{43})$/D', $header, $m)) {
            return null;
        }
        return ['ts' => (int) $m[1], 'mac' => $m[2]];
    }

    /**
     * Check a request's proof against the tablet's key.
     * @return 'valid'|'stale'|'invalid'|'missing' stale: a correct signature outside WINDOW_SECONDS
     */
    public static function check(int $deviceId, #[\SensitiveParameter] ?string $header, string $method, string $endpoint, string $bodySha256, DateTimeImmutable $now): string
    {
        $proof = self::parse($header);
        if ($proof === null) {
            return 'missing';
        }
        $sealed = DeviceRepository::proofKeyCiphertext($deviceId);
        if ($sealed === null) {
            return 'missing';
        }
        try {
            $key = Crypto::decrypt($sealed, "device:$deviceId:proof");
        } catch (RuntimeException $e) {
            // A key id removed from crypto.keys, or a value moved between rows: logged, so a configuration mistake is
            // found from the incident log rather than mistaken for a copied credential.
            ErrorHandler::log(strtoupper(bin2hex(random_bytes(4))), $e);
            return 'invalid';
        }
        $mac = Crypto::unb64urlStrict($proof['mac'], 32);
        if ($mac === null || !hash_equals(Crypto::hmac($key, self::message($method, $endpoint, $proof['ts'], $bodySha256)), $mac)) {
            return 'invalid';
        }
        $nowMs = (int) $now->format('Uv');
        return abs($nowMs - $proof['ts']) > self::WINDOW_SECONDS * 1000 ? 'stale' : 'valid';
    }
}

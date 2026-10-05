<?php
declare(strict_types=1);

namespace Pfpms\Tests\Integration\Device;

use Pfpms\Auth\Tokens;
use Pfpms\Clock;
use Pfpms\Db;
use Pfpms\Device\DeviceProof;
use Pfpms\Security\Crypto;
use Pfpms\Tests\TestCase;

/**
 * DeviceProof::check() against the tablet's stored proof key (50-design X-1, §3.2, §12.1): valid, invalid, missing and
 * stale, with the §3.9 vector. The frozen clock (2026-10-01 12:00:00.000 UTC) is the vector's timestamp, 1790856000000 ms.
 */
final class DeviceProofCheckTest extends TestCase
{
    private const BODY = '{"wiped":true,"items_pushed":3}';
    private const ENDPOINT = 'api/device/heartbeat.php';
    private const TS_MS = 1790856000000;
    private const MAC = 'PrHNdFtRjYHJBKT1J-yFIQ-VNRXbmGStoEtVXIlfQV0';

    private int $site;
    private int $tablet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = $this->makeSite('Northside');
        $this->tablet = $this->inService(self::vectorKey());
    }

    // Helpers -------------------------------------------------------------------------------

    /** The §3.9 proof key: bytes 40..5f. */
    private static function vectorKey(): string
    {
        return implode('', array_map('chr', range(0x40, 0x5f)));
    }

    /** A registered tablet in service with a credential of DeviceGuard::FORMAT and, unless null, this proof key stored as registration stores it. */
    private function inService(?string $proofKey): int
    {
        $credential = 'pfd1_' . Crypto::b64url(random_bytes(32));
        $id = $this->makeDevice($this->site, ['token_hash' => Tokens::hash($credential), 'is_site_registered' => 1]);
        if ($proofKey !== null) {
            $this->setProofKeyCiphertext($id, Crypto::encrypt($proofKey, "device:$id:proof"));
        }
        return $id;
    }

    private function setProofKeyCiphertext(int $deviceId, ?string $ciphertext): void
    {
        Db::pdo()->prepare('UPDATE device SET proof_key_ciphertext = ? WHERE device_id = ?')->execute([$ciphertext, $deviceId]);
    }

    private static function nowMs(): int
    {
        return (int) Clock::now()->format('Uv');
    }

    /** "v1 <ts_ms> <b64url mac>" as the Station signs it. */
    private static function header(string $key, string $method, string $endpoint, int $tsMs, string $body): string
    {
        return 'v1 ' . $tsMs . ' ' . Crypto::b64url(Crypto::hmac($key, DeviceProof::message($method, $endpoint, $tsMs, hash('sha256', $body))));
    }

    private function check(?string $header, string $method = 'POST', string $endpoint = self::ENDPOINT, string $body = self::BODY, ?int $deviceId = null): string
    {
        return DeviceProof::check($deviceId ?? $this->tablet, $header, $method, $endpoint, hash('sha256', $body), Clock::now());
    }

    // Valid ---------------------------------------------------------------------------------

    public function testTheFixtureMacVerifies(): void
    {
        $this->assertSame(self::TS_MS, self::nowMs(), 'the frozen clock is the vector timestamp');
        $this->assertSame('valid', $this->check('v1 ' . self::TS_MS . ' ' . self::MAC));
    }

    public function testAGetIsSignedOverTheEmptyBody(): void
    {
        $header = self::header(self::vectorKey(), 'GET', 'api/session.php', self::nowMs(), '');
        $this->assertSame('valid', $this->check($header, 'GET', 'api/session.php', ''));
        $this->assertSame('valid', DeviceProof::check($this->tablet, $header, 'GET', 'api/session.php',
            'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', Clock::now()), 'the §3.9 empty-body hash');
    }

    // Invalid -------------------------------------------------------------------------------

    public function testAChangedBodyMethodOrEndpointIsInvalid(): void
    {
        $header = 'v1 ' . self::TS_MS . ' ' . self::MAC;
        $this->assertSame('invalid', $this->check($header, body: '{"wiped":true,"items_pushed":4}'), 'a changed body');
        $this->assertSame('invalid', $this->check($header, body: self::BODY . ' '), 'a byte added to the body');
        $this->assertSame('invalid', $this->check($header, method: 'PUT'), 'a changed method');
        $this->assertSame('invalid', $this->check($header, endpoint: 'api/device/register.php'), 'a changed endpoint');
        $this->assertSame('invalid', $this->check($header, endpoint: '/api/device/heartbeat.php'), 'the endpoint is the path under public/, no leading slash');
    }

    public function testAChangedTimestampIsInvalid(): void
    {
        $this->assertSame('invalid', $this->check('v1 ' . (self::TS_MS + 1) . ' ' . self::MAC), 'the timestamp is signed');
    }

    public function testAnotherTabletsKeyIsInvalid(): void
    {
        $otherKey = random_bytes(32);
        $this->inService($otherKey);
        $this->assertSame('invalid', $this->check(self::header($otherKey, 'POST', self::ENDPOINT, self::nowMs(), self::BODY)));
    }

    public function testANonCanonicalMacIsInvalid(): void
    {
        // The vector mac ends in "0"; "1" decodes to the same 32 bytes leniently, but is not the canonical text (§3.2).
        $nonCanonical = substr(self::MAC, 0, 42) . '1';
        $this->assertSame(Crypto::unb64url(self::MAC), Crypto::unb64url($nonCanonical));
        $this->assertNotNull(DeviceProof::parse('v1 ' . self::TS_MS . ' ' . $nonCanonical), 'the header shape is fine');
        $this->assertSame('invalid', $this->check('v1 ' . self::TS_MS . ' ' . $nonCanonical));
    }

    public function testAProofKeyMovedFromAnotherTabletIsInvalid(): void
    {
        $other = $this->inService(null);
        $this->setProofKeyCiphertext($other, Crypto::encrypt(self::vectorKey(), "device:{$this->tablet}:proof"));
        // The key is right, but its ciphertext is bound to the other row (AAD device:<id>:proof), so it never opens here.
        $this->assertSame('invalid', $this->check('v1 ' . self::TS_MS . ' ' . self::MAC, deviceId: $other));
    }

    // Missing -------------------------------------------------------------------------------

    public function testAMissingOrMalformedHeaderIsMissing(): void
    {
        $headers = [
            'no header' => null,
            'empty' => '',
            'another version' => 'v2 ' . self::TS_MS . ' ' . self::MAC,
            'seconds, not milliseconds' => 'v1 1790856000 ' . self::MAC,
            'a short mac' => 'v1 ' . self::TS_MS . ' ' . substr(self::MAC, 0, 42),
            'a padded mac' => 'v1 ' . self::TS_MS . ' ' . self::MAC . '=',
            'the credential scheme' => 'PFPMS-Device pfd1_' . self::MAC,
        ];
        foreach ($headers as $name => $header) {
            $this->assertSame('missing', $this->check($header), $name);
        }
    }

    public function testATabletWithoutAProofKeyIsMissing(): void
    {
        $unkeyed = $this->inService(null);
        $this->assertNull($this->scalar('SELECT proof_key_ciphertext FROM device WHERE device_id = ?', [$unkeyed]));
        $this->assertSame('missing', $this->check('v1 ' . self::TS_MS . ' ' . self::MAC, deviceId: $unkeyed));
        $this->assertSame('missing', $this->check(self::header(random_bytes(32), 'POST', self::ENDPOINT, self::nowMs(), self::BODY), deviceId: $unkeyed));
    }

    public function testAClearedProofKeyIsMissing(): void
    {
        $this->setProofKeyCiphertext($this->tablet, null); // as confirmWipe() leaves the row
        $this->assertSame('missing', $this->check('v1 ' . self::TS_MS . ' ' . self::MAC));
    }

    public function testAnUnknownTabletIsMissing(): void
    {
        $unknown = (int) $this->scalar('SELECT COALESCE(MAX(device_id), 0) + 1000 FROM device');
        $this->assertSame('missing', $this->check('v1 ' . self::TS_MS . ' ' . self::MAC, deviceId: $unknown));
    }

    // Stale ---------------------------------------------------------------------------------

    public function testATimestampOutsideFifteenMinutesIsStale(): void
    {
        $window = DeviceProof::WINDOW_SECONDS * 1000;
        foreach (['behind' => self::nowMs() - $window - 1, 'ahead' => self::nowMs() + $window + 1, 'an hour behind' => self::nowMs() - 3600000] as $name => $ts) {
            $this->assertSame('stale', $this->check(self::header(self::vectorKey(), 'POST', self::ENDPOINT, $ts, self::BODY)), $name);
        }
        // The vector itself, judged 16 minutes later: staleness is measured against the $now given.
        $this->assertSame('stale', DeviceProof::check($this->tablet, 'v1 ' . self::TS_MS . ' ' . self::MAC, 'POST', self::ENDPOINT,
            hash('sha256', self::BODY), Clock::now()->modify('+16 minutes')));
    }

    public function testFifteenMinutesExactlyIsStillValid(): void
    {
        $window = DeviceProof::WINDOW_SECONDS * 1000;
        foreach (['behind' => self::nowMs() - $window, 'ahead' => self::nowMs() + $window] as $name => $ts) {
            $this->assertSame('valid', $this->check(self::header(self::vectorKey(), 'POST', self::ENDPOINT, $ts, self::BODY)), $name);
        }
    }

    public function testAStaleTimestampWithAWrongMacIsInvalid(): void
    {
        $stale = self::nowMs() - DeviceProof::WINDOW_SECONDS * 1000 - 1;
        $cases = [
            'another key' => self::header(random_bytes(32), 'POST', self::ENDPOINT, $stale, self::BODY),
            'another body' => self::header(self::vectorKey(), 'POST', self::ENDPOINT, $stale, '{}'),
            'the vector mac on a stale timestamp' => 'v1 ' . $stale . ' ' . self::MAC,
        ];
        foreach ($cases as $name => $header) {
            $this->assertSame('invalid', $this->check($header), $name);
        }
    }
}

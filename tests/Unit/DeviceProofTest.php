<?php
declare(strict_types=1);

namespace Pfpms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pfpms\Device\DeviceProof;
use Pfpms\Security\Crypto;

/**
 * The tablet's proof signature (50-design X-1, §3.2): the signed message and the header, pinned to the §3.9 vector.
 * DeviceProof::check() reads the tablet's key from its row, so it is covered by Integration/Device/DeviceProofCheckTest.
 */
final class DeviceProofTest extends TestCase
{
    /** 50-design §3.9: proof key bytes 40..5f, a heartbeat at 2026-10-01 12:00:00.000 UTC. */
    private const BODY = '{"wiped":true,"items_pushed":3}';
    private const BODY_SHA256 = 'ce21e1ae308295b6d34dd67226f788849300d36244845c946944d44c4cd7fb41';
    private const ENDPOINT = 'api/device/heartbeat.php';
    private const TS_MS = 1790856000000;
    private const MAC = 'PrHNdFtRjYHJBKT1J-yFIQ-VNRXbmGStoEtVXIlfQV0';
    private const EMPTY_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    private static function proofKey(): string
    {
        return implode('', array_map('chr', range(0x40, 0x5f)));
    }

    public function testMessageLayoutIsExact(): void
    {
        $this->assertSame(self::BODY_SHA256, hash('sha256', self::BODY));
        $this->assertSame(
            "pfpms/v1/proof\nPOST\napi/device/heartbeat.php\n1790856000000\nce21e1ae308295b6d34dd67226f788849300d36244845c946944d44c4cd7fb41",
            DeviceProof::message('POST', self::ENDPOINT, self::TS_MS, self::BODY_SHA256)
        );
        $this->assertSame("pfpms/v1/proof\nGET\napi/session.php\n1790856000000\n" . self::EMPTY_SHA256,
            DeviceProof::message('GET', 'api/session.php', self::TS_MS, hash('sha256', '')), 'a GET signs the hash of the empty body');
    }

    public function testMessageUpperCasesTheMethod(): void
    {
        $this->assertSame(DeviceProof::message('POST', self::ENDPOINT, self::TS_MS, self::BODY_SHA256),
            DeviceProof::message('post', self::ENDPOINT, self::TS_MS, self::BODY_SHA256));
    }

    public function testTheFixtureMacVerifies(): void
    {
        $proof = DeviceProof::parse('v1 ' . self::TS_MS . ' ' . self::MAC);
        $this->assertSame(['ts' => self::TS_MS, 'mac' => self::MAC], $proof);
        $expected = Crypto::hmac(self::proofKey(), DeviceProof::message('POST', self::ENDPOINT, $proof['ts'], hash('sha256', self::BODY)));
        $this->assertSame(self::MAC, Crypto::b64url($expected));
        $this->assertTrue(hash_equals($expected, (string) Crypto::unb64urlStrict($proof['mac'], 32)));
    }

    public function testParseReadsTheTimestampAsAnInteger(): void
    {
        $proof = DeviceProof::parse('v1 0000000000001 ' . self::MAC);
        $this->assertSame(['ts' => 1, 'mac' => self::MAC], $proof);
        $this->assertSame(['ts' => 9999999999999, 'mac' => self::MAC], DeviceProof::parse('v1 9999999999999 ' . self::MAC));
    }

    public function testParseRefusesAMissingOrMalformedHeader(): void
    {
        $ts = (string) self::TS_MS;
        $headers = [
            'no header' => null,
            'empty' => '',
            'another version' => "v2 $ts " . self::MAC,
            'an upper-case version' => "V1 $ts " . self::MAC,
            'no version' => "$ts " . self::MAC,
            '12-digit timestamp' => 'v1 ' . substr($ts, 1) . ' ' . self::MAC,
            '14-digit timestamp' => "v1 {$ts}0 " . self::MAC,
            'seconds, not milliseconds' => 'v1 1790856000 ' . self::MAC,
            'a signed timestamp' => 'v1 -790856000000 ' . self::MAC,
            'a 42-character mac' => "v1 $ts " . substr(self::MAC, 0, 42),
            'a 44-character mac' => "v1 $ts " . self::MAC . 'A',
            'a padded mac' => "v1 $ts " . substr(self::MAC, 0, 42) . '=',
            'the standard alphabet' => "v1 $ts " . strtr(self::MAC, '-_', '+/'),
            'two spaces' => "v1  $ts " . self::MAC,
            'a tab' => "v1\t$ts " . self::MAC,
            'a leading space' => " v1 $ts " . self::MAC,
            'a trailing space' => "v1 $ts " . self::MAC . ' ',
            'a fourth field' => "v1 $ts " . self::MAC . ' x',
            'the mac alone' => self::MAC,
        ];
        foreach ($headers as $name => $header) {
            $this->assertNull(DeviceProof::parse($header), $name);
        }
    }

    public function testTheHeaderNameAndWindowAreAsDesigned(): void
    {
        $this->assertSame('PFPMS-Proof', DeviceProof::HEADER);
        $this->assertSame(900, DeviceProof::WINDOW_SECONDS, '15 minutes');
    }
}

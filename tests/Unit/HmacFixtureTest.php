<?php
declare(strict_types=1);

namespace Pfpms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pfpms\Security\Crypto;

/**
 * HMAC-SHA256 parity (50-design §3.2) against tests/fixtures/hmac.json, the cases the Station's vault.js sign() and
 * WebCrypto also pass (tests/js/hmac.test.js): mac = b64url(HMAC-SHA256(key, message)).
 */
final class HmacFixtureTest extends TestCase
{
    public function testEveryCase(): void
    {
        $cases = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/hmac.json'), true, 512, JSON_THROW_ON_ERROR)['cases'];
        $this->assertCount(5, $cases);
        foreach ($cases as $case) {
            $key = (string) hex2bin($case['key_hex']);
            $this->assertSame(strlen($case['key_hex']) / 2, strlen($key), $case['name']);
            if (isset($case['message_utf8'])) {
                $message = $case['message_utf8'];
                $this->assertTrue(mb_check_encoding($message, 'UTF-8'), $case['name']);
            } elseif (isset($case['message_hex'])) {
                $message = (string) hex2bin($case['message_hex']);
            } else {
                $this->assertSame(1, strlen($case['repeat']['char']), $case['name']);
                $message = str_repeat($case['repeat']['char'], $case['repeat']['count']);
            }
            $mac = Crypto::hmac($key, $message);
            $this->assertSame(32, strlen($mac), $case['name']);
            $this->assertSame($case['mac'], Crypto::b64url($mac), $case['name']);
            $this->assertSame($mac, Crypto::unb64urlStrict($case['mac'], 32), $case['name'] . ': the fixture mac is strict base64url');
        }
    }
}

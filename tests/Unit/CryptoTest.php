<?php
declare(strict_types=1);

namespace Pfpms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pfpms\Config;
use Pfpms\Security\Crypto;
use RuntimeException;

final class CryptoTest extends TestCase
{
    private array $saved;
    private array $keys;

    protected function setUp(): void
    {
        $this->saved = Config::snapshot();
        $this->keys = ['k1' => base64_encode(str_repeat('a', 32)), 'k2' => base64_encode(str_repeat('b', 32))];
        $this->useActiveKey('k2');
    }

    protected function tearDown(): void
    {
        Config::override($this->saved);
    }

    public function testRoundTrip(): void
    {
        $secret = "Voucher for Muñoz household \u{1F43E}";
        $token = Crypto::encrypt($secret, 'aad');
        $this->assertStringContainsString('g1.k2.', $token);
        $this->assertSame($secret, Crypto::decrypt($token, 'aad'));
        $this->assertNotSame(Crypto::encrypt($secret), Crypto::encrypt($secret), 'a fresh IV each time');
    }

    public function testOldKeysStillDecryptAfterRotation(): void
    {
        $this->useActiveKey('k1');
        $old = Crypto::encrypt('written with k1');
        $this->useActiveKey('k2');
        $this->assertSame('written with k1', Crypto::decrypt($old));
    }

    public function testTamperingIsDetected(): void
    {
        $token = Crypto::encrypt('amount=50');
        $raw = Crypto::unb64url(explode('.', $token)[2]);
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
        $this->expectException(RuntimeException::class);
        Crypto::decrypt('g1.k2.' . Crypto::b64url($raw));
    }

    public function testWrongAssociatedDataFails(): void
    {
        $token = Crypto::encrypt('x', 'outbound_message');
        $this->expectException(RuntimeException::class);
        Crypto::decrypt($token, 'import_file');
    }

    public function testMissingKeyIsAConfigurationError(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not configured');
        Crypto::decrypt('g1.k9.AAAA');
    }

    private function useActiveKey(string $id): void
    {
        Config::override(['crypto' => ['active' => $id, 'keys' => $this->keys]] + $this->saved);
    }
}

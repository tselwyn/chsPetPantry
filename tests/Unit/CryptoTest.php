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

    // The Station's WebCrypto layout (50-design §3.2, §3.8, vectors §3.9) ---------------------

    /** 50-design §3.9: the outbox record of item S, sealed by WebCrypto under K_rec of the DVK 00..1f. */
    private const OUTBOX_IV = 'oKGio6Slpqeoqaqr';
    private const OUTBOX_AAD = 'pfpms/v1|outbox|123e4567-e89b-42d3-a456-426614174000';
    private const OUTBOX_SEALED = 'FHbpiwLqcSALgS0SbGtgeYnMpHLZIIN0tX6oCx_DnLbkznLEAuxTyKv1rDmjWDTFkgsXJ3kE_eApe4sAKCTxw14OgGT-Xooi5zceRgBhw7Ul3G8qnLYxt5mKlaFWP7mkvSvyYhGDz9PLSJLpqm7iHbUN7Y82D4CI7CocAB9eFAXpx74Q0JshXQMHy9d5MRIEbu-0-5jMjmP95MpKqCOF2QtwZxc-96IbEMQmQZXPldIzbIJgie5c_6ccUFS5Rizc5MeDRMJ8sLdW7jWBU9UeGu73XWM-LbhbTOT1wZ9G06Y2EP4BqWbJk2YjotqWtSJrxlj_bor5eCSFh5H6afRJ-THZvqWReWwci__VqFFen82TKqUFv2svNObBDeTa0AedzyevqbKcIg';
    private const K_REC_HEX = 'be4682d8c6aaa0a90ce19879e184634a63ef8bf911c4b17e84f855c0bd98d0c3';
    private const K_PIN_HEX = 'c943aefe860fad33bb29317214e7ff1c5ad765844914f5083be571e29604922a';

    /** Bytes $from..$to, as the §3.9 vectors write them ("00 01 … 1f"). */
    private static function bytes(int $from, int $to): string
    {
        return implode('', array_map('chr', range($from, $to)));
    }

    private static function recordKey(): string
    {
        return Crypto::hkdf(self::bytes(0x00, 0x1f), 'pfpms/v1/record');
    }

    /** The canonical bytes of item S (§3.9), with U+2028 literal and ñ as UTF-8. */
    private static function canonicalItem(): string
    {
        return '{"client_uuid":"123e4567-e89b-42d3-a456-426614174000","clock_offset_ms":-1520,"depends_on":null,"device_id":7,"grant_id":345,'
            . '"kind":"test_noop","payload":{"n":3,"note":"Mu' . "\u{00F1}" . 'oz / ' . "\u{2028}" . ' test"},"recorded_at":"2026-10-01 11:59:30.250",'
            . '"recorded_by":12,"seq":41,"session_id":null,"v":1}';
    }

    private static function sealedOutbox(): string
    {
        return (string) Crypto::unb64urlStrict(self::OUTBOX_SEALED);
    }

    private static function outboxIv(): string
    {
        return (string) Crypto::unb64urlStrict(self::OUTBOX_IV, 12);
    }

    public function testOpenRawOpensTheWebCryptoLayout(): void
    {
        $this->assertSame(self::bytes(0xa0, 0xab), self::outboxIv(), 'the IV vector is bytes a0..ab');
        $plain = Crypto::openRaw(self::recordKey(), self::outboxIv(), self::sealedOutbox(), self::OUTBOX_AAD);
        $this->assertSame(self::canonicalItem(), $plain);
        $this->assertSame('16978510d0c584bb6e5069d1e30dbd72f1c3a7d7da8e4731d175736aec27b8a3', hash('sha256', $plain), 'payload_sha256 of the vector');
        $this->assertStringContainsString("\xE2\x80\xA8", $plain, 'U+2028 stays literal (bytes E2 80 A8)');
    }

    public function testOpenRawRefusesAWrongAad(): void
    {
        $wrong = [
            'pfpms/v1|outbox|123e4567-e89b-42d3-a456-426614174001', // another slot
            'pfpms/v1|drafts|123e4567-e89b-42d3-a456-426614174000', // another store
            '',
        ];
        foreach ($wrong as $aad) {
            try {
                Crypto::openRaw(self::recordKey(), self::outboxIv(), self::sealedOutbox(), $aad);
                $this->fail("a record opened with the AAD '$aad'");
            } catch (RuntimeException $e) {
                $this->assertSame('Decryption failed', $e->getMessage(), $aad);
            }
        }
    }

    public function testOpenRawRefusesAShortTag(): void
    {
        $sealed = self::sealedOutbox();
        $ciphertext = substr($sealed, 0, -16);
        $shortTag = substr($sealed, -16, 4);
        // The reason for the fixed 16: openssl on its own accepts a GCM tag truncated to 4 bytes.
        $this->assertSame(self::canonicalItem(), openssl_decrypt($ciphertext, 'aes-256-gcm', self::recordKey(), OPENSSL_RAW_DATA, self::outboxIv(), $shortTag, self::OUTBOX_AAD));

        try {
            Crypto::openRaw(self::recordKey(), self::outboxIv(), $ciphertext . $shortTag, self::OUTBOX_AAD);
            $this->fail('a record with a 4-byte tag was opened');
        } catch (RuntimeException $e) {
            $this->assertSame('Decryption failed', $e->getMessage());
        }
        try {
            Crypto::openRaw(self::recordKey(), self::outboxIv(), substr($sealed, -16, 15), self::OUTBOX_AAD);
            $this->fail('a record shorter than a tag was opened');
        } catch (RuntimeException $e) {
            $this->assertSame('Decryption failed: wrong key, IV or tag size', $e->getMessage());
        }
    }

    public function testOpenRawRefusesAWrongKeyOrIvSize(): void
    {
        $key = self::recordKey();
        $iv = self::outboxIv();
        // openssl would use a 16-byte GCM IV, and pad or cut a key; openRaw takes exactly 32 and 12.
        $cases = ['31-byte key' => [substr($key, 0, 31), $iv], '33-byte key' => [$key . "\0", $iv],
            '11-byte IV' => [$key, substr($iv, 0, 11)], '16-byte IV' => [$key, $iv . "\0\0\0\0"]];
        foreach ($cases as $name => [$k, $i]) {
            try {
                Crypto::openRaw($k, $i, self::sealedOutbox(), self::OUTBOX_AAD);
                $this->fail("opened with a $name");
            } catch (RuntimeException $e) {
                $this->assertSame('Decryption failed: wrong key, IV or tag size', $e->getMessage(), $name);
            }
        }
    }

    public function testOpenRawRefusesAChangedCiphertextTagOrIv(): void
    {
        $sealed = self::sealedOutbox();
        $flip = static function (string $s, int $at): string {
            $s[$at] = chr(ord($s[$at]) ^ 0x01);
            return $s;
        };
        $cases = ['ciphertext' => [$flip($sealed, 0), self::outboxIv()], 'tag' => [$flip($sealed, strlen($sealed) - 1), self::outboxIv()],
            'IV' => [$sealed, $flip(self::outboxIv(), 11)]];
        foreach ($cases as $name => [$s, $iv]) {
            try {
                Crypto::openRaw(self::recordKey(), $iv, $s, self::OUTBOX_AAD);
                $this->fail("a changed $name was opened");
            } catch (RuntimeException $e) {
                $this->assertSame('Decryption failed', $e->getMessage(), $name);
            }
        }
    }

    public function testOpenRawRefusesAnotherKey(): void
    {
        $others = ['K_pin of the same DVK' => Crypto::hkdf(self::bytes(0x00, 0x1f), 'pfpms/v1/pin'),
            "another tablet's K_rec" => Crypto::hkdf(self::bytes(0x01, 0x20), 'pfpms/v1/record')];
        foreach ($others as $name => $key) {
            try {
                Crypto::openRaw($key, self::outboxIv(), self::sealedOutbox(), self::OUTBOX_AAD);
                $this->fail("opened with $name");
            } catch (RuntimeException $e) {
                $this->assertSame('Decryption failed', $e->getMessage(), $name);
            }
        }
    }

    public function testUnb64urlStrictRefusesPaddingWhitespaceAndNonCanonicalEndings(): void
    {
        // The lenient decoder accepts all three (§3.2): that is why tablet-sent values use the strict one.
        $this->assertSame("\0", Crypto::unb64url('AA=='));
        $this->assertSame('ABC', Crypto::unb64url('QU JD'));
        $this->assertSame(Crypto::unb64url('AA'), Crypto::unb64url('AB'));

        // "\n" last also passes a "$"-anchored alphabet regex; the canonical round trip must still refuse it.
        foreach (['AA==', 'AA=', 'QU JD', ' QUJD', "QU\tJD", "QUJD\n", "QUJDRA\n", 'AB', 'AF', 'QUJ'] as $text) {
            $this->assertNull(Crypto::unb64urlStrict($text), json_encode($text));
        }
    }

    public function testUnb64urlStrictRefusesTheStandardAlphabetAndImpossibleLengths(): void
    {
        $this->assertSame("\xfb\xff", Crypto::unb64urlStrict('-_8'), 'the URL-safe alphabet decodes');
        foreach (['+_8', '-/8', 'A', 'QUJDR', 'QUJD.', 'QUJD%3D'] as $text) {
            $this->assertNull(Crypto::unb64urlStrict($text), $text);
        }
    }

    public function testUnb64urlStrictDecodesCanonicalText(): void
    {
        $this->assertSame('', Crypto::unb64urlStrict(''));
        $this->assertSame("\0", Crypto::unb64urlStrict('AA'));
        $this->assertSame('ABC', Crypto::unb64urlStrict('QUJD'));
        $this->assertSame('ABCD', Crypto::unb64urlStrict('QUJDRA'));
        foreach ([1, 2, 12, 31, 32, 33] as $n) {
            $bytes = random_bytes($n);
            $this->assertSame($bytes, Crypto::unb64urlStrict(Crypto::b64url($bytes)), "$n bytes round-trip");
        }
    }

    public function testUnb64urlStrictChecksTheLength(): void
    {
        $mac = 'PrHNdFtRjYHJBKT1J-yFIQ-VNRXbmGStoEtVXIlfQV0';
        $this->assertSame(32, strlen((string) Crypto::unb64urlStrict($mac, 32)));
        $this->assertNull(Crypto::unb64urlStrict($mac, 31));
        $this->assertNull(Crypto::unb64urlStrict($mac, 33));
        $this->assertSame(self::bytes(0xa0, 0xab), Crypto::unb64urlStrict(self::OUTBOX_IV, 12));
        $this->assertNull(Crypto::unb64urlStrict(self::OUTBOX_IV, 16));
        $this->assertNull(Crypto::unb64urlStrict('', 32), 'an empty value is not 32 bytes');
        $this->assertSame('', Crypto::unb64urlStrict('', 0));
    }

    public function testHkdfMatchesTheStationVector(): void
    {
        $dvk = self::bytes(0x00, 0x1f);
        $this->assertSame(self::K_REC_HEX, bin2hex(Crypto::hkdf($dvk, 'pfpms/v1/record')));
        $this->assertSame(self::K_PIN_HEX, bin2hex(Crypto::hkdf($dvk, 'pfpms/v1/pin')));
        $this->assertSame(32, strlen(Crypto::hkdf($dvk, 'pfpms/v1/record')), 'raw bytes, 32 by default');
    }

    public function testHkdfHonoursTheLengthAndTheEmptySalt(): void
    {
        $dvk = self::bytes(0x00, 0x1f);
        $this->assertSame(substr(hex2bin(self::K_REC_HEX), 0, 16), Crypto::hkdf($dvk, 'pfpms/v1/record', 16));
        $this->assertSame(64, strlen(Crypto::hkdf($dvk, 'pfpms/v1/record', 64)));
        // RFC 5869: an empty salt is HashLen zero bytes, so the vector is also the zero-salt derivation, and any other salt differs.
        $this->assertSame(hex2bin(self::K_REC_HEX), hash_hkdf('sha256', $dvk, 32, 'pfpms/v1/record', str_repeat("\0", 32)));
        $this->assertNotSame(hex2bin(self::K_REC_HEX), hash_hkdf('sha256', $dvk, 32, 'pfpms/v1/record', 'salt'));
    }

    public function testHmacIsRaw32Bytes(): void
    {
        $grantKey = self::bytes(0x20, 0x3f);
        $mac = Crypto::hmac($grantKey, '');
        $this->assertSame(32, strlen($mac));
        $this->assertSame(hash_hmac('sha256', '', $grantKey, true), $mac);
        $this->assertSame('9JJAt4qpDlP0YQbDSB43fhlpbndzKLHNbsYGGgvYaKk', Crypto::b64url($mac), '§3.9: the empty message under the grant key');
    }

    public function testHmacMatchesTheItemMacVector(): void
    {
        $this->assertSame('c76N-plOsFpBIWurwIvrMg5hXeo4tnXzK_MOQ1dnfRw', Crypto::b64url(Crypto::hmac(self::bytes(0x20, 0x3f), self::canonicalItem())));
    }

    public function testDerivedKeyNeverReturnsTheRingKey(): void
    {
        $ringKey = base64_decode($this->keys['k2'], true);
        [$kid, $key] = Crypto::derivedKey('pfpms/v1/pin-pepper');
        $this->assertSame('k2', $kid, 'the active key id');
        $this->assertSame(32, strlen($key));
        $this->assertNotSame($ringKey, $key);
        $this->assertSame(hash_hkdf('sha256', $ringKey, 32, 'pfpms/v1/pin-pepper', ''), $key);
        [, $empty] = Crypto::derivedKey('');
        $this->assertNotSame($ringKey, $empty, 'not even with an empty info');
    }

    public function testDerivedKeyUsesTheNamedKeyIdAndItsPurpose(): void
    {
        [$kid, $k1] = Crypto::derivedKey('pfpms/v1/pin-pepper', 'k1');
        $this->assertSame('k1', $kid);
        $this->assertSame(hash_hkdf('sha256', str_repeat('a', 32), 32, 'pfpms/v1/pin-pepper', ''), $k1);
        $this->assertNotSame($k1, Crypto::derivedKey('pfpms/v1/pin-pepper')[1], 'k1 and the active k2 differ');
        $this->assertNotSame(Crypto::derivedKey('pfpms/v1/pin-pepper')[1], Crypto::derivedKey('pfpms/v1/device-credential')[1], 'each purpose has its own key');
    }

    public function testDerivedKeyOfAnUnconfiguredKeyIdIsAConfigurationError(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Encryption key 'k9' is not configured");
        Crypto::derivedKey('pfpms/v1/pin-pepper', 'k9');
    }

    private function useActiveKey(string $id): void
    {
        Config::override(['crypto' => ['active' => $id, 'keys' => $this->keys]] + $this->saved);
    }
}

<?php
declare(strict_types=1);

namespace Pfpms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pfpms\Device\RegistrationCode;

/** Tablet registration codes (plan P2A admin_devices) against the fixtures the Station's JS port must also pass. */
final class RegistrationCodeTest extends TestCase
{
    private static function fixtures(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/../fixtures/registration_code.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testTheSharedFixtures(): void
    {
        $f = self::fixtures();
        foreach ($f['check'] as $case) {
            $this->assertSame($case['check'], RegistrationCode::checkSymbol($case['data']), $case['data']);
        }
        foreach ($f['bytes'] as $case) {
            $this->assertSame($case['code'], RegistrationCode::fromBytes((string) hex2bin($case['hex'])), $case['hex']);
        }
        foreach ($f['normalise'] as $case) {
            $this->assertSame($case['out'], RegistrationCode::normalise($case['in']), $case['in']);
        }
        foreach ($f['format'] as $case) {
            $this->assertSame($case['out'], RegistrationCode::format($case['in']));
        }
        foreach ($f['pairing'] as $case) {
            $this->assertSame($case['check'], RegistrationCode::pairingCheck($case['token_hash']));
        }
        $this->assertSame($f['pairing'][0]['token_hash'], hash('sha256', 'pfd1_test'));
    }

    public function testGeneratedCodesAreValidAndUseTheWholeAlphabet(): void
    {
        $seen = [];
        for ($i = 0; $i < 2000; $i++) {
            $code = RegistrationCode::generate();
            $this->assertMatchesRegularExpression('/^[' . RegistrationCode::ALPHABET . ']{17}$/', $code);
            $this->assertSame($code, RegistrationCode::normalise($code));
            $this->assertSame($code, RegistrationCode::normalise(RegistrationCode::format($code)));
            $this->assertSame($code, RegistrationCode::normalise(RegistrationCode::qrPayload($code)));
            $seen += array_flip(str_split(substr($code, 0, 16)));
        }
        $this->assertCount(32, $seen, 'every symbol appears');
    }

    public function testEverySingleSymbolMistakeIsCaught(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $code = RegistrationCode::generate();
            for ($pos = 0; $pos < 17; $pos++) {
                foreach (str_split(RegistrationCode::ALPHABET) as $symbol) {
                    if ($symbol !== $code[$pos]) {
                        $wrong = substr_replace($code, $symbol, $pos, 1);
                        $this->assertNull(RegistrationCode::normalise($wrong), "$code with $symbol at $pos");
                    }
                }
            }
        }
    }
}

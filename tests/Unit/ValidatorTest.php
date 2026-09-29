<?php
declare(strict_types=1);

namespace Pfpms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pfpms\Validation\Validator;

/** The ported legacy validators, including the bugs fixed during the port (plan §7). */
final class ValidatorTest extends TestCase
{
    public function testDateRequiresARealCalendarDate(): void
    {
        $this->assertSame('2026-02-28', Validator::date('2026-02-28'));
        $this->assertNull(Validator::date('2026-02-30'));
        $this->assertNull(Validator::date('02/28/2026'));
        $this->assertNull(Validator::date(null));
    }

    public function testTime24hIsAnchored(): void
    {
        $this->assertSame('09:30', Validator::time24h('09:30'));
        $this->assertNull(Validator::time24h('x09:30y'), 'legacy regex had no anchors');
        $this->assertNull(Validator::time24h('24:00'));
    }

    public function testTimeRangeChecksBothEnds(): void
    {
        $this->assertTrue(Validator::time24hRange('09:00', '12:00'));
        $this->assertFalse(Validator::time24hRange('09:00', 'noon'), 'legacy code checked the start twice');
        $this->assertFalse(Validator::time24hRange('12:00', '09:00'));
    }

    public function testPhoneAcceptsALeadingCountryCode(): void
    {
        $this->assertSame('8435550142', Validator::phone('(843) 555-0142'));
        $this->assertSame('8435550142', Validator::phone('+1 843 555 0142'));
        $this->assertNull(Validator::phone('555-0142'));
        $this->assertNull(Validator::phone('2 843 555 0142'));
    }

    public function testZipAcceptsZipPlusFour(): void
    {
        $this->assertSame('29401', Validator::zip(' 29401 '));
        $this->assertSame('29401-1234', Validator::zip('294011234'));
        $this->assertSame('29401-1234', Validator::zip('29401-1234'));
        $this->assertNull(Validator::zip('2940'));
    }

    public function testUrlAllowsOnlyHttpAndHttps(): void
    {
        $this->assertSame('https://maps.example.org/x', Validator::url('https://maps.example.org/x'));
        $this->assertNull(Validator::url('javascript:alert(1)'));
        $this->assertNull(Validator::url('ftp://example.org'));
    }

    public function testOneOfIsStrict(): void
    {
        $this->assertSame('Dog', Validator::oneOf('Dog', ['Dog', 'Cat']));
        $this->assertNull(Validator::oneOf('0', [0, 'Cat']));
    }

    public function testTextTrimsAndCapsLength(): void
    {
        $this->assertSame('Jane Doe', Validator::text("  Jane \t Doe ", 20));
        $this->assertNull(Validator::text('   ', 20));
        $this->assertNull(Validator::text(str_repeat('x', 21), 20));
    }

    public function testEmailHasALengthCap(): void
    {
        $this->assertSame('a@example.org', Validator::email(' a@example.org '));
        $this->assertNull(Validator::email(str_repeat('a', 95) . '@x.org'));
        $this->assertNull(Validator::email('not-an-email'));
    }

    public function testDecimalIsCanonicalAndBounded(): void
    {
        $this->assertSame('4.50', Validator::decimal('4.5', 2, '200'), 'same form the DECIMAL column returns');
        $this->assertSame('12.00', Validator::decimal(' 12 ', 2, '200'));
        $this->assertSame('0.00', Validator::decimal('0', 2, '200'));
        $this->assertSame('0.25', Validator::decimal('.25', 2, '200'));
        $this->assertSame('7.00', Validator::decimal('7.', 2, '200'));
        $this->assertSame('200.00', Validator::decimal('200', 2, '200'));
        $this->assertSame('199.99', Validator::decimal('199.99', 2, '199.99'));
        $this->assertSame('7', Validator::decimal('7', 0, '10'));
        foreach (['200.01', '1.234', '-1', '1e3', '1,000', '0x10', '', ' ', '.', '1.2.3', 'abc'] as $bad) {
            $this->assertNull(Validator::decimal($bad, 2, '200'), "refuses '$bad'");
        }
        $this->assertNull(Validator::decimal('7.0', 0, '10'), 'no decimals when the scale is 0');
        $this->assertNull(Validator::decimal(null, 2, '200'));
        $this->assertSame('-3.05', Validator::fromUnits(-305, 2));
        $this->assertSame('0.07', Validator::fromUnits(7, 2));
    }
}

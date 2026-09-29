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
}

<?php
declare(strict_types=1);

namespace Pfpms\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** UC-01 / NFR-SEC-005: a session ends after 30 minutes without a request. */
final class SessionTimeoutTest extends TestCase
{
    private const NOW = 1_800_000_000;

    public function testTheLimitIsThirtyMinutes(): void
    {
        $this->assertSame(1800, PFPMS_SESSION_TIMEOUT_SECONDS);
    }

    public function testActivityWithinThirtyMinutesKeepsTheSession(): void
    {
        $this->assertFalse(pfpms_session_expired(self::NOW, self::NOW));
        $this->assertFalse(pfpms_session_expired(self::NOW - 29 * 60, self::NOW));
        $this->assertFalse(pfpms_session_expired(self::NOW - 1800, self::NOW), 'exactly 30 minutes is still allowed');
    }

    public function testMoreThanThirtyMinutesEndsTheSession(): void
    {
        $this->assertTrue(pfpms_session_expired(self::NOW - 1801, self::NOW));
        $this->assertTrue(pfpms_session_expired(self::NOW - 3 * 3600, self::NOW));
    }

    public function testASessionWithNoRecordedActivityIsNotEnded(): void
    {
        // Sessions started before this feature have no last_activity yet; the
        // first request records it rather than signing the user out.
        $this->assertFalse(pfpms_session_expired(null, self::NOW));
    }
}

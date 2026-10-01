<?php
declare(strict_types=1);

namespace Pfpms\Device;

use DateTimeImmutable;
use DateTimeZone;
use Pfpms\Clock;

/**
 * A tablet's state, worked out from its device row (DeviceRepository::COLUMNS) and never stored,
 * with the words and warnings the admin pages show. No database access.
 *
 * Figures the tablet reports (last heard from, unsynced records, build, storage kept) are written
 * only by the Phase 2B heartbeat; until a tablet has reported, they are shown as "not reported",
 * never as a misleading 0 or No. The warnings are for people only: no decision depends on them.
 */
final class DeviceStatus
{
    public const ERASED = 'erased';
    public const CANCELLED = 'cancelled';
    public const ERASING = 'erasing';
    public const RETIRING = 'retiring';
    public const AWAITING = 'awaiting';
    public const NO_CODE = 'no_code';
    public const IN_SERVICE = 'in_service';

    /** A tablet holding unsynced records that has not been heard from for this long gets a warning. */
    public const STALE_PENDING_HOURS = 24;

    /** The Station build the server serves now: tablets reporting another build are older. P2B's api/ping.php returns the same. */
    public static function currentBuild(): string
    {
        return APP_VERSION;
    }

    /**
     * The state, checked in this order (an erased tablet is erased even though it is also revoked).
     * Needs a DeviceRepository::find() or list() row (it tells "waiting" apart by the live code).
     */
    public static function code(array $d): string
    {
        if (!array_key_exists('code_expires_at', $d)) {
            throw new \LogicException('DeviceStatus::code() needs a DeviceRepository::find() or list() row; use waitingForRegistration() on a locked row.');
        }
        $credential = (int) ($d['has_credential'] ?? 0) === 1;
        return match (true) {
            $d['wiped_at'] !== null => self::ERASED,
            $d['revoked_at'] !== null && !$credential => self::CANCELLED,
            $d['revoked_at'] !== null && $d['wipe_mode'] === 'Wipe Now' => self::ERASING,
            $d['revoked_at'] !== null => self::RETIRING,
            !$credential && ($d['code_expires_at'] ?? null) !== null => self::AWAITING,
            !$credential => self::NO_CODE,
            default => self::IN_SERVICE,
        };
    }

    /** A tablet no tablet has registered yet, and not cancelled: what P2B's redemption requires of the locked row (the code itself is checked by Tokens). */
    public static function waitingForRegistration(array $d): bool
    {
        return (int) ($d['has_credential'] ?? 0) !== 1 && $d['revoked_at'] === null && $d['wiped_at'] === null;
    }

    /** The PHP mirror of DeviceRepository::IN_SERVICE_SQL (P2B trusts only this). */
    public static function inService(array $d): bool
    {
        return (int) ($d['has_credential'] ?? 0) === 1 && (int) $d['is_site_registered'] === 1 && $d['revoked_at'] === null && $d['wiped_at'] === null;
    }

    /**
     * @param string $timeZone the tablet's site's zone (or the organisation's when it has no site)
     * @param bool $offlineAllowed the offline_mode_enabled setting
     * @param string $currentBuild the Station build the server serves now
     * @param int $clockToleranceSeconds a tablet clock further than this from the server's gets a warning (sync_clock_skew_minutes)
     * @return array{code: string, label: string, badge: string, detail: ?string, warnings: list<string>}
     */
    public static function describe(array $d, DateTimeImmutable $now, string $timeZone, bool $offlineAllowed, string $currentBuild,
        int $clockToleranceSeconds = 600): array
    {
        $code = self::code($d);
        $when = fn(?string $utc) => self::at($utc, $timeZone);
        [$label, $badge] = match ($code) {
            self::ERASED => ['Erased ' . $when($d['wiped_at']), 'badge badge-inactive'],
            self::CANCELLED => ['Cancelled before any tablet used it', 'badge badge-inactive'],
            self::ERASING => ['Erase requested ' . $when($d['erase_requested_at'] ?? $d['revoked_at']), 'badge badge-problem'],
            self::RETIRING => ['Retired ' . $when($d['revoked_at']), 'badge'],
            self::AWAITING, self::NO_CODE => ['Waiting for the tablet', 'badge'],
            default => [self::serviceLabel($d, $offlineAllowed), 'badge'],
        };
        // A short badge, with the rest apart (never what a warning below already says).
        $reason = $code === self::IN_SERVICE ? self::onlineOnlyReason($d, $offlineAllowed) : null;
        $detail = match ($code) {
            self::AWAITING => 'Its code works until ' . $when($d['code_expires_at']) . '.',
            self::NO_CODE => 'It has no working code.',
            self::RETIRING => (int) $d['revoked_lost'] === 1 ? 'Reported lost or stolen.' : null,
            self::IN_SERVICE => $reason === 'Its storage is not kept.' ? null : $reason,
            default => null,
        };

        $warnings = [];
        $heard = $d['last_seen_at'] !== null;
        $pending = (int) $d['pending_count'];
        $working = in_array($code, [self::IN_SERVICE, self::RETIRING], true);
        // Figures only the P2B heartbeat writes: rows from queries that do not select them give no warning.
        $reportedMax = $d['reported_max_seq'] ?? null;
        $received = $d['received_count'] ?? null;
        if ($working && $reportedMax !== null && $received !== null && ($gap = (int) $reportedMax - (int) $received - $pending) > 0) {
            $warnings[] = $gap . ' record' . ($gap === 1 ? '' : 's') . ' it numbered ' . ($gap === 1 ? 'has' : 'have')
                . ' neither reached the server nor been reported as waiting on it. They may be lost; ask who used it.';
        }
        if ($working && ($d['locked_out_since'] ?? null) !== null) {
            $warnings[] = 'It locked itself after too many wrong passwords (' . $when($d['locked_out_since'])
                . '). Its unsynced records are kept; someone must sign in on it with a connection.';
        }
        $attention = (int) ($d['attention_count'] ?? 0);
        if ($working && $attention > 0) {
            $warnings[] = $attention . ' record' . ($attention === 1 ? '' : 's') . ' on it could not be uploaded. They are kept on the tablet; tell the Administrator.';
        }
        if (in_array($code, [self::IN_SERVICE, self::RETIRING], true) && $heard && $pending > 0
            && Clock::fromDb($d['last_seen_at'])->modify('+' . self::STALE_PENDING_HOURS . ' hours') <= $now) {
            $warnings[] = 'Holds ' . $pending . ' unsynced record' . ($pending === 1 ? '' : 's') . ', reported ' . self::ago($d['last_seen_at'], $now)
                . '. Connect it to the internet so they upload.';
        }
        if ($code === self::IN_SERVICE && $heard && (int) $d['storage_persisted'] !== 1) {
            $warnings[] = 'It is not keeping its data, so it cannot work offline. Open the installed app, not a browser tab, and allow storage when asked.';
        }
        $mode = $d['display_mode'] ?? null;
        if ($code === self::IN_SERVICE && $mode !== null && $mode !== 'standalone') {
            $warnings[] = 'It is running in a browser tab, not the installed app, so it cannot work offline. Open the Station from the tablet\'s home screen.';
        }
        $skew = $d['clock_skew_seconds'] ?? null;
        if ($code === self::IN_SERVICE && $skew !== null && abs((int) $skew) > $clockToleranceSeconds) {
            $minutes = (int) round(abs((int) $skew) / 60);
            // clock_skew_seconds is the server's time minus the tablet's: positive means the tablet is behind.
            $warnings[] = 'Its clock is ' . $minutes . ' minute' . ($minutes === 1 ? '' : 's') . ' ' . ((int) $skew > 0 ? 'slow' : 'fast')
                . ". Records are timed correctly, but set the tablet's clock to automatic.";
        }
        if ($code === self::IN_SERVICE && $d['app_build'] !== null && $d['app_build'] !== $currentBuild) {
            $warnings[] = 'Runs an older version of the Station (' . $d['app_build'] . '). It updates itself the next time it is opened online.';
        }
        if (isset($d['site_active']) && (int) $d['site_active'] === 0 && ($todo = match ($code) {
            self::IN_SERVICE => ' Retire this tablet.',
            self::AWAITING, self::NO_CODE => ' Cancel this registration.',
            default => null, // already out of service: nothing to do
        }) !== null) {
            $warnings[] = 'Its site, ' . ($d['site_name'] ?? 'unknown') . ', is deactivated.' . $todo;
        }
        if ($d['revoked_at'] !== null && $heard && $d['last_seen_at'] > $d['revoked_at']) {
            $warnings[] = 'Connected after it was taken out of service (' . $when($d['last_seen_at']) . ').';
        }
        return ['code' => $code, 'label' => $label, 'badge' => $badge, 'detail' => $detail, 'warnings' => $warnings];
    }

    /** An in-service tablet in a few words: "Not heard from yet", "Ready to work offline" or "Works online only". */
    public static function serviceLabel(array $d, bool $offlineAllowed): string
    {
        if ($d['last_seen_at'] === null) {
            return 'Not heard from yet';
        }
        return self::onlineOnlyReason($d, $offlineAllowed) === null ? 'Ready to work offline' : 'Works online only';
    }

    /** Why a tablet that has reported cannot work offline, or null when it can (or has not reported yet). */
    public static function onlineOnlyReason(array $d, bool $offlineAllowed): ?string
    {
        if ($d['last_seen_at'] === null || ((int) $d['offline_enabled'] === 1 && (int) $d['storage_persisted'] === 1 && $offlineAllowed)) {
            return null;
        }
        return match (true) {
            !$offlineAllowed => 'Offline working is switched off for all tablets in Settings.',
            (int) $d['storage_persisted'] !== 1 => 'Its storage is not kept.',
            default => 'Offline is not switched on yet.',
        };
    }

    /** "never", "just now", "12 minutes ago", "3 hours ago", "2 days ago". */
    public static function ago(?string $utc, DateTimeImmutable $now): string
    {
        if ($utc === null) {
            return 'never';
        }
        $seconds = $now->getTimestamp() - Clock::fromDb($utc)->getTimestamp();
        $plural = fn(int $n, string $unit) => "$n $unit" . ($n === 1 ? '' : 's') . ' ago';
        return match (true) {
            $seconds < 60 => 'just now',
            $seconds < 3600 => $plural(intdiv($seconds, 60), 'minute'),
            $seconds < 86400 => $plural(intdiv($seconds, 3600), 'hour'),
            default => $plural(intdiv($seconds, 86400), 'day'),
        };
    }

    /** A UTC time as the site reads it: "Oct 1, 8:00 AM". */
    public static function at(?string $utc, string $timeZone): string
    {
        return $utc === null ? '' : Clock::fromDb($utc)->setTimezone(new DateTimeZone($timeZone))->format('M j, g:i A');
    }
}

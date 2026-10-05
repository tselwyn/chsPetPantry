<?php
declare(strict_types=1);

namespace Pfpms\Device;

use DateTimeImmutable;
use Pfpms\Audit\Audit;
use Pfpms\Auth\Tokens;
use Pfpms\Clock;
use Pfpms\Db;
use Pfpms\Http\HttpException;
use Pfpms\Http\Json;
use Pfpms\Http\Request;
use Pfpms\Security\RateLimit;
use Pfpms\Settings;
use Pfpms\Station\StationConfig;

/**
 * The tablet reports in and learns its directive, before anyone signs in (40-design §14.4; 50-design §6.4).
 * Credential (and proof) only. It writes what the tablet reported, never label, site, registration or
 * revocation columns, so no admin form goes stale. A revoked tablet's wipe confirmation counts only with a
 * valid proof signature, because accepting it makes the server forget the keys that open the tablet's
 * unsent records.
 */
final class DeviceHeartbeat
{
    private const DISPLAY_MODES = ['standalone', 'browser', 'minimal-ui', 'fullscreen'];
    private const MAX_COUNT = 10000000;
    private const MAX_INT = 2147483647;

    /** @return array{status: int, body: array, headers: array<string, string>} */
    public static function receive(array $device, array $body): array
    {
        $id = (int) $device['device_id'];
        // Proven calls (a valid proof, or a seed row without a key) and the others have separate budgets, so a copied
        // credential can never use up the real tablet's heartbeats and keep its directive from it (a stale proof counts
        // as unproven here: a signed request copied from the network log and replayed).
        $proven = in_array($device['proof'] ?? 'none', ['valid', 'none'], true);
        if (!RateLimit::hit('heartbeat:device:' . $id . ($proven ? '' : ':unproven'), 120, 900)) {
            throw new HttpException(429, '', 'rate_limited',
                $device['revoked_at'] !== null ? ['status' => 'revoked', 'directive' => DeviceGuard::directive($device)] : [], ['Retry-After' => '900']);
        }
        $clientNow = Clock::fromClient(Json::string($body, 'client_now', 23));
        $skew = $clientNow === null ? null : (int) round(((int) Clock::now()->format('Uv') - (int) $clientNow->format('Uv')) / 1000);
        if ($skew !== null && abs($skew) > 7 * 86400) {
            $skew = null;
        }
        $report = self::report($body, $skew);
        if (!$proven) {
            $report['max_seq'] = null; // only the tablet's own signed reports may raise reported_max_seq (it never comes down)
        }
        if ($report['wiped']) {
            $confirmed = self::confirmWipe($device, $report);
            if ($confirmed !== null) {
                return $confirmed;
            }
        }

        $relaxed = StationConfig::relaxInstallChecks();
        $mode = $report['display_mode'] ?? $device['display_mode'];
        $eligible = (int) ($device['site_active'] ?? 0) === 1 && ($report['storage_persisted'] || $relaxed)
            && ($mode === 'standalone' || $relaxed) && Settings::bool('offline_mode_enabled', true);
        // Every row written from the tablet's report says whether the request carried the tablet's own proof, so a
        // report sent with a copied credential can be told apart in the audit trail.
        $proof = (string) ($device['proof'] ?? 'none');
        $after = Db::transaction(static function () use ($device, $report, $eligible, $skew, $id, $proof): array {
            // "Before" is read under the row lock, so a Retire that committed after the guard's read is not recorded again here.
            $before = DeviceRepository::lock($id) ?? $device;
            DeviceRepository::heartbeat($id, $report, $eligible, $skew, Clock::db());
            $after = DeviceRepository::lock($id) ?? $before;
            $changes = Audit::diff($before, $after, ['storage_persisted', 'app_build', 'display_mode', 'offline_enabled', 'locked_out_since']);
            if ($changes) {
                Audit::record('device_state', 'device', $id, details: ['proof' => $proof], changes: $changes, redactChanges: false);
            }
            if ($report['failed_unlock_wipe']) {
                Audit::record('device_unlock_wipe', 'device', $id, 'Success',
                    'The tablet erased its offline sign-ins after too many wrong passwords; its unsent records were kept',
                    ['pending_count' => (int) $after['pending_count'], 'proof' => $proof]);
            }
            if ($report['clock_rollback']) {
                Audit::record('device_clock_rollback', 'device', $id, 'Denied', 'The tablet clock was set back', ['proof' => $proof]);
            }
            if ($report['auth_failures']) {
                Audit::record('offline_auth_failures', 'device', $id, 'Failed', 'Wrong password or PIN while offline (reported by the tablet)',
                    ['failures' => $report['auth_failures'], 'proof' => $proof],
                    actor: ['user_id' => null, 'session_id' => null, 'occurred_at' => self::failureTime($report['auth_failures'], $skew)]);
            }
            return $after;
        });
        if ($proven && $report['max_seq'] !== null && $device['reported_max_seq'] !== null && $report['max_seq'] < (int) $device['reported_max_seq']) {
            DeviceGuard::noteUnproven($device, Request::scriptPath(), 'seq_went_back');
        }
        return ['status' => 200, 'body' => self::response($device, $after), 'headers' => []];
    }

    /**
     * Typed, bounded, lenient parsing of the heartbeat body: an invalid optional field becomes null, a missing
     * pending_count keeps the stored value, storage_persisted defaults to false. Tablet times go through
     * Clock::fromClient(), get the skew, are capped at now and become Clock::db() text for the DATETIME(0) columns.
     * @return array{app_build: ?string, storage_persisted: bool, display_mode: ?string, pending_count: ?int, attention_count: ?int,
     *   max_seq: ?int, oldest_pending_at: ?string, storage_estimate_kb: ?int, locked_out_since: ?string, failed_unlock_wipe: bool,
     *   clock_rollback: bool, auth_failures: list<array>, wiped: bool, items_pushed: ?int}
     */
    private static function report(array $body, ?int $skewSeconds): array
    {
        $build = Json::string($body, 'app_build', 40);
        $mode = Json::string($body, 'display_mode', 20);
        return [
            'app_build' => $build !== null && preg_match('/^[0-9A-Za-z.+_-]{1,40}$/D', $build) === 1 ? $build : null,
            'storage_persisted' => Json::bool($body, 'storage_persisted') ?? false,
            'display_mode' => $mode === null ? null : (in_array($mode, self::DISPLAY_MODES, true) ? $mode : 'other'),
            'pending_count' => Json::int($body, 'pending_count', 0, self::MAX_COUNT),
            'attention_count' => Json::int($body, 'attention_count', 0, self::MAX_COUNT),
            'max_seq' => Json::int($body, 'max_seq', 0, self::MAX_INT),
            'oldest_pending_at' => self::reportedTime(Json::string($body, 'oldest_pending_at', 23), $skewSeconds),
            'storage_estimate_kb' => Json::int($body, 'storage_estimate_kb', 0, self::MAX_INT),
            'locked_out_since' => self::reportedTime(Json::string($body, 'locked_out_since', 23), $skewSeconds),
            'failed_unlock_wipe' => Json::bool($body, 'failed_unlock_wipe') ?? false,
            'clock_rollback' => Json::bool($body, 'clock_rollback') ?? false,
            'auth_failures' => self::failures(Json::list($body, 'auth_failures', 10000) ?? []),
            'wiped' => Json::bool($body, 'wiped') ?? false,
            'items_pushed' => Json::int($body, 'items_pushed', 0, self::MAX_COUNT),
        ];
    }

    /**
     * The wipe confirmation of a revoked tablet, or null to carry on with the normal path (the claim was ignored).
     * @return ?array{status: int, body: array, headers: array<string, string>}
     */
    private static function confirmWipe(array $device, array $report): ?array
    {
        $id = (int) $device['device_id'];
        if ($device['revoked_at'] === null) {
            self::wipeClaim($id, 'A tablet in service said it had erased itself', 'in_service');
            return null;
        }
        if ($device['proof'] === 'stale') {
            throw new HttpException(401, "The tablet's clock is too far from the server's. Try again.", 'device_proof_stale', ['server_time' => Clock::dbMillis()]);
        }
        if ($device['proof'] !== 'valid') {
            self::wipeClaim($id, 'An erase confirmation was not signed by the tablet', $device['proof'] === 'none' ? 'no_proof_key' : 'no_proof');
            return null;
        }
        Db::transaction(static function () use ($id, $report): void {
            $d = DeviceRepository::lock($id);
            if ($d === null || $d['revoked_at'] === null || $d['wiped_at'] !== null) {
                return; // erased meanwhile: the answer is the same
            }
            DeviceRepository::confirmWipe($id, Clock::db());
            $cleared = DeviceRepository::shredGrantSecrets($id);
            Audit::record('device_wiped', 'device', $id, details: ['wipe_mode' => $d['wipe_mode'], 'items_pushed' => $report['items_pushed'],
                'reported_pending_count' => (int) $d['pending_count'], 'grant_keys_cleared' => $cleared],
                actor: ['user_id' => null, 'session_id' => null, 'site_id' => $d['site_id'] === null ? null : (int) $d['site_id']]);
        });
        return ['status' => 200, 'body' => ['status' => 'wiped', 'server_time' => Clock::dbMillis(), 'build' => DeviceStatus::currentBuild()],
            'headers' => ['Clear-Site-Data' => '"cache", "storage"']];
    }

    /** A wipe claim the server did not accept: one audit row per tablet per hour, since the tablet keeps retrying. */
    private static function wipeClaim(int $id, string $reason, string $reasonCode): void
    {
        if (RateLimit::hit('device_wipe_claim:' . $id, 1, 3600)) {
            Audit::record('device_wipe_claim', 'device', $id, 'Denied', $reason, ['reason_code' => $reasonCode]);
        }
    }

    /**
     * Offline sign-in failures the tablet grouped per claimed person and factor (at most 20 groups; malformed ones
     * are dropped). They are audit only: never the lockout counters (50-design D-31).
     * @return list<array{user_id: ?int, factor: string, count: int, first_at: string, last_at: string}>
     */
    private static function failures(array $groups): array
    {
        $out = [];
        foreach ($groups as $group) {
            if (count($out) >= 20) {
                break;
            }
            if (!is_array($group)) {
                continue;
            }
            $factor = Json::string($group, 'factor', 10);
            $count = Json::int($group, 'count', 1, 1000);
            $first = Clock::fromClient(Json::string($group, 'first_at', 23));
            $last = Clock::fromClient(Json::string($group, 'last_at', 23));
            $userId = array_key_exists('user_id', $group) && $group['user_id'] === null ? null : Json::int($group, 'user_id', 1, self::MAX_INT);
            if (!in_array($factor, ['password', 'pin'], true) || $count === null || $first === null || $last === null
                || (array_key_exists('user_id', $group) && $group['user_id'] !== null && $userId === null)) {
                continue;
            }
            $out[] = ['user_id' => $userId, 'factor' => $factor, 'count' => $count, 'first_at' => Clock::dbMillis($first), 'last_at' => Clock::dbMillis($last)];
        }
        return $out;
    }

    /**
     * The response of a routine heartbeat. Status and directive come from the row as locked after the UPDATE, so a
     * tablet retired a moment ago learns it now; the site's name comes from the guard's read.
     */
    private static function response(array $device, array $after): array
    {
        $id = (int) $device['device_id'];
        return [
            'status' => $after['revoked_at'] !== null ? 'revoked' : 'ok',
            'offline_enabled' => (int) $after['offline_enabled'] === 1,
            'label' => (string) $device['label'],
            'site' => $device['site_id'] === null ? null : ['site_id' => (int) $device['site_id'], 'name' => (string) ($device['site_name'] ?? '')],
            'directive' => DeviceGuard::directive($after),
            'revoked_grants' => Tokens::revokedGrantIds($id),
            'config' => StationConfig::client(),
            'server_time' => Clock::dbMillis(),
            'build' => DeviceStatus::currentBuild(),
        ];
    }

    /** A time the tablet reported: its clock corrected by the skew when known, never in the future, whole seconds. */
    private static function reportedTime(?string $value, ?int $skewSeconds): ?string
    {
        $at = Clock::fromClient($value);
        if ($at === null) {
            return null;
        }
        if ($skewSeconds !== null) {
            $at = $at->modify(($skewSeconds >= 0 ? '+' : '-') . abs($skewSeconds) . ' seconds');
        }
        $now = Clock::now();
        return Clock::db($at > $now ? $now : $at);
    }

    /** When the latest reported failure happened: its time plus the skew, never after now, and at most 30 days back. */
    private static function failureTime(array $failures, ?int $skewSeconds): string
    {
        $latest = max(array_column($failures, 'last_at'));
        $at = Clock::fromDb($latest) ?? Clock::now();
        if ($skewSeconds !== null) {
            $at = $at->modify(($skewSeconds >= 0 ? '+' : '-') . abs($skewSeconds) . ' seconds');
        }
        $now = Clock::now();
        $floor = $now->modify('-30 days');
        return Clock::dbMillis(self::between($at, $floor, $now));
    }

    private static function between(DateTimeImmutable $at, DateTimeImmutable $min, DateTimeImmutable $max): DateTimeImmutable
    {
        return $at < $min ? $min : ($at > $max ? $max : $at);
    }
}

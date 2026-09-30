<?php
declare(strict_types=1);

namespace Pfpms\Device;

use Pfpms\Account\AccountRepository;
use Pfpms\Audit\Audit;
use Pfpms\Auth\AccountRules;
use Pfpms\Auth\Rbac;
use Pfpms\Auth\SessionStore;
use Pfpms\Auth\Tokens;
use Pfpms\Clock;
use Pfpms\Db;
use Pfpms\Notify\Notifications;
use Pfpms\Reference\SiteRepository;
use Pfpms\Settings;
use Pfpms\Validation\ValidationException;
use Pfpms\Validation\Validator;

/**
 * Tablets that run the Station (plan P2A admin_devices). Coordinators (device.register) manage
 * the tablets of their own sites; only an Administrator (device.erase) can have a tablet erase
 * itself without uploading.
 *
 * - Add a tablet (site and name), then print its single-use registration code. The installed
 *   Station redeems the code in Phase 2B; nobody signs in on the tablet to register it.
 * - Taking a tablet out of service always tells it to erase itself: Retire (upload what it holds,
 *   then erase) or Erase now (erase without uploading). It is final: to use the tablet again, add
 *   it as a new tablet. At once, on the server: its codes and grants stop working, its Station
 *   sessions end, and it stops counting as registered. The tablet itself acts when it next connects.
 * - A retired tablet can later be reported lost or stolen, or escalated to Erase now; either way its
 *   upload cut-off comes down to what the server has received (never what the tablet reports), and
 *   P2B holds every upload from it received after the revocation for review.
 * - Adding, renaming, codes and cancelling run under the tablet's named lock (P2B's registration
 *   takes it too). Retire, Erase now and reporting lost only lock the row, which waits its turn, so
 *   whoever holds a tablet can never keep it in service by keeping its lock busy.
 * - Every change locks the row, re-checks the person's scope, and refuses a form showing an older
 *   state (revision). Refusals happen before any write, so a durable Denied row never names a row
 *   this transaction changed. Lock order: named locks, the device row, the person's account row,
 *   then tokens, then sessions.
 */
final class DeviceService
{
    public const LABEL_MAX = 50;
    public const REASON_MAX = 255;
    public const PENDING_LIMIT = 10; // tablets waiting to be registered, per site
    public const RETIRE = 'Push Then Wipe';
    public const ERASE = 'Wipe Now';

    /**
     * @param array{site_id?: ?string, label?: ?string} $input
     * @throws ValidationException
     */
    public static function add(array $input, DeviceScope $scope): int
    {
        $errors = [];
        $label = Validator::text($input['label'] ?? null, self::LABEL_MAX);
        if ($label === null) {
            $errors['label'] = 'Enter a name for the tablet (up to 50 characters), for example "Front desk 1".';
        }
        $siteId = Validator::wholeNumber($input['site_id'] ?? null, 1, 999999999);
        // Outside the person's sites nothing is looked up, so the answer never tells whether a site exists or is active.
        $site = $siteId !== null && ($scope->allSites || $scope->canAddAt($siteId)) ? SiteRepository::find($siteId) : null;
        if ($siteId !== null && !$scope->allSites && !$scope->canAddAt($siteId)) {
            Audit::durable('access_denied', 'site', $siteId, 'Denied', 'Add a tablet at a site not available to this user');
            $errors['site_id'] = 'Choose one of your sites.';
        } elseif ($siteId === null || $site === null) {
            $errors['site_id'] = 'Choose the site this tablet will be used at.';
        } elseif (!$scope->canAddAt($siteId)) {
            $errors['site_id'] = $site['name'] . ' is not active, so no tablet can be added there.'; // site.all holders only
        } elseif ($label !== null && DeviceRepository::labelTaken($siteId, $label, null)) {
            $errors['label'] = self::labelTakenMessage($site['name'], $label);
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        return DeviceLocks::site($siteId, fn(): int => Db::transaction(function () use ($siteId, $site, $label, $scope): int {
            if (DeviceRepository::labelTaken($siteId, $label, null)) {
                throw ValidationException::one('label', self::labelTakenMessage($site['name'], $label));
            }
            if (DeviceRepository::pendingCount($siteId) >= self::PENDING_LIMIT) {
                throw ValidationException::one('_form', $site['name'] . ' already has ' . self::PENDING_LIMIT
                    . ' tablets waiting to be registered. Register or cancel some of them first.');
            }
            $id = DeviceRepository::insertPending($siteId, $label, $scope->actorId, Clock::db());
            Audit::record('device_add', 'device', $id, details: ['site_id' => $siteId, 'label' => $label], actor: ['site_id' => $siteId]);
            return $id;
        }));
    }

    /**
     * @return bool false when the name did not change
     * @throws ValidationException
     * @throws StaleDeviceException
     */
    public static function rename(int $deviceId, ?string $label, ?string $revision, DeviceScope $scope): bool
    {
        $name = Validator::text($label, self::LABEL_MAX)
            ?? throw ValidationException::one('label', 'Enter a name for the tablet (up to 50 characters), for example "Front desk 1".');
        $current = DeviceRepository::find($deviceId) ?? throw ValidationException::one('_form', 'That tablet no longer exists.');
        $work = fn(): bool => self::onDevice($deviceId, $scope, 'rename', true, function (array $d) use ($deviceId, $name, $revision, $current): bool {
            if ($d['revoked_at'] !== null || $d['wiped_at'] !== null) {
                throw ValidationException::one('_form', 'This tablet has been taken out of service, so its name can no longer change.');
            }
            if ($name === $d['label']) {
                return false; // before the revision check, so a repeated post is harmless
            }
            self::checkRevision($d, $revision);
            if ($d['site_id'] !== null && DeviceRepository::labelTaken((int) $d['site_id'], $name, $deviceId)) {
                throw ValidationException::one('label', self::labelTakenMessage((string) $current['site_name'], $name));
            }
            DeviceRepository::rename($deviceId, $name);
            Audit::record('device_rename', 'device', $deviceId, changes: ['label' => [$d['label'], $name]], actor: self::actor($d));
            return true;
        });
        // The site never changes for a tablet, so its lock can be taken before the row is read again.
        return $current['site_id'] !== null ? DeviceLocks::site((int) $current['site_id'], $work) : $work();
    }

    /**
     * Create the tablet's registration code, cancelling any earlier one. The code is returned once
     * and never stored or recorded.
     * @return array{code: string, expires_at: string}
     * @throws ValidationException
     * @throws StaleDeviceException when the page is out of date (a code was created since it was opened)
     */
    public static function issueCode(int $deviceId, ?string $revision, DeviceScope $scope): array
    {
        return self::onDevice($deviceId, $scope, 'issue_code', true, function (array $d) use ($deviceId, $revision, $scope): array {
            if ((int) $d['has_credential'] === 1) {
                throw ValidationException::one('_form', 'This tablet is already registered. To register another tablet, add it as a new tablet.');
            }
            if ($d['revoked_at'] !== null) {
                throw ValidationException::one('_form', 'This tablet was taken out of service, so it cannot be registered again. Add it as a new tablet.');
            }
            $site = $d['site_id'] !== null ? SiteRepository::find((int) $d['site_id']) : null;
            if ($site === null || !(int) $site['is_active']) {
                throw ValidationException::one('_form', "This tablet's site is not active. Reactivate the site first, or cancel this registration.");
            }
            self::checkRevision($d, $revision);
            // The person creating the code, as committed now (read with a shared lock, in the same order
            // as account changes take it): a code must never outlive their access.
            $issuer = AccountRepository::lockShared($scope->actorId);
            if ($issuer === null || !AccountRules::canHoldSession($issuer) || AccountRules::mustChangePassword($issuer)
                || !Rbac::can((string) $issuer['role'], 'device.register')) {
                throw ValidationException::one('_form', 'Your account can no longer register tablets.');
            }
            if ($scope->rowVersion !== null && (int) $issuer['row_version'] !== $scope->rowVersion) {
                throw ValidationException::one('_form', 'Your access changed since you opened this page, so no code was made. Open the page again.');
            }
            $replaced = Tokens::revokeForDevice($deviceId, Tokens::DEVICE_REGISTRATION);
            $code = RegistrationCode::generate();
            $minutes = self::codeMinutes();
            $expires = Tokens::issueValue($scope->actorId, Tokens::DEVICE_REGISTRATION, $code, $minutes, $deviceId);
            Audit::record('device_code_issue', 'device', $deviceId, details: ['expires_at' => $expires, 'minutes' => $minutes, 'replaced_codes' => $replaced],
                actor: self::actor($d));
            return ['code' => $code, 'expires_at' => $expires];
        });
    }

    /**
     * Cancel a registration no tablet has used.
     * @return bool false when it was already cancelled
     * @throws ValidationException
     * @throws StaleDeviceException
     */
    public static function cancel(int $deviceId, ?string $revision, DeviceScope $scope): bool
    {
        return self::onDevice($deviceId, $scope, 'cancel', true, function (array $d) use ($deviceId, $revision, $scope): bool {
            if ($d['revoked_at'] !== null && (int) $d['has_credential'] !== 1) {
                return false;
            }
            if ((int) $d['has_credential'] === 1) {
                throw ValidationException::one('_form', 'A tablet has already used this registration. Retire it instead.');
            }
            self::checkRevision($d, $revision);
            $now = Clock::db();
            if (!DeviceRepository::cancel($deviceId, $scope->actorId, $now)) {
                throw new StaleDeviceException();
            }
            $cancelled = Tokens::revokeForDevice($deviceId, Tokens::DEVICE_REGISTRATION);
            Audit::record('device_cancel', 'device', $deviceId, changes: ['revoked_at' => [null, $now]], details: ['codes_cancelled' => $cancelled],
                actor: self::actor($d));
            return true;
        });
    }

    /**
     * Retire a tablet: it uploads what it holds, then erases itself, the next time it connects.
     * @return ?array{sessions_ended: int, grants_and_codes_revoked: int, administrators_told: bool, already_retired: bool} null when it was already
     *   out of service (and nothing was reported lost); already_retired: someone else had just retired it and this reported it lost
     * @throws ValidationException
     * @throws StaleDeviceException
     */
    public static function retire(int $deviceId, ?string $reason, bool $lost, ?string $revision, DeviceScope $scope): ?array
    {
        return self::onDevice($deviceId, $scope, 'retire', false, function (array $d) use ($reason, $lost, $revision, $scope): ?array {
            if ($d['revoked_at'] !== null) {
                // Before the revision check, so a double tap is harmless. Someone else retired it meanwhile: a
                // "lost or stolen" tick still counts.
                if ($lost && self::canReportLost($d)) {
                    $why = Validator::text($reason, self::REASON_MAX)
                        ?? throw ValidationException::one('reason', 'Say why the tablet is being retired. It is kept in the audit log.');
                    self::markLost($d, $why, $scope);
                    return ['sessions_ended' => 0, 'grants_and_codes_revoked' => 0, 'administrators_told' => true, 'already_retired' => true];
                }
                return null;
            }
            if ((int) $d['has_credential'] !== 1) {
                throw ValidationException::one('_form', 'No tablet has used this registration yet. Cancel it instead.');
            }
            self::checkRevision($d, $revision);
            $why = Validator::text($reason, self::REASON_MAX)
                ?? throw ValidationException::one('reason', 'Say why the tablet is being retired. It is kept in the audit log.');
            $result = self::takeOutOfService($d, self::RETIRE, $lost, $why, $scope);
            if ($lost) {
                self::tellAdministratorsLost($d, $scope);
            }
            return $result + ['administrators_told' => $lost, 'already_retired' => false];
        });
    }

    /**
     * A retired tablet turns out to be lost or stolen: Administrators are told, and its uploads are
     * cut off at what the server has received (P2B holds the rest for review).
     * @return bool false when it was already reported lost
     * @throws ValidationException
     * @throws StaleDeviceException
     */
    public static function reportLost(int $deviceId, ?string $reason, ?string $revision, DeviceScope $scope): bool
    {
        return self::onDevice($deviceId, $scope, 'report_lost', false, function (array $d) use ($reason, $revision, $scope): bool {
            if ((int) $d['revoked_lost'] === 1) {
                return false;
            }
            if (!self::canReportLost($d)) {
                throw ValidationException::one('_form', match (true) {
                    $d['wiped_at'] !== null => 'This tablet has already erased itself, so there is nothing more to report.',
                    $d['revoked_at'] !== null => 'This tablet is already set to erase itself without uploading, so its records will not be added.',
                    (int) $d['has_credential'] !== 1 => 'No tablet has used this registration yet. Cancel it instead.',
                    default => 'This tablet is still in service. Retire it with "It is lost or stolen" ticked instead.',
                });
            }
            self::checkRevision($d, $revision);
            $why = Validator::text($reason, self::REASON_MAX)
                ?? throw ValidationException::one('lost_reason', 'Say what happened to the tablet. It is kept in the audit log.');
            self::markLost($d, $why, $scope);
            return true;
        });
    }

    /**
     * Erase now (device.erase): the tablet erases itself without uploading the next time it
     * connects. Also turns a retirement into an erase.
     * @param ?int $seenPending the unsynced count the page showed; a higher count now is refused once,
     *   unless $countAcknowledged (the person saw the new count and posted again)
     * @return ?array{sessions_ended: int, grants_and_codes_revoked: int, escalated: bool, coordinators_told: bool} null when an erase was already requested
     * @throws ValidationException
     * @throws StaleDeviceException
     */
    public static function erase(int $deviceId, ?string $reason, ?string $typedLabel, ?int $seenPending, bool $countAcknowledged, ?string $revision, DeviceScope $scope): ?array
    {
        return self::onDevice($deviceId, $scope, 'erase', false, function (array $d) use ($deviceId, $reason, $typedLabel, $seenPending, $countAcknowledged, $revision, $scope): ?array {
            if (!$scope->can('device.erase')) {
                Audit::durable('device_erase', 'device', $deviceId, 'Denied', 'Erase now needs device.erase');
                throw ValidationException::one('_form', 'Only an Administrator can erase a tablet without uploading its records. Retire it instead: it uploads its records first.');
            }
            if ($d['revoked_at'] !== null && $d['wipe_mode'] === self::ERASE) {
                return null;
            }
            if ($d['wiped_at'] !== null) {
                throw ValidationException::one('_form', 'This tablet has already erased itself.');
            }
            if ((int) $d['has_credential'] !== 1) {
                throw ValidationException::one('_form', 'No tablet has used this registration yet. Cancel it instead.');
            }
            self::checkRevision($d, $revision);
            $errors = [];
            $why = Validator::text($reason, self::REASON_MAX);
            if ($why === null) {
                $errors['reason'] = 'Say why the tablet must be erased at once. It is kept in the audit log.';
            }
            if (mb_strtolower((string) Validator::text($typedLabel, self::LABEL_MAX)) !== mb_strtolower((string) $d['label'])) {
                $errors['confirm_label'] = "Type the tablet's name exactly as shown (" . $d['label'] . ') to confirm.';
            }
            $pending = (int) $d['pending_count'];
            if (!$countAcknowledged && $pending > ($seenPending ?? 0)) {
                // The count comes from the tablet itself, so it may refuse only once: posting again goes ahead.
                $errors['seen_pending'] = 'The tablet has reported more unsynced records since you opened this page (now ' . $pending
                    . '). If it should still be erased, choose Erase this tablet again.';
            }
            if ($errors) {
                throw new ValidationException($errors);
            }
            $received = DeviceRepository::receivedMaxSeq($deviceId);
            if ($d['revoked_at'] === null) {
                $result = self::takeOutOfService($d, self::ERASE, false, $why, $scope, ['seen_pending' => $seenPending, 'count_acknowledged' => $countAcknowledged]);
                $result['escalated'] = false;
            } else {
                // A retiring tablet: who retired it and when stay; the cut-off comes down to what the server has received.
                $now = Clock::db();
                if (!DeviceRepository::escalateToWipeNow($deviceId, $scope->actorId, $now, $received)) {
                    throw new StaleDeviceException();
                }
                [$sessions, $tokens] = self::endAccess($deviceId, $scope);
                $after = DeviceRepository::lock($deviceId) ?? $d;
                Audit::record('device_erase', 'device', $deviceId, reason: $why,
                    changes: Audit::diff($d, $after, ['wipe_mode', 'erase_requested_at', 'erase_requested_by', 'revoked_max_seq']), details: [
                        'escalated_from' => self::RETIRE, 'sessions_ended' => $sessions, 'grants_and_codes_revoked' => $tokens,
                        'reported_pending_count' => $pending, 'seen_pending' => $seenPending, 'count_acknowledged' => $countAcknowledged,
                        'last_seen_at' => $d['last_seen_at'],
                    ], actor: self::actor($d));
                $result = ['sessions_ended' => $sessions, 'grants_and_codes_revoked' => $tokens, 'escalated' => true];
            }
            $site = $d['site_id'] !== null ? SiteRepository::find((int) $d['site_id']) : null;
            $told = $site !== null && (int) $site['is_active'] === 1; // Coordinators see only active sites
            if ($told) {
                Notifications::toRoleOnce('Coordinator', (int) $d['site_id'], 'device_erase', self::place($d) . ' will erase itself without uploading the next time it connects. '
                    . ($d['last_seen_at'] !== null
                        ? 'It last reported ' . $pending . ' unsynced record' . ($pending === 1 ? '' : 's') . ' (' . DeviceStatus::at($d['last_seen_at'], (string) $site['time_zone']) . ').'
                        : 'It has not reported how many records it holds.')
                    . ' Those distributions will be lost: check stock and any paper slips.', 'device', $deviceId);
            }
            return $result + ['coordinators_told' => $told];
        });
    }

    /**
     * What a form shows about the tablet. Figures the tablet reports (heartbeat columns) are left
     * out, so a heartbeat never makes an open form stale; a new code or a cancelled one is in, a code
     * that merely expires is not.
     */
    public static function revision(array $device): string
    {
        return sha1(json_encode([
            (int) $device['device_id'], $device['site_id'] === null ? null : (int) $device['site_id'], (string) $device['label'],
            (int) $device['is_site_registered'], (int) $device['has_credential'], $device['revoked_at'], (int) $device['revoked_lost'],
            (string) $device['wipe_mode'], $device['wiped_at'], ($device['code_id'] ?? null) === null ? null : (int) $device['code_id'],
        ], JSON_THROW_ON_ERROR));
    }

    /** How long a registration code works. */
    public static function codeMinutes(): int
    {
        return max(10, min(1440, Settings::int('device_code_minutes', 60)));
    }

    /** Whether this server can redeem codes yet (the Station's registration endpoint arrives in P2B). */
    public static function redemptionAvailable(): bool
    {
        return is_file(APP_ROOT . '/public/api/device/register.php');
    }

    /**
     * Row lock, scope re-check, then $fn with the locked row (and its current code id). With $named,
     * the tablet's named lock is taken first (busy: refused at once); without it the row lock simply
     * waits its turn.
     * @template T
     * @param callable(array): T $fn
     * @return T
     */
    private static function onDevice(int $deviceId, DeviceScope $scope, string $action, bool $named, callable $fn): mixed
    {
        $work = fn() => Db::transaction(function () use ($deviceId, $scope, $action, $fn) {
            $d = DeviceRepository::lock($deviceId) ?? throw ValidationException::one('_form', 'That tablet no longer exists.');
            if (!$scope->covers($d['site_id'] === null ? null : (int) $d['site_id'])) {
                // Access lost between the page and this post: the page itself answers 404.
                Audit::durable('access_denied', 'device', $deviceId, 'Denied', 'Device at a site not available to this user',
                    ['site_id' => $d['site_id'] === null ? null : (int) $d['site_id'], 'action' => $action]);
                throw ValidationException::one('_form', 'That tablet is not at one of your sites.');
            }
            $d['code_id'] = DeviceRepository::currentCodeId($deviceId);
            return $fn($d);
        });
        return $named ? DeviceLocks::device($deviceId, $work) : $work();
    }

    /**
     * @param array<string, mixed> $extraDetails
     * @return array{sessions_ended: int, grants_and_codes_revoked: int}
     */
    private static function takeOutOfService(array $d, string $mode, bool $lost, string $reason, DeviceScope $scope, array $extraDetails = []): array
    {
        $id = (int) $d['device_id'];
        $now = Clock::db();
        // Under the row lock, which each P2B upload takes before it records an item, so the count is exact.
        // A tablet that may be in the wrong hands gets only what the server has received, never what it reports
        // (for such a tablet P2B holds every upload received after the revocation; this number is a record).
        $received = DeviceRepository::receivedMaxSeq($id);
        $maxSeq = $lost || $mode === self::ERASE ? $received : max($received, (int) ($d['reported_max_seq'] ?? 0));
        if (!DeviceRepository::revoke($id, $scope->actorId, $now, $mode, $lost, $maxSeq)) {
            throw new StaleDeviceException();
        }
        [$sessions, $tokens] = self::endAccess($id, $scope);
        $after = DeviceRepository::lock($id) ?? $d;
        Audit::record($mode === self::RETIRE ? 'device_retire' : 'device_erase', 'device', $id, reason: $reason, snapshot: $d,
            changes: Audit::diff($d, $after, ['revoked_at', 'revoked_by', 'wipe_mode', 'is_site_registered', 'offline_enabled', 'revoked_lost', 'revoked_max_seq',
                'erase_requested_at', 'erase_requested_by']),
            details: [
                'lost' => $lost, 'sessions_ended' => $sessions, 'grants_and_codes_revoked' => $tokens, 'revoked_max_seq' => $maxSeq,
                'reported_pending_count' => (int) $d['pending_count'], 'last_seen_at' => $d['last_seen_at'], 'last_sync_at' => $d['last_sync_at'],
            ] + $extraDetails, actor: self::actor($d));
        return ['sessions_ended' => $sessions, 'grants_and_codes_revoked' => $tokens];
    }

    /** A retiring tablet not yet reported lost and not yet erased. */
    private static function canReportLost(array $d): bool
    {
        return $d['revoked_at'] !== null && $d['wipe_mode'] === self::RETIRE && $d['wiped_at'] === null && (int) $d['revoked_lost'] !== 1;
    }

    /** Mark a retiring tablet lost: cut-off down to what the server has received, audit, alert every Administrator. */
    private static function markLost(array $d, string $why, DeviceScope $scope): void
    {
        $id = (int) $d['device_id'];
        if (!DeviceRepository::markLost($id, DeviceRepository::receivedMaxSeq($id))) {
            throw new StaleDeviceException();
        }
        $after = DeviceRepository::lock($id) ?? $d;
        Audit::record('device_report_lost', 'device', $id, reason: $why, changes: Audit::diff($d, $after, ['revoked_lost', 'revoked_max_seq']), actor: self::actor($d));
        self::tellAdministratorsLost($d, $scope);
    }

    /** Tokens first, then sessions: the order account changes use. @return array{0: int, 1: int} sessions ended, tokens revoked */
    private static function endAccess(int $deviceId, DeviceScope $scope): array
    {
        $tokens = Tokens::revokeForDevice($deviceId);
        return [SessionStore::endAllForDevice($deviceId, 'Device Revoked', $scope->actorId), $tokens];
    }

    /** Every Administrator, whatever the site's state (a deactivated site's alerts would reach nobody). */
    private static function tellAdministratorsLost(array $d, DeviceScope $scope): void
    {
        Notifications::toRoleOnce('Administrator', null, 'device_lost', self::place($d) . ' was reported lost or stolen by ' . self::personName($scope->actorId)
            . ' and retired. If it may be in the wrong hands, choose Erase now on its page, and consider resetting the passwords of the people who signed in on it.',
            'device', (int) $d['device_id']);
    }

    /** @throws StaleDeviceException */
    private static function checkRevision(array $d, ?string $revision): void
    {
        if ($revision !== null && !hash_equals(self::revision($d), $revision)) {
            throw new StaleDeviceException();
        }
    }

    /** Success rows are recorded against the tablet's own site. */
    private static function actor(array $d): array
    {
        return ['site_id' => $d['site_id'] === null ? null : (int) $d['site_id']];
    }

    /** "Front desk 1 at Northside" */
    private static function place(array $d): string
    {
        $site = $d['site_id'] !== null ? SiteRepository::find((int) $d['site_id']) : null;
        return $d['label'] . ($site !== null ? ' at ' . $site['name'] : '');
    }

    private static function personName(int $userId): string
    {
        $user = AccountRepository::find($userId);
        if ($user === null) {
            return 'someone';
        }
        $display = trim((string) ($user['display_name'] ?? ''));
        return $display !== '' ? $display : trim($user['first_name'] . ' ' . $user['last_name']);
    }

    private static function labelTakenMessage(string $siteName, string $label): string
    {
        return 'Another tablet at ' . $siteName . ' is already called "' . $label . '". Choose a different name.';
    }
}

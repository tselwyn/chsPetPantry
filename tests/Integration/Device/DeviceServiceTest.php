<?php
declare(strict_types=1);

namespace Pfpms\Tests\Integration\Device;

use Pfpms\Auth\SessionStore;
use Pfpms\Auth\Tokens;
use Pfpms\Clock;
use Pfpms\Db;
use Pfpms\Device\DeviceRepository;
use Pfpms\Device\DeviceScope;
use Pfpms\Device\DeviceService;
use Pfpms\Device\DeviceStatus;
use Pfpms\Device\RegistrationCode;
use Pfpms\Device\StaleDeviceException;
use Pfpms\Inventory\CountRepository;
use Pfpms\Tests\TestCase;
use Pfpms\Validation\ValidationException;

/** Tablets on admin_devices (plan P2A): adding, registration codes, renaming, cancelling, retiring and erasing. */
final class DeviceServiceTest extends TestCase
{
    private int $north;
    private int $south;
    private array $admin;
    private array $coordinator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->north = $this->makeSite('Northside');
        $this->south = $this->makeSite('Southside');
        $this->admin = $this->makeUser(['role' => 'Administrator']);
        $this->coordinator = $this->makeUser(['role' => 'Coordinator']);
        $this->grant($this->coordinator['user_id'], $this->north);
    }

    // Helpers -------------------------------------------------------------------------------

    private function grant(int $userId, int $siteId): void
    {
        Db::pdo()->prepare('INSERT INTO user_site_access (user_id, site_id, starts_at, granted_by) VALUES (?, ?, ?, ?)')
            ->execute([$userId, $siteId, '2026-01-01 00:00:00', $this->admin['user_id']]);
    }

    private function scope(?array $user = null): DeviceScope
    {
        $user ??= $this->coordinator;
        return DeviceScope::forUser(\Pfpms\Account\AccountRepository::find((int) $user['user_id']) ?? $user); // as stored, with its row_version
    }

    private function adminScope(): DeviceScope
    {
        return DeviceScope::forUser(\Pfpms\Account\AccountRepository::find((int) $this->admin['user_id']) ?? $this->admin);
    }

    private function rev(int $deviceId): string
    {
        return DeviceService::revision(DeviceRepository::find($deviceId));
    }

    /** A tablet that has registered (P2B does this): credential, registered, optionally reporting. */
    private function registered(?int $siteId = null, array $overrides = []): int
    {
        static $n = 0;
        $n++;
        return $this->makeDevice($siteId ?? $this->north, $overrides + ['token_hash' => hash('sha256', "pfd1_test_$n"), 'is_site_registered' => 1,
            'vault_key_ciphertext' => "v1:k1:test$n", 'label' => "Registered $n"]);
    }

    private function add(string $label, ?int $siteId = null, ?DeviceScope $scope = null): int
    {
        return DeviceService::add(['site_id' => (string) ($siteId ?? $this->north), 'label' => $label], $scope ?? $this->scope());
    }

    private function errorsOf(callable $fn): array
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            return $e->errors;
        }
        $this->fail('expected a ValidationException');
    }

    private function denied(string $action, ?string $entityType = null, ?int $entityId = null): int
    {
        $st = Db::durable()->prepare("SELECT COUNT(*) FROM audit_log WHERE action = ? AND outcome = 'Denied'"
            . ($entityType !== null ? ' AND entity_type = ? AND entity_id = ?' : ''));
        $st->execute($entityType !== null ? [$action, $entityType, $entityId] : [$action]);
        return (int) $st->fetchColumn();
    }

    private function audits(string $action, int $deviceId): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = ? AND entity_type = 'device' AND entity_id = ? AND outcome = 'Success'", [$action, $deviceId]);
    }

    private function row(int $deviceId): array
    {
        $st = Db::pdo()->prepare('SELECT * FROM device WHERE device_id = ?');
        $st->execute([$deviceId]);
        return $st->fetch();
    }

    private function liveCodes(int $deviceId): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM auth_token WHERE device_id = ? AND purpose = 'Device Registration' AND used_at IS NULL AND revoked_at IS NULL AND expires_at > ?",
            [$deviceId, Clock::db()]);
    }

    // Adding tablets --------------------------------------------------------------------------

    public function testAddCreatesAWaitingTabletAndAuditsItAgainstItsSite(): void
    {
        $id = $this->add('  Front   desk 1 ');
        $row = $this->row($id);
        $this->assertSame(['Front desk 1', $this->north, 0, null, $this->coordinator['user_id'], self::NOW],
            [$row['label'], (int) $row['site_id'], (int) $row['is_site_registered'], $row['token_hash'], (int) $row['registered_by'], $row['registered_at']]);
        $this->assertSame(DeviceStatus::NO_CODE, DeviceStatus::code(DeviceRepository::find($id)));
        $this->assertSame($this->north, (int) $this->scalar("SELECT site_id FROM audit_log WHERE action = 'device_add' AND entity_id = ?", [$id]));
    }

    public function testAddReportsEveryFieldErrorAtOnce(): void
    {
        $this->assertEqualsCanonicalizing(['site_id', 'label'], array_keys($this->errorsOf(fn() => DeviceService::add(['site_id' => '', 'label' => ' '], $this->scope()))));
        $this->assertArrayHasKey('label', $this->errorsOf(fn() => $this->add(str_repeat('x', 51))));
        $this->assertArrayHasKey('site_id', $this->errorsOf(fn() => DeviceService::add(['site_id' => '999999', 'label' => 'A'], $this->scope())));
    }

    public function testAddRefusesASiteOutsideTheScopeWithADurableDenial(): void
    {
        $before = $this->denied('access_denied', 'site', $this->south);
        $this->assertSame(['site_id' => 'Choose one of your sites.'], $this->errorsOf(fn() => $this->add('Front desk 1', $this->south)));
        $this->assertSame($before + 1, $this->denied('access_denied', 'site', $this->south));
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM device WHERE site_id = ?', [$this->south]));
    }

    public function testAddRefusesAnInactiveSite(): void
    {
        Db::pdo()->prepare('UPDATE site SET is_active = 0 WHERE site_id = ?')->execute([$this->south]);
        $this->assertStringContainsString('Southside is not active', $this->errorsOf(fn() => $this->add('Spare', $this->south, $this->adminScope()))['site_id']);
    }

    public function testLabelsAreUniqueAmongCurrentTabletsAtASite(): void
    {
        $first = $this->add('Front desk 1');
        foreach (['front desk 1', 'FRONT DÈSK 1 '] as $same) {
            $this->assertStringContainsString('already called', $this->errorsOf(fn() => $this->add($same))['label'] ?? '', $same);
        }
        $this->add('Front desk 1', $this->south, $this->adminScope());
        DeviceService::cancel($first, $this->rev($first), $this->scope());
        $this->assertGreaterThan($first, $this->add('Front desk 1'), 'a cancelled tablet frees its name');
        $retired = $this->registered(null, ['label' => 'Intake']);
        DeviceService::retire($retired, 'Broken screen', false, $this->rev($retired), $this->scope());
        $this->add('Intake');
    }

    public function testPendingCapPerSite(): void
    {
        for ($i = 1; $i <= DeviceService::PENDING_LIMIT; $i++) {
            $this->add("Tablet $i");
        }
        $this->assertStringContainsString('already has 10 tablets waiting', $this->errorsOf(fn() => $this->add('One too many'))['_form']);
        $this->registered(); // registered tablets do not count
        $this->assertSame(DeviceService::PENDING_LIMIT, DeviceRepository::pendingCount($this->north));
    }

    // Registration codes ----------------------------------------------------------------------

    public function testIssueCodeStoresOnlyAHashBoundToTheCreatorAndDevice(): void
    {
        $id = $this->add('Front desk 1');
        $issued = DeviceService::issueCode($id, $this->rev($id), $this->scope());
        $this->assertNotNull(RegistrationCode::normalise($issued['code']));
        $token = Db::pdo()->query("SELECT * FROM auth_token WHERE purpose = 'Device Registration'")->fetchAll();
        $this->assertCount(1, $token);
        $this->assertSame([hash('sha256', $issued['code']), $this->coordinator['user_id'], $id, '2026-10-01 13:00:00', $issued['expires_at']],
            [$token[0]['token_hash'], (int) $token[0]['user_id'], (int) $token[0]['device_id'], $token[0]['expires_at'], '2026-10-01 13:00:00']);
        $found = Tokens::find((string) RegistrationCode::normalise(RegistrationCode::format($issued['code'])), Tokens::DEVICE_REGISTRATION);
        $this->assertSame($id, (int) $found['device_id'], 'P2B finds it from the typed code');
        $this->assertSame(DeviceStatus::AWAITING, DeviceStatus::code(DeviceRepository::find($id)));
    }

    public function testTheCodeNeverAppearsInAuditFieldChangesOrNotifications(): void
    {
        $id = $this->add('Front desk 1');
        $codes = [DeviceService::issueCode($id, $this->rev($id), $this->scope())['code']];
        $codes[] = DeviceService::issueCode($id, $this->rev($id), $this->scope())['code'];
        DeviceService::cancel($id, $this->rev($id), $this->scope());
        $tablet = $this->registered(null, ['pending_count' => 2, 'last_seen_at' => '2026-10-01 11:00:00']);
        DeviceService::retire($tablet, 'Lost at the park', true, $this->rev($tablet), $this->scope());
        DeviceService::erase($tablet, 'Stolen', 'registered ' . substr((string) $this->row($tablet)['label'], 11), 2, false, $this->rev($tablet), $this->adminScope());
        $haystack = implode("\n", array_merge(
            Db::pdo()->query('SELECT CONCAT_WS(\'|\', reason, details, snapshot) FROM audit_log')->fetchAll(\PDO::FETCH_COLUMN),
            Db::pdo()->query('SELECT CONCAT_WS(\'|\', old_value, new_value) FROM audit_field_change')->fetchAll(\PDO::FETCH_COLUMN),
            Db::pdo()->query('SELECT message FROM notification')->fetchAll(\PDO::FETCH_COLUMN),
        ));
        foreach ($codes as $code) {
            $this->assertStringNotContainsString($code, $haystack);
            $this->assertStringNotContainsString(RegistrationCode::format($code), $haystack);
            $this->assertStringNotContainsString(hash('sha256', $code), $haystack);
        }
        $this->assertStringNotContainsString('[redacted]', $haystack, 'no detail key is swallowed by the audit redaction');
        $this->assertStringNotContainsString((string) $this->row($tablet)['token_hash'], $haystack);
        $this->assertStringNotContainsString('v1:k1:test', $haystack, 'nor the vault key');
    }

    public function testANewCodeCancelsTheEarlierOne(): void
    {
        $id = $this->add('Front desk 1');
        $first = DeviceService::issueCode($id, $this->rev($id), $this->scope())['code'];
        DeviceService::issueCode($id, $this->rev($id), $this->adminScope());
        $this->assertNull(Tokens::find($first, Tokens::DEVICE_REGISTRATION));
        $this->assertSame(1, $this->liveCodes($id));
        $details = json_decode((string) $this->scalar("SELECT details FROM audit_log WHERE action = 'device_code_issue' ORDER BY audit_id DESC LIMIT 1"), true);
        $this->assertSame(1, $details['replaced_codes']);
    }

    public function testIssueCodeRefusesAStaleRevisionSoAPrintedSheetIsNeverSilentlyReplaced(): void
    {
        $id = $this->add('Front desk 1');
        $revision = $this->rev($id);
        $first = DeviceService::issueCode($id, $revision, $this->scope())['code'];
        try {
            DeviceService::issueCode($id, $revision, $this->scope()); // a refresh of the sheet page re-posts the old form
            $this->fail('expected a stale form');
        } catch (StaleDeviceException) {
            $this->assertNotNull(Tokens::find($first, Tokens::DEVICE_REGISTRATION), 'the printed code still works');
        }
    }

    public function testCodeLifetimeFollowsTheSetting(): void
    {
        $this->setSetting('device_code_minutes', '30');
        $id = $this->add('Front desk 1');
        $code = DeviceService::issueCode($id, $this->rev($id), $this->scope())['code'];
        Clock::advance('+29 minutes');
        $this->assertNotNull(Tokens::find($code, Tokens::DEVICE_REGISTRATION));
        Clock::advance('+2 minutes');
        $this->assertNull(Tokens::find($code, Tokens::DEVICE_REGISTRATION));
        $this->assertSame(DeviceStatus::NO_CODE, DeviceStatus::code(DeviceRepository::find($id)));
        $this->setSetting('device_code_minutes', '5');
        $this->assertSame(10, DeviceService::codeMinutes(), 'never shorter than 10 minutes');
    }

    public function testNoCodeForRegisteredRetiredCancelledOrClosedSiteTablets(): void
    {
        $registered = $this->registered();
        $this->assertStringContainsString('already registered', $this->errorsOf(fn() => DeviceService::issueCode($registered, $this->rev($registered), $this->scope()))['_form']);
        DeviceService::retire($registered, 'Done', false, $this->rev($registered), $this->scope());
        $this->assertStringContainsString('already registered', $this->errorsOf(fn() => DeviceService::issueCode($registered, $this->rev($registered), $this->scope()))['_form']);
        $cancelled = $this->add('Spare');
        DeviceService::cancel($cancelled, $this->rev($cancelled), $this->scope());
        $this->assertStringContainsString('taken out of service', $this->errorsOf(fn() => DeviceService::issueCode($cancelled, $this->rev($cancelled), $this->scope()))['_form']);
        $closed = $this->add('Closed site tablet', $this->south, $this->adminScope());
        Db::pdo()->prepare('UPDATE site SET is_active = 0 WHERE site_id = ?')->execute([$this->south]);
        $this->assertStringContainsString('site is not active', $this->errorsOf(fn() => DeviceService::issueCode($closed, $this->rev($closed), $this->adminScope()))['_form']);
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM auth_token WHERE purpose = 'Device Registration'"));
    }

    // Rename and cancel -----------------------------------------------------------------------

    public function testRenameChecksUniquenessAndIsAudited(): void
    {
        $a = $this->add('Front desk 1');
        $b = $this->add('Front desk 2');
        $this->assertFalse(DeviceService::rename($a, 'Front desk 1', $this->rev($a), $this->scope()));
        $this->assertSame(0, $this->audits('device_rename', $a));
        $this->assertArrayHasKey('label', $this->errorsOf(fn() => DeviceService::rename($a, 'FRONT DESK 2', $this->rev($a), $this->scope())));
        $revision = $this->rev($a);
        $this->assertTrue(DeviceService::rename($a, 'Intake table', $revision, $this->scope()));
        $this->assertSame([1, 'Intake table'], [$this->audits('device_rename', $a), $this->row($a)['label']]);
        $this->assertTrue(DeviceService::rename($b, 'Front desk 1', $this->rev($b), $this->scope()), 'the old name is free again');
        $this->expectException(StaleDeviceException::class);
        DeviceService::rename($a, 'Back room', $revision, $this->scope());
    }

    public function testCancelRevokesTheRowAndItsCodeAndIsRefusedOnceRegistered(): void
    {
        $id = $this->add('Front desk 1');
        $code = DeviceService::issueCode($id, $this->rev($id), $this->scope())['code'];
        $this->assertTrue(DeviceService::cancel($id, $this->rev($id), $this->scope()));
        $this->assertNull(Tokens::find($code, Tokens::DEVICE_REGISTRATION));
        $this->assertSame(DeviceStatus::CANCELLED, DeviceStatus::code(DeviceRepository::find($id)));
        $this->assertFalse(DeviceService::cancel($id, 'stale', $this->scope()), 'twice: nothing to do');
        $this->assertSame(1, $this->audits('device_cancel', $id));
        $registered = $this->registered();
        $this->assertStringContainsString('Retire it instead', $this->errorsOf(fn() => DeviceService::cancel($registered, $this->rev($registered), $this->scope()))['_form']);
    }

    // Retire and erase ------------------------------------------------------------------------

    public function testRetireRevokesEndsSessionsAndGrantsAndKeepsTheCredential(): void
    {
        $tablet = $this->registered(null, ['offline_enabled' => 1, 'pending_count' => 5, 'last_seen_at' => '2026-10-01 11:00:00']);
        $other = $this->registered();
        $before = $this->row($tablet);
        $volunteer = $this->makeUser();
        $onTablet = [SessionStore::create($volunteer['user_id'], $this->north, 'PIN', $tablet), SessionStore::create($this->coordinator['user_id'], $this->north, 'Password', $tablet)];
        $untouched = [SessionStore::create($volunteer['user_id'], $this->north, 'PIN', $other), SessionStore::create($volunteer['user_id'], $this->north)];
        $grant = Tokens::issue($volunteer['user_id'], Tokens::OFFLINE_GRANT, 600, $tablet);
        $trusted = Tokens::issue($volunteer['user_id'], Tokens::TRUSTED_DEVICE, 600, $tablet);
        $reset = Tokens::issue($volunteer['user_id'], Tokens::PASSWORD_RESET, 60);

        $result = DeviceService::retire($tablet, 'Screen cracked', false, $this->rev($tablet), $this->scope());
        $this->assertSame(['sessions_ended' => 2, 'grants_and_codes_revoked' => 2, 'administrators_told' => false, 'already_retired' => false], $result);
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM notification WHERE kind = 'device_lost'"), 'not reported lost: nobody is alerted');
        $fields = Db::pdo()->query("SELECT f.field_name FROM audit_field_change f JOIN audit_log a ON a.audit_id = f.audit_id WHERE a.action = 'device_retire'")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertEqualsCanonicalizing(['revoked_at', 'revoked_by', 'wipe_mode', 'is_site_registered', 'offline_enabled', 'revoked_max_seq'], $fields,
            'only what changed (revoked_lost stayed 0)');
        $after = $this->row($tablet);
        $this->assertSame([self::NOW, $this->coordinator['user_id'], 'Push Then Wipe', 0, 0, 0],
            [$after['revoked_at'], (int) $after['revoked_by'], $after['wipe_mode'], (int) $after['is_site_registered'], (int) $after['offline_enabled'], (int) $after['revoked_lost']]);
        $this->assertSame([$before['token_hash'], $before['vault_key_ciphertext']], [$after['token_hash'], $after['vault_key_ciphertext']],
            'kept, so P2B can still tell the tablet to erase itself');
        foreach ($onTablet as $sid) {
            $this->assertSame(['Device Revoked', $this->coordinator['user_id']],
                [$this->scalar('SELECT end_reason FROM user_session WHERE session_id = ?', [$sid]), (int) $this->scalar('SELECT ended_by FROM user_session WHERE session_id = ?', [$sid])]);
        }
        foreach ($untouched as $sid) {
            $this->assertArrayHasKey('user', SessionStore::validate($sid));
        }
        $this->assertNull(Tokens::find($grant, Tokens::OFFLINE_GRANT));
        $this->assertNull(Tokens::find($trusted, Tokens::TRUSTED_DEVICE));
        $this->assertNotNull(Tokens::find($reset, Tokens::PASSWORD_RESET), 'the person\'s own reset link is not the tablet\'s');
        $audit = Db::pdo()->query("SELECT details, snapshot FROM audit_log WHERE action = 'device_retire'")->fetch();
        $details = json_decode((string) $audit['details'], true);
        $this->assertSame([false, 2, 2, 5, '2026-10-01 11:00:00'], [$details['lost'], $details['sessions_ended'], $details['grants_and_codes_revoked'],
            $details['reported_pending_count'], $details['last_seen_at']]);
        $snapshot = json_decode((string) $audit['snapshot'], true);
        $this->assertArrayNotHasKey('token_hash', $snapshot);
        $this->assertArrayNotHasKey('vault_key_ciphertext', $snapshot);
        $this->assertFalse(DeviceStatus::inService(DeviceRepository::find($tablet)));
    }

    public function testRetireSnapshotsTheSequenceCutOff(): void
    {
        $plain = $this->registered();
        DeviceService::retire($plain, 'Done', false, $this->rev($plain), $this->scope());
        $this->assertSame(0, (int) $this->row($plain)['revoked_max_seq']);

        $tablet = $this->registered(null, ['reported_max_seq' => 12]);
        $insert = Db::pdo()->prepare("INSERT INTO sync_item (client_uuid, device_id, client_seq, kind, recorded_at_client, payload_sha256, status)
                                      VALUES (?, ?, ?, 'distribution', '2026-10-01 10:00:00.000', ?, 'Accepted')");
        foreach ([8, 15] as $seq) {
            $insert->execute([sprintf('00000000-0000-4000-8000-%012d', $tablet * 100 + $seq), $tablet, $seq, str_repeat('a', 64)]);
        }
        DeviceService::retire($tablet, 'Done', false, $this->rev($tablet), $this->scope());
        $this->assertSame(15, (int) $this->row($tablet)['revoked_max_seq'], 'the higher of what arrived and what the tablet reported');
    }

    public function testRetireNeedsAReasonAndRefusesAStaleRevision(): void
    {
        $tablet = $this->registered();
        $this->assertSame(['reason'], array_keys($this->errorsOf(fn() => DeviceService::retire($tablet, '  ', false, $this->rev($tablet), $this->scope()))));
        $revision = $this->rev($tablet);
        DeviceService::rename($tablet, 'Renamed', $revision, $this->adminScope());
        $this->expectException(StaleDeviceException::class);
        DeviceService::retire($tablet, 'Done', false, $revision, $this->scope());
    }

    public function testRetireTwiceReturnsNullAndWritesOneAuditRow(): void
    {
        $tablet = $this->registered();
        $revision = $this->rev($tablet);
        $this->assertNotNull(DeviceService::retire($tablet, 'Done', false, $revision, $this->scope()));
        $this->assertNull(DeviceService::retire($tablet, 'Done', false, $revision, $this->scope()), 'a double tap is harmless');
        $this->assertSame(1, $this->audits('device_retire', $tablet));
    }

    public function testLostOrStolenNotifiesAdministratorsOnce(): void
    {
        $tablet = $this->registered(null, ['label' => 'Front desk 3']);
        DeviceService::retire($tablet, 'Left at the park', true, $this->rev($tablet), $this->scope());
        $this->assertSame(1, (int) $this->row($tablet)['revoked_lost']);
        $messages = Db::pdo()->query("SELECT recipient_role, site_id, message FROM notification WHERE kind = 'device_lost'")->fetchAll();
        $this->assertCount(1, $messages);
        $this->assertSame(['Administrator', null], [$messages[0]['recipient_role'], $messages[0]['site_id']], 'every Administrator, whatever the site');
        $this->assertStringStartsWith('Front desk 3 at Northside was reported lost or stolen by Test ', $messages[0]['message']);
    }

    public function testOnlyAnAdministratorCanErase(): void
    {
        $tablet = $this->registered();
        $before = $this->denied('device_erase', 'device', $tablet);
        $errors = $this->errorsOf(fn() => DeviceService::erase($tablet, 'Stolen', $this->row($tablet)['label'], 0, false, $this->rev($tablet), $this->scope()));
        $this->assertStringContainsString('Only an Administrator can erase', $errors['_form']);
        $this->assertSame($before + 1, $this->denied('device_erase', 'device', $tablet));
        $this->assertNull($this->row($tablet)['revoked_at']);
    }

    public function testEraseNeedsTheTypedNameAndRefusesWhenMoreRecordsWereReported(): void
    {
        $tablet = $this->registered(null, ['label' => 'Front desk 1', 'pending_count' => 5, 'last_seen_at' => '2026-10-01 11:00:00']);
        $erase = fn(?string $reason, ?string $typed, int $seen, bool $ack = false) => DeviceService::erase($tablet, $reason, $typed, $seen, $ack, $this->rev($tablet), $this->adminScope());
        $this->assertEqualsCanonicalizing(['reason', 'confirm_label', 'seen_pending'], array_keys($this->errorsOf(fn() => $erase(' ', 'Front desk', 3))));
        $this->assertStringContainsString('(now 5)', $this->errorsOf(fn() => $erase('Stolen', 'Front desk 1', 3))['seen_pending']);
        $result = $erase('Stolen', '  front   DESK 1 ', 3, true); // the person saw the new count and posted again
        $this->assertSame(['sessions_ended' => 0, 'grants_and_codes_revoked' => 0, 'escalated' => false, 'coordinators_told' => true], $result);
        $row = $this->row($tablet);
        $this->assertSame(['Wipe Now', $this->admin['user_id'], self::NOW, $this->admin['user_id']],
            [$row['wipe_mode'], (int) $row['revoked_by'], $row['erase_requested_at'], (int) $row['erase_requested_by']]);
        $details = json_decode((string) $this->scalar("SELECT details FROM audit_log WHERE action = 'device_erase' AND entity_id = ?", [$tablet]), true);
        $this->assertSame([3, true], [$details['seen_pending'], $details['count_acknowledged']]);
        $this->assertSame(DeviceStatus::ERASING, DeviceStatus::code(DeviceRepository::find($tablet)));
        $message = (string) $this->scalar("SELECT message FROM notification WHERE kind = 'device_erase' AND recipient_role = 'Coordinator' AND site_id = ?", [$this->north]);
        $this->assertStringContainsString('It last reported 5 unsynced records (Oct 1, 7:00 AM). Those distributions will be lost', $message);
        $this->assertNull($erase('Stolen', 'Front desk 1', 5), 'already requested');
    }

    public function testEscalatingARetirementRecordsWhoChoseEraseAndTightensTheCutOff(): void
    {
        $tablet = $this->registered(null, ['label' => 'Front desk 1', 'reported_max_seq' => 7, 'pending_count' => 4, 'last_seen_at' => '2026-10-01 11:00:00']);
        $this->syncItems($tablet, [3]);
        DeviceService::retire($tablet, 'No longer needed', false, $this->rev($tablet), $this->scope());
        $retired = $this->row($tablet);
        $this->assertSame(7, (int) $retired['revoked_max_seq'], 'a plain retirement trusts what the tablet reported');
        Clock::advance('+2 hours');
        $pending = (int) $retired['pending_count'];
        $result = DeviceService::erase($tablet, 'Confirmed stolen', 'Front desk 1', $pending, false, $this->rev($tablet), $this->adminScope());
        $this->assertTrue($result['escalated']);
        $after = $this->row($tablet);
        $this->assertSame(['Wipe Now', $retired['revoked_at'], $retired['revoked_by'], 3, '2026-10-01 14:00:00', $this->admin['user_id']],
            [$after['wipe_mode'], $after['revoked_at'], $after['revoked_by'], (int) $after['revoked_max_seq'], $after['erase_requested_at'], (int) $after['erase_requested_by']],
            'who retired it stays; the cut-off comes down to what the server received');
        $status = DeviceStatus::describe(DeviceRepository::find($tablet), Clock::now(), 'America/New_York', true, 'x');
        $this->assertSame('Erase requested Oct 1, 10:00 AM', $status['label'], 'the time of the erase, not of the retirement');
        $details = json_decode((string) $this->scalar("SELECT details FROM audit_log WHERE action = 'device_erase' AND entity_id = ?", [$tablet]), true);
        $this->assertSame('Push Then Wipe', $details['escalated_from']);
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM notification WHERE kind = 'device_erase' AND entity_id = ?", [$tablet]));
    }

    public function testATabletThatMayBeInTheWrongHandsDoesNotSetItsOwnCutOff(): void
    {
        foreach ([[true, 'retire'], [false, 'erase']] as [$lost, $how]) {
            $tablet = $this->registered(null, ['reported_max_seq' => 2147483647]);
            $this->syncItems($tablet, [5]);
            $how === 'retire' ? DeviceService::retire($tablet, 'Stolen', $lost, $this->rev($tablet), $this->scope())
                : DeviceService::erase($tablet, 'Stolen', (string) $this->row($tablet)['label'], 0, false, $this->rev($tablet), $this->adminScope());
            $this->assertSame(5, (int) $this->row($tablet)['revoked_max_seq'], $how);
        }
    }

    public function testARetiredTabletCanBeReportedLostLater(): void
    {
        $tablet = $this->registered(null, ['label' => 'Spare', 'reported_max_seq' => 9]);
        $this->syncItems($tablet, [4]);
        DeviceService::retire($tablet, 'No longer needed', false, $this->rev($tablet), $this->scope());
        $this->assertNull(DeviceService::retire($tablet, 'Again', false, $this->rev($tablet), $this->scope()), 'retire again without lost changes nothing');
        $this->assertSame(['lost_reason'], array_keys($this->errorsOf(fn() => DeviceService::reportLost($tablet, ' ', $this->rev($tablet), $this->scope()))));
        $this->assertTrue(DeviceService::reportLost($tablet, 'Not in the cupboard', $this->rev($tablet), $this->scope()));
        $row = $this->row($tablet);
        $this->assertSame([1, 4], [(int) $row['revoked_lost'], (int) $row['revoked_max_seq']]);
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM notification WHERE kind = 'device_lost' AND entity_id = ? AND site_id IS NULL", [$tablet]));
        $this->assertSame(1, $this->audits('device_report_lost', $tablet));
        $this->assertFalse(DeviceService::reportLost($tablet, 'Again', $this->rev($tablet), $this->scope()));
        $inService = $this->registered();
        $this->assertArrayHasKey('_form', $this->errorsOf(fn() => DeviceService::reportLost($inService, 'Lost', $this->rev($inService), $this->scope())));
    }

    public function testAlertsForATabletAtAnInactiveSiteStillReachAdministrators(): void
    {
        $tablet = $this->registered($this->south, ['label' => 'Closed site tablet']);
        Db::pdo()->prepare('UPDATE site SET is_active = 0 WHERE site_id = ?')->execute([$this->south]);
        $this->assertTrue(DeviceService::retire($tablet, 'Site closed; tablet missing', true, $this->rev($tablet), $this->adminScope())['administrators_told']);
        $second = $this->makeUser(['role' => 'Administrator']);
        $ctx = new \Pfpms\Http\Context($second, str_repeat('0', 64), null, \Pfpms\Auth\SiteAccess::sitesFor($second));
        $this->assertContains('device_lost', array_column(\Pfpms\Notify\Notifications::listFor($ctx), 'kind'), 'another Administrator sees it');
        $result = DeviceService::erase($tablet, 'Stolen', 'Closed site tablet', 0, false, $this->rev($tablet), $this->adminScope());
        $this->assertFalse($result['coordinators_told'], 'nobody could see a notice for an inactive site');
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM notification WHERE kind = 'device_erase'"));
    }

    public function testAStolenTabletCannotHoldOffEraseNowWithItsOwnCount(): void
    {
        $tablet = $this->registered(null, ['label' => 'Front desk 1', 'pending_count' => 1, 'last_seen_at' => '2026-10-01 11:00:00']);
        $revision = $this->rev($tablet);
        Db::pdo()->prepare('UPDATE device SET pending_count = 50 WHERE device_id = ?')->execute([$tablet]); // the tablet keeps reporting more
        $this->assertArrayHasKey('seen_pending', $this->errorsOf(fn() => DeviceService::erase($tablet, 'Stolen', 'Front desk 1', 1, false, $revision, $this->adminScope())));
        Db::pdo()->prepare('UPDATE device SET pending_count = 90 WHERE device_id = ?')->execute([$tablet]);
        $this->assertNotNull(DeviceService::erase($tablet, 'Stolen', 'Front desk 1', 50, true, $revision, $this->adminScope()), 'the second post goes ahead');
        $this->assertNull(DeviceService::erase($tablet, 'Stolen', 'Front desk 1', 50, true, $revision, $this->adminScope()), 'and a double post is harmless');
    }

    public function testAnEraseCannotBecomeARetirement(): void
    {
        $tablet = $this->registered(null, ['label' => 'Front desk 1']);
        DeviceService::erase($tablet, 'Stolen', 'Front desk 1', 0, false, $this->rev($tablet), $this->adminScope());
        $this->assertNull(DeviceService::retire($tablet, 'Changed my mind', false, $this->rev($tablet), $this->adminScope()));
        $this->assertSame('Wipe Now', $this->row($tablet)['wipe_mode']);
    }

    public function testRetireAndEraseRefuseAWaitingTablet(): void
    {
        $id = $this->add('Front desk 1');
        $this->assertStringContainsString('Cancel it instead', $this->errorsOf(fn() => DeviceService::retire($id, 'Done', false, $this->rev($id), $this->scope()))['_form']);
        $this->assertStringContainsString('Cancel it instead', $this->errorsOf(fn() => DeviceService::erase($id, 'Done', 'Front desk 1', 0, false, $this->rev($id), $this->adminScope()))['_form']);
    }

    // Scope, locks and listing ----------------------------------------------------------------

    public function testCoordinatorsCannotActOnAnotherSitesTablet(): void
    {
        $waiting = $this->add('South 1', $this->south, $this->adminScope());
        $tablet = $this->registered($this->south, ['label' => 'South 2']);
        $calls = [
            [$waiting, fn() => DeviceService::rename($waiting, 'Mine now', $this->rev($waiting), $this->scope())],
            [$waiting, fn() => DeviceService::issueCode($waiting, $this->rev($waiting), $this->scope())],
            [$waiting, fn() => DeviceService::cancel($waiting, $this->rev($waiting), $this->scope())],
            [$tablet, fn() => DeviceService::retire($tablet, 'Mine', false, $this->rev($tablet), $this->scope())],
            [$tablet, fn() => DeviceService::erase($tablet, 'Mine', 'South 2', 0, false, $this->rev($tablet), $this->scope())],
        ];
        foreach ($calls as $i => [$id, $call]) {
            $before = $this->denied('access_denied', 'device', $id);
            $this->assertSame(['_form' => 'That tablet is not at one of your sites.'], $this->errorsOf($call), "call $i");
            $this->assertSame($before + 1, $this->denied('access_denied', 'device', $id), "call $i is audited");
        }
        $this->assertSame(['South 1', null, 0], [$this->row($waiting)['label'], $this->row($waiting)['revoked_at'], $this->liveCodes($waiting)]);
        $this->assertNull($this->row($tablet)['revoked_at']);
    }

    public function testAdministratorsManageTabletsAtAnInactiveSite(): void
    {
        $third = $this->makeSite('Westside');
        $both = $this->makeUser(['role' => 'Coordinator']);
        $this->grant($both['user_id'], $this->north);
        $this->grant($both['user_id'], $this->south);
        $n = $this->registered($this->north, ['label' => 'N']);
        $s = $this->registered($this->south, ['label' => 'S']);
        $w = $this->registered($third, ['label' => 'W']);
        $ids = fn(DeviceScope $scope) => array_map(fn($d) => (int) $d['device_id'], DeviceRepository::list($scope, null, false));
        $this->assertEqualsCanonicalizing([$n, $s], $ids($this->scope($both)));
        $this->assertSame([$n], $ids($this->scope()));
        $this->assertSame([], $ids($this->scope($this->makeUser(['role' => 'Coordinator']))), 'no sites, no tablets');

        Db::pdo()->prepare('UPDATE site SET is_active = 0 WHERE site_id = ?')->execute([$this->north]);
        $this->assertSame([$s], $ids($this->scope($both)), 'a Coordinator stops seeing an inactive site\'s tablets');
        $this->assertEqualsCanonicalizing([$n, $s, $w], $ids($this->adminScope()));
        $this->assertContains('Its site, Northside, is deactivated. Retire this tablet.',
            DeviceStatus::describe(DeviceRepository::find($n), Clock::now(), 'UTC', true, DeviceStatus::currentBuild())['warnings']);
        DeviceService::retire($n, 'Site closed', false, $this->rev($n), $this->adminScope());
        $this->assertSame([$w], array_map(fn($d) => (int) $d['device_id'], DeviceRepository::list($this->adminScope(), $third, false)), 'the site filter');
    }

    public function testABusyTabletLockHoldsOffCodesButNeverRetireOrErase(): void
    {
        $waiting = $this->add('Front desk 8');
        $tablet = $this->registered(null, ['label' => 'Held']);
        $other = Db::durable(); // P2B's registration, or a tablet contacting the server over and over
        $names = [Db::lockName(Db::pdo(), "device:$waiting"), Db::lockName(Db::pdo(), "device:$tablet")];
        foreach ($names as $name) {
            $other->prepare('SELECT GET_LOCK(?, 0)')->execute([$name]);
        }
        try {
            foreach ([fn() => DeviceService::issueCode($waiting, $this->rev($waiting), $this->scope()), fn() => DeviceService::cancel($waiting, $this->rev($waiting), $this->scope()),
                      fn() => DeviceService::rename($waiting, 'Other', $this->rev($waiting), $this->scope())] as $i => $call) {
                $this->assertStringContainsString('Someone else is changing this tablet right now', $this->errorsOf($call)['_form'], "call $i");
            }
            $this->assertNotNull(DeviceService::retire($tablet, 'Stolen', true, $this->rev($tablet), $this->scope()), 'whoever holds the tablet cannot keep it in service');
            $this->assertNotNull(DeviceService::erase($tablet, 'Stolen', 'Held', 0, false, $this->rev($tablet), $this->adminScope()));
        } finally {
            foreach ($names as $name) {
                $other->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);
            }
        }
        $siteLock = Db::lockName(Db::pdo(), "devices:site:{$this->north}");
        $other->prepare('SELECT GET_LOCK(?, 0)')->execute([$siteLock]);
        try {
            $this->assertStringContainsString('adding or renaming a tablet at this site', $this->errorsOf(fn() => $this->add('Front desk 9'))['_form']);
        } finally {
            $other->prepare('SELECT RELEASE_LOCK(?)')->execute([$siteLock]);
        }
    }

    public function testTheRevisionIgnoresHeartbeatColumns(): void
    {
        $tablet = $this->registered();
        $revision = $this->rev($tablet);
        Db::pdo()->prepare("UPDATE device SET last_seen_at = ?, last_sync_at = ?, pending_count = 9, app_build = 'x', storage_persisted = 1, offline_enabled = 1,
                            reported_max_seq = 40 WHERE device_id = ?")->execute([self::NOW, self::NOW, $tablet]);
        $this->assertNotNull(DeviceService::retire($tablet, 'Done', false, $revision, $this->scope()), 'a heartbeat never makes an open form stale');
    }

    public function testListAndFindNeverCarrySecrets(): void
    {
        $this->registered();
        $this->add('Waiting');
        foreach (array_merge(DeviceRepository::list($this->adminScope(), null, true), [DeviceRepository::find($this->registered())]) as $row) {
            $this->assertArrayNotHasKey('token_hash', $row);
            $this->assertArrayNotHasKey('vault_key_ciphertext', $row);
        }
    }

    public function testInServiceMatchesTheSqlPredicate(): void
    {
        $ids = [$this->add('Waiting'), $this->registered(), $this->registered(null, ['is_site_registered' => 0])];
        $retired = $this->registered();
        DeviceService::retire($retired, 'Done', false, $this->rev($retired), $this->scope());
        $ids[] = $retired;
        $ids[] = $this->registered(null, ['revoked_at' => self::NOW, 'wipe_mode' => 'Wipe Now', 'wiped_at' => self::NOW]);
        $cancelled = $this->add('Cancelled');
        DeviceService::cancel($cancelled, $this->rev($cancelled), $this->scope());
        $ids[] = $cancelled;
        foreach ($ids as $id) {
            $sql = (int) $this->scalar('SELECT COUNT(*) FROM device d WHERE d.device_id = ? AND ' . DeviceRepository::IN_SERVICE_SQL, [$id]) === 1;
            $this->assertSame($sql, DeviceStatus::inService(DeviceRepository::find($id)), "device $id");
        }
        $this->assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM device d WHERE d.device_id IN (' . implode(',', $ids) . ') AND ' . DeviceRepository::IN_SERVICE_SQL));
    }

    public function testCountWarningCountsInServiceAndRetiringTabletsOnly(): void
    {
        $this->registered(null, ['pending_count' => 3]);
        $retiring = $this->registered(null, ['pending_count' => 5]);
        DeviceService::retire($retiring, 'Done', false, $this->rev($retiring), $this->scope());
        $erasing = $this->registered(null, ['pending_count' => 7, 'label' => 'Erase me']);
        DeviceService::erase($erasing, 'Stolen', 'Erase me', 7, false, $this->rev($erasing), $this->adminScope());
        $this->registered(null, ['pending_count' => 11, 'revoked_at' => self::NOW, 'wipe_mode' => 'Push Then Wipe', 'wiped_at' => self::NOW]);
        $this->makeDevice($this->north, ['pending_count' => 13]); // waiting: nothing to upload yet
        $this->registered($this->south, ['pending_count' => 17]);
        $this->assertSame(8, CountRepository::pendingDeviceItems($this->north));
    }

    public function testRecentUsersListsPeopleWhoSignedInOnTheTablet(): void
    {
        $tablet = $this->registered();
        $rosa = $this->makeUser(['first_name' => 'Rosa', 'last_name' => 'Diaz', 'display_name' => 'Rosa D.']);
        $sam = $this->makeUser(['first_name' => 'Sam', 'last_name' => 'Lee']);
        SessionStore::create($rosa['user_id'], $this->north, 'Password', $tablet);
        Clock::advance('+1 hour');
        SessionStore::create($sam['user_id'], $this->north, 'Password', $tablet);
        SessionStore::create($rosa['user_id'], $this->north, 'PIN', $tablet);
        SessionStore::create($sam['user_id'], $this->north); // in a browser, not on the tablet
        $this->assertSame([['user_id' => $rosa['user_id'], 'name' => 'Rosa D.', 'last_signed_in' => '2026-10-01 13:00:00'],
            ['user_id' => $sam['user_id'], 'name' => 'Sam Lee', 'last_signed_in' => '2026-10-01 13:00:00']],
            DeviceRepository::recentUsers($tablet));
    }
    /** @param list<int> $seqs items the server received from the tablet */
    private function syncItems(int $deviceId, array $seqs): void
    {
        $insert = Db::pdo()->prepare("INSERT INTO sync_item (client_uuid, device_id, client_seq, kind, recorded_at_client, payload_sha256, status)
                                      VALUES (?, ?, ?, 'distribution', '2026-10-01 10:00:00.000', ?, 'Accepted')");
        foreach ($seqs as $seq) {
            $insert->execute([sprintf('00000000-0000-4000-8000-%012d', $deviceId * 1000 + $seq), $deviceId, $seq, str_repeat('a', 64)]);
        }
    }

    public function testAnExpiredCodeDoesNotMakeOpenFormsStale(): void
    {
        $id = $this->add('Front desk 1');
        DeviceService::issueCode($id, $this->rev($id), $this->scope());
        $revision = $this->rev($id);
        Clock::advance('+61 minutes'); // the code expires while the page is open
        $this->assertTrue(DeviceService::rename($id, 'Front desk 2', $revision, $this->scope()));
        $this->assertSame(DeviceStatus::NO_CODE, DeviceStatus::code(DeviceRepository::find($id)));
    }

    public function testRenamingTwiceOrOnlyTheCaseIsHarmless(): void
    {
        $id = $this->add('front desk 1');
        $revision = $this->rev($id);
        $this->assertTrue(DeviceService::rename($id, 'Front Desk 1', $revision, $this->scope()), 'its own name, in other capitals');
        $this->assertFalse(DeviceService::rename($id, 'Front Desk 1', $revision, $this->scope()), 'a repeated post changes nothing and is not stale');
        foreach (['cancel', 'retire'] as $how) {
            $tablet = $how === 'cancel' ? $this->add("Cancel me") : $this->registered();
            $how === 'cancel' ? DeviceService::cancel($tablet, $this->rev($tablet), $this->scope()) : DeviceService::retire($tablet, 'Done', false, $this->rev($tablet), $this->scope());
            $this->assertStringContainsString('can no longer change', $this->errorsOf(fn() => DeviceService::rename($tablet, 'New name', $this->rev($tablet), $this->scope()))['_form'], $how);
        }
    }

    public function testCodeMinutesStayWithinTheirLimits(): void
    {
        $this->setSetting('device_code_minutes', '5000');
        $this->assertSame(1440, DeviceService::codeMinutes());
    }

    public function testACodeIsNeverCreatedForSomeoneWhoLostTheRight(): void
    {
        $id = $this->add('Front desk 1');
        $scope = $this->scope();
        Db::pdo()->prepare("UPDATE user_account SET status = 'Inactive' WHERE user_id = ?")->execute([$this->coordinator['user_id']]);
        $this->assertSame('Your account can no longer register tablets.', $this->errorsOf(fn() => DeviceService::issueCode($id, $this->rev($id), $scope))['_form']);
        $this->assertSame(0, $this->liveCodes($id));
    }

    public function testAddingAtAnInactiveSiteOutsideTheScopeRevealsNothing(): void
    {
        $closed = $this->makeSite('Secret closed site');
        Db::pdo()->prepare('UPDATE site SET is_active = 0 WHERE site_id = ?')->execute([$closed]);
        $before = $this->denied('access_denied', 'site', $closed);
        $this->assertSame(['site_id' => 'Choose one of your sites.'], $this->errorsOf(fn() => $this->add('Front desk 1', $closed)));
        $this->assertSame($before + 1, $this->denied('access_denied', 'site', $closed));
    }

    public function testTheListHidesCancelledAndErasedTabletsUnlessAsked(): void
    {
        $waiting = $this->add('Waiting');
        $inService = $this->registered();
        $cancelled = $this->add('Cancelled');
        DeviceService::cancel($cancelled, $this->rev($cancelled), $this->scope());
        $wiped = $this->registered(null, ['revoked_at' => self::NOW, 'wipe_mode' => 'Wipe Now', 'wiped_at' => self::NOW]);
        $ids = fn(bool $all) => array_map(fn($d) => (int) $d['device_id'], DeviceRepository::list($this->scope(), null, $all));
        $this->assertEqualsCanonicalizing([$waiting, $inService], $ids(false));
        $this->assertEqualsCanonicalizing([$waiting, $inService, $cancelled, $wiped], $ids(true));
    }

    public function testStatusOfALockedRowIsRefusedButWaitingCanBeTested(): void
    {
        $id = $this->add('Front desk 1');
        DeviceService::issueCode($id, $this->rev($id), $this->scope());
        $locked = Db::transaction(fn() => DeviceRepository::lock($id));
        $this->assertTrue(DeviceStatus::waitingForRegistration($locked), 'what P2B redemption checks on the locked row');
        $this->expectException(\LogicException::class);
        DeviceStatus::code($locked);
    }
    public function testTickingLostOnATabletSomeoneElseJustRetiredStillReportsIt(): void
    {
        $tablet = $this->registered(null, ['reported_max_seq' => 9]);
        $revision = $this->rev($tablet);
        DeviceService::retire($tablet, 'No longer needed', false, $revision, $this->adminScope()); // someone else, a moment earlier
        $result = DeviceService::retire($tablet, 'Left in the car park', true, $revision, $this->scope());
        $this->assertSame(['sessions_ended' => 0, 'grants_and_codes_revoked' => 0, 'administrators_told' => true, 'already_retired' => true], $result);
        $this->assertSame([1, 0], [(int) $this->row($tablet)['revoked_lost'], (int) $this->row($tablet)['revoked_max_seq']]);
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM notification WHERE kind = 'device_lost' AND entity_id = ?", [$tablet]));
        $this->assertNull(DeviceService::retire($tablet, 'Again', true, $revision, $this->scope()), 'once only');
    }

    public function testReportLostSaysWhyItCannotBeUsed(): void
    {
        $erasing = $this->registered(null, ['label' => 'E']);
        DeviceService::erase($erasing, 'Stolen', 'E', 0, false, $this->rev($erasing), $this->adminScope());
        $wiped = $this->registered(null, ['revoked_at' => self::NOW, 'wipe_mode' => 'Push Then Wipe', 'wiped_at' => self::NOW]);
        $waiting = $this->add('W');
        foreach ([[$erasing, 'already set to erase itself'], [$wiped, 'already erased itself'], [$waiting, 'Cancel it instead']] as [$id, $words]) {
            $this->assertStringContainsString($words, $this->errorsOf(fn() => DeviceService::reportLost($id, 'Lost', $this->rev($id), $this->scope()))['_form']);
        }
    }

    public function testTheCutOffNeverRisesAfterTheRetirement(): void
    {
        $tablet = $this->registered(null, ['label' => 'Front desk 1', 'reported_max_seq' => 7]);
        $this->syncItems($tablet, [5]);
        DeviceService::retire($tablet, 'No longer needed', false, $this->rev($tablet), $this->scope());
        $this->assertSame(7, (int) $this->row($tablet)['revoked_max_seq']);
        $this->syncItems($tablet, [40]); // uploads arriving after the retirement
        DeviceService::erase($tablet, 'Stolen after all', 'Front desk 1', 0, false, $this->rev($tablet), $this->adminScope());
        $this->assertSame(7, (int) $this->row($tablet)['revoked_max_seq'], 'escalating never raises it');
        $lost = $this->registered(null, ['reported_max_seq' => 7]);
        $this->syncItems($lost, [5]);
        DeviceService::retire($lost, 'No longer needed', false, $this->rev($lost), $this->scope());
        $this->syncItems($lost, [40]);
        DeviceService::reportLost($lost, 'Missing', $this->rev($lost), $this->scope());
        $this->assertSame(7, (int) $this->row($lost)['revoked_max_seq'], 'reporting it lost never raises it');
    }

    public function testACodeIsNotCreatedWhenTheCreatorsAccessChangedMeanwhile(): void
    {
        $id = $this->add('Front desk 1');
        foreach ([
            'site taken away' => fn() => Db::pdo()->prepare('UPDATE user_account SET row_version = row_version + 1 WHERE user_id = ?')->execute([$this->coordinator['user_id']]),
            'reset' => fn() => Db::pdo()->prepare('UPDATE user_account SET must_change_password = 1 WHERE user_id = ?')->execute([$this->coordinator['user_id']]),
            'demoted' => fn() => Db::pdo()->prepare("UPDATE user_account SET role = 'Volunteer' WHERE user_id = ?")->execute([$this->coordinator['user_id']]),
        ] as $what => $change) {
            $scope = $this->scope(); // the page opened before the change
            $snapshot = Db::pdo()->query('SELECT row_version, must_change_password, role FROM user_account WHERE user_id = ' . (int) $this->coordinator['user_id'])->fetch();
            $change();
            $this->assertArrayHasKey('_form', $this->errorsOf(fn() => DeviceService::issueCode($id, $this->rev($id), $scope)), $what);
            Db::pdo()->prepare('UPDATE user_account SET row_version = ?, must_change_password = ?, role = ? WHERE user_id = ?')
                ->execute([$snapshot['row_version'], $snapshot['must_change_password'], $snapshot['role'], $this->coordinator['user_id']]);
        }
        $this->assertSame(0, $this->liveCodes($id));
        $this->assertArrayHasKey('code', DeviceService::issueCode($id, $this->rev($id), $this->scope()), 'with the access unchanged');
    }

    public function testAddingAtASiteThatDoesNotExistAnswersLikeAnyOtherSite(): void
    {
        $before = $this->denied('access_denied', 'site', 999999);
        $this->assertSame(['site_id' => 'Choose one of your sites.'], $this->errorsOf(fn() => $this->add('Front desk 1', 999999)));
        $this->assertSame($before + 1, $this->denied('access_denied', 'site', 999999), 'the same answer and trace as a real site outside the scope');
    }

    public function testRedemptionAvailableAtNeedsTheEndpointAndTheShell(): void
    {
        $public = sys_get_temp_dir() . '/pfpms-redemption-' . bin2hex(random_bytes(4));
        $endpoint = "$public/api/device/register.php";
        $shell = "$public/station/index.php";
        mkdir("$public/api/device", 0700, true);
        mkdir("$public/station", 0700, true);
        try {
            $this->assertFalse(DeviceService::redemptionAvailableAt($public), 'neither');

            file_put_contents($endpoint, '<?php');
            $this->assertFalse(DeviceService::redemptionAvailableAt($public), 'the endpoint alone (S1): no installed app to register yet');

            file_put_contents($shell, '<?php');
            $this->assertTrue(DeviceService::redemptionAvailableAt($public), 'both (S2)');

            unlink($endpoint);
            $this->assertFalse(DeviceService::redemptionAvailableAt($public), 'the shell alone: nothing redeems the code');

            mkdir($endpoint);
            $this->assertFalse(DeviceService::redemptionAvailableAt($public), 'a directory with the endpoint\'s name is not the endpoint');
        } finally {
            foreach ([$shell, $endpoint] as $path) {
                if (is_dir($path)) {
                    rmdir($path);
                } elseif (is_file($path)) {
                    unlink($path);
                }
            }
            foreach (["$public/api/device", "$public/api", "$public/station", $public] as $dir) {
                @rmdir($dir);
            }
        }
        $this->assertDirectoryDoesNotExist($public, 'the temporary web root was removed');
    }

    public function testRedemptionIsNotAvailableInThisRepoYet(): void
    {
        $this->assertFileExists(APP_ROOT . '/public/api/device/register.php', 'S1 adds the redemption endpoint');
        $this->assertFileDoesNotExist(APP_ROOT . '/public/station/index.php', 'the Station shell arrives in S2 (then this test expects true)');
        $this->assertFalse(DeviceService::redemptionAvailable(), 'until S2, admin_devices keeps saying tablets cannot be registered yet');
    }

    public function testAnAccountEventThatCancelsAnExpiredCodeDoesNotMakeFormsStale(): void
    {
        $id = $this->add('Front desk 1');
        DeviceService::issueCode($id, $this->rev($id), $this->scope());
        Clock::advance('+2 hours');
        $revision = $this->rev($id);
        Tokens::revokeAll($this->coordinator['user_id'], Tokens::DEVICE_REGISTRATION); // e.g. the creator changed their password
        $this->assertTrue(DeviceService::rename($id, 'Front desk 2', $revision, $this->scope()));
    }
}

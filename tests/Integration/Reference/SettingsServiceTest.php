<?php
declare(strict_types=1);

namespace Pfpms\Tests\Integration\Reference;

use Pfpms\Db;
use Pfpms\Reference\SettingsService;
use Pfpms\Settings;
use Pfpms\Tests\TestCase;
use Pfpms\Validation\ValidationException;

/** Settings screen (plan P2A): registry coverage, typed validation, audit of changed keys only. */
final class SettingsServiceTest extends TestCase
{
    public function testRegistryCoversEverySeededSetting(): void
    {
        $inTable = Db::pdo()->query('SELECT setting_key FROM system_setting ORDER BY setting_key')->fetchAll(\PDO::FETCH_COLUMN);
        $inRegistry = array_keys(SettingsService::registry());
        $this->assertSame([], array_values(array_diff($inTable, $inRegistry)), 'settings in the table with no registry entry');
        $this->assertSame([], array_values(array_diff($inRegistry, $inTable)), 'registry entries with no row in the table');
        $this->assertCount(68, $inRegistry);
    }

    public function testRegistryEntriesAreWellFormedAndSeededValuesPass(): void
    {
        $values = Db::pdo()->query('SELECT setting_key, setting_value FROM system_setting')->fetchAll(\PDO::FETCH_KEY_PAIR);
        foreach (SettingsService::registry() as $key => $def) {
            $this->assertContains($def['group'], SettingsService::GROUPS, $key);
            $this->assertNotSame('', $def['label'], $key);
            $this->assertContains($def['type'], ['int', 'float', 'bool', 'string', 'enum', 'mmdd'], $key);
            if (in_array($def['type'], ['int', 'float', 'string'], true)) {
                $this->assertArrayHasKey('max', $def, $key);
                $this->assertLessThanOrEqual($def['max'], $def['min'], $key);
            }
            if ($def['type'] === 'enum') {
                $this->assertTrue(isset($def['options']) || isset($def['source']), $key);
            }
            $this->assertNotNull(SettingsService::normalise($def, (string) $values[$key]), "seeded value of $key breaks its own rules");
        }
    }

    public function testGroupedListsEverySettingInGroupOrderWithItsDescription(): void
    {
        $groups = SettingsService::grouped();
        $this->assertSame(SettingsService::GROUPS, array_column($groups, 'name'));
        $all = array_merge(...array_column($groups, 'settings'));
        $this->assertCount(68, $all);
        $this->assertSame('30', $all['session_idle_minutes']['value']);
        $this->assertSame('Session inactivity timeout', $all['session_idle_minutes']['hint'], 'the specification reference is dropped');
        $this->assertSame(['en' => 'English', 'es' => 'Spanish'], $all['default_language']['choices']);
        $this->assertSame('How long an account stays locked after “Wrong passwords before an account is locked”', $all['lockout_minutes']['hint'],
            'other settings are named by their labels, not their keys');
        $this->assertSame('Registered tablets may work offline', $all['offline_mode_enabled']['label']);
        $this->assertSame('', $all['offline_mode_enabled']['hint'], 'a hint that only repeats the label is dropped');
    }

    public function testBoundsAreEnforcedAndNothingIsSavedOnError(): void
    {
        $user = $this->makeUser(['role' => 'Administrator']);
        try {
            SettingsService::update([
                'session_idle_minutes' => '4', 'max_failed_logins' => '21', 'password_min_length' => '7',
                'offline_pbkdf2_iterations' => '99999', 'lockout_minutes' => '12.5', 'frequency_rule_days' => 'thirty',
                'reset_link_minutes' => '30',
            ], $user['user_id']);
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['session_idle_minutes', 'max_failed_logins', 'lockout_minutes', 'password_min_length', 'offline_pbkdf2_iterations', 'frequency_rule_days'],
                array_keys($e->errors));
            $this->assertSame('Enter a whole number from 5 to 240 (minutes).', $e->errors['session_idle_minutes']);
            $this->assertSame('Enter a whole number from 100,000 to 2,000,000 (rounds).', $e->errors['offline_pbkdf2_iterations']);
        }
        $this->assertSame('60', $this->scalar("SELECT setting_value FROM system_setting WHERE setting_key = 'reset_link_minutes'"), 'the valid change was not saved either');
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'settings_update'"));

        SettingsService::update(['session_idle_minutes' => '5', 'max_failed_logins' => '20', 'password_min_length' => '64'], $user['user_id']);
        $this->assertSame(5, Settings::int('session_idle_minutes', 0));
        $this->assertSame(64, Settings::int('password_min_length', 0));
        $fields = Db::pdo()->query("SELECT f.field_name FROM audit_field_change f JOIN audit_log a USING (audit_id)
                                     WHERE a.action = 'settings_update' ORDER BY f.field_name")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['max_failed_logins', 'password_min_length', 'session_idle_minutes'], $fields);
    }

    public function testBoolParsing(): void
    {
        $user = $this->makeUser(['role' => 'Administrator']);
        SettingsService::update(['offline_mode_enabled' => 'off', 'password_hibp_check' => 'on'], $user['user_id']);
        $this->assertSame('0', $this->scalar("SELECT setting_value FROM system_setting WHERE setting_key = 'offline_mode_enabled'"));
        $this->assertSame('1', $this->scalar("SELECT setting_value FROM system_setting WHERE setting_key = 'password_hibp_check'"));
        $this->assertFalse(Settings::bool('offline_mode_enabled', true));

        SettingsService::update(['offline_mode_enabled' => 'Yes', 'password_hibp_check' => '0'], $user['user_id']);
        $this->assertTrue(Settings::bool('offline_mode_enabled', false));
        $this->assertFalse(Settings::bool('password_hibp_check', true));

        $this->expectException(ValidationException::class);
        SettingsService::update(['offline_mode_enabled' => 'maybe'], $user['user_id']);
    }

    public function testEnumOptions(): void
    {
        $user = $this->makeUser(['role' => 'Administrator']);
        Db::pdo()->exec("INSERT INTO language (language_code, name, is_active) VALUES ('fr', 'French', 0)");
        try {
            SettingsService::update(['over_allotment_auth_level' => 'Volunteer', 'emergency_auth_level' => 'administrator', 'default_language' => 'fr'], $user['user_id']);
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['default_language', 'over_allotment_auth_level', 'emergency_auth_level'], array_keys($e->errors));
        }
        SettingsService::update(['over_allotment_auth_level' => 'Coordinator', 'default_language' => 'es'], $user['user_id']);
        $this->assertSame('Coordinator', Settings::string('over_allotment_auth_level'));
        $this->assertSame('es', Settings::string('default_language'));
    }

    public function testOnlyChangedKeysAreWrittenAndAudited(): void
    {
        $user = $this->makeUser(['role' => 'Administrator']);
        Db::pdo()->exec("UPDATE system_setting SET updated_at = '2026-01-01 00:00:00'");
        $input = [];
        foreach (SettingsService::grouped() as $group) {
            foreach ($group['settings'] as $key => $s) {
                $input[$key] = $s['value']; // the whole form posted back unchanged ...
            }
        }
        $input['session_idle_minutes'] = '45';          // ... except one setting,
        $input['welfare_prompt_multiplier'] = '2';     // an equal number written differently,
        $input['frequency_rule_days'] = ' 30 ';         // and an equal value with spaces.

        $this->assertSame(['session_idle_minutes'], SettingsService::update($input, $user['user_id']));

        $st = Db::pdo()->prepare("SELECT f.field_name, f.old_value, f.new_value FROM audit_field_change f JOIN audit_log a USING (audit_id)
                                   WHERE a.action = 'settings_update'");
        $st->execute();
        $this->assertSame([['field_name' => 'session_idle_minutes', 'old_value' => '30', 'new_value' => '45']], $st->fetchAll());
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'settings_update' AND entity_type = 'system_setting'"));
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM system_setting WHERE updated_at <> '2026-01-01 00:00:00'"));
        $row = Db::pdo()->query("SELECT updated_by, updated_at FROM system_setting WHERE setting_key = 'session_idle_minutes'")->fetch();
        $this->assertSame([(int) $user['user_id'], self::NOW], [(int) $row['updated_by'], $row['updated_at']]);
        $this->assertSame(45, Settings::int('session_idle_minutes', 0), 'the settings cache is reloaded');

        $this->assertSame([], SettingsService::update($input, $user['user_id']), 'saving the same values again changes nothing');
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'settings_update'"), 'no-op saves are not audited');
    }

    public function testUnknownKeysAreIgnored(): void
    {
        $user = $this->makeUser(['role' => 'Administrator']);
        $changed = SettingsService::update(['no_such_setting' => '1', '_csrf' => 'abc', 'history_page_size' => '50'], $user['user_id']);
        $this->assertSame(['history_page_size'], $changed);
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM system_setting WHERE setting_key IN ('no_such_setting', '_csrf')"));
    }

    public function testMonthDayFloatAndTextSettings(): void
    {
        $user = $this->makeUser(['role' => 'Administrator']);
        SettingsService::update(['programme_year_start' => '7-1', 'litters_prevented_multiplier' => '1.50', 'litters_prevented_citation' => '  Humane   Society  2019 '], $user['user_id']);
        $this->assertSame('07-01', Settings::string('programme_year_start'));
        $this->assertSame('1.5', Settings::string('litters_prevented_multiplier'));
        $this->assertSame('Humane Society 2019', Settings::string('litters_prevented_citation'));

        SettingsService::update(['litters_prevented_multiplier' => '', 'litters_prevented_citation' => ''], $user['user_id']);
        $this->assertSame('', Settings::string('litters_prevented_multiplier', 'x'), 'an optional setting can be cleared');

        try {
            SettingsService::update(['programme_year_start' => '02-29', 'litters_prevented_multiplier' => '-1',
                'litters_prevented_citation' => str_repeat('x', 256), 'organisation_name' => '  '], $user['user_id']);
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['organisation_name', 'litters_prevented_multiplier', 'litters_prevented_citation', 'programme_year_start'], array_keys($e->errors));
            $this->assertSame('This cannot be left empty.', $e->errors['organisation_name']);
        }
        foreach (['13-01', '04-31', '0101', 'July 1'] as $bad) {
            try {
                SettingsService::update(['programme_year_start' => $bad], $user['user_id']);
                $this->fail("accepted $bad");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('programme_year_start', $e->errors);
            }
        }
    }

    public function testRelatedSettingsMustStayInOrder(): void
    {
        $user = $this->makeUser(['role' => 'Administrator']);
        try {
            SettingsService::update(['pin_min_digits' => '6', 'pin_max_digits' => '5'], $user['user_id']); // 6 > 5, both within 4-6
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['pin_min_digits'], array_keys($e->errors));
        }
        try {
            SettingsService::update(['voucher_expiry_days' => '10'], $user['user_id']); // reminder is 14 days before
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['voucher_expiry_days'], array_keys($e->errors));
        }
        $this->assertSame(['pin_min_digits', 'pin_max_digits'], SettingsService::update(['pin_min_digits' => '5', 'pin_max_digits' => '5'], $user['user_id']));
        try {
            SettingsService::update(['import_max_rows_sync' => '20000', 'import_max_rows' => '10000'], $user['user_id']);
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['import_max_rows_sync'], array_keys($e->errors), 'when both change, the smaller one carries the message');
        }
    }

    public function testPinDigitsCannotExceedSix(): void
    {
        $user = $this->makeUser(['role' => 'Administrator']);
        foreach (['pin_max_digits' => '7', 'pin_min_digits' => '8'] as $key => $value) {
            try {
                SettingsService::update([$key => $value], $user['user_id']);
                $this->fail("accepted $key = $value");
            } catch (ValidationException $e) {
                $this->assertSame([$key], array_keys($e->errors), "$key = $value");
                $this->assertSame('Enter a whole number from 4 to 6 (digits).', $e->errors[$key]);
            }
        }
        $this->assertSame([], SettingsService::update(['pin_max_digits' => '6'], $user['user_id']), 'the seeded 6 is unchanged');
        $this->assertSame([4, 6], [Settings::int('pin_min_digits', 0), Settings::int('pin_max_digits', 0)]);
    }

    public function testAnOutOfRangeStoredValueDoesNotBlockOtherChanges(): void
    {
        $user = $this->makeUser(['role' => 'Administrator']);
        $this->setSetting('session_idle_minutes', '1000'); // set outside the screen, above its limit
        $input = [];
        foreach (SettingsService::grouped() as $group) {
            foreach ($group['settings'] as $key => $s) {
                $input[$key] = $s['value'];
            }
        }
        $input['history_page_size'] = '40';
        $this->assertSame(['history_page_size'], SettingsService::update($input, $user['user_id']));
        $this->assertSame('1000', Settings::string('session_idle_minutes'), 'an unchanged value is not checked or rewritten');

        $this->expectException(ValidationException::class);
        SettingsService::update(['session_idle_minutes' => '999'], $user['user_id']);
    }

    public function testNumbersAreStoredInOneForm(): void
    {
        $registry = SettingsService::registry();
        $this->assertSame('0', SettingsService::normalise($registry['litters_prevented_multiplier'], '-0'));
        $this->assertSame('0', SettingsService::normalise($registry['litters_prevented_multiplier'], '0.000'));
        $this->assertSame('2.5', SettingsService::normalise($registry['welfare_prompt_multiplier'], '2.50'));
        $this->assertSame('30', SettingsService::normalise($registry['frequency_rule_days'], '030'));
        $this->assertSame('0', SettingsService::normalise($registry['password_max_age_days'], '-0'));
        $this->assertNull(SettingsService::normalise($registry['frequency_rule_days'], '1e2'));
        $this->assertNull(SettingsService::normalise($registry['welfare_prompt_multiplier'], '2,5'));
    }

    public function testADeactivatedDefaultLanguageStaysVisibleButCannotBeChosenAgain(): void
    {
        $user = $this->makeUser(['role' => 'Administrator']);
        Db::pdo()->exec("INSERT INTO language (language_code, name, is_active) VALUES ('fr', 'French', 1)");
        SettingsService::update(['default_language' => 'fr'], $user['user_id']);
        Db::pdo()->exec("UPDATE language SET is_active = 0 WHERE language_code = 'fr'");

        $all = array_merge(...array_column(SettingsService::grouped(), 'settings'));
        $this->assertSame('fr (no longer available)', $all['default_language']['choices']['fr']);
        $this->assertSame([], SettingsService::update(['default_language' => 'fr', 'history_page_size' => '25'], $user['user_id']),
            'saving the form without touching it is not an error');
        $this->assertSame(['default_language'], SettingsService::update(['default_language' => 'en'], $user['user_id']));
        $this->expectException(ValidationException::class);
        SettingsService::update(['default_language' => 'fr'], $user['user_id']);
    }

    public function testSavingTheFormNeverUndoesSomeoneElsesChange(): void
    {
        $alice = $this->makeUser(['role' => 'Administrator']);
        $bob = $this->makeUser(['role' => 'Administrator']);
        // Both load the form while lockout_minutes is 15 and search_max_results is 100.
        $loaded = ['lockout_minutes' => '15', 'search_max_results' => '100'];
        SettingsService::update(['lockout_minutes' => '30', 'search_max_results' => '100'], $bob['user_id'], $loaded);
        // Alice then saves the whole form: she changed only search_max_results.
        $changed = SettingsService::update(['lockout_minutes' => '15', 'search_max_results' => '150'], $alice['user_id'], $loaded);
        $this->assertSame(['search_max_results'], $changed);
        Settings::reload();
        $this->assertSame(30, Settings::int('lockout_minutes', 0), "Bob's change survives Alice's save");
        $this->assertSame(150, Settings::int('search_max_results', 0));
    }

    public function testSecuritySettingsStayVisibleInTheAuditLog(): void
    {
        $user = $this->makeUser(['role' => 'Administrator']);
        SettingsService::update(['password_min_length' => '8', 'pin_max_failed' => '5'], $user['user_id']);
        $rows = Db::pdo()->query("SELECT f.field_name, f.old_value, f.new_value FROM audit_field_change f JOIN audit_log a USING (audit_id)
                                   WHERE a.action = 'settings_update' ORDER BY f.field_name")->fetchAll();
        $this->assertSame([
            ['field_name' => 'password_min_length', 'old_value' => '12', 'new_value' => '8'],
            ['field_name' => 'pin_max_failed', 'old_value' => '3', 'new_value' => '5'],
        ], $rows, 'a weakened password rule must be visible, not "[redacted]"');
    }
}

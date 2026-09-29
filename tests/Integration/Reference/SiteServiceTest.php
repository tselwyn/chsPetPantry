<?php
declare(strict_types=1);

namespace Pfpms\Tests\Integration\Reference;

use Pfpms\Reference\SiteRepository;
use Pfpms\Reference\SiteService;
use Pfpms\Tests\TestCase;
use Pfpms\Validation\ValidationException;

/** Sites admin (plan P2A): validation, uniqueness, audit, last-active-site rule. */
final class SiteServiceTest extends TestCase
{
    public function testCreateNormalisesAndAudits(): void
    {
        $id = SiteService::create(['name' => '  Westside   Pantry ', 'street_address' => '1 Main St', 'city' => 'Charleston',
            'state' => 'sc', 'postal_code' => '294011234', 'time_zone' => 'America/New_York']);
        $site = SiteRepository::find($id);
        $this->assertSame('Westside Pantry', $site['name']);
        $this->assertSame('SC', $site['state']);
        $this->assertSame('29401-1234', $site['postal_code']);
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'site_create' AND entity_id = ?", [$id]));
    }

    public function testValidationReportsEveryFieldAtOnce(): void
    {
        try {
            SiteService::create(['name' => '', 'state' => 'South Carolina', 'postal_code' => '123', 'time_zone' => 'Mars/Olympus']);
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['name', 'state', 'postal_code', 'time_zone'], array_keys($e->errors));
        }
    }

    public function testNamesAreUniqueIgnoringCaseAndAccents(): void
    {
        SiteService::create(['name' => 'Peña Center', 'time_zone' => 'America/New_York']);
        $this->expectException(ValidationException::class);
        SiteService::create(['name' => 'PENA CENTER', 'time_zone' => 'America/Chicago']);
    }

    public function testUpdateRecordsOnlyChangedFields(): void
    {
        $id = SiteService::create(['name' => 'North', 'city' => 'Charleston', 'time_zone' => 'America/New_York']);
        SiteService::update($id, ['name' => 'North', 'city' => 'Summerville', 'time_zone' => 'America/New_York']);
        $fields = \Pfpms\Db::pdo()->prepare("SELECT f.field_name, f.old_value, f.new_value FROM audit_field_change f JOIN audit_log a USING (audit_id)
                                              WHERE a.action = 'site_update' AND a.entity_id = ?");
        $fields->execute([$id]);
        $this->assertSame([['field_name' => 'city', 'old_value' => 'Charleston', 'new_value' => 'Summerville']], $fields->fetchAll());
        SiteService::update($id, ['name' => 'North', 'city' => 'Summerville', 'time_zone' => 'America/New_York']);
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'site_update' AND entity_id = ?", [$id]), 'no-op saves are not audited');
    }

    public function testTheLastActiveSiteCannotBeDeactivated(): void
    {
        \Pfpms\Db::pdo()->exec('UPDATE site SET is_active = 0');
        $a = SiteService::create(['name' => 'Only A', 'time_zone' => 'America/New_York']);
        $b = SiteService::create(['name' => 'Only B', 'time_zone' => 'America/New_York']);
        SiteService::setActive($a, false);
        $this->expectException(ValidationException::class);
        SiteService::setActive($b, false);
    }
}

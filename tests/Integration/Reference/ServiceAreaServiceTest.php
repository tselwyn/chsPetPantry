<?php
declare(strict_types=1);

namespace Pfpms\Tests\Integration\Reference;

use Pfpms\Db;
use Pfpms\Reference\ServiceAreaRepository;
use Pfpms\Reference\ServiceAreaService;
use Pfpms\Tests\TestCase;
use Pfpms\Validation\ValidationException;

/** Service area ZIP codes (UC-03 §4.3, plan P2A): normalisation, bulk add, reactivation, lookups. */
final class ServiceAreaServiceTest extends TestCase
{
    public function testNormaliseKeepsTheFiveDigitZip(): void
    {
        $this->assertSame('29401', ServiceAreaService::normalise('29401'));
        $this->assertSame('29401', ServiceAreaService::normalise(' 29401-1234 '));
        $this->assertSame('02134', ServiceAreaService::normalise('021341234'));
        $this->assertNull(ServiceAreaService::normalise('2940'));
        $this->assertNull(ServiceAreaService::normalise('294011'));
        $this->assertNull(ServiceAreaService::normalise('abcde'));
    }

    public function testParseSplitsOnCommasSpacesAndNewLines(): void
    {
        $parsed = ServiceAreaService::parse("29401, 29403\n29405-1234;29401\r\n\t abc  1234,,29407\u{00A0}29409");
        $this->assertSame(['29401', '29403', '29405', '29407', '29409'], $parsed['valid']);
        $this->assertSame(['abc', '1234'], $parsed['invalid']);
    }

    public function testBulkAddSavesTheValidZipsAndReportsTheInvalidOnes(): void
    {
        $site = $this->makeSite('Northside');
        $result = ServiceAreaService::bulkAdd("29401, 29402-1111 bad\n123", $site);
        $this->assertSame(['29401', '29402'], $result['added']);
        $this->assertSame(['bad', '123'], $result['invalid']);
        $this->assertSame([], $result['reactivated']);
        $this->assertSame(['postal_code' => '29402', 'site_id' => $site, 'is_active' => 1], ServiceAreaRepository::find('29402'));

        $details = json_decode((string) $this->scalar("SELECT details FROM audit_log WHERE action = 'service_area_add'"), true);
        $this->assertSame(['29401', '29402'], $details['added']);
        $this->assertSame([], $details['reactivated']);
        $this->assertSame(['bad', '123'], $details['invalid']);
        $this->assertSame($site, $details['site_id']);
    }

    public function testReaddingADeactivatedZipReactivatesIt(): void
    {
        $north = $this->makeSite('North');
        $south = $this->makeSite('South');
        ServiceAreaService::bulkAdd('29401 29402', $north);
        ServiceAreaService::setActive('29401', false);
        $this->assertFalse(ServiceAreaService::covers('29401'));

        $result = ServiceAreaService::bulkAdd('29401, 29402, 29403', $south);
        $this->assertSame(['29403'], $result['added']);
        $this->assertSame(['29401'], $result['reactivated']);
        $this->assertSame(['29402'], $result['existing']);
        $this->assertTrue(ServiceAreaService::covers('29401'));
        $this->assertSame($south, ServiceAreaService::siteFor('29401'), 'a reactivated ZIP takes the chosen site');
        $this->assertSame($north, ServiceAreaService::siteFor('29402'), 'a ZIP already listed keeps its site');

        $audits = (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'service_area_add'");
        ServiceAreaService::bulkAdd('29401 29402', null);
        $this->assertSame($audits, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'service_area_add'"), 'nothing changed, nothing audited');
    }

    public function testCoversAndSiteForAcceptZipPlusFour(): void
    {
        $site = $this->makeSite('Downtown');
        ServiceAreaService::bulkAdd('29401', $site);
        ServiceAreaService::bulkAdd('29405', null);

        $this->assertTrue(ServiceAreaService::covers('29401-9999'));
        $this->assertSame($site, ServiceAreaService::siteFor('294019999'));
        $this->assertTrue(ServiceAreaService::covers('29405'));
        $this->assertNull(ServiceAreaService::siteFor('29405'), 'covered, but no site assigned');
        $this->assertFalse(ServiceAreaService::covers('29499'));
        $this->assertNull(ServiceAreaService::siteFor('29499'));
        $this->assertFalse(ServiceAreaService::covers('not a zip'));

        Db::pdo()->prepare('UPDATE site SET is_active = 0 WHERE site_id = ?')->execute([$site]);
        $this->assertTrue(ServiceAreaService::covers('29401'));
        $this->assertNull(ServiceAreaService::siteFor('29401'), 'an inactive site is never proposed');

        ServiceAreaService::setActive('29401-0001', false);
        $this->assertFalse(ServiceAreaService::covers('29401'));
    }

    public function testEmptyInputAndUnusableSitesAreRefused(): void
    {
        try {
            ServiceAreaService::bulkAdd(" ,\n ", null);
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['postal_codes'], array_keys($e->errors));
        }
        $closed = $this->makeSite('Closed');
        Db::pdo()->prepare('UPDATE site SET is_active = 0 WHERE site_id = ?')->execute([$closed]);
        try {
            ServiceAreaService::bulkAdd('29401', $closed);
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['site_id'], array_keys($e->errors));
        }
        $this->assertNull(ServiceAreaRepository::find('29401'), 'nothing is added when the site is refused');

        $result = ServiceAreaService::bulkAdd('bad 1234', null);
        $this->assertSame(['bad', '1234'], $result['invalid']);
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'service_area_add'"));
    }

    public function testChangingTheSiteAndStatusIsAudited(): void
    {
        $site = $this->makeSite('Eastside');
        ServiceAreaService::bulkAdd('29401', null);
        ServiceAreaService::setSite('29401', $site);
        ServiceAreaService::setSite('29401', $site); // no change
        $st = Db::pdo()->prepare("SELECT f.field_name, f.old_value, f.new_value FROM audit_field_change f JOIN audit_log a USING (audit_id)
                                   WHERE a.action = 'service_area_site'");
        $st->execute();
        $this->assertSame([['field_name' => 'site_id', 'old_value' => null, 'new_value' => (string) $site]], $st->fetchAll());

        ServiceAreaService::setSite('29401', null);
        $this->assertNull(ServiceAreaRepository::find('29401')['site_id']);

        ServiceAreaService::setActive('29401', false);
        ServiceAreaService::setActive('29401', false);
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'service_area_deactivate'"));

        $this->expectException(ValidationException::class);
        ServiceAreaService::setActive('29999', true);
    }

    public function testReactivatingWithoutASiteKeepsTheEarlierSite(): void
    {
        $north = $this->makeSite('North');
        ServiceAreaService::bulkAdd('02134-5678', $north);
        $this->assertSame(['postal_code' => '02134', 'site_id' => $north, 'is_active' => 1], ServiceAreaRepository::find('02134'), 'the leading zero is kept');
        ServiceAreaService::setActive('02134', false);

        $result = ServiceAreaService::bulkAdd('021345678', null);
        $this->assertSame(['02134'], $result['reactivated']);
        $this->assertSame($north, ServiceAreaService::siteFor('02134'));
        $details = json_decode((string) $this->scalar("SELECT details FROM audit_log WHERE action = 'service_area_add' ORDER BY audit_id DESC LIMIT 1"), true);
        ksort($details); // MySQL's JSON type reorders object keys; MariaDB keeps them as written
        $this->assertSame(['added' => [], 'invalid' => [], 'reactivated' => ['02134'], 'site_id' => null], $details);
    }

    public function testAZipCannotBeGivenAnInactiveSite(): void
    {
        $closed = $this->makeSite('Closed');
        Db::pdo()->prepare('UPDATE site SET is_active = 0 WHERE site_id = ?')->execute([$closed]);
        ServiceAreaService::bulkAdd('29401', null);
        try {
            ServiceAreaService::setSite('29401', $closed);
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['_form'], array_keys($e->errors));
        }
        $this->assertNull(ServiceAreaRepository::find('29401')['site_id']);
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'service_area_site'"));
    }
}

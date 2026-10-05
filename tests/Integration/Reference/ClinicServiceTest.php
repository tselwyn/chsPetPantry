<?php
declare(strict_types=1);

namespace Pfpms\Tests\Integration\Reference;

use Pfpms\Db;
use Pfpms\Reference\ClinicRepository;
use Pfpms\Reference\ClinicService;
use Pfpms\Tests\TestCase;
use Pfpms\Validation\ValidationException;

/** Clinic directory (plan P2A): normalisation, validation, http(s)-only links, suspend/reactivate, audit. */
final class ClinicServiceTest extends TestCase
{
    private const FULL = [
        'name' => '  Lowcountry   Spay Clinic ', 'is_partner' => '1', 'street_address' => '12 King St', 'city' => 'Charleston', 'state' => 'sc',
        'postal_code' => '29401 1234', 'phone' => '+1 (843) 555-1234', 'hours_text' => 'Mon–Fri 8am–5pm', 'directions_url' => 'https://maps.example.com/?q=12+King+St',
        'voucher_rate' => '$1,250.5', 'period_capacity' => '1,200', 'current_wait_days' => '14', 'offers_low_cost_vaccination' => 'on',
    ];

    public function testCreateNormalisesAndAudits(): void
    {
        $id = ClinicService::create(self::FULL);
        $c = ClinicRepository::find($id);
        $this->assertSame('Lowcountry Spay Clinic', $c['name']);
        $this->assertSame('SC', $c['state']);
        $this->assertSame('29401-1234', $c['postal_code']);
        $this->assertSame('8435551234', $c['phone']);
        $this->assertSame('(843) 555-1234', ClinicService::formatPhone($c['phone']));
        $this->assertSame('1250.50', (string) $c['voucher_rate']);
        $this->assertSame(1200, (int) $c['period_capacity']);
        $this->assertSame(14, (int) $c['current_wait_days']);
        $this->assertSame([1, 1], [(int) $c['is_partner'], (int) $c['offers_low_cost_vaccination']]);
        $this->assertSame('Active', $c['status']);
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'clinic_create' AND entity_id = ?", [$id]));
    }

    public function testOnlyTheNameIsRequired(): void
    {
        $c = ClinicRepository::find(ClinicService::create(['name' => 'Walk-in Vet']));
        foreach (['street_address', 'city', 'state', 'postal_code', 'phone', 'hours_text', 'directions_url', 'voucher_rate', 'period_capacity', 'current_wait_days'] as $field) {
            $this->assertNull($c[$field], $field);
        }
        $this->assertSame([0, 0], [(int) $c['is_partner'], (int) $c['offers_low_cost_vaccination']], 'unticked boxes are stored as no');
    }

    public function testValidationReportsEveryFieldAtOnce(): void
    {
        try {
            ClinicService::create(['name' => '', 'state' => 'South Carolina', 'postal_code' => '123', 'phone' => '555-1234',
                'directions_url' => 'javascript:alert(1)', 'voucher_rate' => '-5', 'period_capacity' => '40000', 'current_wait_days' => 'soon',
                'hours_text' => str_repeat('x', 256)]);
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertEqualsCanonicalizing(['name', 'state', 'postal_code', 'phone', 'directions_url', 'voucher_rate', 'period_capacity',
                'current_wait_days', 'hours_text'], array_keys($e->errors));
        }
    }

    public function testDirectionsLinksMustBeHttpOrHttps(): void
    {
        foreach (['ftp://example.com/map', 'data:text/html,hi', 'javascript:alert(1)', 'maps.example.com', 'https://' . str_repeat('a', 250) . '.com'] as $bad) {
            try {
                ClinicService::create(['name' => 'Link test', 'directions_url' => $bad]);
                $this->fail("expected '$bad' to be refused");
            } catch (ValidationException $e) {
                $this->assertSame(['directions_url'], array_keys($e->errors));
            }
        }
        $id = ClinicService::create(['name' => 'Link test', 'directions_url' => ' http://maps.example.com/a ']);
        $this->assertSame('http://maps.example.com/a', ClinicRepository::find($id)['directions_url']);
    }

    public function testVoucherRateAndCountsStayInRange(): void
    {
        $id = ClinicService::create(['name' => 'Range test', 'voucher_rate' => '99999.99', 'period_capacity' => '32767', 'current_wait_days' => '0']);
        $c = ClinicRepository::find($id);
        $this->assertSame(['99999.99', 32767, 0], [(string) $c['voucher_rate'], (int) $c['period_capacity'], (int) $c['current_wait_days']]);
        $c = ClinicRepository::find(ClinicService::create(['name' => 'Commas', 'voucher_rate' => '$12,345.6', 'period_capacity' => '12,000']));
        $this->assertSame(['12345.60', 12000], [(string) $c['voucher_rate'], (int) $c['period_capacity']], 'commas are fine as thousands separators');
        // A comma anywhere else is refused, so "75,50" is never read as 7550 dollars.
        foreach (['voucher_rate' => ['100000', '12.345', 'free', '75,50', '1,2345', ',75'], 'period_capacity' => ['32768', '2.5', '-1', '1,2', '32,768'],
                     'current_wait_days' => ['99999', '1,4']] as $field => $bads) {
            foreach ($bads as $bad) {
                try {
                    ClinicService::create(['name' => 'Range test', $field => $bad]);
                    $this->fail("expected $field '$bad' to be refused");
                } catch (ValidationException $e) {
                    $this->assertSame([$field], array_keys($e->errors));
                }
            }
        }
    }

    public function testUpdateRecordsOnlyChangedFields(): void
    {
        $id = ClinicService::create(self::FULL);
        $saved = ClinicRepository::find($id);
        $resubmit = ['phone' => ClinicService::formatPhone($saved['phone'])] + $saved; // what the edit form posts back
        ClinicService::update($id, $resubmit);
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'clinic_update' AND entity_id = ?", [$id]), 'no-op saves are not audited');
        ClinicService::update($id, ['current_wait_days' => '21', 'offers_low_cost_vaccination' => ''] + $resubmit);
        $fields = Db::pdo()->prepare("SELECT f.field_name, f.old_value, f.new_value FROM audit_field_change f JOIN audit_log a USING (audit_id)
                                      WHERE a.action = 'clinic_update' AND a.entity_id = ? ORDER BY f.field_name");
        $fields->execute([$id]);
        $this->assertSame([
            ['field_name' => 'current_wait_days', 'old_value' => '14', 'new_value' => '21'],
            ['field_name' => 'offers_low_cost_vaccination', 'old_value' => '1', 'new_value' => '0'],
        ], $fields->fetchAll());
    }

    public function testSuspendAndReactivate(): void
    {
        $id = ClinicService::create(['name' => 'A suspended clinic']);
        ClinicService::create(['name' => 'Zeta active clinic']);
        ClinicService::setSuspended($id, true);
        ClinicService::setSuspended($id, true);
        $this->assertSame('Suspended', ClinicRepository::find($id)['status']);
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'clinic_suspend' AND entity_id = ?", [$id]));
        $names = array_column(ClinicRepository::all(), 'name');
        $this->assertSame('A suspended clinic', end($names), 'suspended clinics are listed last');
        ClinicService::setSuspended($id, false);
        $this->assertSame('Active', ClinicRepository::find($id)['status']);
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'clinic_reactivate' AND entity_id = ?", [$id]));
        $this->expectException(ValidationException::class);
        ClinicService::setSuspended(999999, true);
    }
}

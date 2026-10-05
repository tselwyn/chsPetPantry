<?php
declare(strict_types=1);

namespace Pfpms\Tests\Integration\Reference;

use Pfpms\Db;
use Pfpms\Reference\LookupRepository;
use Pfpms\Reference\LookupService;
use Pfpms\Tests\TestCase;
use Pfpms\Validation\ValidationException;

/** Choice lists (plan P2A): seeded values, code generation, species lists, rename, reorder, deactivate, audit. */
final class LookupServiceTest extends TestCase
{
    public function testSeededValuesAreListedInOrder(): void
    {
        $this->assertSame(['Duplicate record', 'Created in error', 'Erasure request', 'Other (give details)'],
            array_column(LookupRepository::forList('participant_delete_reason'), 'label'));
        $this->assertSame(['duplicate', 'entered_in_error', 'wrong_household', 'other'], array_column(LookupService::options('pet_delete_reason'), 'value_code'));
        $this->assertSame(['value_code' => 'moved_away', 'label' => 'Moved away'], LookupService::options('participant_deactivation_reason')[0]);
        foreach (array_keys(LookupService::LISTS) as $listKey) {
            $this->assertLessThanOrEqual(40, strlen($listKey));
        }
    }

    public function testCodesAreSlugsMadeUniqueWithinTheList(): void
    {
        $id = LookupService::create('participant_delete_reason', ['label' => 'Other']);
        $this->assertSame('other_2', LookupRepository::find($id)['value_code'], "'other' is already used by the seeded value");
        $this->assertSame(10, (int) LookupRepository::find($id)['display_order'], 'new values go after the last one');
        $id2 = LookupService::create('participant_delete_reason', ['label' => "  Owner's   request – Peña "]);
        $value = LookupRepository::find($id2);
        $this->assertSame('owners_request_pena', $value['value_code']);
        $this->assertSame("Owner's request – Peña", $value['label']);
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'lookup_create' AND entity_id = ?", [$id2]));
        // The same wording in another list is fine, and gets the plain code there.
        $this->assertSame('other', LookupRepository::find(LookupService::create('emergency_reason', ['label' => 'Other']))['value_code']);
    }

    public function testSlugsAreAsciiAndFitTheColumn(): void
    {
        $this->assertSame('cafe_creme', LookupService::slug('Café Crème'));
        $this->assertSame('value', LookupService::slug('!!!'));
        $long = str_repeat('Longer wording ', 5);
        $slug = LookupService::slug($long);
        $this->assertLessThanOrEqual(40, strlen($slug));
        $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', $slug);
        LookupService::create('referral_source', ['label' => $long . 'one']);
        $id = LookupService::create('referral_source', ['label' => $long . 'two']);
        $code = LookupRepository::find($id)['value_code'];
        $this->assertLessThanOrEqual(40, strlen($code));
        $this->assertStringEndsWith('_2', $code);
    }

    public function testBodyTypeCodesFitThePetColumn(): void
    {
        $dog = (int) $this->scalar("SELECT species_id FROM species WHERE name = 'Dog'");
        $width = (int) $this->scalar("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
                                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pet' AND COLUMN_NAME = 'body_type'");
        $this->assertSame(30, $width);
        $long = 'Long and low with a deep chest and short legs';
        $first = LookupRepository::find(LookupService::create('body_type', ['label' => $long, 'species_id' => $dog]))['value_code'];
        $second = LookupRepository::find(LookupService::create('body_type', ['label' => $long . ' (older dogs)', 'species_id' => $dog]))['value_code'];
        $this->assertSame('long_and_low_with_a_deep_chest', $first);
        $this->assertSame('long_and_low_with_a_deep_che_2', $second);
        $this->assertLessThanOrEqual($width, strlen($second));
        // Other lists keep the full 40 characters of value_code.
        $this->assertSame(40, strlen(LookupRepository::find(LookupService::create('pet_colour', ['label' => $long]))['value_code']));
    }

    public function testLabelIsRequiredAndUniqueWithinTheList(): void
    {
        foreach (['', '   ', str_repeat('x', 101)] as $bad) {
            try {
                LookupService::create('pet_colour', ['label' => $bad]);
                $this->fail('expected a ValidationException');
            } catch (ValidationException $e) {
                $this->assertSame(['label'], array_keys($e->errors));
            }
        }
        $this->expectException(ValidationException::class);
        LookupService::create('participant_delete_reason', ['label' => 'DUPLICATE RECORD']);
    }

    public function testBodyTypesNeedASpeciesAndAreOfferedPerSpecies(): void
    {
        $dog = (int) $this->scalar("SELECT species_id FROM species WHERE name = 'Dog'");
        $cat = (int) $this->scalar("SELECT species_id FROM species WHERE name = 'Cat'");
        try {
            LookupService::create('body_type', ['label' => 'Stocky']);
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['species_id'], array_keys($e->errors));
        }
        LookupService::create('body_type', ['label' => 'Stocky', 'species_id' => (string) $dog]);
        $catId = LookupService::create('body_type', ['label' => 'Stocky', 'species_id' => $cat]);
        $this->assertSame('stocky_2', LookupRepository::find($catId)['value_code'], 'codes are unique across the whole list');
        $this->assertSame(['stocky'], array_column(LookupService::options('body_type', $dog), 'value_code'));
        $this->assertSame(['stocky_2'], array_column(LookupService::options('body_type', $cat), 'value_code'));
        $this->assertNull(LookupRepository::find(LookupService::create('pet_colour', ['label' => 'Brindle', 'species_id' => $dog]))['species_id'],
            'lists that are not species-specific ignore the species');
    }

    public function testRenameKeepsTheCodeAndIsAuditedOnlyWhenChanged(): void
    {
        $id = (int) $this->scalar("SELECT lookup_id FROM lookup_value WHERE list_key = 'participant_delete_reason' AND value_code = 'duplicate'");
        LookupService::rename($id, ['label' => 'Duplicate participant record']);
        $value = LookupRepository::find($id);
        $this->assertSame('duplicate', $value['value_code']);
        $this->assertSame('Duplicate participant record', $value['label']);
        LookupService::rename($id, ['label' => ' Duplicate participant record ']);
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'lookup_rename' AND entity_id = ?", [$id]), 'no-op saves are not audited');
        $this->assertSame('Duplicate participant record', $this->scalar(
            "SELECT f.new_value FROM audit_field_change f JOIN audit_log a USING (audit_id) WHERE a.action = 'lookup_rename' AND a.entity_id = ? AND f.field_name = 'label'", [$id]));
        $this->expectException(ValidationException::class);
        LookupService::rename($id, ['label' => 'Created in error']);
    }

    public function testMoveSwapsWithTheNeighbour(): void
    {
        $idOf = fn(string $code): int => (int) $this->scalar("SELECT lookup_id FROM lookup_value WHERE list_key = 'participant_delete_reason' AND value_code = ?", [$code]);
        LookupService::move($idOf('created_in_error'), 'up');
        $this->assertSame(['created_in_error', 'duplicate', 'erasure_request', 'other'], array_column(LookupRepository::forList('participant_delete_reason'), 'value_code'));
        $this->assertSame([1, 2, 3, 9], array_map('intval', array_column(LookupRepository::forList('participant_delete_reason'), 'display_order')),
            'the two positions are exchanged; the others keep theirs');
        $this->assertSame(['field_name' => 'display_order', 'old_value' => '2', 'new_value' => '1'], Db::pdo()->query(
            "SELECT f.field_name, f.old_value, f.new_value FROM audit_field_change f JOIN audit_log a USING (audit_id) WHERE a.action = 'lookup_reorder'")->fetch());
        LookupService::move($idOf('other'), 'down');
        LookupService::move($idOf('created_in_error'), 'up');
        $this->assertSame(['created_in_error', 'duplicate', 'erasure_request', 'other'], array_column(LookupRepository::forList('participant_delete_reason'), 'value_code'));
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'lookup_reorder'"), 'moving past either end does nothing');
        LookupService::move($idOf('duplicate'), 'down');
        $this->assertSame(['created_in_error', 'erasure_request', 'duplicate', 'other'], array_column(LookupRepository::forList('participant_delete_reason'), 'value_code'));
    }

    public function testMoveRenumbersTiedPositions(): void
    {
        $a = LookupService::create('decline_reason', ['label' => 'A']);
        $b = LookupService::create('decline_reason', ['label' => 'B']);
        $c = LookupService::create('decline_reason', ['label' => 'C']);
        Db::pdo()->exec("UPDATE lookup_value SET display_order = 0 WHERE list_key = 'decline_reason'");
        LookupService::move($c, 'up');
        $rows = LookupRepository::forList('decline_reason');
        $this->assertSame([$a, $c, $b], array_map('intval', array_column($rows, 'lookup_id')));
        $this->assertSame([1, 2, 3], array_map('intval', array_column($rows, 'display_order')));
    }

    public function testBodyTypesMoveWithinTheirSpecies(): void
    {
        $dog = (int) $this->scalar("SELECT species_id FROM species WHERE name = 'Dog'");
        $cat = (int) $this->scalar("SELECT species_id FROM species WHERE name = 'Cat'");
        LookupService::create('body_type', ['label' => 'Lean', 'species_id' => $cat]);
        $slim = LookupService::create('body_type', ['label' => 'Slim', 'species_id' => $dog]);
        $round = LookupService::create('body_type', ['label' => 'Round', 'species_id' => $cat]);
        LookupService::move($slim, 'up');
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'lookup_reorder'"), 'the only dog value is already first');
        LookupService::move($round, 'up');
        $this->assertSame(['Round', 'Lean'], array_column(LookupService::options('body_type', $cat), 'label'));
        $this->assertSame(['Slim'], array_column(LookupService::options('body_type', $dog), 'label'));
    }

    public function testDeactivatedValuesAreKeptButNotOffered(): void
    {
        $id = (int) $this->scalar("SELECT lookup_id FROM lookup_value WHERE list_key = 'participant_deactivation_reason' AND value_code = 'no_pets'");
        LookupService::setActive($id, false);
        LookupService::setActive($id, false);
        $this->assertNotContains('no_pets', array_column(LookupService::options('participant_deactivation_reason'), 'value_code'));
        $this->assertContains('no_pets', array_column(LookupRepository::forList('participant_deactivation_reason'), 'value_code'));
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'lookup_deactivate' AND entity_id = ?", [$id]));
        LookupService::setActive($id, true);
        $this->assertContains('no_pets', array_column(LookupService::options('participant_deactivation_reason'), 'value_code'));
    }

    public function testUnknownListsAreRejected(): void
    {
        $this->assertFalse(LookupService::isList('shoe_size'));
        $this->expectException(\InvalidArgumentException::class);
        LookupService::options('shoe_size');
    }
}

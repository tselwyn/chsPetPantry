<?php
declare(strict_types=1);

namespace Pfpms\Tests\Integration\Reference;

use Pfpms\Config;
use Pfpms\Db;
use Pfpms\Reference\BreedRepository;
use Pfpms\Reference\BreedService;
use Pfpms\Reference\SizeBandRepository;
use Pfpms\Reference\SizeBandService;
use Pfpms\Reference\SpeciesRepository;
use Pfpms\Reference\SpeciesService;
use Pfpms\Storage\SecureFileStore;
use Pfpms\Tests\TestCase;
use Pfpms\Validation\ValidationException;

/** Species, breeds and size bands with pictures (plan P2A, US-13). */
final class SpeciesBreedSizeBandTest extends TestCase
{
    private array $savedConfig;
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedConfig = Config::snapshot();
        $this->storage = sys_get_temp_dir() . '/pfpms-bands-' . bin2hex(random_bytes(4));
        $app = $this->savedConfig['app'] ?? [];
        $app['secure_storage'] = $this->storage;
        Config::override(['app' => $app] + $this->savedConfig);
    }

    protected function tearDown(): void
    {
        Config::override($this->savedConfig);
        if (is_dir($this->storage)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->storage, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->storage);
        }
        parent::tearDown();
    }

    // Species -----------------------------------------------------------------------------

    public function testSpeciesAreAddedRenamedAndDeactivatedWithAnAuditTrail(): void
    {
        $id = SpeciesService::create(['name' => '  Guinea   pig ']);
        $this->assertSame('Guinea pig', SpeciesRepository::find($id)['name']);
        SpeciesService::rename($id, ['name' => 'Guinea pig']);
        $this->assertSame(0, $this->audits('species_update', $id), 'no-op renames are not audited');
        SpeciesService::rename($id, ['name' => 'Cavy']);
        SpeciesService::setActive($id, false);
        SpeciesService::setActive($id, false);

        $this->assertSame('Cavy', SpeciesRepository::find($id)['name']);
        $this->assertSame(0, (int) SpeciesRepository::find($id)['is_active']);
        $this->assertSame([1, 1, 1], [$this->audits('species_create', $id), $this->audits('species_update', $id), $this->audits('species_deactivate', $id)]);
        $this->assertNotContains($id, array_map('intval', array_column(SpeciesRepository::choices(), 'species_id')), 'inactive species are not offered');
    }

    public function testSpeciesNamesAreUniqueIgnoringCase(): void
    {
        try {
            SpeciesService::create(['name' => 'DOG']);
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['name'], array_keys($e->errors));
        }
        $rabbit = SpeciesService::create(['name' => 'Rabbit']);
        $this->assertRefused(fn() => SpeciesService::rename($rabbit, ['name' => 'cat']), 'name');
        SpeciesService::rename($rabbit, ['name' => 'RABBIT']); // a change of case is allowed
        $this->assertSame('RABBIT', SpeciesRepository::find($rabbit)['name']);
        $this->expectException(ValidationException::class);
        SpeciesService::create(['name' => str_repeat('x', 31)]);
    }

    // Breeds ------------------------------------------------------------------------------

    public function testBreedNamesAreUniqueWithinTheirSpeciesOnly(): void
    {
        $dog = $this->species('Dog');
        $cat = $this->species('Cat');
        BreedService::create($dog, ['name' => 'Mixed breed']);
        BreedService::create($cat, ['name' => 'Mixed breed']);
        try {
            BreedService::create($dog, ['name' => 'mixed BREED']);
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('name', $e->errors);
        }
        $b = BreedService::create($dog, ['name' => 'Beagle']);
        $this->expectException(ValidationException::class);
        BreedService::rename($b, ['name' => 'Mixed Breed']);
    }

    public function testBreedsAreRenamedAndDeactivatedWithAnAuditTrail(): void
    {
        $id = BreedService::create($this->species('Dog'), ['name' => 'Labrador']);
        BreedService::rename($id, ['name' => 'Labrador Retriever']);
        BreedService::rename($id, ['name' => ' Labrador  Retriever ']);
        BreedService::setActive($id, false);
        BreedService::setActive($id, false);
        $breed = BreedRepository::find($id);
        $this->assertSame(['Labrador Retriever', 0], [$breed['name'], (int) $breed['is_active']]);
        $this->assertSame([1, 1, 1], [$this->audits('breed_create', $id), $this->audits('breed_update', $id), $this->audits('breed_deactivate', $id)],
            'no-op renames and repeated deactivations are not audited');
        BreedService::setActive($id, true);
        $this->assertSame([1, 1], [(int) BreedRepository::find($id)['is_active'], $this->audits('breed_activate', $id)]);
    }

    public function testABreedNeedsASpeciesThatExists(): void
    {
        $this->assertRefused(fn() => BreedService::create(999999, ['name' => 'Beagle']), '_form');
        $this->assertRefused(fn() => BreedService::create($this->species('Dog'), ['name' => str_repeat('x', 61)]), 'name');
    }

    // Size bands: ranges ------------------------------------------------------------------

    public function testAdjacentBandsDoNotOverlap(): void
    {
        $species = SpeciesService::create(['name' => 'Band test']);
        $small = $this->band($species, 'Small', '0', '25');
        $medium = $this->band($species, 'Medium', '25', '60');
        $large = $this->band($species, 'Large', '60', '');
        $this->assertSame([$small, $medium, $large], array_map('intval', array_column(SizeBandRepository::forSpecies($species), 'size_band_id')));
        $this->assertSame(['25.0', '60.0', null], array_column(SizeBandRepository::forSpecies($species), 'max_weight_lbs'));
        $this->assertSame([], SizeBandService::gaps(SizeBandRepository::forSpecies($species)));
    }

    public function testAnOverlappingBandIsRefused(): void
    {
        $species = SpeciesService::create(['name' => 'Band test']);
        $this->band($species, 'Small', '0', '25');
        $this->band($species, 'Medium', '25', '60');
        $this->assertRefused(fn() => $this->band($species, 'Between', '20', '30'), 'min_weight_lbs');
        $this->assertRefused(fn() => $this->band($species, 'Inside', '30', '40'), 'min_weight_lbs');
        $this->assertRefused(fn() => $this->band($species, 'Around', '0', '100'), 'min_weight_lbs');
        $this->assertSame(2, count(SizeBandRepository::forSpecies($species)));
    }

    public function testAnOpenEndedBandConflictsWithAnythingAboveItsMinimum(): void
    {
        $species = SpeciesService::create(['name' => 'Band test']);
        $this->band($species, 'Giant', '90', '');
        $this->assertRefused(fn() => $this->band($species, 'Huge', '100', '120'), 'min_weight_lbs');
        $this->assertRefused(fn() => $this->band($species, 'Heavy', '95', ''), 'min_weight_lbs');
        $this->assertRefused(fn() => $this->band($species, 'Crossing', '80', '90.5'), 'min_weight_lbs');
        $this->band($species, 'Large', '60', '90');
        $this->assertRefused(fn() => $this->band($species, 'Open', '50', ''), 'min_weight_lbs');
        $this->band($species, 'Bigger', '50', '60');
    }

    public function testBandsOfDifferentSpeciesMayShareRanges(): void
    {
        $a = SpeciesService::create(['name' => 'Band test A']);
        $b = SpeciesService::create(['name' => 'Band test B']);
        $this->band($a, 'Small', '0', '25');
        $this->band($b, 'Small', '0', '25');
        $this->assertCount(1, SizeBandRepository::forSpecies($b));
    }

    public function testTheLowerWeightMustBeBelowTheUpperWeight(): void
    {
        $species = SpeciesService::create(['name' => 'Band test']);
        $this->assertRefused(fn() => $this->band($species, 'Flat', '30', '30'), 'max_weight_lbs');
        $this->assertRefused(fn() => $this->band($species, 'Upside down', '30', '10'), 'max_weight_lbs');
    }

    public function testWeightsMustBeZeroTo999Point9WithOneDecimalAtMost(): void
    {
        $species = SpeciesService::create(['name' => 'Band test']);
        foreach (['-1', '1000', '12.55', 'ten', ''] as $bad) {
            $this->assertRefused(fn() => $this->band($species, 'Bad', $bad, ''), 'min_weight_lbs');
        }
        $this->assertRefused(fn() => $this->band($species, 'Bad', '0', '1000'), 'max_weight_lbs');
        $id = $this->band($species, 'Fine', '0.5', '999.9');
        $this->assertSame(['0.5', '999.9'], [SizeBandRepository::find($id)['min_weight_lbs'], SizeBandRepository::find($id)['max_weight_lbs']]);
    }

    public function testValidationReportsEveryFieldAtOnce(): void
    {
        try {
            SizeBandService::create(['species_id' => '999999', 'name' => '', 'min_weight_lbs' => 'x', 'max_weight_lbs' => 'y'], 'not a picture');
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['species_id', 'name', 'min_weight_lbs', 'max_weight_lbs', 'picture'], array_keys($e->errors));
        }
    }

    public function testBandNamesAreUniqueWithinTheirSpecies(): void
    {
        $species = SpeciesService::create(['name' => 'Band test']);
        $other = SpeciesService::create(['name' => 'Band test 2']);
        $this->band($species, 'Small', '0', '25');
        $this->assertRefused(fn() => $this->band($species, 'SMALL', '25', '60'), 'name');
        $this->band($other, 'Small', '0', '25');
    }

    public function testAnUpdateIgnoresTheBandItselfAndIsAuditedOnlyWhenSomethingChanged(): void
    {
        $species = SpeciesService::create(['name' => 'Band test']);
        $small = $this->band($species, 'Small', '0', '25');
        $this->band($species, 'Medium', '25', '60');
        SizeBandService::update($small, ['name' => 'Small', 'min_weight_lbs' => '0', 'max_weight_lbs' => '25.0']);
        $this->assertSame(0, $this->audits('size_band_update', $small), 'no-op saves are not audited');

        SizeBandService::update($small, ['name' => 'Little', 'min_weight_lbs' => '0', 'max_weight_lbs' => '20']);
        $changes = Db::pdo()->prepare("SELECT f.field_name, f.old_value, f.new_value FROM audit_field_change f JOIN audit_log a USING (audit_id)
                                        WHERE a.action = 'size_band_update' AND a.entity_id = ? ORDER BY f.field_name");
        $changes->execute([$small]);
        $this->assertSame([['field_name' => 'max_weight_lbs', 'old_value' => '25.0', 'new_value' => '20.0'],
            ['field_name' => 'name', 'old_value' => 'Small', 'new_value' => 'Little']], $changes->fetchAll());
        $this->assertSame(['20 to under 25 lb', '60 lb and over'], SizeBandService::gaps(SizeBandRepository::forSpecies($species)));

        $this->assertRefused(fn() => SizeBandService::update($small, ['name' => 'Little', 'min_weight_lbs' => '0', 'max_weight_lbs' => '30']), 'min_weight_lbs');
    }

    public function testTheSpeciesOfABandCannotBeChangedByAnUpdate(): void
    {
        $species = SpeciesService::create(['name' => 'Band test']);
        $id = $this->band($species, 'Small', '0', '25');
        SizeBandService::update($id, ['species_id' => (string) $this->species('Cat'), 'name' => 'Small', 'min_weight_lbs' => '0', 'max_weight_lbs' => '25']);
        $this->assertSame($species, (int) SizeBandRepository::find($id)['species_id']);
    }

    // Size bands: deleting ----------------------------------------------------------------

    public function testABandInUseCannotBeDeletedButAnUnusedOneCan(): void
    {
        $species = SpeciesService::create(['name' => 'Band test']);
        $used = $this->band($species, 'Small', '0', '25');
        $unused = $this->band($species, 'Medium', '25', '60');
        $admin = $this->makeUser(['role' => 'Administrator'])['user_id'];
        Db::pdo()->prepare('INSERT INTO allotment_rule (rule_version, species_id, size_band_id, lbs_per_distribution, effective_from, created_by, published_at, published_by)
                            VALUES (1, ?, ?, 4.5, ?, ?, ?, ?)')
            ->execute([$species, $used, '2026-10-01', $admin, '2026-10-01 12:00:00', $admin]); // a published rule

        $this->assertTrue(SizeBandRepository::inUse($used));
        $this->assertRefused(fn() => SizeBandService::delete($used), '_form');
        $this->assertNotNull(SizeBandRepository::find($used));

        $this->assertFalse(SizeBandRepository::inUse($unused));
        SizeBandService::delete($unused);
        $this->assertNull(SizeBandRepository::find($unused));
        $this->assertSame(1, $this->audits('size_band_delete', $unused));
        $inUse = array_column(SizeBandRepository::all(), 'in_use', 'size_band_id');
        $this->assertSame(1, (int) $inUse[$used]);
    }

    public function testABandOnlyInTheDraftAllotmentCanBeDeletedAndLeavesTheDraft(): void
    {
        $species = SpeciesService::create(['name' => 'Band test']);
        $band = $this->band($species, 'Small', '0', '25');
        $other = $this->band($species, 'Medium', '25', '60');
        $admin = $this->makeUser(['role' => 'Administrator'])['user_id'];
        $insert = Db::pdo()->prepare('INSERT INTO allotment_rule (rule_version, species_id, size_band_id, lbs_per_distribution, effective_from, created_by)
                                        VALUES (7, ?, ?, ?, ?, ?)'); // draft cells: published_at stays NULL
        $insert->execute([$species, $band, '4.50', '2026-10-02', $admin]);
        $insert->execute([$species, $other, null, '2026-10-02', $admin]);

        $this->assertFalse(SizeBandRepository::inUse($band), 'draft figures do not keep a band in use');
        $this->assertSame(1, (int) array_column(SizeBandRepository::all(), 'in_draft', 'size_band_id')[$band]);
        SizeBandService::delete($band);
        $this->assertNull(SizeBandRepository::find($band));
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM allotment_rule WHERE size_band_id = ?', [$band]));
        $this->assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM allotment_rule WHERE size_band_id = ?', [$other]), 'the rest of the draft stays');
        $snapshot = (string) $this->scalar("SELECT snapshot FROM audit_log WHERE action = 'size_band_delete' AND entity_id = ?", [$band]);
        $this->assertStringContainsString('draft_allotment_cells', $snapshot, 'the removed draft figures are kept in the audit snapshot');
        $this->assertStringContainsString('4.50', $snapshot);
    }

    public function testABandAPetUsesCannotBeDeleted(): void
    {
        $species = SpeciesService::create(['name' => 'Band test']);
        $band = $this->band($species, 'Small', '0', '25');
        $userId = $this->makeUser(['role' => 'Administrator'])['user_id'];
        $site = $this->makeSite('Band test site');
        Db::pdo()->prepare('INSERT INTO participant (participant_code, legal_first_name, legal_last_name, postal_code, household_size,
                                                     home_site_id, registration_site_id, registered_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(['TB1', 'Test', 'Owner', '29401', 1, $site, $site, $userId]);
        Db::pdo()->prepare('INSERT INTO pet (participant_id, name, species_id, size_band_id, created_by) VALUES (?, ?, ?, ?, ?)')
            ->execute([(int) Db::pdo()->lastInsertId(), 'Rex', $species, $band, $userId]);

        $this->assertTrue(SizeBandRepository::inUse($band));
        $this->assertRefused(fn() => SizeBandService::delete($band), '_form');
        $this->assertNotNull(SizeBandRepository::find($band));
        $this->assertSame(0, $this->audits('size_band_delete', $band));
    }

    public function testGapsListEveryWeightNoBandCovers(): void
    {
        $species = SpeciesService::create(['name' => 'Band test']);
        $this->assertSame([], SizeBandService::gaps(SizeBandRepository::forSpecies($species)), 'no bands yet: nothing to warn about');
        $this->band($species, 'Small', '5', '25');
        $this->band($species, 'Large', '40.5', '');
        $this->assertSame(['0 to under 5 lb', '25 to under 40.5 lb'], SizeBandService::gaps(SizeBandRepository::forSpecies($species)));
    }

    // Size bands: pictures ----------------------------------------------------------------

    public function testAPictureIsCleanedStoredEncryptedReplacedAndRemoved(): void
    {
        $species = SpeciesService::create(['name' => 'Band test']);
        $id = SizeBandService::create(['species_id' => (string) $species, 'name' => 'Small', 'min_weight_lbs' => '0', 'max_weight_lbs' => '25'], self::picture('png'));
        $first = SizeBandRepository::find($id)['picture_path'];
        $this->assertMatchesRegularExpression('~^size_bands/\d{4}/\d{2}/[a-f0-9]{32}\.bin$~', (string) $first);
        $this->assertStringStartsWith("\x89PNG", SecureFileStore::get($first));

        SizeBandService::update($id, ['name' => 'Small', 'min_weight_lbs' => '0', 'max_weight_lbs' => '25'], self::picture('jpeg'));
        $second = SizeBandRepository::find($id)['picture_path'];
        $this->assertNotSame($first, $second);
        $this->assertStringStartsWith("\xFF\xD8", SecureFileStore::get($second));
        $this->assertFileDoesNotExist($this->storage . '/' . $first, 'the replaced picture is deleted');

        SizeBandService::update($id, ['name' => 'Small', 'min_weight_lbs' => '0', 'max_weight_lbs' => '25'], null, removePicture: true);
        $this->assertNull(SizeBandRepository::find($id)['picture_path']);
        $this->assertFileDoesNotExist($this->storage . '/' . $second);
        $this->assertSame(2, $this->audits('size_band_update', $id));
    }

    public function testDeletingABandDeletesItsPicture(): void
    {
        $species = SpeciesService::create(['name' => 'Band test']);
        $id = SizeBandService::create(['species_id' => (string) $species, 'name' => 'Small', 'min_weight_lbs' => '0', 'max_weight_lbs' => '25'], self::picture('jpeg'));
        $path = (string) SizeBandRepository::find($id)['picture_path'];
        $this->assertFileExists($this->storage . '/' . $path);
        SizeBandService::delete($id);
        $this->assertFileDoesNotExist($this->storage . '/' . $path);
    }

    public function testAFileThatIsNotAPictureIsRefusedAndNothingIsStored(): void
    {
        $species = SpeciesService::create(['name' => 'Band test']);
        $this->assertRefused(fn() => SizeBandService::create(['species_id' => (string) $species, 'name' => 'Small', 'min_weight_lbs' => '0', 'max_weight_lbs' => '25'],
            "%PDF-1.4 not a picture"), 'picture');
        $this->assertSame([], SizeBandRepository::forSpecies($species));
        $this->assertDirectoryDoesNotExist($this->storage . '/size_bands');
    }

    public function testAPictureOverTheSizeLimitIsRefused(): void
    {
        $this->setSetting('photo_max_mb', '1');
        $species = SpeciesService::create(['name' => 'Band test']);
        $this->assertRefused(fn() => SizeBandService::create(['species_id' => (string) $species, 'name' => 'Small', 'min_weight_lbs' => '0', 'max_weight_lbs' => '25'],
            self::picture('png') . str_repeat("\0", 1048576)), 'picture');
    }

    public function testNothingIsStoredWhenTheSaveIsRefused(): void
    {
        $species = SpeciesService::create(['name' => 'Band test']);
        $id = $this->band($species, 'Small', '0', '25');
        $this->band($species, 'Medium', '25', '60');
        $this->assertRefused(fn() => SizeBandService::update($id, ['name' => 'Medium', 'min_weight_lbs' => '0', 'max_weight_lbs' => '25'], self::picture('png')), 'name');
        $this->assertDirectoryDoesNotExist($this->storage . '/size_bands', 'nothing is stored when validation fails');
    }

    public function testUploadsAreReadOnlyWhenPhpReceivedThem(): void
    {
        $this->assertNull(SizeBandService::readUpload(null));
        $this->assertNull(SizeBandService::readUpload(['error' => UPLOAD_ERR_NO_FILE]));
        $this->assertRefused(fn() => SizeBandService::readUpload(['error' => UPLOAD_ERR_INI_SIZE]), 'picture');
        $this->assertRefused(fn() => SizeBandService::readUpload(['error' => UPLOAD_ERR_PARTIAL, 'tmp_name' => '']), 'picture');
        $this->assertRefused(fn() => SizeBandService::readUpload(['error' => UPLOAD_ERR_OK, 'tmp_name' => __FILE__]), 'picture');
        $this->assertRefused(fn() => SizeBandService::readUpload(['error' => [UPLOAD_ERR_OK], 'tmp_name' => [__FILE__]]), 'picture');
    }

    public function testRangeLabels(): void
    {
        $this->assertSame('0 to under 25 lb', SizeBandService::rangeLabel('0.0', '25.0'));
        $this->assertSame('12.5 to under 25 lb', SizeBandService::rangeLabel('12.5', '25.0'));
        $this->assertSame('90 lb and over', SizeBandService::rangeLabel('90.0', null));
    }

    // Helpers -----------------------------------------------------------------------------

    private function band(int $speciesId, string $name, string $min, string $max): int
    {
        return SizeBandService::create(['species_id' => (string) $speciesId, 'name' => $name, 'min_weight_lbs' => $min, 'max_weight_lbs' => $max]);
    }

    private function species(string $name): int
    {
        return (int) $this->scalar('SELECT species_id FROM species WHERE name = ?', [$name]);
    }

    private function audits(string $action, int $entityId): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM audit_log WHERE action = ? AND entity_id = ?', [$action, $entityId]);
    }

    private function assertRefused(callable $fn, string $field): void
    {
        try {
            $fn();
            $this->fail("expected a ValidationException on $field");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors, 'errors: ' . json_encode($e->errors));
        }
    }

    private static function picture(string $type): string
    {
        $image = imagecreatetruecolor(30, 20);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 150, 50));
        ob_start();
        $type === 'png' ? imagepng($image) : imagejpeg($image);
        return (string) ob_get_clean();
    }
}

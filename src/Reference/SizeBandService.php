<?php
declare(strict_types=1);

namespace Pfpms\Reference;

use InvalidArgumentException;
use PDOException;
use Pfpms\Allotment\AllotmentRuleRepository;
use Pfpms\Allotment\AllotmentRuleService;
use Pfpms\Audit\Audit;
use Pfpms\Db;
use Pfpms\Settings;
use Pfpms\Storage\ImageProcessor;
use Pfpms\Storage\SecureFileStore;
use Pfpms\Validation\ValidationException;
use Pfpms\Validation\Validator;
use RuntimeException;
use Throwable;

/**
 * Size bands with pictures (plan P2A, US-13). A band is a weight range for one species, e.g.
 * Small = 0 to under 25 lb; the band a pet is in decides its food allotment.
 *
 * Ranges are half-open, [min, max), and a band with no max has no upper limit, so the bands
 * 0–25 and 25–60 meet without overlapping and a 25 lb pet is in the second one. Bands of one
 * species must never overlap. Weights are compared in whole tenths of a pound, exactly as
 * DECIMAL(5,1) stores them.
 *
 * Pictures are cleaned by ImageProcessor and kept encrypted in SecureFileStore (area
 * "size_bands"); public/file.php serves them to signed-in users.
 */
final class SizeBandService
{
    public const FIELDS = ['species_id', 'name', 'min_weight_lbs', 'max_weight_lbs'];

    private const AUDITED = ['name', 'min_weight_lbs', 'max_weight_lbs', 'picture_path'];

    private const IN_USE = 'This size band cannot be deleted because pets or published allotment rules use it. You can rename it or change its weights instead.';

    /**
     * @param ?string $picture the uploaded picture's bytes (see readUpload), or null for no picture
     * @throws ValidationException
     */
    public static function create(array $input, ?string $picture = null): int
    {
        [$values, $image] = self::validate($input, null, $picture);
        $path = $image !== null ? SecureFileStore::put('size_bands', $image['bytes']) : null;
        try {
            return Db::transaction(function () use ($values, $path): int {
                self::recheck($values, null);
                $id = SizeBandRepository::insert($values + ['picture_path' => $path]);
                Audit::record('size_band_create', 'size_band', $id, details: $values + ['picture' => $path !== null]);
                return $id;
            });
        } catch (Throwable $e) {
            self::discard($path);
            throw $e;
        }
    }

    /**
     * Change the name, weights or picture. The species of a band never changes. Call this
     * outside any transaction: a replaced picture is deleted once the change has committed.
     * @param ?string $picture new picture bytes, or null to keep the current one
     * @throws ValidationException
     */
    public static function update(int $sizeBandId, array $input, ?string $picture = null, bool $removePicture = false): void
    {
        $before = SizeBandRepository::find($sizeBandId) ?? throw ValidationException::one('_form', 'That size band no longer exists.');
        [$values, $image] = self::validate(['species_id' => (string) $before['species_id']] + $input, $sizeBandId, $picture);
        $values['picture_path'] = $removePicture ? null : $before['picture_path'];
        $newPath = null;
        if ($image !== null) {
            $newPath = $values['picture_path'] = SecureFileStore::put('size_bands', $image['bytes']);
        }
        $changes = Audit::diff($before, $values, self::AUDITED);
        if (!$changes) {
            return;
        }
        try {
            Db::transaction(function () use ($sizeBandId, $values, $changes): void {
                self::recheck($values, $sizeBandId);
                SizeBandRepository::update($sizeBandId, $values);
                Audit::record('size_band_update', 'size_band', $sizeBandId, changes: $changes);
            });
        } catch (Throwable $e) {
            self::discard($newPath);
            throw $e;
        }
        if ($before['picture_path'] !== $values['picture_path']) {
            self::discard($before['picture_path']);
        }
    }

    /**
     * Only a band that no pet or published allotment rule uses can be deleted. Its figures in the
     * draft allotment version are removed with it (under the allotment lock) and kept in the audit
     * snapshot. Call this outside any transaction.
     * @throws ValidationException
     */
    public static function delete(int $sizeBandId): void
    {
        $band = SizeBandRepository::find($sizeBandId) ?? throw ValidationException::one('_form', 'That size band no longer exists.');
        if (SizeBandRepository::inUse($sizeBandId)) {
            throw ValidationException::one('_form', self::IN_USE);
        }
        try {
            AllotmentRuleService::withLock(fn() => Db::transaction(function () use ($sizeBandId, $band): void {
                $draftCells = AllotmentRuleRepository::deleteDraftCellsForBand($sizeBandId);
                SizeBandRepository::delete($sizeBandId);
                Audit::record('size_band_delete', 'size_band', $sizeBandId, snapshot: $band + ['draft_allotment_cells' => $draftCells]);
            }));
        } catch (PDOException $e) {
            if (Db::errorCode($e) === 1451) { // a pet or rule started using it in the meantime
                throw ValidationException::one('_form', self::IN_USE);
            }
            throw $e;
        }
        self::discard($band['picture_path']);
    }

    /**
     * The bytes of the picture uploaded in a $_FILES entry, or null when no file was chosen.
     * @throws ValidationException when the upload failed or is too large
     */
    public static function readUpload(mixed $upload): ?string
    {
        if (!is_array($upload)) {
            return null;
        }
        $error = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw ValidationException::one('picture', self::tooLarge());
        }
        $tmp = $upload['tmp_name'] ?? null;
        if ($error !== UPLOAD_ERR_OK || !is_string($tmp) || !is_uploaded_file($tmp)) {
            throw ValidationException::one('picture', 'The picture did not upload. Please try again.');
        }
        if ((int) filesize($tmp) > self::maxBytes()) {
            throw ValidationException::one('picture', self::tooLarge());
        }
        return (string) file_get_contents($tmp);
    }

    /** A weight range for people to read, e.g. "0 to under 25 lb" or "90 lb and over". */
    public static function rangeLabel(mixed $min, mixed $max): string
    {
        $low = self::formatWeight($min);
        return $max === null || $max === '' ? "$low lb and over" : "$low to under " . self::formatWeight($max) . ' lb';
    }

    /**
     * Weights that none of a species' bands cover, for a warning on the list page: a pet of
     * that weight could not be given a band.
     * @param list<array> $bands the species' bands, lightest first
     * @return list<string> ranges for people to read
     */
    public static function gaps(array $bands): array
    {
        $gaps = [];
        $covered = 0; // everything below this many tenths is covered
        foreach ($bands as $band) {
            $min = (int) self::tenths((string) $band['min_weight_lbs']);
            if ($min > $covered) {
                $gaps[] = self::rangeLabel(self::decimal($covered), self::decimal($min));
            }
            if ($band['max_weight_lbs'] === null) {
                return $gaps;
            }
            $covered = max($covered, (int) self::tenths((string) $band['max_weight_lbs']));
        }
        if ($bands) {
            $gaps[] = self::rangeLabel(self::decimal($covered), null);
        }
        return $gaps;
    }

    /** "25.0" becomes "25"; "12.5" stays "12.5". */
    public static function formatWeight(mixed $weight): string
    {
        $tenths = self::tenths(is_scalar($weight) ? (string) $weight : null);
        if ($tenths === null) {
            return is_scalar($weight) ? (string) $weight : '';
        }
        return $tenths % 10 === 0 ? (string) intdiv($tenths, 10) : intdiv($tenths, 10) . '.' . $tenths % 10;
    }

    /**
     * @return array{0: array{species_id: int, name: string, min_weight_lbs: string, max_weight_lbs: ?string}, 1: ?array{bytes: string, mime: string}}
     *   the normalised values and the cleaned picture
     */
    private static function validate(array $input, ?int $sizeBandId, ?string $picture): array
    {
        $errors = [];
        $speciesRaw = trim((string) ($input['species_id'] ?? ''));
        $speciesId = preg_match('/^\d{1,10}$/', $speciesRaw) ? (int) $speciesRaw : null;
        if ($speciesId === null || SpeciesRepository::find($speciesId) === null) {
            $errors['species_id'] = 'Choose the species this size band is for.';
            $speciesId = null;
        }
        $name = Validator::text($input['name'] ?? null, 20);
        if ($name === null) {
            $errors['name'] = 'Enter a name for the size band (up to 20 characters), e.g. Small.';
        } elseif ($speciesId !== null && ($problem = self::nameProblem($speciesId, $name, $sizeBandId)) !== null) {
            $errors['name'] = $problem;
        }

        $min = self::tenths(isset($input['min_weight_lbs']) ? (string) $input['min_weight_lbs'] : null);
        if ($min === null) {
            $errors['min_weight_lbs'] = 'Enter the lowest weight in pounds, from 0 to 999.9 (at most one decimal place).';
        }
        $maxRaw = trim((string) ($input['max_weight_lbs'] ?? ''));
        $max = $maxRaw === '' ? null : self::tenths($maxRaw);
        if ($maxRaw !== '' && $max === null) {
            $errors['max_weight_lbs'] = 'Enter the upper weight in pounds, up to 999.9 (at most one decimal place), or leave it blank for no upper limit.';
        } elseif ($min !== null && $max !== null && $max <= $min) {
            $errors['max_weight_lbs'] = 'The upper weight must be more than the lowest weight.';
        }
        if ($speciesId !== null && $min !== null && !isset($errors['max_weight_lbs'])
            && ($problem = self::overlapProblem($speciesId, $min, $max, $sizeBandId)) !== null) {
            $errors['min_weight_lbs'] = $problem;
        }

        $image = null;
        if ($picture !== null) {
            if ($picture === '') {
                $errors['picture'] = 'The picture file is empty. Choose another picture.';
            } elseif (strlen($picture) > self::maxBytes()) {
                $errors['picture'] = self::tooLarge();
            } else {
                try {
                    $image = ImageProcessor::sanitize($picture);
                } catch (InvalidArgumentException $e) {
                    $errors['picture'] = $e->getMessage();
                }
            }
        }

        if ($errors) {
            throw new ValidationException($errors);
        }
        return [[
            'species_id' => (int) $speciesId,
            'name' => (string) $name,
            'min_weight_lbs' => self::decimal((int) $min),
            'max_weight_lbs' => $max === null ? null : self::decimal($max),
        ], $image];
    }

    /**
     * Inside the write transaction: lock the species and check the name and range again, so
     * two people saving bands for the same species at once cannot create an overlap.
     */
    private static function recheck(array $values, ?int $sizeBandId): void
    {
        SpeciesRepository::lock($values['species_id']);
        $max = $values['max_weight_lbs'] === null ? null : self::tenths($values['max_weight_lbs']);
        $errors = array_filter([
            'name' => self::nameProblem($values['species_id'], $values['name'], $sizeBandId),
            'min_weight_lbs' => self::overlapProblem($values['species_id'], (int) self::tenths($values['min_weight_lbs']), $max, $sizeBandId),
        ]);
        if ($errors) {
            throw new ValidationException($errors);
        }
    }

    private static function nameProblem(int $speciesId, string $name, ?int $sizeBandId): ?string
    {
        return SizeBandRepository::nameTaken($speciesId, $name, $sizeBandId) ? 'This species already has a size band with this name.' : null;
    }

    /** The first other band of the species whose range meets [min, max), described for the user. */
    private static function overlapProblem(int $speciesId, int $min, ?int $max, ?int $sizeBandId): ?string
    {
        foreach (SizeBandRepository::forSpecies($speciesId) as $band) {
            if ((int) $band['size_band_id'] === $sizeBandId) {
                continue;
            }
            $otherMin = (int) self::tenths((string) $band['min_weight_lbs']);
            $otherMax = $band['max_weight_lbs'] === null ? null : self::tenths((string) $band['max_weight_lbs']);
            if (($otherMax === null || $min < $otherMax) && ($max === null || $otherMin < $max)) {
                return 'These weights overlap the ' . $band['name'] . ' band (' . self::rangeLabel($band['min_weight_lbs'], $band['max_weight_lbs'])
                    . '). Size bands of one species must not overlap.';
            }
        }
        return null;
    }

    /** "25", "25.5" or "025.5" as whole tenths of a pound (0 to 9999), or null when not a valid weight. */
    private static function tenths(?string $weight): ?int
    {
        if ($weight === null || !preg_match('/^\s*(\d{1,3})(?:\.(\d))?\s*$/', $weight, $m)) {
            return null;
        }
        return (int) $m[1] * 10 + (int) ($m[2] ?? 0);
    }

    private static function decimal(int $tenths): string
    {
        return intdiv($tenths, 10) . '.' . $tenths % 10;
    }

    private static function maxBytes(): int
    {
        return max(1, Settings::int('photo_max_mb', 5)) * 1048576;
    }

    private static function tooLarge(): string
    {
        return 'The picture is larger than ' . max(1, Settings::int('photo_max_mb', 5)) . ' MB. Choose a smaller picture.';
    }

    /** Delete a stored picture that is no longer used. A file left behind is encrypted and harmless. */
    private static function discard(?string $path): void
    {
        if ($path === null) {
            return;
        }
        try {
            SecureFileStore::delete($path);
        } catch (RuntimeException) {
            // Nothing to do: the record no longer points at it.
        }
    }
}

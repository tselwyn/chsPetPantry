<?php
declare(strict_types=1);

namespace Pfpms\Allotment;

use Pfpms\Clock;
use Pfpms\Db;

/**
 * SQL for allotment_rule (migration 0011). Prepared statements only; returns plain arrays.
 *
 * Every UPDATE or DELETE on allotment_rule lives here, and each one is limited to draft rows
 * (published_at IS NULL), except renumberToDraft, which is limited to a version that has not
 * started anywhere yet. A published version that has started is never changed, so the rules
 * behind any distribution's allotment_rule_version stay exactly as they were.
 */
final class AllotmentRuleRepository
{
    private const FORM_ORDER = "CASE r.food_form WHEN 'Any' THEN 0 WHEN 'Dry' THEN 1 ELSE 2 END";

    /**
     * One row per version, newest first: start date, publication, number of rules.
     * @return list<array{rule_version: int, effective_from: string, published_at: ?string, published_by: ?int, created_by: int, created_at: string, rules: int, empty_cells: int}>
     */
    public static function versions(): array
    {
        return Db::pdo()->query(
            'SELECT rule_version, MIN(effective_from) AS effective_from, MIN(published_at) AS published_at, MIN(published_by) AS published_by,
                    MIN(created_by) AS created_by, MIN(created_at) AS created_at, COUNT(*) AS rules,
                    SUM(lbs_per_distribution IS NULL) AS empty_cells
               FROM allotment_rule
              GROUP BY rule_version
              ORDER BY rule_version DESC'
        )->fetchAll();
    }

    /** @return list<array{rule_version: int, effective_from: string}> published versions, oldest first */
    public static function published(): array
    {
        return Db::pdo()->query(
            'SELECT rule_version, MIN(effective_from) AS effective_from FROM allotment_rule
              WHERE published_at IS NOT NULL GROUP BY rule_version ORDER BY MIN(effective_from), rule_version'
        )->fetchAll();
    }

    /** The version in force on a site-local date (see AllotmentCalculator::versionOn), or null before the first one starts. */
    public static function versionInForce(string $localDate): ?int
    {
        $st = Db::pdo()->prepare(
            'SELECT rule_version FROM allotment_rule
              WHERE published_at IS NOT NULL AND effective_from <= ? AND rule_version > 0
              GROUP BY rule_version, effective_from ORDER BY effective_from DESC, rule_version DESC LIMIT 1'
        );
        $st->execute([$localDate]);
        $v = $st->fetchColumn();
        return $v === false ? null : (int) $v;
    }

    public static function draftVersion(): ?int
    {
        $v = Db::pdo()->query('SELECT MIN(rule_version) FROM allotment_rule WHERE published_at IS NULL')->fetchColumn();
        return $v === null || $v === false ? null : (int) $v;
    }

    /**
     * Hand out the next version number from the id_sequence counter (migration 0011). It only goes
     * up, so a number that was ever used, even for a draft that was discarded or a version taken
     * back before it started, never stands for a second set of rules. Real versions start at 1:
     * 0 is reserved for imported legacy distributions. Call inside the write transaction.
     */
    public static function allocateVersion(): int
    {
        $pdo = Db::pdo();
        // Never below a number already in the table either (rows a seed or an import inserted directly).
        $st = $pdo->prepare(
            "UPDATE id_sequence SET next_value = LAST_INSERT_ID(GREATEST(next_value, (SELECT COALESCE(MAX(rule_version), 0) + 1 FROM allotment_rule))) + 1
              WHERE seq_name = 'allotment_version'"
        );
        $st->execute();
        if ($st->rowCount() !== 1) {
            throw new \RuntimeException('The allotment_version counter is missing (migration 0011).');
        }
        return (int) $pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();
    }

    /**
     * The rules of one version with species and band details, in display order.
     * @return list<array>
     */
    public static function rules(int $version): array
    {
        $st = Db::pdo()->prepare(
            'SELECT r.rule_id, r.rule_version, r.species_id, sp.name AS species_name, r.size_band_id, sb.name AS band_name,
                    sb.min_weight_lbs, sb.max_weight_lbs, r.food_form, r.lbs_per_distribution, r.effective_from, r.published_at, r.published_by
               FROM allotment_rule r
               JOIN species sp ON sp.species_id = r.species_id
               JOIN size_band sb ON sb.size_band_id = r.size_band_id
              WHERE r.rule_version = ?
              ORDER BY sp.name, sb.min_weight_lbs, ' . self::FORM_ORDER
        );
        $st->execute([$version]);
        return $st->fetchAll();
    }

    /** Lock one version's rows for the rest of the transaction and return them. @return list<array> */
    public static function lockVersion(int $version): array
    {
        $st = Db::pdo()->prepare(
            'SELECT rule_id, rule_version, species_id, size_band_id, food_form, lbs_per_distribution, effective_from, published_at
               FROM allotment_rule WHERE rule_version = ? ORDER BY rule_id FOR UPDATE'
        );
        $st->execute([$version]);
        return $st->fetchAll();
    }

    /**
     * The size bands every version must cover: each band of an active species, and each band that
     * still has an Active pet even if its species has since been deactivated.
     * @return list<array{species_id: int, species_name: string, species_active: int, size_band_id: int, band_name: string, min_weight_lbs: string, max_weight_lbs: ?string}>
     */
    public static function coverage(): array
    {
        return Db::pdo()->query(
            "SELECT sb.species_id, sp.name AS species_name, sp.is_active AS species_active, sb.size_band_id, sb.name AS band_name,
                    sb.min_weight_lbs, sb.max_weight_lbs
               FROM size_band sb
               JOIN species sp ON sp.species_id = sb.species_id
              WHERE sp.is_active = 1 OR EXISTS (SELECT 1 FROM pet p WHERE p.size_band_id = sb.size_band_id AND p.status = 'Active')
              ORDER BY sp.name, sb.min_weight_lbs"
        )->fetchAll();
    }

    /** @param list<array{species_id: int, size_band_id: int, food_form: string, lbs: ?string}> $cells */
    public static function insertDraftCells(int $version, array $cells, string $effectiveFrom, int $createdBy): void
    {
        $st = Db::pdo()->prepare(
            'INSERT INTO allotment_rule (rule_version, species_id, size_band_id, food_form, lbs_per_distribution, effective_from, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $now = Clock::db();
        foreach ($cells as $c) {
            $st->execute([$version, $c['species_id'], $c['size_band_id'], $c['food_form'], $c['lbs'], $effectiveFrom, $createdBy, $now]);
        }
    }

    public static function setDraftCell(int $ruleId, ?string $lbs): void
    {
        Db::pdo()->prepare('UPDATE allotment_rule SET lbs_per_distribution = ? WHERE rule_id = ? AND published_at IS NULL')->execute([$lbs, $ruleId]);
    }

    public static function setDraftStart(int $version, string $effectiveFrom): void
    {
        Db::pdo()->prepare('UPDATE allotment_rule SET effective_from = ? WHERE rule_version = ? AND published_at IS NULL')->execute([$effectiveFrom, $version]);
    }

    /** Delete draft rows: the whole draft, or only the given rows of it. @param ?list<int> $ruleIds */
    public static function deleteDraftRows(int $version, ?array $ruleIds = null): void
    {
        if ($ruleIds === []) {
            return;
        }
        $sql = 'DELETE FROM allotment_rule WHERE rule_version = ? AND published_at IS NULL';
        $args = [$version];
        if ($ruleIds !== null) {
            $sql .= ' AND rule_id IN (' . implode(',', array_fill(0, count($ruleIds), '?')) . ')';
            array_push($args, ...array_map('intval', $ruleIds));
        }
        Db::pdo()->prepare($sql)->execute($args);
    }

    /** Remove a size band's cells from the draft (when the band is deleted) and return what was removed. @return list<array> */
    public static function deleteDraftCellsForBand(int $sizeBandId): array
    {
        $st = Db::pdo()->prepare(
            'SELECT rule_id, rule_version, species_id, size_band_id, food_form, lbs_per_distribution
               FROM allotment_rule WHERE size_band_id = ? AND published_at IS NULL FOR UPDATE'
        );
        $st->execute([$sizeBandId]);
        $rows = $st->fetchAll();
        if ($rows) {
            Db::pdo()->prepare('DELETE FROM allotment_rule WHERE size_band_id = ? AND published_at IS NULL')->execute([$sizeBandId]);
        }
        return $rows;
    }

    /** Publish the draft. Returns the number of rows published. */
    public static function publish(int $version, string $effectiveFrom, int $publishedBy): int
    {
        $st = Db::pdo()->prepare(
            'UPDATE allotment_rule SET effective_from = ?, published_at = ?, published_by = ? WHERE rule_version = ? AND published_at IS NULL'
        );
        $st->execute([$effectiveFrom, Clock::db(), $publishedBy, $version]);
        return $st->rowCount();
    }

    /**
     * Take a published version that has not started anywhere back into the draft, under a new
     * number so a published number never stands for two different sets of rules.
     */
    public static function renumberToDraft(int $version, int $newVersion, string $notStartedAfter): int
    {
        $st = Db::pdo()->prepare(
            'UPDATE allotment_rule SET rule_version = ?, published_at = NULL, published_by = NULL
              WHERE rule_version = ? AND published_at IS NOT NULL AND effective_from > ?'
        );
        $st->execute([$newVersion, $version, $notStartedAfter]);
        return $st->rowCount();
    }

    /**
     * Scheduled or open distribution events dated within a range, for the start-date rule: tablets
     * prepared for them may already hold the current rules.
     * @return list<array{event_id: int, event_date: string, status: string, site_name: string}>
     */
    public static function eventsBetween(string $from, string $to): array
    {
        $st = Db::pdo()->prepare(
            "SELECT e.event_id, e.event_date, e.status, s.name AS site_name
               FROM distribution_event e JOIN site s ON s.site_id = e.site_id
              WHERE e.status IN ('Scheduled', 'Open') AND e.event_date >= ? AND e.event_date <= ?
              ORDER BY e.event_date, s.name"
        );
        $st->execute([$from, $to]);
        return $st->fetchAll();
    }

    /**
     * Where a draft came from, read from the audit log: the version it copied, or the scheduled
     * version that was taken back into it.
     * @return array{based_on?: ?int, taken_back_from?: int}
     */
    public static function draftOrigin(int $version): array
    {
        $st = Db::pdo()->prepare(
            "SELECT action, entity_id, details FROM audit_log
              WHERE entity_type = 'allotment_version' AND action IN ('allotment_draft_create', 'allotment_withdraw')
              ORDER BY audit_id DESC LIMIT 50"
        );
        $st->execute();
        foreach ($st->fetchAll() as $row) {
            $details = json_decode((string) $row['details'], true) ?: [];
            if ($row['action'] === 'allotment_draft_create' && (int) $row['entity_id'] === $version) {
                return ['based_on' => isset($details['based_on']) ? (int) $details['based_on'] : null];
            }
            if ($row['action'] === 'allotment_withdraw' && (int) ($details['now_draft_version'] ?? 0) === $version) {
                return ['taken_back_from' => (int) $row['entity_id']];
            }
        }
        return [];
    }

    /** @return list<string> the time zones of the active sites */
    public static function activeSiteTimeZones(): array
    {
        return array_map('strval', Db::pdo()->query('SELECT DISTINCT time_zone FROM site WHERE is_active = 1')->fetchAll(\PDO::FETCH_COLUMN));
    }
}

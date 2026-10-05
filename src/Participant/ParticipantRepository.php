<?php
declare(strict_types=1);

namespace Pfpms\Participant;

use Pfpms\Db;

/** SQL for participant search (UC-02). Prepared statements only; returns plain arrays. */
final class ParticipantRepository
{
    /** Longest search text accepted (Validator::text). */
    public const MAX_TERM = 100;

    /** Match tiers, best first (plan P3: code > exact name > prefix > phonetic > partial). */
    public const RANK_CODE = 1;
    public const RANK_EXACT = 2;
    public const RANK_PREFIX = 3;
    public const RANK_PARTIAL = 5;

    /** Minimal fields for a result row: no address, phone, date of birth or notes (UC-02). */
    private const COLUMNS = 'p.participant_id, p.participant_code, p.legal_first_name, p.legal_last_name, p.preferred_name, p.status,
        p.last_distribution_date, (SELECT COUNT(*) FROM pet pt WHERE pt.participant_id = p.participant_id AND pt.status = \'Active\') AS pet_count';

    /**
     * Participants registered at $siteId matching $term over legal and preferred names, phone and participant code, best match first.
     * Merged records never show; Deleted ones only with $includeDeleted (Administrators).
     * @return array{rows: list<array>, truncated: bool} at most $limit rows; truncated when more matched
     */
    public static function search(int $siteId, string $term, int $limit, bool $includeDeleted = false): array
    {
        $limit = max(1, min(1000, $limit));
        $tiers = self::tiers($term);
        if ($tiers === []) {
            return ['rows' => [], 'truncated' => false];
        }
        $case = [];
        $rankArgs = [];
        $where = [];
        $whereArgs = [];
        foreach ($tiers as [$rank, $sql, $args]) {
            $case[] = "WHEN $sql THEN $rank";
            $where[] = $sql;
            array_push($rankArgs, ...$args);
            array_push($whereArgs, ...$args);
        }
        $statuses = $includeDeleted ? "'Active', 'Inactive', 'Deleted'" : "'Active', 'Inactive'";
        $st = Db::pdo()->prepare(
            'SELECT ' . self::COLUMNS . ', CASE ' . implode(' ', $case) . ' END AS match_rank
               FROM participant p
               JOIN participant_site ps ON ps.participant_id = p.participant_id AND ps.site_id = ?
              WHERE p.status IN (' . $statuses . ') AND (' . implode(' OR ', $where) . ')
              ORDER BY match_rank, p.legal_last_name, p.legal_first_name, p.participant_id
              LIMIT ' . ($limit + 1)
        );
        $st->execute(array_merge($rankArgs, [$siteId], $whereArgs));
        $rows = $st->fetchAll();
        $truncated = count($rows) > $limit;
        return ['rows' => array_slice($rows, 0, $limit), 'truncated' => $truncated];
    }

    /**
     * The match tiers for $term as [rank, SQL condition, args], in rank order. Names compare under the column collation
     * (utf8mb4_unicode_520_ci), so case and accents do not matter.
     * @return list<array{0: int, 1: string, 2: list<string>}>
     */
    private static function tiers(string $term): array
    {
        $tiers = [];
        $partial = null;
        $code = self::codeOf($term);
        if ($code !== null) {
            $tiers[] = [self::RANK_CODE, 'p.participant_code = ?', [$code]];
        }
        $words = self::words($term);
        if ($words !== []) {
            $name = implode(' ', $words);
            // Exact legal or preferred name (US-05): either name alone, or with the surname in either order.
            $tiers[] = [self::RANK_EXACT, '(p.legal_first_name = ? OR p.legal_last_name = ? OR p.preferred_name = ?
                OR CONCAT(p.legal_first_name, \' \', p.legal_last_name) = ? OR CONCAT(p.legal_last_name, \' \', p.legal_first_name) = ?
                OR CONCAT(p.preferred_name, \' \', p.legal_last_name) = ? OR CONCAT(p.legal_last_name, \' \', p.preferred_name) = ?)',
                array_fill(0, 7, $name)];
            $tiers[] = self::everyWord(self::RANK_PREFIX, $words, fn(string $w): string => self::like($w) . '%');
            $partial = self::everyWord(self::RANK_PARTIAL, $words, fn(string $w): string => '%' . self::like($w) . '%');
        }
        $phone = self::phoneDigits($term);
        if ($phone !== null) {
            $tiers[] = [self::RANK_PARTIAL, 'p.phone LIKE ?', ['%' . $phone . '%']];
        }
        if ($partial !== null) {
            $tiers[] = $partial; // after the phone tier: both rank as partial
        }
        return $tiers;
    }

    /**
     * Each word must match the legal first or last name, or the preferred name (US-05), with $pattern.
     * @param list<string> $words
     * @return array{0: int, 1: string, 2: list<string>}
     */
    private static function everyWord(int $rank, array $words, callable $pattern): array
    {
        $parts = [];
        $args = [];
        foreach ($words as $word) {
            $parts[] = '(p.legal_first_name LIKE ? OR p.legal_last_name LIKE ? OR p.preferred_name LIKE ?)';
            $like = $pattern($word);
            array_push($args, $like, $like, $like);
        }
        return [$rank, '(' . implode(' AND ', $parts) . ')', $args];
    }

    /** "P12", "p 12", "P-12" or a bare number of up to 9 digits → "P12"; else null. */
    public static function codeOf(string $term): ?string
    {
        return preg_match('/^\s*[Pp]?\s*-?\s*0*(\d{1,9})\s*$/', $term, $m) ? 'P' . $m[1] : null;
    }

    /** The digits of a phone-like term (digits, spaces, ( ) . - +), at least 4 of them, without a leading US "1"; else null. */
    public static function phoneDigits(string $term): ?string
    {
        if (!preg_match('/^[\d\s().+-]+$/', $term)) {
            return null;
        }
        $digits = (string) preg_replace('/\D/', '', $term);
        if (strlen($digits) === 11 && $digits[0] === '1') {
            $digits = substr($digits, 1);
        }
        return strlen($digits) >= 4 ? $digits : null;
    }

    /**
     * The name words of $term: split on spaces and commas ("Munoz, Jose"), keeping words with a letter in them.
     * @return list<string>
     */
    public static function words(string $term): array
    {
        $words = preg_split('/[\s,]+/u', trim($term), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_filter($words, fn(string $w): bool => (bool) preg_match('/\p{L}/u', $w)));
    }

    private static function like(string $value): string
    {
        return addcslashes($value, '%_\\');
    }
}

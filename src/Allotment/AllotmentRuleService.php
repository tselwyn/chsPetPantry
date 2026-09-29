<?php
declare(strict_types=1);

namespace Pfpms\Allotment;

use DateTimeImmutable;
use DateTimeZone;
use Pfpms\Audit\Audit;
use Pfpms\Clock;
use Pfpms\Db;
use Pfpms\Reference\SizeBandService;
use Pfpms\Settings;
use Pfpms\Validation\ValidationException;
use Pfpms\Validation\Validator;
use RuntimeException;

/**
 * Allotment rule versions (UC-05 §4.1, UC-06 §4.5, plan P2A): how many pounds of food each pet
 * gets per distribution, by species and size band.
 *
 * - A version is a complete set of rules with a start date. The version in force on a site's
 *   local date is the published one with the latest start date on or before it; a version
 *   that has started is never changed, so the version stamped on a distribution always means
 *   the same rules. Version numbers come from a counter that only goes up.
 * - One upcoming change at a time: either a draft or one scheduled (published, not started)
 *   version. A new draft copies the version in force. A scheduled version can be taken back to
 *   edit until it starts; it then becomes a draft under a new number.
 * - A change starts tomorrow at the earliest at every site, and not on or before an event that
 *   tablets may already have been prepared for (within offline_pack_ttl_hours). The first
 *   version can start today. Version 0 is reserved for imported legacy distributions.
 * - Every write takes one named lock and re-checks the state on locked rows, and the draft forms
 *   carry a revision, so a stale form or a change made after the review is refused rather than
 *   saved or published. Call these methods outside any transaction.
 */
final class AllotmentRuleService
{
    /** Pounds per pet per distribution: 0 means deliberately none; the most is 200. */
    public const MAX_LBS = '200';
    public const MODE_ANY = 'any';
    public const MODE_SPLIT = 'split';
    public const FORM_LABELS = ['Any' => 'dry or wet', 'Dry' => 'dry', 'Wet' => 'wet'];

    private const LOCK = 'allotment_rules';
    private const FORMS = [self::MODE_ANY => ['Any'], self::MODE_SPLIT => ['Dry', 'Wet']];
    private const GONE = 'That draft no longer exists. It may have been published or discarded.';

    /**
     * The versions with their status and dates, the version in force and any upcoming change.
     * @return array{versions: list<array>, in_force: ?int, draft: ?int, scheduled: ?int, today: string}
     */
    public static function overview(): array
    {
        $today = self::today()['latest'];
        $published = AllotmentRuleRepository::published();
        $inForce = AllotmentCalculator::versionOn($published, $today);
        $nextStart = [];
        foreach ($published as $i => $p) {
            $nextStart[(int) $p['rule_version']] = $published[$i + 1]['effective_from'] ?? null;
        }
        $versions = [];
        $draft = $scheduled = null;
        foreach (AllotmentRuleRepository::versions() as $v) {
            $n = (int) $v['rule_version'];
            $v['status'] = match (true) {
                $v['published_at'] === null => 'draft',
                $v['effective_from'] > $today => 'scheduled',
                $n === $inForce => 'in_force',
                default => 'replaced',
            };
            $v['ends_on'] = isset($nextStart[$n]) ? self::dayBefore($nextStart[$n]) : null;
            $draft = $v['status'] === 'draft' ? $n : $draft;
            $scheduled = $v['status'] === 'scheduled' ? $n : $scheduled;
            $versions[] = $v;
        }
        return ['versions' => $versions, 'in_force' => $inForce, 'draft' => $draft, 'scheduled' => $scheduled, 'today' => $today];
    }

    /**
     * One version's rules grouped for display: species → bands → pounds per form.
     * @return list<array{species_id: int, species_name: string, mode: string, bands: list<array>}>
     */
    public static function table(int $version): array
    {
        $coverage = [];
        foreach (AllotmentRuleRepository::coverage() as $c) {
            $coverage[(int) $c['size_band_id']] = $c;
        }
        $species = [];
        foreach (AllotmentRuleRepository::rules($version) as $r) {
            $sid = (int) $r['species_id'];
            $bid = (int) $r['size_band_id'];
            $species[$sid] ??= ['species_id' => $sid, 'species_name' => $r['species_name'], 'mode' => self::MODE_ANY, 'bands' => []];
            if ($r['food_form'] !== 'Any') {
                $species[$sid]['mode'] = self::MODE_SPLIT;
            }
            $species[$sid]['bands'][$bid] ??= ['size_band_id' => $bid, 'band_name' => $r['band_name'],
                'range' => SizeBandService::rangeLabel($r['min_weight_lbs'], $r['max_weight_lbs']), 'required' => isset($coverage[$bid]), 'lbs' => []];
            $species[$sid]['bands'][$bid]['lbs'][$r['food_form']] = $r['lbs_per_distribution'];
        }
        return array_values(array_map(fn(array $s) => ['bands' => array_values($s['bands'])] + $s, $species));
    }

    /**
     * The draft editor's grid: every band the rules must cover plus any the draft already has,
     * in the draft's mode per species. 'needed' says whether any of the species' bands must be
     * filled in (false for a species no longer offered whose pets are all gone).
     * @return list<array{species_id: int, species_name: string, species_active: bool, needed: bool, mode: string, bands: list<array>}>
     */
    public static function grid(int $version): array
    {
        $rows = AllotmentRuleRepository::rules($version);
        $modes = self::modes($rows);
        $values = [];
        foreach ($rows as $r) {
            $values[(int) $r['size_band_id']][$r['food_form']] = $r['lbs_per_distribution'];
        }
        $bands = [];
        foreach (AllotmentRuleRepository::coverage() as $c) {
            $bands[(int) $c['size_band_id']] = $c + ['required' => true];
        }
        foreach ($rows as $r) {
            $bands[(int) $r['size_band_id']] ??= ['species_id' => $r['species_id'], 'species_name' => $r['species_name'], 'species_active' => 0,
                'size_band_id' => $r['size_band_id'], 'band_name' => $r['band_name'], 'min_weight_lbs' => $r['min_weight_lbs'],
                'max_weight_lbs' => $r['max_weight_lbs'], 'required' => false];
        }
        $grid = [];
        foreach ($bands as $bid => $b) {
            $sid = (int) $b['species_id'];
            $grid[$sid] ??= ['species_id' => $sid, 'species_name' => $b['species_name'], 'species_active' => false, 'needed' => false,
                'mode' => $modes[$sid] ?? self::MODE_ANY, 'bands' => []];
            $grid[$sid]['species_active'] = $grid[$sid]['species_active'] || (bool) $b['species_active'];
            $grid[$sid]['needed'] = $grid[$sid]['needed'] || $b['required'];
            $grid[$sid]['bands'][] = ['size_band_id' => $bid, 'band_name' => $b['band_name'], 'required' => $b['required'],
                'range' => SizeBandService::rangeLabel($b['min_weight_lbs'], $b['max_weight_lbs']), 'lbs' => $values[$bid] ?? []];
        }
        uasort($grid, fn($a, $b) => strcmp($a['species_name'], $b['species_name']));
        return array_values($grid);
    }

    /**
     * A fingerprint of the draft as it is now. The editor and the review screen carry it, and a
     * save or publish made from a form showing an older state of the draft is refused.
     */
    public static function revision(int $version): string
    {
        return self::fingerprint(AllotmentRuleRepository::lockVersion($version));
    }

    /**
     * Size bands a published version does not fully cover: a band added after it was published,
     * or a band missing the figure its species' mode needs.
     * @return list<string>
     */
    public static function coverageGaps(int $version): array
    {
        $rows = AllotmentRuleRepository::rules($version);
        $modes = self::modes($rows);
        $have = [];
        foreach ($rows as $r) {
            if ($r['lbs_per_distribution'] !== null) {
                $have[(int) $r['size_band_id']][$r['food_form']] = true;
            }
        }
        $gaps = [];
        foreach (AllotmentRuleRepository::coverage() as $c) {
            foreach (self::FORMS[$modes[(int) $c['species_id']] ?? self::MODE_ANY] as $form) {
                if (!isset($have[(int) $c['size_band_id']][$form])) {
                    $gaps[] = $c['species_name'] . ' – ' . $c['band_name'];
                    break;
                }
            }
        }
        return $gaps;
    }

    /**
     * Work out a household's pounds under one version, for the "try it" form.
     * @param array<int|string, int> $countsByBand number of pets per size band
     * @return array{total: string, buckets: list<array>, per_pet: list<array>}
     * @throws MissingAllotmentRule
     * @throws \OverflowException when the total is more than a distribution can record
     */
    public static function tryIt(int $version, array $countsByBand): array
    {
        $rules = AllotmentRuleRepository::rules($version);
        $speciesOf = [];
        foreach (AllotmentRuleRepository::coverage() as $c) {
            $speciesOf[(int) $c['size_band_id']] = (int) $c['species_id'];
        }
        foreach ($rules as $r) {
            $speciesOf[(int) $r['size_band_id']] = (int) $r['species_id'];
        }
        $pets = [];
        foreach ($countsByBand as $bandId => $count) {
            for ($i = 0; $i < $count && isset($speciesOf[(int) $bandId]); $i++) {
                $pets[] = ['species_id' => $speciesOf[(int) $bandId], 'size_band_id' => (int) $bandId];
            }
        }
        return AllotmentCalculator::entitlement($rules, $pets);
    }

    /**
     * Where a draft's figures came from, for the editor: a copy of a version, or a scheduled
     * version taken back before it started.
     * @return array{based_on?: ?int, taken_back_from?: int}
     */
    public static function draftOrigin(int $version): array
    {
        return AllotmentRuleRepository::draftOrigin($version);
    }

    /** Start a new draft, copying the version in force (or empty for the first version). Returns its number. */
    public static function startDraft(int $actorId): int
    {
        return self::withLock(fn(): int => Db::transaction(function () use ($actorId): int {
            if (AllotmentRuleRepository::draftVersion() !== null) {
                throw ValidationException::one('_form', 'There is already a draft. Continue editing it, or discard it first.');
            }
            $today = self::today();
            if (self::scheduled($today['latest']) !== null) {
                throw ValidationException::one('_form', 'A change is already scheduled. Take it back to edit it instead of starting another one.');
            }
            $coverage = AllotmentRuleRepository::coverage();
            if (!$coverage) {
                throw ValidationException::one('_form', 'Add size bands first (Setup > Size bands): the rules give pounds for each size band.');
            }
            $base = AllotmentCalculator::versionOn(AllotmentRuleRepository::published(), $today['latest']);
            $baseRows = $base !== null ? AllotmentRuleRepository::rules($base) : [];
            $modes = self::modes($baseRows);
            $values = [];
            foreach ($baseRows as $r) {
                $values[(int) $r['size_band_id']][$r['food_form']] = $r['lbs_per_distribution'];
            }
            $cells = [];
            foreach ($coverage as $c) {
                $bid = (int) $c['size_band_id'];
                foreach (self::FORMS[$modes[(int) $c['species_id']] ?? self::MODE_ANY] as $form) {
                    $cells[] = ['species_id' => (int) $c['species_id'], 'size_band_id' => $bid, 'food_form' => $form, 'lbs' => $values[$bid][$form] ?? null];
                }
            }
            $version = AllotmentRuleRepository::allocateVersion();
            $start = self::earliestStart();
            AllotmentRuleRepository::insertDraftCells($version, $cells, $start, $actorId);
            Audit::record('allotment_draft_create', 'allotment_version', $version, details: [
                'based_on' => $base, 'effective_from' => $start, 'cells' => count($cells),
            ]);
            return $version;
        }));
    }

    /**
     * Save the draft grid: start date, a mode per species and the pounds per cell (blank = not
     * filled in yet). Returns the changes made (empty when nothing changed).
     * @param array{effective_from?: ?string, mode?: array<int|string, string>, lbs?: array<string, string>} $input
     * @param ?string $revision the draft revision the form was built from (null skips the check, for scripts)
     * @return array<string, array{0: mixed, 1: mixed}>
     * @throws ValidationException
     * @throws StaleDraftException when the draft changed since the form was opened
     */
    public static function saveDraft(int $version, array $input, int $actorId, ?string $revision): array
    {
        $errors = [];
        $startRaw = trim((string) ($input['effective_from'] ?? ''));
        $start = Validator::date($startRaw);
        if ($start === null) {
            $errors['effective_from'] = 'Enter the start date, for example 2026-11-01.';
        }
        $posted = [];
        foreach ($input['lbs'] ?? [] as $key => $raw) {
            if (!preg_match('/^(\d{1,10})_(Any|Dry|Wet)$/', (string) $key, $m)) {
                continue;
            }
            $raw = trim((string) $raw);
            $lbs = $raw === '' ? null : Validator::decimal($raw, 2, self::MAX_LBS);
            if ($raw !== '' && $lbs === null) {
                $errors["lbs[$key]"] = 'Enter pounds from 0 to ' . self::MAX_LBS . ', with at most 2 decimals, for example 4.5.';
                continue;
            }
            $posted[(int) $m[1]][$m[2]] = $lbs;
        }
        $modesIn = [];
        foreach ($input['mode'] ?? [] as $speciesId => $mode) {
            if (preg_match('/^\d{1,10}$/', (string) $speciesId) && isset(self::FORMS[$mode])) {
                $modesIn[(int) $speciesId] = $mode;
            }
        }
        if ($errors) {
            throw new ValidationException($errors);
        }

        return self::withLock(fn(): array => Db::transaction(function () use ($version, $start, $posted, $modesIn, $actorId, $revision): array {
            $locked = AllotmentRuleRepository::lockVersion($version);
            if (!$locked || $locked[0]['published_at'] !== null) {
                throw ValidationException::one('_form', self::GONE);
            }
            if ($revision !== null && !hash_equals(self::fingerprint($locked), $revision)) {
                throw new StaleDraftException('Someone changed this draft since you opened it, so your changes were not saved. The latest figures are shown: make your change again.');
            }
            $grid = self::grid($version); // current rows plus bands to cover, with names
            $rowId = [];
            foreach ($locked as $r) {
                $rowId[(int) $r['size_band_id']][$r['food_form']] = (int) $r['rule_id'];
            }
            $changes = [];
            if ($start !== $locked[0]['effective_from']) {
                AllotmentRuleRepository::setDraftStart($version, (string) $start);
                $changes['Start date'] = [$locked[0]['effective_from'], $start];
            }
            $insert = [];
            $delete = [];
            foreach ($grid as $species) {
                $sid = $species['species_id'];
                $mode = $modesIn[$sid] ?? $species['mode'];
                if ($mode !== $species['mode']) {
                    $changes[$species['species_name'] . ': pounds for'] = [self::modeLabel($species['mode']), self::modeLabel($mode)];
                }
                foreach ($species['bands'] as $band) {
                    $bid = $band['size_band_id'];
                    $label = $species['species_name'] . ' / ' . $band['band_name'];
                    foreach ($rowId[$bid] ?? [] as $form => $ruleId) {
                        if (!in_array($form, self::FORMS[$mode], true)) {
                            $delete[] = $ruleId; // switched mode: the other form's figures go
                            if (($band['lbs'][$form] ?? null) !== null) {
                                $changes["$label / $form"] = [$band['lbs'][$form], null];
                            }
                        }
                    }
                    foreach (self::FORMS[$mode] as $form) {
                        $old = $band['lbs'][$form] ?? null;
                        $new = array_key_exists($bid, $posted) && array_key_exists($form, $posted[$bid]) ? $posted[$bid][$form] : $old;
                        if (isset($rowId[$bid][$form])) {
                            if ($new !== $old) {
                                AllotmentRuleRepository::setDraftCell($rowId[$bid][$form], $new);
                            }
                        } else {
                            $insert[] = ['species_id' => $sid, 'size_band_id' => $bid, 'food_form' => $form, 'lbs' => $new];
                        }
                        if ($new !== $old) {
                            $changes["$label / $form"] = [$old, $new];
                        }
                    }
                }
            }
            AllotmentRuleRepository::deleteDraftRows($version, $delete);
            AllotmentRuleRepository::insertDraftCells($version, $insert, (string) $start, $actorId);
            if ($changes) {
                // Band names are free text: never let the audit's secret-field redaction hide the pounds.
                Audit::record('allotment_draft_update', 'allotment_version', $version, changes: $changes, redactChanges: false);
            }
            return $changes;
        }));
    }

    public static function discardDraft(int $version, int $actorId): void
    {
        self::withLock(fn() => Db::transaction(function () use ($version): void {
            $locked = AllotmentRuleRepository::lockVersion($version);
            if (!$locked || $locked[0]['published_at'] !== null) {
                throw ValidationException::one('_form', self::GONE);
            }
            $snapshot = self::snapshot($version);
            AllotmentRuleRepository::deleteDraftRows($version);
            Audit::record('allotment_draft_discard', 'allotment_version', $version, snapshot: ['rules' => $snapshot]);
        }));
    }

    /**
     * Check that the draft can be published from a date, without changing anything, and describe
     * what publishing would do: the version it replaces and every figure that changes. The
     * returned revision goes into the publish form.
     * @return array{version: int, effective_from: string, first: bool, previous: ?int, previous_until: ?string, changes: list<array>, revision: string}
     * @throws ValidationException listing every problem
     */
    public static function review(int $version, ?string $effectiveFrom): array
    {
        $rows = AllotmentRuleRepository::rules($version);
        if (!$rows || $rows[0]['published_at'] !== null) {
            throw ValidationException::one('_form', self::GONE);
        }
        return self::check($version, $rows, $effectiveFrom) + ['revision' => self::revision($version)];
    }

    /**
     * Publish the draft from a date, with an optional note on what changed and why. Returns the
     * start date. $revision is the one shown on the review screen (null skips the check, for scripts).
     * @throws ValidationException
     * @throws StaleDraftException when the draft changed after it was reviewed
     */
    public static function publish(int $version, ?string $effectiveFrom, ?string $reason, int $actorId, ?string $revision): string
    {
        $reasonRaw = trim((string) $reason);
        $reason = $reasonRaw === '' ? null : Validator::text($reasonRaw, 255);
        if ($reasonRaw !== '' && $reason === null) {
            throw ValidationException::one('reason', 'Keep the note to 255 characters or fewer.');
        }
        return self::withLock(fn(): string => Db::transaction(function () use ($version, $effectiveFrom, $reason, $actorId, $revision): string {
            $locked = AllotmentRuleRepository::lockVersion($version);
            if (!$locked || $locked[0]['published_at'] !== null) {
                throw ValidationException::one('_form', self::GONE);
            }
            if ($revision !== null && !hash_equals(self::fingerprint($locked), $revision)) {
                throw new StaleDraftException('The draft was changed after you reviewed it, so it was not published. Review it again.');
            }
            $plan = self::check($version, AllotmentRuleRepository::rules($version), $effectiveFrom);
            // After check() every band is either complete or wholly empty and no longer needed: drop the empty ones.
            $empty = array_map(fn($r) => (int) $r['rule_id'], array_filter($locked, fn($r) => $r['lbs_per_distribution'] === null));
            AllotmentRuleRepository::deleteDraftRows($version, $empty);
            $published = AllotmentRuleRepository::publish($version, $plan['effective_from'], $actorId);
            if ($published === 0 || $published !== count($locked) - count($empty)) {
                throw new RuntimeException('Allotment version publish touched an unexpected number of rows.');
            }
            Audit::record('allotment_publish', 'allotment_version', $version, reason: $reason, details: [
                'effective_from' => $plan['effective_from'], 'replaces' => $plan['previous'], 'replaced_until' => $plan['previous_until'],
                'rules' => $published, 'changed' => count($plan['changes']),
            ], snapshot: ['rules' => self::snapshot($version)]);
            return $plan['effective_from'];
        }));
    }

    /**
     * Take the scheduled version back to edit before it starts. It becomes the draft under a new
     * number, so the old number is never used for different rules. Returns the draft's number.
     */
    public static function withdraw(int $version, int $actorId): int
    {
        return self::withLock(fn(): int => Db::transaction(function () use ($version): int {
            $today = self::today()['latest'];
            $published = AllotmentRuleRepository::published();
            $latest = $published ? $published[count($published) - 1] : null;
            if ($latest === null || (int) $latest['rule_version'] !== $version) {
                throw ValidationException::one('_form', 'Only the upcoming version can be taken back.');
            }
            if ($latest['effective_from'] <= $today) {
                throw ValidationException::one('_form', 'This version has already started, so it cannot be taken back. Start a new version instead.');
            }
            if (AllotmentRuleRepository::draftVersion() !== null) {
                throw ValidationException::one('_form', 'Discard the current draft first.');
            }
            $events = AllotmentRuleRepository::eventsBetween($latest['effective_from'], self::packHorizon());
            if ($events) {
                throw ValidationException::one('_form', 'Tablets may already be prepared with this version for ' . self::eventList($events)
                    . ', so it can no longer be taken back. Once it has started you can start another change.');
            }
            $locked = AllotmentRuleRepository::lockVersion($version);
            $draft = AllotmentRuleRepository::allocateVersion();
            if (AllotmentRuleRepository::renumberToDraft($version, $draft, $today) !== count($locked)) {
                throw new RuntimeException('Allotment version withdraw touched an unexpected number of rows.');
            }
            Audit::record('allotment_withdraw', 'allotment_version', $version, details: [
                'effective_from' => $latest['effective_from'], 'now_draft_version' => $draft,
            ]);
            return $draft;
        }));
    }

    /**
     * The earliest date a new version could start: today for the first version; otherwise
     * tomorrow at every site, moved past any event tablets may already be prepared for.
     */
    public static function earliestStart(): string
    {
        $today = self::today();
        if (!AllotmentRuleRepository::published()) {
            return $today['earliest'];
        }
        $start = self::dayAfter($today['latest']);
        foreach (AllotmentRuleRepository::eventsBetween($start, self::packHorizon()) as $event) {
            $start = max($start, self::dayAfter($event['event_date']));
        }
        return $start;
    }

    /**
     * Run $fn holding the allotment rules lock (timeout 0). Size band deletion uses it too,
     * because it removes the band's draft cells.
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function withLock(callable $fn): mixed
    {
        $pdo = Db::pdo();
        $name = Db::lockName($pdo, self::LOCK);
        $st = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $st->execute([$name]);
        if ((int) $st->fetchColumn() !== 1) {
            throw ValidationException::one('_form', 'Someone else is changing the allotment rules right now. Wait a moment and try again.');
        }
        try {
            return $fn();
        } finally {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);
        }
    }

    public static function modeLabel(string $mode): string
    {
        return $mode === self::MODE_SPLIT ? 'dry and wet separately' : 'dry or wet (one figure)';
    }

    /**
     * Today's date at the active sites: 'latest' is the furthest ahead (a change must start after
     * it everywhere), 'earliest' the furthest behind. Falls back to the organisation's date.
     * @return array{latest: string, earliest: string}
     */
    public static function today(): array
    {
        $dates = [];
        foreach (AllotmentRuleRepository::activeSiteTimeZones() as $zone) {
            $dates[] = Clock::localDate(in_array($zone, DateTimeZone::listIdentifiers(), true) ? $zone : 'America/New_York');
        }
        if (!$dates) {
            $dates[] = Clock::orgToday();
        }
        return ['latest' => max($dates), 'earliest' => min($dates)];
    }

    public static function longDate(string $date): string
    {
        return (new DateTimeImmutable($date))->format('l j F Y');
    }

    /**
     * Validate a draft for publishing from a date, and describe the change.
     * @param list<array> $rows the draft's rules with names (AllotmentRuleRepository::rules)
     * @return array{version: int, effective_from: string, first: bool, previous: ?int, previous_until: ?string, changes: list<array>}
     */
    private static function check(int $version, array $rows, ?string $effectiveFrom): array
    {
        $errors = [];
        $today = self::today();
        $published = AllotmentRuleRepository::published();
        $first = !$published;
        $date = Validator::date(trim((string) ($effectiveFrom ?? $rows[0]['effective_from'])));
        if ($date === null) {
            $errors['effective_from'] = 'Enter the start date, for example 2026-11-01.';
        } elseif ($first && $date < $today['earliest']) {
            $errors['effective_from'] = 'The first version can start today (' . self::longDate($today['earliest']) . ') at the earliest, not in the past.';
        } elseif (!$first && $date <= $today['latest']) {
            $errors['effective_from'] = 'A change can start tomorrow (' . self::longDate(self::dayAfter($today['latest']))
                . ') at the earliest, so food already given out today is not judged by different rules.';
        } elseif (!$first && ($events = AllotmentRuleRepository::eventsBetween($date, self::packHorizon()))) {
            $errors['effective_from'] = 'Tablets may already be prepared with the current rules for ' . self::eventList($events)
                . '. Start the change after the last of these events, from ' . self::longDate(self::dayAfter(end($events)['event_date'])) . '.';
        }
        if (!$first && self::scheduled($today['latest']) !== null) {
            $errors['_form'] = 'A change is already scheduled. Take it back to edit it first.';
        }

        // Every band the rules must cover needs all the figures of its species' mode; a band that
        // is no longer needed may be left wholly empty (it is dropped), but not half filled in.
        $modes = [];
        $cells = [];
        $names = [];
        foreach ($rows as $r) {
            $sid = (int) $r['species_id'];
            $bid = (int) $r['size_band_id'];
            $mode = $r['food_form'] === 'Any' ? self::MODE_ANY : self::MODE_SPLIT;
            if (isset($modes[$sid]) && $modes[$sid] !== $mode) {
                $errors['_form'] = "{$r['species_name']} has both a single figure and separate dry and wet figures. Choose one.";
            }
            $modes[$sid] = $mode;
            $cells[$bid][$r['food_form']] = $r['lbs_per_distribution'];
            $names[$bid] = ['species_id' => $sid, 'species_name' => $r['species_name'], 'band_name' => $r['band_name']];
        }
        $required = [];
        foreach (AllotmentRuleRepository::coverage() as $c) {
            $required[(int) $c['size_band_id']] = true;
            $names[(int) $c['size_band_id']] ??= ['species_id' => (int) $c['species_id'], 'species_name' => $c['species_name'], 'band_name' => $c['band_name']];
        }
        $missing = 0;
        $kept = 0;
        foreach ($names as $bid => $n) {
            $forms = self::FORMS[$modes[$n['species_id']] ?? self::MODE_ANY];
            $filled = array_filter($forms, fn($f) => ($cells[$bid][$f] ?? null) !== null);
            if (!isset($required[$bid]) && !$filled) {
                continue; // not needed and left empty: dropped when publishing
            }
            $kept += count($filled);
            foreach (array_diff($forms, $filled) as $form) {
                $missing++;
                $errors["lbs[{$bid}_$form]"] = "Enter the pounds for {$n['species_name']} {$n['band_name']} (" . self::FORM_LABELS[$form] . ')'
                    . (isset($required[$bid]) ? '.' : ', or leave all its figures blank: it is no longer needed.');
            }
        }
        if ($missing > 0 && !isset($errors['_form'])) {
            $errors['_form'] = "Fill in every size band before publishing: $missing figure" . ($missing === 1 ? ' is' : 's are') . ' still empty.';
        } elseif ($kept === 0 && !isset($errors['_form'])) {
            $errors['_form'] = 'There is nothing to publish: no size band needs figures and none are filled in.';
        }
        if ($errors) {
            throw new ValidationException($errors);
        }

        $previous = AllotmentCalculator::versionOn($published, self::dayBefore((string) $date));
        return ['version' => $version, 'effective_from' => (string) $date, 'first' => $first, 'previous' => $previous,
            'previous_until' => $previous !== null ? self::dayBefore((string) $date) : null,
            'changes' => self::changes($rows, $previous !== null ? AllotmentRuleRepository::rules($previous) : [])];
    }

    /**
     * Every figure that differs from the version being replaced, per pet. When a species switches
     * between one figure and separate dry and wet figures, the band's totals are compared.
     * @param list<array> $new the draft's rules
     * @param list<array> $old the replaced version's rules
     * @return list<array{species_name: string, band_name: string, range: string, food: string, old: ?string, new: ?string, large: bool}>
     */
    private static function changes(array $new, array $old): array
    {
        $bands = [];
        foreach ([$old, $new] as $i => $rules) {
            foreach ($rules as $r) {
                if ($r['lbs_per_distribution'] === null) {
                    continue;
                }
                $bid = (int) $r['size_band_id'];
                $bands[$bid] ??= ['species_name' => $r['species_name'], 'band_name' => $r['band_name'],
                    'range' => SizeBandService::rangeLabel($r['min_weight_lbs'], $r['max_weight_lbs']), 'old' => [], 'new' => []];
                $bands[$bid][$i === 0 ? 'old' : 'new'][$r['food_form']] = $r['lbs_per_distribution'];
            }
        }
        $changes = [];
        foreach ($bands as $b) {
            $row = ['species_name' => $b['species_name'], 'band_name' => $b['band_name'], 'range' => $b['range']];
            $switched = $b['old'] && $b['new'] && isset($b['old']['Any']) !== isset($b['new']['Any']);
            if ($switched) {
                $changes[] = $row + ['food' => isset($b['new']['Any']) ? 'Now one figure for dry or wet' : 'Now dry and wet separately',
                    'old' => self::describe($b['old']), 'new' => self::describe($b['new']),
                    'large' => self::isLargeChange(self::units($b['old']), self::units($b['new']))];
                continue;
            }
            foreach (array_unique(array_merge(array_keys($b['old']), array_keys($b['new']))) as $form) {
                $was = $b['old'][$form] ?? null;
                $now = $b['new'][$form] ?? null;
                if ($was !== $now) {
                    $changes[] = $row + ['food' => ucfirst(self::FORM_LABELS[$form]), 'old' => $was === null ? null : "$was lb",
                        'new' => $now === null ? null : "$now lb",
                        'large' => $was !== null && self::isLargeChange(self::units([$was]), $now === null ? 0 : self::units([$now]))];
                }
            }
        }
        return $changes;
    }

    /** '3.00 lb dry + 1.00 lb wet', or '4.00 lb' for one figure. @param array<string, string> $forms */
    private static function describe(array $forms): string
    {
        if (isset($forms['Any'])) {
            return $forms['Any'] . ' lb';
        }
        return implode(' + ', array_map(fn($f) => $forms[$f] . ' lb ' . self::FORM_LABELS[$f], array_keys(array_intersect_key(['Dry' => 1, 'Wet' => 1], $forms))));
    }

    /** @param array<array-key, string> $lbs pound figures → their total in hundredths */
    private static function units(array $lbs): int
    {
        $total = 0;
        foreach ($lbs as $v) {
            [$whole, $fraction] = array_pad(explode('.', $v, 2), 2, '');
            $total += (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
        }
        return $total;
    }

    /** A published version that has not started anywhere yet, if any. */
    private static function scheduled(string $latestToday): ?int
    {
        $scheduled = null;
        foreach (AllotmentRuleRepository::published() as $p) {
            if ($p['effective_from'] > $latestToday) {
                $scheduled = (int) $p['rule_version'];
            }
        }
        return $scheduled;
    }

    /**
     * Scheduled or Open events that tablets may already have been prepared for, in plain words:
     * their packs hold the rules in force on the event date (plan P2B/P4).
     * @param non-empty-list<array> $events
     */
    private static function eventList(array $events): string
    {
        $names = array_map(fn($e) => $e['site_name'] . ' on ' . self::longDate($e['event_date']), array_slice($events, 0, 3));
        return implode(', ', $names) . (count($events) > 3 ? ' and ' . (count($events) - 3) . ' other event(s)' : '');
    }

    /** The last date an offline pack downloaded today could still be used for. */
    private static function packHorizon(): string
    {
        $days = max(1, (int) ceil(Settings::int('offline_pack_ttl_hours', 72) / 24));
        return (new DateTimeImmutable(self::today()['latest']))->modify("+$days days")->format('Y-m-d');
    }

    /** @param list<array> $rows @return array<int, string> species id → mode */
    private static function modes(array $rows): array
    {
        $modes = [];
        foreach ($rows as $r) {
            if ($r['food_form'] !== 'Any') {
                $modes[(int) $r['species_id']] = self::MODE_SPLIT;
            } else {
                $modes[(int) $r['species_id']] ??= self::MODE_ANY;
            }
        }
        return $modes;
    }

    /** @param list<array> $locked rows from lockVersion */
    private static function fingerprint(array $locked): string
    {
        return sha1(json_encode(array_map(fn($r) => [(int) $r['rule_id'], $r['food_form'], $r['lbs_per_distribution'], $r['effective_from']], $locked),
            JSON_THROW_ON_ERROR));
    }

    /** @return list<array{species: string, band: string, food_form: string, lbs: ?string}> */
    private static function snapshot(int $version): array
    {
        return array_map(fn($r) => ['species' => $r['species_name'], 'band' => $r['band_name'], 'food_form' => $r['food_form'],
            'lbs' => $r['lbs_per_distribution']], AllotmentRuleRepository::rules($version));
    }

    /** More than half as much again or less, or dropping to nothing: worth a second look before publishing. */
    private static function isLargeChange(int $old, int $new): bool
    {
        return $old > 0 && ($new === 0 || abs($new - $old) * 2 > $old);
    }

    private static function dayAfter(string $date): string
    {
        return (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
    }

    private static function dayBefore(string $date): string
    {
        return (new DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d');
    }
}

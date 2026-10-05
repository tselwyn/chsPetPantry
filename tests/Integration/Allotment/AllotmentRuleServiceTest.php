<?php
declare(strict_types=1);

namespace Pfpms\Tests\Integration\Allotment;

use Pfpms\Allotment\AllotmentRuleRepository;
use Pfpms\Allotment\AllotmentRuleService;
use Pfpms\Allotment\StaleDraftException;
use Pfpms\Clock;
use Pfpms\Db;
use Pfpms\Reference\SizeBandService;
use Pfpms\Reference\SpeciesService;
use Pfpms\Tests\TestCase;
use Pfpms\Validation\ValidationException;

/**
 * Allotment rule versions (UC-05 §4.1, UC-06 §4.5, plan P2A): draft → review → publish, one
 * upcoming change at a time, start dates by site-local date, and published rules never changing.
 * The clock is frozen at 2026-10-01 12:00 UTC, which is 08:00 on 1 October in New York.
 */
final class AllotmentRuleServiceTest extends TestCase
{
    private int $admin;
    private int $site;
    private int $dog;
    private int $cat;
    private int $small;
    private int $large;
    private int $standard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeUser(['role' => 'Administrator'])['user_id'];
        $this->site = $this->makeSite('Allotment Site');
        $this->dog = (int) $this->scalar("SELECT species_id FROM species WHERE name = 'Dog'");
        $this->cat = (int) $this->scalar("SELECT species_id FROM species WHERE name = 'Cat'");
        $this->small = $this->band($this->dog, 'Small', '0', '25');
        $this->large = $this->band($this->dog, 'Large', '25', null);
        $this->standard = $this->band($this->cat, 'Standard', '0', null);
    }

    private function band(int $species, string $name, string $min, ?string $max): int
    {
        return SizeBandService::create(['species_id' => (string) $species, 'name' => $name, 'min_weight_lbs' => $min, 'max_weight_lbs' => $max ?? '']);
    }

    /** Fill cells of the draft (keys: band id for 'Any', or "<band>_Dry" etc.) and save it, as the editor would. */
    private function fill(int $version, array $lbsByBand, ?string $start = null, array $mode = []): array
    {
        $lbs = [];
        foreach ($lbsByBand as $key => $value) {
            $lbs[is_int($key) ? "{$key}_Any" : $key] = $value;
        }
        $start ??= (string) $this->scalar('SELECT MIN(effective_from) FROM allotment_rule WHERE rule_version = ?', [$version]);
        return AllotmentRuleService::saveDraft($version, ['effective_from' => $start, 'mode' => $mode, 'lbs' => $lbs], $this->admin,
            AllotmentRuleService::revision($version));
    }

    private function publish(int $version, string $date, ?string $reason = null): string
    {
        return AllotmentRuleService::publish($version, $date, $reason, $this->admin, AllotmentRuleService::review($version, $date)['revision']);
    }

    /** Version 1, in force from 1 October: Small dog 4.50, Large dog 10.00, cat 3.25. */
    private function firstVersion(): int
    {
        $v = AllotmentRuleService::startDraft($this->admin);
        $this->fill($v, [$this->small => '4.5', $this->large => '10', $this->standard => '3.25']);
        $this->publish($v, '2026-10-01');
        return $v;
    }

    private function errorsOf(callable $fn): array
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            return $e->errors;
        }
        $this->fail('expected a ValidationException');
    }

    private function audits(string $action, int $version): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = ? AND entity_type = 'allotment_version' AND entity_id = ?", [$action, $version]);
    }

    private function event(string $date, string $status = 'Scheduled'): void
    {
        Db::pdo()->prepare("INSERT INTO distribution_event (site_id, event_date, starts_at, ends_at, status) VALUES (?, ?, '10:00', '12:00', ?)")
            ->execute([$this->site, $date, $status]);
    }

    private function pet(int $species, int $band, string $status = 'Active'): void
    {
        static $n = 0;
        $n++;
        Db::pdo()->prepare('INSERT INTO participant (participant_code, legal_first_name, legal_last_name, postal_code, household_size,
                                                     home_site_id, registration_site_id, registered_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(["AR$n", 'Test', 'Owner', '29401', 1, $this->site, $this->site, $this->admin]);
        Db::pdo()->prepare('INSERT INTO pet (participant_id, name, species_id, size_band_id, status, created_by) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([(int) Db::pdo()->lastInsertId(), "Pet$n", $species, $band, $status, $this->admin]);
    }

    private function forms(int $version, int $band): string
    {
        return (string) $this->scalar('SELECT GROUP_CONCAT(CONCAT(food_form, "=", COALESCE(lbs_per_distribution, "-")) ORDER BY food_form)
                                         FROM allotment_rule WHERE rule_version = ? AND size_band_id = ?', [$version, $band]);
    }

    // The main path -----------------------------------------------------------------------

    public function testTheFirstVersionFromDraftToPublished(): void
    {
        $v = AllotmentRuleService::startDraft($this->admin);
        $this->assertSame(1, $v, 'real versions start at 1; 0 is kept for imported legacy distributions');
        $this->assertSame(3, (int) $this->scalar('SELECT COUNT(*) FROM allotment_rule WHERE rule_version = 1 AND lbs_per_distribution IS NULL AND published_at IS NULL'),
            'one empty cell per size band');
        $this->assertSame('2026-10-01', AllotmentRuleService::earliestStart(), 'the first version can start today');

        $errors = $this->errorsOf(fn() => AllotmentRuleService::publish($v, '2026-10-01', null, $this->admin, null));
        $this->assertArrayHasKey("lbs[{$this->small}_Any]", $errors);
        $this->assertArrayHasKey('_form', $errors, 'a summary of how many figures are missing');

        $this->fill($v, [$this->small => '4.5', $this->large => '10', $this->standard => '3.25']);
        $this->assertArrayHasKey('effective_from', $this->errorsOf(fn() => $this->publish($v, '2026-09-30')), 'never backdated');
        $this->assertSame('2026-10-01', $this->publish($v, '2026-10-01', 'Opening figures'));

        $this->assertSame(1, AllotmentRuleRepository::versionInForce('2026-10-01'));
        $this->assertNull(AllotmentRuleRepository::versionInForce('2026-09-30'));
        $this->assertNull(AllotmentRuleRepository::draftVersion());
        foreach (['allotment_draft_create', 'allotment_draft_update', 'allotment_publish'] as $action) {
            $this->assertSame(1, $this->audits($action, $v), $action);
        }
        $this->assertSame('Opening figures', $this->scalar("SELECT reason FROM audit_log WHERE action = 'allotment_publish'"));
        $snapshot = json_decode((string) $this->scalar("SELECT snapshot FROM audit_log WHERE action = 'allotment_publish'"), true);
        $this->assertContainsEquals(['species' => 'Dog', 'band' => 'Small', 'food_form' => 'Any', 'lbs' => '4.50'], $snapshot['rules'], // MySQL's JSON type reorders keys
            'the publish audit records exactly which figures the number stands for');
        $this->assertSame('4.50', AllotmentRuleService::tryIt($v, [$this->small => 1])['total']);
        $this->assertSame('21.00', AllotmentRuleService::tryIt($v, [$this->small => 1, $this->large => 1, $this->standard => 2])['total']);
    }

    public function testAChangeStartsTomorrowAtTheEarliestAndNeverAltersTheOldVersion(): void
    {
        $v1 = $this->firstVersion();
        $before = AllotmentRuleRepository::rules($v1);

        $v2 = AllotmentRuleService::startDraft($this->admin);
        $this->assertSame(2, $v2);
        $this->assertSame('2026-10-02', $this->scalar('SELECT MIN(effective_from) FROM allotment_rule WHERE rule_version = 2'), 'proposed start: the earliest allowed');
        $this->assertSame('4.50', $this->scalar('SELECT lbs_per_distribution FROM allotment_rule WHERE rule_version = 2 AND size_band_id = ?', [$this->small]),
            'a new draft copies the version in use');
        $this->assertSame(['based_on' => 1], AllotmentRuleService::draftOrigin($v2));
        $this->fill($v2, [$this->large => '12']);
        $this->assertArrayHasKey('effective_from', $this->errorsOf(fn() => $this->publish($v2, '2026-10-01')),
            'not today: food already given out today is judged by today\'s rules');

        $review = AllotmentRuleService::review($v2, '2026-10-02');
        $this->assertSame([1, '2026-10-01'], [$review['previous'], $review['previous_until']]);
        $this->assertCount(1, $review['changes']);
        $this->assertSame(['Dry or wet', '10.00 lb', '12.00 lb', false],
            [$review['changes'][0]['food'], $review['changes'][0]['old'], $review['changes'][0]['new'], $review['changes'][0]['large']]);

        $this->publish($v2, '2026-10-02');
        $this->assertSame(1, AllotmentRuleRepository::versionInForce('2026-10-01'));
        $this->assertSame(2, AllotmentRuleRepository::versionInForce('2026-10-02'));
        $this->assertSame($before, AllotmentRuleRepository::rules($v1), 'the published version is exactly as it was');
        $this->assertNull($this->scalar('SELECT MAX(effective_to) FROM allotment_rule'), 'effective_to is not used');

        Clock::freeze('2026-10-03 12:00:00');
        $overview = AllotmentRuleService::overview();
        $this->assertSame([2 => 'in_force', 1 => 'replaced'], array_column($overview['versions'], 'status', 'rule_version'));
        $this->assertSame('2026-10-01', array_column($overview['versions'], 'ends_on', 'rule_version')[1]);
        $this->assertSame(2, $overview['in_force']);
    }

    public function testOneUpcomingChangeAtATimeAndNumbersAreNeverReused(): void
    {
        $this->firstVersion();
        $v2 = AllotmentRuleService::startDraft($this->admin);
        $this->assertArrayHasKey('_form', $this->errorsOf(fn() => AllotmentRuleService::startDraft($this->admin)), 'one draft at a time');
        $this->fill($v2, [$this->large => '12']);
        $this->publish($v2, '2026-10-05');
        $this->assertArrayHasKey('_form', $this->errorsOf(fn() => AllotmentRuleService::startDraft($this->admin)), 'a change is already scheduled');
        $this->assertSame('scheduled', array_column(AllotmentRuleService::overview()['versions'], 'status', 'rule_version')[$v2]);

        $v3 = AllotmentRuleService::withdraw($v2, $this->admin);
        $this->assertSame(3, $v3);
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM allotment_rule WHERE rule_version = 2'));
        $this->assertSame('Any=12.00', $this->forms($v3, $this->large), 'the figures come back as the draft');
        $this->assertSame(['taken_back_from' => 2], AllotmentRuleService::draftOrigin($v3));
        $this->assertSame(1, $this->audits('allotment_withdraw', $v2));

        AllotmentRuleService::discardDraft($v3, $this->admin);
        $this->assertSame(4, AllotmentRuleService::startDraft($this->admin), 'neither the taken-back 2 nor the discarded 3 is used again');

        AllotmentRuleService::discardDraft(4, $this->admin);
        $v5 = AllotmentRuleService::startDraft($this->admin);
        $this->fill($v5, [$this->large => '12']);
        $this->publish($v5, '2026-10-05');
        Clock::freeze('2026-10-05 12:00:00');
        $this->assertArrayHasKey('_form', $this->errorsOf(fn() => AllotmentRuleService::withdraw($v5, $this->admin)), 'it has started');
        $this->assertArrayHasKey('_form', $this->errorsOf(fn() => AllotmentRuleService::withdraw(1, $this->admin)), 'only the upcoming version');
    }

    // Dates -------------------------------------------------------------------------------

    public function testDatesAreEachSitesLocalDateNotUtc(): void
    {
        $this->firstVersion();
        $v2 = AllotmentRuleService::startDraft($this->admin);
        $this->fill($v2, [$this->large => '12']);
        $this->publish($v2, '2026-10-02');

        Clock::freeze('2026-10-02 02:00:00'); // 10 pm on 1 October in New York; already 2 October in UTC
        $this->assertSame(1, AllotmentRuleRepository::versionInForce(Clock::localDate('America/New_York')), 'still 1 October locally');
        $this->assertSame(2, AllotmentRuleRepository::versionInForce('2026-10-02'));
        $this->assertSame('scheduled', array_column(AllotmentRuleService::overview()['versions'], 'status', 'rule_version')[$v2],
            'not started at the site yet');

        $west = $this->makeSite('West Site');
        Db::pdo()->prepare("UPDATE site SET time_zone = 'America/Los_Angeles' WHERE site_id = ?")->execute([$west]);
        Clock::freeze('2026-10-02 04:30:00'); // 00:30 on 2 October in New York, 21:30 on 1 October in Los Angeles
        $this->assertSame(['latest' => '2026-10-02', 'earliest' => '2026-10-01'], AllotmentRuleService::today());
        $this->assertSame('2026-10-03', AllotmentRuleService::earliestStart(), 'after today at every site');
    }

    public function testTheFirstVersionMayStartOnTheEarliestSitesToday(): void
    {
        $west = $this->makeSite('West Site');
        Db::pdo()->prepare("UPDATE site SET time_zone = 'America/Los_Angeles' WHERE site_id = ?")->execute([$west]);
        $closed = $this->makeSite('Closed Site');
        Db::pdo()->prepare("UPDATE site SET time_zone = 'Pacific/Kiritimati', is_active = 0 WHERE site_id = ?")->execute([$closed]);
        Clock::freeze('2026-10-02 11:00:00'); // 07:00 on 2 October in New York; Kiritimati (UTC+14) is already on 3 October
        $this->assertSame('2026-10-02', AllotmentRuleService::today()['latest'], 'an inactive site does not count');
        Clock::freeze('2026-10-02 04:30:00'); // 00:30 on 2 October in New York, 21:30 on 1 October in Los Angeles
        $this->assertSame(['latest' => '2026-10-02', 'earliest' => '2026-10-01'], AllotmentRuleService::today());
        $v = AllotmentRuleService::startDraft($this->admin);
        $this->assertSame('2026-10-01', AllotmentRuleService::earliestStart());
        $this->fill($v, [$this->small => '4.5', $this->large => '10', $this->standard => '3']);
        $this->assertSame('2026-10-01', $this->publish($v, '2026-10-01'), 'in force at once everywhere, including Los Angeles');
    }

    public function testTabletsPreparedForAnEventKeepTheirRules(): void
    {
        $this->firstVersion();
        $v2 = AllotmentRuleService::startDraft($this->admin);
        $this->fill($v2, [$this->large => '12']);
        $this->event('2026-10-02', 'Closed'); // finished: no tablet needs its rules any more
        $this->assertSame('2026-10-02', AllotmentRuleService::earliestStart(), 'a closed event does not count');
        $this->event('2026-10-03', 'Open');
        $this->event('2026-10-20'); // beyond the 72-hour pack lifetime: no tablet holds it yet
        $this->assertSame('2026-10-04', AllotmentRuleService::earliestStart());
        $errors = $this->errorsOf(fn() => $this->publish($v2, '2026-10-03'));
        $this->assertStringContainsString('Allotment Site', $errors['effective_from']);

        $this->setSetting('offline_pack_ttl_hours', '240'); // packs can be 10 days old
        $this->event('2026-10-09');
        $this->assertSame('2026-10-10', AllotmentRuleService::earliestStart(), 'the horizon follows the setting');
        $this->setSetting('offline_pack_ttl_hours', '72');
        $this->assertSame('2026-10-04', AllotmentRuleService::earliestStart(), '72 hours: 1 to 4 October; the 9th is beyond it');

        $this->publish($v2, '2026-10-04');
        $this->event('2026-10-04'); // an event on the new start date, which tablets may be prepared for
        $this->assertArrayHasKey('_form', $this->errorsOf(fn() => AllotmentRuleService::withdraw($v2, $this->admin)),
            'cannot be taken back while tablets may already hold it');
    }

    // Grid, modes and coverage ------------------------------------------------------------

    public function testDryAndWetSeparatelyBothWaysAndCopiedIntoTheNextDraft(): void
    {
        $v = AllotmentRuleService::startDraft($this->admin);
        $this->fill($v, [$this->small => '4.5', $this->large => '10', $this->standard => '3'], null, [$this->cat => 'split']);
        $this->assertSame('Dry=-,Wet=-', $this->forms($v, $this->standard), 'switching the cat to dry and wet replaces its single figure');

        $errors = $this->errorsOf(fn() => $this->publish($v, '2026-10-01'));
        $this->assertArrayHasKey("lbs[{$this->standard}_Dry]", $errors);
        $this->assertArrayHasKey("lbs[{$this->standard}_Wet]", $errors);
        $this->fill($v, ["{$this->standard}_Dry" => '2', "{$this->standard}_Wet" => '0'], null, [$this->cat => 'split']);
        $this->publish($v, '2026-10-01');

        $result = AllotmentRuleService::tryIt($v, [$this->standard => 2, $this->small => 1]);
        $this->assertSame('8.50', $result['total']);
        $this->assertContains(['species_id' => $this->cat, 'food_form' => 'Wet', 'lbs' => '0.00'], $result['buckets']);

        $v2 = AllotmentRuleService::startDraft($this->admin);
        $this->assertSame('Dry=2.00,Wet=0.00', $this->forms($v2, $this->standard), 'the next draft keeps dry and wet');
        $this->fill($v2, [$this->standard => '2.5'], null, [$this->cat => 'any']);
        $this->assertSame('Any=2.50', $this->forms($v2, $this->standard), 'switching back leaves one figure only');
        $this->publish($v2, '2026-10-02');
    }

    public function testTheReviewComparesTotalsWhenASpeciesSwitchesMode(): void
    {
        $v1 = AllotmentRuleService::startDraft($this->admin);
        $this->fill($v1, ["{$this->small}_Dry" => '3', "{$this->small}_Wet" => '1', "{$this->large}_Dry" => '8', "{$this->large}_Wet" => '2', $this->standard => '3'],
            null, [$this->dog => 'split']);
        $this->publish($v1, '2026-10-01');
        $v2 = AllotmentRuleService::startDraft($this->admin);
        $this->fill($v2, [$this->small => '40', $this->large => '10'], null, [$this->dog => 'any']);

        $changes = array_column(AllotmentRuleService::review($v2, '2026-10-02')['changes'], null, 'band_name');
        $this->assertSame(['Now one figure for dry or wet', '3.00 lb dry + 1.00 lb wet', '40.00 lb', true],
            [$changes['Small']['food'], $changes['Small']['old'], $changes['Small']['new'], $changes['Small']['large']]);
        $this->assertFalse($changes['Large']['large'], '8 + 2 = 10 lb before and after');
    }

    public function testABigChangeIsFlaggedForASecondLook(): void
    {
        $this->firstVersion();
        $v2 = AllotmentRuleService::startDraft($this->admin);
        $this->fill($v2, [$this->small => '45', $this->standard => '0']);
        $flags = array_column(AllotmentRuleService::review($v2, '2026-10-02')['changes'], 'large', 'band_name');
        $this->assertSame(['Standard' => true, 'Small' => true], $flags, '3.25 → 0 and 4.5 → 45 both need a second look');
        $this->fill($v2, [$this->small => '6.75', $this->standard => '3.25']); // exactly half as much again: not flagged
        $this->assertSame(['Small' => false], array_column(AllotmentRuleService::review($v2, '2026-10-02')['changes'], 'large', 'band_name'));
    }

    public function testABandAddedLaterIsAGapUntilANewVersionCoversIt(): void
    {
        $v1 = $this->firstVersion();
        Db::pdo()->prepare('UPDATE size_band SET max_weight_lbs = 90 WHERE size_band_id = ?')->execute([$this->large]);
        $giant = $this->band($this->dog, 'Giant', '90', null);
        $this->assertSame(['Dog – Giant'], AllotmentRuleService::coverageGaps($v1));

        $v2 = AllotmentRuleService::startDraft($this->admin);
        $this->assertSame('Any=-', $this->forms($v2, $giant), 'the new draft has an empty cell for the new band');
        $this->assertArrayHasKey("lbs[{$giant}_Any]", $this->errorsOf(fn() => $this->publish($v2, '2026-10-02')));
    }

    public function testABandMissingOneOfDryAndWetIsAGap(): void
    {
        $bird = SpeciesService::create(['name' => 'Bird']);
        $cage = $this->band($bird, 'Any size', '0', null);
        Db::pdo()->prepare("INSERT INTO allotment_rule (rule_version, species_id, size_band_id, food_form, lbs_per_distribution, effective_from, created_by, published_at, published_by)
                            VALUES (9, ?, ?, 'Dry', 1.00, '2026-01-01', ?, '2026-01-01 00:00:00', ?)")->execute([$bird, $cage, $this->admin, $this->admin]);
        $this->assertContains('Bird – Any size', AllotmentRuleService::coverageGaps(9), 'dry without wet does not cover the band');
    }

    public function testABandOfAnInactiveSpeciesIsNeededOnlyWhileItHasActivePets(): void
    {
        $rabbit = SpeciesService::create(['name' => 'Rabbit']);
        $hutch = $this->band($rabbit, 'Any size', '0', null);
        SpeciesService::setActive($rabbit, false);
        $this->pet($rabbit, $hutch, 'Inactive');
        $v = AllotmentRuleService::startDraft($this->admin);
        $this->assertSame('', $this->forms($v, $hutch), 'not needed: its only pet is not Active');

        $this->pet($rabbit, $hutch);
        $this->fill($v, [$this->small => '4.5', $this->large => '10', $this->standard => '3']);
        $this->assertArrayHasKey("lbs[{$hutch}_Any]", $this->errorsOf(fn() => $this->publish($v, '2026-10-01')),
            'an Active pet still needs its figure after its species stops being offered');
        $this->fill($v, [$hutch => '1']);
        $this->publish($v, '2026-10-01');
        $this->assertSame('1.00', AllotmentRuleService::tryIt($v, [$hutch => 1])['total']);
    }

    public function testFiguresABandNoLongerNeedsAreDroppedButNotHalfFilled(): void
    {
        $rabbit = SpeciesService::create(['name' => 'Rabbit']);
        $hutch = $this->band($rabbit, 'Any size', '0', null);
        $v = AllotmentRuleService::startDraft($this->admin); // Rabbit offered: its band is in the draft
        $this->fill($v, [$this->small => '4.5', $this->large => '10', $this->standard => '3'], null, [$rabbit => 'split']);
        SpeciesService::setActive($rabbit, false); // no longer needed

        $this->fill($v, ["{$hutch}_Dry" => '2'], null, [$rabbit => 'split']);
        $this->assertArrayHasKey("lbs[{$hutch}_Wet]", $this->errorsOf(fn() => $this->publish($v, '2026-10-01')), 'dry without wet is refused');
        $this->fill($v, ["{$hutch}_Dry" => ''], null, [$rabbit => 'split']);
        $this->publish($v, '2026-10-01');
        $this->assertSame('', $this->forms($v, $hutch), 'the wholly empty band is dropped');
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM allotment_rule WHERE lbs_per_distribution IS NULL AND published_at IS NOT NULL'),
            'a published version never has empty figures');
    }

    public function testThereMustBeSomethingToPublish(): void
    {
        $v = AllotmentRuleService::startDraft($this->admin);
        SpeciesService::setActive($this->dog, false);
        SpeciesService::setActive($this->cat, false);
        $this->assertStringContainsString('nothing to publish', $this->errorsOf(fn() => $this->publish($v, '2026-10-01'))['_form']);
    }

    // Validation, stale forms, locking, audit ---------------------------------------------

    public function testDraftValuesAreValidatedAndPublishedRulesCannotBeEdited(): void
    {
        $v = AllotmentRuleService::startDraft($this->admin);
        $errors = $this->errorsOf(fn() => $this->fill($v, [$this->small => '4.567', $this->large => '-1', $this->standard => '201']));
        $this->assertSame(["lbs[{$this->small}_Any]", "lbs[{$this->large}_Any]", "lbs[{$this->standard}_Any]"], array_keys($errors));
        $this->assertArrayHasKey('effective_from', $this->errorsOf(fn() => $this->fill($v, [], 'next week')));

        $this->fill($v, [$this->small => '4.5', $this->large => '10', $this->standard => '0']);
        $this->assertSame([], $this->fill($v, [$this->small => '4.50']), 'the same figure is not a change');
        $this->assertSame(1, $this->audits('allotment_draft_update', $v), 'and writes no audit row');
        $this->publish($v, '2026-10-01');
        $before = AllotmentRuleRepository::rules($v);
        $this->assertArrayHasKey('_form', $this->errorsOf(fn() => AllotmentRuleService::saveDraft($v,
            ['effective_from' => '2026-10-01', 'lbs' => ["{$this->small}_Any" => '9']], $this->admin, null)));
        $this->assertArrayHasKey('_form', $this->errorsOf(fn() => AllotmentRuleService::discardDraft($v, $this->admin)));
        $this->assertArrayHasKey('_form', $this->errorsOf(fn() => AllotmentRuleService::publish($v, '2026-10-05', null, $this->admin, null)));
        $this->assertSame($before, AllotmentRuleRepository::rules($v));
    }

    public function testTheReviewUsesTheSavedStartDate(): void
    {
        $this->firstVersion();
        $v2 = AllotmentRuleService::startDraft($this->admin);
        $changes = $this->fill($v2, [$this->large => '12'], '2026-10-09');
        $this->assertSame(['2026-10-02', '2026-10-09'], $changes['Start date']);
        $this->assertSame('2026-10-09', AllotmentRuleService::review($v2, null)['effective_from'], 'as the page does: save, then review');
    }

    public function testAStaleFormIsRefusedInsteadOfUndoingSomeoneElsesWork(): void
    {
        $v = AllotmentRuleService::startDraft($this->admin);
        $opened = AllotmentRuleService::revision($v); // Administrator A opens the editor
        $this->fill($v, [$this->small => '8']);       // B saves first
        $this->expectException(StaleDraftException::class);
        AllotmentRuleService::saveDraft($v, ['effective_from' => '2026-10-01', 'lbs' => ["{$this->large}_Any" => '12', "{$this->small}_Any" => '']],
            $this->admin, $opened);
    }

    public function testPublishingFiguresNobodyReviewedIsRefused(): void
    {
        $v = AllotmentRuleService::startDraft($this->admin);
        $this->fill($v, [$this->small => '4.5', $this->large => '10', $this->standard => '3']);
        $reviewed = AllotmentRuleService::review($v, '2026-10-01')['revision'];
        $this->fill($v, [$this->small => '45']); // changed after the review
        try {
            AllotmentRuleService::publish($v, '2026-10-01', null, $this->admin, $reviewed);
            $this->fail('expected a StaleDraftException');
        } catch (StaleDraftException) {
            $this->assertNull(AllotmentRuleRepository::versionInForce('2026-10-01'));
        }
        $this->assertSame('2026-10-01', $this->publish($v, '2026-10-01'), 'after a fresh review it publishes');
    }

    public function testAPublishNoteIsKeptOrRefusedNeverDropped(): void
    {
        $v = AllotmentRuleService::startDraft($this->admin);
        $this->fill($v, [$this->small => '4.5', $this->large => '10', $this->standard => '3']);
        $this->assertArrayHasKey('reason', $this->errorsOf(fn() => $this->publish($v, '2026-10-01', str_repeat('x', 256))));
        $this->publish($v, '2026-10-01', str_repeat('x', 255));
        $this->assertSame(255, strlen((string) $this->scalar("SELECT reason FROM audit_log WHERE action = 'allotment_publish'")));
    }

    public function testDiscardingADraftIsAuditedWithItsFigures(): void
    {
        $v = AllotmentRuleService::startDraft($this->admin);
        $this->fill($v, [$this->small => '4.5']);
        AllotmentRuleService::discardDraft($v, $this->admin);
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM allotment_rule WHERE rule_version = ?', [$v]));
        $this->assertStringContainsString('4.50', (string) $this->scalar("SELECT snapshot FROM audit_log WHERE action = 'allotment_draft_discard'"));
    }

    public function testBandNamesNeverHideThePoundsInTheAuditLog(): void
    {
        Db::pdo()->prepare("UPDATE size_band SET name = 'Pinscher' WHERE size_band_id = ?")->execute([$this->small]); // contains 'pin'
        $v = AllotmentRuleService::startDraft($this->admin);
        $this->fill($v, [$this->small => '6']);
        $this->assertSame('6.00', $this->scalar("SELECT f.new_value FROM audit_field_change f JOIN audit_log a USING (audit_id)
                                                 WHERE a.action = 'allotment_draft_update' AND f.field_name = 'Dog / Pinscher / Any'"));
    }

    public function testVersionZeroIsNeverInForce(): void
    {
        Db::pdo()->prepare("INSERT INTO allotment_rule (rule_version, species_id, size_band_id, food_form, lbs_per_distribution, effective_from, created_by, published_at, published_by)
                            VALUES (0, ?, ?, 'Any', 1.00, '2020-01-01', ?, '2020-01-01 00:00:00', ?)")->execute([$this->dog, $this->small, $this->admin, $this->admin]);
        $this->assertNull(AllotmentRuleRepository::versionInForce('2026-10-01'), 'version 0 is only for imported legacy distributions');
    }

    public function testNumbersSkipPastRulesInsertedDirectly(): void
    {
        Db::pdo()->prepare("INSERT INTO allotment_rule (rule_version, species_id, size_band_id, food_form, lbs_per_distribution, effective_from, created_by, published_at, published_by)
                            VALUES (7, ?, ?, 'Any', 1.00, '2026-01-01', ?, '2026-01-01 00:00:00', ?)")->execute([$this->dog, $this->small, $this->admin, $this->admin]);
        $this->assertSame(8, AllotmentRuleService::startDraft($this->admin), 'a seed or import that bypassed the counter cannot cause a clash');
    }

    public function testEveryWriteWaitsForAnotherAdministratorHoldingTheLock(): void
    {
        $v1 = $this->firstVersion();
        $draft = AllotmentRuleService::startDraft($this->admin);
        $this->fill($draft, [$this->large => '12']);
        $ferret = SpeciesService::create(['name' => 'Ferret']);
        $unused = $this->band($ferret, 'Any size', '0', null);

        $other = Db::durable(); // a second connection standing in for another Administrator's request
        $name = Db::lockName(Db::pdo(), 'allotment_rules');
        $st = $other->prepare('SELECT GET_LOCK(?, 0)');
        $st->execute([$name]);
        $this->assertSame(1, (int) $st->fetchColumn());
        try {
            // Each of these would otherwise succeed or fail for a different reason.
            $calls = [
                'start' => fn() => AllotmentRuleService::startDraft($this->admin),
                'save' => fn() => AllotmentRuleService::saveDraft($draft, ['effective_from' => '2026-10-02', 'lbs' => []], $this->admin, null),
                'publish' => fn() => AllotmentRuleService::publish($draft, '2026-10-02', null, $this->admin, null),
                'discard' => fn() => AllotmentRuleService::discardDraft($draft, $this->admin),
                'withdraw' => fn() => AllotmentRuleService::withdraw($v1, $this->admin),
                'size band delete' => fn() => SizeBandService::delete($unused),
            ];
            foreach ($calls as $what => $call) {
                $this->assertStringContainsString('Someone else', $this->errorsOf($call)['_form'] ?? '', $what);
            }
        } finally {
            $other->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);
        }
        $this->assertSame('Any=12.00', $this->forms($draft, $this->large), 'nothing changed while the lock was held');
        $this->assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM size_band WHERE size_band_id = ?', [$unused]));
    }
}

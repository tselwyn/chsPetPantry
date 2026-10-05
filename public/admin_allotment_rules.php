<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Pfpms\Allotment\AllotmentRuleService;
use Pfpms\Allotment\MissingAllotmentRule;
use Pfpms\Http\Page;
use Pfpms\Http\Request;
use Pfpms\View\View;

// Allotment rules (UC-05 §4.1, plan P2A): the versions, the pounds per pet in one version, and a
// "try it" form that works out a household's allotment. Read-only; changes are made on
// admin_allotment_rule_edit.php (allotment.manage).
$ctx = Page::start(['capability' => 'allotment.view']);
$overview = AllotmentRuleService::overview();
$canManage = $ctx->can('allotment.manage');
// People who may only view the rules never see the draft, not even as an empty row.
$visible = array_values(array_filter($overview['versions'], fn(array $v) => $canManage || $v['status'] !== 'draft'));

// The version to show: the one asked for (drafts only for people who may edit them), else the
// version in force, else the upcoming one.
$byNumber = array_column($visible, null, 'rule_version');
$asked = Request::int('version', fromQuery: true);
$shown = $asked !== null && isset($byNumber[$asked]) ? $asked
    : ($overview['in_force'] ?? $overview['scheduled'] ?? ($canManage ? $overview['draft'] : null));

$counts = [];
$countErrors = [];
$rawCounts = Request::array('pets', fromQuery: true);
foreach ($rawCounts as $bandId => $raw) {
    if (!preg_match('/^\d{1,10}$/', (string) $bandId) || $raw === '') {
        continue;
    }
    if (preg_match('/^\d{1,2}$/', $raw)) {
        if ((int) $raw > 0) {
            $counts[(int) $bandId] = (int) $raw;
        }
    } else {
        $countErrors["pets[$bandId]"] = 'Enter a whole number of pets, from 0 to 99.';
    }
}
$tried = null;
$tryError = null;
if ($shown !== null && $counts && !$countErrors) {
    try {
        $tried = AllotmentRuleService::tryIt($shown, $counts);
    } catch (MissingAllotmentRule $e) {
        $tryError = 'This version has no figure yet for one of those size bands, so the allotment cannot be worked out.';
    } catch (OverflowException $e) {
        $tryError = $e->getMessage();
    }
}

View::render('pages/admin/allotment_rules', [
    'title' => 'Allotment rules', 'ctx' => $ctx, 'overview' => $overview, 'visible' => $visible, 'canManage' => $canManage,
    'shown' => $shown, 'shownVersion' => $shown !== null ? $byNumber[$shown] : null,
    'table' => $shown !== null ? AllotmentRuleService::table($shown) : [],
    'gaps' => array_filter([
        'in_force' => $overview['in_force'] !== null ? AllotmentRuleService::coverageGaps($overview['in_force']) : [],
        'scheduled' => $overview['scheduled'] !== null ? AllotmentRuleService::coverageGaps($overview['scheduled']) : [],
    ]),
    'counts' => $counts, 'rawCounts' => $rawCounts, 'countErrors' => $countErrors, 'tried' => $tried, 'tryError' => $tryError, 'earliestStart' => AllotmentRuleService::earliestStart(),
]);

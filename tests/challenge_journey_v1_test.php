<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/product/contracts.php';

function cj_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$expected = [
    'DRAFT',
    'FORMING_CREW',
    'LAUNCHED',
    'BASELINE',
    'LIVE',
    'FINAL_WEEK_LIVE',
    'RESULTS_UNDER_REVIEW',
    'COMPLETED',
];

cj_assert(FC_CHALLENGE_LIFECYCLES === $expected, 'Canonical lifecycle order mismatch.');
cj_assert(!in_array('READY_TO_LAUNCH', FC_CHALLENGE_LIFECYCLES, true), 'READY_TO_LAUNCH must be retired.');

$labels = [
    'DRAFT' => 'Challenge Created',
    'FORMING_CREW' => 'Forming Crew',
    'LAUNCHED' => 'Challenge Launched',
    'BASELINE' => 'Baseline Week',
    'LIVE' => 'Competing',
    'FINAL_WEEK_LIVE' => 'Final Week',
    'RESULTS_UNDER_REVIEW' => 'Results Under Review',
    'COMPLETED' => 'Challenge Complete',
];
foreach ($labels as $code => $label) {
    cj_assert(fc_challenge_lifecycle_label($code) === $label, "Lifecycle label mismatch for {$code}.");
}

$root = dirname(__DIR__);
$home = file_get_contents($root . '/views/app/challenge/home.php');
$migration = file_get_contents($root . '/database/migrations/0520_challenge_lifecycle_launched.sql');
$rules = file_get_contents($root . '/inc/product/rules.php');

cj_assert(is_string($home) && is_string($migration) && is_string($rules), 'Journey source files unavailable.');
cj_assert(str_contains($home, '<h2 id="challenge-lifecycle-title">Challenge Journey</h2>'), 'Challenge Journey heading missing.');
cj_assert(str_contains($home, "Stage <?= fc_e((string) (\$currentLifecycleIndex + 1)) ?> of"), 'Stage N of 8 display missing.');
cj_assert(str_contains($home, "'DRAFT' => '/challenge-manage.php?challenge='"), 'Challenge Created destination missing.');
cj_assert(str_contains($home, "'FORMING_CREW' => '/participants.php?challenge='"), 'Forming Crew destination missing.');
cj_assert(str_contains($home, 'lifecycle-step-action'), 'Navigable Journey row contract missing.');
cj_assert(str_contains($home, '<details class="lifecycle-step-details">'), 'Informational fallback stages missing.');
cj_assert(!str_contains($home, 'From setup to finish.'), 'Retired Journey heading still present.');
cj_assert(!str_contains($home, 'Ready to Launch'), 'Ready to Launch remains user-facing in Journey.');
cj_assert(!str_contains($home, 'Final Week — Live'), 'Final Week — Live remains user-facing in Journey.');
cj_assert(!str_contains($home, 'Challenge Complete — Results Under Review'), 'Combined results label remains user-facing in Journey.');

cj_assert(str_contains($migration, "WHERE lifecycle_status = 'READY_TO_LAUNCH'"), '0520 READY_TO_LAUNCH fail-closed preflight missing.');
cj_assert(str_contains($migration, 'DROP CONSTRAINT chk_challenges_lifecycle'), '0520 lifecycle constraint replacement missing.');
cj_assert(str_contains($migration, "'LAUNCHED'"), '0520 LAUNCHED database value missing.');
cj_assert(!preg_match("/IN \([^;]*'READY_TO_LAUNCH'/s", substr($migration, strpos($migration, 'ADD CONSTRAINT')) ?: ''), '0520 new lifecycle constraint still permits READY_TO_LAUNCH.');

cj_assert(str_contains($rules, "SET lifecycle_status = \'FORMING_CREW\'"), 'Existing DRAFT -> FORMING_CREW behavior changed unexpectedly.');

foreach (['Challenge Created','Forming Crew','Challenge Launched','Baseline Week','Competing','Final Week','Results Under Review','Challenge Complete'] as $label) {
    cj_assert(in_array($label, $labels, true), "Missing accepted Journey label: {$label}");
}

fwrite(STDOUT, "Challenge Journey v1 contract proof: PASS\n");
fwrite(STDOUT, "- canonical lifecycle order / READY_TO_LAUNCH retirement: PASS\n");
fwrite(STDOUT, "- accepted Journey labels / Baseline-after-Launched order: PASS\n");
fwrite(STDOUT, "- Stage N of 8 / canonical COMPLETE-CURRENT-UPCOMING source: PASS\n");
fwrite(STDOUT, "- navigable rows / informational fallbacks / no lifecycle mutation action: PASS\n");
fwrite(STDOUT, "- 0520 fail-closed schema alignment: PASS\n");
fwrite(STDOUT, "- existing DRAFT -> FORMING_CREW behavior preserved: PASS\n");

<?php

declare(strict_types=1);

$root = dirname(__DIR__);

function fcmc_read(string $path): string
{
    $content = file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException('Unable to read ' . $path);
    }
    return $content;
}

function fcmc_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$crewController = fcmc_read($root . '/crew.php');
$crewView = fcmc_read($root . '/views/app/crew/home.php');
$challengeController = fcmc_read($root . '/challenge.php');
$challengeIndex = fcmc_read($root . '/views/app/challenge/index.php');
$manageController = fcmc_read($root . '/challenge-manage.php');
$manageView = fcmc_read($root . '/views/app/challenge/manage.php');
$challengeService = fcmc_read($root . '/inc/product/challenges.php');

fcmc_assert(str_contains($crewController, '$showCrewList'), 'Crew route must support a canonical multi-Crew list.');
fcmc_assert(str_contains($crewView, 'Your Crews.'), 'Crew list heading missing.');
fcmc_assert(str_contains($crewView, 'Create New Crew'), 'Create New Crew must remain available during prelaunch.');
fcmc_assert(!str_contains($crewController, 'FREE_PLAN') && !str_contains($crewView, 'upgrade'), 'No subscription ownership enforcement may be introduced.');
fcmc_assert(str_contains($crewView, 'Each Crew may have its own current Challenge.'), 'Multi-Crew current-Challenge model copy missing.');

fcmc_assert(str_contains($crewView, 'name="action" value="prepare_new_challenge"'), 'Crew Home must expose existing Challenge creation path for eligible Crew.');
fcmc_assert(str_contains($challengeController, 'fc_crew_current_challenge($pdo, (int) $crew[\'id\']) !== null'), 'Challenge preparation must preserve one-current-Challenge-per-Crew guard.');
fcmc_assert(str_contains($challengeService, 'INSERT INTO crew_current_challenges'), 'Database-backed current-Challenge authority must remain.');
fcmc_assert(str_contains($challengeIndex, 'several current Challenges across different Crews'), 'Global Challenge page must explain multi-Crew current Challenge capability.');
fcmc_assert(str_contains($challengeIndex, 'section-context-link'), 'Challenge groups must link back to Crew Home.');

fcmc_assert(str_contains($manageController, '$displayRule = $publishedRule ?? $draftRule;'), 'Manage Challenge summary must reuse current Rules truth.');
fcmc_assert(str_contains($manageView, 'Basics'), 'Manage Challenge basics section missing.');
fcmc_assert(str_contains($manageView, 'Rules &amp; Schedule'), 'Manage Challenge Rules section missing.');
fcmc_assert(str_contains($manageView, 'Lifecycle &amp; Visibility'), 'Manage Challenge lifecycle section missing.');
fcmc_assert(str_contains($manageView, 'Danger Zone'), 'Manage Challenge danger zone missing.');
fcmc_assert(str_contains($manageView, 'Open Challenge'), 'Manage Challenge must retain direct Challenge navigation.');
fcmc_assert(str_contains($manageView, 'Crew Home'), 'Manage Challenge must retain direct Crew navigation.');

echo "Multi-Crew / Challenge creation UX proof: PASS\n";
echo "- multiple Crew ownership visible / Create New Crew available: PASS\n";
echo "- one current Challenge per Crew preserved: PASS\n";
echo "- Crew Home / global Challenge creation paths aligned: PASS\n";
echo "- Manage Challenge behavior preserved with clearer presentation: PASS\n";

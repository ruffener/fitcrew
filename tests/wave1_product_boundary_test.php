<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not Found\n");
}

$root = dirname(__DIR__);
$wave1Migrations = glob($root . '/database/migrations/02*.sql') ?: [];
sort($wave1Migrations, SORT_STRING);
$names = array_map('basename', $wave1Migrations);
$expected = [
    '0200_create_crews.sql',
    '0210_create_crew_memberships.sql',
    '0220_create_challenges.sql',
    '0230_create_challenge_participations.sql',
    '0240_create_challenge_rule_versions.sql',
    '0250_create_user_product_contexts.sql',
];
if ($names !== $expected) {
    throw new RuntimeException('Unexpected Wave 1 migration footprint.');
}

$combined = '';
foreach ($wave1Migrations as $path) {
    $combined .= "\n" . file_get_contents($path);
}
$forbiddenTables = [
    'health_provider_connections',
    'raw_health_imports',
    'official_daily_logs',
    'challenge_monies',
    'challenge_stack',
    'scoring_results',
    'standings',
    'payments',
];
foreach ($forbiddenTables as $table) {
    if (stripos($combined, 'CREATE TABLE ' . $table) !== false) {
        throw new RuntimeException('Wave 1 crossed a held runtime boundary: ' . $table);
    }
}

$authFiles = [
    'inc/auth/google.php',
    'inc/auth/microsoft.php',
    'auth/google/credential.php',
    'auth/microsoft/start.php',
    'auth/microsoft/callback.php',
    'auth/microsoft/complete.php',
];
foreach ($authFiles as $file) {
    if (!is_file($root . '/' . $file)) {
        throw new RuntimeException('Accepted Auth file unexpectedly missing: ' . $file);
    }
}

$dashboard = file_get_contents($root . '/views/app/dashboard.php') ?: '';
foreach (['Phase 1B', 'Protected app shell', 'No group selected', 'Group behavior has not been implemented'] as $retired) {
    if (str_contains($dashboard, $retired)) {
        throw new RuntimeException('Participant-facing development language remains in Overview: ' . $retired);
    }
}

fwrite(STDOUT, "Wave 1 product boundary proof: PASS\n");
fwrite(STDOUT, "- exact Wave 1 migration footprint: PASS\n");
fwrite(STDOUT, "- no Health/Scoring/Monies/payment runtime tables: PASS\n");
fwrite(STDOUT, "- accepted Auth runtime preserved: PASS\n");
fwrite(STDOUT, "- retired development-language checks: PASS\n");

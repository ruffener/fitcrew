<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/inc/config/paths.php';
require_once fc_path('inc/db/migrations.php');

function test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$sourceDirectory = fc_path('database/migrations');
$canonical = fc_migration_discover($sourceDirectory);
test_assert(count($canonical) === 1, 'Expected exactly one Phase 2A1 migration.');
test_assert($canonical[0]['name'] === FC_MIGRATION_BOOTSTRAP_FILE, 'Bootstrap migration name mismatch.');

test_assert(
    preg_match('/^[a-f0-9]{64}$/', $canonical[0]['checksum']) === 1,
    'Expected a SHA-256 checksum.'
);

$syntheticLedger = [
    $canonical[0]['name'] => [
        'id' => 1,
        'migration' => $canonical[0]['name'],
        'checksum' => $canonical[0]['checksum'],
        'applied_at' => '2026-08-16 00:00:00.000000',
    ],
];

fc_migration_verify_integrity($canonical, $syntheticLedger);

$tempDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fitcrew-migration-test-' . bin2hex(random_bytes(6));
if (!mkdir($tempDirectory, 0700, true) && !is_dir($tempDirectory)) {
    throw new RuntimeException('Could not create temporary test directory.');
}

try {
    $tempMigration = $tempDirectory . DIRECTORY_SEPARATOR . FC_MIGRATION_BOOTSTRAP_FILE;
    if (!copy($canonical[0]['path'], $tempMigration)) {
        throw new RuntimeException('Could not copy migration into temporary test directory.');
    }

    file_put_contents($tempMigration, PHP_EOL . '-- controlled checksum test mutation' . PHP_EOL, FILE_APPEND);
    $mutated = fc_migration_discover($tempDirectory);

    $mismatchDetected = false;
    try {
        fc_migration_verify_integrity($mutated, $syntheticLedger);
    } catch (RuntimeException $error) {
        $mismatchDetected = str_contains($error->getMessage(), 'checksum mismatch');
    }

    test_assert($mismatchDetected, 'Expected controlled checksum mismatch to stop integrity verification.');
} finally {
    if (isset($tempMigration) && is_file($tempMigration)) {
        unlink($tempMigration);
    }
    if (is_dir($tempDirectory)) {
        rmdir($tempDirectory);
    }
}

fwrite(STDOUT, "Migration foundation unit proof: PASS\n");
fwrite(STDOUT, "- deterministic discovery: PASS\n");
fwrite(STDOUT, "- canonical checksum verification: PASS\n");
fwrite(STDOUT, "- controlled checksum mismatch stop: PASS\n");

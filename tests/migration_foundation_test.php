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
test_assert(count($canonical) >= 1, 'Expected at least the migration bootstrap file.');
test_assert($canonical[0]['name'] === FC_MIGRATION_BOOTSTRAP_FILE, 'Bootstrap migration name mismatch.');

$names = array_column($canonical, 'name');
$sortedNames = $names;
sort($sortedNames, SORT_STRING);
test_assert($names === $sortedNames, 'Migration discovery order is not deterministic.');
test_assert(count($names) === count(array_unique($names)), 'Duplicate migration filename discovered.');

foreach ($canonical as $migration) {
    test_assert(
        preg_match('/^[a-f0-9]{64}$/', $migration['checksum']) === 1,
        'Expected a SHA-256 checksum for ' . $migration['name'] . '.'
    );
}

$bootstrap = $canonical[0];
$syntheticLedger = [
    $bootstrap['name'] => [
        'id' => 1,
        'migration' => $bootstrap['name'],
        'checksum' => $bootstrap['checksum'],
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
    $sourceBytes = file_get_contents($bootstrap['path']);
    if ($sourceBytes === false) {
        throw new RuntimeException('Could not read canonical bootstrap migration.');
    }

    $lfBytes = str_replace(["\r\n", "\r"], "\n", $sourceBytes);
    if (file_put_contents($tempMigration, $lfBytes) === false) {
        throw new RuntimeException('Could not write LF migration fixture.');
    }
    $lfDiscovered = fc_migration_discover($tempDirectory);

    $crlfBytes = str_replace("\n", "\r\n", $lfBytes);
    if (file_put_contents($tempMigration, $crlfBytes) === false) {
        throw new RuntimeException('Could not write CRLF migration fixture.');
    }
    $crlfDiscovered = fc_migration_discover($tempDirectory);

    test_assert(
        hash_equals($lfDiscovered[0]['checksum'], $crlfDiscovered[0]['checksum'])
            && hash_equals($bootstrap['checksum'], $crlfDiscovered[0]['checksum']),
        'LF/CRLF migration checkout changed the canonical checksum.'
    );

    file_put_contents($tempMigration, "\r\n-- controlled checksum test mutation\r\n", FILE_APPEND);
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
fwrite(STDOUT, "- LF/CRLF checksum portability: PASS\n");
fwrite(STDOUT, "- controlled checksum mismatch stop: PASS\n");

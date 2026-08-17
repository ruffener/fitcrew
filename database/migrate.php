<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
    }

    echo "Not Found\n";
    exit(1);
}

require_once dirname(__DIR__) . '/inc/config/paths.php';
require_once fc_path('inc/config/env.php');

fc_load_env(fc_path('.env'));

require_once fc_path('inc/config/app.php');
require_once fc_path('inc/db/connection.php');
require_once fc_path('inc/db/migrations.php');

const FC_MIGRATE_EXIT_CONFIG = 10;
const FC_MIGRATE_EXIT_CONNECTION = 11;
const FC_MIGRATE_EXIT_INTEGRITY = 12;
const FC_MIGRATE_EXIT_EXECUTION = 13;

function fc_migrate_out(string $message = ''): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function fc_migrate_err(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
}

function fc_migrate_fail(string $category, Throwable $error, int $exitCode): never
{
    fc_migrate_err(sprintf('[FAIL:%s] %s', $category, $error->getMessage()));
    exit($exitCode);
}

try {
    $target = fc_migration_validate_target();
} catch (Throwable $error) {
    fc_migrate_fail('CONFIG', $error, FC_MIGRATE_EXIT_CONFIG);
}

fc_migrate_out('FitCrew Challenge — Migration Runner');
fc_migrate_out(str_repeat('=', 38));
fc_migrate_out(sprintf('Environment : %s', $target['env']));
fc_migrate_out(sprintf('Host        : %s:%s', $target['host'], $target['port']));
fc_migrate_out(sprintf('Database    : %s', $target['database']));
fc_migrate_out(sprintf('DB user     : %s', $target['username']));
fc_migrate_out(sprintf('Migrations  : %s', fc_path('database/migrations')));
fc_migrate_out(str_repeat('-', 38));

try {
    $pdo = fc_db();
    $serverVersion = (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
    fc_migrate_out(sprintf('Connected   : MariaDB/MySQL %s', $serverVersion));
} catch (Throwable $error) {
    fc_migrate_fail('CONNECTION', $error, FC_MIGRATE_EXIT_CONNECTION);
}

try {
    $migrations = fc_migration_discover(fc_path('database/migrations'));
} catch (Throwable $error) {
    fc_migrate_fail('INTEGRITY', $error, FC_MIGRATE_EXIT_INTEGRITY);
}

if ($migrations === []) {
    fc_migrate_fail(
        'INTEGRITY',
        new RuntimeException('No migration files were discovered. The bootstrap migration is required.'),
        FC_MIGRATE_EXIT_INTEGRITY
    );
}

$firstMigration = $migrations[0];
if ($firstMigration['name'] !== FC_MIGRATION_BOOTSTRAP_FILE) {
    fc_migrate_fail(
        'INTEGRITY',
        new RuntimeException(sprintf(
            'First migration must be %s; found %s.',
            FC_MIGRATION_BOOTSTRAP_FILE,
            $firstMigration['name']
        )),
        FC_MIGRATE_EXIT_INTEGRITY
    );
}

try {
    $ledgerExists = fc_migration_ledger_exists($pdo);
} catch (Throwable $error) {
    fc_migrate_fail('INTEGRITY', $error, FC_MIGRATE_EXIT_INTEGRITY);
}

$appliedThisRun = 0;

if (!$ledgerExists) {
    fc_migrate_out(sprintf('[BOOTSTRAP] Applying %s', $firstMigration['name']));

    try {
        fc_migration_execute($pdo, $firstMigration);

        if (!fc_migration_ledger_exists($pdo)) {
            throw new RuntimeException('Bootstrap migration completed without creating schema_migrations.');
        }

        fc_migration_record($pdo, $firstMigration);
        $appliedThisRun++;
        fc_migrate_out(sprintf('[APPLIED]   %s', $firstMigration['name']));
    } catch (Throwable $error) {
        fc_migrate_fail('EXECUTION', $error, FC_MIGRATE_EXIT_EXECUTION);
    }
}

try {
    $ledger = fc_migration_ledger($pdo);

    if (!isset($ledger[FC_MIGRATION_BOOTSTRAP_FILE])) {
        throw new RuntimeException(
            'Migration ledger exists but the bootstrap migration is not recorded. Refusing to adopt ambiguous migration history.'
        );
    }

    fc_migration_verify_integrity($migrations, $ledger);
} catch (Throwable $error) {
    fc_migrate_fail('INTEGRITY', $error, FC_MIGRATE_EXIT_INTEGRITY);
}

foreach ($migrations as $migration) {
    if (isset($ledger[$migration['name']])) {
        continue;
    }

    fc_migrate_out(sprintf('[PENDING]   %s', $migration['name']));

    try {
        fc_migration_execute($pdo, $migration);
        fc_migration_record($pdo, $migration);
        $appliedThisRun++;
        fc_migrate_out(sprintf('[APPLIED]   %s', $migration['name']));
    } catch (Throwable $error) {
        fc_migrate_fail('EXECUTION', $error, FC_MIGRATE_EXIT_EXECUTION);
    }
}

if ($appliedThisRun === 0) {
    fc_migrate_out('No pending migrations.');
} else {
    fc_migrate_out(sprintf('Migration run complete. Applied %d migration(s).', $appliedThisRun));
}

exit(0);

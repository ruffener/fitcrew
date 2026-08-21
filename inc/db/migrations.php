<?php

declare(strict_types=1);

const FC_MIGRATION_BOOTSTRAP_FILE = '0001_create_schema_migrations.sql';
const FC_MIGRATION_LEDGER_TABLE = 'schema_migrations';

/**
 * @return list<string>
 */
function fc_migration_csv_list(string $value): array
{
    $items = array_values(array_filter(
        array_map(static fn (string $item): string => trim($item), explode(',', $value)),
        static fn (string $item): bool => $item !== ''
    ));

    return array_values(array_unique($items));
}

function fc_migration_required_env(string $key): string
{
    $value = $_ENV[$key] ?? getenv($key);

    if ($value === false || $value === null || trim((string) $value) === '') {
        throw new RuntimeException(sprintf('Required migration configuration is missing: %s', $key));
    }

    return trim((string) $value);
}

/**
 * Validate the configured migration target before opening a database connection.
 *
 * @return array{env:string,host:string,port:string,database:string,username:string,charset:string}
 */
function fc_migration_validate_target(): array
{
    $env = fc_migration_required_env('APP_ENV');
    $host = fc_migration_required_env('DB_HOST');
    $port = fc_migration_required_env('DB_PORT');
    $database = fc_migration_required_env('DB_DATABASE');
    $username = fc_migration_required_env('DB_USERNAME');
    $charset = fc_migration_required_env('DB_CHARSET');

    $allowedEnvironments = fc_migration_csv_list(fc_migration_required_env('MIGRATION_ALLOWED_ENVIRONMENTS'));
    $allowedHosts = fc_migration_csv_list(fc_migration_required_env('MIGRATION_ALLOWED_HOSTS'));
    $allowedDatabases = fc_migration_csv_list(fc_migration_required_env('MIGRATION_ALLOWED_DATABASES'));

    if (!in_array($env, $allowedEnvironments, true)) {
        throw new RuntimeException(sprintf(
            'Migration target rejected: APP_ENV "%s" is not in MIGRATION_ALLOWED_ENVIRONMENTS.',
            $env
        ));
    }

    if (!in_array($host, $allowedHosts, true)) {
        throw new RuntimeException(sprintf(
            'Migration target rejected: DB_HOST "%s" is not in MIGRATION_ALLOWED_HOSTS.',
            $host
        ));
    }

    if (!in_array($database, $allowedDatabases, true)) {
        throw new RuntimeException(sprintf(
            'Migration target rejected: DB_DATABASE "%s" is not in MIGRATION_ALLOWED_DATABASES.',
            $database
        ));
    }

    if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
        throw new RuntimeException(sprintf('Migration target rejected: invalid DB_PORT "%s".', $port));
    }

    return [
        'env' => $env,
        'host' => $host,
        'port' => $port,
        'database' => $database,
        'username' => $username,
        'charset' => $charset,
    ];
}

function fc_migration_checksum(string $path): string
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException(sprintf('Unable to checksum migration: %s', basename($path)));
    }

    // Migration history must be portable across Windows and Linux working trees.
    // Canonicalize text line endings to LF before hashing so Git/ZIP checkout
    // line-ending conversion cannot make an otherwise identical migration look mutated.
    $canonicalContents = str_replace(["\r\n", "\r"], "\n", $contents);

    return hash('sha256', $canonicalContents);
}

/**
 * @return list<array{name:string,path:string,checksum:string}>
 */
function fc_migration_discover(string $directory): array
{
    if (!is_dir($directory)) {
        throw new RuntimeException(sprintf('Migration directory does not exist: %s', $directory));
    }

    $paths = glob($directory . DIRECTORY_SEPARATOR . '*.sql');
    if ($paths === false) {
        throw new RuntimeException(sprintf('Unable to read migration directory: %s', $directory));
    }

    sort($paths, SORT_STRING);

    $migrations = [];
    foreach ($paths as $path) {
        $name = basename($path);

        if (!preg_match('/^\d{4}_[a-z0-9_]+\.sql$/', $name)) {
            throw new RuntimeException(sprintf(
                'Invalid migration filename "%s". Expected NNNN_lowercase_name.sql.',
                $name
            ));
        }

        $checksum = fc_migration_checksum($path);

        $migrations[] = [
            'name' => $name,
            'path' => $path,
            'checksum' => $checksum,
        ];
    }

    return $migrations;
}

function fc_migration_ledger_exists(PDO $pdo): bool
{
    $statement = $pdo->query(
        "SELECT COUNT(*) AS table_count\n" .
        "FROM information_schema.tables\n" .
        "WHERE table_schema = DATABASE()\n" .
        "  AND table_name = '" . FC_MIGRATION_LEDGER_TABLE . "'"
    );

    $row = $statement->fetch();

    return (int) ($row['table_count'] ?? 0) === 1;
}

/**
 * @return array<string,array{id:int,migration:string,checksum:string,applied_at:string}>
 */
function fc_migration_ledger(PDO $pdo): array
{
    $statement = $pdo->query(
        'SELECT id, migration, checksum, applied_at FROM ' . FC_MIGRATION_LEDGER_TABLE . ' ORDER BY id ASC'
    );

    $rows = [];
    foreach ($statement->fetchAll() as $row) {
        $migration = (string) $row['migration'];
        $rows[$migration] = [
            'id' => (int) $row['id'],
            'migration' => $migration,
            'checksum' => (string) $row['checksum'],
            'applied_at' => (string) $row['applied_at'],
        ];
    }

    return $rows;
}

/**
 * @param list<array{name:string,path:string,checksum:string}> $migrations
 * @param array<string,array{id:int,migration:string,checksum:string,applied_at:string}> $ledger
 */
function fc_migration_verify_integrity(array $migrations, array $ledger): void
{
    $filesByName = [];
    foreach ($migrations as $migration) {
        $filesByName[$migration['name']] = $migration;
    }

    foreach ($ledger as $name => $applied) {
        if (!isset($filesByName[$name])) {
            throw new RuntimeException(sprintf(
                'Migration integrity failure: applied migration file is missing: %s',
                $name
            ));
        }

        if (!hash_equals($applied['checksum'], $filesByName[$name]['checksum'])) {
            throw new RuntimeException(sprintf(
                'Migration checksum mismatch: %s. Applied history is immutable; restore the canonical file and create a new migration for schema changes.',
                $name
            ));
        }
    }
}

/**
 * @param array{name:string,path:string,checksum:string} $migration
 */
function fc_migration_execute(PDO $pdo, array $migration): void
{
    $sql = file_get_contents($migration['path']);
    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException(sprintf('Migration SQL is empty or unreadable: %s', $migration['name']));
    }

    $pdo->exec($sql);
}

/**
 * @param array{name:string,path:string,checksum:string} $migration
 */
function fc_migration_record(PDO $pdo, array $migration): void
{
    $statement = $pdo->prepare(
        'INSERT INTO ' . FC_MIGRATION_LEDGER_TABLE . ' (migration, checksum) VALUES (:migration, :checksum)'
    );
    $statement->execute([
        ':migration' => $migration['name'],
        ':checksum' => $migration['checksum'],
    ]);
}

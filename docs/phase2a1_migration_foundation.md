# Phase 2A1 — Migration Foundation

## Purpose

Phase 2A1 establishes the trusted mechanism FitCrew Challenge uses to create, track, verify, and evolve MariaDB schema.

It does **not** introduce account, group, challenge, health, scoring, fine, badge, or billing tables.

## Canonical command

```text
php database/migrate.php
```

The runner is CLI-only. It rejects non-CLI execution before loading configuration or database code. The root `.htaccess` also denies direct HTTP access to `/database/`, and `database/.htaccess` independently denies that directory, providing defense in depth. The production deployment workflow explicitly uploads `database/.htaccess` so that protection is not dependent on wildcard/dotfile behavior.

## Migration files

Migrations live in:

```text
database/migrations/
```

Naming contract:

```text
NNNN_lowercase_name.sql
```

Files are discovered and executed in deterministic lexical order.

The bootstrap migration must remain:

```text
0001_create_schema_migrations.sql
```

Applied migration files are immutable history. Schema evolution occurs through new migration files.

Checksum comparison is line-ending portable: migration text is normalized to LF before SHA-256 hashing so equivalent Windows CRLF and Linux LF working-tree copies preserve the same immutable migration identity.

## Migration ledger

Table:

```text
schema_migrations
```

Contract:

| Column | Purpose |
|---|---|
| `id` | Monotonic execution order / primary key |
| `migration` | Unique migration filename |
| `checksum` | SHA-256 checksum of canonical migration text with CRLF/CR line endings normalized to LF |
| `applied_at` | Database timestamp when the migration was recorded |

The first migration creates the ledger and is then recorded in that ledger.

## Integrity behavior

Before any pending migration is executed, the runner verifies every recorded migration against the current canonical file.

The runner stops if:

- an applied migration file is missing;
- an applied migration checksum changed;
- the ledger exists but the bootstrap migration is not recorded;
- migration filenames violate the naming contract;
- the required bootstrap migration is not first.

The ledger is never silently rewritten to accept changed history.

## Environment / target safeguards

The runner requires explicit values for:

```text
APP_ENV
DB_HOST
DB_PORT
DB_DATABASE
DB_USERNAME
DB_CHARSET
MIGRATION_ALLOWED_ENVIRONMENTS
MIGRATION_ALLOWED_HOSTS
MIGRATION_ALLOWED_DATABASES
```

Before connecting, the target environment, host, and database must match the configured migration allowlists.

Phase 2A1 defaults in `.env.example` allow only:

```text
APP_ENV=local
DB_HOST=127.0.0.1 or localhost
DB_DATABASE=fitcrew
```

Production migration policy is intentionally not authorized by Phase 2A1 and should be enabled only by a later Governance decision.

## Failure posture

The migration runner:

- stops on the first error;
- does not silently continue;
- does not attempt unproven automatic DDL rollback;
- records a migration only after its SQL executes successfully.

Development may use a clean reset/rebuild when appropriate.

Shared/production environments should use backup + tested corrective forward migrations once production migration execution is separately authorized.

## Expected proof

### First run

Against a local `fitcrew` database without `schema_migrations`:

```text
php database/migrate.php
```

Expected result includes:

```text
[BOOTSTRAP] Applying 0001_create_schema_migrations.sql
[APPLIED]   0001_create_schema_migrations.sql
Migration run complete. Applied 1 migration(s).
```

### Second run

Run the same command again.

Expected:

```text
No pending migrations.
```

### Checksum mismatch

In a controlled local test only, change the already-applied bootstrap migration file and rerun.

Expected:

```text
[FAIL:INTEGRITY] Migration checksum mismatch: 0001_create_schema_migrations.sql ...
```

Restore the canonical file immediately afterward. Do not commit altered migration history.

### Wrong target

Temporarily set a database name not included in `MIGRATION_ALLOWED_DATABASES` and run again.

Expected:

```text
[FAIL:CONFIG] Migration target rejected ...
```

No database connection or schema change should occur.

### HTTP / browser

Direct browser access to:

```text
/database/
/database/migrate.php
/database/migrations/
```

must be denied by the web security boundary.

Even if that web-server deny rule is accidentally removed, `database/migrate.php` independently checks `PHP_SAPI` and refuses non-CLI execution before loading configuration or migration behavior.

# Database Schema Plan

## Phase 2A1 — Migration foundation

Phase 2A1 authorizes migration infrastructure only.

The only database table introduced in this phase is:

- `schema_migrations` — immutable migration ledger/source of truth.

The canonical migration entry point is CLI-only:

```text
php database/migrate.php
```

See `docs/phase2a1_migration_foundation.md` for the migration contract, safety rules, and proof procedure.

## Future product tables

Product tables remain separately governed and are **not** created by Phase 2A1.

Examples of later candidates include:

- users
- user_auth_identities
- user_contact_emails
- user_sessions
- groups
- group_members
- group_invitations
- challenges
- challenge_participants
- health_provider_connections
- health_import_batches
- raw_health_imports
- official_daily_logs
- official_daily_log_sources
- baseline_snapshots
- leaderboard_snapshots
- fine_assessments
- badge_awards

The Google Sheets prototype remains product evidence, not database architecture.

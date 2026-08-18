# Database Schema Plan

## Phase 2A1 — Migration foundation

Accepted infrastructure:

- `schema_migrations` — immutable migration ledger/source of truth.
- CLI-only migration entry point: `php database/migrate.php`.
- SHA-256 applied-history integrity enforcement.

## Phase 2A2 — Account / Identity foundation

Authorized product tables:

- `users` — durable platform account with numeric internal ID + opaque ULID public ID.
- `user_auth_identities` — Google, Apple, and Microsoft identity records; no local-password identity.
- `user_contact_emails` — non-globally-unique communication/invitation email addresses.
- `user_sessions` — hashed server-side session identity and revocation records.
- `auth_transactions` — short-lived provider/intent-bound authentication transaction evidence.
- `audit_events` — append-oriented security/account audit foundation.

Phase 2A2 migration files:

```text
0010_create_users.sql
0020_create_user_auth_identities.sql
0030_create_user_contact_emails.sql
0040_create_user_sessions.sql
0050_create_auth_transactions.sql
0060_create_audit_events.sql
```

## Still not authorized

Examples include:

- groups / memberships / invitations
- challenges / participants
- health_provider_connections
- health imports / raw_health_imports
- official_daily_logs
- scoring / leaderboard
- fines / badges
- billing

The Google Sheets prototype remains product evidence, not database architecture.

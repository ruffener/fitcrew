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
- `auth_transactions` — short-lived provider/intent-bound authentication transaction evidence and protected temporary PKCE verifier material.
- `audit_events` — append-oriented security/account audit foundation.

Original Phase 2A2 migration files (already applied; immutable):

```text
0010_create_users.sql
0020_create_user_auth_identities.sql
0030_create_user_contact_emails.sql
0040_create_user_sessions.sql
0050_create_auth_transactions.sql
0060_create_audit_events.sql
```

Targeted Phase 2A2 reconciliation uses forward-only migrations:

```text
0070_add_provider_email_verification_claim.sql
0080_add_auth_identity_session_owner_key.sql
0090_enforce_session_identity_owner.sql
0100_add_auth_transaction_pkce_secret.sql
```

Reconciled contracts:

- provider email verification preserves TRUE / FALSE / UNKNOWN through nullable `provider_email_verified` plus optional observation time;
- `user_sessions` has database-enforced composite integrity tying the session user to the owner of the establishing authentication identity;
- PKCE verifier material can be recovered only through protected short-lived transaction logic using an environment-held Sodium secretbox key; the raw verifier is never stored or logged in plaintext;
- account-entry provider display order is Google → Apple → Microsoft.

## Still not authorized

Examples include:

- live Google / Apple / Microsoft authentication
- groups / memberships / invitations
- challenges / participants
- health_provider_connections
- health imports / raw_health_imports
- official_daily_logs
- scoring / leaderboard
- fines / badges
- billing

The Google Sheets prototype remains product evidence, not database architecture.

## Wave 1 — Core Product Spine

Authorized Wave 1 adds the first durable product tables after the accepted authentication foundation:

- `crews` — private persistent Crew identity and Owner;
- `crew_memberships` — server-authorized Crew membership/role/status;
- `challenges` — Crew-scoped Challenge identity, Owner, lifecycle and operational state;
- `challenge_participations` — explicit Challenge competitor relationship distinct from Crew membership;
- `challenge_rule_versions` — typed draft/published immutable version foundation for the Challenge contract;
- `user_product_contexts` — selected Crew/Challenge preference only; never authorization truth.

Held Wave 2–4 tables are intentionally absent. No Health import/official-log, scoring result/standings, Challenge Monies, recognition, payment, or billing runtime is introduced by Wave 1.

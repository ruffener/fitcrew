# ADMIN-1 — Platform administration foundation

Status: implementation candidate rebased to September 22; automated local proof PASS. Fresh browser visual smoke remains pending because this execution environment blocked browser verification. Governance review is required before main promotion. Production bootstrap and production smoke are not performed.

## Source authority and ownership

Revalidated against `fitcrew-20260922-062428.zip` and matching `.sql`, supplied directly to Admin. The prior 40-file ADMIN-1 candidate was recovered from its September 18 review package; all existing source comes from the September 22 baseline.

- Source SHA-256: `f56167c39eec3f90a88abcf80ad2e6885f89e9bdfe5336e6fa4cba624f3cbf73`
- SQL SHA-256: `68443d8e9b3612747a3bc383c6ae5878a7830985bd88516b2c82f609b74f8b29`
- Auth's `FC_PLATFORM_ROLES` already includes `PLATFORM_SUPER_ADMIN`; it is consumed unchanged.
- All 25 applied migration checksums matched the supplied source. Migration family 0400 was unused.
- Changes are new files under `admin/`, `inc/admin/`, `views/admin/`, `tests/admin_*`, Admin documentation, and the authorized migration 0400. Existing source files remain byte-identical.
- No shared layout, Auth, Identity, Security, Product, Mail, environment file or deployment-tool modification.

## Authorization contract

Auth's `fc_current_user()` is the sole source of the authenticated principal. Request data cannot supply an actor, session or identity. Every Admin request re-reads the canonical user role and account status. A locked current read verifies that the Auth principal's owned identity and durable session remain active. Session expiry is compared with a fresh database clock read after lock acquisition.

- `USER`: no Admin access.
- Active `PLATFORM_ADMIN`: all read-only operational pages; no Admin management route or platform-role mutation.
- Active `PLATFORM_SUPER_ADMIN`: all read-only pages and Admin management.
- Missing, inactive, unknown or revoked authority fails closed.
- Email does not authorize any runtime Admin operation.
- Admin access does not grant Crew ownership, Challenge participation or Product mutation privileges.

Anonymous visitors are sent to the existing sign-in page. Auth retains its existing destination contract; after sign-in, the operator opens `/admin/` again. No Website navigation integration is included.

## Routes

| Route | Purpose |
| --- | --- |
| `/admin/` | Dashboard and latest safe audit events |
| `/admin/users.php` | Search users by name, contact/provider email or canonical public ID |
| `/admin/user.php?id=ULID` | Account status, authentication-method summary, session status, memberships, audit history |
| `/admin/crews.php` | Search Crews |
| `/admin/crew.php?id=ULID` | Owner, membership, Challenge summary, invitations, Crew audit history |
| `/admin/invitations.php` | Search invitations and view effective/transport status |
| `/admin/invitation.php?id=ULID` | Invitation lifecycle and safe transport details |
| `/admin/authentication.php` | Authentication methods, session counts, last-24-hour authentication audit counts |
| `/admin/system.php` | Database availability, migration/index presence, Super Admin count, database clock |
| `/admin/admins.php` | Super Admin only: current admins and search for a user |
| `/admin/role.php` | Super Admin only: GET confirmation preview, POST role change |

Search lists are parameterized, escape LIKE wildcard characters, and paginate in groups of 50. Detail sections and audit views explicitly show the latest 50 records. The console is responsive and keyboard accessible, with horizontally scrollable record tables on small screens. Visual styling consumes existing FitCrew assets and color/font variables without changing the shared visual foundation.

Invitation lists and detail show the associated Challenge, while historical null-Challenge invitations are explicitly labeled `Legacy Crew-only`. No Challenge consent is inferred from Crew membership. Challenge lifecycle values, including `LAUNCHED`, are read directly from the canonical stored record.

Admin uses the current shared UI Foundation typography, spacing, radius, control-height and focus tokens in its own stylesheet. No shared CSS or layout is edited.

Invitation `TRANSPORT_ACCEPTED` is not described as inbox delivery. Effective expiry is computed for display without updating the invitation. No operational data cleanup or repair is performed.

## Role mutations and Super Admin protection

Only these transitions exist:

- Make Admin: USER → PLATFORM_ADMIN, for an active target account.
- Remove Admin: PLATFORM_ADMIN → USER, including an inactive ordinary Admin.

Both require POST, the shared CSRF check, Super Admin authority, a confirmation checkbox and a short-lived one-use confirmation bound to actor, durable session, target, action and expected old role. GET only presents confirmation. A confirmation lasts five minutes; up to ten independent confirmation tabs are retained. Other methods return 405.

The role service owns its transaction. It locks actor and target users in numeric order, then the actor's identity and session; rechecks current authority; rejects Super Admin targets; checks the expected role; updates exactly one row; and appends the success audit before commit. Stale requests return a conflict or denial. Simultaneous duplicate promotions yield one success. Deadlocks or database failures produce a generic retryable failure, with no unconfirmed retry by the service.

An ordinary Admin cannot grant or revoke any platform role. Even a Super Admin cannot demote the Super Admin through this console. No account suspension/deactivation function exists here. No route or action can create another Super Admin.

## Migration 0400

`database/migrations/0400_platform_super_admin.sql` is one forward ALTER TABLE statement:

1. Replace the role CHECK with the three exact canonical codes.
2. Add `platform_super_admin_slot`, a persistent generated TINYINT: 1 for Super Admin, NULL for all other roles.
3. Add unique index `uq_users_single_platform_super_admin` on that column.

The unique index enforces **at most one Super Admin**, regardless of account status, including concurrent direct SQL updates. Multiple Users/Admins remain permitted because their generated keys are NULL. The CHECK is case-sensitive so variant spellings cannot evade the singleton expression. There is no email, INSERT, UPDATE, promotion, trigger or routine in the migration.

This uses the documented MariaDB [generated column](https://mariadb.com/docs/server/reference/sql-statements/data-definition/create/generated-columns) and [nullable unique-key](https://mariadb.com/docs/server/server-usage/tables/create-table) mechanisms. Execution was proved on MariaDB 10.6.23.

The existing migration runner records the migration normally. Admin has no migration-ledger write/edit feature. Re-running the runner must report no pending migrations. DDL is not rolled back by an ordinary transaction; use the existing backup/migration operational practice and review before production application.

## Audit and safe data

Admin consumes `fc_audit_event_write()` unchanged.

- `ADMIN_ENTRY`: successful and denied entry per route/request; anonymous attempts have a null actor.
- `ADMIN_ROLE_GRANTED` / `ADMIN_ROLE_REVOKED`: success or denial, with actor canonical ID, target canonical ID, old role, requested new role, result and database timestamp.
- `ADMIN_ROLE_REJECTED` / `ADMIN_REQUEST_REJECTED`: invalid action, CSRF or method attempts where applicable.
- `ADMIN_SUPER_BOOTSTRAPPED`: successful manual one-time bootstrap.

Success audit and role mutation are atomic. Audit storage failure rolls the mutation back. A failure audit is attempted after a runtime transaction rollback; if audit storage itself is unavailable, the response fails closed with 503 and a generic server log. No application can guarantee persistence into unavailable audit storage.

The UI selects safe operational columns explicitly. It does not select or render token material, token hashes, raw sessions, client/network evidence, provider subjects, provider object IDs, transport message IDs, protected envelopes, environment values, database credentials, raw health evidence or unfiltered audit metadata. Audit role fields are projected only for known role events and rendered using canonical labels. Output is escaped. Responses use no-store, no-referrer, noindex and frame-denial headers.

Expected writes on otherwise read-only visits are Auth's existing session maintenance and Admin entry audits. There are no Product-domain writes.

## One-time operator bootstrap

Use `docs/admin_1_bootstrap.sql` only after Governance accepts the candidate, authorizes promotion/migration, and the operator is ready to bootstrap manually. Do not put it into migrations or execute it as part of deployment.

The supplied snapshot identifies this candidate:

- canonical user ID: `144`
- canonical public ID: `01M1TW269Q9M5DNWDN16YDWCQZ`
- display name: Brad Ruffener
- current account status: ACTIVE
- current platform role: USER

Reverify those values against the live database using step 1 of the bootstrap procedure; the fresh production result wins. The procedure requires an explicit canonical public ID and checks that exactly one canonical user is associated with the existing active, verified Google identity used for bootstrap identification. Leave the placeholder unchanged to refuse execution safely. The email string does not become a runtime rule.

Copy the verified public ID into the indicated session variable and execute the block with the MariaDB CLI (which supports DELIMITER), without `--force`. No stored routine is created. The block locks and validates current user/identity state, refuses an existing Super Admin, changes only the selected canonical user's role, writes the audit and commits both together. Any SQL error rolls back and is rethrown. A repeat is refused.

Open `https://fitcrewchallenge.com/admin/` while signed in as that canonical user. Its current session reads the role from the database; no provider-email check is involved. If the session is expired, use normal production sign-in first.

## Proof and reproduction

The September 22 proof includes 98 passing Admin assertions (13 authorization, 33 operations, 45 HTTP, 7 concurrency), six passing bootstrap checks, 36 PHP syntax checks, and passing Identity, core Product, Challenge Journey and UI Foundation regression suites. Migration, original-data preservation across all 27 tables, and SQL export/restore passed on MariaDB 10.6.23 with PHP 8.1.

Fresh browser visual verification is pending: local browser startup was restricted and the cloud browser refused local-file preview navigation. The six captured HTML previews are actual HTTP-rendered synthetic-fixture responses, supplied for review; they are not a browser PASS. The live HTTP role workflow passed independently. The earlier September 18 browser result is historical only.

The review package includes exact test logs and a source/data integrity report. Tests use a separate disposable database named **fitcrew_admin1_test**, bound to localhost. They do not load `.env` and refuse other database names or non-local hosts. HTTP proof additionally requires an isolated candidate copy with no `.env` so the existing application bootstrap cannot load production configuration. Do not remove or edit your working `.env` to run tests; use a separate extracted candidate copy.

Prepare a disposable database with that name and run the ordinary migration runner against it using process-scoped configuration and its existing allowlist. Then set:

```text
FC_ADMIN_TEST_DSN=mysql:host=127.0.0.1;port=YOUR_LOCAL_PORT;dbname=fitcrew_admin1_test;charset=utf8mb4
FC_ADMIN_TEST_USER=YOUR_LOCAL_TEST_DB_USER
FC_ADMIN_TEST_PASSWORD=YOUR_LOCAL_TEST_DB_PASSWORD
```

Run sequentially:

```text
php tests/admin_authorization_test.php
php tests/admin_operations_test.php
php tests/admin_http_test.php
php tests/admin_concurrency_test.php
```

The tests clear fixtures only in that explicit disposable test schema. Concurrency proof needs PHP CLI pcntl on Linux/WSL. HTTP proof uses synthetic local test sessions; it does not implement or enable local provider sign-in for FitCrew.

The snapshot proof database is separate from the fixture database. The returned SQL snapshot contains original supplied data plus migration 0400 and its normal ledger entry, with zero Super Admin promotions. It is review evidence, **not a production restore instruction**.

## Held scope and next step

No deletion, merge, identity repair, impersonation, billing, account status changes, health-data editing, score/rank editing, generic permissions builder or arbitrary SQL UI exists.

Governance reviews the candidate before main promotion. After acceptance: apply the patch in the authoritative repository, obtain the real repository commit, use the accepted deployment path, apply migration through the existing runner, perform the one-time manual bootstrap, and verify the production Admin workflow. Production and provider-login acceptance are not claimed by local proof.

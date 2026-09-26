# ADMIN-2A — Auth / Identity user operations

Owner contribution for Governance. This package implements services and an Auth-owned mailbox confirmation, with no Admin console pages or integration changes. ADMIN-1 is closed; historical audit 388 and the canonical Super Admin's historical `updated_at` are not repaired or rewritten.

## Callable contracts

Load the ordinary application bootstrap, then `inc/auth/user_operations.php`.

| Function | Arguments after `PDO $pdo` |
| --- | --- |
| `fc_auth_user_operations_snapshot` | `string $targetPublicId` |
| `fc_auth_user_profile_edit` | `string $target, array $fields, string $revision, string $requestKey, string $reason` |
| `fc_auth_user_sessions_end` | `string $target, string $revision, string $requestKey, string $reason` |
| `fc_auth_user_suspend` | Same as end sessions |
| `fc_auth_user_restore` | Same as end sessions |
| `fc_auth_user_primary_contact_select` | `string $target, int $contactId, string $revision, string $requestKey, string $reason` |
| `fc_auth_user_replacement_contact_initiate` | `string $target, string $email, string $revision, string $requestKey, string $reason` |
| `fc_auth_user_contact_verification_complete` | `string $rawToken`; Auth mailbox endpoint only |

Targets are canonical public IDs. There is no caller-supplied actor argument. Every administrator operation resolves the current PHP session to a durable FitCrew session and rechecks the current account, identity status, expiry and stored platform role under database locks. Internal `fc_user_ops_*` and `fc_user_contact_*` helpers are not authorization entry points.

The snapshot contains profile fields, an opaque SHA-256 `revision`, allowed operations, and the supported locale list. Super Admin snapshots also expose target contact choices. No raw session ID, hash, token or provider subject is exposed. Send back the revision from the displayed snapshot; it covers current profile/status/role, contacts and session issuance/revocation. Changes to any of these invalidate a fresh submission. Ordinary session idle-extension does not invalidate the editor.

Each mutation requires a 16–80 character request key (`A-Z`, `a-z`, digits, underscore or hyphen) and a nonempty, single-line, valid UTF-8 reason of at most 500 characters. Generate a fresh unpredictable request key for each intended operation. Exact repeated requests return the original result/audit without applying again. Reusing a key with different operation, target, fields, reason or expected revision fails `idempotency_conflict`. Fresh authority/target protections still apply to replay. A replayed session-ending operation cannot revoke sessions created after the original operation.

Results contain `operation`, `target_public_id`, `audit_id`, `replayed`, and operation-specific `revoked_sessions` or `verification_public_id`. A newly initiated verification also returns `delivery` (`ACCEPTED`, `FAILED`, `UNKNOWN`, or `UNAVAILABLE`). A replay reports the original committed initiation without sending another message; it does not assert current delivery. Transport acceptance is not mailbox verification. An unknown delivery result leaves a non-completable pending record; a fresh initiation after the cooldown replaces it.

Functions own their database transaction and reject an already active caller transaction. They explicitly establish UTC, lock actor/target rows in ascending ID order, recheck state, and commit the data mutation, durable repeat receipt and success audit together. No successful mutation may outlive a failed success-audit insert. Domain rejections roll back the mutation. A resolved denied operation creates a separate denial audit; unknown principals fail closed without a claimed actor. SQL failures/deadlocks propagate after rollback; callers may retry the identical request key and payload after a transient failure.

The consuming HTTP surface owns POST, CSRF, confirmation and presentation. These services do not expose a general SQL editor or accept an arbitrary principal array. No Admin implementation directive is issued by this owner contribution.

## Authority and states

| Operation | PLATFORM_ADMIN | PLATFORM_SUPER_ADMIN |
| --- | --- | --- |
| Profile / end sessions | USER targets | USER and PLATFORM_ADMIN targets |
| Suspend / restore | Denied | USER and PLATFORM_ADMIN targets |
| Verified primary contact / replacement verification | Denied | USER and PLATFORM_ADMIN targets |

PLATFORM_SUPER_ADMIN targets are protected from **every** operation, including profile edits. DEACTIVATED and unknown-role targets are rejected. Profile edits, session ending and selection of an already verified primary contact support ACTIVE or SUSPENDED targets. New mailbox verification requires ACTIVE at initiation and completion. Suspension requires ACTIVE → SUSPENDED; restoration requires SUSPENDED → ACTIVE. A new request for an invalid transition fails; an identical successful request repeats safely.

Profile allowlist: `display_name`, `timezone`, `locale`. At least one field is required. Names are trimmed valid UTF-8, 1–120 characters, without control characters. Timezones must be recognized IANA/PHP identifiers. The shipped application has English UI and no translation catalogue: supported ordinary editable locale is `en`. Existing null/default or other stored locale values remain unchanged when locale is omitted; additional languages require an owner-supported language release. Unknown fields, identity/role/verification fields, invalid values and unsupported locales fail validation.

Ending sessions revokes all currently unrevoked durable records. Suspension changes status and revokes sessions atomically. The session-creation helper now locks the user and requires ACTIVE, so it serializes against suspension/end-session operations. Session resolution re-reads current state after touching a session and cannot return a record revoked during the lookup. Restoration never revives a record; it defensively revokes any remaining records and requires fresh authentication. A genuinely new authentication after an end-session operation may create a new session for an ACTIVE user. Normal self-service remains separate.

## Contact verification

Primary selection accepts only an existing, unremoved VERIFIED contact of the same canonical user. Unverified or foreign contact IDs fail. No field allows an administrator to assert arbitrary verification evidence.

Replacement initiation checks canonical verified ownership and existing authentication-email evidence, creates a hash-only 256-bit, 15-minute verification challenge bound to the same canonical target, initiating administrator, reason, target role and target revision, and records its success audit. It sends through the existing `fc_mail_send` provider-neutral service. There is a 60-second per-target initiation cooldown. A new initiation replaces previous pending/issued challenges.

The email points to `/auth/contact/confirm.php#token=...`. The fragment is not sent in the GET request. GET displays a confirmation page without consuming evidence; the page removes the fragment from browser history and has no third-party resources. An explicit checkbox and POST to `/auth/contact/complete.php` are required. The endpoint checks CSRF, supplied Origin, a strict field allowlist, and the existing network rate limiter.

On completion, Auth revalidates the initiating administrator's current ACTIVE Super Admin role, the target's ACTIVE non-Super role/revision, expiry, challenge status and canonical ownership. Mailbox completion authorizes only the bound contact proof; it does not depend on the recipient being signed in. It does **not** sign in, create/move an authentication identity, merge accounts or change the primary contact. A verified contact can subsequently be selected by the separately authorized primary-contact service. Retrying a consumed proof returns its completed result without another mutation or success audit.

A verified owner, EMAIL subject, or conflicting provider-email evidence for another canonical user yields `account_reconciliation_required` (**ACCOUNT RECONCILIATION REQUIRED**). No address is transferred, no owner is chosen, and no identity is moved. The unique verified-email constraint remains the final concurrent-write guard. Failed, pending-send, replaced, expired, stale-state and no-longer-authorized challenges cannot complete. Raw proof tokens exist only in Auth memory, the delivered link and protected confirmation POST; they are absent from operation results, database rows and audit metadata.

## Audit events

Success events:

- `USER_PROFILE_EDITED`
- `USER_SESSIONS_ENDED`
- `USER_ACCOUNT_SUSPENDED`
- `USER_ACCOUNT_RESTORED`
- `USER_PRIMARY_CONTACT_SELECTED`
- `USER_CONTACT_VERIFICATION_INITIATED`
- `USER_CONTACT_VERIFIED`

`USER_CONTACT_VERIFICATION_DELIVERY` records transport acceptance or failure. `USER_OPERATION_REJECTED` and `USER_CONTACT_VERIFICATION_REJECTED` record resolved rejected attempts. Events retain canonical actor/target, administrator reason, allowed before/after fields where applicable, affected session count or verification public ID, outcome and UTC occurrence time. Mailbox-completion records attribute the initiating administrative request and identify mailbox confirmation as the method. No session identifiers or raw contact-proof tokens enter metadata.

## Schema and ownership

`0530_auth_user_operations.sql` is the new forward-only Auth/Identity migration. It adds only `auth_user_operations` (durable repeat receipts) and `auth_contact_verifications` (hash-only mailbox proof state). Migration 0530 was unused on the synchronized baseline. Existing migrations, role checks, singleton index, users and audit history are unchanged by migration SQL. Use the normal migration runner before enabling consumers of these services. Existing authentication continues to use its current tables.

Intended owner boundary, equal to the actual contribution:

- `inc/auth/user_operations.php` — new
- `inc/auth/user_contact_verification.php` — new
- `inc/identity/session_records.php` — modified
- `auth/contact/confirm.php` — new
- `auth/contact/complete.php` — new
- `views/auth/contact_confirm.php` — new Auth view
- `database/migrations/0530_auth_user_operations.sql` — new Auth/Identity migration
- `tests/auth_user_operations_support.php` — new
- `tests/auth_user_operations_test.php` — new
- `tests/auth_user_operations_concurrency_test.php` — new
- `tests/auth_user_operations_http_test.php` — new
- `docs/auth_admin_2a_user_operations.md` — new

No Admin, Product, Website shared, Mail implementation, Security implementation, `.env` or deployment tooling file is changed. No genuine Mail or Product capability dependency was found. The existing Mail service and canonical uniqueness support the authorized flow.

## Proof

New tests require a disposable loopback database named exactly `fitcrew_auth_admin2a_test`, migrated through 0530. They refuse other targets and never load `.env`. Set `FC_AUTH_USER_OPS_TEST_DSN=mysql:host=127.0.0.1;port=PORT;dbname=fitcrew_auth_admin2a_test;charset=utf8mb4`, plus the matching test username/password environment variables if needed. The tests clear their fixture rows only in that guarded test schema. Never point an existing application connection at that name as a workaround.

Run `php tests/auth_user_operations_test.php`, `php tests/auth_user_operations_concurrency_test.php` (Linux/WSL pcntl), and `php tests/auth_user_operations_http_test.php`. The HTTP test additionally uses the normal DB environment variables directed to that same isolated schema and a disposable `AUTH_TRANSACTION_SECRET_KEY_B64` for the rate limiter. It exercises real HTTP endpoints and CSRF sessions; all email uses the log transport. No real mailbox is contacted.

Proof covers the permission matrix, prohibited fields/values, reason and revision contracts, replay without session re-revocation, preserved Super Admin fields/timestamps, session denial after suspension/revocation and restoration, canonical contact conflicts/selection, mailbox proof expiry/replacement/staleness, scanner-safe confirmation, Origin/CSRF/field rejection, atomic audit failure rollback, UTC, concurrent edits, actor demotion and session expiry during lock waits, and suspension versus new-session issuance.

The owner return includes exact regression receipts and runtime versions. Production browser/delivery proof and any later Admin surface integration are distinct release gates; this contribution is for develop only.

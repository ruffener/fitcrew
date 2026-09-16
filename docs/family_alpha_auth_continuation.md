# Family Alpha A — Auth Invitation Continuation Contract

Status: Auth PASS 2 implementation candidate

Purpose: `CREW_INVITATION_ALPHA_ENTRY_PROOF_V1`

Fixed destination: `CREW_INVITATION_ACCEPTANCE` → `/crew-invite.php`

This contract carries a Website-validated Crew invitation through Google or
EMAIL sign-in without making invitation email an identity claim. Auth never
receives or stores the raw invitation token or invited email, and Auth never
creates Crew membership.

## Migration 0310

Apply:

```text
database/migrations/0310_create_auth_invitation_continuations.sql
```

It creates three Auth-owned tables:

- `auth_invitation_continuations`: invitation public ID/generation, browser-binding evidence, Auth transaction/session/user bindings, state, and timestamps;
- `auth_invitation_admission_claims`: one database-enforced new-account admission claim per logical invitation public ID;
- `security_rate_limit_buckets`: keyed bucket evidence and counter/timing state.

The primary key of `auth_invitation_admission_claims` is `invitation_public_id`. Consequently, multiple continuations and resend generations for one Website invitation cannot create multiple FitCrew accounts. Auth inserts the claim after fresh Website `FOR UPDATE` validation and before user creation, within the same transaction as user, identity, session, and continuation completion. Rollback removes the claim and all account artifacts together.

## Accepted Website validator

Auth consumes the accepted Website callable from `inc/product/crew_invitations.php`:

```php
function fc_crew_invitation_auth_snapshot(
    PDO $pdo,
    string $invitationPublicId,
    int $expectedGeneration,
    bool $lockForAdmission = false
): ?array;
```

It returns `null` unless the invitation public ID and generation are current, `PENDING`, unexpired, uncancelled, and unaccepted. A valid result contains exactly:

```php
[
    'invitation_public_id' => $invitationPublicId,
    'generation' => $expectedGeneration,
    'expires_at' => 'YYYY-MM-DD HH:MM:SS.uuuuuu',
]
```

When `$lockForAdmission` is `true`, an active database transaction is mandatory and Website uses `SELECT ... FOR UPDATE`. The lock remains held until Auth commits or rolls back the complete new-account operation.

## Website → Auth continuation issue

Website calls this only after validating the raw invitation token and after completing any caller-owned SQL transaction:

```php
$result = fc_auth_crew_invitation_continuation_issue(
    $pdo,
    $invitationPublicId,
    $generation,
    $invitationExpiresAt
);
```

The callable requires that `$pdo` have no active transaction. Auth commits the continuation row before setting the PHP session pointer, preventing a pointer to a subsequently rolled-back row.

Return:

```php
[
    'public_id' => 'opaque Auth continuation ULID',
    'next_path' => '/login.php',
    'destination_key' => 'CREW_INVITATION_ACCEPTANCE',
    'expires_at' => 'Auth continuation expiration',
]
```

Auth creates browser binding internally. The continuation expires at the earlier of the invitation expiration and 30 minutes. Website redirects to `next_path` only after removing the raw invitation token from the browser URL.

## Google sign-in behavior

When the bound browser loads `/login.php`, Auth:

1. creates one `LOGIN` Auth transaction;
2. binds the continuation to that transaction;
3. fixes the destination to `/crew-invite.php`;
4. rejects reuse, expiry, or a different browser binding.

For an existing Google identity, Auth confirms Website still reports the invitation current. Existing FitCrew users do not require or consume the logical invitation's new-account admission authority.

For a new Google identity, the single transaction is:

```text
Website validator SELECT ... FOR UPDATE
→ Auth logical-invitation admission claim
→ user creation
→ Google issuer + subject identity creation
→ FitCrew server-side session creation
→ continuation authentication
→ admission-claim completion
→ COMMIT
```

A duplicate logical-invitation claim fails before user creation. Resend generation changes invalidate stale continuations but never create another admission slot. Provider email is not compared with invited email and cannot substitute for the continuation.

Outside this continuation path, the existing Google prelaunch allowlist remains enforced.

## EMAIL cross-browser handoff

EMAIL magic-link completion uses a short-lived, 256-bit, single-use bearer
credential. Unlike OAuth/OIDC providers, the original requesting-browser
binding is not an EMAIL completion predicate.

After the arrival browser renders the scanner-safe confirmation page and sends
its own CSRF-protected explicit POST, Auth locks the challenge, exact EMAIL
LOGIN transaction, and exact associated continuation. Within one transaction,
Auth freshly validates Website invitation state, resolves or creates the
authorized EMAIL identity, creates the FitCrew session, transfers the
continuation binding to the arrival browser/session, and consumes the challenge
and LOGIN transaction.

Only after the database commit does Auth place the opaque continuation public
ID into the arrival PHP session. The original browser may retain an obsolete
opaque pointer, but its database binding no longer matches and it cannot read
or consume the transferred continuation.

This EMAIL-specific handoff does not modify Google browser binding. The fixed
destination remains `/crew-invite.php`, and Website still performs explicit
invitation acceptance and Crew membership activation.

## Auth → Website result

On `/crew-invite.php`, Website reads:

```php
$continuation = fc_auth_crew_invitation_continuation_current($pdo);
```

Auth internally validates the current authenticated FitCrew user, server-side session, browser binding, continuation state, and expiry. Website receives only:

```php
[
    'invitation_public_id' => 'Website invitation ULID',
    'generation' => 0,
    'expires_at' => 'continuation expiration',
]
```

The result contains no continuation ID, user ID, or user-session database ID. It proves neither invitation acceptance nor membership authorization.

After Website performs fresh locked invitation validation and its idempotent membership decision, it consumes Auth continuation state inside the same caller-owned transaction:

```php
$consumed = fc_auth_crew_invitation_continuation_consume(
    $pdo,
    $invitationPublicId,
    $generation
);
```

The function derives and verifies the current canonical user/session internally. It changes only the Auth database row and deliberately does not clear PHP session state. If Website rolls back, the continuation remains usable. After a successful commit, Auth lazily clears the stale opaque pointer when a later read observes terminal database state.

An already-authenticated user is supported. Auth binds the current FitCrew user/session and returns to `/crew-invite.php` without creating a LOGIN transaction or consuming new-account admission.

## Generic rate-limit interface

The shared callable is:

```php
$decision = fc_rate_limit_consume(
    $pdo,
    $namespace,
    $subject,
    $maxAttempts,
    $windowSeconds,
    $blockSeconds = 0
);
```

Cross-lane result:

```php
[
    'allowed' => true,
    'remaining' => 4,
    'retry_after_seconds' => 0,
]
```

The primitive is a database-backed fixed-window limiter. It stores only keyed HMAC evidence. Website owns invitation-specific policies, subject composition, call sites, HTTP behavior, and audit semantics.

Auth maintenance helpers exist but are not part of the promoted Website contract:

```php
fc_rate_limit_clear(PDO $pdo, string $namespace, string $subject): bool;
fc_rate_limit_cleanup(PDO $pdo, int $retentionSeconds = 86400): int;
```

## Invariants

- Token possession never creates a FitCrew session or Crew membership.
- Invitation email never participates in identity resolution or account linking.
- Google identity remains issuer + subject.
- Raw invitation token and invited email never enter Auth storage, logs, audits, or continuation parameters.
- Cancellation, expiration, prior acceptance, or generation rotation invalidates stale admission.
- One logical invitation public ID admits at most one new account across every continuation and generation.
- A failed transaction leaves no admission claim, user, identity, or session.
- Auth does not modify `crew_invitations` or `crew_memberships`.
- Website does not control Auth browser binding, EMAIL arrival transfer,
  transactions, sessions, or admission claims.
- No arbitrary return URL is accepted.

## Proof commands

```powershell
php -l inc/auth/invitation_continuations.php
php -l inc/security/rate_limit.php
php -l inc/auth/google.php
php -l inc/identity/contracts.php
php -l login.php
php -l auth/google/credential.php
php -l tests/auth_invitation_continuation_unit_test.php
php -l tests/auth_invitation_continuation_foundation_test.php

php database/migrate.php

php tests/crew_invitation_auth_snapshot_test.php
php tests/auth_invitation_continuation_unit_test.php
php tests/auth_invitation_continuation_foundation_test.php
php tests/phase2a2_contract_unit_test.php
php tests/phase2a2_reconciliation_test.php
php tests/phase2a2_identity_foundation_test.php
php tests/phase2a3_google_auth_unit_test.php
php tests/phase2a3_google_auth_foundation_test.php
php tests/phase2a4_microsoft_auth_unit_test.php
php tests/phase2a4_microsoft_auth_foundation_test.php

git diff --check
```

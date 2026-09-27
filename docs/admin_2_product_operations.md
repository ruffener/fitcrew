# ADMIN-2B / ADMIN-2C — Product owner operations

This document is the Website/Product owner contribution consumed by the Admin console. It adds **services, not Admin pages**.

## Boundary

Website/Product owns Crew and Challenge product truth. Admin owns editor screens. Auth owns User/session/contact operations. The Product service consumes the authenticated session and stored platform role but does not create or change authentication identities.

Every mutation:

- derives the actor from the current FitCrew session;
- rechecks ACTIVE account + ACTIVE identity + unexpired/unrevoked session under lock;
- requires a 64-character snapshot revision;
- requires a 16–80 character idempotency request key;
- requires a non-empty administrator reason (maximum 500 characters);
- writes before/after audit metadata with the successful product mutation in one transaction;
- writes a durable `product_admin_operations` receipt so an exact replay returns the prior result and a changed replay key/digest fails;
- rechecks the privileged session after lock waits before committing success.

`PLATFORM_SUPER_ADMIN` accounts are not product targets here; platform roles are relevant only to the actor. User account operations remain Auth-owned ADMIN-2A.

## Public Crew contracts — ADMIN-2B

```php
fc_product_admin_crew_snapshot(PDO $pdo, string $crewPublicId): array
fc_product_admin_crew_edit(PDO $pdo, string $crewPublicId, array $fields, string $revision, string $requestKey, string $reason): array
fc_product_admin_crew_transfer_owner(PDO $pdo, string $crewPublicId, string $newOwnerPublicId, string $revision, string $requestKey, string $reason): array
fc_product_admin_crew_archive(PDO $pdo, string $crewPublicId, string $revision, string $requestKey, string $reason): array
fc_product_admin_crew_restore(PDO $pdo, string $crewPublicId, string $revision, string $requestKey, string $reason): array
fc_product_admin_crew_member_remove(PDO $pdo, string $crewPublicId, string $memberPublicId, string $revision, string $requestKey, string $reason): array
```

### Crew permission matrix

| Operation | PLATFORM_ADMIN | PLATFORM_SUPER_ADMIN |
|---|---:|---:|
| Read governed editor snapshot | Yes | Yes |
| Edit `display_name`, `description` | Yes | Yes |
| Transfer ownership | No | Yes |
| Archive / restore | No | Yes |
| Remove active Crew member | No | Yes |

Ownership transfer requires the new owner to be an **ACTIVE existing Crew member**. It never manufactures membership. The old owner remains an active MEMBER. The Crew's current/non-terminal Challenge, if one exists through `crew_current_challenges`, follows the new Crew Owner so current management authority remains coherent. Historical Challenge owner attribution is not rewritten.

Crew archive fails while the Crew has a current Challenge. Restore preserves all Crew/Challenge history.

Removing a Crew member is history-preserving: membership becomes `REMOVED`; active Challenge participations in that Crew become `REMOVED`; active participation intervals close; pending in-app participant offers are cancelled; selected context is cleared. The Crew Owner cannot be removed.

There is intentionally **no Admin direct-add/reactivate membership operation**. Explicit invitation/acceptance remains authoritative.

## Public Challenge contracts — ADMIN-2C

```php
fc_product_admin_challenge_snapshot(PDO $pdo, string $challengePublicId): array
fc_product_admin_challenge_edit_name(PDO $pdo, string $challengePublicId, string $displayName, string $revision, string $requestKey, string $reason): array
fc_product_admin_challenge_rule_draft_edit(PDO $pdo, string $challengePublicId, array $fields, string $revision, string $requestKey, string $reason): array
fc_product_admin_challenge_participant_remove(PDO $pdo, string $challengePublicId, string $participantPublicId, string $revision, string $requestKey, string $reason): array
fc_product_admin_challenge_end(PDO $pdo, string $challengePublicId, ?string $endReason, string $revision, string $requestKey, string $reason): array
fc_product_admin_challenge_archive(PDO $pdo, string $challengePublicId, string $revision, string $requestKey, string $reason): array
fc_product_admin_challenge_unarchive(PDO $pdo, string $challengePublicId, string $revision, string $requestKey, string $reason): array
```

### Challenge permission matrix

| Operation | PLATFORM_ADMIN | PLATFORM_SUPER_ADMIN |
|---|---:|---:|
| Read governed editor snapshot | Yes | Yes |
| Name / Rule-draft edit while DRAFT or FORMING_CREW | Yes | Yes |
| Name / Rule-draft correction after launch | No | Yes |
| Remove active participant | No | Yes |
| End / archive / unarchive | No | Yes |

COMPLETED is terminal history. Completed Challenges permit only noncompetitive name correction and archive. `unarchive` restores current-Challenge authority, so it is neither advertised nor permitted for a COMPLETED Challenge. Showing archived completed history again would require a separate presentation operation; this service does not provide one. Competitive Rule settings remain locked after completion.

Supported Rule-draft fields are:

- `planned_start_date`;
- `planned_end_date` or `duration_days`;
- `challenge_timezone`;
- `weekly_checkin_day`;
- `live_leaderboard_visible`.

Published Rule rows are **never edited**. If an edit is requested when no draft exists, the service creates a new DRAFT version superseding the current PUBLISHED version, copies the published settings, and applies the requested correction to the draft. The published version and all participant acceptance records remain unchanged. This contribution does **not** silently publish the draft or manufacture participant re-acceptance.

Removing a Challenge participant preserves Crew membership and Challenge history. There is intentionally **no Admin direct-add/reactivate participant operation**; the governed Challenge invitation / explicit `ACCEPT CHALLENGE` path remains authoritative.

`end`, `archive`, and `unarchive` use the existing history-preserving `challenge_owner_controls` / current-Challenge-pointer semantics. They do not directly rewrite canonical Journey lifecycle codes. Direct arbitrary lifecycle-status editing is not part of ADMIN-2C.

## Snapshot/revision shape

Crew snapshots return Crew profile truth, membership rows, current-Challenge reference, allowed operations and one revision hash.

Challenge snapshots return Challenge profile truth, owner controls, current DRAFT/PUBLISHED Rule versions, participation rows, allowed operations and one revision hash.

Admin must submit the returned revision unchanged with a mutation. A concurrent change produces `stale_crew_state` or `stale_challenge_state` and Admin must reload.

## Audit / idempotency

Successful operations use specific `ADMIN_*` audit event names and include:

- administrator actor;
- target Crew/Challenge;
- administrator reason;
- before state;
- after state;
- operation-specific nonsecret metadata.

Denied/failed governed operations write best-effort `PRODUCT_ADMIN_OPERATION_REJECTED` audit evidence without replacing the original failure if denial-audit storage itself fails.

`0540_product_admin_operations.sql` stores only idempotency receipts. It does not duplicate Crew, Challenge, membership, participation, Rule, invitation or audit truth.

Crew mutations begin a transaction, resolve the canonical current actor, lock the Crew, and verify current platform-role authority for the operation class **before** looking up the actor/request-key receipt. An exact digest match returns the original successful result with `replayed=true`, without another domain mutation or success audit. A changed target, operation, payload, revision or reason under that key fails `idempotency_conflict`. Current mutable transition eligibility and revision checks apply only when no receipt exists. Archive and restore therefore replay successfully after their original transitions, while actor demotion, suspension, identity revocation, session revocation or expiry still denies replay. The session is rechecked after receipt lock waits as well.

## Product gate regression proof

`tests/product_admin_operations_contract_test.php` checks the public contract and completed-Challenge allowed operations without a database.

`tests/product_admin_operations_foundation_test.php` runs actual owner services against a **fresh, empty, fully migrated disposable** `fitcrew_product_admin_test` database on loopback. Install the locked Composer dependencies and apply the existing migrations through 0540 with the normal migration runner to that dedicated database. No new migration is needed. The test never loads `.env`, deletes existing rows, or uses the application connection. Configure only its process environment:

```text
FC_PRODUCT_ADMIN_TEST_DSN=mysql:host=127.0.0.1;port=<local-port>;dbname=fitcrew_product_admin_test;charset=utf8mb4
FC_PRODUCT_ADMIN_TEST_USER=<disposable-db-user>
FC_PRODUCT_ADMIN_TEST_PASSWORD=<disposable-db-password>
php tests/product_admin_operations_foundation_test.php
```

The test rejects an unexpected DSN/database or an existing user population before fixture writes. It leaves synthetic committed evidence in the disposable database because these services own their transactions; recreate only that test database before another run. Proof covers archive/restore exact replay with byte-equivalent domain rows, unchanged receipt/audit rows, changed-digest conflict, current authority loss, stale/new requests, every Crew/Challenge mutation class's receipt replay, ownership/history boundaries, and terminal Challenge operation/runtime agreement.

`tests/family_alpha_foundation_test.php` remains the rollback-based protected Product regression. After the all-lifecycle invitation loop it explicitly establishes FORMING_CREW and current-Challenge authority for ordinary archive/unarchive, then separately proves a COMPLETED historical fixture can be archived but cannot return as current. The existing `fc_challenge_manage` and `fc_crew_current_challenge_restore` runtime implementations are unchanged.

## Explicit exclusions

This owner contribution does **not**:

- create Admin screens;
- edit Admin-owned files;
- edit Auth/Identity/Security-owned files;
- change User/profile/session/contact behavior from ADMIN-2A;
- directly add/reactivate Crew membership or Challenge participation;
- bypass invitation acceptance;
- overwrite a PUBLISHED Rule Version;
- silently publish an Admin correction draft;
- invent a new lifecycle engine or arbitrary lifecycle-status setter;
- change scoring, Health, privacy, Challenge Monies or billing;
- modify `.env`, deployment tooling or subscription entitlement.

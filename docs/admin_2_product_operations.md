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

Completed Challenges permit only noncompetitive name correction and archive/unarchive presentation controls. Competitive Rule settings are locked from this service after completion.

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

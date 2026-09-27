# ADMIN-2 console integration candidate

Status: **Reconciled; complete integrated automated proof PASS. Main promotion requires Governance review.**

Current source baseline: `8315f57dddf7ce55d33bd6f56c5f7ff2effa81ca`.
Preserved Admin candidate: `7f47966548da18fcf7483477fd0635363f5d4d27`.
Auth service authority: `bc1b6ac085a6a535f0a370ac59572f380affd296`.
Product schema/service authority: `e56d0b7f4c36d61cc6f0573ddb300f046ae25218` / `e9cfd90097f7c515229f8fe3216abc3ae3201f1a`.
Accepted Product corrections: `a4ed16494aa3e2ab4d74cd56c95fb9b83ae120ec`.
Accepted PASS 3 fixture maintenance: `10ff1d98e59d115623ba02443f7bd2ea04838587`.
Accepted account-switch proof maintenance: `8315f57dddf7ce55d33bd6f56c5f7ff2effa81ca`.

## Boundary and persistence

Only Admin routes, orchestration, views, CSS, Admin tests and this document change.
The accepted ADMIN-1 role mutation remains separate and unchanged. No new migration,
Auth/Identity/Product/Mail/Security/Website edit, environment edit or deployment edit.
Owners 0530 and 0540 are required. Use the normal migration runner on an isolated
proof database; rerun and require no pending migrations. The supplied older SQL is
not proof of these migrations or production readiness.

## Routes and permissions

`/admin/user.php` and `/admin/crew.php` gain operation links beside their detail cards.
`/admin/challenges.php` provides searchable, paginated listing;
`/admin/challenge.php` shows lifecycle, owner controls, draft/published Rules,
participants and audit history. Crew Challenge rows link to this detail.
`/admin/operation.php` provides the shared edit → review → explicit confirmation
→ owner operation → safe receipt flow.

| Operation | PLATFORM_ADMIN | PLATFORM_SUPER_ADMIN |
|---|---|---|
| User profile / end sessions | USER targets | USER / PLATFORM_ADMIN targets |
| Suspend / restore / contacts | Denied | USER / PLATFORM_ADMIN targets |
| Any ADMIN-2 operation targeting a Super Admin user | Denied | Denied |
| Crew name / description | Allowed | Allowed |
| Crew transfer / remove member / archive / restore | Denied | Product eligibility |
| Challenge name / draft Rules before launch | Product DRAFT / FORMING_CREW | Product eligibility |
| Challenge name / draft Rules after launch | Denied | Product eligibility |
| Participant removal / end / archive / unarchive | Denied | Product eligibility |
| Completed Challenge competitive settings | Denied | Denied |
| Completed name correction / archive presentation | Denied | Product eligibility; unarchive is not an allowed operation |

USER cannot enter Admin. An ordinary Admin has no Admins navigation. UI visibility
is presentation only; owner services resolve the actor from the live authenticated
session and revalidate current authority, target state and revisions under lock.

## Owner call map

| Console action | Accepted service |
|---|---|
| User snapshot | `fc_auth_user_operations_snapshot` |
| Profile | `fc_auth_user_profile_edit` |
| End sessions | `fc_auth_user_sessions_end` |
| Suspend / restore | `fc_auth_user_suspend` / `fc_auth_user_restore` |
| Primary verified contact | `fc_auth_user_primary_contact_select` |
| Replacement verification | `fc_auth_user_replacement_contact_initiate` |
| Crew snapshot / profile | `fc_product_admin_crew_snapshot` / `fc_product_admin_crew_edit` |
| Transfer owner | `fc_product_admin_crew_transfer_owner` |
| Remove member | `fc_product_admin_crew_member_remove` |
| Archive / restore Crew | `fc_product_admin_crew_archive` / `fc_product_admin_crew_restore` |
| Challenge snapshot / name | `fc_product_admin_challenge_snapshot` / `fc_product_admin_challenge_edit_name` |
| Draft Rules | `fc_product_admin_challenge_rule_draft_edit` |
| Remove participant | `fc_product_admin_challenge_participant_remove` |
| End / archive / unarchive | `fc_product_admin_challenge_end` / `fc_product_admin_challenge_archive` / `fc_product_admin_challenge_unarchive` |

## User and contact behavior

Editable profile fields are display name, IANA timezone and the owner's supported
locale list (currently English). No provider, identifier, role, timestamp or evidence
editor exists. End Sessions revokes durable sessions; Suspend also blocks access.
Restore never revives old sessions. A fresh normal sign-in is required.

Primary contact choices are verified contacts already owned by this user.
Replacement verification uses Auth and the existing provider-neutral Mail service.
Mailbox confirmation stays at the Auth endpoint; Admin never receives its bearer
token and never marks an arbitrary email verified. Verification does not silently
switch the primary address. Contact conflicts show ACCOUNT RECONCILIATION REQUIRED;
there is no merge, address transfer or identity reassignment operation.

## Crew and Challenge effects

Crew transfer offers active existing members, labels them with public IDs and
shows the previous owner. Product updates the current Challenge's management
owner; historical Challenge ownership is retained. The previous owner remains a
member. The explicit removal confirmation states that Product atomically removes
Crew membership and active Challenge participation in that Crew, closes intervals,
cancels pending participant offers and clears selected context. The Crew Owner
cannot be removed. A current Challenge blocks Crew archive.

Challenge draft editing offers planned start date, duration days, timezone,
weekly check-in day and live leaderboard visibility. Duration is the supplied
alternative to an explicit planned end date. Existing published Rules are never
edited, published automatically or silently accepted for participants. The owner
creates a superseding correction draft when necessary. Completed competitive
truth is protected. No score, rank, Champion, formula or raw scoring fields are
editable. End/archive operate only on Product controls and current pointers;
there is no arbitrary lifecycle dropdown or direct lifecycle write.

There are no direct-add/reactivate Crew membership or Challenge participation
capabilities. Invitation and explicit acceptance remain authoritative.

## Review, errors, receipts and audit

A server-side session record holds the owner snapshot/revision, requested values,
reason and request key. Opaque form handles bind to actor and durable session.
Revision hashes and owner request keys are not rendered. Edit and execution POSTs
both require CSRF. Field allowlists reject crafted extra inputs. The second POST
accepts only the confirmation handle/checkbox; it cannot replace reviewed fields.
Review does not mutate domain data. Cancel is always a normal reachable link.

A reason and explicit confirmation are mandatory. Successful POST redirects to a
receipt. Exact retries call the same owner service with the original revision,
reason, payload and request key; Admin does not invent a second receipt/revision
system. Owners alone write mutation audits atomically. Admin access audits remain
separate. Receipts project audit ID, replay status and allowed operation-specific
counts or references, never arbitrary owner arrays or raw audit metadata.

Stale state/idempotency conflicts return 409 with a review-current-values message.
Authorization returns safe 403. Known Product blocking reasons and contact conflicts
are explicitly mapped. Invalid fields return 400; unknown failures are generic
503. SQL errors, exception text, secrets, provider subjects and raw tokens are not
rendered. User-supplied output is escaped.

Existing UI Foundation card/button/control/spacing/focus tokens are reused. Tables
retain their own scroll containers; the skip-link target is focusable. No client
script or new design system is introduced. Manual browser visual/zoom proof and
production proof are not claimed by the automated checks.

## Reproducible proof

Tests require loopback-only dedicated `fitcrew_admin2_test`; they clear only this
explicit disposable schema. The test support loads existing test helpers but never
loads production credentials or targets a production database. Test fixture email
addresses use `example.test`. Verification delivery is the log driver.

```
php database/migrate.php
php database/migrate.php
php tests/admin_2_users_test.php
php tests/admin_2_crews_test.php
php tests/admin_2_challenges_test.php
php tests/admin_2_http_test.php
php tests/admin_2_boundary_test.php
```

Set `FC_ADMIN2_TEST_DSN=mysql:host=127.0.0.1;port=PORT;dbname=fitcrew_admin2_test;charset=utf8mb4`
and optional `FC_ADMIN2_TEST_USER` / `FC_ADMIN2_TEST_PASSWORD`. Migration-runner
configuration must independently allow only that disposable environment/host/schema.
Existing Auth owner tests use their separately guarded `fitcrew_auth_admin2a_test`.
ADMIN-1 tests use `fitcrew_admin1_test`. Run the owner and existing UI/Journey suites
without changing protected test files. Concurrency proof requires CLI pcntl.

## Reconciliation and closed Product gates

The existing 22-file Admin boundary is retained: 15 additions, 7 modifications,
zero deletions. All Admin runtime, UI and orchestration files are byte-preserved
from candidate 7f479665. No implementation was rebuilt or compensated.

The only candidate variances are this status/contract documentation and
`tests/admin_2_challenges_test.php`: the corrected Product contract now rejects a
completed Challenge unarchive as `challenge_operation_denied` at its allowed-operation
gate, rather than the former later `challenge_cannot_be_restored` guard. The Admin
proof expects that result and explicitly verifies no advertised restore action or
current-Challenge authority. The terminal invariant is unchanged.

Governance closed both prior HOLD gates through Product-owned changes. Crew archive
and restore exact retries now return the original receipt after current actor
checks and before new-request state eligibility. No mutation or duplicate success
audit is reapplied. Completed Challenges remain terminal. The Family Alpha fixture
establishes a non-terminal state before ordinary archive/unarchive checks. The
PASS 3 fixture separately proves issuance/proof commit before external transport;
delivery in an active caller transaction remains rejected.

Governance closed the final protected invitation account-switch fixture gate in
`8315f57`. The accepted public flow remains a CSRF-protected POST with
`action=use_different_account` to `/crew-invite.php`, Website revalidation and
continuation issuance/binding, followed by the protected POST handoff to
`/auth/invitation/switch-account.php`. Auth retires the old session/continuation,
commits the fresh anonymous continuation, then replaces browser authority and
redirects to `/login.php`. Admin does not alter this flow or its protected proof.

The complete integrated run on this baseline passed all 54 test commands, including
ADMIN-2, ADMIN-1, Auth 0530, Product 0540, the corrected account-switch fixture and
the existing Journey/Identity/UI regressions. Six fresh isolated databases each
passed the normal migration runner through 0530/0540 and a second run reporting no
pending migrations (12 migration commands). All 20 candidate PHP files passed
syntax checks. The exact ownership boundary and whitespace checks passed.

No additional Admin schema is required. Commit/push uses only the accepted 22-file
Admin boundary. Main remains held for Governance review; automated proof does
not constitute production browser/runtime acceptance.

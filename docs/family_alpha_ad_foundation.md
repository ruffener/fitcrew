# Family Alpha Core — B–D implementation candidate

Source: `fitcrew-20260911-194159.zip` + matching SQL, 11 September 2026.
Authority: Governance's Family Alpha Core Product Contract, accepted with corrections.

## Scope and status

| Lane | This candidate |
|---|---|
| A. Crew email invitations | Requires Auth's executable invitation/post-auth binding and delivery contract. No email-token implementation, email sending, or email-based membership activation is included. |
| B. Flexible participation | Implemented candidate; local MariaDB and authenticated production proof required. |
| C. Challenge management | Implemented candidate; local MariaDB and authenticated production proof required. |
| D. Participant privacy foundation | Personal category preferences, consent receipts, audience policy and safe current roster projection implemented. No Health or scoring data feed exists in this slice. |
| E. Measurements/scoring | Held. No manual competitive measurements, official_daily_logs, provider imports, scoring calculations, rank, Champion, Monies or Recognition runtime. |

Website may build B–D without inventing A's Auth contract. The internal in-app participant offer is NOT a Crew email invitation. It goes only to someone already in the Crew and sends no email. Normal Member-ID Crew enrollment has been retired; the Crew page honestly states that email invitations are not available yet. Do not call A complete.

## Product behavior

### Owner management

`challenge-manage.php` supports name editing; links to the existing versioned Rules editor; and End, Archive, Unarchive and Delete confirmations. Every submitted form names the exact Challenge and carries CSRF protection. Management revisions reject stale changes. Owner authority is checked server-side; a platform role is not a substitute.

End records its first effective UTC timestamp, actor and optional reason. It does not set COMPLETED, choose a winner, finalize scores, or invent a results-review decision. Archive is reversible and independent of End. Delete removes a Challenge from ordinary active lists while preserving required history and personal access. This UI uses history-preserving deletion even for pristine Drafts. The pre-existing physical-Draft-delete service remains guarded against participation, Rules and the new history/consent records; it is not the normal Delete action.

The Challenge list has an explicit history view. Ended/archived/deleted state is displayed separately from the competitive lifecycle. Personal history is discoverable even after withdrawal or Crew removal. Archive/Delete never disconnect Health or delete accounts.

### Personal participation

The Owner can initiate an in-app offer to a current Crew member at any lifecycle stage. An offer stays PENDING and grants neither full Challenge access nor roster access. The recipient opens a minimal personal-review page. A published Rule Version, explicit current-user acceptance and personal visibility choices are required before new participation becomes ACTIVE. The Owner cannot submit acceptance for a target user. The exact Rule Version and current offer ID are checked again in the transaction; stale published-rule or replaced/cancelled offer forms are rejected.

A Draft can receive offers, but acceptance waits for published Rules. This is a consent prerequisite, not a late-entry lock. The entry label records whether acceptance occurred before/after the planned start in the Challenge timezone. It is NOT certification of scoring eligibility.

An active participant may withdraw after ending, archiving or deletion from active use. Withdrawal leaves Crew membership and account-level Health alone. A rejoin appends a new interval and acceptance receipt rather than erasing earlier participation. Owner removal ends active participation; a fresh pending offer is required to rejoin after removal. Decline and Cancel never fabricate participation.

Earlier active participants stay as recorded. The migration preserves the currently recorded legacy interval but does not invent missing earlier intervals, historical acceptance, or privacy consent. Existing participants can record a genuine acceptance now. Later publication preserves prior receipts and prompts personal review; no downstream eligibility consequence is invented.

### Privacy

Four categories implement the accepted separation:

* Competition Results: required Challenge-visible participation output, not broad Crew visibility.
* Official Measurements: PRIVATE default; participant choice between PRIVATE and approved CHALLENGE sharing.
* Personal Progress: PRIVATE default; participant choice between PRIVATE and approved CHALLENGE sharing.
* Raw Health / Provider Data: never ordinary Challenge-visible; no sharing toggle.

A participant can save sharing choices independently of accepting revised Rules or rejoining. Privacy writes use only the authenticated actor's ID; posted target-user fields cannot change another person's settings. Owners receive no override. Choices neither authorize provider collection nor create missing measurements/results.

`fc_challenge_disclosure_policy` defines the server-side category policy for later authorized readers. Current participant cards use an explicit field allowlist: public user ID, display name, participation status, entry timing. The ordinary model returns no private health fields at all. Crew-only users and pending invitees cannot read it; withdrawn users retain personal access, not current roster visibility. Later Health/Scoring readers must enforce this policy before selecting/serializing data. That later integration is not proven by this patch.

## Data and transaction design

One additive migration, `0300_family_alpha_relationships.sql`, creates six tables:

1. `challenge_owner_controls` — independent Owner-management timestamps/actors/revision.
2. `challenge_product_events` — append-only application event writes.
3. `challenge_participant_offers` — current in-app offer state, not an email token.
4. `challenge_acceptance_records` — immutable personal acceptance receipts.
5. `challenge_participation_intervals` — preserved intervals, including truthful legacy backfill.
6. `challenge_privacy_preferences` — current self-owned category choices.

No existing migration checksum is changed. Foreign keys retain governed references. Product writers lock Crew before Challenge, then use current reads for mutable decisions. Nested product operations use savepoints so a failed nested action cannot leave a partially successful change in a caller-owned transaction. This is a locking design, not a claim that multi-process stress testing has passed.

## Required proof

Run the existing Wave 1 core/boundary/HTTP tests and affected Auth regressions. New tests:

* `family_alpha_contract_test.php` — pure/static boundary checks.
* `family_alpha_foundation_test.php` — local MariaDB transaction-backed cases; rolls back fixtures.

The database test covers pending/nonmember/owner negative access, consent prerequisites, old-offer and stale-rule rejection, idempotent acceptance/withdrawal, independent category privacy, original joined_at and interval preservation, management without finalization, personal access after Crew removal, and safe deletion. Test-only fixtures use an internal membership helper; this does not authorize Member-ID browser enrollment.

## Rollout: schema before runtime

Deployment v2 transports files; the supplied workflow does not apply database migrations. Do NOT push this runtime to production before migration 0300 succeeds.

Use two ordinary Git commits: migration only, then runtime/tests/docs. Push develop. Promote the schema-only commit to main first and let automatic Deployment v2 complete. Pause browser writes during the short upgrade window. Preserve and verify a current production backup using the accepted backup process. Run the existing production migration runner, then rerun it for no-pending/checksum proof. Only then fast-forward main to the already-proven runtime commit. Deployment v2 remains the only code transport.

Do not import the supplied empty SQL snapshot into production. Do not use a destructive DB reset for this additive patch. If migration fails or is partial, stop before runtime promotion and retain backup/error evidence for controlled recovery. The runner is not being represented as an automatic transactional rollback of MySQL DDL.

## Production browser acceptance

Use real authenticated accounts on fitcrewchallenge.com, not an offline render fixture:

1. Owner invites an existing Crew member: Pending list grows, active participant count does not.
2. Invitee opens personal review: no full roster; accepting current published Rules activates only that user.
3. Cancel/reinvite and publish-new-rules stale tabs: old acceptance fails safely.
4. Participant declines; Owner reinvites; no automatic acceptance.
5. Participant changes privacy before/after new Rules: no forced consent and no Owner override.
6. End, Archive/Unarchive and Delete: confirm/cancel modals work; historical Rule/participation records survive; no score finalization claimed.
7. Participant withdraws after End/Delete, and can find personal history after Crew removal.
8. Another Crew-only account and guessed public IDs cannot see active Challenge detail/private fields.
9. Phone/touch, keyboard focus/Escape, dialog close/cancel, cross-tab target binding.
10. Final exact deployment revision, migration ledger, local proof, production observations, synchronized Git state and A dependency go to Governance.

Do not mark A–D production complete until the actual applicable proof and Auth integration return exist.


## Crew invitation + transactional mail

Crew membership onboarding uses an email invitation as a delivery mechanism, not as identity truth. The Website-owned `crew_invitations` record stores only a SHA-256 token hash. Pending invitation does not grant membership. An authenticated FitCrew user explicitly accepts the invitation before membership becomes active.

Transactional mail is provider-neutral under `inc/mail/**`. `MAIL_DRIVER=log` remains the safe default. `MAIL_DRIVER=postmark` uses `POSTMARK_SERVER_TOKEN` from environment configuration only; the token is never committed or stored in application data. Crew invitations use `hello@fitcrewchallenge.com`, with `{Inviter First Name} via FitCrew Challenge` as the sender display name, and include both HTML and plain-text representations. Tracking is disabled.

The current Auth destination allowlist does not contain an invitation-aware post-auth continuation. Website does not modify Auth-owned files. An unauthenticated recipient may open the invitation and sign in, then return to the invitation link to explicitly accept. A seamless post-auth return remains an Auth-owned interface enhancement.

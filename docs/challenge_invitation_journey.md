# Challenge Invitation Journey

## Product contract

The normal invitation targets one exact current Challenge and its Crew. One Crew may retain many historical Challenges, but the database-backed `crew_current_challenges` authority permits at most one current Challenge at a time.

The Owner supplies only a recipient email destination. Account-presence classification is obtained privately from Auth and is used only to choose recipient-facing email copy. It is never shown to the Owner and is never identity authority.

## Recipient journey

1. The email opens a bearer-token URL.
2. Website validates the token, sends `Referrer-Policy: no-referrer`, stores only invitation public ID + generation in server-side review state, and redirects to clean `/crew-invite.php`.
3. The recipient reviews invitation-safe Challenge details before authentication. Opening/reviewing creates no membership, participation, authentication or health authorization.
4. `Accept Challenge` is the one explicit product acceptance. It creates a server-side one-time acceptance intent bound to the exact current Rule Version, consent contract and participant privacy choices.
5. If signed out, Auth continuation handles the chosen authentication method and returns the canonical FitCrew user. The acceptance intent remains Website state; no sensitive acceptance data travels through Auth or a caller-editable URL.
6. If signed in, the page shows the canonical FitCrew account and supports the Auth-owned account-switch path.
7. Website revalidates the current invitation generation, current-Challenge authority, Rules/consent and privacy choices. Material changes require review and a new `Accept Challenge` decision.
8. Final enrollment is one Website-owned transaction: Crew membership, Challenge acceptance/participation, privacy, invitation consumption, acceptance-intent consumption, applicable Auth-continuation consumption and selected Crew/Challenge context commit or roll back together.

Health authorization and scoring eligibility remain separate later steps.

## Legacy truth

Historical Crew-only invitation rows remain Crew-only. In particular, a legacy pending Crew-only invitation cannot display Challenge consent or complete the new Challenge-scoped enrollment journey. Owners must cancel it and issue a new Challenge invitation.

## Current-Challenge authority

`crew_current_challenges` is the canonical current authority. Challenge creation locks the Crew and fails if a current pointer exists. Ending/archiving/deleting the current Challenge releases that authority under the existing Owner-management contract; restoring an eligible archived Challenge is permitted only if no other current Challenge exists. Historical Challenge rows are never overwritten or deleted merely to make another Challenge current.

## UI Foundation v1

The public Challenge review is the first new Website surface built on the semantic FitCrew UI Foundation v1 roles in `assets/css/app.css`: shared typography roles, spacing, card/form/notice primitives, control sizing and visible keyboard focus. This pass does not broadly restyle existing application/Auth surfaces.

## Fresh production-data preflight (2026-09-18 baseline)

The accepted synchronized SQL snapshot was reviewed before the 0500 design:

- Crew 5 is the only Crew in the snapshot.
- Challenge 5 (`FORMING_CREW`) is the only non-finalized/current Challenge for that Crew; no multi-current conflict exists.
- The existing owner membership (user 144) already has active participation in Challenge 5, so the snapshot contains no current-Crew member lacking participation in the current Challenge.
- Six pre-0500 Crew-only invitation rows exist: historical accepted/cancelled rows plus one PENDING legacy row. None is assigned to a Challenge by 0500.
- No historical Challenge row is deleted or reassigned by the migration.

The first post-deployment production proof must use a newly-created Challenge-scoped invitation, not any legacy Crew-only row.

## Invitation EMAIL authentication simplification (September 19, 2026)

The Challenge invitation email is now the default EMAIL authentication proof for
that invitation generation. Fresh issuance/resend registers the same high-entropy
invitation bearer with Auth before Postmark transport and sends it only in a URL
fragment (`/crew-invite.php#token=...`).

Opening the link does not authenticate, create an account, consume the invitation,
or enroll anyone. The browser removes the fragment from history before normal
resources load and exchanges it through a protected same-origin POST. The clean
Challenge review identifies the recipient email.

The participant still makes exactly one product decision: **Accept Challenge**.
On that POST, Website preserves the exact acceptance intent and, inside the final
caller-owned enrollment transaction, asks Auth to resolve/create the canonical
EMAIL account and establish the session. Website enrolls the exact `user_id`
returned by Auth, commits Crew membership + Challenge participation + consent +
selected context atomically, and then redirects to `/app.php` (Overview).

If the email is new, Auth returns `invitation_email_profile_required`; Website
collects only the required display name and resumes the same already accepted
intent without a second Challenge acceptance. If the participant already has a
FitCrew account under another email, the explicit ordinary account-switch path
remains available through the existing Auth continuation contract.

Existing pre-integration invitation links are not silently upgraded into EMAIL
login credentials. The Crew Owner must resend/create a fresh invitation generation.

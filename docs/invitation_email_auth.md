# Challenge invitation EMAIL authentication — Auth interface

Baseline: `b7fc025605e7afa4e0db1373e3046af06bfc41ea` (fresh source/SQL supplied September 19, 2026).

This is the bounded Auth dependency requested by Website. It is implemented but
is not wired into Website controllers or deployed. Website remains the integration
owner. No Website file or product state writer is changed by this Auth patch.

## Security and identity contract

A NEW invitation email can carry EMAIL authentication proof. Merely opening or
previewing it does not authenticate, create an account, consume an invitation,
accept terms, create membership/participation, or grant health authorization.
The explicit Accept POST may redeem the proof. Existing verified canonical email
ownership resolves the existing user; descriptive provider email never transfers
ownership or merges users. Inactive users or revoked EMAIL identities are denied.

Migration `0510_invitation_email_auth_proofs.sql` records Auth-only issuance and
single consumption. It intentionally backfills NO old invitation. Website must
create/resend an invitation using the new mail composition integration below.
Registration is permitted only before sending a fresh generation (`PENDING_SEND`,
no `sent_at`). Failed/log-mode delivery cannot authenticate. Postmark remains the
transport; no new mail/configuration/provider is introduced.

The Auth token is the high-entropy invitation bearer, explicitly registered for
this purpose. The inviter must NEVER receive the recipient's URL, message body,
raw token, or a preview containing it. Do not add a Copy Invitation Link control.
The returned URL uses a fragment so the credential stays out of request URLs and
access logs. Do not convert it to a `?token=` link or enable email link tracking.
Raw proof is not persisted in the DB, server session, logs, or normal navigation.

## Website integration — exact calls

Load `inc/auth/invitation_email.php` after the standard bootstrap. All calls are
server-side PHP functions, not public account-lookup APIs.

1. **Compose the one invitation email** after create/resend returns the new token:

```php
$recipientUrl = fc_auth_invitation_email_register(
    $pdo, $invitation['public_id'], $invitation['generation'], $invitation['token']
);
```

Use this URL in that SAME invitation email, through existing Postmark transport.
Registration participates in a caller transaction, or commits its own when called
outside one. Send only after both issuance and registration commit. Preserve
Website's transport success/failure recording. The service sends no email.

2. **Open/review:** `/crew-invite.php#token=...` should serve a first-party landing
with `Cache-Control: no-store` and a fresh CSRF token. Read the fragment and
immediately remove it with `history.replaceState`, before loading other resources.
Exchange it in the POST body to the same-origin Website landing handler:

```php
$proof = fc_auth_invitation_email_capture($pdo, $rawToken, $csrfToken);
```

The handler validates its capture action, uses the returned invitation public ID
and generation to establish Website's review, then redirects to clean
`/crew-invite.php`. Capture itself requires POST, CSRF, and permitted Origin;
GET and query-token capture are refused. Capture performs no SQL writes. A mail
scanner may preview/exchange the token without consuming anyone's proof.

On the clean review page use `fc_auth_invitation_email_context($pdo)` to obtain
`proof_public_id`, `invitation_public_id`, `generation`, and recipient `email`.
Only a browser that presented the actual registered bearer has this context;
public ID/generation alone is insufficient. The opaque proof ID may be carried
in the Accept form; the private session receipt must never be serialized there.
Show clearly **Accept Challenge as [recipient email]**. Use `Referrer-Policy:
same-origin` on the clean first-party review/form so the completion POST does not
produce opaque Origin. Explicit `Origin: null` and foreign origins are rejected;
missing Origin is allowed only with valid session CSRF, as in existing EMAIL.

3. **Accept:** Website validates explicit acceptance and stores its exact
Rules/privacy intent. Then, in Website's final enrollment transaction:

```php
$pdo->beginTransaction();
try {
    // Website revalidates the exact accepted invitation/Rules/privacy intent.
    $auth = fc_auth_invitation_email_complete(
        $pdo, $proofPublicId, $acceptedInvitationPublicId, $acceptedGeneration,
        $csrfToken, $displayNameOrNull
    );
    // Use the RETURNED ID, never a pre-auth/cached current-user value.
    $enrollment = fc_challenge_invitation_enroll($pdo, $auth['user_id'], false);
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    // Handle profile-required as described below; other Auth denials can be
    // recorded with fc_auth_invitation_email_audit_rejection($pdo, $error).
    // Keep the accepted journey/review where still valid. Never show Overview
    // as a successful enrollment after an exception.
    throw $error;
}
// Clear Website's completed review/intent only AFTER commit.
fc_redirect('/app.php');
```

Successful result: `user_id`, `session_record_id`, `new_account`,
`invitation_public_id`, `generation`. Auth rotates the PHP session ID, preserves
Website session values, and creates the canonical database session. It does not
commit, redirect, send mail, select product context, or write product tables.
Website controls final enrollment and the Overview redirect. A rollback restores
unused proof and removes new account/session/admission/enrollment records; the
rotated browser session remains anonymous and can retry while authority is valid.

For an unknown canonical mailbox with no contradictory provider evidence, the
service returns the exception reason `invitation_email_profile_required` until
Website collects a display name (1–120 characters). Roll back, retain the already
accepted intent, show only required profile fields, and call the SAME completion
interface from the protected profile-completion POST. Do not ask for Challenge
acceptance a second time. Missing/changed/expired accepted terms still require
Website's existing fresh-review policy.

Other named failures include `invitation_email_review_expired`,
`invitation_email_invalid`, `invitation_email_not_registered`,
`invitation_email_account_switch_required`, `account_reconciliation_required`,
`fitcrew_account_access_denied`, and `prelaunch_invitation_admission_claimed`.
Do not display raw SQL/exception details. After rollback the Auth rejection-audit
helper emits only allowlisted reasons, no raw credentials or recipient email.

## Ordinary sign-in / another email

Offer **Already have a FitCrew account using another email?** The recipient may
choose ordinary authentication instead of invitation-email authentication.
Website persists its accepted intent and uses the existing fixed invitation
continuation (`fc_auth_crew_invitation_continuation_issue`) for this explicitly
chosen alternative. Existing account switching continues to preserve that
invitation. Website resumes and revalidates the same intent after login, enrolls,
then redirects to Overview. Do not use an `APP_HOME` login transaction that skips
pending enrollment. No new account-linking screen or Microsoft method is added.

If a different account is already authenticated, the new mailbox-proof call
refuses silent switching. Website must offer explicit account choice/switching.
Auth never equates that account with the invited mailbox by name or provider claim.

## Authority lifecycle and atomicity

Each proof is bound to one exact invitation generation, immutable recipient,
original token hash, expiry, and successful Postmark transport evidence. Opening
it creates only a browser-bound review receipt, capped at 30 minutes and the
invitation expiry. Reopening a still-valid unused bearer can restart review;
scanners cannot exhaust a one-time token merely by opening it. Resend/cancel,
recipient/token changes, expiry, completed invitations, and proof reuse all deny.

Completion locks current Website invitation authority and the Auth proof in a
consistent order. It uses a savepoint within Website's transaction. Duplicate
redemption is serialized and consumption is conditional. New-account admission
reuses the existing one-new-account-per-logical-invitation database claim. Its
internal Auth admission record is created and completed in this one call; it
causes no provider transaction, mail round trip, or redirect chain.

## Rollout and proof boundary

Apply 0510 with the existing migration runner BEFORE enabling Website integration.
The matching SQL in this handoff is an OFFLINE copy of the supplied production
snapshot with only 0510 and its ledger entry added. It is not a live backup after
deployment and must never be imported over production to deploy this change.
No `.env` change is required. Preserve the accepted schema-before-runtime rollout.

`invitation_email_auth_foundation_test.php` exercises the real Auth service and
Website enrollment service with rollback-only fixtures, including existing/new
accounts and product failure rollback. Existing EMAIL/Google/account-presence/
continuation/Website regressions remain required because account resolution is
shared. Production browser acceptance remains Website's final integration proof;
this package does not claim the live journey has changed.

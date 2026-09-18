# Phase 2A3 — Google Authentication

## Scope

Phase 2A3 implements explicit-button Google authentication only. It does not authorize Google Health, Google API access, One Tap, automatic sign-in, Microsoft authentication, Apple authentication, identity linking, groups, challenges, or public launch.

Controlled proof origin:

```text
https://fitcrewchallenge.com
```

## Official Google direction

- Google Identity Services JavaScript library renders the official `Continue with Google` button.
- FedCM button UX is enabled.
- One Tap and automatic sign-in are not enabled.
- Google returns an ID token for authentication; FitCrew does not request Google API authorization tokens during sign-in.
- Server verification uses Google's maintained PHP API client.
- Canonical Google identity remains validated issuer + `sub`; the narrow verified-mailbox ownership rule below does not change that lookup.

## FitCrew flow

```text
login page
→ create short-lived LOGIN auth transaction
→ render official Google button with transaction state + nonce
→ Google returns ID token to browser callback
→ same-origin POST to /auth/google/credential.php
→ CSRF + Origin + transaction + browser-binding validation
→ Google ID-token verification
→ audience / issuer / expiry / nonce validation
→ existing Google identity or new governed FitCrew account
→ consume auth transaction once
→ regenerate local PHP session ID
→ store only its SHA-256 evidence in user_sessions
→ authenticated /app.php
```

## Canonical email ownership for ordinary email sign-in

User-directed September 18, 2026 revision: after fresh Google token signature,
issuer, audience, expiry, nonce and transaction validation, Auth can establish
canonical VERIFIED ownership on the already resolved/newly admitted Google user
when `email_verified` is true and either:

- the canonical email domain is exactly `gmail.com`; or
- the signed token includes a nonempty valid `hd` domain (Workspace).

This follows [Google's server token verification guidance](https://developers.google.com/identity/gsi/web/guides/verify-google-id-token).
A third-party Google account email without `hd` remains descriptive even with
`email_verified=true`. Historical SQL claims alone are never promoted. No dot,
plus-address, or domain-alias rewriting is performed.

Auth first checks existing canonical ownership and EMAIL identity ownership.
A different owner causes reconciliation; Auth never selects that other user,
creates a replacement user, merges users, or transfers an identity. An existing
Google user remains the same issuer/sub user. Establishing canonical ownership
and the Google session is atomic. Normal new-account admission rules and USER
role defaults remain in force.

A subsequent completed ordinary email magic link may reuse this canonical
owner and create its internal EMAIL identity on first use. The user's account,
data and roles remain the same; no Add sign-in method setup is required. See
`email_magic_link_v1.md`. New Google identities are not automatically attached
to existing EMAIL-only accounts by email matching.

## Stale transaction refresh

A visible Google button is backed by a short-lived FitCrew LOGIN transaction.
The browser proactively asks Auth to replace a transaction shortly before
expiry. If an expired exact transaction reaches the credential endpoint, the
server rejects the old credential, retires the old transaction, and returns
fresh state and nonce. The official button is rendered again and the participant
must click it again; old state, nonce, and credential are never reused.

For Crew invitations, Auth first revalidates the Website-owned invitation
snapshot. A still-current continuation is released from the old transaction and
atomically rebound to the new one. Cancelled, expired, accepted, or rotated
Website authority cannot be refreshed.

The browser spaces automatic refresh attempts at least 60 seconds apart. Installing any
replacement transaction also starts a 60-second cooldown. This matters when a
still-valid invitation caps the replacement transaction to less than the normal
60-second refresh lead time: the browser must not immediately refresh it again.
Visibility events share the same cooldown, including after a failed request.
The cooldown never extends server-side transaction or invitation validity.
After expiry or rejection, the server remains authoritative; a new Google click
is still required for fresh state and nonce.

If the invitation continuation is already bound to an email sign-in, the login
page displays "Sign-in in progress" and asks the person to complete that sign-in.
It does not replace the selected provider or treat this expected state as a
Google outage. Unexpected preparation errors retain the temporary-unavailability
message and an appropriate retry status.

Browser-script regression proof (executes the production JavaScript with a
controlled clock, Google callback, visibility events, and server responses):

```bash
node tests/google_auth_refresh_browser_test.js
```

## Prelaunch account-creation gate

`PRELAUNCH_AUTH_PROOF_MODE=true` gates only **new** Google identities. A new account is permitted only when a validated, provider-verified email claim matches `PRELAUNCH_AUTH_ALLOWED_EMAILS` from environment configuration.

This allowlist is not account identity. No email match ever merges or links FitCrew users. Existing linked Google identities may return without reusing the new-account gate.

## Configuration

Committed safe contracts:

```text
GOOGLE_AUTH_ENABLED=false
GOOGLE_AUTH_CLIENT_ID=
PRELAUNCH_AUTH_PROOF_MODE=true
PRELAUNCH_AUTH_ALLOWED_EMAILS=
SESSION_IDLE_SECONDS=
SESSION_ABSOLUTE_SECONDS=
```

Production proof requires explicit non-empty session timeout values, `SESSION_SECURE=true`, a production-only `AUTH_TRANSACTION_SECRET_KEY_B64`, and a dedicated Google Authentication Web client ID.

No Google client secret is required by this GIS ID-token callback design.

## Session contract

- PHP session ID is regenerated after successful Google credential validation.
- Raw session ID is never stored in MariaDB.
- `user_sessions.session_id_hash` remains canonical server-side session evidence.
- Revocation, idle expiry, absolute expiry, user status, and identity status are enforced when resolving the current user.
- Logout revokes the FitCrew DB session and destroys the local PHP session; it does not sign the person out of Google.

## Production migration boundary

Phase 2A3 adds no schema migration. Existing migrations `0001` through `0100` remain canonical. The separately approved one-time production migration authorization must be executed manually before Phase 2A3 live proof; migrations are never auto-run by GitHub deployment.

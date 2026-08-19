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
- Canonical Google identity is validated issuer + `sub`; provider email is claim evidence only.

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

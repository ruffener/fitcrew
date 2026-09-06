# Phase 2A4 — Microsoft Authentication

Status: **AUTHORIZED / implementation candidate pending proof**

Phase 2A4 adds Microsoft as the second live FitCrew federated authentication provider while preserving the accepted provider-neutral Phase 2A2 identity/session foundation and the production-proven Google provider.

## Scope

Authentication only.

Supported Microsoft account audience:

```text
Accounts in any organizational directory
+
personal Microsoft accounts
```

Authority:

```text
https://login.microsoftonline.com/common
```

Flow:

```text
OAuth 2.0 Authorization Code
+ OpenID Connect
+ PKCE S256
```

Requested scopes:

```text
openid profile email
```

Not requested:

```text
offline_access
Microsoft Graph product permissions
mail / calendar / contacts / Teams / files permissions
```

Any transient provider access token returned by the protocol is discarded and is not FitCrew authorization or identity truth.

## Microsoft application registration

Dedicated application registration:

```text
FitCrew Challenge Authentication
```

Production redirect URI:

```text
https://fitcrewchallenge.com/auth/microsoft/callback.php
```

The application registration must allow:

```text
Accounts in any organizational directory
and personal Microsoft accounts
```

Environment-only configuration:

```text
MICROSOFT_AUTH_ENABLED
MICROSOFT_AUTH_CLIENT_ID
MICROSOFT_AUTH_CLIENT_SECRET
MICROSOFT_AUTH_REDIRECT_URI
PRELAUNCH_MICROSOFT_ALLOWED_IDENTITIES
```

Secret values never belong in Git, documentation examples with real values, audit events, or test fixtures.

Token-endpoint failures are reduced to fixed local audit classifications:

```text
code_exchange_invalid_client
code_exchange_invalid_grant
code_exchange_invalid_scope
code_exchange_unauthorized_client
code_exchange_provider_unavailable
code_exchange_transport_failed
code_exchange_response_invalid
code_exchange_failed
```

Microsoft's raw `error_description`, numeric provider error codes, trace/correlation identifiers,
authorization code, client secret, and returned token material are not logged or persisted. The
browser-facing failure remains generic; the fixed audit classification exists only to distinguish
configuration, one-time-code/PKCE, provider availability, and transport/response failures during
controlled production proof.

## Authorization / callback sequence

```text
Continue with Microsoft
→ POST /auth/microsoft/start.php
→ same-origin + CSRF check
→ create MICROSOFT LOGIN auth transaction
→ state + nonce + browser binding
→ PKCE verifier protected by accepted Sodium envelope
→ redirect to Microsoft /authorize
→ response_type=code
→ response_mode=form_post
→ callback bridge receives code/state without URL query logging
→ bridge performs no session/database work
→ bridge preserves a concrete same-origin Origin for completion
→ same-origin POST /auth/microsoft/complete.php
→ FitCrew SameSite=Lax session/browser binding available
→ validate transaction/state/browser
→ recover + integrity-check PKCE verifier
→ server-side code exchange
→ validate Microsoft ID token
→ resolve canonical tid + oid
→ enforce FitCrew account/identity status
→ create/reuse user + MICROSOFT identity
→ regenerate FitCrew session ID
→ consume transaction
→ create hashed server-side FitCrew session evidence
→ APP_HOME
```

The callback bridge intentionally does not load FitCrew bootstrap/session state. A cross-site `form_post` callback is incompatible with depending on a SameSite=Lax cookie on that first request; the bridge lets the browser return to FitCrew origin first and then reposts to the same-origin completion endpoint. The bridge uses `Referrer-Policy: same-origin` so that local repost carries the concrete FitCrew `Origin` required by the completion endpoint while referrers remain suppressed for every other origin. Missing, opaque (`null`), and foreign origins remain rejected. This keeps the authorization code out of the URL while preserving the accepted host-only SameSite=Lax FitCrew cookie contract.

## Canonical Microsoft identity

Identity truth:

```text
provider_key = MICROSOFT
provider_tenant_id = validated tid
provider_object_id = validated oid
```

Uniqueness remains the accepted database constraint:

```text
MICROSOFT + tid + oid
```

Additional validated protocol evidence:

```text
issuer = actual tenant issuer
protocol_subject = validated sub
```

Email, preferred_username, UPN, display name, phone, or login name are never canonical identity truth.

## ID-token validation

Phase 2A4 accepts only Microsoft identity-platform v2.0 tokens and validates:

- RS256 signature against current Microsoft common JWKS keys;
- signing-key `issuer` scope;
- `ver = 2.0`;
- audience equals the FitCrew Microsoft application client ID;
- expiration / temporal validity;
- nonce evidence;
- `tid` is a GUID;
- `oid` is a GUID;
- actual issuer is exactly `https://login.microsoftonline.com/{tid}/v2.0`;
- signing-key issuer resolves to that same actual issuer;
- protocol `sub` is present.

The `common` authority is never persisted as the user's issuer or tenant.

Personal Microsoft accounts use Microsoft's consumer tenant GUID and remain inside the same tid + oid canonical identity model.

## Provider email

If the ID token contains a valid `email` claim, FitCrew may retain it as descriptive provider evidence.

Phase 2A4 does not infer provider email verification from the presence of an email-like claim:

```text
provider_email_verified = NULL / UNKNOWN
```

`preferred_username` and similar mutable sign-in/display claims are not promoted to provider email.

Provider email never auto-merges users, auto-links identities, or creates a canonical contact-email record.

## PKCE

Method:

```text
S256
```

Raw verifier:

```text
never stored directly
```

Stored evidence:

```text
SHA-256 evidence hash
+
Sodium-protected recoverable envelope
```

Recovery re-hashes the plaintext verifier and compares it with stored evidence before code exchange.

Consumed or expired transactions have no recoverable verifier. Corrupted envelopes or wrong verifier evidence fail closed.

## Prelaunch proof gate

Production remains controlled prelaunch proof only.

New Microsoft users are allowed only if validated canonical identity appears in:

```text
PRELAUNCH_MICROSOFT_ALLOWED_IDENTITIES
```

Format:

```text
tid:oid,tid:oid
```

The gate uses validated tenant/object identity only. Email cannot satisfy or bypass the Microsoft proof gate.

Existing linked Microsoft identities may return even if the temporary new-account proof allowlist later changes.

## Session / logout

Microsoft authentication reuses the accepted FitCrew session contract:

- Secure in production;
- HttpOnly;
- SameSite=Lax;
- host-only cookie;
- post-authentication session-ID regeneration;
- only hashed session-ID evidence persisted;
- configurable idle and absolute expiry;
- logout revokes the FitCrew server-side session and destroys the local FitCrew session.

FitCrew logout does not globally sign the user out of Microsoft.

## Persistence exclusions

Phase 2A4 does not persist:

```text
authorization code
raw PKCE verifier
Microsoft ID token
Microsoft access token
Microsoft refresh token
Microsoft client secret
raw FitCrew session ID
AUTH_TRANSACTION_SECRET_KEY_B64
```

`offline_access` is not requested and durable Microsoft refresh tokens are prohibited.

## Migration boundary

No schema migration is required.

The accepted identity foundation already contains:

```text
provider_tenant_id
provider_object_id
protocol_subject
```

Canonical migration ledger remains:

```text
0001
0010
0020
0030
0040
0050
0060
0070
0080
0090
0100
```

Any discovered schema need requires Governance return before a migration is authored.

## Exclusions

Phase 2A4 does not implement Apple authentication, Graph product functionality, account linking/unlinking/merge, passkeys, passwords, magic links, Google Health authorization, health imports, Crew/Challenge behavior, scoring, Challenge Monies, payments, billing, or deployment-transport redesign.

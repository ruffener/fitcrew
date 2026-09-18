# EMAIL_MAGIC_LINK_V1

Status: Family Alpha implementation candidate. Public signup remains closed.

## Identity contract

- Provider: `EMAIL`
- Issuer: `https://fitcrewchallenge.com/auth/email`
- Subject: the canonical mailbox address proven by the completed magic link
- Identity lookup: `provider_key + issuer + provider_subject`

The EMAIL provider does not change the meaning of email claims from Google,
Apple, or Microsoft. Those claims are never used to find, link, or merge an
EMAIL identity. A new authentication method may be attached to an existing
FitCrew user only through a future explicit `LINK_IDENTITY` flow bound to an
authenticated `expected_user_id`; matching email strings never authorize it.

## Endpoints

| Endpoint | Method | Contract |
|---|---|---|
| `/auth/email/request.php` | POST | Same-origin and CSRF protected; applies email/network rate limits, issues a hash-only challenge, sends with `fc_mail_send()`, and always returns the same public response. |
| `/auth/email/confirm.php#token=...` | GET | Renders confirmation only. The fragment is not sent in the HTTP request. Returns `no-store`, `Referrer-Policy: same-origin`, and a nonce-restricted no-third-party CSP with `form-action 'self'`. |
| `/auth/email/complete.php` | POST | CSRF protected; validates any supplied `Origin` against the canonical FitCrew origin while tolerating legitimate header omission; applies the completion-attempt limit, atomically validates/consumes the link, resolves identity, creates the FitCrew session, and completes Auth continuation state. |

The confirmation page is standalone and loads no external scripts, images,
fonts, or stylesheets. A nonce-restricted inline script reads the token from the
URL fragment, immediately removes the fragment from the address bar, and places
the token in the explicit POST form. URL fragments are not sent to the web
server, keeping the raw token out of ordinary HTTP access logs. Automated
email-link GET scanners still cannot authenticate.

The confirmation page uses `Referrer-Policy: same-origin` deliberately. Under
the [Fetch Origin-header algorithm](https://fetch.spec.whatwg.org/#append-a-request-origin-header),
`no-referrer` makes a native form POST send the literal `Origin: null` even
when the destination is same-origin. Completion correctly rejects that opaque
origin. `same-origin` preserves the canonical Origin on the direct completion
POST while withholding referrer information from other origins. The token
remains excluded from request URLs and referrers. The completion endpoint's
own `no-referrer` response policy remains unchanged; it does not set the policy
of the preceding confirmation page.

## Challenge persistence

Migration `0330_add_email_magic_link_authentication.sql` adds EMAIL to Auth
provider constraints and creates `email_magic_link_challenges`.

The table stores:

- an opaque public row ID;
- the owning Auth LOGIN transaction;
- canonical issuer and EMAIL subject;
- HMAC evidence of the 256-bit token;
- HMAC evidence used to enforce one active challenge per email/flow;
- `ISSUED`, `CONSUMED`, or `REPLACED` lifecycle timestamps;
- a maximum 900-second expiry.

It never stores the raw token. Replacement changes the Auth transaction state
evidence and marks the prior challenge `REPLACED`, making the old URL unusable.

## Runtime flow

1. The participant submits an email from `/login.php`.
2. Auth applies `5 / 15 minutes` for the canonical email and `20 / 15 minutes`
   for network/client evidence. Rate-limit storage contains keyed evidence only.
3. Auth creates or replaces an EMAIL LOGIN transaction and challenge. If a Crew
   invitation continuation is active, it is bound to that exact transaction.
   Choosing EMAIL atomically invalidates an unused provider transaction already
   prepared for the same continuation; both can never remain active.
4. Auth sends `Your FitCrew sign-in link` using the shared `fc_mail_send()`
   service and `hello@fitcrewchallenge.com` mail contract.
5. A GET of the link only renders confirmation.
6. The participant explicitly submits the protected confirmation POST.
7. Auth locks and validates the bearer challenge, exact EMAIL LOGIN transaction,
   expiry, account/identity status, and—where applicable—the current Website
   invitation snapshot. Request-browser equality is not an EMAIL completion
   predicate.
8. Auth creates a server-side FitCrew session for the arrival browser. For an
   invitation flow, it atomically transfers the continuation binding to that
   arrival browser, user, and session.
9. Auth consumes the challenge and exact EMAIL LOGIN transaction in that same
   database transaction. After commit, it places only the opaque continuation
   ID in the arrival PHP session and redirects to the fixed destination.

For an existing EMAIL identity, the canonical user is reused. For an unknown
EMAIL identity, account creation is denied unless a current Family Alpha Crew
invitation continuation can claim its one-account admission authority. The
invited address is neither received nor compared by Auth.

After invitation-bound authentication, Auth returns only to
`/crew-invite.php`. Website still owns explicit invitation acceptance and Crew
membership creation. Auth never creates membership.

## Verified contact behavior

Successful EMAIL proof may create or update a `VERIFIED` canonical email claim
on the already resolved or newly created user. One canonical verified email may
belong to only one FitCrew user. An existing EMAIL identity remains issuer +
mailbox; a verified claim owned by another user or matching federated-provider
email evidence produces `ACCOUNT RECONCILIATION REQUIRED`. It never
auto-selects, links, merges, or transfers a user or identity. A future explicit
link requires an authenticated user plus a `LINK_IDENTITY` transaction.

Migration `0340_enforce_verified_email_uniqueness.sql` performs a duplicate
preflight and then enforces the verified canonical claim with a MariaDB-safe
explicit column, unique index, and check constraint. No generated-column
partial-uniqueness pattern is introduced.

## Operational notes

- No new environment setting is introduced.
- `.env` and `.env.example` are untouched.
- Delivery uses the existing configured mail driver and Postmark adapter.
- A valid EMAIL bearer link may be completed in another browser or device. The
  confirmation POST remains session-bound and CSRF protected in the arrival
  browser. A supplied `Origin` must be canonical; the existing missing-header
  compatibility rule remains unchanged. Literal `Origin: null` and foreign
  origins remain rejected; neither is equivalent to a missing header.
- Invitation continuation transfer is committed atomically with EMAIL token,
  LOGIN transaction, identity, and FitCrew-session state. Its former browser
  binding is rejected after transfer.
- Google, Microsoft, and future Apple OAuth/OIDC browser binding is unchanged.
- Invalid public completion responses disclose no account-existence detail.
- Raw token, raw network evidence, and mail-delivery secrets are excluded from
  audit metadata and application logs.

## Confirmation-policy production browser proof

Run only after the correction's exact main commit is successfully deployed.
Use an existing EMAIL identity or an authorized invitation flow; a matching
Google mailbox alone is not authorization to link identities or create users.

1. Request one fresh link, open only that newest email, and stop on the
   confirmation page. Do not reuse a tab loaded before deployment.
2. In browser Developer Tools, enable Network / Preserve log before opening
   the link (or reopen that same unconsumed email link after enabling it).
   The confirmation GET must return `Referrer-Policy: same-origin` and
   `Cache-Control: no-store, private`, with the existing restricted CSP.
3. Merely opening the page must not authenticate or consume the link.
4. Click Continue once, within the 15-minute lifetime. The completion POST's
   request header must be `Origin: https://fitcrewchallenge.com`, not `null`.
   Its Referer, if sent, must not contain the token; do not copy or share the
   request body, token, cookies, or CSRF value.
5. Verify the expected signed-in account/destination, and confirm both the
   exact challenge and its LOGIN transaction have consumption timestamps.
   An invitation flow must still require separate explicit Crew acceptance.
6. Reopen the same consumed email link and click Continue once: it must be
   rejected without creating another authentication session. Repeat the valid
   flow with a separately requested fresh link in another browser to prove
   arrival-session CSRF and cross-browser completion still work.

Unit checks guard the header/form contract and existing origin/CSRF rejection.
They do not replace this real browser proof. If the origin is now canonical but
completion still fails, inspect the newest rejection reason; do not weaken the
origin or CSRF checks, and do not attribute every generic rejection to expiry.

## Explicitly add email sign-in to an existing account

A provider's descriptive email is reconciliation evidence, not an EMAIL sign-in
identity or canonical verified-email ownership. An ordinary email LOGIN must not
silently select a Google/Microsoft user by matching that claim. A provider-only
account therefore needs an explicit Add email sign-in setup once.

Auth exposes `/auth/email/link.php`, linked from the login screen. Signed-out users
first authenticate with their existing method; a short-lived session preference
returns ordinary APP_HOME authentication to this fixed setup route. Invitation
returns keep priority and are never rewritten. This preference conveys no linking
authority. A signed-in user can visit the setup route directly.

The user explicitly requests adding an address, receives a Postmark confirmation
through the existing mail transport, opens it in the same initiating signed-in
browser, and confirms Add email sign-in. The server requires an active account,
active existing sign-in identity, and original authenticated session created within
the last 10 minutes at both issuance and completion. Setup confirmation has a
maximum 10-minute lifetime. An older sign-in requires signing in again and a fresh
setup request; it cannot be extended by refreshing the page.

The setup proof uses `LINK_IDENTITY` / `EMAIL`, `expected_user_id`, a fresh token,
browser binding, and a nonce hash binding the exact original authenticated session.
Only HMAC token/session evidence is stored. The confirmation token stays in the
URL fragment and protected POST body. Its GET makes no identity changes; its
isolated page retains the same-origin referrer policy and restrictive CSP.

Completion revalidates all authority and ownership inside the database transaction.
It may add an EMAIL identity and verified contact only to that authenticated user.
A foreign EMAIL identity, foreign canonical verified owner, or provider-email
conflict belonging to another user stops the operation. Inactive EMAIL identities
are not automatically reactivated. No users, roles, memberships or login sessions
are created by linking; no identities or email ownership are transferred. Existing
sign-in methods keep working. Successful completion consumes both challenge and
transaction, and records `EMAIL_IDENTITY_LINKED` without the raw token or address.
The service follows the existing transaction convention: callers that supply an
open transaction must roll it back on any failure.

LINK_IDENTITY proof cannot be used for LOGIN, and LOGIN proof cannot add a method.
Replacement has a separate flow namespace from ordinary login and invitations.
Requests share the existing per-mailbox and per-network rate limits; failed mail
transport invalidates the confirmation. No migration or environment change is
required. Postmark remains the configured transport.

After setup, ordinary 15-minute EMAIL sign-in links continue to work in the same
browser or another browser/device. Switching browsers is a compatibility test,
not a normal sign-in requirement. This setup does not alter the Website-owned
Challenge invitation or enrollment journey.

## Required request acknowledgement

Every ordinary request outcome uses the same message:
“If that email can be used, a FitCrew sign-in link will arrive shortly.”
It is shown in an Auth-only modal with one explicit “OK, I understand” POST action.
The server retains the acknowledgement until that CSRF-protected action succeeds.
There is no Escape, backdrop or timeout dismissal. Keyboard focus starts on the
button; native dialog semantics provide modality, and the rendered fallback leaves
the page background inert when JavaScript is unavailable. Browser navigation is
not intercepted. Reloading while acknowledgement is pending shows it again.

The link-setup request uses the same mechanism with its own generic confirmation
copy. Delivery success, failure, throttling and account presence are not disclosed
by either request response. Other notices remain on the existing flash path.
Presentation lives in `assets/css/auth-email.css` and `assets/js/auth-email.js`,
loaded by the Auth layout only; shared Website/Admin CSS is not modified.

Proof: `email_identity_link_foundation_test.php`,
`email_identity_link_unit_test.php`, and `email_auth_ack_browser_test.js`, together
with the existing Auth/invitation regressions. The JavaScript test is a DOM behavior
harness, not a visual browser rendering proof. Production visual and Postmark
mailbox proof must follow deployment of these exact files.

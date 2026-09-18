# EMAIL_MAGIC_LINK_V1

Status: Family Alpha implementation candidate. Public signup remains closed.

## Identity contract

- Provider: `EMAIL`
- Issuer: `https://fitcrewchallenge.com/auth/email`
- Subject: the canonical mailbox address proven by the completed magic link
- Identity lookup: `provider_key + issuer + provider_subject`

Ordinary EMAIL LOGIN now accepts a completed mailbox proof for the unique
canonical VERIFIED email owner. If that user has no EMAIL identity yet, Auth
creates that internal identity on the same user in the login transaction. There
is no separate setup page, extra sign-in, or new account. Google continues to
resolve by validated issuer + subject, never by an email search.

Fresh validated Google authentication may establish canonical ownership when
Google is authoritative for that mailbox (verified Gmail, or verified Workspace
with a signed `hd` claim). Stored provider claims, other providers' descriptive
email, unverified contacts, and email text equality alone do not establish it.
See `phase2a3_google_authentication.md` for the precise authority rule.

This is the user-directed September 18, 2026 revision of the prior explicit
email-setup requirement. It does not merge users, transfer identities, weaken
canonical uniqueness, or turn LINK_IDENTITY proof into LOGIN proof.

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

For an existing EMAIL identity or a unique canonical VERIFIED email owner,
the same active user is reused. The latter gains its internal EMAIL identity
only after valid mailbox proof. Neither case consumes new-account invitation
admission. If neither exists, descriptive provider-email matches require
reconciliation; otherwise creation still requires a current Family Alpha Crew
invitation's one-account admission authority. Auth does not compare the invited
address to the login mailbox.

After invitation-bound authentication, Auth returns only to
`/crew-invite.php`. Website still owns explicit invitation acceptance and Crew
membership creation. Auth never creates membership.

## Verified contact behavior

Successful EMAIL proof creates or updates VERIFIED contact ownership on the
resolved user. One canonical verified email belongs to one FitCrew user. If an
EMAIL identity and a canonical owner exist but point to different users, Auth
stops without changing either account. Inactive users or EMAIL identities remain
blocked. A matching provider claim without canonical ownership cannot select a
user. Account/identity, contact, session, invitation continuation, and token
consumption changes commit or roll back together.

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
Use an existing EMAIL identity, a canonical verified-email owner, or an
authorized invitation flow. For an older Google account with no canonical email
record, first sign in with Google after deploying this revision. That fresh
validated login establishes ownership; historical provider claims are not
backfilled from SQL.

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

## Ordinary Google then email experience

1. Sign in through Google. Auth creates or reuses the Google account and, for
   authoritative verified Google mailboxes, establishes its canonical email.
2. Sign out and enter that mailbox in the normal email sign-in form.
3. Acknowledge the generic request modal, open the newest email, and confirm.
4. Auth signs in to the same FitCrew user. Account data, roles, and memberships
   remain attached to that user. Subsequent Google or email sign-ins reuse it.

No separate Add sign-in method action is required. Existing setup routes now
return a fixed 303 to `/login.php` for all requests and do not inspect tokens,
send mail, access SQL, or authenticate. The old setup helper is inert. Existing
LINK_IDENTITY challenges remain unusable for ordinary EMAIL LOGIN and expire
normally. The retired deployment-owned paths are retained until a governed
manifest-deletion pass, avoiding a routine deployment that leaves old executable
setup code live. Old setup views are inert redirects as well.

This revision does not attach a new Google subject to an existing EMAIL-only
account by email matching. That is a separate linking/reconciliation decision;
the supported Google-first then email path does not need it.

## Required request acknowledgement

After request Origin and CSRF validation, all ordinary request outcomes retain the same message:

“If that email can be used, a FitCrew sign-in link will arrive shortly.”

The modal requires its acknowledgement button; backdrop clicks and Escape do
not dismiss it. It has an accessible name/description, initial focus, native
modal focus containment, a protected POST acknowledgement, and an inert page
background including the no-JavaScript fallback. The message never reveals
whether an account exists, an address was admitted, or mail was delivered.

Auth-only styles and behavior remain `assets/css/auth-email.css` and
`assets/js/auth-email.js`; shared Website styling and provider buttons are not
changed by this revision.

Proof: `auth_email_account_unit_test.php`,
`auth_email_account_foundation_test.php`, `email_auth_ack_browser_test.js`, and
all existing EMAIL, Google, identity, invitation, private-presence, and mail
transport regressions. No new migration or environment setting is needed.

## Rejected form and closed-prelaunch feedback

If an email request fails Origin or CSRF validation, it never reaches challenge
issuance or mail transport. Auth records the rejection and shows a required
retry modal on the refreshed login page, explicitly stating that no email was
requested. Acknowledging the modal uses the existing protected POST. All requests
that pass those request protections retain the exact generic email message,
regardless of account presence, rate limit or transport result.

A valid mailbox proof that cannot create an account during closed prelaunch now
explains that new accounts require a Challenge invitation. Invalid, expired,
replaced, and replayed proofs retain the generic invalid-link response. This is
feedback only: no invitation, account, session, origin or CSRF rule is weakened.

Google refresh rejections now record an Auth audit reason with operation=refresh
and tell the user to reload when the page proof cannot be verified. An expired
Google transaction with intact browser/session authority still uses the accepted
refresh flow. Loss of that browser/session authority requires a fresh page;
transaction refresh cannot recreate it. No raw token, email, CSRF, state or nonce
is added to rejection audit metadata.

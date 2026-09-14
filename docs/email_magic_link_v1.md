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
| `/auth/email/confirm.php#token=...` | GET | Renders confirmation only. The fragment is not sent in the HTTP request. Returns `no-store`, `no-referrer`, and a nonce-restricted no-third-party CSP. |
| `/auth/email/complete.php` | POST | Same-origin and CSRF protected; applies the completion-attempt limit, atomically validates/consumes the link, resolves identity, creates the FitCrew session, and completes Auth continuation state. |

The confirmation page is standalone and loads no external scripts, images,
fonts, or stylesheets. A nonce-restricted inline script reads the token from the
URL fragment, immediately removes the fragment from the address bar, and places
the token in the explicit POST form. URL fragments are not sent to the web
server, keeping the raw token out of ordinary HTTP access logs. Automated
email-link GET scanners still cannot authenticate.

## Challenge persistence

Migration `0330_add_email_magic_link_authentication.sql` adds EMAIL to Auth
provider constraints and creates `email_magic_link_challenges`.

The table stores:

- an opaque public row ID;
- the owning Auth LOGIN transaction;
- canonical issuer and EMAIL subject;
- HMAC evidence of the 256-bit token;
- HMAC evidence used to enforce one active challenge per browser/email/flow;
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
7. Auth locks and validates the challenge, transaction, browser binding, expiry,
   account/identity status, and—where applicable—the current Website invitation
   snapshot.
8. Auth consumes the transaction and challenge and creates a server-side
   FitCrew session in the same database transaction.

For an existing EMAIL identity, the canonical user is reused. For an unknown
EMAIL identity, account creation is denied unless a current Family Alpha Crew
invitation continuation can claim its one-account admission authority. The
invited address is neither received nor compared by Auth.

After invitation-bound authentication, Auth returns only to
`/crew-invite.php`. Website still owns explicit invitation acceptance and Crew
membership creation. Auth never creates membership.

## Verified contact behavior

Successful EMAIL proof may create or update the same canonical mailbox as a
`VERIFIED` contact email on the already resolved or newly created user. The
query is scoped by that user's numeric ID. Contact-email lookup never selects a
FitCrew user.

## Operational notes

- No new environment setting is introduced.
- `.env` and `.env.example` are untouched.
- Delivery uses the existing configured mail driver and Postmark adapter.
- Cross-device/browser handoff is intentionally unsupported for Family Alpha.
- Invalid public completion responses disclose no account-existence detail.
- Raw token, raw network evidence, and mail-delivery secrets are excluded from
  audit metadata and application logs.

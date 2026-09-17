# Private account-presence classification

Governance's September 17, 2026 addendum authorizes the server-side callable:

```php
fc_auth_account_presence_for_email(PDO $pdo, string $email): string
```

It is loaded by `inc/bootstrap.php`, or can be required directly from
`inc/auth/account_presence.php`. Its only results are:

| Result | Meaning |
| --- | --- |
| KNOWN_ACCOUNT | An existing FitCrew user owns this canonical VERIFIED contact email. |
| UNKNOWN | Canonical verified-email ownership could not be established. This does not assert that no account exists. |

The existing canonical verified-owner lookup and migration 0340 control the
classification. Canonicalization trims and lowercases without rewriting dots or
plus tags. Unverified and removed contacts do not establish ownership. Descriptive
Google, Apple, or Microsoft email claims never establish this classification,
including claims marked verified by the provider.

ACTIVE, SUSPENDED, and DEACTIVATED owners all remain KNOWN_ACCOUNT. Account-access
status is not part of this interface. Blank input and database lookup failures
return UNKNOWN without returning failure details or account metadata.

Website may use the result only to customize the recipient's transactional
Challenge invitation email. Do not expose it to the inviter or through a public
endpoint. It returns no user or identity IDs, provider claims, status details,
or contact metadata. It performs a non-locking read, emits no response, and never
changes caller transaction ownership, account data, sessions, or membership.
It cannot authorize authentication, linking, merging, account creation, or
invitation admission. Separate provider-email reconciliation remains unchanged.

Proof on a migrated local test database:

```powershell
php tests/auth_account_presence_unit_test.php
php tests/auth_account_presence_foundation_test.php
```

The foundation proof verifies 22 cases, unchanged table/session state, and fixture
rollback. No migration or environment-file change is needed. This interface is
part of the coordinated Auth pass; Website owns subsequent invitation integration.

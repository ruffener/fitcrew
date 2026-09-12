# Governance Decisions

This file is a concise current-state summary. Historical decisions that have been replaced are retained as `SUPERSEDED` where they remain useful context.

## Product / Platform Foundation

| Decision | Current Governance Status |
|---|---|
| Product name: FitCrew Challenge | APPROVED |
| Domain: fitcrewchallenge.com | APPROVED |
| Project/repo short name: fitcrew | APPROVED |
| Local-first PHP/MariaDB; Laragon + VS Code + Git | APPROVED |
| Multiple private groups from day one | APPROVED |
| Root-web architecture with protected internal paths | ACCEPTED / PROVEN |
| Phase 2A1 migration foundation | ACCEPTED / COMPLETE |
| Product schema evolution | Authorized incrementally by Governance slice |

## Authentication / Identity

| Decision | Current Governance Status |
|---|---|
| Visible consumer authentication providers | GOOGLE / APPLE |
| Supported authentication identities | GOOGLE / APPLE / MICROSOFT |
| Authentication model | FEDERATED / PASSWORDLESS FIRST |
| FitCrew-managed passwords | NOT APPROVED — prior email/password MVP direction SUPERSEDED |
| Email magic-link login | NOT INITIAL MVP |
| Google Authentication implementation | Phase 2A3 — ACCEPTED / COMPLETE / PRODUCTION PROVEN |
| Microsoft Authentication implementation | Phase 2A4 — ACCEPTED / COMPLETE / PRODUCTION PROVEN |
| Microsoft consumer visibility | DEFERRED / CONFIGURATION-CONTROLLED |
| Apple Authentication provider readiness | NEXT AUTH PRIORITY / AUTHORIZED |
| Apple Authentication implementation | Phase 2A5 — NOT YET AUTHORIZED |
| Authentication identity | Separate from health authorization |
| Matching email | Never automatic account-linking or merge truth |

## Health Data

| Decision | Current Governance Status |
|---|---|
| Google Health API | MVP provider candidate |
| Health Data Architecture v1.0 | ACCEPTED |
| Google Health production readiness | NOT YET PROVEN |
| Health Data Phase 2 | Capability & Provenance Proof AUTHORIZED |
| Raw health imports | Evidence only |
| `official_daily_logs` | Challenge truth |

Canonical product data spine:

```text
measurement source
→ health-data platform/provider
→ raw_health_imports
→ official_daily_logs
→ scoring / dashboards / leaderboard / participant pages / fines / badges / communications
```

## Privacy

Canonical privacy product names:

```text
Private Measurements
Crew-Shared Measurements
```

Prior Phase 0 labels `OPEN_FAMILY` / `PRIVATE_METRICS` are `SUPERSEDED` as product-facing privacy names.

## Branding / Public Experience

- FitCrew Branding v1.0 is established.
- Primary tagline: `Your Crew. Your Challenge. Your Progress.`
- Secondary statement: `Stronger Together. Driven to Improve.`
- Royal Blue + Energy Orange + Deep Navy visual system is established.
- Experience Phases 1–6: `ACCEPTED / COMPLETE`.

## Historical Decisions Superseded by Current Governance

The following were valid earlier planning decisions but are no longer canonical:

- `Email/password login for MVP` — **SUPERSEDED** by federated/passwordless-first authentication.
- `Google login pending future authorization` / `Apple login pending future authorization` — **SUPERSEDED** by the accepted federated provider program. Google is production-proven, Apple is the next visible consumer provider, and Microsoft is production-proven with consumer visibility deferred.
- `MVP provider: Google Health — Approved` — **SUPERSEDED** by the more precise status: Google Health API is the MVP provider candidate; Health Data Architecture v1.0 is accepted; production readiness is not yet proven.
- `Final SQL authorization pending` — **SUPERSEDED** by governed incremental numbered migrations authorized per implementation slice.
- `OPEN_FAMILY` / `PRIVATE_METRICS` — **SUPERSEDED** as product-facing privacy names by `Crew-Shared Measurements` / `Private Measurements`.

## Phase 2A3 Google Authentication

Current governed status:

- Phase 2A2 Account / Identity Foundation: **ACCEPTED / COMPLETE**.
- Phase 2A3 Google Authentication: **ACCEPTED / COMPLETE / PRODUCTION PROVEN**.
- Proof origin: `https://fitcrewchallenge.com`.
- Environment classification: **CONTROLLED PRELAUNCH PRODUCTION / PROOF ENVIRONMENT**.
- Public launch readiness: **NOT AUTHORIZED**.
- Phase 2A4 Microsoft Authentication: **ACCEPTED / COMPLETE / PRODUCTION PROVEN**.
- Microsoft consumer visibility: **DEFERRED**.
- Phase 2A5 Apple provider readiness: **AUTHORIZED / NEXT AUTH PRIORITY**.
- Phase 2A5 Apple implementation: **NOT YET AUTHORIZED**.
- Google Health remains separate and is **NOT AUTHORIZED by the Phase 2A3 authentication variance**.
- New prelaunch Google account creation is controlled by an environment-only allowlist; the gate is not identity truth.
- Canonical Google identity remains validated issuer + `sub`.
- Google authentication must not request or store Google Health scopes, access tokens, or refresh tokens.


## Phase 2A4 Microsoft Authentication

Current governed status:

- Phase 2A3 Google Authentication: **ACCEPTED / COMPLETE / PRODUCTION PROVEN**.
- Phase 2A4 Microsoft Authentication: **ACCEPTED / COMPLETE / PRODUCTION PROVEN**.
- Microsoft consumer visibility: **DEFERRED / CONFIGURATION-CONTROLLED**.
- Phase 2A5 Apple provider readiness: **AUTHORIZED / NEXT AUTH PRIORITY**.
- Phase 2A5 Apple implementation: **NOT YET AUTHORIZED**.
- Microsoft account audience: organizational directories + personal Microsoft accounts.
- Microsoft flow: Authorization Code + OpenID Connect + PKCE S256.
- Canonical Microsoft identity: validated `tid` + `oid`; email is descriptive evidence only.
- Requested scope family: `openid profile email`; `offline_access` and Microsoft Graph product permissions are excluded.
- New Microsoft proof identities are gated by an environment-only validated `tid:oid` allowlist.
- Ordinary Microsoft login visibility and direct initiation are controlled by `MICROSOFT_AUTH_CONSUMER_VISIBLE`, which defaults to `false` while the provider is deferred.
- The underlying Microsoft provider implementation, tests, identity support, and production history remain retained for future governed reactivation.
- No schema migration is authorized or expected for Phase 2A4.
- Public launch remains **NOT AUTHORIZED**.

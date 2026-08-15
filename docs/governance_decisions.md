# Governance Decisions

## Approved Phase 0 / Phase 1 Direction

| Decision | Status |
|---|---|
| Product name: FitCrew Challenge | Approved |
| Domain: fitcrewchallenge.com | Approved |
| Project/repo short name: fitcrew | Approved |
| Local-first PHP/MariaDB | Approved |
| Laragon + VS Code + Git workflow | Approved |
| Multiple private groups from day one | Approved |
| Email/password login for MVP | Approved |
| Google Health connection separate from account login | Approved |
| Google login | Pending future authorization |
| Apple login | Pending future authorization |
| MVP provider: Google Health | Approved |
| Provider-aware architecture | Required |
| official_daily_logs source of truth | Approved |
| raw_health_imports evidence only | Approved |
| MVP privacy modes: OPEN_FAMILY and PRIVATE_METRICS | Approved |

## Still Pending Before Product Build

- Final Fine Fund rules and amounts.
- Final Weekly Badge names and tone.
- Final SQL authorization.
- Later provider/health implementation decisions at their authorized phase.

## Phase 1B Branding Integration

Authorized direction:

- FitCrew Branding v1.0 applied to the reusable website shell.
- Prior green development identity retired.
- Runtime/browser asset source of truth: `assets/`.
- Design evidence moved to `docs/design/` and is not part of the public asset tree.
- Primary tagline: `Your Crew. Your Challenge. Your Progress.`
- Secondary statement: `Stronger Together. Driven to Improve.`
- Royal Blue + Energy Orange + Deep Navy visual system established.
- Bebas Neue + Montserrat typography direction established for the shell.
- Board-derived raster logo assets are reference assets only; clean production SVG masters remain pending future brand-asset delivery/approval.
- Phase 1B does not authorize Phase 2A or later product behavior.

## Phase 1B Root Web-Root Amendment

FitCrew now uses:

```text
C:\laragon\www\fitcrew
```

as both the project root and Apache document root.

The earlier `public/` document-root pattern is superseded.

Security boundary:

- root `.htaccess` denies direct access to application internals and repository/configuration files;
- `Options -Indexes` disables directory listings;
- `assets/.htaccess` blocks executable/server-side files in the runtime asset tree;
- security smoke proof must verify protected paths return 403/404 before acceptance/deployment.

See `docs/root_web_security.md`.

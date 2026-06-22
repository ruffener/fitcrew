# FitCrew Challenge Source-of-Truth Contract

The website must preserve this architecture rule:

```text
measurement source
→ health data platform/provider
→ raw_health_imports
→ official_daily_logs
→ dashboards / leaderboard / participant pages / fines / badges / emails
```

## Governing truth rule

```text
Raw imports are evidence.
official_daily_logs are challenge truth.
```

## Meaning

- A scale, watch, phone, app, or manual device may be the original measurement source.
- Google Health, Apple Health, Health Connect, CSV, or another provider may be the data conduit.
- Raw provider records are stored as evidence.
- Official daily logs are the controlled challenge record.
- Dashboards, leaderboards, participant pages, fines, badges, and emails must read from official daily logs, not directly from raw imports.

## Phase 1 status

This is a documentation contract only. No raw imports, official log generation, scoring, or dashboards are implemented in Phase 1 skeleton.

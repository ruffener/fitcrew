# FitCrew Challenge

Local-first PHP/MariaDB website skeleton for the FitCrew Challenge private-group body-composition challenge platform.

## Phase status

- Phase 0 Architecture: Approved by Governance
- Phase 1 Skeleton Implementation: Authorized only for foundation files
- Product behavior: Not implemented
- Final SQL: Not implemented
- Google Health OAuth/imports/scoring/fines/badges/billing: Not implemented

## Local setup

1. Place this project at:

   ```text
   C:\laragon\www\fitcrew
   ```

2. Configure Laragon/Apache so the web root points to:

   ```text
   C:\laragon\www\fitcrew\public
   ```

3. Copy `.env.example` to `.env` and adjust local values.

4. Start Laragon.

5. Visit:

   ```text
   http://fitcrew.test
   ```

## Source-of-truth rule

```text
measurement source
→ health data platform/provider
→ raw_health_imports
→ official_daily_logs
→ dashboards / leaderboard / participant pages / fines / badges / emails
```

```text
Raw imports are evidence.
official_daily_logs are challenge truth.
```

## Phase 1 exclusions

This skeleton intentionally does not include:

- Google Health OAuth
- Imports
- raw_health_imports implementation
- official_daily_logs generation
- Scoring
- Real dashboards
- Leaderboard behavior
- Participant pages beyond placeholders
- Fines
- Badges
- Billing
- Apple Health
- Final SQL
- Google Sheets prototype modifications

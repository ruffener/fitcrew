# FitCrew Challenge

Local-first PHP/MariaDB website foundation for the FitCrew Challenge private-group body-composition challenge platform.

## Phase status

- Phase 0 Architecture: Approved by Governance
- Phase 1 Skeleton: Accepted / Complete
- Phase 1B Branding Integration: Implemented for review
- Phase 2 Planning: Accepted / Complete
- Phase 2A product behavior: Not implemented
- Final SQL: Not implemented
- Google Health OAuth/imports/scoring/fines/badges/billing: Not implemented

## Local setup

1. Place this project at:

   ```text
   C:\laragon\www\fitcrew
   ```

2. Use the **project root** as the Laragon/Apache document root:

   ```text
   C:\laragon\www\fitcrew
   ```

   FitCrew intentionally does not use a separate `public/` directory.

3. Confirm Apache allows the repository `.htaccess` rules. Those rules are part of the FitCrew security boundary and block direct web access to application internals such as:

   ```text
   .env
   .git/
   inc/
   views/
   database/
   storage/
   docs/
   tests/
   vendor/
   composer.json
   composer.lock
   README.md
   ```

4. Copy `.env.example` to `.env` and adjust local values.

5. Start Laragon.

6. Visit:

   ```text
   http://fitcrew.test
   ```

## Root web contract

Only intentional route entry points and browser assets are meant to be publicly addressable.

Examples:

```text
/                         public page
/login.php                public route
/register.php             public route
/app.php                  protected route shell
/health/google/...        intentional future-provider placeholders
/api/index.php            intentional API placeholder
/assets/...               static browser assets
```

Application internals remain in the repository root for developer clarity but are denied by Apache.

See:

```text
docs/root_web_security.md
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

## Current exclusions

This foundation intentionally does not include:

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

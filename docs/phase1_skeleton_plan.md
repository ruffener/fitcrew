# Phase 1 Skeleton Plan

## Authorized

- Create local project structure.
- Create public web root.
- Create protected inc/views/storage/database/docs/tests folders.
- Create .env.example with safe placeholders.
- Create .gitignore.
- Create README.md.
- Create composer.json with minimal PHP setup.
- Create bootstrap/config/session/db connection skeleton.
- Create basic public/auth/app layout shell.
- Create placeholder pages.
- Create documentation files.

## Not Authorized

- Google Health OAuth.
- Imports.
- raw_health_imports implementation.
- official_daily_logs generation.
- Scoring.
- Real dashboards.
- Leaderboard behavior.
- Participant pages beyond placeholders.
- Fines.
- Badges.
- Billing.
- Apple Health.
- Final SQL.
- Google Sheets prototype modification.

## Phase 1B Architecture Amendment

The original Phase 1 authorization used a separate `public/` document root.

That historical plan is preserved above as the accepted Phase 1 record, but the runtime architecture is now superseded by the Phase 1B root web-root amendment:

```text
Project/document root:
C:\laragon\www\fitcrew
```

Sensitive directories and root configuration files are protected through the explicit Apache rules documented in:

```text
docs/root_web_security.md
```

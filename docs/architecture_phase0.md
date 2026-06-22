# Phase 0 Architecture Summary

FitCrew Challenge is a private-group body-composition challenge platform.

The website is designed as a local-first PHP/MariaDB application with multiple private groups from day one.

The current Google Sheets / Apps Script version remains the live prototype and product evidence. The website should not copy the spreadsheet tab structure directly into the database and should not convert Apps Script line-for-line into PHP.

Core relationship:

```text
users
→ group_members
→ groups
→ challenges
→ challenge_participants
```

Core data truth:

```text
raw_health_imports = evidence
official_daily_logs = challenge truth
```

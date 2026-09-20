# FitCrew UI Foundation v1 — Website Implementation

## Scope

This pass establishes the shared Website-owned visual foundation and normalizes representative Overview, Crew, Challenge and People surfaces without redesigning product information architecture beyond the authorized People/navigation consolidation.

## Canonical scale

- Typography: Display; Page Title; Section Title; Card Title; Body 16px; Supporting 14px; Meta 13px; Micro 11px (rare/nonessential only).
- Spacing: 4 / 8 / 12 / 16 / 24 / 32 / 48px.
- Radius: control 10px; notice 14px; card/action tile 18px; major shell/hero/modal 24px; pill fully rounded.
- Controls: standard button 46px; inputs/selects 48px; touch and icon-only targets at least 44px.
- Focus: one visible royal-blue focus-visible treatment across Website controls.

Compatibility aliases (`--fc-type-title`, `--fc-type-section`, `--fc-type-support` and existing component classes) remain so the pass does not require an all-CSS rewrite.

## People surface

`Crew → People` and `Challenge → Participants` converge on `participants.php`, which renders Crew membership and selected/current Challenge participation together while preserving them as distinct records and actions.

Existing invitation, Crew removal and Challenge participation services are reused. No participation is manufactured from Crew membership.

## Context navigation

Crew and Challenge context values in the shared header/sidebar link to the exact Crew and Challenge being shown. `crew.php?crew=<public-id>` provides an exact read-only Crew context without changing product history.

## Explicitly not implemented

- Invite Entire Crew runtime or server eligibility engine.
- Subscription/free-plan enforcement.
- Auth-owned UI normalization.
- Admin-specific UI.
- Any schema change.

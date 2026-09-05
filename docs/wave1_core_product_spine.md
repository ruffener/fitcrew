# FitCrew Challenge — Wave 1 Core Product Spine

## Status

Website Product Experience Assembly v1 / Wave 1 implementation artifact.

## Domain boundary

Wave 1 establishes the durable product shell beneath the accepted consumer experience:

```text
User
→ Crew membership
→ private Crew
→ explicit Challenge relationship
→ Challenge lifecycle
→ published Rule Version
→ selected context preference
→ authenticated product surfaces
```

Canonical distinctions are preserved:

```text
platform user
≠ Crew member
≠ Crew Owner
≠ Challenge participant
≠ Challenge Owner
```

Selected Crew/Challenge context is a preference only. Every read and mutation rechecks server-side authorization.

## Crew

A Crew is a persistent private community. `crews.owner_user_id` records canonical Crew ownership and `crew_memberships` records authorized membership. Crew creation atomically creates an active OWNER membership for the creator.

Wave 1 does not create public Crew discovery or an email-based invitation identity shortcut.

## Challenge

A Challenge belongs to one Crew but participation is explicit. Active Crew membership does not automatically grant full Challenge access. Challenge Owner and active Challenge participants receive Challenge-scoped access.

Initial durable lifecycle:

```text
DRAFT
→ FORMING_CREW when the initial Rule Version is published
```

The complete accepted lifecycle vocabulary is represented in the contract, but Wave 1 does not expose arbitrary lifecycle controls or pretend that downstream Baseline/Health/Scoring readiness exists.

## Rules

`challenge_rule_versions` is typed/versioned truth, not an editable JSON blob.

Wave 1 supports:

- mutable draft rule configuration;
- immutable published versions;
- current published version = latest published version number;
- a later Owner change begins as a new draft copied from current published truth;
- publishing a later draft does not delete or overwrite prior history.

Typed Wave 1 rule fields are limited to the accepted owner-customizable Challenge shell:

- planned start date;
- Challenge duration;
- Challenge timezone;
- weekly check-in day;
- Live leaderboard visibility;
- certified scoring-standard identity.

`BODY_COMPOSITION_V1_0` is referenced as the certified standard. Wave 1 does not calculate it.

## Selected context

`user_product_contexts` stores the user’s selected Crew/Challenge preference. Context selection requires current authorization. Stale or unauthorized stored context is ignored and replaced with an accessible fallback.

## Held runtime boundaries

Wave 1 does not implement:

- authentication/provider work;
- Google Health or provider synchronization;
- raw health imports or `official_daily_logs`;
- BODY_COMPOSITION_V1_0 mathematics, rank, or Champion;
- Challenge Monies calculation/event/Stack runtime;
- payments or real-money Stakes.

The UI may reserve truthful integration points but must say unavailable rather than fabricate downstream data.

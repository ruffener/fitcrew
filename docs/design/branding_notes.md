# FitCrew Design Notes

## Branding Version 1.0

The prior green development shell is retired.

Approved visual direction:

- Royal Blue `#1E40AF`
- Energy Orange `#FF6A00`
- Deep Navy `#0D1B3D`
- Steel Gray `#6B7280`
- Light Gray `#F2F4F7`
- White `#FFFFFF`
- Display typography: Bebas Neue
- Body/interface typography: Montserrat
- Primary tagline: **Your Crew. Your Challenge. Your Progress.**
- Secondary statement: **Stronger Together. Driven to Improve.**

Brand character: competitive, fresh, energetic, private, trustworthy, community-oriented, family-friendly, and not medical-clinical.

## Asset source of truth

`assets/` is the browser/runtime asset source of truth.

Design evidence is stored under `docs/design/`, outside the public asset tree and protected by the root web-security rules.

The approved branding board is preserved here as design evidence:

- `docs/design/FitCrew_Branding_v1.0.png`

The PNGs under `assets/img/brand/` are exact raster crops derived from the approved board. They are suitable for Phase 1B visual integration and proof, but they are **not** declared production vector masters.

Production-ready brand delivery still needs clean approved SVG masters for the primary logo, FC Peak mark, Crew mark, favicon/app mark, and light/dark variants.

## Typography delivery

Phase 1B loads Bebas Neue and Montserrat through Google Fonts so the local shell can demonstrate the approved typography without bundling font binaries in the repository.

Before public production hosting, Governance should decide whether to self-host the open-license font files or continue using an external font service. The CSS includes resilient system fallbacks if the external font request is unavailable.

## Accessibility

Energy Orange remains the brand accent. Small text is not placed as white-on-orange by default. The primary orange CTA uses Deep Navy text for readable contrast. Blue/navy controls use white text.

State meaning is expressed with text/symbols in addition to color.

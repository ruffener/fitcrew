# FitCrew Challenge Branding v1.0 — Website Contract

## Status

Phase 1B branding integration is presentation-only. No account, group, challenge, health, import, official-log, scoring, leaderboard, fine, badge, billing, or mobile behavior is authorized by this document.

## Core identity

- Product: **FitCrew Challenge**
- Dominant brand: **FitCrew**
- Public naming: use **FitCrew Challenge** on first/formal reference; **FitCrew** may be used as shorthand after the full product name is established.
- Primary tagline: **Your Crew. Your Challenge. Your Progress.**
- Secondary statement: **Stronger Together. Driven to Improve.**
- Primary mark direction: **FC Peak / Mountain**
- Secondary mark direction: **Three-person Crew / Community**

## Palette

| Token | Value | Use |
|---|---|---|
| Royal Blue | `#1E40AF` | structure, links, selected states, primary actions |
| Energy Orange | `#FF6A00` | energy accents, milestones, emphasis |
| Deep Navy | `#0D1B3D` | strong backgrounds, premium contrast, primary text |
| Steel Gray | `#6B7280` | neutral support |
| Light Gray | `#F2F4F7` | page/background neutral |
| White | `#FFFFFF` | surfaces and contrast |

Production support colors are defined as semantic CSS tokens in `assets/css/app.css` and may be refined for hover/focus/accessibility while preserving the unmistakable FitCrew palette.

## Typography

- Display: `Bebas Neue`
- Body/interface: `Montserrat`

Phase 1B loads these through Google Fonts with system fallbacks. No font binaries are committed.

## Runtime asset ownership

`assets/` is the runtime/browser asset source of truth.

Design evidence and design notes live under `docs/design/`, which is protected from direct browser access by the root web-security contract.

## Board-derived raster assets

The available branding board is a concept raster rather than a production vector package. Phase 1B uses exact crops from that approved board without redrawing the logo:

- `fitcrew-logo-light-reference.png`
- `fitcrew-logo-dark-reference.png`
- `fitcrew-crew-mark-reference.png`
- `fitcrew-app-icon-navy-reference.png`
- `favicon-reference-32.png`
- `favicon-reference-64.png`
- `apple-touch-icon-reference.png`

These are reference-derived implementation assets for the current shell, not final production masters.

## Production asset gap

Before public production launch, provide/approve clean SVG masters for:

1. full horizontal logo — light background;
2. full horizontal logo — dark background;
3. stacked logo — light/dark;
4. FC Peak mark;
5. simplified micro/fav icon mark;
6. Crew/community mark;
7. one-color mark;
8. app-icon source artwork, ideally 1024×1024 vector/raster master.

No production SVG was fabricated from the raster board in Phase 1B.

## Homepage communication boundary

The homepage may explain the FitCrew concept and brand promise. It must not imply unfinished behavior is operational.

Safe Phase 1B actions:

- anchor navigation within the homepage;
- view the sign-in placeholder;
- view intentional placeholder pages.

Not represented as live:

- registration;
- authentication;
- groups;
- challenges;
- health connections;
- imports;
- official logs;
- scoring;
- leaderboards;
- fines/badges;
- billing;
- notifications;
- mobile app behavior.

## Phase 1B public-shell layout refinement

The public shell uses the branding-board application example as the structural target rather than reproducing it pixel-for-pixel.

Standing presentation rules:

- keep the global header compact so the hero owns the first viewport;
- use a wide desktop hero instead of a narrow centered content column;
- place the primary tagline in the hero as the dominant message;
- reserve the right side of the hero for community/crew imagery or illustration;
- avoid technical implementation/status copy in the primary public message;
- keep public actions truthful while account behavior remains unavailable;
- collapse navigation cleanly on smaller viewports.

The current Phase 1B hero uses a CSS/brand illustration rather than pretending the concept-board photograph is a production photography asset. A final licensed/approved hero photograph may replace that illustration later without changing the layout contract.

## Asset cache behavior

CSS and JavaScript URLs include a file-modification version query generated from the runtime files. This prevents a browser from continuing to render the retired Phase 1 green stylesheet after a branding update while keeping the asset paths stable.

## Phase 1B public landing-page direction

The public landing page is intentionally concise:

1. compact branded header,
2. full-width hero with a close-knit crew image integrated into the deep-navy/royal-blue hero background,
3. compact `How FitCrew Works` section,
4. compact private-by-design / final CTA section,
5. footer.

The earlier three-pillar `Why FitCrew` section was removed because its message duplicated the hero and increased page length without adding a distinct decision point.

Public CTA hierarchy:

- `Create Account` — primary,
- `Learn More` — secondary,
- `Sign In` — navigation only.

The Phase 1B hero image is an AI-generated FitCrew-specific visual showing a close-knit group looking forward together. It contains no product data, people identities, or implied customer claims.


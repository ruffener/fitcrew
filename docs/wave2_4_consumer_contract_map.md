# FitCrew Challenge — Waves 2–4 Consumer Contract Map

Planning/contract-mapping only. No held runtime is implemented by this artifact.

## Wave 2 — Competition Consumer Surfaces

Future Challenge surfaces consume authoritative server-owned projections for:

- Baseline Progress;
- Current Check-In status;
- Personal Progress;
- Live — Provisional result after governed evidence allows it;
- Official — Week [N];
- Standings and shared ranks;
- corrections/supersession;
- Final Official Results.

Website does not implement BODY_COMPOSITION_V1_0 calculations. Required read models must contain score/result state, published precision, rank/tie state, evidence sufficiency, period context, correction/supersession, explanation/freshness, and certified scoring-version identity.

## Wave 3 — Health Consumer Experience

Future Health UI preserves the progression:

```text
Health connection
→ evidence received
→ qualification status
→ official Challenge measurement
→ authoritative scoring result
```

Required normalized states include Not Connected, Connected, Action Needed, Partial, Paired, Unaccepted Provenance, Source Mismatch, Waiting for FitCrew Challenge, Official, and Corrected Official.

Current Google Health routes remain nonfunctional placeholders. Wave 1 must not imply live provider support.

## Wave 4 — Challenge Monies Consumer Experience

Challenge Monies remains optional and inside Challenge context. Future consumer projections include:

- Challenge Monies Summary;
- Rules View;
- Participant Monies View;
- How the Stack Grew;
- Activity/History;
- Owner Workspace;
- Final Monies Result View.

Website must consume canonical Starting Monies, cap application, Stack, correction, Final Monies Result, and Tie Remainder outputs. It must never calculate those values independently or present a wallet/balance/currency experience.

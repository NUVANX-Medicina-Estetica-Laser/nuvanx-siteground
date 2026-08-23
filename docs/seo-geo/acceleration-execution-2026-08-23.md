# NUVANX SEO/GEO Acceleration Execution — 2026-08-23

## Objective

Maximize qualified visibility and revenue from Google Organic, Google Local/Maps, Google AI surfaces, ChatGPT Search, Bing/Copilot and high-authority medical/local entities in the shortest measurable cycle. No fixed 90-day waiting plan. Each intervention must have an owner, evidence grade, earliest measurable checkpoint and rollback/next action.

## Evidence contract

Every finding is tagged:

- `OBSERVED`: directly visible in the source/surface.
- `VERIFIED`: independently reproduced or confirmed by a primary/internal source.
- `INFERRED`: plausible causal explanation, not proven.
- `UNPROVEN`: insufficient evidence; never promoted to a P0 fix by itself.

Source priority is contextual, not ideological:

1. NUVANX production / Search Console / GBP admin / Doctoralia admin / official sanitary register.
2. Public live Google, Maps, Doctoralia, Bing and AI surfaces.
3. Official platform documentation.
4. Reproducible independent experiments (e.g. Sterling Sky/BrightLocal/Whitespark).
5. Community evidence (Reddit, Local Search Forum, GitHub, Hugging Face) as hypothesis/tooling input.

Hugging Face is explicitly allowed as an experimental infrastructure layer for datasets, agents, extraction, NLP/topic modelling, entity comparison and rapid prototypes. It is not treated as evidence of Google's private ranking formula.

## Current P0 observations

### GBP review path

`inc/data/gbp-profiles.json` currently has empty `place_id` values for Chamberí and Goya. Therefore `nvx_gbp_review_url()` falls back to a generic Google Maps search URL instead of the direct Google review composer. This adds avoidable friction to the existing T+7 review workflow.

Acceptance:

- verify the canonical live GBP entity for each sede from GBP admin;
- store the verified Place ID or verified direct review URL;
- test incognito/mobile that each link opens the correct sede and review composer;
- never infer a Place ID from a stale third-party cache when counts/categories conflict with the live profile.

### GBP semantic services

The website already has strong owner URLs for high-intent services. GBP admin must be inspected and mapped to real services per sede, starting with Google's predefined services where available and adding truthful custom services only where needed.

Earliest experimental checkpoint after a service change: 24–72 hours, then day 7 and day 14. Measure query-specific local grid deltas; do not claim causality from one point measurement.

### Goya entity drift

Public third-party sources still contain legacy Sol Centro / inherited Goya information. Doctoralia Goya must be reconciled against the current official sanitary record and current clinical offering. The legal/official source wins; SEO does not determine the responsible sanitario.

### Reviews

Use the existing neutral T+7 request system for genuine patients. No incentives, review gating, staff quotas or requested review wording. Measure review recency/velocity against the actual query competitors instead of adopting a universal target.

## 72-hour execution queue

### T0–T+6h — unblock conversion and entity truth

1. Verify GBP canonical entity and direct review URL/Place ID for Chamberí and Goya.
2. Verify GBP primary/secondary categories, service editor, website/appointment URLs, hours and phones.
3. Verify official CS20073 responsible sanitario before editing Doctoralia Goya.
4. Capture current local SERP/Maps baseline for Tier-A queries around both sedes.
5. Capture current Google AI / ChatGPT / Perplexity citation baseline for the same clusters.

### T+6–24h — semantic relevance deployment

1. Apply truthful predefined/custom GBP services per sede using `gbp-service-matrix.json` after admin verification.
2. Reconcile Goya Doctoralia service list, team, description and URLs against current source of truth.
3. Ensure sede pages link contextually to their real Tier-A treatments and Tier-A treatment pages link back to the relevant sedes.
4. Verify Googlebot, OAI-SearchBot and PerplexityBot access; keep `llms.txt` informational rather than a Google ranking requirement.
5. Submit changed web URLs through normal sitemap/indexing paths and IndexNow where applicable.

### T+24–72h — first causal read

1. Re-run exact local grids for changed GBP services.
2. Re-run exact Google/AI prompts, preserving location/query/device evidence.
3. Check indexing/canonical/rendering of changed site URLs.
4. Record review-request delivery and direct-link failures.
5. Promote only reproducible movers; revert/no-op tactics that show no signal after appropriate repeated tests.

## Tier-A query clusters

1. `valoracion`: valoración medicina estética Madrid; consulta medicina estética Madrid.
2. `endolift`: Endolift Madrid; Endolift facial Madrid; Endolift papada Madrid; Endolift mandíbula Madrid; Endolift precio Madrid.
3. `co2`: láser CO2 Madrid; láser CO2 fraccionado Madrid; láser cicatrices acné Madrid.
4. `endolaser`: endoláser Madrid; remodelación corporal láser Madrid; grasa localizada láser Madrid.
5. `exion`: EXION Madrid; EXION Face Madrid; EXION Body Madrid; radiofrecuencia fraccionada Madrid.

## Review velocity experiment

For each actual Top-3/Top-10 local competitor in a Tier-A query:

- total public reviews;
- reviews in last 30 days (`RV30`);
- reviews in last 90 days / 3 (`RV90m`);
- days since latest review;
- rating;
- local-grid visibility.

Set an operational target from the competitive distribution (e.g. P75 of `RV90m`), not from an invented universal threshold. Track:

`review requests -> published reviews -> Local Share of Voice -> GBP actions -> leads`.

## External entity distribution

Accelerate corroboration where search/AI already retrieves evidence:

- official manufacturers and technology partners;
- Doctoralia and legitimate medical directories;
- medical/editorial press;
- Madrid/local authority citations;
- expert interviews and original NUVANX clinical decision assets;
- authentic community participation where appropriate.

No fake users, fabricated recommendations, paid disguised endorsements, copied reviews or third-party pages created principally to exploit host authority.

## AI/GEO experimental layer

Use Google query fan-out as a coverage model: one clinical cluster must answer the decision graph through a combination of treatment page, concern hub, doctor, sede, price/investment, cases and Journal—not dozens of thin near-duplicate pages.

Hugging Face/GitHub/local tooling may be used to:

- classify competitor review topics and recency;
- compare entity/NAP/service drift across sources;
- cluster questions and citation sources;
- prototype geo/local rank collection and evidence extraction;
- red-team NUVANX answers against competitor evidence.

Any output remains `INFERRED` until checked against live search/platform evidence.

## Success gates

A Tier-A cluster is not declared won from one ranking screenshot. Require concurrent progress in:

- non-brand organic visibility;
- local grid visibility near the relevant sede;
- citation/retrieval in AI surfaces;
- entity consistency;
- qualified leads/appointments;
- no technical/indexing regression.

Optimization cadence is event-driven: deploy -> earliest valid measurement -> retain/revert/iterate. No arbitrary multi-month waiting period.
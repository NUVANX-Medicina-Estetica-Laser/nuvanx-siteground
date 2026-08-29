# Architecture

This document describes durable repository architecture. Runtime values and current project status belong in code/data, Issues, PRs and Actions evidence rather than here.

## Runtime boundary

NUVANX is a WordPress site whose repository-owned runtime is the custom theme at:

`wp-content/themes/nuvanx-medical/`

The theme is responsible for presentation, governed page/content data, SEO/schema integration, conversion/consent integration and the public deployment identity surface. WordPress core, hosting infrastructure and third-party services remain external runtime dependencies.

## Source-of-truth layers

The architecture favors explicit machine-readable ownership instead of duplicated literals:

- `inc/data/publication-manifest.json` — canonical public publication topology.
- `inc/data/routes.json` + schema — route metadata/contracts.
- `inc/data/tariff-catalog.json` — tariff/pricing data used by renderers.
- `inc/data/clinical-matrix.json` and governed clinical JSON — clinical/content ownership.
- `inc/data/seo-metadata.json` and related governed metadata — repository-owned SEO values.
- `assets/css/nvx-tokens.css` — canonical visual tokens.
- `dist/manifest.json` — generated asset mapping; generated CSS must remain reproducible from source.

PHP renderers consume these owners; Markdown must not become a competing source of changing production values.

## Document rendering governance

The rendered document has one canonical theme-owned output path. Global document behavior is implemented through the theme modules and verified by contracts rather than by manual checklists.

Key invariants:

- do not introduce broad full-document output buffering as a presentation mechanism;
- keep document/head/body ownership deterministic and source-scoped;
- preserve exactly one effective H1 per governed page unless a specific contract says otherwise;
- preserve canonical metadata/schema ownership and prevent duplicate emitters;
- keep environment/release identity visible through the governed deployment markers;
- treat WordPress/plugin output as an integration boundary that may be normalized only through narrow, tested hooks.

Relevant implementation lives under `inc/nvx-document-*.php`, SEO/schema modules and `scripts/lint/` / `scripts/staging2/` contracts.

## Visual system

The production theme uses one shared design system. New pages should compose existing tokens and components rather than create page-local systems.

Canonical principles:

- spacing, typography, radius, shadow, z-index and motion derive from shared tokens where available;
- responsive behavior must be defined intentionally rather than fixed through viewport-specific patches;
- shared header/footer/CTA/form patterns remain visually and behaviorally consistent across routes;
- generated assets under `dist/` must match their source build;
- Figma/design references are calibration inputs, not runtime sources of truth. Once implemented and tested, the repository tokens/components are authoritative.

## Integration ownership

External services are integrated through explicit contracts:

- HubSpot form/attribution ownership is implemented in theme integration modules and verified by static/runtime tests.
- Google/Search tooling is isolated under `scripts/seo/`; Production owns Search Console post-release execution where applicable.
- Meta browser ownership is intentionally retired in the theme; server-side/event ownership is governed separately.
- Consent behavior must preserve the Complianz/Google consent contracts verified by the repository tests.

No extra GitHub workflow should be created for an integration-specific task.

## Release architecture

There are exactly two workflow orchestration surfaces:

1. `staging.yml` validates and deploys a candidate to Staging2 and can produce immutable production-eligible exact-SHA evidence.
2. `production.yml` manually promotes an accepted exact SHA or executes read-only verification/probe modes.

The shell/Node/PHP files under `tools/` and `scripts/` are implementation components, not alternate authorization paths.

See [`operations/deployment.md`](operations/deployment.md) for operational sequencing, transient classification and rollback semantics.

## Documentation architecture

Keep documentation small and role-based:

- `README.md` — navigation and repository overview.
- `AGENTS.md` — execution rules.
- `SECURITY.md` — current security policy.
- this file — stable architecture.
- `docs/operations/` — only durable operational contracts that cannot be represented more clearly by executable code/data.
- local README files — only when a tool/package has a distinct durable ownership boundary.

Do not commit issue mirrors, dated audit reports, completed one-time procedures, temporary checklists or historical troubleshooting journals. Git history and GitHub already preserve that evidence.

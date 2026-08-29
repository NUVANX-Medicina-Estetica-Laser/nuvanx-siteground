# NUVANX SiteGround

Canonical source repository for the NUVANX WordPress theme, release tooling and production verification contracts.

The repository deliberately keeps **two GitHub Actions workflows only**:

- `.github/workflows/staging.yml` — quality gates, Staging2 deployment, rollback protection and immutable exact-SHA acceptance evidence.
- `.github/workflows/production.yml` — manual production release, verification-only audits and SSH connectivity probes.

Production is never authorized by a push alone. A release must use an exact candidate SHA with valid Staging acceptance evidence and must pass the guarded Production workflow.

## Canonical sources

| Concern | Source of truth |
| --- | --- |
| Repository/release architecture | [`docs/architecture.md`](docs/architecture.md) |
| Staging, Production, SSH, rollback and transient failures | [`docs/operations/deployment.md`](docs/operations/deployment.md) |
| Agent/contributor execution rules | [`AGENTS.md`](AGENTS.md) |
| Security policy | [`SECURITY.md`](SECURITY.md) |
| Clinical Endolaser content approval | [`docs/operations/endolaser-clinical-content-gate.md`](docs/operations/endolaser-clinical-content-gate.md) + `docs/approvals/endolaser-content-approval.json` |
| Tariff rendering | [`docs/operations/tariff-shortcode-usage.md`](docs/operations/tariff-shortcode-usage.md) + `wp-content/themes/nuvanx-medical/inc/data/tariff-catalog.json` |
| SEO/Search tooling | [`scripts/seo/README.md`](scripts/seo/README.md) |
| Retained migrations | [`tools/migrations/README.md`](tools/migrations/README.md) |
| Theme visual implementation | `wp-content/themes/nuvanx-medical/assets/css/`, especially `nvx-tokens.css` |
| Publication inventory | `wp-content/themes/nuvanx-medical/inc/data/publication-manifest.json` |

Dynamic project status does **not** belong in Markdown. Open work and blockers live in GitHub Issues/PRs; run evidence lives in GitHub Actions artifacts and logs; historical decisions remain available through Git history.

## Repository layout

- `wp-content/themes/nuvanx-medical/` — production WordPress theme and governed data.
- `scripts/lint/` — static and semantic contracts.
- `scripts/staging2/` — browser/runtime acceptance tooling.
- `scripts/production/` — production boundary, identity and audit tooling.
- `scripts/seo/` — Search Console, Google/Search diagnostics and support package.
- `tools/deploy/` — guarded deployment implementation helpers; not independent release authorization surfaces.
- `tools/migrations/` — bounded retained migration/audit tooling.
- `docs/` — durable architecture and operational contracts only.

## Development checks

The authoritative gate list is the current workflow/code, not prose. At minimum, before proposing a runtime change:

```bash
npm run build:css
npm run lint:manifest
find wp-content/themes/nuvanx-medical -path '*/vendor' -prune -o -name '*.php' -type f -print0 | xargs -0 -n1 php -l
bash scripts/ci/test-mutation-fifo-contract.sh
```

Use the same PHP/Node versions and exact commands enforced by Staging for release-significant work.

## Release rule

1. Change code/data on a branch and pass PR checks.
2. Merge to `master`.
3. Obtain a successful canonical Staging run for the exact `master` SHA and its immutable acceptance evidence.
4. Dispatch Production manually with that exact accepted SHA.
5. Treat the release as valid only after the mandatory production boundary/identity gates pass.

Never deploy directly through WordPress administration, ad-hoc SSH, WPVibe, or a temporary workflow to bypass this chain.

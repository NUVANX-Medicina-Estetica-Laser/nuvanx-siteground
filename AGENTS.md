# NUVANX repository execution rules

These rules apply to agents and contributors working in this repository.

## 1. Canonical release model

- The repository supports exactly two workflows: `.github/workflows/staging.yml` and `.github/workflows/production.yml`.
- Do not add one-time, temporary, migration-specific or diagnostic workflows. Use repository scripts and the existing canonical workflows.
- Production release authorization is manual and exact-SHA. Never infer Production eligibility from `master` advancing.
- Do not bypass Staging acceptance through direct WordPress writes, ad-hoc SSH deployment, WPVibe writes or workflow self-mutation.
- A runtime-changing merge advances the candidate SHA and therefore requires fresh exact-SHA Staging acceptance before Production promotion.

See [`docs/operations/deployment.md`](docs/operations/deployment.md) for the full operational contract.

## 2. Evidence before conclusions

Distinguish application defects from infrastructure or configuration failures.

Classify failures using the evidence produced by the canonical scripts:

- `FAIL_REAL` — candidate/application defect established by deterministic evidence.
- `TRANSIENT_INFRASTRUCTURE` / exit `75` — transport or temporary infrastructure condition; release remains blocked because evidence is incomplete, but candidate defect is not established.
- `FAIL_CONFIG` / exit `78` — configuration/precondition failure; do not patch application code to hide it.
- SSH exit `255`, bounded TCP timeouts, or verified SiteGround challenge/HTTP `202` are transport/edge signals unless an independent application failure is also proven.

A red workflow is not, by itself, proof of a code regression. Read the failing step and its artifacts/logs before changing source.

## 3. Mutation safety

- Preserve the FIFO mutation contract. Do not weaken cross-workflow serialization.
- Do not rerun a mutating Production/Staging job when the FIFO contract says `rerun_forbidden`; start a new canonical run instead.
- Never disable strict SSH host-key verification.
- Never remove backup, rollback, exact-SHA identity or production-boundary gates to make a release green.
- Do not rewrite already-applied migrations. Add a new bounded migration when a data change is genuinely required.

## 4. Source-of-truth discipline

Prefer executable/data contracts over prose:

- publication topology: `wp-content/themes/nuvanx-medical/inc/data/publication-manifest.json`
- routes: `wp-content/themes/nuvanx-medical/inc/data/routes.json`
- tariffs: `wp-content/themes/nuvanx-medical/inc/data/tariff-catalog.json`
- design tokens: `wp-content/themes/nuvanx-medical/assets/css/nvx-tokens.css`
- clinical content approval: `docs/approvals/endolaser-content-approval.json`
- workflow behavior: the current canonical YAML and invoked scripts

Do not duplicate changing values into Markdown unless the Markdown is the actual owner of that value.

## 5. Documentation hygiene

Markdown is for durable contracts, not project journals.

- Put current blockers/status in GitHub Issues or PRs.
- Put run evidence in Actions logs/artifacts.
- Let Git history preserve superseded investigations and one-time procedures.
- Do not add dated audit dumps, temporary checklists, issue mirrors or completed one-timer instructions to `docs/`.
- When a durable rule changes, update the smallest canonical document instead of creating another overlapping file.

## 6. Change quality

Before changing or deleting code:

1. find all consumers/references;
2. verify the owning source of truth;
3. run the relevant static/semantic tests;
4. keep the diff scoped;
5. state what was directly verified versus inferred;
6. avoid placeholders, mocks or silent fallbacks in release-critical paths.

For theme changes, preserve the existing design system and use tokens/components rather than introducing page-local visual systems. See [`docs/architecture.md`](docs/architecture.md).

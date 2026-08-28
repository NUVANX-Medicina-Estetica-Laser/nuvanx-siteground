# Deployment operations

Mutating deploy scripts require `--confirm` or `NUVANX_CONFIRM=yes`.

## Identity and invariants

- Canonical source branch: `master`
- Deployment identity: full lowercase 40-character Git SHA
- Staging2: `https://staging2.nuvanx.com`
- Production: `https://nuvanx.com`
- Live theme marker: `wp-content/themes/nuvanx-medical/.nvx-deploy-sha`
- Persistent GitHub Actions workflows: exactly **two**
- Cross-environment mutation lock: `nuvanx-environment-mutation`

Branch names, tags and release-control files express intent. They are not proof of what is live. The environment marker plus successful validation evidence are authoritative.

## Canonical workflow model

Only these workflows are persistent:

1. `.github/workflows/staging.yml`
2. `.github/workflows/production.yml`

The same two workflow blobs are kept on `master` and `release/production`. Repository hygiene inside `staging.yml` rejects any future third workflow.

### Staging

`staging.yml` owns the complete Staging2 lifecycle in one workflow:

- static repository, PHP, JavaScript and design-system gates;
- exact-SHA Staging2 deployment from `master`;
- strict environment-isolation checks;
- rollback snapshot of theme, required MU plugins and Staging2 database;
- required MU-plugin and content-hygiene deployment;
- cache purge and exact `.nvx-deploy-sha` verification;
- public Staging2 boundary validation;
- WordPress template validation;
- canonical Block C browser acceptance and valoración-placement validation;
- read-only proof that production remained unchanged;
- automatic full Staging2 rollback after a failed mutation;
- same-repository, label-gated PR preview using trusted `master` tooling.

A relevant push to `master` can mutate **Staging2 only**. It never authorizes production.

The production-eligible Staging2 evidence artifact is:

```text
staging2-block-c-<sha>
```

The runtime acceptance inventory is dynamic and validates every trusted published WordPress page at the configured viewports. Canonical manifest membership is enforced before the browser matrix runs.

**Failure interpretation is part of the deployment contract.** Before diagnosing a red Staging/PR-preview job as a candidate regression, apply the canonical taxonomy in [`staging-transient-classification.md`](./staging-transient-classification.md). In particular, `EX_TEMPFAIL (75)`, a classified SiteGround `202`/captcha challenge, SSH transport `255`, and `FAIL_CONFIG (78)` do not establish an application defect. They remain NO-GO because evidence is incomplete and require targeted recovery or a fresh exact-SHA run. Only `FAIL_REAL` or equivalent non-transient application/contract evidence establishes a candidate defect.

### Production

`production.yml` owns the complete production lifecycle in one workflow:

- read-only Staging2 and production identity gate;
- resolution of the SHA **actually deployed on Staging2** from `.nvx-deploy-sha`;
- verification that the live Staging2 SHA is contained in `origin/master`;
- verification of a successful, non-expired exact-SHA `staging2-block-c-<sha>` artifact from `master`;
- exact candidate materialization from Git history;
- strict production SSH/preflight checks;
- guarded atomic production cutover through `tools/deploy/deploy-to-prod.sh`;
- exact public and on-disk production SHA verification;
- SEO/GEO, document-title and IndexNow post-release validation;
- optional Lighthouse matrix and optional live HubSpot E2E for explicit manual runs.

Staging and production use the same `nuvanx-environment-mutation` concurrency group. A Staging2 mutation therefore cannot advance the live staging payload while Production is resolving, validating and promoting it.

## Production authorization

Production is dispatched manually from the `Production` workflow with the requested candidate SHA.

On every release, `production.yml` runs `scripts/ci/verify-staging-acceptance.sh` to require exact, immutable, successful acceptance evidence (`staging2-block-c-<sha>`) from a completed canonical `master` Staging run before any production mutation. Production also acquires a FIFO environment mutation turn via `scripts/ci/wait-for-environment-mutation-turn.sh`.

**Never infer acceptance from `master` advancing alone.** The correct acceptance signal is a completed canonical Staging run that produced a successful `staging2-block-c-<sha>` artifact. This artifact must:
- Be from a `master` branch run (not a fork or PR)
- Contain the exact SHA being promoted
- Have passed all browser acceptance tests (Block C matrix)
- Be verified as non-expired and unmodified

Simply having the SHA on `master` is insufficient for production authorization. The artifact provides immutable proof that the specific SHA passed all acceptance tests on Staging2.

This removes the stale-manifest race that can occur when Staging2 advances after a release candidate file was written.

## Trigger ownership

Only canonical workflows `staging.yml` and `production.yml` are authorized to use `GITHUB_TOKEN` for repository mutations. Any third workflow attempting to mutate the repository via the GitHub API will be rejected by the repository hygiene gate in `staging.yml`.

This GITHUB_TOKEN recursion invariant prevents workflow self-mutation attacks where a malicious or transient workflow could modify its own definition or create new workflows. The canonical workflows enforce this by checking that exactly two workflow files exist (`staging.yml` and `production.yml`) and rejecting any additional or modified workflow configurations.

**Example:** The staging2 workflow file (`.github/workflows/staging.yml`) is one of the two trusted workflows that owns the complete Staging2 lifecycle. Any attempt to create a third workflow file (e.g., `staging2-helper.yml`) would be detected and rejected by the repository hygiene check.

## Reconciliación del contrato de HubSpot

La reconciliación del formulario canónico de valoración es una operación manual, limitada y auditable. Se ejecuta exclusivamente desde `staging.yml` mediante el input `reconcile_hubspot_attribution=true`; no se activa en los pushes ordinarios. El runner ejecuta `scripts/ci/provision-hubspot-attribution-contract.sh --apply` sólo bajo ese input, con `NUVANX_CONFIRM=yes` dentro del entorno efímero y con el secreto de repositorio `HUBSPOT_ACCESS_TOKEN`.

El reconciliador puede crear únicamente las propiedades administradas por el contrato y añadir al formulario canónico los campos faltantes como ocultos. Antes de autorizar una candidata, verifica que cada campo de atribución sea único, oculto, opcional y mantenga su tipo semántico; el control QA `nvx_is_test_lead` debe conservarse como `single_checkbox`, mientras que el resto se mantiene como `single_line_text`.

Tras una reconciliación manual debe completarse una nueva aceptación de Staging2 para el SHA exacto. La evidencia debe demostrar un nuevo submit QA con el mismo `nvx_lead_id` en formulario first-party, HubSpot y `public.web_lead_captures`, donde `is_test_lead = true`, `reconciliation_status = 'qa_suppressed'` y `applied_lead_id IS NULL`. No puede existir proyección a Deals, ni conversión hacia Google Data Manager, ni otra salida comercial de QA. Una reconciliación satisfactoria por sí misma nunca autoriza una promoción a producción.

## Staging2 secrets

Required:

- `STAGING2_SSH_HOST`
- `STAGING2_SSH_PORT`
- `STAGING2_SSH_USER`
- `STAGING2_SSH_PRIVATE_KEY`
- `STAGING2_SSH_KNOWN_HOSTS`

Required only for the manual HubSpot reconciliation gate:

- `HUBSPOT_ACCESS_TOKEN`

## Production secrets

Required for production mutation and production-origin audits:

- `PROD_SSH_HOST`
- `PROD_SSH_PORT`
- `PROD_SSH_USER`
- `PROD_SSH_PRIVATE_KEY`
- `PROD_SSH_KNOWN_HOSTS`

## Local Staging2 acceptance

Install the scoped dependencies, then execute the resilient entrypoint from the repository root:

```bash
cd scripts/staging2
npm ci --ignore-scripts
npx playwright install chromium
cd ../..
EXPECTED_SHA=<40-char-sha> BASE_URL=https://staging2.nuvanx.com node scripts/staging2/block-c-entrypoint.mjs
# Note: ORIGIN_SSH_ALIAS (defaults to 'nvx-staging2') requires SSH host configuration for published post WP-CLI inventory
EXPECTED_SHA=<40-char-sha> BASE_URL=https://staging2.nuvanx.com ORIGIN_SSH_ALIAS=nvx-staging2 node scripts/staging2/valoracion-placement.mjs
```

The Staging acceptance runners adhere to the following exit-code contracts. The detailed cross-run interpretation is canonicalized in [`staging-transient-classification.md`](./staging-transient-classification.md).

### Valoración and quality orchestrator (`valoracion-placement.mjs`)
The orchestrator sequences three isolated validation stages:
1. **SiteGround transient classifier (`test-siteground-transient-classifier.mjs`)**: deterministic classifier unit test suite (exit `0` on pass, exit `1` on regression).
2. **Governed blog head contract (`governed-blog-head-contract.mjs`)**: validates SEO, titles, canonicals, og:url, and `noindex` across published journal entries with 4 bounded retries per post (exit `0` on pass, exit `1` on real assertion failure, exit `75` on transient challenge exhaustion with Staging2 rollback explicitly disarmed).
3. **Valoración placement runner (`valoracion-placement-resilient.mjs`)**: validates visual geometry, SHA meta tags, and HubSpot interactive mounting across viewports, automatically retried across up to 3 outer QA cycles on transient failure (`EX_TEMPFAIL` 75) with backoff to absorb transient mount jitter (exit `0` on pass, exit `1` on real assertion failure, exit `75` on transient exhaustion with rollback triggered).
- `0`: All stages passed (`STAGING_ACCEPTANCE_COMPONENT=PASS`).
- `1`: Real assertion failure (`VALORACION_PLACEMENT=FAIL_REAL` or real contract defect). Fails immediately on the first cycle to preserve failure evidence and save CI time.
- `75` (`EX_TEMPFAIL`): Transient challenge exhaustion. If originating from the governed blog head stage, Staging2 rollback is disarmed (`STAGING_MUTATION_ARMED=0`); if originating from the valoración placement runner (`VALORACION_PLACEMENT=TRANSIENT_ONLY`), Staging2 rollback is triggered. Diagnostics are written to GitHub Step Summary. This is an evidence NO-GO, not proof of a candidate defect.

### Block C matrix runner (`block-c-entrypoint.mjs` / `block-c-matrix.mjs`)
- `0`: Validation passed (`BLOCK_C_RESILIENT=PASS` or a successful targeted-recovery PASS). All published routes/viewports have complete required acceptance evidence. Eligible for the Block C portion of Production acceptance.
- `1` or another non-transient code with `FAIL_REAL`: Real browser/contract assertion failure or malformed application evidence. This establishes a candidate/contract defect and remains a hard NO-GO.
- `75` (`EX_TEMPFAIL`): `TRANSIENT_INFRASTRUCTURE`; candidate defect **not established**. The full 228-case matrix runs once per runner. Isolated transient route/viewport evidence is revalidated through bounded targeted public-browser recovery rather than replaying the full matrix. If evidence still cannot be completed, the run remains ineligible for Production and a fresh exact-SHA acceptance run is required.
- `78` (`EX_CONFIG`): CI/configuration contract failure; candidate defect **not established**. Correct the acceptance configuration and rerun the same exact candidate where applicable.
- SSH/preflight `255`: transport/infrastructure failure; candidate defect **not established**. Do not patch candidate code based only on this signal.

A red GitHub job can therefore mean either a real candidate failure **or** a deliberately blocking transient/configuration state. Inspect the explicit `BLOCK_C_*` classification marker before attributing the failure to application code. `TRANSIENT_INFRASTRUCTURE` is still NO-GO; it is simply not a demonstrated code regression.

## Repository hygiene

Repository hygiene is part of `staging.yml`; it is no longer a separate workflow. It rejects transient one-shot workflows, workflow self-mutation, tracked generated/local debris, empty/editor-residue files and any `.github/workflows` state other than `production.yml` plus `staging.yml`. Gitleaks runs on the applicable trusted Staging workflow paths.

Operational evidence belongs in GitHub Actions artifacts or Git history, not as permanent root-level audit dumps.

## Release record

For every production release retain:

- exact live-and-accepted Staging2 SHA;
- exact Block C run/artifact;
- environment identity evidence;
- rollback/backup evidence;
- production public-boundary evidence;
- post-release audit evidence.

Never store secret values in repository documents or HTML.

# Deployment and release operations

This is the single durable runbook for Staging2, Production, SSH transport, mutation ordering, rollback and release evidence.

The current workflow YAML and invoked scripts are authoritative when implementation details differ from prose.

## 1. Canonical workflows

The repository supports exactly:

- `.github/workflows/staging.yml`
- `.github/workflows/production.yml`

Do not create one-time, diagnostic, migration-specific or integration-specific workflows. Reusable logic belongs in scripts invoked by one of these two workflows.

## 2. Release state machine

A normal release follows this order:

1. PR/static checks on the proposed change.
2. Merge to `master`.
3. Staging run for the exact resulting `master` SHA.
4. Successful immutable Staging acceptance evidence for that exact SHA.
5. Manual Production dispatch for the same SHA.
6. Production preflight, backup and guarded cutover.
7. Exact production boundary/identity verification.
8. Required post-cutover gates; compensating rollback if a mandatory post-cutover gate fails.

A previous Staging acceptance cannot authorize a newer SHA. Any merge that advances `master` creates a new candidate.

## 3. Staging2

Staging2 is the production-eligibility environment, not an informal preview.

The canonical Staging workflow owns:

- repository/static quality gates;
- secret scanning;
- FIFO mutation ordering;
- strict SiteGround SSH setup;
- rollback snapshot before runtime mutation;
- environment isolation checks;
- immutable candidate deployment;
- cache purge and exact deployment identity;
- publication/sitemap and public-boundary verification;
- page/template/browser acceptance;
- read-only confirmation that Production was not changed;
- immutable acceptance artifact creation.

The workflow may use multiple fresh hosted runners serially to survive transient SiteGround transport failures. A later runner may skip work only when the same run already produced its valid completion marker.

### Exact-SHA Lighthouse performance gate

The Staging job `Exact-SHA Staging Lighthouse performance gate` runs only after `deploy_staging` succeeds. It verifies that Staging still holds the candidate SHA, then measures a 6-route × mobile/desktop matrix with Lighthouse `12.8.2` (Home, Endolift facial, Endoláser corporal, Medicina estética, Valoración, Blog; 3 attempts per cell; median of valid runs).

Modes:

- `baseline` — current push default. Captures metrics and SHA-bound artifacts; never blocks release eligibility.
- `enforce` — fail-closed on material regression beyond bounded LCP/CLS/TBT/TTFB/performance-score budgets.

SiteGround/transport transients are classified separately from Lighthouse or application regressions. A transient does not prove a performance defect. Incomplete evidence also does not become Production eligibility.

Do not change the push default from `baseline` to `enforce` until all of the following are true:

1. an exact-SHA Staging acceptance has actually reached the performance job;
2. enough valid per-route/mode runs exist to quantify SiteGround/CI variability;
3. budgets are reviewed against that empirical baseline rather than only the generic defaults in `scripts/staging2/lighthouse-performance-gate.mjs`;
4. a real regression is shown to block eligibility while a verified transient does not.

Manual `workflow_dispatch` may select `enforce` for a specific SHA once a baseline exists. Load/concurrency testing is a separate Staging-only front and must not run against Production from CI.

The optional Production Lighthouse job (`run_performance`, default false) is a post-release matrix. It is not a Staging acceptance gate and does not authorize or block cutover.

## 4. Production

Production is **manual-only**. A successful Staging run proves eligibility but does not authorize release.

Normal release requirements:

- candidate is a full lowercase 40-character SHA;
- candidate is contained in `master`;
- normal `release` mode requires candidate == current `master` HEAD;
- candidate has successful immutable Staging acceptance evidence;
- release tooling is materialized from the exact candidate;
- production environment identity/preconditions pass before mutation, including `DB_NAME` matching the provisioned Production database (`PROD_DB_NAME` from GitHub secrets or vars; the workflow may fall back to the canonical database name only when both are unset).

Production may also be run with `deploy=false` for verification-only audits, or in SSH connectivity probe mode. Those modes must not perform a cutover.

### Do not rerun mutating jobs

The FIFO contract intentionally rejects a GitHub Actions rerun of a mutating run/attempt:

`MUTATION_FIFO=FAIL reason=rerun_forbidden ... action=start_new_run`

When a Staging/Production mutation run fails and a retry is appropriate, start a **new canonical workflow run**. Do not use “Re-run failed jobs” to bypass mutation ordering.

## 5. Mutation ordering

Each workflow run has a unique GitHub concurrency group. Cross-workflow ordering is enforced by `scripts/ci/wait-for-environment-mutation-turn.sh`.

The FIFO contract exists so a newer Staging or Production run cannot overtake an older pending mutation. Do not weaken, bypass or replace this with a single GitHub concurrency slot that can discard pending SHAs.

## 6. SiteGround SSH

SSH configuration is centralized in `scripts/production/configure-siteground-ssh.sh` and used by both canonical workflows.

Security/transport rules:

- strict host-key verification is mandatory;
- primary and approved fallback endpoints are attempted with bounded retries;
- no `StrictHostKeyChecking=no`;
- credentials remain in GitHub secret surfaces;
- transport classification is separate from application classification.

### Failure classification

Use evidence, not the workflow color alone:

| Class | Meaning | Action |
| --- | --- | --- |
| `FAIL_REAL` | deterministic candidate/application failure | fix source/data, then obtain new exact-SHA acceptance |
| `TRANSIENT_INFRASTRUCTURE` / `75` | temporary transport/infrastructure failure | candidate defect not established; start a fresh run when retrying |
| `FAIL_CONFIG` / `78` | missing/invalid environment or configuration precondition | correct configuration; do not patch app code to mask it |
| SSH `255` / bounded TCP timeout | transport exhausted | no mutation should be inferred unless logs prove a later mutation step ran |
| SiteGround HTTP `202` challenge | public-edge anti-bot condition | use the workflow's deterministic origin/approved fallback evidence; `202` alone is not PASS or app failure |

Staging acceptance is fail-closed: incomplete evidence never becomes production eligibility.

## 7. Production mutation and rollback

The guarded Production path must preserve this sequence:

1. validate exact candidate and accepted tooling;
2. wait for mutation turn;
3. configure strict SSH;
4. verify Production preflight;
5. upload exact accepted payload/tooling to an isolated remote release path;
6. create the rollback snapshot required by `deploy-to-prod.sh`;
7. execute guarded atomic cutover;
8. verify public/origin boundary and exact disk SHA;
9. verify the full deployment identity chain;
10. run required origin audits;
11. compensate automatically if a mandatory post-cutover gate fails;
12. clean remote release payload and temporary identities.

Never deploy the theme by copying files directly into Production outside this transaction.

## 8. Deployment identity

A valid release must converge on one identity across the theme/disk marker, deployment stamp and rendered public metadata. The workflow verifies the expected SHA and run identity after cutover.

Do not manually edit `.nvx-deploy-sha` or `.nvx-deploy-stamp.json` to manufacture a PASS. They are outputs of the canonical deploy transaction.

## 9. Migrations

Production deployment owns only the explicit retained migration set declared by the deployment script/workflow. Required migration payload and the deployment script must remain in lockstep.

Rules:

- snapshot before mutation;
- no destructive ad-hoc SQL in the workflow;
- do not rewrite an already-applied historical migration;
- delete completed one-time migrations once no compatibility guard/runtime dependency requires them;
- preserve fail-closed post-migration audits.

See [`../../tools/migrations/README.md`](../../tools/migrations/README.md).

## 10. Helper ownership

`tools/deploy/` contains implementation helpers, not separate release paths:

- `deploy-to-staging2.sh` — Staging deployment implementation.
- `deploy-to-prod.sh` — guarded Production deployment implementation with rollback contract.
- `siteground-cache-purge.sh` — canonical SiteGround cache purge owner. Staging post-deploy and post-migration purge must call this helper; do not add a second inline `wp sg purge` in workflow YAML.

`scripts/production/` contains identity, boundary, SEO/origin, SSH and compensating-rollback tooling.

Do not execute these helpers as an informal substitute for the canonical workflows.

## 11. Post-release audits

Production can run reusable optional verification jobs without creating new workflows, including:

- SEO/GEO + Search Console + IndexNow;
- Lighthouse performance matrix;
- HubSpot zero-submit contract verification.

These are retained because they are reusable production controls, not one-time workflows. The reusable redacted scanner `tools/migrations/scan-forensic-source.py` remains a release-regression dependency.

## 12. Evidence and documentation

- Actions logs/artifacts are the evidence for a specific run/SHA.
- GitHub Issues/PRs own current blockers and remediation state.
- Markdown documents describe stable operational rules only.
- Git history preserves retired investigations and superseded procedures.

Do not add a new Markdown file merely to record one deployment, one incident or one audit result.
 

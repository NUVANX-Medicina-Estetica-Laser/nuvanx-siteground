# Deployment helpers

Mutating scripts require `--confirm` or `NUVANX_CONFIRM=yes`.

| Script | Purpose |
|--------|---------|
| `deploy-to-staging2.sh` | Guard Staging2 identity, PHP lint, backup, rsync theme, stamp SHA and purge caches |
| `deploy-to-prod.sh` | Guarded production promotion implementation for the canonical GitHub Actions release |
| `flush-prod-cache.sh` | Flush WordPress and SiteGround optimizer caches for the production root |

## Workflow ownership

The shell scripts in this directory are implementation helpers. Release orchestration is owned by the canonical GitHub workflows:

- `.github/workflows/staging.yml` - Complete Staging2 lifecycle
- `.github/workflows/production.yml` - Production promotion with SEO/GEO audits

A relevant push to `master` can automatically deploy **Staging2 only** through `staging.yml`. Production deployment is explicitly dispatched through `production.yml` and requires verified, immutable exact-SHA Staging2 acceptance evidence before mutation.

`deploy-to-prod.sh` is not an independent authorization surface. It requires the numeric `GITHUB_RUN_ID` supplied by the canonical Production workflow and refuses a host-level invocation that cannot produce the same immutable four-field deployment identity consumed by the production boundary verifiers.

See [`docs/operations/deployment.md`](../../docs/operations/deployment.md) for the canonical release model.

## Failure classification before remediation

Before changing application code because a Staging2 or deployment job is red, read [`AGENTS.md`](../../AGENTS.md) and [`docs/operations/staging-transient-classification.md`](../../docs/operations/staging-transient-classification.md).

Do not classify these signals as application regressions without independent `FAIL_REAL` evidence:

- `EX_TEMPFAIL` / exit `75` / `TRANSIENT_INFRASTRUCTURE`;
- SiteGround HTTP `202`, antibot/captcha/challenge responses confirmed by the classifier;
- `EX_CONFIG` / exit `78` / `FAIL_CONFIG`;
- SSH transport/preflight exit `255`.

These outcomes still block release because exact-SHA evidence is incomplete, but the correct diagnosis is `candidate_defect=not_established`. For isolated Block C transients, use the bounded targeted-recovery path; never restore full-matrix replay merely to retry one route/viewport.

## Migrations are separate from deploys

One-time or bounded data migrations do not belong in this directory. Retained migration tooling lives under [`tools/migrations/`](../migrations/).

The currently retained CMS cleanup migration is documented in [`tools/migrations/README.md`](../migrations/README.md). It remains only because active theme compatibility guards explicitly depend on evidence that the migration has completed.

The shared content-hygiene migration and the divergence audit are the sole exception: `deploy-to-prod.sh` runs `tools/migrations/content-hygiene-shared.php` and `tools/migrations/audit-content-divergence.php` inside the atomic post-cutover window. If either the `MIGRATION_OK` or the `AUDIT_CLEAN` status is missing, the deploy rolls back both the previous theme and the database snapshot together. No other migration may be executed as part of routine deployment.

To prevent editorial content changes (e.g. H1 text) from triggering full rollbacks, the audit runs twice:
- Pre-cutover: checks only non-migratable issues (legal page H1, missing pages) and fails fast on those
- Post-migration: requires full AUDIT_CLEAN including string/regex hygiene rules after migration fixes them

**Important changes:**
- The workflow step "Run shared content migration" has been removed. The migration now executes only once, inside deploy-to-prod.sh's atomic post-cutover window.
- BACKUP_DIR has been moved outside the document root to `$PROD_PARENT/.nvx-backups/` to prevent HTTP exposure of the database dump.
- Production promotion has one authorization path: `.github/workflows/production.yml`. Direct host invocation of `deploy-to-prod.sh` is intentionally rejected without a numeric GitHub Actions run ID.

## Host-level maintenance

Host-level maintenance may perform non-release operations such as an explicit cache flush. It must not be used to bypass Staging acceptance or create a production release identity.

Production cache flush:

```bash
NUVANX_CONFIRM=yes bash tools/deploy/flush-prod-cache.sh \
  --wp-root /home/customer/www/nuvanx.com/public_html \
  --confirm
```
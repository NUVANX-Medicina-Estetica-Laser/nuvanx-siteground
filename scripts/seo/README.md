# SEO / Google Ads support tooling

This directory contains the repository-owned Google/Search support package. It is **not website runtime code** and it does not own an additional GitHub Actions workflow.

The repository currently keeps exactly three canonical workflows: `gemini-pr-reviewer.yml`, `staging.yml`, and `production.yml`. SEO/Search tooling is consumed through those existing contracts rather than through a separate workflow.

## Package and CI boundary

`scripts/seo` remains an independent Node package because its Google Ads, Search Console and GTM dependencies are operational tooling rather than WordPress runtime dependencies.

Current contract:

- the required `Static quality and repository integrity` gate reaches `scripts/ci/test-release-regression-contract.sh` through `scripts/ci/test-mutation-fifo-contract.sh`; that release contract syntax-checks every top-level `scripts/seo/*.js` file with `node --check`;
- the same release contract runs `npm audit --audit-level=high` for `scripts/seo` on the weekly Staging schedule and when a governed dependency lock changes;
- dependency changes must update `scripts/seo/package-lock.json` with npm;
- credentialed mutating Google/GTM helpers are never run automatically;
- `index-pages.js` is different: Production separately syntax-checks it, installs the `scripts/seo` package with `npm ci --ignore-scripts`, and owns its execution for Search Console URL Inspection when the production post-audit path and GSC authentication are enabled;
- no SEO-specific GitHub Actions workflow should be added while repository hygiene requires the three canonical workflows above.

## Current ownership inventory

### Production/release-critical

- `index-pages.js` — Search Console URL Inspection utility. Production syntax-checks it as exact-candidate tooling, installs this package with `npm ci --prefix scripts/seo --ignore-scripts`, and may execute it against the canonical sitemap URL inventory during post-release audits. **Do not remove or relocate this file without updating `production.yml`, the package boundary, artifacts paths, and the release regression contract in the same change.**

### Read-only manual diagnostics

- `google-ads-list-campaigns.js` — bounded Google Ads credential/API and campaign diagnostic. Credentials are supplied privately; output is intentionally constrained so secrets and sensitive request metadata are not printed.
- `classify-google-credential.js` — local presence/shape classifier for a Google Ads credential bundle. It reports classes/counts rather than secret values.
- `gsc-client.js` — shared Search Console helper used by `gsc-full-analysis.js`; owns authentication, bounded API calls, and dynamic date windows.
- `gsc-full-analysis.js` — read-only Search Console analysis covering queries, pages, devices, countries, trends and query/page combinations.
- `pagespeed-cwv-analysis.js` — manual Core Web Vitals/PageSpeed diagnostic for the configured NUVANX URLs.

### Manual GTM publisher

- `gtm-utils.js` — shared sanitization/helper module used by the GTM publisher.
- `setup-gtm-conversion-trigger.js` — private local publisher for the governed `nvx_conversion_signal` → Google Ads conversion path. It refuses CI/non-TTY execution, requires `GTM_CONFIRM_PUBLISH=yes`, requires target identifiers through environment variables, uses an isolated workspace and publishes only entities created by the current invocation.

## GTM publisher required environment

Before running `setup-gtm-conversion-trigger.js`, configure these values in the private local environment or `.env.local`:

- `GTM_REFRESH_TOKEN`
- `GTM_CLIENT_ID` and `GTM_CLIENT_SECRET` (or the corresponding `GOOGLE_ADS_*` OAuth client pair)
- `GTM_ACCOUNT_ID`
- `GTM_CONTAINER_ID`
- `GOOGLE_ADS_CONVERSION_ID` in `AW-<digits>` format
- `GOOGLE_ADS_CONVERSION_LABEL`

Invoke it deliberately from a private local TTY:

```bash
source .env.local
GTM_CONFIRM_PUBLISH=yes node scripts/seo/setup-gtm-conversion-trigger.js
```

A successful publisher exit is not sufficient evidence to retire another tracking owner. Verify the live GTM container/version and the expected conversion event end-to-end first.

## Security and cleanup rules

1. Never commit `google-ads.json`, OAuth tokens, developer tokens, refresh tokens or API keys.
2. Never paste credentials into GitHub issues, PRs, Actions logs or chat transcripts.
3. Do not add temporary workflows to execute these helpers.
4. Generated dependency metadata (`package-lock.json`) must be updated with npm rather than hand-edited.
5. Absence of an automated CI consumer does **not** by itself make a manual operational tool obsolete. Remove a script only after confirming that its operational owner has been retired or replaced.
6. `scripts/seo` as a directory is not currently removable because `index-pages.js` and its package dependencies are part of the Production/release contract.

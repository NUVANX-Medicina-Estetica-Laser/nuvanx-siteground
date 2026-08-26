# SEO / Google Ads support tooling

This directory contains the repository-owned Google/Search support package. It is **not website runtime code** and it does not own an additional GitHub Actions workflow.

The repository currently keeps exactly two canonical workflows: `staging.yml` and `production.yml`. SEO/Search tooling is consumed through those existing contracts rather than through a separate workflow.

## Package and CI boundary

`scripts/seo` remains an independent Node package because its Google Ads, Search Console and GTM dependencies are operational tooling rather than WordPress runtime dependencies.

Current contract:

- the required `Static quality and repository integrity` gate reaches `scripts/ci/test-release-regression-contract.sh` through `scripts/ci/test-mutation-fifo-contract.sh`; that release contract syntax-checks every top-level `scripts/seo/*.js` file with `node --check`;
- the same release contract runs `npm audit --audit-level=high` for `scripts/seo` on the weekly Staging schedule and when any governed repository dependency lock changes;
- dependency changes to this package must update `scripts/seo/package-lock.json` with npm;
- credentialed mutating Google/GTM helpers are never run automatically;
- `index-pages.js` is different: Production separately syntax-checks it, installs the `scripts/seo` package with `npm ci --ignore-scripts`, and owns its execution for Search Console URL Inspection when the production post-audit path and GSC authentication are enabled;
- no SEO-specific GitHub Actions workflow should be added while repository hygiene requires the two canonical workflows above.

## Current ownership inventory

### Production/release-critical

- `index-pages.js` — Search Console URL Inspection utility. Production syntax-checks it as exact-candidate tooling, installs this package with `npm ci --prefix scripts/seo --ignore-scripts`, and may execute it against the canonical sitemap URL inventory during post-release audits. **Do not remove or relocate this file without updating `production.yml`, the package boundary, artifacts paths, and the release regression contract in the same change.**

### Read-only manual diagnostics

- `google-ads-list-campaigns.js` — bounded Google Ads credential/API and campaign diagnostic. Credentials are supplied privately; output is intentionally constrained so secrets and sensitive request metadata are not printed.
- `classify-google-credential.js` — local presence/shape classifier for a Google Ads credential bundle. It reports classes/counts rather than secret values.
- `gsc-client.js` — shared Search Console helper used by `gsc-full-analysis.js`; owns authentication, bounded API calls, and dynamic date windows.
- `gsc-full-analysis.js` — read-only Search Console analysis covering queries, pages, devices, countries, trends and query/page combinations.
- `pagespeed-cwv-analysis.js` — manual Core Web Vitals/PageSpeed diagnostic for the configured NUVANX URLs.

### GTM ownership

`gtm-utils.js` remains available for read-only GTM diagnostics. The former `setup-gtm-conversion-trigger.js` publisher has been retired because it could create a second, direct Google Ads form-conversion owner.

The canonical contract is:

`HubSpot successful submit → GA4 generate_lead → Google Ads 908 import`

No repository script may create or publish a direct Google Ads form tag. Changes to the live GTM container must be performed manually in the administrative UI, after verifying Primary/Secondary action settings and HubSpot Ads > Events. The direct 820 phone/WhatsApp conversion remains a separate, deliberate measurement decision and is not part of form-conversion cleanup.

## Security and cleanup rules

1. Never commit `google-ads.json`, OAuth tokens, developer tokens, refresh tokens or API keys.
2. Never paste credentials into GitHub issues, PRs, Actions logs or chat transcripts.
3. Do not add temporary workflows to execute these helpers.
4. Generated dependency metadata (`package-lock.json`) must be updated with npm rather than hand-edited.
5. Absence of an automated CI consumer does **not** by itself make a manual operational tool obsolete. Remove a script only after confirming that its operational owner has been retired or replaced.
6. `scripts/seo` as a directory is not currently removable because `index-pages.js` and its package dependencies are part of the Production/release contract.

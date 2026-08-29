# SEO / Search tooling

`scripts/seo/` is repository-owned operational tooling. It is **not WordPress runtime code** and it does not own a separate GitHub Actions workflow.

## Ownership boundary

- Production may use `index-pages.js` for Search Console URL Inspection as part of the canonical post-release audit path.
- Other scripts in this package are bounded manual/read-only diagnostics unless their current caller explicitly proves otherwise.
- Package dependencies are owned by `scripts/seo/package.json` and `scripts/seo/package-lock.json`.
- Syntax/security checks are invoked through the canonical repository CI/release contracts.

Do not add an SEO-specific workflow. If a tool becomes release-critical, wire it into `staging.yml` or `production.yml` and update the corresponding executable contract in the same change.

## Security rules

1. Never commit Google credentials, OAuth tokens, developer tokens, refresh tokens, API keys or generated credential files.
2. Never print credential values or sensitive request metadata into Actions logs or public artifacts.
3. Credentialed mutation helpers must not run automatically.
4. Update dependency locks with npm; do not hand-edit lockfile integrity data.
5. Remove a manual diagnostic only after confirming that its operational owner has actually been retired or replaced.

## Measurement ownership

Canonical form-conversion ownership is:

`HubSpot successful submit → GA4 generate_lead → downstream Google Ads import`

Direct browser form-to-Ads tags are not the canonical owner. The executable conversion contract and live platform configuration own account IDs, labels and Primary/Secondary state; this README intentionally does not mirror those changing values.

## Release-critical file

`index-pages.js` is part of the Production verification contract. Do not remove or relocate it without updating `production.yml`, package/artifact paths and the release regression tests in the same reviewed change.

Current operational results belong in Actions artifacts, Issues or platform evidence, not in this README.

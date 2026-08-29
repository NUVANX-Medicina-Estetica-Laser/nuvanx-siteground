# Security policy

This repository contains the production WordPress theme and release tooling for NUVANX. Security controls are enforced through code, branch protection and the two canonical GitHub Actions workflows.

## Secrets and credentials

- Never commit API keys, access tokens, private keys, OAuth credentials, service-account JSON, database credentials or WordPress secrets.
- Never paste secrets into issues, PR comments, Actions logs, documentation or chat transcripts.
- Runtime credentials must be supplied through the approved private secret/variable surfaces.
- Gitleaks is part of the repository security gate. A historical false positive may be allowlisted only with the narrowest exact fingerprint/commit/path/rule evidence; broad rule suppression is not acceptable.

## GitHub Actions

- Only `.github/workflows/staging.yml` and `.github/workflows/production.yml` are supported.
- External Actions must be pinned to immutable commit SHAs.
- Checkout must not persist repository credentials unless a reviewed use case explicitly requires it.
- Workflow self-mutation and temporary credentialed workflows are prohibited.
- Production is manual-only and requires immutable exact-SHA Staging acceptance evidence.

## SSH and hosting

- Strict host-key verification is mandatory. `StrictHostKeyChecking=no` is prohibited.
- SiteGround access uses bounded retries and explicit transport classification.
- A TCP timeout or SSH transport exit `255` blocks the operation but does not establish an application defect.
- Direct host deployment must not bypass the canonical Production workflow or its release identity contract.

## Production mutation controls

A production release must retain all of these protections:

1. exact candidate SHA validation;
2. successful immutable Staging acceptance evidence;
3. FIFO mutation serialization;
4. production environment preflight;
5. pre-mutation rollback snapshot;
6. guarded atomic cutover;
7. exact disk/public release identity verification;
8. compensating rollback for a failed post-cutover mandatory gate.

Do not weaken these controls to recover from a failed deployment.

## Data and evidence handling

- Production audits should be read-only unless the workflow explicitly owns a reviewed mutation.
- Diagnostic artifacts must be redacted and bounded; do not publish raw secret-bearing configuration or private source extracts as public artifacts.
- Database backups must remain outside `public_html` and be cleaned according to the deployment contract.
- QA submissions must be identifiable as QA/test data and must not create unintended production sales or advertising side effects.

## WordPress application security

Theme/runtime changes must preserve:

- output escaping and sanitization appropriate to context;
- nonce/capability checks for state-changing WordPress operations;
- governed security headers;
- consent and analytics ownership contracts;
- no executable secrets in theme files or public assets.

The executable security contracts under `scripts/lint/` are authoritative for implementation details.

## Reporting

Do not open a public issue containing an undisclosed vulnerability, credential or exploit detail. Use the repository/organization's private security reporting channel when available, or contact the repository owner privately before publishing sensitive details.

Historical incidents, closed investigations and remediated one-time procedures belong in Git history, PRs and Actions evidence rather than this living policy.

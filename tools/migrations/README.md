# Migration lifecycle

`tools/migrations/` contains bounded migration and audit tooling. Presence in this directory does **not** mean a script runs on every release.

The authoritative release-owned migration set is declared by the current deployment implementation/workflow. Do not copy that list into Markdown.

## Rules

- A migration that mutates Production must execute only inside an approved rollback-protected release path.
- A pre-mutation snapshot is mandatory for release-owned data changes.
- Do not rewrite a migration that has already been applied as historical evidence; add a new bounded migration when a new state transition is required.
- Keep a one-time migration only while runtime/data compatibility or an active verification contract still depends on it.
- Once a one-time migration is complete and its compatibility guards are removed, delete the script in the same reviewed cleanup cycle.
- Read-only collectors/audits may remain when they provide a reusable release or forensic contract.

## Audit semantics

Callers must evaluate explicit status markers, not merely process exit `0`, when a script documents multiple non-error states such as clean versus pending-migratable.

A migration/audit pair must fail closed for non-migratable divergence and must verify the resulting state after mutation.

## Public-content retirement

When retiring a WordPress route/content record, preserve the intended redirect/publication contract and use WordPress APIs where the migration requires reversible trash semantics. Do not turn a bounded retirement into an unreviewed permanent deletion.

The current route list, redirect targets and publication expectations are owned by machine-readable route/publication data and executable migration/audit code rather than this README.

## Documentation

Do not add a Markdown file for an individual migration execution. Use the relevant PR/Issue and Actions artifact/log evidence; Git history preserves the retired implementation after cleanup.

# NUVANX repository instructions for coding agents

These instructions apply to every task in this repository.

## Staging and CI failure interpretation

Before diagnosing any Staging2, Block C, browser-acceptance, SSH, or deployment failure, read:

- `docs/operations/staging-transient-classification.md`
- `docs/operations/deployment.md`

Do **not** infer that a candidate or application is broken merely because a GitHub Actions job is red, timed out, or incomplete. Read the classifier markers and the failing assertion first.

Canonical interpretation:

| Evidence | Interpretation |
|---|---|
| `FAIL_REAL` or an explicit non-transient application/contract assertion failure | Candidate defect established; fix candidate/application code. |
| Exit `75` / `EX_TEMPFAIL` / `TRANSIENT_INFRASTRUCTURE` | Candidate defect **not established**. Evidence is incomplete; promotion remains blocked until exact-SHA acceptance completes. |
| SiteGround HTTP `202`, antibot/captcha/challenge, or allowed transient browser navigation | Infrastructure/edge transient when confirmed by the classifier; **not** a code regression by itself. |
| Exit `78` / `EX_CONFIG` / `FAIL_CONFIG` | CI/configuration defect; candidate defect **not established**. |
| SSH exit `255` / transport-preflight failure | Transport/infrastructure defect; candidate defect **not established**. |

Required language for transient cases:

`classification=transient_infrastructure candidate_defect=not_established`

Never convert transient evidence into `PASS`. A transient is still a release **NO-GO** because exact-SHA evidence is incomplete; it simply must not be reported as an application regression without independent `FAIL_REAL` evidence.

## Block C retry rule

Block C runs the complete published-surface matrix once per runner. An isolated transient route/viewport must use bounded targeted recovery and must **not** trigger another full-matrix replay in the same runner. If targeted recovery remains inconclusive, return `EX_TEMPFAIL (75)` and require a fresh exact-SHA acceptance run.

Do not increase retry breadth, weaken assertions, reduce route/viewport coverage, or reinterpret incomplete browser geometry as PASS in order to make CI green.

## Historical reference

Run `33145945869` established the distinction: the first matrix reached 228/228 PASS, one SiteGround `202` left a single tablet case inconclusive, and the old full-matrix replay then exhausted the outer time budget. That incident was infrastructure/retry-architecture evidence, not proof of a candidate regression.

## Production safety

A non-defect transient classification never authorizes Production. Production requires all current release gates, immutable exact-SHA Staging2 acceptance evidence, and any explicit promotion authorization required by the active release issue/workflow.

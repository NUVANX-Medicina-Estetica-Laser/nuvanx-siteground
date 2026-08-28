# Staging2 transient acceptance classification

This document is the canonical interpretation contract for NUVANX Staging2 acceptance evidence. It exists to prevent infrastructure/edge transients from being reported as application regressions while preserving strict release gating.

## Non-negotiable rule

A candidate defect is established only by application/contract evidence classified as `FAIL_REAL` (or an equivalent explicit real-failure marker). A transient edge, transport, runner, or bounded-time event does **not** establish a candidate defect by itself.

Transient evidence is never promoted to `PASS`. It remains ineligible for Production until the missing exact-SHA acceptance evidence is recovered or a fresh exact-SHA run completes.

## Canonical classifications

| Signal | Classification | Candidate defect? | Production eligible? | Required action |
|---|---|---:|---:|---|
| Exit `0` with required `PASS` markers and complete evidence | `PASS` | No | Yes, only for the gate represented by that evidence | Continue to the next release gate |
| Exit `75` / `EX_TEMPFAIL` | `TRANSIENT_INFRASTRUCTURE` | **Not established** | **No** | Targeted recovery or fresh exact-SHA acceptance run |
| Exit `78` / `EX_CONFIG` | `FAIL_CONFIG` | **Not established** | **No** | Correct CI/configuration, then rerun exact SHA |
| Exit `255` from SSH transport/preflight | `FAIL_TRANSPORT` / infrastructure | **Not established** | **No** | Fresh runner / transport retry; do not patch candidate code without independent evidence |
| `FAIL_REAL`, non-transient browser/contract finding, or explicit application assertion failure | `FAIL_REAL` | **Established** | **No** | Correct the candidate/application and rerun |

## SiteGround Antibot / edge challenges

The following are infrastructure evidence, not application defects, when the classifier confirms the transient signature:

- SiteGround Antibot HTTP `202` or captcha/challenge response;
- transient navigation with no response and only allowed SiteGround `ERR_ABORTED` evidence;
- exact-SHA origin verification succeeds (`HTTP 200`, expected deploy SHA, staging noindex) while public-browser geometry remains inconclusive;
- bounded browser/runner timeout after prior complete PASS evidence, when logs identify `TRANSIENT_INFRASTRUCTURE` / `candidate_defect=not_established`.

An origin fallback proves only the properties it checks. It does **not** convert missing browser geometry, H1, responsive-layout, image, or interaction evidence into PASS. Those visual checks must be recovered through the public browser or repeated on a fresh exact-SHA run.

## Block C retry policy

Block C performs the full published-surface matrix once per runner. A transient-only case must **not** trigger another complete 228-case replay in the same runner.

Recovery order is:

1. run the full matrix once;
2. classify all incomplete evidence;
3. for Home, use the stricter dedicated Home recovery contract;
4. for a small number of other transient route/viewport cases, run targeted public-browser recovery only for those cases;
5. where safe and applicable, use exact-origin network recovery for completed visual cases affected only by transient same-origin network evidence;
6. if evidence remains inconclusive, return `EX_TEMPFAIL (75)` with `candidate_defect=not_established` and require a fresh exact-SHA acceptance run.

Targeted recovery is intentionally bounded. A broader cluster of transient cases is treated as infrastructure instability rather than consuming an unbounded browser budget.

## Required log language

Automation and human reviews should use these markers literally when available:

- `classification=transient_infrastructure candidate_defect=not_established`
- `BLOCK_C_RETRY_CLASSIFICATION=TRANSIENT_INFRASTRUCTURE`
- `BLOCK_C_TRANSIENT_RECOVERY=...`
- `BLOCK_C_RESILIENT=FAIL_TRANSIENT_EXHAUSTED`
- `BLOCK_C_RESILIENT=FAIL_REAL`

Do not describe an `EX_TEMPFAIL (75)`, SiteGround `202`, SSH transport `255`, or `FAIL_CONFIG (78)` as a code regression unless separate evidence establishes an application defect.

## Historical incident that established this contract

On Staging2 PR preview run `33145945869`, the first Block C matrix completed **228/228 PASS**. One tablet request for `/politica-privacidad/` received a SiteGround edge `202`; origin returned `200` with the exact deployed SHA, so browser geometry for that one case was correctly marked transient/inconclusive. The old orchestrator then replayed the entire matrix. The second matrix reached **174/228 PASS** before the outer `1200s` wrapper terminated it.

The timeout therefore did not establish a regression in the candidate. It demonstrated that full-matrix replay was the wrong recovery architecture for isolated edge transients. The canonical behavior is now full matrix once plus targeted recovery.

## Release interpretation

`TRANSIENT_INFRASTRUCTURE` means **NO-GO for promotion because evidence is incomplete**, not **candidate code is broken**. These are deliberately separate statements.

Never authorize or invoke Production solely because a transient is classified as non-defect. Production still requires every release gate and any explicit promotion authorization defined by the current release issue/workflow.

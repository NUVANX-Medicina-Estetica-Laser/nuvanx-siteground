#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SUBJECT="$ROOT/scripts/ci/wait-for-environment-mutation-turn.sh"
WORKFLOW="$ROOT/.github/workflows/staging.yml"
[[ -s "$SUBJECT" ]]
[[ -s "$WORKFLOW" ]]

readonly TEST_REPOSITORY="${TEST_REPOSITORY:-Arisofia/nuvanx-siteground}"
readonly TEST_RUN_ID="${TEST_RUN_ID:-42}"
readonly TEST_TOKEN="${TEST_TOKEN:-test-token}"
readonly TEST_PR_SHA="aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
readonly TEST_NEW_PR_SHA="bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/bin"

cat > "$TMP/bin/gh" <<'STUB'
#!/usr/bin/env bash
set -euo pipefail
if [[ "${1:-}" != api ]]; then
  echo "unexpected gh command: $*" >&2
  exit 2
fi
shift

if [[ "${1:-}" == '--method' && "${2:-}" == 'POST' ]]; then
  target="${3:-}"
  case "$target" in
    */actions/runs/41/cancel)
      touch "${TMP_DIR:-/tmp}/cancelled_41"
      exit 0
      ;;
    *)
      echo "unexpected gh POST target: $target" >&2
      exit 2
      ;;
  esac
fi

if [[ "${1:-}" == --paginate ]]; then
  scenario="${TEST_SCENARIO:-pass}"
  case "$scenario" in
    pass)
      exit 0
      ;;
    blocked|aggregate_gap)
      printf '%s\t%s\t%s\t%s\t%s\n' '41' 'in_progress' 'push' '.github/workflows/staging.yml' '0123456789abcdef0123456789abcdef01234567'
      exit 0
      ;;
    nonmutation)
      printf '%s\t%s\t%s\t%s\t%s\n' '41' 'in_progress' 'pull_request' '.github/workflows/staging.yml' '0123456789abcdef0123456789abcdef01234567'
      exit 0
      ;;
    cancel_old)
      if [[ ! -f "${TMP_DIR:-/tmp}/cancelled_41" ]]; then
        printf '%s\t%s\t%s\t%s\t%s\n' '41' 'in_progress' 'push' '.github/workflows/staging.yml' '1111111111111111111111111111111111111111'
      fi
      exit 0
      ;;
    transient_api_fail)
      fail_flag="${TMP_DIR:-/tmp}/failed_once"
      if [[ ! -f "$fail_flag" ]]; then
        touch "$fail_flag"
        echo 'simulated 502 Bad Gateway' >&2
        exit 1
      fi
      exit 0
      ;;
    *)
      exit 0
      ;;
  esac
fi

case "${1:-}" in
  */actions/runs/42)
    printf '%s\n' '{"path":".github/workflows/staging.yml","event":"push","status":"in_progress","run_attempt":1,"head_sha":"0123456789abcdef0123456789abcdef01234567","head_branch":"master"}'
    ;;
  */actions/runs/41)
    if [[ -f "${TMP_DIR:-/tmp}/cancelled_41" ]]; then
      printf '%s\n' '{"path":".github/workflows/staging.yml","event":"push","status":"completed","conclusion":"cancelled","run_attempt":1,"head_sha":"1111111111111111111111111111111111111111","head_branch":"master"}'
    else
      printf '%s\n' '{"path":".github/workflows/staging.yml","event":"push","status":"in_progress","run_attempt":1,"head_sha":"1111111111111111111111111111111111111111","head_branch":"master"}'
    fi
    ;;
  */pulls/1083)
    case "${TEST_PR_SCENARIO:-open}" in
      closed)
        printf '%s\n' '{"state":"closed","merged_at":"2026-09-04T12:00:00Z","head":{"sha":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"},"base":{"ref":"master"}}'
        ;;
      changed_head)
        printf '%s\n' '{"state":"open","merged_at":null,"head":{"sha":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"},"base":{"ref":"master"}}'
        ;;
      changed_base)
        printf '%s\n' '{"state":"open","merged_at":null,"head":{"sha":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"},"base":{"ref":"release"}}'
        ;;
      open|*)
        printf '%s\n' '{"state":"open","merged_at":null,"head":{"sha":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"},"base":{"ref":"master"}}'
        ;;
    esac
    ;;
  */actions/runs/*/jobs*)
    # The FIFO must no longer use job materialization as lease authority. This
    # fixture models the dangerous gap: all currently visible jobs are complete
    # while the aggregate run remains in_progress and can create more jobs.
    printf '%s\n' '{"total_count":2,"jobs":[{"id":1,"status":"completed","conclusion":"success"},{"id":2,"status":"completed","conclusion":"success"}]}'
    ;;
  */actions/workflows/staging.yml/runs*)
    branch_scenario="${TEST_BRANCH_SCENARIO:-none}"
    case "$branch_scenario" in
      superseded)
        printf '%s\n' '{"workflow_runs":[{"id":43,"head_sha":"9999999999abcdef0123456789abcdef01234567","head_branch":"master","event":"push","status":"queued"}]}'
        ;;
      older_active)
        printf '%s\n' '{"workflow_runs":[{"id":42,"head_sha":"0123456789abcdef0123456789abcdef01234567","head_branch":"master","event":"push","status":"in_progress"},{"id":41,"head_sha":"1111111111111111111111111111111111111111","head_branch":"master","event":"push","status":"in_progress"}]}'
        ;;
      none|*)
        printf '%s\n' '{"workflow_runs":[{"id":42,"head_sha":"0123456789abcdef0123456789abcdef01234567","head_branch":"master","event":"push","status":"in_progress"}]}'
        ;;
    esac
    ;;
  *)
    echo "unexpected gh api target: ${1:-missing}" >&2
    exit 2
    ;;
esac
STUB
chmod +x "$TMP/bin/gh"

common_env=(
  "PATH=$TMP/bin:$PATH"
  "TMP_DIR=$TMP"
  "GITHUB_REPOSITORY=$TEST_REPOSITORY"
  "GITHUB_RUN_ID=$TEST_RUN_ID"
  "GH_TOKEN=$TEST_TOKEN"
  "MUTATION_WAIT_STABILIZE_SECONDS=1"
  "MUTATION_WAIT_POLL_SECONDS=1"
  "MUTATION_WAIT_MAX_SECONDS=60"
)

# Case 1: Happy path.
pass_log="$TMP/pass.log"
env "${common_env[@]}" GITHUB_RUN_ATTEMPT=1 TEST_SCENARIO=pass bash "$SUBJECT" >"$pass_log" 2>&1
grep -Fq 'MUTATION_FIFO=PASS' "$pass_log"
grep -Fq 'aggregate_status=authoritative' "$pass_log"

# Case 2: Re-run rejection.
rerun_log="$TMP/rerun.log"
set +e
env "${common_env[@]}" GITHUB_RUN_ATTEMPT=2 TEST_SCENARIO=pass bash "$SUBJECT" >"$rerun_log" 2>&1
rerun_rc=$?
set -e
[[ "$rerun_rc" -eq 1 ]]
grep -Fq 'reason=rerun_forbidden' "$rerun_log"
grep -Fq 'action=start_new_run' "$rerun_log"

# Case 3: An older active canonical mutation remains a blocker.
blocked_log="$TMP/blocked.log"
set +e
env "${common_env[@]}" GITHUB_RUN_ATTEMPT=1 MUTATION_WAIT_MAX_SECONDS=1 TEST_SCENARIO=blocked bash "$SUBJECT" >"$blocked_log" 2>&1
blocked_rc=$?
set -e
[[ "$blocked_rc" -eq 1 ]]
grep -Fq 'MUTATION_FIFO=FAIL reason=wait_timeout' "$blocked_log"
grep -Fq 'MUTATION_FIFO_BLOCKER run_id=41' "$blocked_log"

# Case 4: Transient run-list API failure recovers rather than failing open.
transient_log="$TMP/transient.log"
rm -f "$TMP/failed_once"
env "${common_env[@]}" GITHUB_RUN_ATTEMPT=1 TEST_SCENARIO=transient_api_fail bash "$SUBJECT" >"$transient_log" 2>&1
grep -Fq 'MUTATION_FIFO=WARN reason=api_query_failed retrying=true' "$transient_log"
grep -Fq 'MUTATION_FIFO=PASS' "$transient_log"

# Case 5: Superseded push run rejection (exit 78).
superseded_log="$TMP/superseded.log"
set +e
env "${common_env[@]}" GITHUB_RUN_ATTEMPT=1 MUTATION_ROLE=staging TEST_BRANCH_SCENARIO=superseded bash "$SUBJECT" >"$superseded_log" 2>&1
superseded_rc=$?
set -e
[[ "$superseded_rc" -eq 78 ]]
grep -Fq 'MUTATION_FIFO=SUPERSEDED' "$superseded_log"
grep -Fq 'mutation=forbidden' "$superseded_log"

# Case 6: Latest staging push cancels only an older active staging push and then
# waits until the aggregate no longer appears active.
cancel_log="$TMP/cancel.log"
rm -f "$TMP/cancelled_41"
env "${common_env[@]}" \
  GITHUB_RUN_ATTEMPT=1 \
  MUTATION_ROLE=staging \
  MUTATION_CANCEL_SUPERSEDED_STAGING=1 \
  TEST_SCENARIO=cancel_old \
  TEST_BRANCH_SCENARIO=older_active \
  bash "$SUBJECT" >"$cancel_log" 2>&1
[[ -f "$TMP/cancelled_41" ]]
grep -Fq 'MUTATION_FIFO=CANCEL_SUPERSEDED role=staging run_id=41' "$cancel_log"
grep -Fq 'MUTATION_FIFO=PASS role=staging run_id=42' "$cancel_log"

# Case 7: Critical regression — an in_progress aggregate MUST remain a blocker
# even if all currently materialized jobs would report completed. We no longer
# query the jobs endpoint or infer a zombie from a temporary job graph gap.
gap_log="$TMP/aggregate-gap.log"
set +e
env "${common_env[@]}" GITHUB_RUN_ATTEMPT=1 MUTATION_WAIT_MAX_SECONDS=1 TEST_SCENARIO=aggregate_gap bash "$SUBJECT" >"$gap_log" 2>&1
gap_rc=$?
set -e
[[ "$gap_rc" -eq 1 ]]
grep -Fq 'MUTATION_FIFO_BLOCKER run_id=41' "$gap_log"
! grep -Fq 'MUTATION_FIFO=IGNORE_ZOMBIE' "$SUBJECT"
! grep -Fq 'run_jobs_activity' "$SUBJECT"

# Case 8: A non-mutating pull_request run never occupies the mutation FIFO.
nonmutation_log="$TMP/nonmutation.log"
env "${common_env[@]}" GITHUB_RUN_ATTEMPT=1 TEST_SCENARIO=nonmutation bash "$SUBJECT" >"$nonmutation_log" 2>&1
grep -Fq 'MUTATION_FIFO=PASS' "$nonmutation_log"
! grep -Fq 'MUTATION_FIFO_BLOCKER run_id=41' "$nonmutation_log"

# Case 9: A PR that was merged/closed while waiting loses mutation authority
# before any git worktree rebuild or remote Staging2 mutation can begin.
closed_pr_log="$TMP/closed-pr.log"
set +e
env "${common_env[@]}" \
  GITHUB_RUN_ATTEMPT=1 \
  MUTATION_ROLE=pr-preview \
  TEST_SCENARIO=pass \
  TEST_PR_SCENARIO=closed \
  PR_NUMBER=1083 \
  PR_SHA="$TEST_PR_SHA" \
  CANDIDATE_ROOT="$TMP/pr-candidate-closed" \
  GITHUB_ENV="$TMP/github-env-closed" \
  bash "$SUBJECT" >"$closed_pr_log" 2>&1
closed_pr_rc=$?
set -e
[[ "$closed_pr_rc" -eq 78 ]]
grep -Fq 'MUTATION_FIFO=SUPERSEDED role=pr-preview reason=pr_not_open' "$closed_pr_log"
grep -Fq 'mutation=forbidden' "$closed_pr_log"
[[ ! -e "$TMP/pr-candidate-closed" ]]

# Case 10: A PR head that advanced while queued also loses mutation authority.
changed_head_log="$TMP/changed-head.log"
set +e
env "${common_env[@]}" \
  GITHUB_RUN_ATTEMPT=1 \
  MUTATION_ROLE=pr-preview \
  TEST_SCENARIO=pass \
  TEST_PR_SCENARIO=changed_head \
  PR_NUMBER=1083 \
  PR_SHA="$TEST_PR_SHA" \
  CANDIDATE_ROOT="$TMP/pr-candidate-head" \
  GITHUB_ENV="$TMP/github-env-head" \
  bash "$SUBJECT" >"$changed_head_log" 2>&1
changed_head_rc=$?
set -e
[[ "$changed_head_rc" -eq 78 ]]
grep -Fq 'MUTATION_FIFO=SUPERSEDED role=pr-preview reason=pr_head_superseded' "$changed_head_log"
grep -Fq "actual=$TEST_NEW_PR_SHA" "$changed_head_log"
[[ ! -e "$TMP/pr-candidate-head" ]]

echo 'MUTATION_FIFO_CONTRACT_TEST=PASS cases=10 aggregate_status=authoritative job_materialization_gap=blocked api=fail_closed pr_preview_liveness=fail_closed'

# A cancellation after a rollback snapshot and mutation arm must restore Staging
# for both master deployments and labeled PR previews. `failure()` alone does
# not run on GitHub cancellation and previously left a cancelled preview live.
rollback_condition="if: \${{ (failure() || cancelled()) && env.STAGING_SNAPSHOT_READY == '1' && env.STAGING_MUTATION_ARMED == '1' }}"
rollback_count="$(grep -Fc "$rollback_condition" "$WORKFLOW" || true)"
[[ "$rollback_count" -eq 2 ]] || {
  echo "STAGING_CANCELLATION_ROLLBACK_CONTRACT=FAIL expected=2 actual=$rollback_count" >&2
  exit 1
}
echo 'STAGING_CANCELLATION_ROLLBACK_CONTRACT=PASS owners=master,pr-preview trigger=failure_or_cancelled armed=1'

# Vendor media governance must distinguish packshot filenames from legitimate
# treatment-directory names such as /exion-face/ and /endolift-facial/.
php "$ROOT/scripts/lint/test-vendor-image-url-boundary.php"

# Public image hygiene must fail safe when route context is unavailable and
# must never leave a vendor figure/caption behind.
php "$ROOT/scripts/lint/test-gbp-image-hygiene-edge.php"

# Theme-owned clinic photography has a 500 KiB regression budget. Existing
# oversized legacy files are capped exceptions and cannot grow or multiply.
php "$ROOT/scripts/lint/test-clinic-media-budget.php"

# Sonar configuration must describe only supported scanner behavior. Remote
# Quality Gate conditions stay server-owned, and coverage is never fabricated.
bash "$ROOT/scripts/ci/test-sonar-project-contract.sh"

# The Staging boundary must bypass a SiteGround 202 challenge only through the
# exact HTTPS vhost on loopback, preserving host/SNI, exact deploy SHA and the
# strict noindex,nofollow contract. HTTP localhost fallback is forbidden.
node "$ROOT/scripts/staging2/test-staging-boundary-origin-contract.mjs"

# Release and theme regressions are intentionally owned by a separate contract
# with their own diagnostics. Keep this call as the current static-gate
# aggregation point until the workflow exposes a dedicated release-test step.
bash "$ROOT/scripts/ci/test-release-regression-contract.sh"

# Complianz policy routing is a release-blocking pre-production invariant.
# Execute both structural ownership and behavioral routing contracts here so
# translated href="#" policy links cannot bypass the protected quality job.
node "$ROOT/scripts/lint/test-complianz-policy-routing.mjs"
php "$ROOT/scripts/lint/test-complianz-policy-routing.php"

# Design-token adoption blocks the zero-baseline ratcheted categories by
# default; --strict additionally blocks every category in STRICT_CATEGORIES.
node "$ROOT/scripts/lint/audit-design-token-adoption.mjs"

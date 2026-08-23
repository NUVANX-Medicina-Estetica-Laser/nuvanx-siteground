#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SUBJECT="$ROOT/scripts/ci/wait-for-environment-mutation-turn.sh"
[[ -s "$SUBJECT" ]]

# Centralized test configuration
readonly TEST_REPOSITORY="${TEST_REPOSITORY:-Arisofia/nuvanx-siteground}"
readonly TEST_RUN_ID="${TEST_RUN_ID:-42}"
readonly TEST_RUN_ATTEMPT="${TEST_RUN_ATTEMPT:-1}"
readonly TEST_TOKEN="${TEST_TOKEN:-test-token}"

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
    blocked)
      printf '%s\t%s\t%s\t%s\t%s\n' '41' 'in_progress' 'push' '.github/workflows/staging.yml' '0123456789abcdef0123456789abcdef01234567'
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
        echo "simulated 502 Bad Gateway" >&2
        exit 1
      fi
      exit 0
      ;;
    *) exit 0 ;;
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

pass_log="$TMP/pass.log"
env "${common_env[@]}" GITHUB_RUN_ATTEMPT=1 TEST_SCENARIO=pass bash "$SUBJECT" >"$pass_log" 2>&1
grep -Fq 'MUTATION_FIFO=PASS' "$pass_log"
grep -Fq 'attempt=1' "$pass_log"

after_rerun="$TMP/rerun.log"
set +e
env "${common_env[@]}" GITHUB_RUN_ATTEMPT=2 TEST_SCENARIO=pass bash "$SUBJECT" >"$after_rerun" 2>&1
rerun_rc=$?
set -e
[[ "$rerun_rc" -eq 1 ]]
grep -Fq 'reason=rerun_forbidden' "$after_rerun"
grep -Fq 'action=start_new_run' "$after_rerun"

blocked_log="$TMP/blocked.log"
set +e
env "${common_env[@]}" GITHUB_RUN_ATTEMPT=1 MUTATION_WAIT_MAX_SECONDS=1 TEST_SCENARIO=blocked bash "$SUBJECT" >"$blocked_log" 2>&1
blocked_rc=$?
set -e
[[ "$blocked_rc" -eq 1 ]]
grep -Fq 'MUTATION_FIFO=FAIL reason=wait_timeout' "$blocked_log"
grep -Fq 'MUTATION_FIFO_BLOCKER run_id=41' "$blocked_log"

transient_log="$TMP/transient.log"
rm -f "$TMP/failed_once"
env "${common_env[@]}" GITHUB_RUN_ATTEMPT=1 TEST_SCENARIO=transient_api_fail bash "$SUBJECT" >"$transient_log" 2>&1
grep -Fq 'MUTATION_FIFO=WARN reason=api_query_failed retrying=true' "$transient_log"
grep -Fq 'MUTATION_FIFO=PASS' "$transient_log"

superseded_log="$TMP/superseded.log"
set +e
env "${common_env[@]}" GITHUB_RUN_ATTEMPT=1 MUTATION_ROLE=staging TEST_BRANCH_SCENARIO=superseded bash "$SUBJECT" >"$superseded_log" 2>&1
superseded_rc=$?
set -e
[[ "$superseded_rc" -eq 78 ]]
grep -Fq 'MUTATION_FIFO=SUPERSEDED' "$superseded_log"
grep -Fq 'mutation=forbidden' "$superseded_log"

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

echo 'MUTATION_FIFO_CONTRACT_TEST=PASS cases=6'

php "$ROOT/scripts/lint/test-document-buffer-retirement.php"
php "$ROOT/scripts/lint/test-vendor-image-url-boundary.php"
php "$ROOT/scripts/lint/test-gbp-image-hygiene-edge.php"
php "$ROOT/scripts/lint/test-clinic-media-budget.php"
bash "$ROOT/scripts/ci/test-sonar-project-contract.sh"
bash "$ROOT/scripts/ci/test-release-regression-contract.sh"

# Design-token adoption is report-only while the existing CSS baseline is
# classified. The script becomes blocking only when invoked with --strict.
node "$ROOT/scripts/lint/audit-design-token-adoption.mjs"

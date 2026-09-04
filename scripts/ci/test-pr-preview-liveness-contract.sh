#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SUBJECT="$ROOT/scripts/ci/validate-pr-preview-liveness.sh"
[[ -s "$SUBJECT" ]]

readonly TEST_PR=1083
readonly TEST_SHA='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
readonly OTHER_SHA='bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'
readonly TEST_RUN_ID=42

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/bin"

cat > "$TMP/bin/gh" <<'STUB'
#!/usr/bin/env bash
set -euo pipefail
[[ "${1:-}" == api ]] || { echo "unexpected gh command: $*" >&2; exit 2; }
target="${2:-}"
case "$target" in
  */pulls/1083)
    printf '%s\n' '{"state":"open","merged_at":null,"head":{"sha":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"},"base":{"ref":"master"}}'
    ;;
  */actions/workflows/staging.yml/runs*)
    case "${TEST_DUP_SCENARIO:-none}" in
      newer_same)
        printf '%s\n' '{"workflow_runs":[{"id":43,"event":"pull_request_target","head_sha":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","pull_requests":[{"number":1083}]}]}'
        ;;
      older_same)
        printf '%s\n' '{"workflow_runs":[{"id":41,"event":"pull_request_target","head_sha":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","pull_requests":[{"number":1083}]}]}'
        ;;
      newer_other_pr)
        printf '%s\n' '{"workflow_runs":[{"id":43,"event":"pull_request_target","head_sha":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","pull_requests":[{"number":1084}]}]}'
        ;;
      newer_other_sha)
        printf '%s\n' '{"workflow_runs":[{"id":43,"event":"pull_request_target","head_sha":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","pull_requests":[{"number":1083}]}]}'
        ;;
      query_fail)
        exit 1
        ;;
      none|*)
        printf '%s\n' '{"workflow_runs":[]}'
        ;;
    esac
    ;;
  *)
    echo "unexpected gh api target: $target" >&2
    exit 2
    ;;
esac
STUB
chmod +x "$TMP/bin/gh"

common_env=(
  "PATH=$TMP/bin:$PATH"
  'GITHUB_REPOSITORY=Arisofia/nuvanx-siteground'
  "GITHUB_RUN_ID=$TEST_RUN_ID"
  "PR_NUMBER=$TEST_PR"
  "PR_SHA=$TEST_SHA"
  'GH_TOKEN=test-token'
)

pass_log="$TMP/pass.log"
env "${common_env[@]}" TEST_DUP_SCENARIO=none bash "$SUBJECT" contract >"$pass_log" 2>&1
grep -Fq 'PR_PREVIEW_LIVENESS=PASS' "$pass_log"
grep -Fq 'duplicate_owner=latest' "$pass_log"

newer_log="$TMP/newer.log"
set +e
env "${common_env[@]}" TEST_DUP_SCENARIO=newer_same bash "$SUBJECT" contract >"$newer_log" 2>&1
newer_rc=$?
set -e
[[ "$newer_rc" -eq 78 ]]
grep -Fq 'reason=duplicate_preview_superseded' "$newer_log"
grep -Fq 'newer_run_id=43' "$newer_log"

for scenario in older_same newer_other_pr newer_other_sha; do
  log="$TMP/${scenario}.log"
  env "${common_env[@]}" TEST_DUP_SCENARIO="$scenario" bash "$SUBJECT" contract >"$log" 2>&1
  grep -Fq 'PR_PREVIEW_LIVENESS=PASS' "$log"
done

query_fail_log="$TMP/query-fail.log"
set +e
env "${common_env[@]}" TEST_DUP_SCENARIO=query_fail bash "$SUBJECT" contract >"$query_fail_log" 2>&1
query_fail_rc=$?
set -e
[[ "$query_fail_rc" -eq 1 ]]
grep -Fq 'reason=preview_run_query_failed' "$query_fail_log"
grep -Fq 'mutation=forbidden' "$query_fail_log"

echo 'PR_PREVIEW_LIVENESS_CONTRACT=PASS latest_same_head_owner=1 older_superseded=1 unrelated_runs_ignored=1 query_fail_closed=1'
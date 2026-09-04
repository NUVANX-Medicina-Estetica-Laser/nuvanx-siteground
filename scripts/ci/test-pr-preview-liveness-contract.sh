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
    case "${TEST_PULL_SCENARIO:-valid}" in
      valid)
        printf '%s\n' '{"state":"open","merged_at":null,"head":{"sha":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"},"base":{"ref":"master"}}'
        ;;
      query_fail)
        exit 1
        ;;
      malformed_json)
        printf '%s\n' 'not json'
        ;;
      missing_sha)
        printf '%s\n' '{"state":"open","base":{"ref":"master"}}'
        ;;
      *)
        echo "unexpected TEST_PULL_SCENARIO: ${TEST_PULL_SCENARIO}" >&2
        exit 2
        ;;
    esac
    ;;
  */actions/runs/*/jobs*)
    case "${TEST_JOB_SCENARIO:-active}" in
      active)
        printf '%s\n' '{"total_count":1,"jobs":[{"id":1001,"name":"Labeled same-repo PR preview on Staging2","conclusion":null,"status":"in_progress"}]}'
        ;;
      skipped)
        printf '%s\n' '{"total_count":1,"jobs":[{"id":1001,"name":"Labeled same-repo PR preview on Staging2","conclusion":"skipped","status":"completed"}]}'
        ;;
      query_fail)
        exit 1
        ;;
      malformed_json)
        printf '%s\n' '{"jobs": malformed json'
        ;;
      non_object)
        printf '%s\n' '["not", "an", "object"]'
        ;;
      *)
        echo "unexpected TEST_JOB_SCENARIO: ${TEST_JOB_SCENARIO}" >&2
        exit 2
        ;;
    esac
    ;;
  */actions/workflows/staging.yml/runs*)
    page=1
    if [[ "$target" =~ [?\&]page=([0-9]+) ]]; then
      page="${BASH_REMATCH[1]}"
    fi

    case "${TEST_DUP_SCENARIO:-none}" in
      newer_same)
        printf '%s\n' '{"workflow_runs":[{"id":43,"event":"pull_request_target","head_sha":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","pull_requests":[{"number":1083}],"conclusion":null,"display_title":"Staging · PR #1083 preview"}]}'
        ;;
      older_same)
        printf '%s\n' '{"workflow_runs":[{"id":41,"event":"pull_request_target","head_sha":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","pull_requests":[{"number":1083}],"conclusion":null,"display_title":"Staging · PR #1083 preview"}]}'
        ;;
      newer_other_pr)
        printf '%s\n' '{"workflow_runs":[{"id":43,"event":"pull_request_target","head_sha":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","pull_requests":[{"number":1084}],"conclusion":null,"display_title":"Staging · PR #1084 preview"}]}'
        ;;
      newer_other_sha)
        printf '%s\n' '{"workflow_runs":[{"id":43,"event":"pull_request_target","head_sha":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","pull_requests":[{"number":1083}],"conclusion":null,"display_title":"Staging · PR #1083 preview"}]}'
        ;;
      newer_unrelated_label_title)
        printf '%s\n' '{"workflow_runs":[{"id":43,"event":"pull_request_target","head_sha":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","pull_requests":[{"number":1083}],"conclusion":null,"display_title":"Staging · PR #1083 (docs)"}]}'
        ;;
      newer_unrelated_label_skipped_run)
        printf '%s\n' '{"workflow_runs":[{"id":43,"event":"pull_request_target","head_sha":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","pull_requests":[{"number":1083}],"conclusion":"skipped","display_title":null}]}'
        ;;
      newer_unrelated_label_job_skipped)
        printf '%s\n' '{"workflow_runs":[{"id":43,"event":"pull_request_target","head_sha":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","pull_requests":[{"number":1083}],"conclusion":null,"display_title":null}]}'
        ;;
      paginated_duplicate)
        if [[ "$page" -eq 1 ]]; then
          jq -nc '{"workflow_runs": [range(200; 100; -1) | {"id": ., "event": "pull_request_target", "head_sha": "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb", "pull_requests": [{"number": 1083}], "conclusion": null, "display_title": "Staging · PR #1083 preview"}]}'
        elif [[ "$page" -eq 2 ]]; then
          printf '%s\n' '{"workflow_runs":[{"id":90,"event":"pull_request_target","head_sha":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","pull_requests":[{"number":1083}],"conclusion":null,"display_title":"Staging · PR #1083 preview"},{"id":40,"event":"pull_request_target","head_sha":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","pull_requests":[{"number":1083}],"conclusion":null,"display_title":"Staging · PR #1083 preview"}]}'
        else
          printf '%s\n' '{"workflow_runs":[]}'
        fi
        ;;
      query_fail)
        exit 1
        ;;
      malformed_json_runs)
        printf '%s\n' '{"workflow_runs": [invalid json'
        ;;
      non_object_runs)
        printf '%s\n' '[{"id": 43}]'
        ;;
      missing_workflow_runs)
        printf '%s\n' '{"message":"Not Found"}'
        ;;
      invalid_run_fields)
        printf '%s\n' '{"workflow_runs":[{"id":"not_a_number","event":"pull_request_target","head_sha":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","pull_requests":[{"number":1083}]}]}'
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
  'LIVENESS_RETRY_SLEEP=0'
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

for scenario in older_same newer_other_pr newer_other_sha newer_unrelated_label_title newer_unrelated_label_skipped_run; do
  log="$TMP/${scenario}.log"
  env "${common_env[@]}" TEST_DUP_SCENARIO="$scenario" bash "$SUBJECT" contract >"$log" 2>&1
  grep -Fq 'PR_PREVIEW_LIVENESS=PASS' "$log"
done

job_skip_log="$TMP/newer_unrelated_label_job_skipped.log"
env "${common_env[@]}" TEST_DUP_SCENARIO=newer_unrelated_label_job_skipped TEST_JOB_SCENARIO=skipped bash "$SUBJECT" contract >"$job_skip_log" 2>&1
grep -Fq 'PR_PREVIEW_LIVENESS=PASS' "$job_skip_log"

paginated_log="$TMP/paginated.log"
set +e
env "${common_env[@]}" TEST_DUP_SCENARIO=paginated_duplicate bash "$SUBJECT" contract >"$paginated_log" 2>&1
paginated_rc=$?
set -e
[[ "$paginated_rc" -eq 78 ]]
grep -Fq 'reason=duplicate_preview_superseded' "$paginated_log"
grep -Fq 'newer_run_id=90' "$paginated_log"

query_fail_log="$TMP/query_fail.log"
set +e
env "${common_env[@]}" TEST_DUP_SCENARIO=query_fail bash "$SUBJECT" contract >"$query_fail_log" 2>&1
query_fail_rc=$?
set -e
[[ "$query_fail_rc" -eq 75 ]]
grep -Fq 'PR_PREVIEW_LIVENESS=TRANSIENT' "$query_fail_log"
grep -Fq 'reason=preview_run_query_failed' "$query_fail_log"
grep -Fq 'mutation=forbidden' "$query_fail_log"

for scenario in malformed_json_runs non_object_runs missing_workflow_runs invalid_run_fields; do
  log="$TMP/${scenario}.log"
  set +e
  env "${common_env[@]}" TEST_DUP_SCENARIO="$scenario" bash "$SUBJECT" contract >"$log" 2>&1
  rc=$?
  set -e
  [[ "$rc" -eq 1 ]]
  grep -Fq 'PR_PREVIEW_LIVENESS=FAIL' "$log"
  grep -Fq 'reason=preview_run_payload_invalid' "$log"
  grep -Fq 'mutation=forbidden' "$log"
done

pull_fail_log="$TMP/pull_query_fail.log"
set +e
env "${common_env[@]}" TEST_DUP_SCENARIO=none TEST_PULL_SCENARIO=query_fail bash "$SUBJECT" contract >"$pull_fail_log" 2>&1
pull_fail_rc=$?
set -e
[[ "$pull_fail_rc" -eq 75 ]]
grep -Fq 'PR_PREVIEW_LIVENESS=TRANSIENT' "$pull_fail_log"
grep -Fq 'reason=pr_metadata_fetch_failed' "$pull_fail_log"
grep -Fq 'mutation=forbidden' "$pull_fail_log"

for pull_scenario in malformed_json missing_sha; do
  log="$TMP/pull_${pull_scenario}.log"
  set +e
  env "${common_env[@]}" TEST_DUP_SCENARIO=none TEST_PULL_SCENARIO="$pull_scenario" bash "$SUBJECT" contract >"$log" 2>&1
  rc=$?
  set -e
  [[ "$rc" -eq 1 ]]
  grep -Fq 'PR_PREVIEW_LIVENESS=FAIL' "$log"
  grep -Fq 'reason=pr_metadata_payload_invalid' "$log"
  grep -Fq 'mutation=forbidden' "$log"
done

job_fail_log="$TMP/job_query_fail.log"
set +e
env "${common_env[@]}" TEST_DUP_SCENARIO=newer_same TEST_JOB_SCENARIO=query_fail bash "$SUBJECT" contract >"$job_fail_log" 2>&1
job_fail_rc=$?
set -e
[[ "$job_fail_rc" -eq 75 ]]
grep -Fq 'PR_PREVIEW_LIVENESS=TRANSIENT' "$job_fail_log"
grep -Fq 'reason=candidate_job_query_failed' "$job_fail_log"
grep -Fq 'mutation=forbidden' "$job_fail_log"

for job_scenario in malformed_json non_object; do
  log="$TMP/job_${job_scenario}.log"
  set +e
  env "${common_env[@]}" TEST_DUP_SCENARIO=newer_same TEST_JOB_SCENARIO="$job_scenario" bash "$SUBJECT" contract >"$log" 2>&1
  rc=$?
  set -e
  [[ "$rc" -eq 1 ]]
  grep -Fq 'PR_PREVIEW_LIVENESS=FAIL' "$log"
  grep -Fq 'reason=candidate_job_payload_invalid' "$log"
  grep -Fq 'mutation=forbidden' "$log"
done

echo 'PR_PREVIEW_LIVENESS_CONTRACT=PASS latest_same_head_owner=1 older_superseded=1 unrelated_runs_ignored=1 unrelated_labels_ignored=1 paginated_duplicates_found=1 transient_exit_75=1 payload_fail_closed_exit_1=1'
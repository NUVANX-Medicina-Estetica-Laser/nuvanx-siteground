#!/usr/bin/env bash
set -Eeuo pipefail

: "${GITHUB_REPOSITORY:?Missing GITHUB_REPOSITORY}"
: "${GITHUB_RUN_ID:?Missing GITHUB_RUN_ID}"
: "${PR_NUMBER:?Missing PR_NUMBER}"
: "${PR_SHA:?Missing PR_SHA}"
: "${GH_TOKEN:?Missing GH_TOKEN}"

command -v gh >/dev/null || { echo 'PR_PREVIEW_LIVENESS=FAIL reason=missing_gh' >&2; exit 1; }
command -v jq >/dev/null || { echo 'PR_PREVIEW_LIVENESS=FAIL reason=missing_jq' >&2; exit 1; }

[[ "$GITHUB_RUN_ID" =~ ^[0-9]{1,20}$ ]] || { echo "PR_PREVIEW_LIVENESS=FAIL reason=invalid_run_id value=$GITHUB_RUN_ID" >&2; exit 1; }
[[ "$PR_NUMBER" =~ ^[0-9]+$ ]] || { echo "PR_PREVIEW_LIVENESS=FAIL reason=invalid_pr_number value=$PR_NUMBER" >&2; exit 1; }
[[ "$PR_SHA" =~ ^[0-9a-f]{40}$ ]] || { echo "PR_PREVIEW_LIVENESS=FAIL reason=invalid_pr_sha value=$PR_SHA" >&2; exit 1; }

stage="${1:-unspecified}"
pr_meta=""
for attempt in 1 2 3; do
  if pr_meta="$(gh api "/repos/${GITHUB_REPOSITORY}/pulls/${PR_NUMBER}" 2>/dev/null)" && [[ -n "$pr_meta" ]]; then
    break
  fi
  pr_meta=""
  sleep "${LIVENESS_RETRY_SLEEP:-2}"
done

[[ -n "$pr_meta" ]] || {
  echo "PR_PREVIEW_LIVENESS=TRANSIENT reason=pr_metadata_fetch_failed pr=$PR_NUMBER stage=$stage mutation=forbidden" >&2
  exit 75
}

if ! printf '%s' "$pr_meta" | jq -e '
  type == "object"
  and (.state | type == "string")
  and (.head | type == "object" and (.sha | type == "string"))
  and (.base | type == "object" and (.ref | type == "string"))
' >/dev/null 2>&1; then
  echo "PR_PREVIEW_LIVENESS=FAIL reason=pr_metadata_payload_invalid pr=$PR_NUMBER stage=$stage mutation=forbidden" >&2
  exit 1
fi

pr_state="$(printf '%s' "$pr_meta" | jq -r '.state // ""')"
merged_at="$(printf '%s' "$pr_meta" | jq -r '.merged_at // ""')"
api_pr_sha="$(printf '%s' "$pr_meta" | jq -r '.head.sha // ""')"
api_base_ref="$(printf '%s' "$pr_meta" | jq -r '.base.ref // ""')"

if [[ "$pr_state" != 'open' || -n "$merged_at" ]]; then
  echo "MUTATION_FIFO=SUPERSEDED role=pr-preview reason=pr_not_open pr=$PR_NUMBER state=${pr_state:-unknown} merged=$([[ -n "$merged_at" ]] && printf true || printf false) stage=$stage mutation=forbidden" >&2
  exit 78
fi
if [[ "$api_base_ref" != 'master' ]]; then
  echo "MUTATION_FIFO=SUPERSEDED role=pr-preview reason=pr_base_changed pr=$PR_NUMBER expected=master actual=${api_base_ref:-missing} stage=$stage mutation=forbidden" >&2
  exit 78
fi
if [[ "$api_pr_sha" != "$PR_SHA" ]]; then
  echo "MUTATION_FIFO=SUPERSEDED role=pr-preview reason=pr_head_superseded pr=$PR_NUMBER expected=$PR_SHA actual=${api_pr_sha:-missing} stage=$stage mutation=forbidden" >&2
  exit 78
fi

# Re-labeling an unchanged PR creates another pull_request_target run with the
# same PR head. Without a single owner, both runs remain live and consume the
# cross-workflow FIFO serially. The newest label event is authoritative: any
# older run for this exact PR + head must fail closed before rebuilding or
# mutating Staging2.
page=1
max_pages=10
newer_duplicate=""

while (( page <= max_pages )); do
  preview_runs=""
  for attempt in 1 2 3; do
    if preview_runs="$(gh api "/repos/${GITHUB_REPOSITORY}/actions/workflows/staging.yml/runs?event=pull_request_target&per_page=100&page=${page}" 2>/dev/null)" && [[ -n "$preview_runs" ]]; then
      break
    fi
    preview_runs=""
    sleep "${LIVENESS_RETRY_SLEEP:-2}"
  done

  [[ -n "$preview_runs" ]] || {
    echo "PR_PREVIEW_LIVENESS=TRANSIENT reason=preview_run_query_failed pr=$PR_NUMBER sha=$PR_SHA stage=$stage mutation=forbidden" >&2
    exit 75
  }

  if ! printf '%s' "$preview_runs" | jq -e '
    type == "object"
    and (.workflow_runs | type == "array")
    and (.workflow_runs | all(
      type == "object"
      and (.id | type == "number")
      and (.event | type == "string")
      and (.head_sha | type == "string")
      and (.pull_requests | type == "array")
      and (.pull_requests | all(type == "object" and (.number | type == "number")))
    ))
  ' >/dev/null 2>&1; then
    echo "PR_PREVIEW_LIVENESS=FAIL reason=preview_run_payload_invalid pr=$PR_NUMBER sha=$PR_SHA stage=$stage mutation=forbidden" >&2
    exit 1
  fi

  candidate_runs="$(printf '%s' "$preview_runs" | jq -r \
    --argjson current_run "$GITHUB_RUN_ID" \
    --argjson pr "$PR_NUMBER" \
    --arg sha "$PR_SHA" '
      [.workflow_runs[]?
        | select((.id > $current_run)
          and (.event == "pull_request_target")
          and (.head_sha == $sha)
          and (any(.pull_requests[]?; .number == $pr))
          and (.conclusion != "skipped")
          and (
            if .display_title != null then
              (.display_title | test("\\((?!deploy-staging2).*\\)$") | not)
            else
              true
            end
          ))]
      | sort_by(.id)
      | map(.id | tostring)
      | .[]
    ')" || {
    echo "PR_PREVIEW_LIVENESS=FAIL reason=preview_run_payload_invalid pr=$PR_NUMBER sha=$PR_SHA stage=$stage mutation=forbidden" >&2
    exit 1
  }

  for cand_id in $candidate_runs; do
    cand_jobs=""
    for attempt in 1 2 3; do
      if cand_jobs="$(gh api "/repos/${GITHUB_REPOSITORY}/actions/runs/${cand_id}/jobs" 2>/dev/null)" && [[ -n "$cand_jobs" ]]; then
        break
      fi
      cand_jobs=""
      sleep "${LIVENESS_RETRY_SLEEP:-1}"
    done

    [[ -n "$cand_jobs" ]] || {
      echo "PR_PREVIEW_LIVENESS=TRANSIENT reason=candidate_job_query_failed pr=$PR_NUMBER sha=$PR_SHA cand_id=$cand_id stage=$stage mutation=forbidden" >&2
      exit 75
    }

    if ! printf '%s' "$cand_jobs" | jq -e 'type == "object" and (.jobs | type == "array")' >/dev/null 2>&1; then
      echo "PR_PREVIEW_LIVENESS=FAIL reason=candidate_job_payload_invalid pr=$PR_NUMBER sha=$PR_SHA cand_id=$cand_id stage=$stage mutation=forbidden" >&2
      exit 1
    fi

    preview_skipped="$(printf '%s' "$cand_jobs" | jq -r '
      [.jobs[]? | select(.name == "Labeled same-repo PR preview on Staging2")]
      | if length == 0 then "0"
        elif .[0].conclusion == "skipped" then "1"
        else "0"
        end
    ')"
    if [[ "$preview_skipped" == "1" ]]; then
      continue
    fi

    newer_duplicate="$cand_id"
  done

  if [[ -n "$newer_duplicate" ]]; then
    echo "MUTATION_FIFO=SUPERSEDED role=pr-preview reason=duplicate_preview_superseded pr=$PR_NUMBER sha=$PR_SHA run_id=$GITHUB_RUN_ID newer_run_id=$newer_duplicate stage=$stage mutation=forbidden" >&2
    exit 78
  fi

  page_has_older_or_equal="$(printf '%s' "$preview_runs" | jq -r \
    --argjson current_run "$GITHUB_RUN_ID" '
      if (.workflow_runs | length < 100) or any(.workflow_runs[]?; .id <= $current_run) then
        "true"
      else
        "false"
      end
    ')" || {
    echo "PR_PREVIEW_LIVENESS=FAIL reason=preview_run_payload_invalid pr=$PR_NUMBER sha=$PR_SHA stage=$stage mutation=forbidden" >&2
    exit 1
  }

  if [[ "$page_has_older_or_equal" == "true" ]]; then
    break
  fi

  page=$((page + 1))
done

echo "PR_PREVIEW_LIVENESS=PASS pr=$PR_NUMBER sha=$PR_SHA base=$api_base_ref run_id=$GITHUB_RUN_ID duplicate_owner=latest stage=$stage"
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
  sleep 2
done

[[ -n "$pr_meta" ]] || {
  echo "PR_PREVIEW_LIVENESS=FAIL reason=pr_metadata_fetch_failed pr=$PR_NUMBER stage=$stage mutation=forbidden" >&2
  exit 1
}

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
preview_runs=""
for attempt in 1 2 3; do
  if preview_runs="$(gh api "/repos/${GITHUB_REPOSITORY}/actions/workflows/staging.yml/runs?event=pull_request_target&per_page=100" 2>/dev/null)" && [[ -n "$preview_runs" ]]; then
    break
  fi
  preview_runs=""
  sleep 2
done

[[ -n "$preview_runs" ]] || {
  echo "PR_PREVIEW_LIVENESS=FAIL reason=preview_run_query_failed pr=$PR_NUMBER sha=$PR_SHA stage=$stage mutation=forbidden" >&2
  exit 1
}

newer_duplicate="$(printf '%s' "$preview_runs" | jq -r \
  --argjson current_run "$GITHUB_RUN_ID" \
  --argjson pr "$PR_NUMBER" \
  --arg sha "$PR_SHA" '
    [.workflow_runs[]?
      | select((.id > $current_run)
        and (.event == "pull_request_target")
        and (.head_sha == $sha)
        and (any(.pull_requests[]?; .number == $pr)))]
    | sort_by(.id)
    | last
    | if . == null then "" else (.id | tostring) end
  ' 2>/dev/null || true)"

if [[ -n "$newer_duplicate" ]]; then
  echo "MUTATION_FIFO=SUPERSEDED role=pr-preview reason=duplicate_preview_superseded pr=$PR_NUMBER sha=$PR_SHA run_id=$GITHUB_RUN_ID newer_run_id=$newer_duplicate stage=$stage mutation=forbidden" >&2
  exit 78
fi

echo "PR_PREVIEW_LIVENESS=PASS pr=$PR_NUMBER sha=$PR_SHA base=$api_base_ref run_id=$GITHUB_RUN_ID duplicate_owner=latest stage=$stage"
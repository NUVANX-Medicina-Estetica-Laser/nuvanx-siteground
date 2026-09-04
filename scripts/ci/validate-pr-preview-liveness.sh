#!/usr/bin/env bash
set -Eeuo pipefail

: "${GITHUB_REPOSITORY:?Missing GITHUB_REPOSITORY}"
: "${PR_NUMBER:?Missing PR_NUMBER}"
: "${PR_SHA:?Missing PR_SHA}"
: "${GH_TOKEN:?Missing GH_TOKEN}"

command -v gh >/dev/null || { echo 'PR_PREVIEW_LIVENESS=FAIL reason=missing_gh' >&2; exit 1; }
command -v jq >/dev/null || { echo 'PR_PREVIEW_LIVENESS=FAIL reason=missing_jq' >&2; exit 1; }

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

echo "PR_PREVIEW_LIVENESS=PASS pr=$PR_NUMBER sha=$PR_SHA base=$api_base_ref stage=$stage"

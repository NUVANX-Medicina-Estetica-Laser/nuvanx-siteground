#!/usr/bin/env bash
set -Eeuo pipefail

: "${GITHUB_REPOSITORY:?Missing GITHUB_REPOSITORY}"
: "${GITHUB_RUN_ID:?Missing GITHUB_RUN_ID}"
: "${GITHUB_RUN_ATTEMPT:?Missing GITHUB_RUN_ATTEMPT}"
: "${GH_TOKEN:?Missing GH_TOKEN}"

command -v gh >/dev/null || { echo 'MUTATION_FIFO=FAIL reason=missing_gh' >&2; exit 1; }
command -v sort >/dev/null || { echo 'MUTATION_FIFO=FAIL reason=missing_sort' >&2; exit 1; }

CURRENT_RUN_ID="$(printf '%s' "$GITHUB_RUN_ID" | tr -d '[:space:]')"
CURRENT_RUN_ATTEMPT="$(printf '%s' "$GITHUB_RUN_ATTEMPT" | tr -d '[:space:]')"
[[ "$CURRENT_RUN_ID" =~ ^[0-9]{1,20}$ ]] || { echo "MUTATION_FIFO=FAIL reason=invalid_run_id value=$CURRENT_RUN_ID" >&2; exit 1; }
[[ "$CURRENT_RUN_ATTEMPT" =~ ^[0-9]{1,6}$ && "$CURRENT_RUN_ATTEMPT" -ge 1 ]] || { echo "MUTATION_FIFO=FAIL reason=invalid_run_attempt value=$CURRENT_RUN_ATTEMPT" >&2; exit 1; }

# Re-runs keep the original run_id while incrementing run_attempt. That breaks
# the monotonic ordering used as the cross-workflow FIFO. Mutation retries must
# therefore start a new workflow run and receive a fresh run_id.
if (( CURRENT_RUN_ATTEMPT > 1 )); then
  echo "MUTATION_FIFO=FAIL reason=rerun_forbidden run_id=$CURRENT_RUN_ID attempt=$CURRENT_RUN_ATTEMPT action=start_new_run" >&2
  exit 1
fi

ROLE="${MUTATION_ROLE:-environment-mutation}"
POLL_SECONDS="${MUTATION_WAIT_POLL_SECONDS:-15}"
STABILIZE_SECONDS="${MUTATION_WAIT_STABILIZE_SECONDS:-5}"
MAX_WAIT_SECONDS="${MUTATION_WAIT_MAX_SECONDS:-3600}"
CANCEL_SUPERSEDED_STAGING="${MUTATION_CANCEL_SUPERSEDED_STAGING:-0}"

[[ "$POLL_SECONDS" =~ ^[0-9]{1,5}$ && "$POLL_SECONDS" -ge 1 ]] || { echo 'MUTATION_FIFO=FAIL reason=invalid_poll_seconds' >&2; exit 1; }
[[ "$STABILIZE_SECONDS" =~ ^[0-9]{1,5}$ && "$STABILIZE_SECONDS" -ge 1 ]] || { echo 'MUTATION_FIFO=FAIL reason=invalid_stabilize_seconds' >&2; exit 1; }
[[ "$MAX_WAIT_SECONDS" =~ ^[0-9]{1,6}$ && "$MAX_WAIT_SECONDS" -ge 1 ]] || { echo 'MUTATION_FIFO=FAIL reason=invalid_max_wait_seconds' >&2; exit 1; }
[[ "$CANCEL_SUPERSEDED_STAGING" =~ ^[01]$ ]] || { echo 'MUTATION_FIFO=FAIL reason=invalid_cancel_superseded_staging' >&2; exit 1; }

is_mutation_workflow_path() {
  case "$1" in
    .github/workflows/staging.yml|.github/workflows/staging.yml@*|.github/workflows/production.yml|.github/workflows/production.yml@*) return 0 ;;
    *) return 1 ;;
  esac
}

is_mutation_event() {
  case "$1" in
    push|workflow_dispatch|pull_request_target) return 0 ;;
    *) return 1 ;;
  esac
}

current_meta=""
for attempt in 1 2 3; do
  if current_meta="$(gh api "/repos/${GITHUB_REPOSITORY}/actions/runs/${CURRENT_RUN_ID}" 2>/dev/null)" && [[ -n "$current_meta" ]]; then
    break
  fi
  current_meta=""
  sleep 2
done

[[ -n "$current_meta" ]] || {
  echo "MUTATION_FIFO=FAIL reason=api_fetch_current_run_failed run_id=$CURRENT_RUN_ID" >&2
  exit 1
}

current_path="$(printf '%s' "$current_meta" | jq -r '.path // ""')"
current_event="$(printf '%s' "$current_meta" | jq -r '.event // ""')"
current_status="$(printf '%s' "$current_meta" | jq -r '.status // ""')"
current_head_branch="$(printf '%s' "$current_meta" | jq -r '.head_branch // ""')"
api_attempt="$(printf '%s' "$current_meta" | jq -r '(.run_attempt // 0) | tostring')"

is_mutation_workflow_path "$current_path" || {
  echo "MUTATION_FIFO=FAIL reason=current_workflow_not_canonical path=$current_path" >&2
  exit 1
}
is_mutation_event "$current_event" || {
  echo "MUTATION_FIFO=FAIL reason=current_event_not_mutating event=$current_event" >&2
  exit 1
}
[[ "$api_attempt" == "$CURRENT_RUN_ATTEMPT" ]] || {
  echo "MUTATION_FIFO=FAIL reason=run_attempt_identity_mismatch env=$CURRENT_RUN_ATTEMPT api=$api_attempt" >&2
  exit 1
}
[[ "$current_status" != 'completed' ]] || {
  echo "MUTATION_FIFO=FAIL reason=current_run_already_completed run_id=$CURRENT_RUN_ID" >&2
  exit 1
}

EX_SUPERSEDED=78
DEFAULT_BRANCH="${STAGING_ACCEPTANCE_BRANCH:-${current_head_branch:-${DEFAULT_BRANCH:-master}}}"

# A push-triggered Staging run is useful only while it is the latest mutating
# Staging push for the canonical branch. Older pushes may be cancelled by the
# newest push, but PR previews/Production never cancel another mutation owner.
check_for_superseding_staging_run() {
  if [[ "$ROLE" != 'staging' || "$current_event" != 'push' ]]; then
    return 0
  fi

  local recent_runs=""
  for attempt in 1 2 3; do
    if recent_runs="$(gh api "/repos/${GITHUB_REPOSITORY}/actions/workflows/staging.yml/runs?event=push&per_page=30" 2>/dev/null)"; then
      break
    fi
    recent_runs=""
    sleep 2
  done
  [[ -n "$recent_runs" ]] || return 0

  local newer_run=""
  newer_run="$(printf '%s' "$recent_runs" | jq -r --arg current_id "$CURRENT_RUN_ID" --arg branch "$DEFAULT_BRANCH" '
    [.workflow_runs[]? | select((.id > ($current_id | tonumber)) and (.head_branch == $branch or .head_branch == null))]
    | first // empty
    | [(.id|tostring), (.head_sha // "")]
    | @tsv
  ' 2>/dev/null || true)"

  if [[ -n "$newer_run" ]]; then
    local newer_id newer_sha
    IFS=$'\t' read -r newer_id newer_sha <<< "$newer_run"
    if [[ -n "$newer_id" ]]; then
      echo "MUTATION_FIFO=SUPERSEDED role=staging run_id=$CURRENT_RUN_ID newer_run_id=$newer_id newer_sha=$newer_sha branch=$DEFAULT_BRANCH mutation=forbidden" >&2
      exit "$EX_SUPERSEDED"
    fi
  fi
}

cancel_superseded_staging_pushes() {
  if [[ "$CANCEL_SUPERSEDED_STAGING" != '1' || "$ROLE" != 'staging' || "$current_event" != 'push' ]]; then
    return 0
  fi

  local recent_runs=""
  for attempt in 1 2 3; do
    if recent_runs="$(gh api "/repos/${GITHUB_REPOSITORY}/actions/workflows/staging.yml/runs?event=push&branch=${DEFAULT_BRANCH}&per_page=30" 2>/dev/null)"; then
      break
    fi
    recent_runs=""
    sleep 2
  done
  [[ -n "$recent_runs" ]] || {
    echo 'MUTATION_FIFO=FAIL reason=cancel_candidate_query_failed' >&2
    exit 1
  }

  local targets=""
  targets="$(printf '%s' "$recent_runs" | jq -r --arg current_id "$CURRENT_RUN_ID" --arg branch "$DEFAULT_BRANCH" '
    .workflow_runs[]?
    | select((.id < ($current_id | tonumber))
      and (.event == "push")
      and (.head_branch == $branch or .head_branch == null)
      and ((.status == "queued") or (.status == "in_progress") or (.status == "waiting") or (.status == "requested") or (.status == "pending")))
    | [(.id|tostring), (.status // ""), (.head_sha // "")]
    | @tsv
  ' 2>/dev/null || true)"

  [[ -n "$targets" ]] || return 0

  while IFS=$'\t' read -r old_run_id old_status old_sha; do
    [[ "$old_run_id" =~ ^[0-9]{1,20}$ ]] || continue
    (( old_run_id < CURRENT_RUN_ID )) || continue

    if gh api --method POST "/repos/${GITHUB_REPOSITORY}/actions/runs/${old_run_id}/cancel" >/dev/null 2>&1; then
      echo "MUTATION_FIFO=CANCEL_SUPERSEDED role=staging run_id=$old_run_id status=$old_status sha=$old_sha newer_run_id=$CURRENT_RUN_ID"
      continue
    fi

    local old_meta="" refreshed_status=""
    old_meta="$(gh api "/repos/${GITHUB_REPOSITORY}/actions/runs/${old_run_id}" 2>/dev/null || true)"
    refreshed_status="$(printf '%s' "$old_meta" | jq -r '.status // ""' 2>/dev/null || true)"
    if [[ "$refreshed_status" == 'completed' ]]; then
      echo "MUTATION_FIFO=CANCEL_RACE_COMPLETED role=staging run_id=$old_run_id newer_run_id=$CURRENT_RUN_ID"
      continue
    fi

    echo "MUTATION_FIFO=FAIL reason=cancel_superseded_failed run_id=$old_run_id status=${refreshed_status:-unknown}" >&2
    exit 1
  done <<< "$targets"
}

# A PR preview can wait behind an older mutation for several minutes. Rebuild
# its synthetic master+PR tree only after the FIFO is positively clear so the
# deployed preview always includes the latest master visible at mutation time.
rebuild_pr_preview_after_fifo() {
  if [[ "$ROLE" != 'pr-preview' ]]; then
    return 0
  fi

  : "${PR_NUMBER:?Missing PR_NUMBER for pr-preview}"
  : "${PR_SHA:?Missing PR_SHA for pr-preview}"
  : "${CANDIDATE_ROOT:?Missing CANDIDATE_ROOT for pr-preview}"
  : "${GITHUB_ENV:?Missing GITHUB_ENV for pr-preview}"
  [[ "$PR_NUMBER" =~ ^[0-9]+$ && "$PR_SHA" =~ ^[0-9a-f]{40}$ ]]

  git fetch --no-tags origin master
  git fetch --no-tags origin "pull/${PR_NUMBER}/head:refs/remotes/origin/nvx-pr-preview"
  local resolved_pr_sha
  resolved_pr_sha="$(git rev-parse refs/remotes/origin/nvx-pr-preview)"
  [[ "$resolved_pr_sha" == "$PR_SHA" ]] || {
    echo "MUTATION_FIFO=FAIL reason=pr_head_mismatch expected=$PR_SHA actual=$resolved_pr_sha" >&2
    exit 1
  }

  if git worktree list --porcelain | grep -Fqx "worktree $CANDIDATE_ROOT"; then
    git worktree remove --force "$CANDIDATE_ROOT"
  elif [[ -e "$CANDIDATE_ROOT" ]]; then
    echo "MUTATION_FIFO=FAIL reason=candidate_root_not_managed path=$CANDIDATE_ROOT" >&2
    exit 1
  fi

  git worktree add --detach "$CANDIDATE_ROOT" origin/master
  set +e
  git -C "$CANDIDATE_ROOT" -c user.name='NUVANX CI' -c user.email='ci@nuvanx.invalid' merge --no-commit --no-ff "$PR_SHA"
  local merge_rc=$?
  set -e
  if (( merge_rc != 0 )); then
    git -C "$CANDIDATE_ROOT" merge --abort || true
    echo 'MUTATION_FIFO=FAIL reason=pr_preview_merge_conflict action=resolve_or_rebase' >&2
    exit 1
  fi

  if git -C "$CANDIDATE_ROOT" rev-parse -q --verify MERGE_HEAD >/dev/null; then
    git -C "$CANDIDATE_ROOT" -c user.name='NUVANX CI' -c user.email='ci@nuvanx.invalid' commit --no-gpg-sign -m "ci: preview merge PR #${PR_NUMBER} into master"
  fi

  local pr_preview_sha
  pr_preview_sha="$(git -C "$CANDIDATE_ROOT" rev-parse HEAD)"
  [[ "$pr_preview_sha" =~ ^[0-9a-f]{40}$ ]]
  git -C "$CANDIDATE_ROOT" diff --check origin/master "$pr_preview_sha"
  ! git -C "$CANDIDATE_ROOT" ls-tree -r "$pr_preview_sha" wp-content/themes/nuvanx-medical/ | awk '$1 == "120000" { found=1 } END { exit(found ? 0 : 1) }'
  find "$CANDIDATE_ROOT/wp-content/themes/nuvanx-medical" -path '*/vendor' -prune -o -name '*.php' -type f -print0 | xargs -0 -n1 php -l >/dev/null
  find "$CANDIDATE_ROOT/wp-content/themes/nuvanx-medical" -name '*.js' -type f -print0 | xargs -0 -r -n1 node --check >/dev/null

  echo "CANDIDATE_ROOT=$CANDIDATE_ROOT" >> "$GITHUB_ENV"
  echo "PR_PREVIEW_SHA=$pr_preview_sha" >> "$GITHUB_ENV"
  echo "PR_PREVIEW_REBUILT_AFTER_FIFO=PASS pr_sha=$PR_SHA preview_sha=$pr_preview_sha master_sha=$(git rev-parse origin/master)"
}

check_for_superseding_staging_run
cancel_superseded_staging_pushes

started_epoch="$(date +%s)"
clear_scans=0

while :; do
  blockers=""
  scan_failed=0

  # IMPORTANT TRUST BOUNDARY:
  # An aggregate workflow run stays a blocker for its entire non-completed
  # lifetime. GitHub can temporarily report every currently materialized job as
  # completed before a later matrix/fresh-runner job is materialized. Treating
  # that gap as a "zombie" allowed two Staging2 mutators to overlap. We now use
  # the aggregate run status as the lease authority and fail closed until it is
  # completed. A genuinely stale GitHub aggregate times out rather than opening
  # a concurrent mutation window.
  for status in queued in_progress waiting requested pending; do
    raw_status_runs=""
    if ! raw_status_runs="$(gh api --paginate "/repos/${GITHUB_REPOSITORY}/actions/runs?status=${status}&per_page=100" \
      --jq '.workflow_runs[] | [(.id|tostring),(.status // ""),(.event // ""),(.path // ""),(.head_sha // "")] | @tsv' 2>/dev/null)"; then
      scan_failed=1
      break
    fi

    while IFS=$'\t' read -r run_id run_status run_event run_path run_sha; do
      [[ -n "$run_id" ]] || continue
      [[ "$run_id" =~ ^[0-9]{1,20}$ ]] || continue
      (( run_id < CURRENT_RUN_ID )) || continue
      is_mutation_workflow_path "$run_path" || continue
      is_mutation_event "$run_event" || continue
      blockers+="${run_id}"$'\t'"${run_status}|${run_event}|${run_path}|${run_sha}"$'\n'
    done <<< "$raw_status_runs"
  done

  if (( scan_failed != 0 )); then
    clear_scans=0
    waited=$(( $(date +%s) - started_epoch ))
    if (( waited >= MAX_WAIT_SECONDS )); then
      echo "MUTATION_FIFO=FAIL reason=api_wait_timeout role=$ROLE run_id=$CURRENT_RUN_ID waited_seconds=$waited" >&2
      exit 1
    fi
    echo 'MUTATION_FIFO=WARN reason=api_query_failed retrying=true' >&2
    sleep "$POLL_SECONDS"
    continue
  fi

  if [[ -n "$blockers" ]]; then
    unique_blockers="$(printf '%s' "$blockers" | grep -v '^[[:space:]]*$' | sort -u -k1,1)"
    blocker_count="$(printf '%s\n' "$unique_blockers" | grep -c . || true)"
  else
    unique_blockers=""
    blocker_count=0
  fi

  if (( blocker_count == 0 )); then
    clear_scans=$((clear_scans + 1))
    if (( clear_scans >= 2 )); then
      check_for_superseding_staging_run
      rebuild_pr_preview_after_fifo
      waited=$(( $(date +%s) - started_epoch ))
      echo "MUTATION_FIFO=PASS role=$ROLE run_id=$CURRENT_RUN_ID attempt=$CURRENT_RUN_ATTEMPT waited_seconds=$waited stable_scans=$clear_scans aggregate_status=authoritative"
      exit 0
    fi
    echo "MUTATION_FIFO=CLEAR_STABILIZING role=$ROLE run_id=$CURRENT_RUN_ID attempt=$CURRENT_RUN_ATTEMPT scan=$clear_scans/2"
    sleep "$STABILIZE_SECONDS"
    continue
  fi

  clear_scans=0
  waited=$(( $(date +%s) - started_epoch ))
  if (( waited >= MAX_WAIT_SECONDS )); then
    echo "MUTATION_FIFO=FAIL reason=wait_timeout role=$ROLE run_id=$CURRENT_RUN_ID waited_seconds=$waited blockers=$blocker_count" >&2
    while IFS=$'\t' read -r b_run_id b_meta; do
      [[ -n "$b_run_id" ]] || continue
      echo "MUTATION_FIFO_BLOCKER run_id=$b_run_id meta=$b_meta" >&2
    done <<< "$unique_blockers"
    exit 1
  fi

  echo "MUTATION_FIFO=WAIT role=$ROLE run_id=$CURRENT_RUN_ID waited_seconds=$waited blockers=$blocker_count"
  while IFS=$'\t' read -r b_run_id b_meta; do
    [[ -n "$b_run_id" ]] || continue
    echo "MUTATION_FIFO_BLOCKER run_id=$b_run_id meta=$b_meta"
  done <<< "$unique_blockers"
  sleep "$POLL_SECONDS"
done

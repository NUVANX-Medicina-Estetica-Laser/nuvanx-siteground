#!/usr/bin/env bash
set -Eeuo pipefail

: "${CANDIDATE_SHA:?Missing CANDIDATE_SHA}"
: "${GITHUB_REPOSITORY:?Missing GITHUB_REPOSITORY}"
: "${GH_TOKEN:?Missing GH_TOKEN}"

STAGING_ACCEPTANCE_BRANCH="${STAGING_ACCEPTANCE_BRANCH:-master}"
STAGING_ACCEPTANCE_WORKFLOW_PATH="${STAGING_ACCEPTANCE_WORKFLOW_PATH:-.github/workflows/staging.yml}"

[[ "$CANDIDATE_SHA" =~ ^[0-9a-f]{40}$ ]] || { echo 'STAGING_ACCEPTANCE=FAIL reason=invalid_candidate_sha' >&2; exit 1; }
command -v curl >/dev/null
command -v jq >/dev/null
command -v unzip >/dev/null

api_headers=(
  -H "Authorization: Bearer $GH_TOKEN"
  -H 'Accept: application/vnd.github+json'
  -H 'X-GitHub-Api-Version: 2022-11-28'
)
public_api_headers=(
  -H 'Accept: application/vnd.github+json'
  -H 'X-GitHub-Api-Version: 2022-11-28'
)

# Fail closed on repository-governance bypasses. A production candidate must be
# a GitHub-verified commit produced by a merged PR into the canonical branch.
# This prevents unsigned/direct-to-master commits from being promoted even if a
# repository administrator can temporarily bypass the branch ruleset.
if ! commit_response="$(curl -fsSL --retry 3 --retry-all-errors --connect-timeout 10 --max-time 60 --proto '=https' --proto-redir '=https' "${api_headers[@]}" "https://api.github.com/repos/${GITHUB_REPOSITORY}/commits/${CANDIDATE_SHA}")"; then
  echo "STAGING_ACCEPTANCE=FAIL reason=github_api_commit_query_failed sha=$CANDIDATE_SHA" >&2
  exit 1
fi
commit_verified="$(printf '%s' "$commit_response" | jq -r '.commit.verification.verified // false')"
commit_verification_reason="$(printf '%s' "$commit_response" | jq -r '.commit.verification.reason // "unknown"')"
if [[ "$commit_verified" != 'true' ]]; then
  echo "STAGING_ACCEPTANCE=FAIL reason=unverified_candidate_commit sha=$CANDIDATE_SHA verification_reason=$commit_verification_reason" >&2
  exit 1
fi

if ! prs_response="$(curl -fsSL --retry 3 --retry-all-errors --connect-timeout 10 --max-time 60 --proto '=https' --proto-redir '=https' "${api_headers[@]}" "https://api.github.com/repos/${GITHUB_REPOSITORY}/commits/${CANDIDATE_SHA}/pulls?per_page=100")"; then
  echo "STAGING_ACCEPTANCE=FAIL reason=github_api_commit_pr_query_failed sha=$CANDIDATE_SHA" >&2
  exit 1
fi
merged_pr_fields="$(printf '%s' "$prs_response" | jq -cer --arg sha "$CANDIDATE_SHA" --arg branch "$STAGING_ACCEPTANCE_BRANCH" '
  if type != "array" then error("expected PR array") else . end
  | [
      .[]
      | select(
          (.number | type) == "number"
          and (.merged_at | type) == "string"
          and .base.ref == $branch
          and .merge_commit_sha == $sha
          and (.head.sha | type) == "string"
          and (.head.sha | test("^[0-9a-f]{40}$"))
          and (.user.login | type) == "string"
          and (.user.login | length) > 0
        )
    ]
  | sort_by(.number)
  | last
  | select(. != null)
  | [.number, .head.sha, .user.login]
  | @tsv
' 2>/dev/null)" || {
  echo "STAGING_ACCEPTANCE=FAIL reason=candidate_not_merged_pr_head sha=$CANDIDATE_SHA branch=$STAGING_ACCEPTANCE_BRANCH" >&2
  exit 1
}
IFS=$'\t' read -r merged_pr_number merged_pr_head_sha merged_pr_author <<< "$merged_pr_fields"
if [[ ! "$merged_pr_number" =~ ^[0-9]+$ || ! "$merged_pr_head_sha" =~ ^[0-9a-f]{40}$ || -z "$merged_pr_author" ]]; then
  echo "STAGING_ACCEPTANCE=FAIL reason=invalid_merged_pr_identity sha=$CANDIDATE_SHA" >&2
  exit 1
fi

# The reviews endpoint can be read without authentication for public repositories.
# Production intentionally keeps a least-privilege GITHUB_TOKEN without
# pull-requests:read, so assert the repository is public and use the public REST
# surface for review evidence. If visibility ever changes, this gate fails closed
# rather than silently weakening workflow permissions.
if ! repository_response="$(curl -fsSL --retry 3 --retry-all-errors --connect-timeout 10 --max-time 60 --proto '=https' --proto-redir '=https' "${api_headers[@]}" "https://api.github.com/repos/${GITHUB_REPOSITORY}")"; then
  echo "STAGING_ACCEPTANCE=FAIL reason=github_api_repository_query_failed" >&2
  exit 1
fi
repository_visibility="$(printf '%s' "$repository_response" | jq -er 'select(type == "object") | .visibility | select(type == "string")' 2>/dev/null)" || {
  echo "STAGING_ACCEPTANCE=FAIL reason=invalid_repository_visibility_response" >&2
  exit 1
}
if [[ "$repository_visibility" != 'public' ]]; then
  echo "STAGING_ACCEPTANCE=FAIL reason=review_probe_requires_public_repository visibility=$repository_visibility" >&2
  exit 1
fi

# The repository ruleset currently requires one approval, but administrators can
# bypass it. Mirror that requirement in the release gate and require an approval
# that is still the reviewer's latest decisive state, is anchored to the exact PR
# head merged into this candidate, comes from somebody other than the author, and
# belongs to a real user with write-or-higher repository permission.
reviews_json='[]'
review_page=1
while :; do
  if (( review_page > 100 )); then
    echo "STAGING_ACCEPTANCE=FAIL reason=review_pagination_limit_exceeded pr=$merged_pr_number" >&2
    exit 1
  fi
  if ! reviews_response="$(curl -fsSL --retry 3 --retry-all-errors --connect-timeout 10 --max-time 60 --proto '=https' --proto-redir '=https' "${public_api_headers[@]}" "https://api.github.com/repos/${GITHUB_REPOSITORY}/pulls/${merged_pr_number}/reviews?per_page=100&page=${review_page}")"; then
    echo "STAGING_ACCEPTANCE=FAIL reason=github_api_public_pr_reviews_query_failed pr=$merged_pr_number page=$review_page" >&2
    exit 1
  fi
  review_count="$(printf '%s' "$reviews_response" | jq -er '
    if type != "array" then error("expected review array") else . end
    | select(all(.[ ];
        type == "object"
        and (.id | type) == "number"
        and (.state | type) == "string"
        and (.user | type) == "object"
        and (.user.login | type) == "string"
        and (.user.login | test("^[A-Za-z0-9-]+$"))
        and (.user.type | type) == "string"
        and ((.commit_id == null) or ((.commit_id | type) == "string" and (.commit_id | test("^[0-9a-f]{40}$"))))
        and ((.submitted_at == null) or ((.submitted_at | type) == "string"))
      ))
    | length
  ' 2>/dev/null)" || {
    echo "STAGING_ACCEPTANCE=FAIL reason=invalid_pr_reviews_response pr=$merged_pr_number page=$review_page" >&2
    exit 1
  }
  if [[ ! "$review_count" =~ ^[0-9]+$ ]]; then
    echo "STAGING_ACCEPTANCE=FAIL reason=invalid_pr_reviews_response pr=$merged_pr_number page=$review_page" >&2
    exit 1
  fi
  reviews_json="$(jq -cn --argjson accumulated "$reviews_json" --argjson page "$reviews_response" '$accumulated + $page')" || {
    echo "STAGING_ACCEPTANCE=FAIL reason=review_accumulation_failed pr=$merged_pr_number page=$review_page" >&2
    exit 1
  }
  (( review_count < 100 )) && break
  review_page=$((review_page + 1))
done

eligible_reviewers_json="$(printf '%s' "$reviews_json" | jq -cer --arg head "$merged_pr_head_sha" --arg author "$merged_pr_author" '
  [
    .[]
    | select(.state == "APPROVED" or .state == "CHANGES_REQUESTED" or .state == "DISMISSED")
  ]
  | sort_by(.user.login, .id)
  | group_by(.user.login)
  | map(max_by(.id))
  | [
      .[]
      | select(
          .state == "APPROVED"
          and .commit_id == $head
          and .user.login != $author
          and .user.type == "User"
        )
      | .user.login
    ]
  | unique
' 2>/dev/null)" || {
  echo "STAGING_ACCEPTANCE=FAIL reason=review_state_resolution_failed pr=$merged_pr_number" >&2
  exit 1
}
mapfile -t eligible_reviewers < <(printf '%s' "$eligible_reviewers_json" | jq -r '.[]')
if (( ${#eligible_reviewers[@]} < 1 )); then
  echo "STAGING_ACCEPTANCE=FAIL reason=missing_current_head_approval pr=$merged_pr_number sha=$CANDIDATE_SHA pr_head=$merged_pr_head_sha" >&2
  exit 1
fi

approved_review_count=0
for reviewer in "${eligible_reviewers[@]}"; do
  if ! permission_response="$(curl -fsSL --retry 3 --retry-all-errors --connect-timeout 10 --max-time 60 --proto '=https' --proto-redir '=https' "${api_headers[@]}" "https://api.github.com/repos/${GITHUB_REPOSITORY}/collaborators/${reviewer}/permission")"; then
    echo "STAGING_ACCEPTANCE=FAIL reason=github_api_reviewer_permission_query_failed pr=$merged_pr_number reviewer=$reviewer" >&2
    exit 1
  fi
  reviewer_permission="$(printf '%s' "$permission_response" | jq -er 'select(type == "object") | .permission | select(type == "string")' 2>/dev/null)" || {
    echo "STAGING_ACCEPTANCE=FAIL reason=invalid_reviewer_permission_response pr=$merged_pr_number reviewer=$reviewer" >&2
    exit 1
  }
  case "$reviewer_permission" in
    admin|maintain|write)
      approved_review_count=$((approved_review_count + 1))
      ;;
    *)
      echo "STAGING_ACCEPTANCE_REVIEWER_SKIPPED pr=$merged_pr_number reviewer=$reviewer permission=$reviewer_permission reason=insufficient_permission" >&2
      ;;
  esac
done
if (( approved_review_count < 1 )); then
  echo "STAGING_ACCEPTANCE=FAIL reason=missing_authorized_current_head_approval pr=$merged_pr_number sha=$CANDIDATE_SHA pr_head=$merged_pr_head_sha" >&2
  exit 1
fi

echo "STAGING_ACCEPTANCE_GOVERNANCE=PASS sha=$CANDIDATE_SHA verified=1 merged_pr=$merged_pr_number approvals=$approved_review_count pr_head=$merged_pr_head_sha branch=$STAGING_ACCEPTANCE_BRANCH"

# Production candidates must carry the zero-submit HubSpot verification contract.
# This permanently rejects historical SHAs whose production QA filled and
# submitted the commercial HubSpot form, even if those SHAs once had successful
# Staging acceptance artifacts.
candidate_hubspot_probe="$(git show "${CANDIDATE_SHA}:scripts/staging2/h1-hubspot-e2e.mjs" 2>/dev/null || true)"
[[ -n "$candidate_hubspot_probe" ]] || {
  echo "STAGING_ACCEPTANCE=FAIL reason=missing_zero_submit_hubspot_probe sha=$CANDIDATE_SHA" >&2
  exit 1
}
if ! grep -Fq 'HUBSPOT_PRODUCTION_CONTRACT_MODE=ZERO_SUBMIT' <<< "$candidate_hubspot_probe"; then
  echo "STAGING_ACCEPTANCE=FAIL reason=hubspot_probe_missing_zero_submit_marker sha=$CANDIDATE_SHA" >&2
  exit 1
fi
if ! grep -Fq 'PRODUCTION_HUBSPOT_CONTRACT=PASS' <<< "$candidate_hubspot_probe"; then
  echo "STAGING_ACCEPTANCE=FAIL reason=hubspot_probe_missing_contract_marker sha=$CANDIDATE_SHA" >&2
  exit 1
fi
if grep -Eqi "from[[:space:]]+['\"]playwright['\"]|nvxqa-h1-|QA H1 Attribution|wp_set_consent|\?gclid=|\.click[[:space:]]*\(|submissions/v3" <<< "$candidate_hubspot_probe"; then
  echo "STAGING_ACCEPTANCE=FAIL reason=unsafe_live_hubspot_probe sha=$CANDIDATE_SHA" >&2
  exit 1
fi
echo "STAGING_ACCEPTANCE_HUBSPOT_SAFETY=PASS sha=$CANDIDATE_SHA zero_submit=1"

artifact_name="staging2-block-c-${CANDIDATE_SHA}"
if ! response="$(curl -fsSL --retry 3 --retry-all-errors --connect-timeout 10 --max-time 60 --proto '=https' --proto-redir '=https' "${api_headers[@]}" "https://api.github.com/repos/${GITHUB_REPOSITORY}/actions/artifacts?name=${artifact_name}&per_page=100")"; then
  echo "STAGING_ACCEPTANCE=FAIL reason=github_api_artifacts_query_failed sha=$CANDIDATE_SHA" >&2
  exit 1
fi
mapfile -t candidates < <(printf '%s' "$response" | jq -rc --arg name "$artifact_name" '[.artifacts[] | select(.name == $name and .expired == false)] | sort_by(.created_at) | reverse | .[] | [.id, .workflow_run.id, .created_at] | @tsv')
(( ${#candidates[@]} > 0 )) || { echo "STAGING_ACCEPTANCE=FAIL reason=no_artifact sha=$CANDIDATE_SHA" >&2; exit 1; }

for candidate in "${candidates[@]}"; do
  IFS=$'\t' read -r artifact_id run_id _ <<< "$candidate"
  [[ "$artifact_id" =~ ^[0-9]{1,20}$ && "$run_id" =~ ^[0-9]{1,20}$ ]] || continue

  run=""
  if ! run="$(curl -fsSL --retry 3 --retry-all-errors --connect-timeout 10 --max-time 60 --proto '=https' --proto-redir '=https' "${api_headers[@]}" "https://api.github.com/repos/${GITHUB_REPOSITORY}/actions/runs/${run_id}")"; then
    echo "STAGING_ACCEPTANCE_CANDIDATE_SKIPPED artifact_id=$artifact_id run_id=$run_id reason=fetch_run_failed" >&2
    continue
  fi
  IFS=$'\t' read -r head_branch run_head_sha workflow_path run_event < <(printf '%s' "$run" | jq -r '[.head_branch // "",.head_sha // "",.path // "",.event // ""] | @tsv')

  if [[ "$head_branch" != "$STAGING_ACCEPTANCE_BRANCH" ]]; then
    echo "STAGING_ACCEPTANCE_CANDIDATE_SKIPPED artifact_id=$artifact_id run_id=$run_id reason=branch_mismatch branch=$head_branch expected=$STAGING_ACCEPTANCE_BRANCH" >&2
    continue
  fi
  workflow_prefix="${workflow_path:0:${#STAGING_ACCEPTANCE_WORKFLOW_PATH}}"
  workflow_suffix="${workflow_path:${#STAGING_ACCEPTANCE_WORKFLOW_PATH}}"
  if [[ "$workflow_prefix" != "$STAGING_ACCEPTANCE_WORKFLOW_PATH" || ( -n "$workflow_suffix" && "${workflow_suffix:0:1}" != '@' ) || "$workflow_suffix" == '@' ]]; then
    echo "STAGING_ACCEPTANCE_CANDIDATE_SKIPPED artifact_id=$artifact_id run_id=$run_id reason=workflow_path_mismatch path=$workflow_path" >&2
    continue
  fi
  if [[ ! "$run_head_sha" =~ ^[0-9a-f]{40}$ ]]; then
    echo "STAGING_ACCEPTANCE_CANDIDATE_SKIPPED artifact_id=$artifact_id run_id=$run_id reason=invalid_run_head_sha sha=$run_head_sha" >&2
    continue
  fi
  if [[ "$run_event" != push && "$run_event" != workflow_dispatch ]]; then
    echo "STAGING_ACCEPTANCE_CANDIDATE_SKIPPED artifact_id=$artifact_id run_id=$run_id reason=non_mutation_event event=$run_event" >&2
    continue
  fi

  if [[ "$run_event" == push ]]; then
    if [[ "$run_head_sha" != "$CANDIDATE_SHA" ]]; then
      echo "STAGING_ACCEPTANCE_CANDIDATE_SKIPPED artifact_id=$artifact_id run_id=$run_id reason=push_sha_mismatch run_sha=$run_head_sha candidate_sha=$CANDIDATE_SHA" >&2
      continue
    fi
  else
    if ! git cat-file -e "${run_head_sha}^{commit}" 2>/dev/null || \
       ! git merge-base --is-ancestor "$run_head_sha" "origin/$STAGING_ACCEPTANCE_BRANCH" || \
       ! git merge-base --is-ancestor "$CANDIDATE_SHA" "$run_head_sha"; then
      echo "STAGING_ACCEPTANCE_CANDIDATE_SKIPPED artifact_id=$artifact_id run_id=$run_id reason=dispatch_sha_lineage_invalid run_sha=$run_head_sha candidate_sha=$CANDIDATE_SHA" >&2
      continue
    fi
  fi

  artifact_zip="${RUNNER_TEMP:-$(mktemp -d)}/staging-acceptance-${artifact_id}.zip"
  rm -f "$artifact_zip"
  if ! curl -LfsS --retry 3 --retry-all-errors --connect-timeout 10 --max-time 180 --max-filesize 157286400 --proto '=https' --proto-redir '=https' "${api_headers[@]}" "https://api.github.com/repos/${GITHUB_REPOSITORY}/actions/artifacts/${artifact_id}/zip" -o "$artifact_zip" || \
     ! unzip -tqq "$artifact_zip" >/dev/null 2>&1; then
    echo "STAGING_ACCEPTANCE_CANDIDATE_SKIPPED artifact_id=$artifact_id run_id=$run_id reason=artifact_download_or_zip_corrupt" >&2
    rm -f "$artifact_zip"
    continue
  fi

  manifest_path="$(unzip -Z1 "$artifact_zip" | grep -E '(^|/)acceptance-manifest\.json$' | head -n1 || true)"
  if [[ -z "$manifest_path" ]]; then
    echo "STAGING_ACCEPTANCE_CANDIDATE_SKIPPED artifact_id=$artifact_id run_id=$run_id reason=missing_manifest" >&2
    rm -f "$artifact_zip"
    continue
  fi
  manifest="$(unzip -p "$artifact_zip" "$manifest_path" 2>/dev/null || true)"
  rm -f "$artifact_zip"

  manifest_fields="$(printf '%s' "$manifest" | jq -er '
    select(type == "object" and .schema == 1) |
    [.candidate_sha,.run_id,.run_attempt,.event,.head_sha,.head_branch,.workflow_path] |
    select(all(.[]; type == "string" and length > 0)) |
    @tsv
  ' 2>/dev/null)" || {
    echo "STAGING_ACCEPTANCE_CANDIDATE_SKIPPED artifact_id=$artifact_id run_id=$run_id reason=invalid_manifest_schema" >&2
    continue
  }
  IFS=$'\t' read -r manifest_candidate manifest_run_id manifest_run_attempt manifest_event manifest_head_sha manifest_head_branch manifest_workflow <<< "$manifest_fields"

  if [[ "$manifest_candidate" != "$CANDIDATE_SHA" || "$manifest_run_id" != "$run_id" || ! "$manifest_run_attempt" =~ ^[0-9]{1,6}$ ]]; then
    echo "STAGING_ACCEPTANCE_CANDIDATE_SKIPPED artifact_id=$artifact_id run_id=$run_id reason=manifest_identity_mismatch" >&2
    continue
  fi
  manifest_run_attempt=$((10#$manifest_run_attempt))
  if (( manifest_run_attempt < 1 )); then
    echo "STAGING_ACCEPTANCE_CANDIDATE_SKIPPED artifact_id=$artifact_id run_id=$run_id reason=invalid_attempt_number" >&2
    continue
  fi
  if [[ "$manifest_event" != "$run_event" || "$manifest_head_sha" != "$run_head_sha" || "$manifest_head_branch" != "$STAGING_ACCEPTANCE_BRANCH" || "$manifest_workflow" != "$STAGING_ACCEPTANCE_WORKFLOW_PATH" ]]; then
    echo "STAGING_ACCEPTANCE_CANDIDATE_SKIPPED artifact_id=$artifact_id run_id=$run_id reason=manifest_metadata_mismatch" >&2
    continue
  fi

  exact_run=""
  if ! exact_run="$(curl -fsSL --retry 3 --retry-all-errors --connect-timeout 10 --max-time 60 --proto '=https' --proto-redir '=https' "${api_headers[@]}" "https://api.github.com/repos/${GITHUB_REPOSITORY}/actions/runs/${run_id}/attempts/${manifest_run_attempt}")"; then
    echo "STAGING_ACCEPTANCE_CANDIDATE_SKIPPED artifact_id=$artifact_id run_id=$run_id attempt=$manifest_run_attempt reason=fetch_attempt_failed" >&2
    continue
  fi
  IFS=$'\t' read -r exact_status exact_conclusion exact_branch exact_head_sha exact_path exact_event exact_attempt < <(printf '%s' "$exact_run" | jq -r '[.status // "",.conclusion // "",.head_branch // "",.head_sha // "",.path // "",.event // "",(.run_attempt // "" | tostring)] | @tsv')

  if [[ "$exact_status" != completed || "$exact_conclusion" != success ]]; then
    echo "STAGING_ACCEPTANCE_CANDIDATE_SKIPPED artifact_id=$artifact_id run_id=$run_id attempt=$manifest_run_attempt reason=attempt_not_successful status=$exact_status conclusion=$exact_conclusion" >&2
    continue
  fi
  if [[ "$exact_attempt" != "$manifest_run_attempt" || "$exact_branch" != "$manifest_head_branch" || "$exact_head_sha" != "$manifest_head_sha" || "$exact_event" != "$manifest_event" ]]; then
    echo "STAGING_ACCEPTANCE_CANDIDATE_SKIPPED artifact_id=$artifact_id run_id=$run_id attempt=$manifest_run_attempt reason=attempt_metadata_mismatch" >&2
    continue
  fi
  exact_prefix="${exact_path:0:${#STAGING_ACCEPTANCE_WORKFLOW_PATH}}"
  exact_suffix="${exact_path:${#STAGING_ACCEPTANCE_WORKFLOW_PATH}}"
  if [[ "$exact_prefix" != "$STAGING_ACCEPTANCE_WORKFLOW_PATH" || ( -n "$exact_suffix" && "${exact_suffix:0:1}" != '@' ) || "$exact_suffix" == '@' ]]; then
    echo "STAGING_ACCEPTANCE_CANDIDATE_SKIPPED artifact_id=$artifact_id run_id=$run_id reason=attempt_workflow_path_mismatch path=$exact_path" >&2
    continue
  fi

  if [[ "$manifest_event" == push ]]; then
    if [[ "$manifest_head_sha" != "$CANDIDATE_SHA" ]]; then
      echo "STAGING_ACCEPTANCE_CANDIDATE_SKIPPED artifact_id=$artifact_id run_id=$run_id reason=manifest_push_sha_mismatch" >&2
      continue
    fi
  else
    if ! git merge-base --is-ancestor "$CANDIDATE_SHA" "$manifest_head_sha"; then
      echo "STAGING_ACCEPTANCE_CANDIDATE_SKIPPED artifact_id=$artifact_id run_id=$run_id reason=manifest_candidate_not_ancestor" >&2
      continue
    fi
  fi

  if [[ -n "${GITHUB_OUTPUT:-}" ]]; then
    {
      echo "artifact_id=$artifact_id"
      echo "run_id=$run_id"
      echo "run_attempt=$manifest_run_attempt"
      echo "event=$manifest_event"
      echo "head_sha=$manifest_head_sha"
    } >> "$GITHUB_OUTPUT"
  fi
  echo "STAGING_ACCEPTANCE=PASS artifact=$artifact_name artifact_id=$artifact_id run_id=$run_id attempt=$manifest_run_attempt sha=$CANDIDATE_SHA event=$manifest_event run_head_sha=$manifest_head_sha conclusion=success"
  exit 0
done

echo "STAGING_ACCEPTANCE=FAIL reason=no_valid_successful_attempt sha=$CANDIDATE_SHA" >&2
exit 1
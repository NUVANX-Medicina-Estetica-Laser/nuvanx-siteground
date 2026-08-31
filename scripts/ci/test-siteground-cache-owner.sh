#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
HELPER="$ROOT/tools/deploy/siteground-cache-purge.sh"
DEPLOY="$ROOT/tools/deploy/deploy-to-staging2.sh"
WORKFLOW="$ROOT/.github/workflows/staging.yml"

fail() {
  echo "SITEGROUND_CACHE_OWNER_CONTRACT=FAIL $*" >&2
  exit 1
}

for file in "$HELPER" "$DEPLOY" "$WORKFLOW"; do
  [[ -s "$file" ]] || fail "missing=$file"
done

helper_purge_count="$(grep -Fc 'wp sg purge' "$HELPER" || true)"
deploy_purge_count="$(grep -Fc 'wp sg purge' "$DEPLOY" || true)"
workflow_purge_count="$(grep -Fc 'wp sg purge' "$WORKFLOW" || true)"

[[ "$helper_purge_count" -eq 1 ]] || fail "helper_wp_sg_purge_count=$helper_purge_count expected=1"
[[ "$deploy_purge_count" -eq 0 ]] || fail "deploy_inline_wp_sg_purge_count=$deploy_purge_count expected=0"
[[ "$workflow_purge_count" -eq 0 ]] || fail "workflow_inline_wp_sg_purge_count=$workflow_purge_count expected=0"

grep -Fq 'siteground_cache_purge "$WP_ROOT" preserve' "$DEPLOY" \
  || fail 'deploy_missing_canonical_helper_call'
grep -Fq 'tools/deploy/siteground-cache-purge.sh' "$WORKFLOW" \
  || fail 'workflow_missing_helper_payload'
[[ "$(grep -Fc 'siteground_cache_purge "$STAGING_ROOT" active' "$WORKFLOW" || true)" -eq 2 ]] \
  || fail 'workflow_expected_two_active_post_migration_calls'
[[ "$(grep -Fc 'siteground_cache_purge "$STAGING_ROOT" preserve' "$WORKFLOW" || true)" -eq 2 ]] \
  || fail 'workflow_expected_two_preserving_rollback_calls'
! grep -Eq 'siteground_cache_purge[^\n]*\|\|[[:space:]]*true' "$WORKFLOW" \
  || fail 'workflow_cache_helper_must_be_fail_closed'

bash -n "$HELPER"
bash -n "$DEPLOY"

echo "SITEGROUND_CACHE_OWNER_CONTRACT=PASS helper_purge_count=$helper_purge_count workflow_inline=0 deploy_inline=0"

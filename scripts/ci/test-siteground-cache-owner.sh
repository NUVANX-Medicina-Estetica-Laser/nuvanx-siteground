#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
HELPER="$ROOT/tools/deploy/siteground-cache-purge.sh"
BEHAVIOR_TEST="$ROOT/scripts/ci/test-siteground-cache-purge-behavior.sh"
DEPLOY="$ROOT/tools/deploy/deploy-to-staging2.sh"
WORKFLOW="$ROOT/.github/workflows/staging.yml"
RETIRED_PROD_CACHE="$ROOT/tools/deploy/flush-prod-cache.sh"

fail() {
  echo "SITEGROUND_CACHE_OWNER_CONTRACT=FAIL $*" >&2
  exit 1
}

for file in "$HELPER" "$BEHAVIOR_TEST" "$DEPLOY" "$WORKFLOW"; do
  [[ -s "$file" ]] || fail "missing=$file"
done
[[ ! -e "$RETIRED_PROD_CACHE" ]] || fail 'retired_duplicate_production_cache_script_present'

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
[[ "$(grep -Fc 'siteground_cache_purge "$STAGING_ROOT" "$optimizer_state"' "$WORKFLOW" || true)" -eq 2 ]] \
  || fail 'workflow_expected_two_snapshot_state_rollback_calls'
[[ "$(grep -Fc 'optimizer-state.txt' "$WORKFLOW" || true)" -ge 6 ]] \
  || fail 'workflow_optimizer_snapshot_state_contract_missing'
[[ "$(grep -Fc '$ROLLBACK_DIR/siteground-cache-purge.sh' "$WORKFLOW" || true)" -ge 4 ]] \
  || fail 'workflow_rollback_helper_snapshot_contract_missing'
! grep -Eq 'siteground_cache_purge[^\n]*\|\|[[:space:]]*true' "$WORKFLOW" \
  || fail 'workflow_cache_helper_must_be_fail_closed'

grep -Fq 'restore_requested_state_on_failure' "$HELPER" \
  || fail 'helper_failure_restore_trap_missing'
grep -Fq 'SITEGROUND_CACHE_PURGE_RESTORE=PASS' "$HELPER" \
  || fail 'helper_failure_restore_evidence_missing'
grep -Fq 'trap - ERR' "$HELPER" \
  || fail 'helper_must_clear_inherited_err_trap_before_failure_cleanup'

bash -n "$HELPER"
bash -n "$BEHAVIOR_TEST"
bash -n "$DEPLOY"
bash "$BEHAVIOR_TEST"

echo "SITEGROUND_CACHE_OWNER_CONTRACT=PASS helper_purge_count=$helper_purge_count workflow_inline=0 deploy_inline=0 retired_prod_duplicate=absent rollback_state=snapshot inherited_err_trap=isolated behavior=verified"

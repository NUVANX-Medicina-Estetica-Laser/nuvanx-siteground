#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
HELPER="$ROOT/tools/deploy/siteground-cache-purge.sh"
BEHAVIOR_TEST="$ROOT/scripts/ci/test-siteground-cache-purge-behavior.sh"
STAGING_DEPLOY="$ROOT/tools/deploy/deploy-to-staging2.sh"
STAGING_WORKFLOW="$ROOT/.github/workflows/staging.yml"
PROD_DEPLOY="$ROOT/tools/deploy/deploy-to-prod.sh"
PROD_WORKFLOW="$ROOT/.github/workflows/production.yml"
PROD_COMPENSATION="$ROOT/scripts/production/compensating-rollback.sh"
RETIRED_PROD_CACHE="$ROOT/tools/deploy/flush-prod-cache.sh"

fail() {
  echo "SITEGROUND_CACHE_OWNER_CONTRACT=FAIL $*" >&2
  exit 1
}

for file in \
  "$HELPER" \
  "$BEHAVIOR_TEST" \
  "$STAGING_DEPLOY" \
  "$STAGING_WORKFLOW" \
  "$PROD_DEPLOY" \
  "$PROD_WORKFLOW" \
  "$PROD_COMPENSATION"; do
  [[ -s "$file" ]] || fail "missing=$file"
done
[[ ! -e "$RETIRED_PROD_CACHE" ]] || fail 'retired_duplicate_production_cache_script_present'

helper_purge_count="$(grep -Fc 'wp sg purge' "$HELPER" || true)"
[[ "$helper_purge_count" -eq 1 ]] || fail "helper_wp_sg_purge_count=$helper_purge_count expected=1"

for consumer in "$STAGING_DEPLOY" "$STAGING_WORKFLOW" "$PROD_DEPLOY" "$PROD_WORKFLOW" "$PROD_COMPENSATION"; do
  inline_count="$(grep -Fc 'wp sg purge' "$consumer" || true)"
  [[ "$inline_count" -eq 0 ]] || fail "inline_wp_sg_purge file=$consumer count=$inline_count expected=0"
  ! grep -Eq 'plugin (activate|deactivate)[[:space:]]+sg-cachepress' "$consumer" \
    || fail "inline_optimizer_state_owner file=$consumer"
done

# Staging owns policy/orchestration; the helper alone owns cache mechanics.
grep -Fq 'siteground_cache_purge "$WP_ROOT" preserve' "$STAGING_DEPLOY" \
  || fail 'staging_deploy_missing_canonical_helper_call'
grep -Fq 'tools/deploy/siteground-cache-purge.sh' "$STAGING_WORKFLOW" \
  || fail 'staging_workflow_missing_helper_payload'
[[ "$(grep -Fc 'siteground_cache_purge "$STAGING_ROOT" active' "$STAGING_WORKFLOW" || true)" -eq 2 ]] \
  || fail 'staging_workflow_expected_two_active_post_migration_calls'
[[ "$(grep -Fc 'siteground_cache_purge "$STAGING_ROOT" "$optimizer_state"' "$STAGING_WORKFLOW" || true)" -eq 2 ]] \
  || fail 'staging_workflow_expected_two_snapshot_state_rollback_calls'
[[ "$(grep -Fc 'optimizer-state.txt' "$STAGING_WORKFLOW" || true)" -ge 6 ]] \
  || fail 'staging_workflow_optimizer_snapshot_state_contract_missing'
[[ "$(grep -Fc '$ROLLBACK_DIR/siteground-cache-purge.sh' "$STAGING_WORKFLOW" || true)" -ge 4 ]] \
  || fail 'staging_workflow_rollback_helper_snapshot_contract_missing'
! grep -Eq 'siteground_cache_purge[^\n]*\|\|[[:space:]]*true' "$STAGING_WORKFLOW" \
  || fail 'staging_workflow_cache_helper_must_be_fail_closed'

# Production must consume the exact accepted helper payload, never recreate its
# internals inside cutover, workflow YAML, or post-cutover compensation.
grep -Fq 'SITEGROUND_CACHE_HELPER="$SCRIPT_DIR/siteground-cache-purge.sh"' "$PROD_DEPLOY" \
  || fail 'production_deploy_helper_path_not_canonical'
grep -Fq 'source "$SITEGROUND_CACHE_HELPER"' "$PROD_DEPLOY" \
  || fail 'production_deploy_helper_not_sourced'
[[ "$(grep -Fc 'siteground_cache_purge "$PROD_ROOT" preserve' "$PROD_DEPLOY" || true)" -eq 2 ]] \
  || fail 'production_deploy_expected_cutover_and_rollback_cache_calls'
! grep -Fq 'purge_siteground_dynamic_cache' "$PROD_DEPLOY" \
  || fail 'production_deploy_legacy_cache_owner_present'
! grep -Fq 'wp cache flush' "$PROD_DEPLOY" \
  || fail 'production_deploy_manual_cache_flush_present'
! grep -Fq 'siteground-optimizer-combined-' "$PROD_DEPLOY" \
  || fail 'production_deploy_manual_optimizer_cache_delete_present'
! grep -Fq 'opcache_reset' "$PROD_DEPLOY" \
  || fail 'production_deploy_manual_opcache_owner_present'

grep -Fq ': "${SITEGROUND_CACHE_HELPER:?Missing SITEGROUND_CACHE_HELPER}"' "$PROD_COMPENSATION" \
  || fail 'production_compensation_helper_not_required'
grep -Fq 'source "$SITEGROUND_CACHE_HELPER"' "$PROD_COMPENSATION" \
  || fail 'production_compensation_helper_not_sourced'
[[ "$(grep -Fc 'siteground_cache_purge "$PROD_ROOT" preserve' "$PROD_COMPENSATION" || true)" -eq 1 ]] \
  || fail 'production_compensation_expected_one_canonical_cache_call'
! grep -Eq 'siteground_cache_purge[^\n]*\|\|[[:space:]]*true' "$PROD_COMPENSATION" \
  || fail 'production_compensation_cache_helper_must_be_fail_closed'
! grep -Fq 'wp cache flush' "$PROD_COMPENSATION" \
  || fail 'production_compensation_manual_cache_flush_present'
! grep -Fq 'siteground-optimizer-combined-' "$PROD_COMPENSATION" \
  || fail 'production_compensation_manual_optimizer_cache_delete_present'
! grep -Fq 'opcache_reset' "$PROD_COMPENSATION" \
  || fail 'production_compensation_manual_opcache_owner_present'

grep -Fq 'tools/deploy/siteground-cache-purge.sh' "$PROD_WORKFLOW" \
  || fail 'production_workflow_missing_exact_candidate_helper_source'
grep -Fq 'siteground-cache-purge.sh" "nvx-prod:$REMOTE_RELEASE/siteground-cache-purge.sh"' "$PROD_WORKFLOW" \
  || fail 'production_workflow_missing_remote_helper_payload'
grep -Fq 'PRODUCTION_CACHE_HELPER_PAYLOAD=PASS' "$PROD_WORKFLOW" \
  || fail 'production_workflow_missing_helper_lineage_evidence'
grep -Fq "SITEGROUND_CACHE_HELPER='\$REMOTE_RELEASE/siteground-cache-purge.sh'" "$PROD_WORKFLOW" \
  || fail 'production_workflow_compensation_helper_handoff_missing'

# The canonical helper itself is fail-closed and restores requested plugin state
# on failures inherited through nested shell contexts.
grep -Fq 'restore_requested_state_on_failure' "$HELPER" \
  || fail 'helper_failure_restore_trap_missing'
grep -Fq 'SITEGROUND_CACHE_PURGE_RESTORE=PASS' "$HELPER" \
  || fail 'helper_failure_restore_evidence_missing'
grep -Fq 'trap - ERR' "$HELPER" \
  || fail 'helper_must_clear_inherited_err_trap_before_failure_cleanup'

bash -n "$HELPER"
bash -n "$BEHAVIOR_TEST"
bash -n "$STAGING_DEPLOY"
bash -n "$PROD_DEPLOY"
bash -n "$PROD_COMPENSATION"
bash "$BEHAVIOR_TEST"

echo "SITEGROUND_CACHE_OWNER_CONTRACT=PASS helper_purge_count=$helper_purge_count staging_inline=0 production_inline=0 production_compensation_inline=0 retired_prod_duplicate=absent rollback_state=snapshot production_payload=lineage_verified fail_closed=true behavior=verified"

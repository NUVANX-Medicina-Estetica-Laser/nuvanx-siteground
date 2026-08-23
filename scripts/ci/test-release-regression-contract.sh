#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
fail() { echo "RELEASE_REGRESSION_CONTRACT=FAIL reason=$1" >&2; exit 1; }
assertion_count=0
pass_assert() {
  assertion_count=$((assertion_count + 1))
  echo "RELEASE_REGRESSION_ASSERT=PASS name=$1"
}

BRIDAL="$ROOT/wp-content/themes/nuvanx-medical/inc/nvx-catalog-json.php"
IDENTITY_CONTRACT="$ROOT/scripts/production/test-deploy-identity-contract.mjs"
DEPLOY="$ROOT/tools/deploy/deploy-to-prod.sh"
WORKFLOW="$ROOT/.github/workflows/production.yml"
BOUNDARY="$ROOT/scripts/production/verify-production-boundary.mjs"
VALORACION_FORM_CONTRACT="$ROOT/scripts/production/valoracion-form-contract.mjs"
VALORACION_FORM_CONTRACT_TEST="$ROOT/scripts/production/test-valoracion-form-contract.mjs"
ENV_FLAGS="$ROOT/wp-content/themes/nuvanx-medical/inc/nvx-environment-flags.php"
DEPLOY_STAMP="$ROOT/wp-content/themes/nuvanx-medical/inc/nvx-deploy-stamp.php"
LCP_CSS_CONTRACT="$ROOT/scripts/lint/test-lcp-css-delivery.mjs"
META_BROWSER_OWNER_CONTRACT="$ROOT/scripts/lint/test-meta-browser-owner-retirement.php"
SEO_OWNERSHIP_CONTRACT="$ROOT/scripts/lint/test-seo-catalog-ownership.php"
PREMERGE_CONTRACT="$ROOT/scripts/ci/test-pre-merge-protection-contract.sh"
SEO_TOOLING_DIR="$ROOT/scripts/seo"
THEME_DIR="$ROOT/wp-content/themes/nuvanx-medical"

for required in "$BRIDAL" "$IDENTITY_CONTRACT" "$DEPLOY" "$WORKFLOW" "$BOUNDARY" "$VALORACION_FORM_CONTRACT" "$VALORACION_FORM_CONTRACT_TEST" "$ENV_FLAGS" "$DEPLOY_STAMP" "$LCP_CSS_CONTRACT" "$META_BROWSER_OWNER_CONTRACT" "$SEO_OWNERSHIP_CONTRACT" "$PREMERGE_CONTRACT" "$SEO_TOOLING_DIR/package-lock.json" "$THEME_DIR/composer.lock"; do
  [[ -s "$required" ]] || fail "missing_file:$required"
done

# Workflow files must never contain unresolved merge/stash conflict markers.
# A single marker makes GitHub register the file without its declared name or
# workflow_dispatch trigger, which can silently disable the production control plane.
if grep -RInE '^[[:space:]]*(<<<<<<<|=======|>>>>>>>)' "$ROOT/.github/workflows" --include='*.yml' --include='*.yaml'; then
  fail 'workflow_conflict_marker_present'
fi
pass_assert 'workflow-no-conflict-markers'

bash "$PREMERGE_CONTRACT" || fail 'pre_merge_protection_execution_contract'
pass_assert 'pre-merge-protection-execution'

# Bridal retirement must remain an AND condition. This assertion is textual
# because the source depends on WordPress runtime state, but it tolerates
# formatting changes and produces an explicit diagnostic.
grep -Eq '\$is_seed[[:space:]]*=[[:space:]]*\$has_meta_key[[:space:]]*&&[[:space:]]*\$has_seed_marker' "$BRIDAL" \
  || fail 'bridal_seed_requires_meta_and_marker'
if grep -Eq '\$is_seed[[:space:]]*=[[:space:]]*\$has_meta_key[[:space:]]*\|\|[[:space:]]*\$has_seed_marker' "$BRIDAL"; then
  fail 'bridal_seed_or_logic_forbidden'
fi
pass_assert 'bridal-seed-and-contract'

node "$IDENTITY_CONTRACT" || fail 'deploy_identity_behavior'
pass_assert 'deploy-identity-behavior'

# Production deploys must refuse anonymous/manual identity before cutover.
grep -Eq 'DEPLOY_RUN_ID=.*GITHUB_RUN_ID' "$DEPLOY" || fail 'deploy_run_id_not_sourced_from_github'
grep -Eq 'DEPLOY_RUN_ID.*\^\[0-9\].*\$' "$DEPLOY" || fail 'deploy_run_id_numeric_guard_missing'
! grep -Eq 'DEPLOY_RUN_ID=.*manual' "$DEPLOY" || fail 'manual_deploy_identity_still_allowed'
pass_assert 'deploy-run-id-enforcement'

# Validate semantic workflow wiring without depending on step display names or
# environment assignment ordering.
grep -Eq 'EXPECTED_RUN_ID=.*GITHUB_RUN_ID' "$WORKFLOW" || fail 'release_expected_run_id_not_wired'
grep -Fq 'ORIGIN_SSH_ALIAS=nvx-prod-audit' "$WORKFLOW" || fail 'audit_origin_alias_not_wired'
grep -Fq 'ORIGIN_SSH_ALIAS=nvx-prod-hubspot' "$WORKFLOW" || fail 'hubspot_origin_alias_not_wired'
grep -Fq 'steps.production_identity.outcome' "$WORKFLOW" || fail 'identity_failure_not_compensated'
pass_assert 'workflow-identity-wiring'

# Boundary must use the shared semantic parser/validator and explicit run ID.
grep -Fq "from './deploy-identity-contract.mjs'" "$BOUNDARY" || fail 'boundary_shared_contract_missing'
grep -Fq "process.env.EXPECTED_RUN_ID || ''" "$BOUNDARY" || fail 'boundary_expected_run_id_not_explicit'
grep -Fq 'EXPECTED_HOST=${expectedHost}' "$BOUNDARY" || fail 'boundary_origin_expected_host_not_wired'
grep -Fq "from './valoracion-form-contract.mjs'" "$BOUNDARY" || fail 'boundary_valoracion_structural_contract_not_wired'
! grep -Fq 'process.env.EXPECTED_RUN_ID || process.env.GITHUB_RUN_ID' "$BOUNDARY" || fail 'boundary_current_audit_run_fallback_forbidden'
pass_assert 'boundary-identity-semantics'

# SiteGround may challenge GitHub-hosted runner egress with HTTP 202. That may
# only become a release PASS when every external route failed with the exact
# challenge signature and a second, non-loopback HTTPS public-host probe from
# SiteGround verifies the same four-field identity and full route contract.
grep -Fq 'verifyFromSiteGroundPublicEdge' "$BOUNDARY" || fail 'siteground_public_edge_fallback_missing'
grep -Fq "verifyFromSiteGroundProbe('public-edge')" "$BOUNDARY" || fail 'siteground_public_edge_mode_missing'
grep -Fq 'PROBE_MODE=${probeMode}' "$BOUNDARY" || fail 'siteground_probe_mode_not_wired'
grep -Fq 'externalFailures.length === routes.length' "$BOUNDARY" || fail 'siteground_challenge_all_routes_guard_missing'
grep -Fq 'failure.issues.length === 1' "$BOUNDARY" || fail 'siteground_challenge_single_issue_guard_missing'
grep -Fq 'HTTP 202 .*sg-captcha=challenge' "$BOUNDARY" || fail 'siteground_exact_challenge_signature_missing'
grep -Fq -- "--proto '=https' --proto-redir '=https'" "$BOUNDARY" || fail 'siteground_public_edge_https_only_missing'
grep -Fq 'reason=public_edge_loopback' "$BOUNDARY" || fail 'siteground_public_edge_loopback_guard_missing'
grep -Fq 'data-nvx-consent="functional"' "$BOUNDARY" || fail 'siteground_public_edge_functional_form_contract_missing'
grep -Fq 'report.external.inconclusiveAntiBot && report.sitegroundPublicEdge.pass' "$BOUNDARY" || fail 'siteground_public_edge_quorum_missing'
! grep -Fq 'report.external.pass || report.external.inconclusiveAntiBot' "$BOUNDARY" || fail 'siteground_challenge_direct_pass_forbidden'
pass_assert 'siteground-antibot-public-edge-fallback'

# IndexNow public-key check must not treat a GitHub-runner HTTP 202 as a
# public-edge PASS. It may only continue when the SiteGround host already
# verified the same public URL (non-loopback) during cutover.
grep -Fq 'source=siteground-public-edge github_runner_status=' "$WORKFLOW" || fail 'indexnow_github_202_public_edge_evidence_missing'
grep -Fq 'accept_siteground_public_edge_evidence' "$WORKFLOW" || fail 'indexnow_siteground_public_edge_helper_missing'
grep -Fq 'INDEXNOW_KEY_PUBLIC=FAIL reason=http_status status=$http_code' "$WORKFLOW" || fail 'indexnow_non_challenge_http_still_fails'
! grep -Fq 'INDEXNOW_KEY_PUBLIC=PASS source=public-edge github_runner_status=202' "$WORKFLOW" || fail 'indexnow_github_202_direct_public_edge_pass_forbidden'
pass_assert 'indexnow-github-202-siteground-public-edge'

node "$VALORACION_FORM_CONTRACT_TEST" || fail 'valoracion_form_structural_boundary_behavior'
pass_assert 'valoracion-form-structural-boundary'

# Shell-local variables inside the origin String.raw script must not use
# JavaScript template interpolation syntax. Dynamic values used in ERE matches
# must be escaped before interpolation so regex metacharacters stay literal.
! grep -Fq "bash.*\${name}" "$BOUNDARY" || fail 'boundary_shell_name_js_interpolation_forbidden'
! grep -Fq '${expected}' "$BOUNDARY" || fail 'boundary_shell_expected_js_interpolation_forbidden'
grep -Fq 'escape_ere()' "$BOUNDARY" || fail 'boundary_ere_escape_helper_missing'
grep -Fq 'name_re="$(escape_ere "$name")"' "$BOUNDARY" || fail 'boundary_name_regex_escape_missing'
grep -Fq 'expected_re="$(escape_ere "$expected")"' "$BOUNDARY" || fail 'boundary_expected_regex_escape_missing'
grep -Fq '$name_re' "$BOUNDARY" || fail 'boundary_escaped_name_reference_missing'
grep -Fq '$expected_re' "$BOUNDARY" || fail 'boundary_escaped_expected_reference_missing'
pass_assert 'boundary-shell-local-interpolation'

# Public deploy identity has exactly one wp_head owner. Staging only writes the
# legacy `.nvx-deploy-sha` file, so the canonical stamp renderer must fall back
# to nvx_environment_deploy_sha() while the environment module remains resolver-only.
! grep -Fq "add_action( 'wp_head', 'nvx_environment_render_deploy_sha'" "$ENV_FLAGS" || fail 'legacy_deploy_sha_head_emitter_forbidden'
! grep -Fq 'function nvx_environment_render_deploy_sha' "$ENV_FLAGS" || fail 'legacy_deploy_sha_renderer_forbidden'
grep -Fq "function_exists( 'nvx_environment_deploy_sha' )" "$DEPLOY_STAMP" || fail 'deploy_stamp_environment_fallback_guard_missing'
grep -Fq "nvx_environment_deploy_sha()" "$DEPLOY_STAMP" || fail 'deploy_stamp_environment_fallback_missing'
grep -Fq "add_action( 'wp_head', 'nvx_render_deploy_stamp_meta', 1 );" "$DEPLOY_STAMP" || fail 'canonical_deploy_stamp_head_owner_missing'
pass_assert 'single-deploy-sha-head-owner'

# Browser Meta ownership is intentionally absent. The source-scoped retirement
# contract must remain blocking so an unversioned production MU owner cannot
# silently restore pre-consent _fbp/_fbc, Pixel or browser dedupe behavior.
php "$META_BROWSER_OWNER_CONTRACT" || fail 'meta_browser_owner_retirement_contract'
pass_assert 'meta-browser-owner-retirement'

# LCP delivery rules are part of the release contract, not an optional lint.
# The canonical test protects the inlined foundation, blocking structural CSS,
# non-blocking Google Fonts, and the narrow editorial-only defer boundary.
node "$LCP_CSS_CONTRACT" || fail 'lcp_css_delivery_contract'
pass_assert 'lcp-css-delivery'

# Keep routed SEO metadata complete and enforce one text-metadata owner.
php "$SEO_OWNERSHIP_CONTRACT" || fail 'seo_catalog_ownership_contract'
pass_assert 'seo-catalog-ownership'

# scripts/seo remains an independent support package. CI validates syntax only;
# no credentialed Google/GTM publisher or diagnostic is executed automatically.
while IFS= read -r -d '' seo_script; do
  node --check "$seo_script" >/dev/null || fail "seo_script_syntax:$seo_script"
done < <(find "$SEO_TOOLING_DIR" -maxdepth 1 -type f -name '*.js' -print0)
pass_assert 'seo-tooling-syntax'

# The canonical weekly schedule already executes this release contract. Audit
# the two lockfiles that actually carry dependencies without creating a third
# workflow or adding registry-sensitive audits to every pull request.
if [[ "${GITHUB_EVENT_NAME:-}" == 'schedule' ]] || git diff --name-only HEAD~1 2>/dev/null | grep -qE 'package-lock\.json|composer\.lock'; then
  (
    cd "$SEO_TOOLING_DIR"
    npm audit --audit-level=high
  ) || fail 'weekly_seo_npm_audit'
  (
    cd "$THEME_DIR"
    composer audit --locked --format=summary
  ) || fail 'weekly_theme_composer_audit'
  pass_assert 'weekly-dependency-security-audit'
fi

echo "RELEASE_REGRESSION_CONTRACT=PASS assertions=$assertion_count"

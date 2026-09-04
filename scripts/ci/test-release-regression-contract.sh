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
PREMERGE_CONTRACT="$ROOT/scripts/ci/test-pre-merge-protection-contract.sh"
BOUNDARY="$ROOT/scripts/production/verify-production-boundary.mjs"
VALORACION_FORM_CONTRACT="$ROOT/scripts/production/valoracion-form-contract.mjs"
VALORACION_FORM_CONTRACT_TEST="$ROOT/scripts/production/test-valoracion-form-contract.mjs"
SIGNATURE_FRAME_CONTRACT="$ROOT/scripts/production/signature-frame-contract.mjs"
SIGNATURE_FRAME_CONTRACT_TEST="$ROOT/scripts/production/test-signature-frame-contract.mjs"
ENV_FLAGS="$ROOT/wp-content/themes/nuvanx-medical/inc/nvx-environment-flags.php"
DEPLOY_STAMP="$ROOT/wp-content/themes/nuvanx-medical/inc/nvx-deploy-stamp.php"
LCP_CSS_CONTRACT="$ROOT/scripts/lint/test-lcp-css-delivery.mjs"
CONVERSION_OWNERSHIP_CONTRACT="$ROOT/scripts/lint/test-conversion-ownership-contract.mjs"
META_BROWSER_OWNER_CONTRACT="$ROOT/scripts/lint/test-meta-browser-owner-retirement.php"
META_CAPI_GOVERNANCE_CONTRACT="$ROOT/scripts/lint/test-meta-capi-governance.mjs"
SEO_OWNERSHIP_CONTRACT="$ROOT/scripts/lint/test-seo-catalog-ownership.php"
WORDPRESS_SECURITY_CONTRACT="$ROOT/scripts/lint/test-wordpress-security-contract.php"
FORENSIC_SCANNER="$ROOT/tools/migrations/scan-forensic-source.py"
HUBSPOT_ZERO_SUBMIT_CONTRACT="$ROOT/scripts/staging2/h1-hubspot-e2e.mjs"
SEO_GEO_AUDIT="$ROOT/scripts/production/seo-geo-origin-audit.sh"
SEO_TOOLING_DIR="$ROOT/scripts/seo"
THEME_DIR="$ROOT/wp-content/themes/nuvanx-medical"

for required in "$BRIDAL" "$IDENTITY_CONTRACT" "$DEPLOY" "$WORKFLOW" "$PREMERGE_CONTRACT" "$BOUNDARY" "$VALORACION_FORM_CONTRACT" "$VALORACION_FORM_CONTRACT_TEST" "$SIGNATURE_FRAME_CONTRACT" "$SIGNATURE_FRAME_CONTRACT_TEST" "$ENV_FLAGS" "$DEPLOY_STAMP" "$LCP_CSS_CONTRACT" "$CONVERSION_OWNERSHIP_CONTRACT" "$META_BROWSER_OWNER_CONTRACT" "$META_CAPI_GOVERNANCE_CONTRACT" "$SEO_OWNERSHIP_CONTRACT" "$WORDPRESS_SECURITY_CONTRACT" "$FORENSIC_SCANNER" "$HUBSPOT_ZERO_SUBMIT_CONTRACT" "$SEO_GEO_AUDIT" "$SEO_TOOLING_DIR/package-lock.json" "$THEME_DIR/composer.lock" "$THEME_DIR/composer.json" "$THEME_DIR/phpcs.xml.dist"; do
  [[ -s "$required" ]] || fail "missing_file:$required"
done

# Workflow files must never contain unresolved merge/stash conflict markers.
# A single marker makes GitHub register the file without its declared name or
# workflow_dispatch trigger, which can silently disable the production control plane.
if grep -RInE '^[[:space:]]*(<<<<<<<|=======|>>>>>>>)' "$ROOT/.github/workflows" --include='*.yml' --include='*.yaml'; then
  fail 'workflow_conflict_marker_present'
fi
pass_assert 'workflow-no-conflict-markers'

# The executable merge/promotion protection gate is itself a release invariant.
# Its regression contract locks direct argv execution, canonical-root semantics,
# production dependency/runtime coverage and current-tree secret-scan semantics.
bash "$PREMERGE_CONTRACT" || fail 'premerge_protection_contract'
pass_assert 'premerge-protection-contract'

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
grep -Fq 'secrets.PROD_DB_NAME || vars.PROD_DB_NAME' "$WORKFLOW" || fail 'production_db_name_fallback_missing'
pass_assert 'workflow-identity-wiring'

# llms.txt remains an optional machine-readable discovery aid. Google Search
# does not use it as a Search visibility/ranking requirement, so every LLMS_*
# negative branch must be non-blocking while actual crawl/Search gates remain
# release-blocking. Keep this as a static contract because it guards policy
# classification, not the runtime reachability of the optional file.
grep -Fq "info 'LLMS_GOOGLE_SEARCH_REQUIREMENT=OPTIONAL'" "$SEO_GEO_AUDIT" || fail 'llms_google_optional_marker_missing'
llms_block="$(awk '
  /LLMS_GOOGLE_SEARCH_REQUIREMENT=OPTIONAL/ { capture=1 }
  capture { print }
  capture && /^fi$/ { exit }
' "$SEO_GEO_AUDIT")"
[[ -n "$llms_block" ]] || fail 'llms_optional_block_missing'
if grep -Eq '(^|[[:space:]])fail[[:space:]]' <<<"$llms_block"; then
  fail 'llms_optional_branch_calls_fail'
fi
for marker in 'LLMS_HTTP' 'LLMS_TOO_SMALL' 'LLMS_IDENTITY_MISSING' 'LLMS_FETCH'; do
  grep -Fq "warn \"$marker" <<<"$llms_block" \
    || grep -Fq "warn '$marker" <<<"$llms_block" \
    || fail "llms_negative_not_warning:$marker"
done
grep -Fq 'pass "LLMS_DISCOVERY bytes=$llms_bytes"' "$SEO_GEO_AUDIT" || fail 'llms_healthy_pass_missing'

# Critical Search gates must remain blocking independently of llms.txt.
grep -Fq "fail 'ROBOTS_FETCH'" "$SEO_GEO_AUDIT" || fail 'robots_fetch_no_longer_blocking'
grep -Fq 'fail "AI_SEARCH_CRAWLER_BLOCKED bot=$bot"' "$SEO_GEO_AUDIT" || fail 'ai_search_crawler_no_longer_blocking'
grep -Fq "echo 'SITEMAP_INDEX_FETCH=FAIL' >&2; exit 1" "$SEO_GEO_AUDIT" || fail 'sitemap_fetch_no_longer_blocking'
grep -Fq 'echo "SITEMAP_INDEX_HTTP=FAIL status=$HTTP_CODE" >&2; exit 1' "$SEO_GEO_AUDIT" || fail 'sitemap_http_no_longer_blocking'
grep -Fq "\$issues[] = 'canonical-count-'" "$SEO_GEO_AUDIT" || fail 'canonical_count_issue_missing'
grep -Fq "\$issues[] = 'canonical-mismatch:'" "$SEO_GEO_AUDIT" || fail 'canonical_mismatch_issue_missing'
grep -Fq "\$issues[] = 'missing-h1'" "$SEO_GEO_AUDIT" || fail 'h1_issue_missing'
grep -Fq "\$issues[] = 'missing-jsonld'" "$SEO_GEO_AUDIT" || fail 'jsonld_issue_missing'
grep -Fq 'exit($issues ? 1 : 0);' "$SEO_GEO_AUDIT" || fail 'html_issue_exit_no_longer_blocking'
grep -Fq 'fail "URL_SEO url=$url detail=$detail"' "$SEO_GEO_AUDIT" || fail 'url_seo_failure_no_longer_blocking'

# Origin schema probes must target published canonical routes, not retired aliases.
schema_probe_block="$(awk '
  /treatment_procedure_paths=\(/ { capture=1 }
  capture { print }
  /for path in "\$\{treatment_faq_paths\[@\]\}"/ { exit }
' "$SEO_GEO_AUDIT")"
[[ -n "$schema_probe_block" ]] || fail 'seo_geo_schema_probe_block_missing'
for retired in '/laser-co2-madrid/' '/btl-exion-madrid/' '/acido-hialuronico-madrid/' '/profhilo-madrid/'; do
  if grep -Fq "$retired" <<<"$schema_probe_block"; then
    fail "seo_geo_retired_schema_path:$retired"
  fi
done
grep -Fq '/laser-co2-fraccionado-madrid-textura-cicatrices-poro/' "$SEO_GEO_AUDIT" || fail 'seo_geo_missing_canonical_co2_path'
grep -Fq '/exion-btl/' "$SEO_GEO_AUDIT" || fail 'seo_geo_missing_canonical_exion_path'
grep -Fq '/acido-hialuronico-relleno-madrid/' "$SEO_GEO_AUDIT" || fail 'seo_geo_missing_canonical_ha_path'
FAQ_CATALOG="$ROOT/wp-content/themes/nuvanx-medical/inc/nvx-schema-faq.php"
FAQ_CONTRACT="$ROOT/scripts/lint/test-treatment-faqpage-contract.php"
# Validate the semantic key->schema alias while tolerating PHPCS/alignment whitespace.
grep -Eq "'facial_ha'[[:space:]]*=>[[:space:]]*'acido_hialuronico'" "$FAQ_CATALOG" || fail 'seo_geo_missing_facial_ha_schema_alias'
grep -Fq "'acido_hialuronico'," "$FAQ_CONTRACT" || fail 'seo_geo_missing_acido_hialuronico_faq_contract'
pass_assert 'seo-geo-canonical-schema-paths'

# Production identity is a hard boundary before the audit can report PASS.
grep -Fq "[[ \"\$release_sha\" =~ ^[0-9a-f]{40}\$ ]] || { echo 'Invalid production deploy marker.' >&2; exit 1; }" "$SEO_GEO_AUDIT" || fail 'deploy_sha_identity_no_longer_blocking'
grep -Fq '[[ "$(wp config get DB_NAME)" == "$PROD_DB_NAME" ]]' "$SEO_GEO_AUDIT" || fail 'production_db_identity_guard_missing'
grep -Fq '[[ "$(wp option get home)" == "$BASE_URL" ]]' "$SEO_GEO_AUDIT" || fail 'production_home_identity_guard_missing'
grep -Fq '[[ "$(wp option get siteurl)" == "$BASE_URL" ]]' "$SEO_GEO_AUDIT" || fail 'production_siteurl_identity_guard_missing'
grep -Fq '[[ "$(wp option get blog_public)" == '\''1'\'' ]]' "$SEO_GEO_AUDIT" || fail 'production_indexability_identity_guard_missing'
grep -Fq '[[ "$(wp theme list --status=active --field=name)" == '\''nuvanx-medical'\'' ]]' "$SEO_GEO_AUDIT" || fail 'production_theme_identity_guard_missing'
pass_assert 'llms-google-search-optional-critical-gates-blocking'

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
grep -Fq 'canonicalValoracionFirstPartyIssues' "$BOUNDARY" || fail 'siteground_public_edge_first_party_form_contract_missing'
grep -Fq 'report.external.inconclusiveAntiBot && report.sitegroundPublicEdge.pass' "$BOUNDARY" || fail 'siteground_public_edge_quorum_missing'
! grep -Fq 'report.external.pass || report.external.inconclusiveAntiBot' "$BOUNDARY" || fail 'siteground_challenge_direct_pass_forbidden'
pass_assert 'siteground-antibot-public-edge-fallback'

# Production origin verification must preserve Host/SNI while tolerating SiteGround
# hosts with no HTTPS listener on 127.0.0.1. Only curl exit 7 may activate a
# validated non-loopback local IPv4; DNS/public edge is never an origin fallback.
grep -Fq 'hostname -I 2>/dev/null || true' "$BOUNDARY" || fail 'production_origin_local_ip_discovery_missing'
grep -Fq 'origin_fallback_ip_unavailable' "$BOUNDARY" || fail 'production_origin_local_ip_fail_closed_missing'
grep -Fq "wpSGCacheBypass=1" "$BOUNDARY" || fail 'production_origin_cache_bypass_missing'
grep -Fq 'origin_remote_ip_mismatch' "$BOUNDARY" || fail 'production_origin_remote_ip_guard_missing'
grep -Fq '[[ "$curl_rc" -eq 7 ]]' "$BOUNDARY" || fail 'production_origin_exit7_fallback_guard_missing'
! grep -Eq 'getent[[:space:]]+(ahosts|hosts)|dig[[:space:]]|nslookup[[:space:]]' "$BOUNDARY" || fail 'production_origin_dns_fallback_forbidden'
pass_assert 'production-origin-local-ip-fallback'

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

node "$SIGNATURE_FRAME_CONTRACT_TEST" || fail 'signature_frame_structural_boundary_behavior'
pass_assert 'signature-frame-structural-boundary'

python3 -m py_compile "$FORENSIC_SCANNER" || fail 'forensic_scanner_syntax'
pass_assert 'forensic-scanner-syntax'

# Historical one-time Production collectors were retired atomically with their
# workflow inputs/jobs. The reusable redacted scanner stays as a release dependency.
[[ ! -e "$ROOT/tools/migrations/final-close-wp-inventory.php" ]] \
  || fail 'retired_final_close_collector_present'
[[ ! -e "$ROOT/tools/migrations/collect-mu-plugin-forensics.sh" ]] \
  || fail 'retired_mu_plugin_forensics_collector_present'
! grep -Fq 'run_final_close_audit' "$WORKFLOW" || fail 'retired_final_close_input_present'
! grep -Fq 'run_mu_plugin_forensics' "$WORKFLOW" || fail 'retired_mu_forensics_input_present'
! grep -Eq '^[[:space:]]*final_close_audit:' "$WORKFLOW" || fail 'retired_final_close_job_present'
! grep -Eq '^[[:space:]]*mu_plugin_forensics:' "$WORKFLOW" || fail 'retired_mu_forensics_job_present'
if git -C "$ROOT" grep -I -n -E 'run_final_close_audit|run_mu_plugin_forensics|final-close-wp-inventory|collect-mu-plugin-forensics' \
  -- ':!scripts/ci/test-release-regression-contract.sh' >/tmp/nvx-retired-collector-refs; then
  cat /tmp/nvx-retired-collector-refs >&2
  fail 'retired_collector_reference_present'
fi
pass_assert 'historical-production-collectors-retired'

EXPECTED_SHA="$(git -C "$ROOT" rev-parse HEAD)" node "$HUBSPOT_ZERO_SUBMIT_CONTRACT" || fail 'hubspot_zero_submit_contract'
pass_assert 'hubspot-zero-submit-contract'

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

# Meta CAPI governance: browser Pixel owner must remain 'none', server-side
# CAPI must be governed, and the Supabase 402 gateway restriction must be
# classified as TRANSIENT_INFRASTRUCTURE (not a code defect). Guardrails
# prevent redeploy/credential-rotation/data-purge as a 402 response.
node "$META_CAPI_GOVERNANCE_CONTRACT" || fail 'meta_capi_governance_contract'
pass_assert 'meta-capi-governance'

# WPCS is intentionally absent from Composer to keep the lock free of the LGPL
# dependency chain and the vulnerable legacy WPCS release. Preserve the removed
# WordPress security semantics with a repository-owned, executable contract.
php "$WORDPRESS_SECURITY_CONTRACT" || fail 'wordpress_security_contract'
pass_assert 'wordpress-security-contract'

# LCP delivery rules are part of the release contract, not an optional lint.
# The canonical test protects the inlined foundation, blocking structural CSS,
# non-blocking Google Fonts, and the narrow editorial-only defer boundary.
node "$LCP_CSS_CONTRACT" || fail 'lcp_css_delivery_contract'
pass_assert 'lcp-css-delivery'
# Form conversion ownership is a release-blocking invariant. The canonical
# path is HubSpot -> GA4 generate_lead -> Ads 908 import; direct form owners
# and the retired GTM publisher must not return.
node "$CONVERSION_OWNERSHIP_CONTRACT" || fail 'conversion_ownership_contract'
pass_assert 'conversion-ownership-contract'

# Keep routed SEO metadata complete and enforce one text-metadata owner.
php "$SEO_OWNERSHIP_CONTRACT" || fail 'seo_catalog_ownership_contract'
pass_assert 'seo-catalog-ownership'

# scripts/seo remains an independent support package. CI validates syntax only;
# no credentialed Google/GTM publisher or diagnostic is executed automatically.
while IFS= read -r -d '' seo_script; do
  node --check "$seo_script" >/dev/null || fail "seo_script_syntax:$seo_script"
done < <(find "$SEO_TOOLING_DIR" -maxdepth 1 -type f -name '*.js' -print0)
pass_assert 'seo-tooling-syntax'

# The canonical weekly schedule executes this release contract. A pull request
# must evaluate the complete branch delta against its base, not only HEAD~1;
# otherwise dependency changes split across multiple commits (or followed by a
# base-branch merge) could bypass audit/install/PHPCS/PHPStan.
dependency_gate=0
if [[ "${GITHUB_EVENT_NAME:-}" == 'schedule' ]]; then
  dependency_gate=1
elif [[ "${GITHUB_EVENT_NAME:-}" == 'pull_request' ]]; then
  base_ref="${GITHUB_BASE_REF:-master}"
  git rev-parse --verify "origin/${base_ref}^{commit}" >/dev/null 2>&1 || fail 'dependency_base_ref_missing'
  if git diff --name-only "origin/${base_ref}...HEAD" | grep -qE 'package-lock\.json|composer\.lock'; then
    dependency_gate=1
  fi
elif git diff --name-only HEAD~1 2>/dev/null | grep -qE 'package-lock\.json|composer\.lock'; then
  dependency_gate=1
fi

if (( dependency_gate == 1 )); then
  (
    cd "$SEO_TOOLING_DIR"
    npm audit --audit-level=high
  ) || fail 'weekly_seo_npm_audit'
  (
    cd "$THEME_DIR"
    composer validate --no-check-publish
    composer audit --locked --format=summary
    composer install --no-interaction --no-progress --prefer-dist
    ./vendor/bin/phpcs --standard=phpcs.xml.dist
    ./vendor/bin/phpstan analyse --memory-limit=2G
  ) || fail 'weekly_theme_dependency_quality'
  pass_assert 'weekly-dependency-security-and-quality'
fi

echo "RELEASE_REGRESSION_CONTRACT=PASS assertions=$assertion_count"

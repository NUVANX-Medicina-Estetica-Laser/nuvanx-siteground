#!/usr/bin/env node
import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const boundarySource = await fs.readFile(path.join(here, 'verify-staging-boundary.mjs'), 'utf8');
const sharedSource = await fs.readFile(path.join(here, 'siteground-origin-verifier.mjs'), 'utf8');

const failures = [];
const requireSource = (source, needle, reason) => {
  if (!source.includes(needle)) failures.push(reason);
};
const forbidSource = (source, needle, reason) => {
  if (source.includes(needle)) failures.push(reason);
};

// The reusable origin verifier continues to own strict HTTPS/SNI loopback
// verification and must retain its transport, status, captcha, exact-SHA and
// robots guards.
requireSource(sharedSource, '--resolve "${EXPECTED_HOST}:443:127.0.0.1"', 'shared_https_loopback_resolve_missing');
requireSource(sharedSource, 'origin_url="https://${EXPECTED_HOST}${ROUTE}"', 'shared_https_origin_url_missing');
requireSource(sharedSource, "--proto \\'=https\\'", 'shared_https_protocol_guard_missing');
requireSource(sharedSource, 'unexpected_remote_ip_$remote_ip', 'shared_loopback_remote_ip_guard_missing');
requireSource(sharedSource, "[[ \"$code\" == \\'200\\' ]]", 'shared_origin_http_200_guard_missing');
requireSource(sharedSource, 'deploy_sha_${deploy_sha:-missing}', 'shared_exact_deploy_sha_guard_missing');
requireSource(sharedSource, "^sg-captcha:[[:space:]]*challenge", 'shared_origin_captcha_signature_missing');
requireSource(sharedSource, 'captcha-header', 'shared_origin_captcha_diagnostic_missing');
requireSource(sharedSource, 'missing-noindex', 'shared_origin_noindex_guard_missing');
requireSource(sharedSource, 'missing-nofollow', 'shared_origin_nofollow_guard_missing');
requireSource(sharedSource, 'ORIGIN_VERIFY_FAIL route=$ROUTE reason=$1', 'shared_verify_failure_diagnostic_missing');
requireSource(sharedSource, 'ORIGIN_HTML_FAIL route=$ROUTE reason=$1', 'shared_html_failure_diagnostic_missing');
forbidSource(sharedSource, 'base_url="http://localhost"', 'shared_legacy_http_localhost_fallback_present');
forbidSource(sharedSource, '"${base_url}${ROUTE}"', 'shared_legacy_http_localhost_request_present');

// The top-level boundary verifier deliberately moved its SiteGround fallback
// away from loopback curl after the host proved that 127.0.0.1:443 is not a
// reliable listener. Its fail-closed origin contract is now WP-CLI based:
// immutable deploy marker + functional WordPress + staging blog_public=0.
requireSource(boundarySource, "const shaFile = `${stagingRoot}/wp-content/themes/nuvanx-medical/.nvx-deploy-sha`;", 'boundary_deploy_sha_file_owner_missing');
requireSource(boundarySource, "deploy_sha=\"$(tr -d '\\\\r\\\\n' < '${shaFile}' 2>/dev/null || true)\"", 'boundary_deploy_sha_read_missing');
requireSource(boundarySource, '[[ "$deploy_sha" =~ ^[0-9a-f]{40}$ ]]', 'boundary_deploy_sha_shape_guard_missing');
requireSource(boundarySource, '[[ "$deploy_sha" == "$EXPECTED_SHA" ]]', 'boundary_exact_deploy_sha_guard_missing');
requireSource(boundarySource, "wp eval 'echo \\\"WP_OK\\\";' --allow-root", 'boundary_wp_functional_probe_missing');
requireSource(boundarySource, 'wp option get blog_public --allow-root', 'boundary_blog_public_probe_missing');
requireSource(boundarySource, '[[ "$blog_public" == "0" ]]', 'boundary_staging_noindex_guard_missing');
requireSource(boundarySource, "robots_combined='noindex,nofollow'", 'boundary_robots_contract_missing');
requireSource(boundarySource, 'ORIGIN_BOUNDARY_FAIL route=$ROUTE reason=$1', 'boundary_failure_diagnostic_missing');
requireSource(boundarySource, 'isTransientSiteGroundChallenge(response) && getOriginFallbackAvailable()', 'boundary_transient_origin_fallback_gate_missing');
requireSource(boundarySource, 'result.originFallback = verifyViaSiteGroundOrigin(route)', 'boundary_wpcli_origin_fallback_wiring_missing');
forbidSource(boundarySource.toLowerCase(), 'localhost', 'boundary_localhost_transport_present');
forbidSource(boundarySource, '127.0.0.1', 'boundary_loopback_address_present');
forbidSource(boundarySource.toLowerCase(), 'curl', 'boundary_curl_transport_present');

if (failures.length > 0) {
  console.error(`STAGING_BOUNDARY_ORIGIN_CONTRACT=FAIL reasons=${failures.join(',')}`);
  process.exit(1);
}

console.log('STAGING_BOUNDARY_ORIGIN_CONTRACT=PASS boundary=wpcli-failclosed shared=https-loopback sha=exact robots=noindex,nofollow captcha=strict diagnostic=explicit');

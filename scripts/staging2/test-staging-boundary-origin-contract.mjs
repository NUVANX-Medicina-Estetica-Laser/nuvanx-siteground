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

// Verify boundary delegates curl transport to the shared helper
requireSource(boundarySource, 'buildSiteGroundOriginCurlLines(', 'boundary_delegates_to_shared_transport_missing');
requireSource(boundarySource, 'origin_url="https://${EXPECTED_HOST}${ROUTE}"', 'boundary_https_origin_url_missing');
requireSource(boundarySource, 'deploy_sha_${deploy_sha:-missing}', 'boundary_exact_deploy_sha_guard_missing');
forbidSource(boundarySource, 'base_url="http://localhost"', 'boundary_legacy_http_localhost_fallback_present');
forbidSource(boundarySource, '"${base_url}${ROUTE}"', 'boundary_legacy_http_localhost_request_present');

// Verify boundary inline guards
requireSource(boundarySource, "^sg-captcha:[[:space:]]*challenge", 'boundary_origin_captcha_signature_missing');
requireSource(boundarySource, 'sg_captcha_challenge', 'boundary_origin_captcha_diagnostic_missing');
requireSource(boundarySource, 'missing_noindex', 'boundary_origin_noindex_guard_missing');
requireSource(boundarySource, 'missing_nofollow', 'boundary_origin_nofollow_guard_missing');
requireSource(boundarySource, 'ORIGIN_BOUNDARY_FAIL route=$ROUTE reason=$1', 'boundary_failure_diagnostic_missing');

// Verify shared transport helper owns strict loopback & local fallback probes
requireSource(sharedSource, '--resolve "\\${EXPECTED_HOST}:443:127.0.0.1"', 'shared_https_loopback_resolve_missing');
requireSource(sharedSource, '--resolve "\\${EXPECTED_HOST}:443:\\${fallback_ip}"', 'shared_https_fallback_resolve_missing');
requireSource(sharedSource, "--proto '=https'", 'shared_https_protocol_guard_missing');
requireSource(sharedSource, 'unexpected_remote_ip_$remote_ip', 'shared_loopback_remote_ip_guard_missing');
requireSource(sharedSource, "[[ \"$code\" == \\'200\\' ]]", 'shared_origin_http_200_guard_missing');
requireSource(sharedSource, 'origin_url="https://${EXPECTED_HOST}${ROUTE}"', 'shared_https_origin_url_missing');
requireSource(sharedSource, 'deploy_sha_${deploy_sha:-missing}', 'shared_exact_deploy_sha_guard_missing');
forbidSource(sharedSource, 'base_url="http://localhost"', 'shared_legacy_http_localhost_fallback_present');
forbidSource(sharedSource, '"${base_url}${ROUTE}"', 'shared_legacy_http_localhost_request_present');

// Verify shared verifier probes have cache bypass
const probes = sharedSource
  .split('\n')
  .filter((line) => line.includes('curl -4') && line.includes('$origin_url'));

if (probes.length < 2) {
  failures.push(`shared_origin_curl_probe_count_${probes.length}`);
}

probes.forEach((probe, index) => {
  if (!probe.includes("-b 'wpSGCacheBypass=1'")) {
    failures.push(`shared_origin_curl_${index + 1}_cache_bypass_missing`);
  }
});

requireSource(sharedSource, "^sg-captcha:[[:space:]]*challenge", 'shared_origin_captcha_signature_missing');
requireSource(sharedSource, 'captcha-header', 'shared_origin_captcha_diagnostic_missing');
requireSource(sharedSource, 'missing-noindex', 'shared_origin_noindex_guard_missing');
requireSource(sharedSource, 'missing-nofollow', 'shared_origin_nofollow_guard_missing');
requireSource(sharedSource, 'ORIGIN_VERIFY_FAIL route=$ROUTE reason=$1', 'shared_verify_failure_diagnostic_missing');
requireSource(sharedSource, 'ORIGIN_HTML_FAIL route=$ROUTE reason=$1', 'shared_html_failure_diagnostic_missing');

if (failures.length > 0) {
  console.error(`STAGING_BOUNDARY_ORIGIN_CONTRACT=FAIL reasons=${failures.join(',')}`);
  process.exit(1);
}

console.log('STAGING_BOUNDARY_ORIGIN_CONTRACT=PASS owners=boundary,shared transport=centralized host=sni-preserved status=200 sha=exact robots=noindex,nofollow captcha=strict cache_bypass=all_origin_curl_probes diagnostic=explicit');

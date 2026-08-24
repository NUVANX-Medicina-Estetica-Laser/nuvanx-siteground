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

for (const [label, source] of [
  ['boundary', boundarySource],
  ['shared', sharedSource],
]) {
  requireSource(source, '--resolve "${EXPECTED_HOST}:443:127.0.0.1"', `${label}_https_loopback_resolve_missing`);
  requireSource(source, 'origin_url="https://${EXPECTED_HOST}${ROUTE}"', `${label}_https_origin_url_missing`);
  requireSource(source, "--proto \\'=https\\'", `${label}_https_protocol_guard_missing`);
  requireSource(source, 'unexpected_remote_ip_$remote_ip', `${label}_loopback_remote_ip_guard_missing`);
  requireSource(source, "[[ \"$code\" == \\'200\\' ]]", `${label}_origin_http_200_guard_missing`);
  requireSource(source, 'deploy_sha_${deploy_sha:-missing}', `${label}_exact_deploy_sha_guard_missing`);
  requireSource(source, 'sg_captcha_challenge', `${label}_origin_captcha_header_guard_missing`);
  forbidSource(source, 'base_url="http://localhost"', `${label}_legacy_http_localhost_fallback_present`);
  forbidSource(source, '"${base_url}${ROUTE}"', `${label}_legacy_http_localhost_request_present`);
}

requireSource(boundarySource, 'missing_noindex', 'boundary_origin_noindex_guard_missing');
requireSource(boundarySource, 'missing_nofollow', 'boundary_origin_nofollow_guard_missing');
requireSource(boundarySource, 'ORIGIN_BOUNDARY_FAIL route=$ROUTE reason=$1', 'boundary_failure_diagnostic_missing');
requireSource(sharedSource, 'missing-noindex', 'shared_origin_noindex_guard_missing');
requireSource(sharedSource, 'missing-nofollow', 'shared_origin_nofollow_guard_missing');
requireSource(sharedSource, 'ORIGIN_VERIFY_FAIL route=$ROUTE reason=$1', 'shared_verify_failure_diagnostic_missing');
requireSource(sharedSource, 'ORIGIN_HTML_FAIL route=$ROUTE reason=$1', 'shared_html_failure_diagnostic_missing');

if (failures.length > 0) {
  console.error(`STAGING_BOUNDARY_ORIGIN_CONTRACT=FAIL reasons=${failures.join(',')}`);
  process.exit(1);
}

console.log('STAGING_BOUNDARY_ORIGIN_CONTRACT=PASS owners=boundary,shared transport=https-loopback host=sni-preserved status=200 sha=exact robots=noindex,nofollow diagnostic=explicit');

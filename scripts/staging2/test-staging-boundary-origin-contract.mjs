#!/usr/bin/env node
import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const subjectPath = path.join(here, 'verify-staging-boundary.mjs');
const source = await fs.readFile(subjectPath, 'utf8');

const failures = [];
const requireSource = (needle, reason) => {
  if (!source.includes(needle)) failures.push(reason);
};
const forbidSource = (needle, reason) => {
  if (source.includes(needle)) failures.push(reason);
};

requireSource('--resolve "${EXPECTED_HOST}:443:127.0.0.1"', 'https_loopback_resolve_missing');
requireSource('origin_url="https://${EXPECTED_HOST}${ROUTE}"', 'https_origin_url_missing');
requireSource("--proto \\'=https\\'", 'https_protocol_guard_missing');
requireSource('unexpected_remote_ip_$remote_ip', 'loopback_remote_ip_guard_missing');
requireSource("[[ \"$code\" == \\'200\\' ]]", 'origin_http_200_guard_missing');
requireSource('deploy_sha_${deploy_sha:-missing}', 'exact_deploy_sha_guard_missing');
requireSource('missing_noindex', 'origin_noindex_guard_missing');
requireSource('missing_nofollow', 'origin_nofollow_guard_missing');
requireSource('sg_captcha_challenge', 'origin_captcha_header_guard_missing');
requireSource('ORIGIN_BOUNDARY_FAIL route=$ROUTE reason=$1', 'origin_failure_diagnostic_missing');
forbidSource('base_url="http://localhost"', 'legacy_http_localhost_fallback_present');
forbidSource('"${base_url}${ROUTE}"', 'legacy_http_localhost_request_present');

if (failures.length > 0) {
  console.error(`STAGING_BOUNDARY_ORIGIN_CONTRACT=FAIL reasons=${failures.join(',')}`);
  process.exit(1);
}

console.log('STAGING_BOUNDARY_ORIGIN_CONTRACT=PASS transport=https-loopback host=sni-preserved status=200 sha=exact robots=noindex,nofollow diagnostic=explicit');

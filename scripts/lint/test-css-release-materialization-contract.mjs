#!/usr/bin/env node
/**
 * Linked static CSS release-boundary contract.
 *
 * Staging and Production must verify the tracked CSS source surface before and
 * after cutover. Generated dist/ materialization is forbidden.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '../..');
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8');
const fail = (message) => { throw new Error(`CSS_RELEASE_BOUNDARY_CONTRACT=FAIL ${message}`); };
const requireText = (source, needle, label) => {
  if (!source.includes(needle)) fail(`${label} missing=${JSON.stringify(needle)}`);
};
const forbidText = (source, needle, label) => {
  if (source.includes(needle)) fail(`${label} forbidden=${JSON.stringify(needle)}`);
};
const requireOrder = (source, first, second, label) => {
  const a = source.indexOf(first);
  const b = source.indexOf(second);
  if (a < 0 || b < 0 || a >= b) fail(`${label} order_invalid`);
};

const verifier = read('wp-content/themes/nuvanx-medical/tools/verify-theme-css.php');
const nodeVerifier = read('scripts/build/compile-theme-css.mjs');
const staging = read('tools/deploy/deploy-to-staging2.sh');
const production = read('tools/deploy/deploy-to-prod.sh');

requireText(verifier, 'CSS_RELEASE_VERIFICATION=PASS', 'php_verifier_marker');
requireText(verifier, 'legacy_dist_present', 'php_verifier_dist_guard');
requireText(verifier, 'artifact_owner=git_exact_sha', 'php_verifier_exact_sha_owner');
requireText(nodeVerifier, 'CSS_RELEASE_VERIFICATION=PASS', 'node_verifier_marker');
forbidText(nodeVerifier, 'compile-theme-css.php', 'node_compiler_transport_retired');
forbidText(nodeVerifier, 'spawnSync', 'node_runtime_compiler_retired');

requireText(staging, 'tools/verify-theme-css.php', 'staging_verifier_payload');
requireText(staging, 'php "$SOURCE_THEME/tools/verify-theme-css.php"', 'staging_source_verification');
requireText(staging, 'php "$LIVE_THEME/tools/verify-theme-css.php"', 'staging_live_verification');
forbidText(staging, 'compile-theme-css.php', 'staging_compiler_retired');
forbidText(staging, 'dist/manifest.json', 'staging_manifest_retired');
requireOrder(staging, 'php "$SOURCE_THEME/tools/verify-theme-css.php"', "echo '== Synchronize theme to staging2 =='", 'staging_verify_before_copy');
requireOrder(staging, "echo '== Synchronize theme to staging2 =='", 'php "$LIVE_THEME/tools/verify-theme-css.php"', 'staging_verify_after_copy');

requireText(production, 'tools/verify-theme-css.php', 'production_verifier_payload');
requireText(production, 'php "$STAGED_THEME/tools/verify-theme-css.php"', 'production_staged_verification');
requireText(production, 'php wp-content/themes/nuvanx-medical/tools/verify-theme-css.php', 'production_live_verification');
forbidText(production, 'compile-theme-css.php', 'production_compiler_retired');
forbidText(production, 'dist/manifest.json', 'production_manifest_retired');
requireOrder(production, 'php "$STAGED_THEME/tools/verify-theme-css.php"', '== Mandatory pre-deploy rollback snapshot ==', 'production_verify_before_snapshot');
requireOrder(production, '== Directory cutover ==', 'php wp-content/themes/nuvanx-medical/tools/verify-theme-css.php', 'production_verify_after_cutover');

console.log('CSS_RELEASE_BOUNDARY_CONTRACT=PASS model=linked_exact_sha staging=verify+copy+verify production=verify+cutover+verify generated_dist=absent');

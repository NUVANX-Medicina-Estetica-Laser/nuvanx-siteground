#!/usr/bin/env node
/**
 * CSS release materialization regression contract.
 *
 * Ensures generated dist/ state is materialized from the exact accepted theme
 * before Staging/Production cutover and then verified on the deployed theme.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '../..');

const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8');
const fail = (message) => {
  throw new Error(`CSS_RELEASE_MATERIALIZATION_CONTRACT=FAIL ${message}`);
};
const requireText = (content, needle, label) => {
  if (!content.includes(needle)) fail(`${label} missing=${JSON.stringify(needle)}`);
};
const requireOrder = (content, first, second, label) => {
  const firstIndex = content.indexOf(first);
  const secondIndex = content.indexOf(second);
  if (firstIndex < 0 || secondIndex < 0 || firstIndex >= secondIndex) {
    fail(`${label} order_invalid first=${JSON.stringify(first)} second=${JSON.stringify(second)}`);
  }
};

const compiler = read('wp-content/themes/nuvanx-medical/tools/compile-theme-css.php');
const wrapper = read('scripts/build/compile-theme-css.mjs');
const staging = read('tools/deploy/deploy-to-staging2.sh');
const production = read('tools/deploy/deploy-to-prod.sh');

requireText(compiler, 'CSS_COMPILATION=PASS', 'compiler_build_marker');
requireText(compiler, 'CSS_DISTRIBUTION=PASS', 'compiler_verify_marker');
requireText(compiler, '--verify-only', 'compiler_verify_mode');
requireText(compiler, "'assets/css/nvx-treatment-authority.css'", 'compiler_core_contract');
requireText(wrapper, 'tools/compile-theme-css.php', 'node_wrapper_single_owner');
requireText(wrapper, "spawnSync('php'", 'node_wrapper_php_transport');

requireText(staging, 'tools/compile-theme-css.php', 'staging_compiler_payload');
requireText(staging, 'SOURCE_DATE_EPOCH=0 php "$SOURCE_THEME/tools/compile-theme-css.php"', 'staging_materialization');
requireText(staging, '[[ -s "$SOURCE_THEME/dist/manifest.json" ]]', 'staging_manifest_precondition');
requireText(staging, 'php "$LIVE_THEME/tools/compile-theme-css.php" --verify-only', 'staging_runtime_verify');
requireText(staging, 'STAGING_CSS_RUNTIME=PASS', 'staging_runtime_marker');
requireOrder(
  staging,
  'SOURCE_DATE_EPOCH=0 php "$SOURCE_THEME/tools/compile-theme-css.php"',
  "echo '== Synchronize theme to staging2 =='",
  'staging_compile_before_cutover'
);
requireOrder(
  staging,
  "echo '== Synchronize theme to staging2 =='",
  'php "$LIVE_THEME/tools/compile-theme-css.php" --verify-only',
  'staging_verify_after_copy'
);

requireText(production, 'SOURCE_DATE_EPOCH=0 php "$STAGED_THEME/tools/compile-theme-css.php"', 'production_materialization');
requireText(production, '[[ -s "$STAGED_THEME/dist/manifest.json" ]]', 'production_manifest_precondition');
requireText(production, 'dist/manifest.json', 'production_required_manifest');
requireText(production, 'php wp-content/themes/nuvanx-medical/tools/compile-theme-css.php --verify-only', 'production_runtime_verify');
requireText(production, 'PRODUCTION_CSS_RUNTIME=PASS', 'production_runtime_marker');
requireOrder(
  production,
  'SOURCE_DATE_EPOCH=0 php "$STAGED_THEME/tools/compile-theme-css.php"',
  '== Mandatory pre-deploy rollback snapshot ==',
  'production_compile_before_snapshot_and_cutover'
);
requireOrder(
  production,
  '== Directory cutover ==',
  'php wp-content/themes/nuvanx-medical/tools/compile-theme-css.php --verify-only',
  'production_verify_after_cutover'
);

console.log('CSS_RELEASE_MATERIALIZATION_CONTRACT=PASS compiler=single_owner staging=materialize+verify production=materialize+verify fallback=not_release_path');

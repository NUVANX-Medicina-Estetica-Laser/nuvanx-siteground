#!/usr/bin/env node
/**
 * Transport wrapper for the canonical theme-local CSS compiler.
 *
 * The compiler itself ships with the theme so CI, Staging and Production use
 * one implementation when materializing generated dist/ state from an exact
 * accepted source tree.
 *
 * @package nuvanx-siteground
 */

import { spawnSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT_DIR = path.join(__dirname, '../..');
const COMPILER = path.join(
  ROOT_DIR,
  'wp-content/themes/nuvanx-medical/tools/compile-theme-css.php'
);

const result = spawnSync('php', [COMPILER, ...process.argv.slice(2)], {
  cwd: ROOT_DIR,
  env: process.env,
  stdio: 'inherit',
});

if (result.error) {
  console.error('CSS_COMPILATION=FAIL', result.error.message);
  process.exit(1);
}

process.exit(result.status ?? 1);

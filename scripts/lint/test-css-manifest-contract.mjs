#!/usr/bin/env node
/**
 * Static CSS source-release contract.
 *
 * The tracked source files are the only runtime CSS representation. Generated
 * dist/ artifacts and manifest ownership are forbidden.
 */

import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '../..');
const theme = path.join(root, 'wp-content/themes/nuvanx-medical');
const cssDir = path.join(theme, 'assets/css');
const governancePath = path.join(theme, 'inc/nvx-native-style-governance.php');
const distDir = path.join(theme, 'dist');

const governance = await fs.readFile(governancePath, 'utf8');
const inventoryMatch = governance.match(/function\s+nvx_theme_critical_stylesheet_files\s*\([^)]*\)\s*:\s*array\s*\{([\s\S]*?)\n\}/);
if (!inventoryMatch) throw new Error('CSS source inventory owner is missing');

const declared = [...inventoryMatch[1].matchAll(/['"](assets\/css\/[^'"]+\.css)['"]/g)]
  .map((match) => match[1])
  .sort();
const actual = (await fs.readdir(cssDir, { withFileTypes: true }))
  .filter((entry) => entry.isFile() && entry.name.endsWith('.css'))
  .map((entry) => `assets/css/${entry.name}`)
  .sort();

if (new Set(declared).size !== declared.length) throw new Error('CSS inventory contains duplicate source ownership');
if (JSON.stringify(declared) !== JSON.stringify(actual)) {
  throw new Error(`CSS inventory mismatch declared=${declared.length} actual=${actual.length}`);
}

for (const relative of actual) {
  const content = await fs.readFile(path.join(theme, relative));
  if (content.length === 0) throw new Error(`CSS source is empty: ${relative}`);
}

try {
  const stat = await fs.stat(distDir);
  if (stat.isDirectory()) throw new Error('Retired theme dist/ directory exists');
} catch (error) {
  if (error?.code !== 'ENOENT') throw error;
}

if (/dist\/manifest\.json|nvx-critical-inline|nvx_theme_get_css_manifest|nvx_theme_get_compiled_critical_css_bundle/.test(governance)) {
  throw new Error('Runtime still contains generated CSS ownership');
}

console.log(
  `CSS_SOURCE_RELEASE_CONTRACT=PASS sources=${actual.length}`
  + ' single_representation=tracked_source legacy_dist=absent runtime_manifest=absent'
);

#!/usr/bin/env node
/**
 * Verify the exact-SHA static CSS release surface.
 *
 * There is no generated runtime CSS distribution. assets/css/*.css are the
 * deployable artifacts and WordPress serves them as normal stylesheet links.
 */

import crypto from 'node:crypto';
import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '../..');
const theme = path.join(root, 'wp-content/themes/nuvanx-medical');
const cssDir = path.join(theme, 'assets/css');
const governancePath = path.join(theme, 'inc/nvx-native-style-governance.php');
const legacyDist = path.join(theme, 'dist');

const governance = await fs.readFile(governancePath, 'utf8');
if (/dist\/manifest\.json|nvx-critical-inline|nvx_theme_get_compiled_critical_css_bundle/.test(governance)) {
  throw new Error('CSS runtime still references the retired generated distribution');
}
if (!/function\s+nvx_theme_public_delivers_inline_styles\s*\(\s*\)\s*:\s*bool\s*\{\s*return\s+false\s*;\s*\}/s.test(governance)) {
  throw new Error('CSS runtime must use linked static delivery');
}

try {
  const stat = await fs.stat(legacyDist);
  if (stat.isDirectory()) throw new Error(`Legacy generated CSS directory must be absent: ${legacyDist}`);
} catch (error) {
  if (error?.code !== 'ENOENT') throw error;
}

const inventoryMatch = governance.match(/function\s+nvx_theme_critical_stylesheet_files\s*\([^)]*\)\s*:\s*array\s*\{([\s\S]*?)\n\}/);
if (!inventoryMatch) throw new Error('Canonical CSS source inventory is missing');
const declared = [...inventoryMatch[1].matchAll(/['"](assets\/css\/[^'"]+\.css)['"]/g)]
  .map((match) => match[1])
  .sort();
const declaredSet = new Set(declared);
if (declaredSet.size !== declared.length) throw new Error('Canonical CSS inventory contains duplicates');

const actual = (await fs.readdir(cssDir, { withFileTypes: true }))
  .filter((entry) => entry.isFile() && entry.name.endsWith('.css'))
  .map((entry) => `assets/css/${entry.name}`)
  .sort();
if (JSON.stringify(declared) !== JSON.stringify(actual)) {
  const missing = actual.filter((file) => !declaredSet.has(file));
  const stale = declared.filter((file) => !actual.includes(file));
  throw new Error(`CSS source inventory mismatch missing=[${missing.join(',')}] stale=[${stale.join(',')}]`);
}

const fingerprint = crypto.createHash('sha256');
let bytes = 0;
for (const relative of actual) {
  const content = await fs.readFile(path.join(theme, relative));
  if (content.length === 0) throw new Error(`Empty CSS source: ${relative}`);
  bytes += content.length;
  fingerprint.update(relative).update('\0').update(content).update('\0');
}

console.log(
  `CSS_RELEASE_VERIFICATION=PASS sources=${actual.length} bytes=${bytes}`
  + ` fingerprint=${fingerprint.digest('hex')}`
  + ' delivery=linked artifact_owner=git_exact_sha legacy_dist=absent source_coverage=complete'
);

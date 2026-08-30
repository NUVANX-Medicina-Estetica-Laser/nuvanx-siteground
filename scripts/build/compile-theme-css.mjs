#!/usr/bin/env node
/**
 * Deterministic CSS compiler and manifest generator for nuvanx-medical theme.
 *
 * Compiles modular source CSS into immutable hashed distribution artifacts.
 * Runtime consumes one aggregate core bundle plus one hashed file for each
 * route-local stylesheet. A source that belongs to core is never emitted again
 * as an individual dist artifact; dist/ represents runtime consumption, not
 * source history or alternate representations.
 *
 * @package nuvanx-siteground
 */

import fs from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT_DIR = path.join(__dirname, '../..');
const THEME_DIR = path.join(ROOT_DIR, 'wp-content/themes/nuvanx-medical');
const CSS_SRC_DIR = path.join(THEME_DIR, 'assets/css');
const DIST_DIR = path.join(THEME_DIR, 'dist');

const BUNDLE_DEFINITIONS = {
  core: [
    'assets/css/nvx-fonts.css',
    'assets/css/nvx-tokens.css',
    'assets/css/nvx-base.css',
    'assets/css/nvx-site-layout.css',
    'assets/css/nvx-components.css',
    'assets/css/nvx-patterns-editorial.css',
    'assets/css/nvx-treatment-authority.css',
    'assets/css/nvx-header.css',
    'assets/css/nvx-footer.css',
    'assets/css/nvx-accessibility-governance.css',
  ],
};

/** Compute 10-char sha256 hash for content. */
function computeHash(content) {
  return crypto.createHash('sha256').update(content, 'utf8').digest('hex').slice(0, 10);
}

/** Clean and normalize CSS content deterministically. */
function normalizeCss(raw) {
  return raw
    .replace(/\r\n/g, '\n')
    .replace(/\r/g, '\n')
    .trim();
}

async function main() {
  await fs.mkdir(DIST_DIR, { recursive: true });

  // dist/ is generated state, not an archive. Remove every prior CSS artifact
  // and manifest before rebuilding the exact current runtime graph.
  const existingFiles = await fs.readdir(DIST_DIR);
  for (const file of existingFiles) {
    if (file.endsWith('.css') || file === 'manifest.json') {
      await fs.unlink(path.join(DIST_DIR, file));
    }
  }

  const sourceDateEpoch = Number(process.env.SOURCE_DATE_EPOCH || 0);
  const manifest = {
    schema: 1,
    generated: new Date(sourceDateEpoch * 1000).toISOString(),
    bundles: {},
    files: {},
  };

  const bundledSources = new Set(Object.values(BUNDLE_DEFINITIONS).flat());

  // Only route-local sources that are not already represented inside an
  // aggregate bundle get their own immutable dist artifact.
  const srcFiles = await fs.readdir(CSS_SRC_DIR);
  for (const srcFile of srcFiles) {
    if (!srcFile.endsWith('.css')) continue;
    const relSrc = `assets/css/${srcFile}`;
    if (bundledSources.has(relSrc)) continue;

    const fullSrc = path.join(CSS_SRC_DIR, srcFile);
    const content = normalizeCss(await fs.readFile(fullSrc, 'utf8'));
    const hash = computeHash(content);
    const baseName = srcFile.replace(/\.css$/, '');
    const distFileName = `${baseName}.${hash}.css`;
    const distPath = path.join(DIST_DIR, distFileName);

    await fs.writeFile(distPath, content + '\n', 'utf8');
    manifest.files[relSrc] = {
      file: distFileName,
      hash,
      size: Buffer.byteLength(content, 'utf8'),
    };
  }

  // Only true multi-source aggregate bundles belong here.
  for (const [bundleName, sourceList] of Object.entries(BUNDLE_DEFINITIONS)) {
    if (sourceList.length < 2) {
      throw new Error(`Bundle ${bundleName} must aggregate at least two sources`);
    }

    const parts = [];
    for (const relSrc of sourceList) {
      const fullSrc = path.join(THEME_DIR, relSrc);
      const content = normalizeCss(await fs.readFile(fullSrc, 'utf8'));
      parts.push(`/* ${path.basename(relSrc)} */\n${content}`);
    }

    const bundleContent = parts.join('\n\n');
    const hash = computeHash(bundleContent);
    const distFileName = `nvx-${bundleName}.${hash}.css`;
    const distPath = path.join(DIST_DIR, distFileName);

    await fs.writeFile(distPath, bundleContent + '\n', 'utf8');

    manifest.bundles[bundleName] = {
      file: distFileName,
      hash,
      size: Buffer.byteLength(bundleContent, 'utf8'),
      sources: sourceList,
    };
  }

  const manifestPath = path.join(DIST_DIR, 'manifest.json');
  await fs.writeFile(manifestPath, JSON.stringify(manifest, null, 2) + '\n', 'utf8');

  console.log(
    `CSS_COMPILATION=PASS bundles=${Object.keys(manifest.bundles).length} ` +
    `route_files=${Object.keys(manifest.files).length} dist=${path.relative(ROOT_DIR, DIST_DIR)}`
  );
}

main().catch((err) => {
  console.error('CSS_COMPILATION=FAIL', err);
  process.exit(1);
});

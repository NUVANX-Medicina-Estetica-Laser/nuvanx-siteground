#!/usr/bin/env node
/**
 * Test contract for compiled CSS manifest and deterministic build integrity.
 *
 * Validates:
 * 1. dist/manifest.json exists and conforms to schema 1.
 * 2. `core` is the only aggregate bundle; single-source bundles are forbidden.
 * 3. Every canonical source CSS file is represented exactly once in manifest.files.
 * 4. All generated files exist with matching content hashes.
 * 5. Each aggregate bundle is reconstructible byte-for-byte from its sources.
 * 6. No orphan or historical CSS artifact exists in dist/.
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
const MANIFEST_PATH = path.join(DIST_DIR, 'manifest.json');

function computeHash(content) {
  return crypto.createHash('sha256').update(content, 'utf8').digest('hex').slice(0, 10);
}

function normalizeCss(raw) {
  return raw
    .replace(/\r\n/g, '\n')
    .replace(/\r/g, '\n')
    .trim();
}

async function testManifestContract() {
  const manifestRaw = await fs.readFile(MANIFEST_PATH, 'utf8');
  const manifest = JSON.parse(manifestRaw);

  if (manifest.schema !== 1) {
    throw new Error(`Invalid manifest schema: ${manifest.schema}`);
  }

  const bundles = manifest.bundles;
  const files = manifest.files;
  if (!bundles || !bundles.core || !bundles.core.file) {
    throw new Error('Manifest missing core bundle');
  }
  if (!files || typeof files !== 'object') {
    throw new Error('Manifest missing files map');
  }

  const bundleNames = Object.keys(bundles).sort();
  if (bundleNames.length !== 1 || bundleNames[0] !== 'core') {
    throw new Error(
      `Only the multi-source core bundle is allowed; found: ${bundleNames.join(', ') || '(none)'}`
    );
  }

  const sourceCssFiles = (await fs.readdir(CSS_SRC_DIR))
    .filter((file) => file.endsWith('.css'))
    .map((file) => `assets/css/${file}`)
    .sort();
  const manifestSourceFiles = Object.keys(files).sort();

  if (JSON.stringify(sourceCssFiles) !== JSON.stringify(manifestSourceFiles)) {
    const missing = sourceCssFiles.filter((file) => !manifestSourceFiles.includes(file));
    const stale = manifestSourceFiles.filter((file) => !sourceCssFiles.includes(file));
    throw new Error(
      `Manifest/source CSS mismatch: missing=[${missing.join(', ')}] stale=[${stale.join(', ')}]`
    );
  }

  const referencedFiles = new Set();

  for (const [name, info] of Object.entries(bundles)) {
    if (!Array.isArray(info.sources) || info.sources.length < 2) {
      throw new Error(`Bundle ${name} must aggregate at least two source files`);
    }

    if (referencedFiles.has(info.file)) {
      throw new Error(`Generated CSS artifact referenced more than once: ${info.file}`);
    }
    referencedFiles.add(info.file);

    const bundleFilePath = path.join(DIST_DIR, info.file);
    const distContent = normalizeCss(await fs.readFile(bundleFilePath, 'utf8'));
    const actualHash = computeHash(distContent);

    if (actualHash !== info.hash) {
      throw new Error(`Bundle ${name} hash mismatch: manifest=${info.hash} actual=${actualHash}`);
    }

    if (!info.file.includes(info.hash)) {
      throw new Error(`Bundle ${name} filename ${info.file} does not contain hash ${info.hash}`);
    }

    const parts = [];
    for (const relSrc of info.sources) {
      const fullSrc = path.join(THEME_DIR, relSrc);
      const srcContent = normalizeCss(await fs.readFile(fullSrc, 'utf8'));
      parts.push(`/* ${path.basename(relSrc)} */\n${srcContent}`);
    }

    const reconstructed = parts.join('\n\n');
    const reconstructedHash = computeHash(reconstructed);
    const reconstructedSize = Buffer.byteLength(reconstructed, 'utf8');

    if (reconstructedHash !== info.hash) {
      throw new Error(
        `Bundle ${name} source reconstruction hash mismatch: ` +
        `manifest=${info.hash} reconstructed=${reconstructedHash}`
      );
    }

    if (reconstructedSize !== info.size) {
      throw new Error(
        `Bundle ${name} source reconstruction size mismatch: ` +
        `manifest=${info.size} reconstructed=${reconstructedSize}`
      );
    }

    if (reconstructed !== distContent) {
      throw new Error(
        `Bundle ${name} source reconstruction content mismatch: ` +
        'dist file does not equal concatenation of declared sources'
      );
    }
  }

  for (const [relPath, info] of Object.entries(files)) {
    if (referencedFiles.has(info.file)) {
      throw new Error(`Generated CSS artifact referenced more than once: ${info.file}`);
    }
    referencedFiles.add(info.file);

    const distFilePath = path.join(DIST_DIR, info.file);
    const content = normalizeCss(await fs.readFile(distFilePath, 'utf8'));
    const actualHash = computeHash(content);

    if (actualHash !== info.hash) {
      throw new Error(`File ${relPath} hash mismatch: manifest=${info.hash} actual=${actualHash}`);
    }

    const srcFilePath = path.join(THEME_DIR, relPath);
    const srcContent = normalizeCss(await fs.readFile(srcFilePath, 'utf8'));
    const srcHash = computeHash(srcContent);

    if (srcHash !== info.hash) {
      throw new Error(`Source ${relPath} hash mismatch with dist: src=${srcHash} dist=${info.hash}`);
    }
  }

  const distFiles = (await fs.readdir(DIST_DIR)).filter((file) => file.endsWith('.css')).sort();
  const expectedDistFiles = [...referencedFiles].sort();
  const orphans = distFiles.filter((file) => !referencedFiles.has(file));
  const missingDist = expectedDistFiles.filter((file) => !distFiles.includes(file));

  if (orphans.length > 0 || missingDist.length > 0) {
    throw new Error(
      `Generated CSS set mismatch: orphan=[${orphans.join(', ')}] missing=[${missingDist.join(', ')}]`
    );
  }

  console.log(
    `CSS_MANIFEST_CONTRACT=PASS bundles=${bundleNames.length} ` +
    `files=${manifestSourceFiles.length} bundle_reconstruction=verified ` +
    'hash_integrity=verified duplicate_check=clean orphan_check=clean source_coverage=complete'
  );
}

testManifestContract().catch((err) => {
  console.error('CSS_MANIFEST_CONTRACT=FAIL', err);
  process.exit(1);
});

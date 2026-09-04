#!/usr/bin/env node
/**
 * Deterministic CSS distribution contract.
 *
 * Every source stylesheet must have exactly one runtime representation:
 * either it belongs to the multi-source `core` bundle, or it is emitted as one
 * route-local immutable file. No source may appear in both, no single-source
 * bundles are allowed, and dist/ may not contain orphan/historical CSS.
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
  return raw.replace(/\r\n/g, '\n').replace(/\r/g, '\n').trim();
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
    throw new Error('Manifest missing route files map');
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

  const coreSources = bundles.core.sources;
  if (!Array.isArray(coreSources) || coreSources.length < 2) {
    throw new Error('Core bundle must aggregate at least two source files');
  }

  const coreSet = new Set(coreSources);
  if (coreSet.size !== coreSources.length) {
    throw new Error('Core bundle contains duplicate source entries');
  }

  const routeSources = Object.keys(files).sort();
  const overlappingSources = routeSources.filter((file) => coreSet.has(file));
  if (overlappingSources.length > 0) {
    throw new Error(
      `CSS sources have duplicate runtime representations: ${overlappingSources.join(', ')}`
    );
  }

  const representedSources = [...coreSources, ...routeSources].sort();
  if (JSON.stringify(sourceCssFiles) !== JSON.stringify(representedSources)) {
    const missing = sourceCssFiles.filter((file) => !representedSources.includes(file));
    const stale = representedSources.filter((file) => !sourceCssFiles.includes(file));
    throw new Error(
      `Manifest/source CSS mismatch: missing=[${missing.join(', ')}] stale=[${stale.join(', ')}]`
    );
  }

  const referencedArtifacts = new Set();

  for (const [name, info] of Object.entries(bundles)) {
    if (!Array.isArray(info.sources) || info.sources.length < 2) {
      throw new Error(`Bundle ${name} must aggregate at least two source files`);
    }
    if (referencedArtifacts.has(info.file)) {
      throw new Error(`Generated CSS artifact referenced more than once: ${info.file}`);
    }
    referencedArtifacts.add(info.file);

    const bundlePath = path.join(DIST_DIR, info.file);
    const distContent = normalizeCss(await fs.readFile(bundlePath, 'utf8'));
    const actualHash = computeHash(distContent);
    if (actualHash !== info.hash) {
      throw new Error(`Bundle ${name} hash mismatch: manifest=${info.hash} actual=${actualHash}`);
    }
    if (!info.file.includes(info.hash)) {
      throw new Error(`Bundle ${name} filename ${info.file} does not contain hash ${info.hash}`);
    }

    const parts = [];
    for (const relSrc of info.sources) {
      const srcContent = normalizeCss(await fs.readFile(path.join(THEME_DIR, relSrc), 'utf8'));
      parts.push(`/* ${path.basename(relSrc)} */\n${srcContent}`);
    }
    const reconstructed = parts.join('\n\n');
    if (computeHash(reconstructed) !== info.hash) {
      throw new Error(`Bundle ${name} source reconstruction hash mismatch`);
    }
    if (Buffer.byteLength(reconstructed, 'utf8') !== info.size) {
      throw new Error(`Bundle ${name} source reconstruction size mismatch`);
    }
    if (reconstructed !== distContent) {
      throw new Error(`Bundle ${name} is not byte-exact reconstruction of declared sources`);
    }
  }

  for (const [relPath, info] of Object.entries(files)) {
    if (referencedArtifacts.has(info.file)) {
      throw new Error(`Generated CSS artifact referenced more than once: ${info.file}`);
    }
    referencedArtifacts.add(info.file);

    const distContent = normalizeCss(await fs.readFile(path.join(DIST_DIR, info.file), 'utf8'));
    const srcContent = normalizeCss(await fs.readFile(path.join(THEME_DIR, relPath), 'utf8'));
    const actualHash = computeHash(distContent);
    const sourceHash = computeHash(srcContent);

    if (actualHash !== info.hash || sourceHash !== info.hash) {
      throw new Error(
        `Route CSS hash mismatch ${relPath}: manifest=${info.hash} source=${sourceHash} dist=${actualHash}`
      );
    }
    if (srcContent !== distContent) {
      throw new Error(`Route CSS ${relPath} differs from its immutable dist artifact`);
    }
  }

  const distFiles = (await fs.readdir(DIST_DIR)).filter((file) => file.endsWith('.css')).sort();
  const expectedDistFiles = [...referencedArtifacts].sort();
  const orphans = distFiles.filter((file) => !referencedArtifacts.has(file));
  const missingDist = expectedDistFiles.filter((file) => !distFiles.includes(file));

  if (orphans.length > 0 || missingDist.length > 0) {
    throw new Error(
      `Generated CSS set mismatch: orphan=[${orphans.join(', ')}] missing=[${missingDist.join(', ')}]`
    );
  }

  console.log(
    `CSS_MANIFEST_CONTRACT=PASS bundles=${bundleNames.length} route_files=${routeSources.length} ` +
    `sources=${sourceCssFiles.length} runtime_artifacts=${expectedDistFiles.length} ` +
    'single_representation=verified hash_integrity=verified orphan_check=clean source_coverage=complete'
  );
}

testManifestContract().catch((err) => {
  console.error('CSS_MANIFEST_CONTRACT=FAIL', err);
  process.exit(1);
});

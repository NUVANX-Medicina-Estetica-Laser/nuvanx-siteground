#!/usr/bin/env node
/**
 * CSS ownership and regression contract.
 *
 * Enforces:
 * - no permanent <style> blocks or CSS heredocs in theme PHP;
 * - wp_add_inline_style() only in the canonical compiled-bundle transport or
 *   explicitly classified runtime dynamic-style owners;
 * - no duplicate global @keyframes names across source stylesheets;
 * - every !important declaration is explicitly classified as an approved
 *   accessibility, third-party containment, or print-policy exception;
 * - no hard-coded immutable dist CSS filenames outside dist/manifest.json.
 */

import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT_DIR = path.join(__dirname, '../..');
const THEME_DIR = path.join(ROOT_DIR, 'wp-content/themes/nuvanx-medical');
const CSS_DIR = path.join(THEME_DIR, 'assets/css');
const CANONICAL_INLINE_OWNER = path.join(THEME_DIR, 'inc/nvx-native-style-governance.php');
const RUNTIME_INLINE_STYLE_OWNERS = new Map([
  // Dynamic featured-image URL only. Permanent CSS remains source/dist-owned.
  [path.resolve(THEME_DIR, 'inc/nvx-hero-and-forms.php'), 1],
]);
const IGNORED_DIRECTORIES = new Set(['.git', 'node_modules', 'vendor', 'dist']);

async function walk(dir) {
  const entries = await fs.readdir(dir, { withFileTypes: true });
  const files = [];
  for (const entry of entries) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      if (IGNORED_DIRECTORIES.has(entry.name)) continue;
      files.push(...await walk(full));
    } else {
      files.push(full);
    }
  }
  return files;
}

function rel(file) {
  return path.relative(ROOT_DIR, file).replaceAll(path.sep, '/');
}

function lineNumber(content, offset) {
  return content.slice(0, offset).split('\n').length;
}

/**
 * Mask PHP block/line comments without changing source length or line numbers.
 * Quoted strings are preserved because emitted <style> or CSS strings are part
 * of the ownership surface and must remain visible to the scanner.
 */
function maskPhpComments(content) {
  const chars = [...content];
  const out = [...content];
  let state = 'code';

  const blank = (index) => {
    if (chars[index] !== '\n' && chars[index] !== '\r') out[index] = ' ';
  };

  for (let i = 0; i < chars.length; i += 1) {
    const char = chars[i];
    const next = chars[i + 1] ?? '';

    if (state === 'single') {
      if (char === '\\') {
        i += 1;
      } else if (char === "'") {
        state = 'code';
      }
      continue;
    }

    if (state === 'double') {
      if (char === '\\') {
        i += 1;
      } else if (char === '"') {
        state = 'code';
      }
      continue;
    }

    if (state === 'block-comment') {
      blank(i);
      if (char === '*' && next === '/') {
        blank(i + 1);
        i += 1;
        state = 'code';
      }
      continue;
    }

    if (state === 'line-comment') {
      if (char === '\n' || char === '\r') {
        state = 'code';
      } else {
        blank(i);
      }
      continue;
    }

    if (char === "'") {
      state = 'single';
    } else if (char === '"') {
      state = 'double';
    } else if (char === '/' && next === '*') {
      blank(i);
      blank(i + 1);
      i += 1;
      state = 'block-comment';
    } else if (char === '/' && next === '/') {
      blank(i);
      blank(i + 1);
      i += 1;
      state = 'line-comment';
    } else if (char === '#') {
      blank(i);
      state = 'line-comment';
    }
  }

  return out.join('');
}

/** Mask CSS block comments while preserving offsets/newlines. */
function maskCssComments(content) {
  return content.replace(/\/\*[\s\S]*?\*\//g, (comment) =>
    comment.replace(/[^\r\n]/g, ' ')
  );
}

async function main() {
  const violations = [];
  const themeFiles = await walk(THEME_DIR);
  const phpFiles = themeFiles.filter((file) => file.endsWith('.php'));

  for (const file of phpFiles) {
    const content = await fs.readFile(file, 'utf8');
    const scannableContent = maskPhpComments(content);

    for (const match of scannableContent.matchAll(/<style\b/giu)) {
      violations.push(`${rel(file)}:${lineNumber(scannableContent, match.index)} permanent <style> block`);
    }

    for (const match of scannableContent.matchAll(/<<<\s*['"]?CSS['"]?/g)) {
      violations.push(`${rel(file)}:${lineNumber(scannableContent, match.index)} CSS heredoc/nowdoc`);
    }

    const inlineCalls = [...scannableContent.matchAll(/\bwp_add_inline_style\s*\(/g)];
    const resolvedFile = path.resolve(file);
    if (resolvedFile !== path.resolve(CANONICAL_INLINE_OWNER)) {
      const permittedRuntimeCalls = RUNTIME_INLINE_STYLE_OWNERS.get(resolvedFile) ?? 0;
      if (inlineCalls.length > permittedRuntimeCalls) {
        for (const match of inlineCalls.slice(permittedRuntimeCalls)) {
          violations.push(`${rel(file)}:${lineNumber(scannableContent, match.index)} non-canonical wp_add_inline_style()`);
        }
      }
    }
  }

  const canonicalInlineContent = await fs.readFile(CANONICAL_INLINE_OWNER, 'utf8');
  const canonicalScannableContent = maskPhpComments(canonicalInlineContent);
  const canonicalInlineCalls = [...canonicalScannableContent.matchAll(/\bwp_add_inline_style\s*\(/g)];
  if (canonicalInlineCalls.length !== 1) {
    violations.push(
      `${rel(CANONICAL_INLINE_OWNER)} must contain exactly one wp_add_inline_style() transport call; found ${canonicalInlineCalls.length}`
    );
  }

  const cssFiles = (await fs.readdir(CSS_DIR))
    .filter((name) => name.endsWith('.css'))
    .map((name) => path.join(CSS_DIR, name))
    .sort();

  const keyframeOwners = new Map();
  for (const file of cssFiles) {
    const content = await fs.readFile(file, 'utf8');
    const scannableContent = maskCssComments(content);

    for (const match of scannableContent.matchAll(/@(?:-webkit-)?keyframes\s+([A-Za-z0-9_-]+)/g)) {
      const name = match[1];
      const owners = keyframeOwners.get(name) ?? [];
      owners.push(`${rel(file)}:${lineNumber(scannableContent, match.index)}`);
      keyframeOwners.set(name, owners);
    }

    const sourceLines = content.split('\n');
    const scannableLines = scannableContent.split('\n');
    scannableLines.forEach((codeLine, index) => {
      if (!codeLine.includes('!important')) return;
      const sourceLine = sourceLines[index] ?? '';
      const hasMarker = sourceLine.includes('nvx-token-exception:');
      const hasApprovedClass = /reduced-motion|THIRD_PARTY_CONTAINMENT|PRINT_POLICY/i.test(sourceLine);
      if (!hasMarker || !hasApprovedClass) {
        violations.push(`${rel(file)}:${index + 1} unclassified !important: ${sourceLine.trim()}`);
      }
    });
  }

  for (const [name, owners] of keyframeOwners.entries()) {
    if (owners.length > 1) {
      violations.push(`duplicate @keyframes ${name}: ${owners.join(', ')}`);
    }
  }

  const repoFiles = await walk(ROOT_DIR);
  const immutableCssRef = /\bnvx-[A-Za-z0-9_-]+\.[0-9a-f]{10}\.css\b/g;
  for (const file of repoFiles) {
    const relative = rel(file);
    if (!/\.(?:php|js|mjs|json|md|ya?ml|sh)$/i.test(file)) continue;

    let content;
    try {
      content = await fs.readFile(file, 'utf8');
    } catch {
      continue;
    }

    for (const match of content.matchAll(immutableCssRef)) {
      violations.push(`${relative}:${lineNumber(content, match.index)} hard-coded dist CSS artifact ${match[0]}`);
    }
  }

  if (violations.length > 0) {
    console.error('CSS_OWNERSHIP_CONTRACT=FAIL');
    for (const violation of violations) console.error(` - ${violation}`);
    process.exit(1);
  }

  console.log(
    `CSS_OWNERSHIP_CONTRACT=PASS css_sources=${cssFiles.length} ` +
    `keyframes=${keyframeOwners.size} inline_owner=canonical important_policy=classified`
  );
}

main().catch((err) => {
  console.error('CSS_OWNERSHIP_CONTRACT=FAIL', err);
  process.exit(1);
});

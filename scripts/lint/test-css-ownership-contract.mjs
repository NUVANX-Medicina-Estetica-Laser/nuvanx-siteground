#!/usr/bin/env node
/**
 * CSS ownership and regression contract.
 *
 * Static presentation belongs to assets/css and must be browser-cacheable.
 * PHP may expose only bounded runtime values through an explicitly approved
 * wp_add_inline_style() owner. !important has an exact, minimal exception
 * budget for third-party containment, reduced-motion and print policy.
 */

import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT_DIR = path.join(__dirname, '../..');
const THEME_DIR = path.join(ROOT_DIR, 'wp-content/themes/nuvanx-medical');
const CSS_DIR = path.join(THEME_DIR, 'assets/css');
const NATIVE_OWNER = path.join(THEME_DIR, 'inc/nvx-native-style-governance.php');
const RUNTIME_INLINE_STYLE_OWNERS = new Map([
  [path.resolve(THEME_DIR, 'inc/nvx-hero-and-forms.php'), 1],
]);
const IGNORED_DIRECTORIES = new Set(['.git', 'node_modules', 'vendor', 'dist']);

const IMPORTANT_ALLOWLIST = new Map([
  ['wp-content/themes/nuvanx-medical/assets/css/nvx-base.css', new Map([
    ['display: none !important; /* nvx-token-exception: PRINT_POLICY — interactive chrome must not print */', 1],
  ])],
  ['wp-content/themes/nuvanx-medical/assets/css/nvx-accessibility-governance.css', new Map([
    ['min-height: var(--nvx-touch-target-min, 48px) !important; /* nvx-token-exception: THIRD_PARTY_CONTAINMENT — Complianz late CSS */', 1],
    ['animation-duration: 0.01ms !important; /* nvx-token-exception: reduced-motion override */', 1],
    ['animation-iteration-count: 1 !important; /* nvx-token-exception: reduced-motion override */', 1],
    ['transition-duration: 0.01ms !important; /* nvx-token-exception: reduced-motion override */', 1],
    ['scroll-behavior: auto !important; /* nvx-token-exception: reduced-motion override */', 1],
    ['animation: none !important; /* nvx-token-exception: reduced-motion override — drawer must not traverse the viewport */', 1],
    ['display: none !important; /* nvx-token-exception: reduced-motion override — autoplay media is removed entirely */', 1],
    ['transform: none !important; /* nvx-token-exception: reduced-motion override — route bundles may load after core */', 1],
  ])],
]);

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
      if (char === '\\') i += 1;
      else if (char === "'") state = 'code';
      continue;
    }
    if (state === 'double') {
      if (char === '\\') i += 1;
      else if (char === '"') state = 'code';
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
      if (char === '\n' || char === '\r') state = 'code';
      else blank(i);
      continue;
    }
    if (char === "'") state = 'single';
    else if (char === '"') state = 'double';
    else if (char === '/' && next === '*') {
      blank(i); blank(i + 1); i += 1; state = 'block-comment';
    } else if (char === '/' && next === '/') {
      blank(i); blank(i + 1); i += 1; state = 'line-comment';
    } else if (char === '#') {
      blank(i); state = 'line-comment';
    }
  }
  return out.join('');
}

function maskCssComments(content) {
  return content.replace(/\/\*[\s\S]*?\*\//g, (comment) => comment.replace(/[^\r\n]/g, ' '));
}

async function main() {
  const violations = [];
  const themeFiles = await walk(THEME_DIR);
  const phpFiles = themeFiles.filter((file) => file.endsWith('.php'));

  for (const file of phpFiles) {
    const content = await fs.readFile(file, 'utf8');
    const scannable = maskPhpComments(content);
    for (const match of scannable.matchAll(/<style\b/giu)) {
      violations.push(`${rel(file)}:${lineNumber(scannable, match.index)} permanent <style> block`);
    }
    for (const match of scannable.matchAll(/<<<\s*['"]?CSS['"]?/g)) {
      violations.push(`${rel(file)}:${lineNumber(scannable, match.index)} CSS heredoc/nowdoc`);
    }

    const calls = [...scannable.matchAll(/\bwp_add_inline_style\s*\(/g)];
    const permitted = RUNTIME_INLINE_STYLE_OWNERS.get(path.resolve(file)) ?? 0;
    if (calls.length !== permitted) {
      violations.push(`${rel(file)} wp_add_inline_style count=${calls.length} expected=${permitted}`);
    }
  }

  const native = await fs.readFile(NATIVE_OWNER, 'utf8');
  const forbiddenNative = [
    'dist/manifest.json',
    "'/dist/'",
    'nvx-critical-inline',
    'nvx_theme_get_css_manifest',
    'nvx_theme_get_compiled_critical_css_bundle',
    'nvx_theme_inline_critical_style_foundation',
    'nvx_theme_drop_inlined_file_links',
    'file_get_contents(',
  ];
  for (const needle of forbiddenNative) {
    if (native.includes(needle)) violations.push(`${rel(NATIVE_OWNER)} forbidden static-inline runtime=${needle}`);
  }
  if (!/function\s+nvx_theme_public_delivers_inline_styles\s*\(\s*\)\s*:\s*bool\s*\{\s*return\s+false\s*;\s*\}/s.test(native)) {
    violations.push(`${rel(NATIVE_OWNER)} must fail closed to linked static CSS`);
  }

  const cssFiles = (await fs.readdir(CSS_DIR))
    .filter((name) => name.endsWith('.css'))
    .map((name) => path.join(CSS_DIR, name))
    .sort();

  const keyframeOwners = new Map();
  const importantSeen = new Map();
  for (const file of cssFiles) {
    const content = await fs.readFile(file, 'utf8');
    const scannable = maskCssComments(content);
    for (const match of scannable.matchAll(/@(?:-webkit-)?keyframes\s+([A-Za-z0-9_-]+)/g)) {
      const owners = keyframeOwners.get(match[1]) ?? [];
      owners.push(`${rel(file)}:${lineNumber(scannable, match.index)}`);
      keyframeOwners.set(match[1], owners);
    }

    const allowed = IMPORTANT_ALLOWLIST.get(rel(file)) ?? new Map();
    const sourceLines = content.split('\n');
    const codeLines = scannable.split('\n');
    codeLines.forEach((codeLine, index) => {
      if (!codeLine.includes('!important')) return;
      const sourceLine = (sourceLines[index] ?? '').trim();
      if (!allowed.has(sourceLine)) {
        violations.push(`${rel(file)}:${index + 1} unapproved !important: ${sourceLine}`);
        return;
      }
      const key = `${rel(file)}\n${sourceLine}`;
      importantSeen.set(key, (importantSeen.get(key) ?? 0) + 1);
    });
  }

  for (const [file, rules] of IMPORTANT_ALLOWLIST) {
    for (const [rule, expected] of rules) {
      const actual = importantSeen.get(`${file}\n${rule}`) ?? 0;
      if (actual !== expected) violations.push(`${file} important budget mismatch expected=${expected} actual=${actual} rule=${rule}`);
    }
  }

  for (const [name, owners] of keyframeOwners.entries()) {
    if (owners.length > 1) violations.push(`duplicate @keyframes ${name}: ${owners.join(', ')}`);
  }

  const repoFiles = await walk(ROOT_DIR);
  const immutableCssRef = /\bnvx-[A-Za-z0-9_-]+\.[0-9a-f]{10}\.css\b/g;
  for (const file of repoFiles) {
    if (!/\.(?:php|js|mjs|json|md|ya?ml|sh)$/i.test(file)) continue;
    let content;
    try { content = await fs.readFile(file, 'utf8'); } catch { continue; }
    for (const match of content.matchAll(immutableCssRef)) {
      violations.push(`${rel(file)}:${lineNumber(content, match.index)} hard-coded dist CSS artifact ${match[0]}`);
    }
  }

  if (violations.length > 0) {
    console.error('CSS_OWNERSHIP_CONTRACT=FAIL');
    for (const violation of violations) console.error(` - ${violation}`);
    process.exit(1);
  }

  const importantBudget = [...IMPORTANT_ALLOWLIST.values()]
    .reduce((sum, rules) => sum + [...rules.values()].reduce((a, b) => a + b, 0), 0);
  console.log(
    `CSS_OWNERSHIP_CONTRACT=PASS css_sources=${cssFiles.length} keyframes=${keyframeOwners.size} ` +
    `static_inline=0 dynamic_inline_owners=${RUNTIME_INLINE_STYLE_OWNERS.size} important_budget=${importantBudget}`
  );
}

main().catch((err) => {
  console.error('CSS_OWNERSHIP_CONTRACT=FAIL', err);
  process.exit(1);
});

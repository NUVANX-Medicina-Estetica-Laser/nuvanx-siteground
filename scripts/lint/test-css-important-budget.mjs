#!/usr/bin/env node
/**
 * Exact exception budget for CSS !important declarations.
 *
 * Covers every repository-owned stylesheet shipped by the theme, including
 * style.css and nested CSS source directories. !important is allowed only
 * where the cascade must intentionally beat third-party, reduced-motion or
 * print-policy CSS. New exceptions require explicit registry review.
 */

import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.join(__dirname, '../..');
const THEME_ROOT = path.join(ROOT, 'wp-content/themes/nuvanx-medical');
const IGNORED_DIRS = new Set(['dist', 'node_modules', 'vendor', '.git']);

const ALLOWLIST = new Map([
  ['assets/css/nvx-base.css', new Map([
    ['display: none !important; /* nvx-token-exception: PRINT_POLICY — interactive chrome must not print */', 1],
  ])],
  ['assets/css/nvx-accessibility-governance.css', new Map([
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

function maskComments(source) {
  return source.replace(/\/\*[\s\S]*?\*\//g, (comment) => comment.replace(/[^\r\n]/g, ' '));
}

async function walkCss(dir) {
  const result = [];
  for (const entry of await fs.readdir(dir, { withFileTypes: true })) {
    if (entry.isDirectory() && IGNORED_DIRS.has(entry.name)) continue;
    const absolute = path.join(dir, entry.name);
    if (entry.isDirectory()) result.push(...await walkCss(absolute));
    else if (entry.isFile() && entry.name.endsWith('.css')) result.push(absolute);
  }
  return result;
}

function importantDeclarations(source, relativePath) {
  const declarations = [];
  const codeLines = maskComments(source).split('\n');
  const sourceLines = source.split('\n');
  codeLines.forEach((codeLine, index) => {
    if (!codeLine.includes('!important')) return;
    declarations.push({
      path: relativePath,
      line: index + 1,
      literal: (sourceLines[index] ?? '').trim(),
    });
  });
  return declarations;
}

// Contract fixtures: comments never count; style.css and nested styles are
// treated exactly like assets/css sources and therefore cannot bypass budget.
if (importantDeclarations('/* color:red !important; */\nbody { color: black; }', 'style.css').length !== 0) {
  throw new Error('CSS_IMPORTANT_BUDGET_FIXTURE=FAIL comments');
}
if (importantDeclarations('body { color:red !important; }', 'style.css').length !== 1) {
  throw new Error('CSS_IMPORTANT_BUDGET_FIXTURE=FAIL style_css');
}
if (importantDeclarations('.x { display:none !important; }', 'nested/deep.css').length !== 1) {
  throw new Error('CSS_IMPORTANT_BUDGET_FIXTURE=FAIL nested_css');
}

const failures = [];
const seen = new Map();
const files = (await walkCss(THEME_ROOT)).sort();

for (const absolute of files) {
  const relative = path.relative(THEME_ROOT, absolute).split(path.sep).join('/');
  const source = await fs.readFile(absolute, 'utf8');
  const allowed = ALLOWLIST.get(relative) ?? new Map();
  for (const declaration of importantDeclarations(source, relative)) {
    if (!allowed.has(declaration.literal)) {
      failures.push(`${relative}:${declaration.line} unapproved=${declaration.literal}`);
      continue;
    }
    const key = `${relative}\n${declaration.literal}`;
    seen.set(key, (seen.get(key) ?? 0) + 1);
  }
}

let expectedTotal = 0;
for (const [file, rules] of ALLOWLIST) {
  for (const [literal, expected] of rules) {
    expectedTotal += expected;
    const actual = seen.get(`${file}\n${literal}`) ?? 0;
    if (actual !== expected) {
      failures.push(`${file} budget expected=${expected} actual=${actual} rule=${literal}`);
    }
  }
}

const actualTotal = [...seen.values()].reduce((sum, count) => sum + count, 0);
if (actualTotal !== expectedTotal) failures.push(`total expected=${expectedTotal} actual=${actualTotal}`);

if (failures.length > 0) {
  for (const failure of failures) console.error(`CSS_IMPORTANT_BUDGET=FAIL ${failure}`);
  process.exit(1);
}

console.log(`CSS_IMPORTANT_BUDGET=PASS files=${files.length} owners=${ALLOWLIST.size} exceptions=${expectedTotal} fixtures=3`);

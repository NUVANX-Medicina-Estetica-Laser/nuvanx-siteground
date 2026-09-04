#!/usr/bin/env node
/**
 * Exact exception budget for CSS !important declarations.
 *
 * !important is allowed only where the cascade must intentionally beat
 * third-party, reduced-motion or print-policy CSS. New exceptions require an
 * explicit review of this registry rather than a marker-only self-approval.
 */

import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.join(__dirname, '../..');
const CSS_ROOT = path.join(ROOT, 'wp-content/themes/nuvanx-medical/assets/css');

const ALLOWLIST = new Map([
  ['nvx-base.css', new Map([
    ['display: none !important; /* nvx-token-exception: PRINT_POLICY — interactive chrome must not print */', 1],
  ])],
  ['nvx-accessibility-governance.css', new Map([
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

const failures = [];
const seen = new Map();
const files = (await fs.readdir(CSS_ROOT)).filter((name) => name.endsWith('.css')).sort();

for (const file of files) {
  const source = await fs.readFile(path.join(CSS_ROOT, file), 'utf8');
  const codeLines = maskComments(source).split('\n');
  const sourceLines = source.split('\n');
  const allowed = ALLOWLIST.get(file) ?? new Map();

  codeLines.forEach((codeLine, index) => {
    if (!codeLine.includes('!important')) return;
    const literal = (sourceLines[index] ?? '').trim();
    if (!allowed.has(literal)) {
      failures.push(`${file}:${index + 1} unapproved=${literal}`);
      return;
    }
    const key = `${file}\n${literal}`;
    seen.set(key, (seen.get(key) ?? 0) + 1);
  });
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
if (actualTotal !== expectedTotal) {
  failures.push(`total expected=${expectedTotal} actual=${actualTotal}`);
}

if (failures.length > 0) {
  for (const failure of failures) console.error(`CSS_IMPORTANT_BUDGET=FAIL ${failure}`);
  process.exit(1);
}

console.log(`CSS_IMPORTANT_BUDGET=PASS files=${files.length} owners=${ALLOWLIST.size} exceptions=${expectedTotal}`);

#!/usr/bin/env node
/**
 * Report design-system literals that should be reviewed for token adoption.
 *
 * This audit is intentionally report-only by default while the legacy baseline
 * is being classified. Pass --strict only after the governed categories have
 * been migrated or explicitly marked with `nvx-token-exception`.
 */

import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, '../..');
const CSS_DIR = path.join(ROOT, 'wp-content/themes/nuvanx-medical/assets/css');
const TOKEN_FILE = path.join(CSS_DIR, 'nvx-tokens.css');
const BASE_FILE = path.join(CSS_DIR, 'nvx-base.css');
const strict = process.argv.includes('--strict');
const MAX_PRINT = 400;

const STRICT_CATEGORIES = new Set([
  'spacing',
  'motion',
  'radius',
  'shadow',
  'z-index',
  'typography-metric',
]);

const SPACING_PROPERTIES = /^(?:margin(?:-(?:top|right|bottom|left|inline|inline-start|inline-end|block|block-start|block-end))?|padding(?:-(?:top|right|bottom|left|inline|inline-start|inline-end|block|block-start|block-end))?|gap|row-gap|column-gap)$/;
const DIMENSION_PROPERTIES = /^(?:width|height|min-width|max-width|min-height|max-height)$/;
const MOTION_PROPERTIES = /^(?:transition(?:-duration)?|animation-duration)$/;
const TYPOGRAPHY_PROPERTIES = /^(?:line-height|letter-spacing|font-weight)$/;
const POSITION_PROPERTIES = /^(?:top|right|bottom|left|inset|inset-inline|inset-block)$/;

const CANONICAL_PX = new Set([8, 16, 20, 24, 32, 36, 40, 48, 56, 60, 64, 72, 80, 96, 120, 152, 168, 200, 224, 256, 320]);
const CANONICAL_MS = new Set([80, 160, 240, 320, 480]);

function stripCommentsPreserveLines(input) {
  return input.replace(/\/\*[\s\S]*?\*\//g, (comment) => comment.replace(/[^\n]/g, ' '));
}

function lineNumberAt(source, index) {
  let line = 1;
  for (let i = 0; i < index; i += 1) {
    if (source.charCodeAt(i) === 10) line += 1;
  }
  return line;
}

function extractScalarLiterals(value) {
  const literals = [];
  for (const match of value.matchAll(/(?<![\w.-])(-?\d*\.?\d+)(px|rem|em|ms)(?![\w-])/g)) {
    literals.push({ raw: match[0], number: Number(match[1]), unit: match[2] });
  }
  return literals;
}

async function collectTokenDefinitions() {
  const definitions = new Map();
  for (const file of [TOKEN_FILE, BASE_FILE]) {
    const content = stripCommentsPreserveLines(await fs.readFile(file, 'utf8'));
    for (const match of content.matchAll(/(--nvx-[\w-]+)\s*:\s*([^;{}]+);/g)) {
      definitions.set(match[1], match[2].trim());
    }
  }
  return definitions;
}

function tokenCandidatesForLiteral(definitions, literal) {
  const exact = [];
  const contains = [];
  for (const [name, value] of definitions.entries()) {
    if (value === literal) {
      exact.push(name);
    } else if (new RegExp(`(^|[^0-9.])${literal.replace('.', '\\.')}(?![0-9])`).test(value)) {
      contains.push(name);
    }
  }
  return { exact, contains: contains.slice(0, 6) };
}

function classifyDeclaration(property, value, literals) {
  const findings = [];
  const hasNvxVar = /var\(\s*--nvx-/.test(value);

  if (SPACING_PROPERTIES.test(property)) {
    for (const literal of literals) {
      if (literal.unit === 'px' && literal.number !== 0 && CANONICAL_PX.has(Math.abs(literal.number))) {
        findings.push({ category: 'spacing', literal: literal.raw });
      }
    }
  }

  if (DIMENSION_PROPERTIES.test(property)) {
    for (const literal of literals) {
      if (literal.unit === 'px' && literal.number > 0 && CANONICAL_PX.has(literal.number)) {
        findings.push({ category: 'dimension-review', literal: literal.raw });
      }
    }
  }

  if (POSITION_PROPERTIES.test(property)) {
    for (const literal of literals) {
      if (literal.unit === 'px' && literal.number !== 0 && CANONICAL_PX.has(Math.abs(literal.number))) {
        findings.push({ category: 'position-review', literal: literal.raw });
      }
    }
  }

  if (MOTION_PROPERTIES.test(property)) {
    for (const literal of literals) {
      if (literal.unit === 'ms' && literal.number > 0 && CANONICAL_MS.has(literal.number)) {
        findings.push({ category: 'motion', literal: literal.raw });
      }
    }
  }

  if (property === 'border-radius' && !hasNvxVar) {
    for (const literal of literals) {
      if ((literal.unit === 'px' || literal.unit === 'rem') && literal.number > 0) {
        findings.push({ category: 'radius', literal: literal.raw });
      }
    }
  }

  if (property === 'box-shadow' && value.trim() !== 'none' && !hasNvxVar) {
    findings.push({ category: 'shadow', literal: value.trim() });
  }

  if (property === 'z-index' && !hasNvxVar && /^-?\d+$/.test(value.trim()) && Number(value.trim()) !== 0) {
    findings.push({ category: 'z-index', literal: value.trim() });
  }

  if (TYPOGRAPHY_PROPERTIES.test(property) && !hasNvxVar) {
    const normalized = value.trim();
    if (property === 'font-weight' && /^(?:400|500|600|700)$/.test(normalized)) {
      findings.push({ category: 'typography-metric', literal: normalized });
    } else if (property === 'line-height' && /^(?:\d*\.\d+|\d+)$/.test(normalized) && normalized !== '1') {
      findings.push({ category: 'typography-metric', literal: normalized });
    } else if (property === 'letter-spacing' && /-?\d*\.?\d+(?:em|rem|px)/.test(normalized)) {
      findings.push({ category: 'typography-metric', literal: normalized });
    }
  }

  return findings;
}

async function cssFiles() {
  const entries = await fs.readdir(CSS_DIR, { withFileTypes: true });
  return entries
    .filter((entry) => entry.isFile() && entry.name.endsWith('.css') && entry.name !== 'nvx-tokens.css')
    .map((entry) => path.join(CSS_DIR, entry.name))
    .sort();
}

async function main() {
  const definitions = await collectTokenDefinitions();
  const findings = [];

  for (const file of await cssFiles()) {
    const original = await fs.readFile(file, 'utf8');
    const source = stripCommentsPreserveLines(original);
    const originalLines = original.split('\n');
    const declarationPattern = /([a-zA-Z-]+)\s*:\s*([^;{}]+);/g;

    for (const match of source.matchAll(declarationPattern)) {
      const property = match[1].toLowerCase();
      if (property.startsWith('--')) continue;

      const value = match[2].trim();
      const line = lineNumberAt(source, match.index ?? 0);
      const originalLine = originalLines[line - 1] ?? '';
      if (originalLine.includes('nvx-token-exception')) continue;

      const literals = extractScalarLiterals(value);
      for (const finding of classifyDeclaration(property, value, literals)) {
        const candidates = tokenCandidatesForLiteral(definitions, finding.literal);
        findings.push({
          category: finding.category,
          file: path.relative(ROOT, file),
          line,
          property,
          value,
          literal: finding.literal,
          token_exact: candidates.exact,
          token_contains: candidates.contains,
        });
      }
    }
  }

  const counts = findings.reduce((acc, finding) => {
    acc[finding.category] = (acc[finding.category] ?? 0) + 1;
    return acc;
  }, {});

  console.log(`DESIGN_TOKEN_ADOPTION_AUDIT=REPORT files=${(await cssFiles()).length} findings=${findings.length} strict=${strict}`);
  console.log(`DESIGN_TOKEN_ADOPTION_COUNTS=${JSON.stringify(counts)}`);

  for (const finding of findings.slice(0, MAX_PRINT)) {
    console.log(`DESIGN_TOKEN_FINDING=${JSON.stringify(finding)}`);
  }
  if (findings.length > MAX_PRINT) {
    console.log(`DESIGN_TOKEN_ADOPTION_TRUNCATED shown=${MAX_PRINT} total=${findings.length}`);
  }

  if (strict) {
    const blocking = findings.filter((finding) => STRICT_CATEGORIES.has(finding.category));
    if (blocking.length > 0) {
      console.error(`DESIGN_TOKEN_ADOPTION_AUDIT=FAIL blocking=${blocking.length}`);
      process.exit(1);
    }
  }

  console.log('DESIGN_TOKEN_ADOPTION_AUDIT=PASS mode=report');
}

main().catch((error) => {
  console.error(`DESIGN_TOKEN_ADOPTION_AUDIT=ERROR message=${JSON.stringify(error?.message ?? String(error))}`);
  process.exit(1);
});

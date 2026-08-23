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
const MOTION_PROPERTIES = /^(?:transition(?:-duration)?|animation(?:-duration)?)$/;
const TYPOGRAPHY_PROPERTIES = /^(?:line-height|letter-spacing|font-weight)$/;
const POSITION_PROPERTIES = /^(?:top|right|bottom|left|inset|inset-inline|inset-block)$/;
const DECLARATION_PATTERN = /([a-zA-Z-]+)\s*:\s*([^;{}]+)(?:;|(?=}|$))/g;

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

/**
 * Remove complete var(--nvx-...) expressions, including their fallback values,
 * while leaving any literals elsewhere in the declaration available to audit.
 * This distinguishes an adopted fallback such as var(--nvx-space-2, 16px)
 * from a mixed residual literal such as var(--nvx-space-2) 12px.
 */
function stripNvxVarExpressions(value) {
  let output = '';
  let cursor = 0;

  while (cursor < value.length) {
    const remainder = value.slice(cursor);
    const startMatch = remainder.match(/^var\(\s*--nvx-[\w-]+/);
    if (!startMatch) {
      output += value[cursor];
      cursor += 1;
      continue;
    }

    let depth = 0;
    let end = cursor;
    let closed = false;
    for (; end < value.length; end += 1) {
      const char = value[end];
      if (char === '(') depth += 1;
      if (char === ')') {
        depth -= 1;
        if (depth === 0) {
          end += 1;
          closed = true;
          break;
        }
      }
    }

    if (!closed) {
      output += value[cursor];
      cursor += 1;
      continue;
    }

    output += ' ';
    cursor = end;
  }

  return output;
}

function extractScalarLiterals(value) {
  const literals = new Map();
  for (const match of value.matchAll(/(?<![\w.-])(-?\d*\.?\d+)(px|rem|em|ms|s)(?![\w-])/g)) {
    const literal = { raw: match[0], number: Number(match[1]), unit: match[2] };
    literals.set(`${literal.number}|${literal.unit}`, literal);
  }
  return [...literals.values()];
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

function escapeRegExp(value) {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function tokenCandidatesForLiteral(definitions, literal) {
  const exact = [];
  const contains = [];
  const escaped = escapeRegExp(literal);
  for (const [name, value] of definitions.entries()) {
    if (value === literal) {
      exact.push(name);
    } else if (new RegExp(`(^|[^0-9.])${escaped}(?![0-9])`).test(value)) {
      contains.push(name);
    }
  }
  return { exact, contains: contains.slice(0, 6) };
}

function classifyDeclaration(property, originalValue) {
  const findings = [];
  const residualValue = stripNvxVarExpressions(originalValue).trim();
  const literals = extractScalarLiterals(residualValue);

  if (SPACING_PROPERTIES.test(property)) {
    for (const literal of literals) {
      if (literal.unit === 'px' && literal.number !== 0) findings.push({ category: 'spacing', literal: literal.raw });
    }
  }

  if (DIMENSION_PROPERTIES.test(property)) {
    for (const literal of literals) {
      if (literal.unit === 'px' && literal.number > 0) findings.push({ category: 'dimension-review', literal: literal.raw });
    }
  }

  if (POSITION_PROPERTIES.test(property)) {
    for (const literal of literals) {
      if (literal.unit === 'px' && literal.number !== 0) findings.push({ category: 'position-review', literal: literal.raw });
    }
  }

  if (MOTION_PROPERTIES.test(property)) {
    for (const literal of literals) {
      if ((literal.unit === 'ms' || literal.unit === 's') && literal.number > 0) findings.push({ category: 'motion', literal: literal.raw });
    }
  }

  if (property === 'border-radius') {
    for (const literal of literals) {
      if ((literal.unit === 'px' || literal.unit === 'rem') && literal.number > 0) findings.push({ category: 'radius', literal: literal.raw });
    }
  }

  if (property === 'box-shadow' && residualValue !== '' && residualValue !== 'none') {
    findings.push({ category: 'shadow', literal: residualValue });
  }

  if (property === 'z-index' && /^-?\d+$/.test(residualValue) && Number(residualValue) !== 0) {
    findings.push({ category: 'z-index', literal: residualValue });
  }

  if (TYPOGRAPHY_PROPERTIES.test(property)) {
    const normalized = residualValue;
    if (property === 'font-weight' && /^(?:400|500|600|700)$/.test(normalized)) {
      findings.push({ category: 'typography-metric', literal: normalized });
    } else if (property === 'line-height' && /^(?:\d*\.\d+|\d+)$/.test(normalized) && normalized !== '0' && normalized !== '1') {
      findings.push({ category: 'typography-metric', literal: normalized });
    } else if (property === 'letter-spacing' && /^-?\d*\.?\d+(?:em|rem|px)$/.test(normalized)) {
      findings.push({ category: 'typography-metric', literal: normalized });
    }
  }

  return findings;
}

function auditParserSelfTest() {
  const fixture = '.a{padding:var(--nvx-space-2, 16px) 12px}.b{margin:8px}.c{width:var(--nvx-touch-target-min,48px)}.d{transition:color .15s ease}.e{animation:slideIn 300ms ease}';
  const declarations = [...fixture.matchAll(DECLARATION_PATTERN)].map((match) => ({ property: match[1], value: match[2].trim() }));
  const padding = declarations.find((item) => item.property === 'padding');
  const margin = declarations.find((item) => item.property === 'margin');
  const width = declarations.find((item) => item.property === 'width');
  const transition = declarations.find((item) => item.property === 'transition');
  const animation = declarations.find((item) => item.property === 'animation');
  if (!padding || !margin || !width || !transition || !animation) throw new Error('parser_self_test_missing_semicolonless_declaration');

  const paddingFindings = classifyDeclaration(padding.property, padding.value);
  if (!paddingFindings.some((item) => item.category === 'spacing' && item.literal === '12px')) throw new Error('parser_self_test_mixed_literal_not_reported');
  if (paddingFindings.some((item) => item.literal === '16px')) throw new Error('parser_self_test_token_fallback_reported');

  const marginFindings = classifyDeclaration(margin.property, margin.value);
  if (!marginFindings.some((item) => item.category === 'spacing' && item.literal === '8px')) throw new Error('parser_self_test_final_declaration_not_reported');
  if (classifyDeclaration(width.property, width.value).length !== 0) throw new Error('parser_self_test_adopted_dimension_reported');
  if (!classifyDeclaration(transition.property, transition.value).some((item) => item.category === 'motion' && item.literal === '.15s')) throw new Error('parser_self_test_seconds_motion_not_reported');
  if (!classifyDeclaration(animation.property, animation.value).some((item) => item.category === 'motion' && item.literal === '300ms')) throw new Error('parser_self_test_animation_shorthand_not_reported');
}

async function cssFiles() {
  const entries = await fs.readdir(CSS_DIR, { withFileTypes: true });
  return entries.filter((entry) => entry.isFile() && entry.name.endsWith('.css') && entry.name !== 'nvx-tokens.css').map((entry) => path.join(CSS_DIR, entry.name)).sort();
}

async function main() {
  auditParserSelfTest();
  const definitions = await collectTokenDefinitions();
  const files = await cssFiles();
  const findings = [];

  for (const file of files) {
    const original = await fs.readFile(file, 'utf8');
    const source = stripCommentsPreserveLines(original);
    const originalLines = original.split('\n');

    for (const match of source.matchAll(DECLARATION_PATTERN)) {
      const property = match[1].toLowerCase();
      if (property.startsWith('--')) continue;
      const value = match[2].trim();
      const line = lineNumberAt(source, match.index ?? 0);
      const originalLine = originalLines[line - 1] ?? '';
      if (originalLine.includes('nvx-token-exception')) continue;

      for (const finding of classifyDeclaration(property, value)) {
        const candidates = tokenCandidatesForLiteral(definitions, finding.literal);
        findings.push({ category: finding.category, file: path.relative(ROOT, file), line, property, value, literal: finding.literal, token_exact: candidates.exact, token_contains: candidates.contains });
      }
    }
  }

  const counts = findings.reduce((acc, finding) => {
    acc[finding.category] = (acc[finding.category] ?? 0) + 1;
    return acc;
  }, {});

  console.log(`DESIGN_TOKEN_ADOPTION_AUDIT=REPORT files=${files.length} findings=${findings.length} strict=${strict}`);
  console.log(`DESIGN_TOKEN_ADOPTION_COUNTS=${JSON.stringify(counts)}`);
  for (const finding of findings.slice(0, MAX_PRINT)) console.log(`DESIGN_TOKEN_FINDING=${JSON.stringify(finding)}`);
  if (findings.length > MAX_PRINT) console.log(`DESIGN_TOKEN_ADOPTION_TRUNCATED shown=${MAX_PRINT} total=${findings.length}`);

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

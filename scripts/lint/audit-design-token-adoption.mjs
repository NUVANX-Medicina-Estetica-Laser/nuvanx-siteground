#!/usr/bin/env node
/**
 * Report design-system literals that should be reviewed for token adoption.
 *
 * The legacy baseline is still being classified, so most categories remain
 * report-only by default. Categories that have reached zero are ratcheted here
 * so they cannot regress while the remaining debt is migrated. Pass --strict
 * only after every governed category has been migrated or explicitly marked
 * with `nvx-token-exception`.
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

// Closed categories become default blocking ratchets even while the broader
// adoption audit remains report-only. Add a category here only after CI has
// demonstrated a zero baseline on protected master.
const DEFAULT_BLOCKING_CATEGORIES = new Set(['motion', 'z-index', 'shadow', 'radius', 'spacing']);

// Exact legacy literals whose effective runtime values are governed by a later
// semantic owner, plus structural values that are not the design category they
// superficially resemble. This allowlist is deliberately file+line+property+
// value bound: any source drift or additional literal must reopen the finding.
const GOVERNED_LEGACY_LITERAL_EXCEPTIONS = new Set([
  'wp-content/themes/nuvanx-medical/assets/css/nvx-patterns-editorial.css:170:z-index:2',
  'wp-content/themes/nuvanx-medical/assets/css/nvx-patterns-editorial.css:209:z-index:1',
  'wp-content/themes/nuvanx-medical/assets/css/nvx-components.css:244:padding-bottom:80px',
  'wp-content/themes/nuvanx-medical/assets/css/nvx-components.css:723:box-shadow:0 0 0 3px var(--nvx-accent-glow)',
  'wp-content/themes/nuvanx-medical/assets/css/nvx-components.css:999:margin:-1px',
  'wp-content/themes/nuvanx-medical/assets/css/nvx-components.css:1467:margin-bottom:2px',
  'wp-content/themes/nuvanx-medical/assets/css/nvx-posts.css:784:box-shadow:inset 0 0 0 var(--nvx-border-hairline) var(--nvx-color-line)',
  'wp-content/themes/nuvanx-medical/assets/css/nvx-site-layout.css:417:border-radius:16px',
]);

const SPACING_PROPERTIES = /^(?:margin(?:-(?:top|right|bottom|left|inline|inline-start|inline-end|block|block-start|block-end))?|padding(?:-(?:top|right|bottom|left|inline|inline-start|inline-end|block|block-start|block-end))?|gap|row-gap|column-gap)$/;
const DIMENSION_PROPERTIES = /^(?:width|height|min-width|max-width|min-height|max-height)$/;
const MOTION_PROPERTIES = /^(?:transition(?:-duration)?|animation|animation-duration)$/;
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

function isGovernedLegacyLiteralException(file, line, property, value) {
  const relativeFile = path.relative(ROOT, file);
  const key = `${relativeFile}:${line}:${property}:${value.trim()}`;
  return GOVERNED_LEGACY_LITERAL_EXCEPTIONS.has(key);
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

  const editorialFile = path.join(CSS_DIR, 'nvx-patterns-editorial.css');
  const componentsFile = path.join(CSS_DIR, 'nvx-components.css');
  const postsFile = path.join(CSS_DIR, 'nvx-posts.css');
  const layoutFile = path.join(CSS_DIR, 'nvx-site-layout.css');
  if (!isGovernedLegacyLiteralException(editorialFile, 170, 'z-index', '2')) throw new Error('parser_self_test_governed_legacy_z_overlay_missing');
  if (!isGovernedLegacyLiteralException(editorialFile, 209, 'z-index', '1')) throw new Error('parser_self_test_governed_legacy_z_base_missing');
  if (isGovernedLegacyLiteralException(editorialFile, 171, 'z-index', '2')) throw new Error('parser_self_test_governed_legacy_z_exception_too_broad');
  if (!isGovernedLegacyLiteralException(componentsFile, 244, 'padding-bottom', '80px')) throw new Error('parser_self_test_governed_footer_spacing_missing');
  if (!isGovernedLegacyLiteralException(componentsFile, 723, 'box-shadow', '0 0 0 3px var(--nvx-accent-glow)')) throw new Error('parser_self_test_governed_focus_shadow_missing');
  if (!isGovernedLegacyLiteralException(componentsFile, 999, 'margin', '-1px')) throw new Error('parser_self_test_structural_hidden_margin_missing');
  if (!isGovernedLegacyLiteralException(componentsFile, 1467, 'margin-bottom', '2px')) throw new Error('parser_self_test_governed_factsheet_spacing_missing');
  if (!isGovernedLegacyLiteralException(postsFile, 784, 'box-shadow', 'inset 0 0 0 var(--nvx-border-hairline) var(--nvx-color-line)')) throw new Error('parser_self_test_structural_inset_shadow_missing');
  if (!isGovernedLegacyLiteralException(layoutFile, 417, 'border-radius', '16px')) throw new Error('parser_self_test_governed_legal_radius_missing');
  if (isGovernedLegacyLiteralException(layoutFile, 418, 'border-radius', '16px')) throw new Error('parser_self_test_governed_legacy_radius_exception_too_broad');
  if (isGovernedLegacyLiteralException(componentsFile, 245, 'padding-bottom', '80px')) throw new Error('parser_self_test_governed_spacing_exception_too_broad');
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
      if (isGovernedLegacyLiteralException(file, line, property, value)) continue;

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

  console.log(`DESIGN_TOKEN_ADOPTION_AUDIT=REPORT files=${files.length} findings=${findings.length} strict=${strict} ratchet=${[...DEFAULT_BLOCKING_CATEGORIES].join(',')}`);
  console.log(`DESIGN_TOKEN_ADOPTION_COUNTS=${JSON.stringify(counts)}`);
  for (const finding of findings.slice(0, MAX_PRINT)) console.log(`DESIGN_TOKEN_FINDING=${JSON.stringify(finding)}`);
  if (findings.length > MAX_PRINT) console.log(`DESIGN_TOKEN_ADOPTION_TRUNCATED shown=${MAX_PRINT} total=${findings.length}`);

  const blockingCategories = strict ? STRICT_CATEGORIES : DEFAULT_BLOCKING_CATEGORIES;
  const blocking = findings.filter((finding) => blockingCategories.has(finding.category));
  if (blocking.length > 0) {
    console.error(`DESIGN_TOKEN_ADOPTION_AUDIT=FAIL blocking=${blocking.length} categories=${[...blockingCategories].join(',')}`);
    process.exit(1);
  }

  console.log(`DESIGN_TOKEN_ADOPTION_AUDIT=PASS mode=${strict ? 'strict' : 'ratchet'}`);
}

main().catch((error) => {
  console.error(`DESIGN_TOKEN_ADOPTION_AUDIT=ERROR message=${JSON.stringify(error?.message ?? String(error))}`);
  process.exit(1);
});

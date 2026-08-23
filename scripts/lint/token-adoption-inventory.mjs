#!/usr/bin/env node
/**
 * Read-only inventory for NUVANX design-token adoption.
 *
 * This script deliberately does NOT fail CI. It inventories physical CSS values
 * that may need migration into the canonical token SSOT and classifies obvious
 * structural / third-party exceptions so the later blocking gate is evidence-led.
 *
 * Usage:
 *   node scripts/lint/token-adoption-inventory.mjs
 *   node scripts/lint/token-adoption-inventory.mjs --json
 *
 * @see ../../wp-content/themes/nuvanx-medical/assets/css/nvx-tokens.css
 */

import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = path.resolve(__dirname, '../..');
const THEME_ROOT = path.join(REPO_ROOT, 'wp-content/themes/nuvanx-medical');
const TOKENS_FILE = path.join(THEME_ROOT, 'assets/css/nvx-tokens.css');

const TARGET_EXTENSIONS = new Set(['.css', '.php']);
const TARGET_PROPERTIES = /^(?:margin(?:-[a-z]+)?|padding(?:-[a-z]+)?|gap|row-gap|column-gap|inset(?:-[a-z]+)?|top|right|bottom|left|width|height|min-width|max-width|min-height|max-height|border-radius|box-shadow|text-shadow|transition|transition-duration|animation-duration|transform|z-index|font-size|line-height)$/i;
const PHYSICAL_VALUE = /-?(?:\d+\.?\d*|\.\d+)(?:px|rem|em|vh|vw|svh|lvh|dvh|vmin|vmax|ms|s)\b/gi;
const CSS_DECLARATION = /([\w-]+)\s*:\s*([^;{}]+);?/g;
const ALLOW_MARKER = /nvx-token-allow\s*:\s*([^*\n]+)/i;

/**
 * Recursively collect theme CSS/PHP files while excluding dependency trees.
 * @param {string} dir
 * @returns {Promise<string[]>}
 */
async function collectFiles(dir) {
  const out = [];
  const entries = await fs.readdir(dir, { withFileTypes: true });
  for (const entry of entries) {
    if (entry.name === 'node_modules' || entry.name === 'vendor') continue;
    const fullPath = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      out.push(...await collectFiles(fullPath));
      continue;
    }
    if (TARGET_EXTENSIONS.has(path.extname(entry.name))) out.push(fullPath);
  }
  return out.sort();
}

function relative(filePath) {
  return path.relative(REPO_ROOT, filePath).replaceAll('\\', '/');
}

function isThirdPartyContext(context) {
  return /joinchat|whatsapp-me|cmplz|complianz|hubspot|hs-form|hsfc-|grecaptcha|recaptcha/i.test(context);
}

function isStructuralAllowed(property, value) {
  const trimmed = value.trim().toLowerCase();
  if (/^(?:0|auto|none|normal|inherit|initial|unset)$/.test(trimmed)) return true;
  if (/^(?:100%|50%|auto)$/.test(trimmed)) return true;
  if (/^1px$/.test(trimmed) && /border|outline/.test(property)) return true;
  return false;
}

function classify({ property, value, context, allowReason }) {
  if (allowReason) return 'EXCEPTION_DOCUMENTED';
  if (isThirdPartyContext(context)) return 'THIRD_PARTY_CONTAINMENT';
  if (isStructuralAllowed(property, value)) return 'STRUCTURAL_ALLOWED';
  return 'TOKEN_REQUIRED';
}

function selectorAt(lines, index) {
  for (let i = index; i >= 0 && i >= index - 8; i--) {
    const line = lines[i].trim();
    if (line.endsWith('{')) return line.slice(0, -1).trim();
  }
  return '';
}

function inventoryCss(filePath, content) {
  if (path.resolve(filePath) === path.resolve(TOKENS_FILE)) return [];
  const findings = [];
  const lines = content.split('\n');

  lines.forEach((line, index) => {
    const allowReason = line.match(ALLOW_MARKER)?.[1]?.trim() || null;
    const selector = selectorAt(lines, index);
    for (const match of line.matchAll(CSS_DECLARATION)) {
      const property = match[1].trim();
      const value = match[2].trim();
      if (!TARGET_PROPERTIES.test(property)) continue;
      const physicalValues = [...value.matchAll(PHYSICAL_VALUE)].map((item) => item[0]);
      if (physicalValues.length === 0 && property.toLowerCase() !== 'z-index') continue;
      if (value.includes('var(--nvx-') && physicalValues.length === 0) continue;

      const context = `${selector} ${line}`;
      findings.push({
        file: relative(filePath),
        line: index + 1,
        source: 'css',
        selector,
        property,
        value,
        physical_values: physicalValues,
        classification: classify({ property, value, context, allowReason }),
        allow_reason: allowReason,
      });
    }
  });

  return findings;
}

function inventoryPhp(filePath, content) {
  const findings = [];
  const lines = content.split('\n');

  lines.forEach((line, index) => {
    const allowReason = line.match(ALLOW_MARKER)?.[1]?.trim() || null;
    // Limit PHP inventory to lines that can emit style declarations. This avoids
    // treating unrelated PHP dimensions, IDs or business values as design drift.
    if (!/style\s*=|style['"]?\s*=>|<style|wp_add_inline_style|\.css\s*\(/i.test(line)) return;

    for (const match of line.matchAll(CSS_DECLARATION)) {
      const property = match[1].trim();
      const value = match[2].trim();
      if (!TARGET_PROPERTIES.test(property)) continue;
      const physicalValues = [...value.matchAll(PHYSICAL_VALUE)].map((item) => item[0]);
      if (physicalValues.length === 0 && property.toLowerCase() !== 'z-index') continue;

      findings.push({
        file: relative(filePath),
        line: index + 1,
        source: 'php-inline-style',
        selector: '',
        property,
        value,
        physical_values: physicalValues,
        classification: classify({ property, value, context: line, allowReason }),
        allow_reason: allowReason,
      });
    }
  });

  return findings;
}

function summarize(findings) {
  const byClassification = {};
  const byProperty = {};
  const byFile = {};

  for (const finding of findings) {
    byClassification[finding.classification] = (byClassification[finding.classification] || 0) + 1;
    byProperty[finding.property] = (byProperty[finding.property] || 0) + 1;
    byFile[finding.file] = (byFile[finding.file] || 0) + 1;
  }

  return {
    total: findings.length,
    by_classification: Object.fromEntries(Object.entries(byClassification).sort()),
    by_property: Object.fromEntries(Object.entries(byProperty).sort((a, b) => b[1] - a[1])),
    by_file: Object.fromEntries(Object.entries(byFile).sort((a, b) => b[1] - a[1])),
  };
}

async function main() {
  const jsonMode = process.argv.includes('--json');
  const files = await collectFiles(THEME_ROOT);
  const findings = [];

  for (const filePath of files) {
    const content = await fs.readFile(filePath, 'utf8');
    findings.push(...(path.extname(filePath) === '.css'
      ? inventoryCss(filePath, content)
      : inventoryPhp(filePath, content)));
  }

  const payload = {
    schema: 'nvx-token-adoption-inventory-v1',
    generated_at: new Date().toISOString(),
    theme_root: relative(THEME_ROOT),
    token_ssot: relative(TOKENS_FILE),
    scanned_files: files.length,
    summary: summarize(findings),
    findings,
  };

  if (jsonMode) {
    process.stdout.write(`${JSON.stringify(payload, null, 2)}\n`);
    return;
  }

  console.log('NUVANX TOKEN ADOPTION INVENTORY');
  console.log(`scanned_files=${payload.scanned_files}`);
  console.log(`findings=${payload.summary.total}`);
  for (const [classification, count] of Object.entries(payload.summary.by_classification)) {
    console.log(`${classification}=${count}`);
  }
  console.log('\nTop files:');
  for (const [file, count] of Object.entries(payload.summary.by_file).slice(0, 20)) {
    console.log(`${String(count).padStart(4)}  ${file}`);
  }
  console.log('\nThis command is inventory-only and intentionally exits 0.');
}

main().catch((error) => {
  console.error('TOKEN_ADOPTION_INVENTORY=ERROR');
  console.error(error);
  process.exit(1);
});

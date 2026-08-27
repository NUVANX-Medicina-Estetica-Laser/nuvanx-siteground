#!/usr/bin/env node
/**
 * Contract test for interior hero surface semantics.
 *
 * Migrated pages must declare a surface explicitly. The final governance layer
 * owns the effective foreground/background variables until the CSS build/layer
 * refactor moves this contract into the compiled component bundle.
 */

import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.join(__dirname, '../..');
const theme = path.join(root, 'wp-content/themes/nuvanx-medical');

const [tokens, governance, nativeGovernance, contacto, valoracion] = await Promise.all([
  fs.readFile(path.join(theme, 'assets/css/nvx-tokens.css'), 'utf8'),
  fs.readFile(path.join(theme, 'assets/css/nvx-accessibility-governance.css'), 'utf8'),
  fs.readFile(path.join(theme, 'inc/nvx-native-style-governance.php'), 'utf8'),
  fs.readFile(path.join(theme, 'templates/page-contacto.php'), 'utf8'),
  fs.readFile(path.join(theme, 'inc/nvx-valoracion-managed-page.php'), 'utf8'),
]);

assert.match(
  governance,
  /\.nvx-brand-hero--surface-ink\s*\{[\s\S]*?--nvx-hero-bg:\s*var\(--nvx-ink\);[\s\S]*?--nvx-hero-fg:\s*var\(--nvx-light\);[\s\S]*?--nvx-hero-muted:\s*var\(--nvx-text-on-dark-90\);/,
  'Ink hero surface must own semantic background, foreground and muted tokens',
);

assert.match(
  governance,
  /\.nvx-brand-hero--surface-media-light\s*\{[\s\S]*?--nvx-hero-bg:\s*var\(--nvx-light\);[\s\S]*?--nvx-hero-fg:\s*var\(--nvx-ink\);[\s\S]*?--nvx-hero-muted:\s*var\(--nvx-text-body\);/,
  'Light-media hero surface must invert the semantic foreground contract',
);

assert.match(
  governance,
  /\.nvx-brand-hero--surface-ink \.nvx-brand-hero__title,[\s\S]*?color:\s*var\(--nvx-hero-fg\);/,
  'Hero title must consume --nvx-hero-fg on explicit surfaces',
);

assert.match(
  governance,
  /\.nvx-brand-hero--surface-ink \.nvx-brand-hero__lead,[\s\S]*?color:\s*var\(--nvx-hero-muted\);/,
  'Hero lead must consume --nvx-hero-muted on explicit surfaces',
);

assert.match(
  contacto,
  /class="nvx-brand-hero nvx-brand-hero--surface-ink"/,
  'Contacto must declare the dark hero surface explicitly',
);

assert.match(
  valoracion,
  /class=\\"nvx-brand-hero nvx-brand-hero--surface-ink nvx-valoracion-hero\\"/,
  'Valoracion managed renderer must declare the dark hero surface explicitly',
);

const patternsIndex = nativeGovernance.indexOf("'assets/css/nvx-patterns-editorial.css'");
const governanceIndex = nativeGovernance.indexOf("'assets/css/nvx-accessibility-governance.css'");
assert.ok(patternsIndex >= 0, 'Native style governance must include nvx-patterns-editorial.css');
assert.ok(governanceIndex > patternsIndex, 'Accessibility governance must load after editorial patterns');

function parseHexToken(name) {
  const match = tokens.match(new RegExp(`${name}:\\s*#([0-9a-fA-F]{6});`));
  assert.ok(match, `Missing ${name} hex token`);
  return match[1];
}

function srgbToLinear(value) {
  const v = value / 255;
  return v <= 0.04045 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
}

function luminance(hex) {
  const r = Number.parseInt(hex.slice(0, 2), 16);
  const g = Number.parseInt(hex.slice(2, 4), 16);
  const b = Number.parseInt(hex.slice(4, 6), 16);
  return 0.2126 * srgbToLinear(r) + 0.7152 * srgbToLinear(g) + 0.0722 * srgbToLinear(b);
}

function contrast(a, b) {
  const l1 = luminance(a);
  const l2 = luminance(b);
  return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
}

const light = parseHexToken('--nvx-light');
const ink = parseHexToken('--nvx-ink');
const ratio = contrast(light, ink);
assert.ok(ratio >= 4.5, `Ink surface text contrast ${ratio.toFixed(2)}:1 must meet WCAG AA`);

console.log(`HERO_SURFACE_CONTRACT=PASS contrast=${ratio.toFixed(2)}:1`);

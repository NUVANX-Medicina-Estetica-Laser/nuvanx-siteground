#!/usr/bin/env node
/**
 * CSS ownership and regression contract.
 *
 * Static theme CSS has exactly one transport: versioned linked stylesheets.
 * Runtime inline CSS is allowed only for explicitly dynamic values that cannot
 * be represented by a static stylesheet.
 */

import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT_DIR = path.join(__dirname, '../..');
const THEME_DIR = path.join(ROOT_DIR, 'wp-content/themes/nuvanx-medical');
const CSS_DIR = path.join(THEME_DIR, 'assets/css');
const EDITORIAL_OWNER = path.resolve(CSS_DIR, 'nvx-patterns-editorial.css');
const IGNORED_DIRECTORIES = new Set(['.git', 'node_modules', 'vendor', 'dist']);
const DYNAMIC_INLINE_STYLE_BUDGET = new Map([
  // Dynamic featured-image URL; permanent declarations stay in source CSS.
  [path.resolve(THEME_DIR, 'inc/nvx-hero-and-forms.php'), 1],
]);
const RETIRED_STATIC_INLINE_SYMBOLS = [
  'nvx_theme_public_delivers_inline_styles',
  'nvx_theme_register_inline_style_handles',
  'nvx_theme_critical_stylesheet_files',
  'nvx_theme_local_style_handles',
  'nvx_theme_style_after_data',
  'nvx_theme_get_css_manifest',
  'nvx_theme_get_compiled_critical_css_bundle',
  'nvx_theme_inline_critical_style_foundation',
  'nvx_theme_dequeue_late_local_styles',
  'nvx_theme_drop_inlined_file_links',
  'nvx-critical-inline',
];
const AUTHENTIC_GRID_GEOMETRY_RULES = [
  {
    selector: '.nvx-authentic-photo-grid__grid',
    declaration: /grid-template-columns\s*:\s*repeat\(\s*12\s*,/u,
    fixtureDeclaration: 'grid-template-columns: repeat(12, minmax(0, 1fr));',
    label: 'desktop grid uses 12 columns',
  },
  {
    selector: '.nvx-authentic-photo-grid__item:first-child:nth-last-child(2)',
    declaration: /grid-column\s*:\s*span\s+7\b/u,
    fixtureDeclaration: 'grid-column: span 7;',
    label: 'two-photo lead spans 7 columns',
  },
  {
    selector: '.nvx-authentic-photo-grid__item:nth-child(2):last-child',
    declaration: /grid-column\s*:\s*span\s+5\b/u,
    fixtureDeclaration: 'grid-column: span 5;',
    label: 'two-photo support spans 5 columns',
  },
  {
    selector: '.nvx-authentic-photo-grid__item:nth-child(n + 3)',
    declaration: /grid-column\s*:\s*span\s+6\b/u,
    fixtureDeclaration: 'grid-column: span 6;',
    label: 'subsequent photos span 6 columns',
  },
  {
    selector: '.nvx-authentic-photo-grid__image',
    declaration: /aspect-ratio\s*:\s*4\s*\/\s*3\b/u,
    fixtureDeclaration: 'aspect-ratio: 4 / 3;',
    label: 'supporting images use 4:3 ratio',
  },
  {
    selector: '.nvx-authentic-photo-grid__item:first-child .nvx-authentic-photo-grid__image',
    declaration: /aspect-ratio\s*:\s*3\s*\/\s*2\b/u,
    fixtureDeclaration: 'aspect-ratio: 3 / 2;',
    label: 'lead image uses 3:2 ratio',
  },
];

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

function maskCssComments(content) {
  return content.replace(/\/\*[\s\S]*?\*\//g, (comment) => comment.replace(/[^\r\n]/g, ' '));
}

function fontSizeDeclarations(source) {
  const code = maskCssComments(source);
  return [...code.matchAll(/font-size\s*:\s*([^;{}]+)(?:;|(?=\s*}))/giu)].map((match) => ({
    value: match[1],
    index: match.index ?? 0,
  }));
}

function cssRuleBlocks(source) {
  const code = maskCssComments(source);
  return [...code.matchAll(/([^{}]+)\{([^{}]*)\}/gu)].map((match) => ({
    selectors: match[1]
      .split(',')
      .map((selector) => selector.trim())
      .filter(Boolean),
    declarations: match[2],
  }));
}

function geometryRuleViolations(source, requirements = AUTHENTIC_GRID_GEOMETRY_RULES) {
  const rules = cssRuleBlocks(source);
  return requirements
    .filter((requirement) => !rules.some(
      (rule) => rule.selectors.includes(requirement.selector) && requirement.declaration.test(rule.declarations)
    ))
    .map((requirement) => requirement.label);
}

function geometryMatcherFixtureViolations() {
  const violations = [];
  AUTHENTIC_GRID_GEOMETRY_RULES.forEach((requirement, index) => {
    const decoy = `.nvx-geometry-decoy-${index}`;
    const negativeFixture = `${requirement.selector} { display: block; }\n${decoy} { ${requirement.fixtureDeclaration} }`;
    if (geometryRuleViolations(negativeFixture, [requirement]).length === 0) {
      violations.push(`negative fixture ${index + 1} crossed a CSS rule boundary`);
    }

    const positiveFixture = `${requirement.selector} { ${requirement.fixtureDeclaration} }\n${decoy} { display: block; }`;
    if (geometryRuleViolations(positiveFixture, [requirement]).length !== 0) {
      violations.push(`positive fixture ${index + 1} rejected the owning CSS rule`);
    }
  });
  return violations;
}

async function main() {
  const violations = [];
  const themeFiles = await walk(THEME_DIR);
  const phpFiles = themeFiles.filter((file) => file.endsWith('.php'));

  for (const file of phpFiles) {
    const content = await fs.readFile(file, 'utf8');
    const scannable = maskPhpComments(content);
    const absolute = path.resolve(file);

    for (const match of scannable.matchAll(/<style\b/giu)) {
      violations.push(`${rel(file)}:${lineNumber(scannable, match.index)} permanent <style> block`);
    }
    for (const match of scannable.matchAll(/<<<\s*['"]?CSS['"]?/g)) {
      violations.push(`${rel(file)}:${lineNumber(scannable, match.index)} CSS heredoc/nowdoc`);
    }

    const inlineCalls = [...scannable.matchAll(/\bwp_add_inline_style\s*\(/g)];
    const allowed = DYNAMIC_INLINE_STYLE_BUDGET.get(absolute) ?? 0;
    if (inlineCalls.length !== allowed) {
      violations.push(`${rel(file)} dynamic wp_add_inline_style budget expected=${allowed} actual=${inlineCalls.length}`);
    }

    for (const retired of RETIRED_STATIC_INLINE_SYMBOLS) {
      const index = scannable.indexOf(retired);
      if (index >= 0) {
        violations.push(`${rel(file)}:${lineNumber(scannable, index)} retired static-inline symbol ${retired}`);
      }
    }

    if (/dist\/manifest\.json|\/dist\/[^'"\s]+\.css/iu.test(scannable)) {
      violations.push(`${rel(file)} runtime PHP must not own compiled CSS/dist transport`);
    }
    if (/file_get_contents\s*\([^)]*\.css/giu.test(scannable)) {
      violations.push(`${rel(file)} runtime PHP must not read static CSS into memory`);
    }
  }

  const cssFiles = (await walk(THEME_DIR)).filter((file) => file.endsWith('.css')).sort();
  const keyframeOwners = new Map();
  const componentOwners = new Set();

  for (const file of cssFiles) {
    const content = await fs.readFile(file, 'utf8');
    const scannable = maskCssComments(content);

    if (/\.nvx-authentic-photo-grid(?:__[-_a-z0-9]+)?\b/iu.test(scannable)) {
      componentOwners.add(path.resolve(file));
    }

    for (const match of scannable.matchAll(/@(?:-webkit-)?keyframes\s+([A-Za-z0-9_-]+)/g)) {
      const name = match[1];
      const owners = keyframeOwners.get(name) ?? [];
      owners.push(`${rel(file)}:${lineNumber(scannable, match.index)}`);
      keyframeOwners.set(name, owners);
    }

    for (const declaration of fontSizeDeclarations(content)) {
      if (/--nvx-space-/u.test(declaration.value)) {
        violations.push(
          `${rel(file)}:${lineNumber(scannable, declaration.index)} spacing token used as font-size: ${declaration.value.trim()}`
        );
      }
    }
  }

  if (componentOwners.size !== 1 || !componentOwners.has(EDITORIAL_OWNER)) {
    violations.push(
      `authentic photo grid owner must be only assets/css/nvx-patterns-editorial.css; actual=${[...componentOwners].map(rel).join(',') || 'none'}`
    );
  }

  const editorial = await fs.readFile(EDITORIAL_OWNER, 'utf8');
  geometryRuleViolations(editorial).forEach((label) => {
    violations.push(`authentic photo grid canonical geometry missing in owning rule: ${label}`);
  });
  geometryMatcherFixtureViolations().forEach((failure) => {
    violations.push(`authentic photo grid geometry matcher fixture failed: ${failure}`);
  });

  const responsiveMarkers = [
    /@media \(min-width:\s*768px\) and \(max-width:\s*1239px\)/u,
    /@media \(max-width:\s*767px\)/u,
  ];
  responsiveMarkers.forEach((marker, index) => {
    if (!marker.test(editorial)) violations.push(`authentic photo grid responsive geometry marker ${index + 1} missing`);
  });

  for (const [name, owners] of keyframeOwners.entries()) {
    if (owners.length > 1) violations.push(`duplicate @keyframes ${name}: ${owners.join(', ')}`);
  }

  const repoFiles = await walk(ROOT_DIR);
  const immutableCssRef = /\bnvx-[A-Za-z0-9_-]+\.[0-9a-f]{10}\.css\b/g;
  for (const file of repoFiles) {
    if (!/\.(?:php|js|mjs|json|md|ya?ml|sh)$/i.test(file)) continue;
    let content;
    try {
      content = await fs.readFile(file, 'utf8');
    } catch {
      continue;
    }
    for (const match of content.matchAll(immutableCssRef)) {
      violations.push(`${rel(file)}:${lineNumber(content, match.index)} hard-coded dist CSS artifact ${match[0]}`);
    }
  }

  if (violations.length > 0) {
    console.error('CSS_OWNERSHIP_CONTRACT=FAIL');
    for (const violation of violations) console.error(` - ${violation}`);
    process.exit(1);
  }

  console.log(
    `CSS_OWNERSHIP_CONTRACT=PASS css_sources=${cssFiles.length} keyframes=${keyframeOwners.size} ` +
    'static_delivery=linked dynamic_inline_owners=1 authentic_grid_owner=editorial typography_spacing_crossovers=0 geometry_rules=block_bounded'
  );
}

main().catch((err) => {
  console.error('CSS_OWNERSHIP_CONTRACT=FAIL', err);
  process.exit(1);
});

#!/usr/bin/env node
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

const root = process.cwd();
const tracked = execFileSync('git', ['ls-files', '-z'], { encoding: 'utf8' })
  .split('\0')
  .filter(Boolean)
  .sort();
const failures = [];
const fail = (kind, detail) => failures.push(`${kind}: ${detail}`);

const canonicalMarkdown = new Set([
  '.github/pull_request_template.md',
  'AGENTS.md',
  'README.md',
  'SECURITY.md',
  'docs/architecture.md',
  'docs/integration-configuration-governance.md',
  'docs/operations/deployment.md',
  'docs/operations/endolaser-clinical-content-gate.md',
  'docs/operations/tariff-shortcode-usage.md',
  'scripts/seo/README.md',
  'tools/migrations/README.md',
]);

const markdown = tracked.filter((file) => file.endsWith('.md'));
for (const file of markdown) {
  if (!canonicalMarkdown.has(file)) fail('UNOWNED_MARKDOWN_SURFACE', file);
}
for (const file of canonicalMarkdown) {
  if (!tracked.includes(file)) fail('CANONICAL_MARKDOWN_MISSING', file);
}

const workflows = tracked.filter((file) => /^\.github\/workflows\/.*\.ya?ml$/i.test(file));
const expectedWorkflows = ['.github/workflows/production.yml', '.github/workflows/staging.yml'];
if (JSON.stringify(workflows) !== JSON.stringify(expectedWorkflows)) {
  fail('WORKFLOW_SURFACE_DRIFT', workflows.join(','));
}

const textExtensions = /\.(?:md|txt|json|ya?ml|php|mjs|js|cjs|sh|py|css)$/i;
const forbiddenDeveloperPaths = [
  { pattern: /\/Users\/[A-Za-z0-9._-]+\//g, label: 'macOS user home' },
  { pattern: /[A-Za-z]:\\Users\\[A-Za-z0-9._-]+\\/g, label: 'Windows user home' },
  { pattern: /file:\/\/\/(?:Users|home)\//g, label: 'local file URL' },
];

for (const file of tracked.filter((item) => textExtensions.test(item))) {
  let source;
  try { source = fs.readFileSync(path.join(root, file), 'utf8'); } catch { continue; }
  for (const { pattern, label } of forbiddenDeveloperPaths) {
    pattern.lastIndex = 0;
    if (pattern.test(source)) fail('DEVELOPER_LOCAL_PATH', `${file} (${label})`);
  }
}

const phpFiles = tracked.filter((file) => file.endsWith('.php'));
const dynamicStyleOwners = new Set([]);
for (const file of phpFiles) {
  const source = fs.readFileSync(path.join(root, file), 'utf8');
  const htmlStyle = /<[A-Za-z][^>]*\sstyle\s*=\s*["']/giu;
  if (htmlStyle.test(source) && !dynamicStyleOwners.has(file)) {
    fail('INLINE_HTML_STYLE_ATTRIBUTE', file);
  }
}

const forbiddenSeoOneShotPatterns = [
  /^scripts\/seo\/(?:full-|verify-and-)/,
  /^scripts\/seo\/pagespeed-(?:100-percent|full-audit)\./,
];
for (const file of tracked) {
  if (forbiddenSeoOneShotPatterns.some((pattern) => pattern.test(file))) {
    fail('SEO_ONE_SHOT_SURFACE', file);
  }
}

if (failures.length > 0) {
  for (const failure of failures) console.error(`REPOSITORY_PORTABILITY=FAIL ${failure}`);
  console.error(`REPOSITORY_PORTABILITY=FAIL count=${failures.length}`);
  process.exit(1);
}

console.log(
  `REPOSITORY_PORTABILITY=PASS markdown=${markdown.length} workflows=${workflows.length}`
  + ' developer_local_paths=0 inline_html_style=0 seo_one_shots=0'
);

#!/usr/bin/env node
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

const root = process.cwd();
const posix = path.posix;
const registryPath = 'scripts/lint/js-mjs-semantic-audit.json';
const tracked = execFileSync('git', ['ls-files', '-z'], { cwd: root, encoding: 'utf8' })
  .split('\0')
  .filter(Boolean)
  .sort();
const jsFiles = tracked.filter((file) => /\.(?:js|mjs)$/.test(file));
const jsSet = new Set(jsFiles);

const registry = JSON.parse(fs.readFileSync(path.join(root, registryPath), 'utf8'));
assert.equal(registry.schema, 1, 'JS/MJS semantic audit registry schema must be 1');
for (const key of ['manual_entrypoints', 'browser_entrypoints', 'raw_json_validators', 'bounded_waits']) {
  assert.ok(registry[key] && typeof registry[key] === 'object' && !Array.isArray(registry[key]), `Registry ${key} must be an object`);
}

const sourceCache = new Map();
function read(file) {
  if (!sourceCache.has(file)) {
    sourceCache.set(file, fs.readFileSync(path.join(root, file), 'utf8'));
  }
  return sourceCache.get(file);
}

const textExtensions = new Set(['.php', '.mjs', '.js', '.json', '.yml', '.yaml', '.md', '.sh', '.txt', '.xml']);
const textFiles = tracked.filter((file) => {
  if (!textExtensions.has(path.extname(file)) && file !== 'package.json') return false;
  try {
    return fs.statSync(path.join(root, file)).size <= 1_500_000;
  } catch {
    return false;
  }
});

const seeds = new Map();
function addSeed(file, reason) {
  if (!jsSet.has(file)) return;
  if (!seeds.has(file)) seeds.set(file, new Set());
  seeds.get(file).add(reason);
}

for (const [manual, reason] of Object.entries(registry.manual_entrypoints)) {
  assert.ok(jsSet.has(manual), `Manual JS/MJS entrypoint does not exist: ${manual}`);
  assert.ok(typeof reason === 'string' && reason.trim().length >= 16, `Manual entrypoint needs a substantive reason: ${manual}`);
  addSeed(manual, 'manual_registry');
}

// Only executable authorities establish automatic reachability. Documentation,
// arbitrary JSON and prose may describe a script, but they cannot make it live.
// Explicit one-shots must be admitted through manual_entrypoints with a reason.
const executableAuthorities = textFiles.filter((file) => (
  file === 'package.json'
  || /\.php$/.test(file)
  || /\.sh$/.test(file)
  || /^\.github\/workflows\/[^/]+\.ya?ml$/.test(file)
));
for (const caller of executableAuthorities) {
  const content = read(caller);
  for (const target of jsFiles) {
    const themePrefix = 'wp-content/themes/nuvanx-medical';
    const themeRelative = target.startsWith(`${themePrefix}/`) ? target.slice(themePrefix.length) : '';
    if (content.includes(target) || (themeRelative && content.includes(themeRelative))) {
      addSeed(target, `referenced_by:${caller}`);
    }
  }
}

function resolveJsSpecifier(fromFile, specifier) {
  if (!specifier.startsWith('.')) return null;
  const base = posix.normalize(posix.join(posix.dirname(fromFile), specifier));
  const candidates = [base, `${base}.js`, `${base}.mjs`, posix.join(base, 'index.js'), posix.join(base, 'index.mjs')];
  return candidates.find((candidate) => jsSet.has(candidate)) || null;
}

const edges = new Map(jsFiles.map((file) => [file, new Set()]));
const incoming = new Map(jsFiles.map((file) => [file, new Set()]));
const importPatterns = [
  /\bimport\s+(?:[^'"()]+?\s+from\s+)?['"]([^'"]+)['"]/g,
  /\bexport\s+[^'"()]+?\s+from\s+['"]([^'"]+)['"]/g,
  /\bimport\s*\(\s*['"]([^'"]+)['"]\s*\)/g,
  /\brequire\s*\(\s*['"]([^'"]+)['"]\s*\)/g,
  /\bnew\s+URL\s*\(\s*['"]([^'"]+\.(?:js|mjs))['"]\s*,\s*import\.meta\.url\s*\)/g,
];

for (const file of jsFiles) {
  const source = read(file);
  for (const pattern of importPatterns) {
    for (const match of source.matchAll(pattern)) {
      const resolved = resolveJsSpecifier(file, match[1]);
      if (!resolved) continue;
      edges.get(file).add(resolved);
      incoming.get(resolved).add(file);
    }
  }
}

const reachable = new Set(seeds.keys());
const queue = [...reachable];
while (queue.length > 0) {
  const file = queue.shift();
  for (const dependency of edges.get(file) || []) {
    if (!reachable.has(dependency)) {
      reachable.add(dependency);
      queue.push(dependency);
    }
  }
}

const orphans = jsFiles.filter((file) => !reachable.has(file));

const browserEntrypoints = jsFiles.filter((file) => {
  if (!file.includes('/assets/js/')) return false;
  const reasons = [...(seeds.get(file) || [])];
  return reasons.some((reason) => reason.startsWith('referenced_by:'));
});

const browserReviewRequired = ['owner', 'init', 'listeners', 'observers', 'timers', 'global_state', 'notes'];
const missingBrowserReviews = [];
for (const file of browserEntrypoints) {
  const review = registry.browser_entrypoints[file];
  if (!review || typeof review !== 'object') {
    missingBrowserReviews.push(file);
    continue;
  }
  for (const field of browserReviewRequired) {
    assert.ok(typeof review[field] === 'string' && review[field].trim().length >= 3,
      `Browser review ${file}.${field} must be explicit`);
  }
}
const staleBrowserReviews = Object.keys(registry.browser_entrypoints).filter((file) => !browserEntrypoints.includes(file));

const rawJsonConsumers = jsFiles.filter((file) => {
  if (!/^(?:scripts\/(?:lint|staging2|build)|tools\/)/.test(file)) return false;
  const source = read(file);
  return /JSON\.parse\s*\(/.test(source) && /(?:readFileSync|readFile)\s*\(/.test(source);
});
const rawReviewFields = ['duplicate_keys', 'aliases_cycles', 'structural_invariants'];
const missingRawReviews = [];
for (const file of rawJsonConsumers) {
  const review = registry.raw_json_validators[file];
  if (!review || typeof review !== 'object') {
    missingRawReviews.push(file);
    continue;
  }
  for (const field of rawReviewFields) {
    assert.ok(typeof review[field] === 'string' && review[field].trim().length >= 8,
      `Raw JSON review ${file}.${field} must state the policy or why it is not applicable`);
  }
}
const staleRawReviews = Object.keys(registry.raw_json_validators).filter((file) => !rawJsonConsumers.includes(file));

const boundedWaitConsumers = jsFiles.filter((file) => {
  if (!/^(?:scripts\/staging2|tools\/)/.test(file)) return false;
  return /(?:setTimeout\s*\(|\bsleep\s*\()/.test(read(file));
});
const missingBoundedWaitReviews = [];
for (const file of boundedWaitConsumers) {
  const review = registry.bounded_waits[file];
  if (!review || typeof review !== 'object') {
    missingBoundedWaitReviews.push(file);
    continue;
  }
  assert.ok(typeof review.reason === 'string' && review.reason.trim().length >= 8, `Bounded wait reason missing: ${file}`);
  assert.ok(typeof review.bound === 'string' && review.bound.trim().length >= 8, `Bounded wait authority missing: ${file}`);
}
const staleWaitReviews = Object.keys(registry.bounded_waits).filter((file) => !boundedWaitConsumers.includes(file));

const classifierFiles = jsFiles.filter((file) => file.startsWith('scripts/staging2/') && /classifier\.mjs$/.test(file) && !/\/test-/.test(file));
const classifierWithoutTest = classifierFiles.filter((file) => {
  const callers = [...(incoming.get(file) || [])];
  return !callers.some((caller) => /(?:^|\/)test-[^/]*\.mjs$/.test(caller));
});

for (const required of [
  'scripts/lint/test-conversion-ownership-contract.mjs',
  'scripts/lint/test-single-hubspot-mount.mjs',
  'scripts/staging2/siteground-transient-classifier.mjs',
]) {
  assert.ok(jsSet.has(required), `Required semantic owner/gate missing: ${required}`);
  assert.ok(reachable.has(required), `Required semantic owner/gate is not executable-reachable: ${required}`);
}

function printSection(label, items) {
  if (items.length === 0) return;
  console.error(`${label}_BEGIN count=${items.length}`);
  for (const item of items) console.error(item);
  console.error(`${label}_END`);
}

printSection('JS_MJS_ORPHANS', orphans);
printSection('JS_BROWSER_REVIEW_MISSING', missingBrowserReviews);
printSection('JS_BROWSER_REVIEW_STALE', staleBrowserReviews);
printSection('JS_RAW_JSON_REVIEW_MISSING', missingRawReviews);
printSection('JS_RAW_JSON_REVIEW_STALE', staleRawReviews);
printSection('JS_BOUNDED_WAIT_REVIEW_MISSING', missingBoundedWaitReviews);
printSection('JS_BOUNDED_WAIT_REVIEW_STALE', staleWaitReviews);
printSection('JS_CLASSIFIER_TEST_MISSING', classifierWithoutTest);

const failures = [
  orphans,
  missingBrowserReviews,
  staleBrowserReviews,
  missingRawReviews,
  staleRawReviews,
  missingBoundedWaitReviews,
  staleWaitReviews,
  classifierWithoutTest,
].reduce((sum, items) => sum + items.length, 0);

const classificationCounts = {
  browser_runtime: jsFiles.filter((file) => file.includes('/assets/js/')).length,
  lint_build: jsFiles.filter((file) => /^scripts\/(?:lint|build)\//.test(file)).length,
  staging_harness: jsFiles.filter((file) => file.startsWith('scripts/staging2/')).length,
  tools: jsFiles.filter((file) => file.startsWith('tools/')).length,
  other: jsFiles.filter((file) => !file.includes('/assets/js/') && !/^scripts\/(?:lint|build|staging2)\//.test(file) && !file.startsWith('tools/')).length,
};

console.log(`JS_MJS_INVENTORY total=${jsFiles.length} reachable=${reachable.size} seeds=${seeds.size} orphans=${orphans.length}`);
console.log(`JS_MJS_CLASSIFICATION ${Object.entries(classificationCounts).map(([key, value]) => `${key}=${value}`).join(' ')}`);
console.log(`JS_MJS_REVIEWS browser=${browserEntrypoints.length} raw_json=${rawJsonConsumers.length} bounded_waits=${boundedWaitConsumers.length} classifiers=${classifierFiles.length}`);

if (failures > 0) {
  console.error(`JS_MJS_SEMANTIC_AUDIT=FAIL findings=${failures}`);
  process.exit(1);
}

console.log('JS_MJS_SEMANTIC_AUDIT_COMPLETE=PASS orphan_executables=0 browser_lifecycle=reviewed raw_validators=reviewed harness_waits=bounded classifiers=tested');

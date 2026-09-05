#!/usr/bin/env node
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

const root = process.cwd();
const registry = JSON.parse(fs.readFileSync(path.join(root, 'scripts/lint/js-mjs-semantic-audit.json'), 'utf8'));
const tracked = execFileSync('git', ['ls-files', '-z'], { cwd: root, encoding: 'utf8' }).split('\0').filter(Boolean);
const phpFiles = tracked.filter((file) => file.endsWith('.php')).sort();
const browserFiles = tracked.filter((file) => file.startsWith('wp-content/themes/nuvanx-medical/assets/js/') && file.endsWith('.js'));

const sourceCache = new Map();
const read = (file) => {
  if (!sourceCache.has(file)) sourceCache.set(file, fs.readFileSync(path.join(root, file), 'utf8'));
  return sourceCache.get(file);
};

for (const file of browserFiles) {
  const review = registry.browser_entrypoints[file];
  assert.ok(review && typeof review.owner === 'string', `Missing browser owner review: ${file}`);
  const themeRelative = file.replace('wp-content/themes/nuvanx-medical', '');
  const references = (candidate) => {
    const source = read(candidate);
    return source.includes(file) || source.includes(themeRelative);
  };

  const owners = phpFiles.filter(references);
  assert.deepEqual(owners, [review.owner], `Browser runtime must have exactly one PHP asset owner: ${file}`);

  const parallelRuntimeLoaders = browserFiles.filter((candidate) => candidate !== file && references(candidate));
  assert.equal(parallelRuntimeLoaders.length, 0, `Browser asset may not dynamically reload peer runtime: ${file}`);
}

assert.equal(Object.keys(registry.browser_entrypoints).length, browserFiles.length, 'Every tracked browser runtime must have exactly one lifecycle review');
console.log(`JS_BROWSER_RUNTIME_OWNER=PASS entrypoints=${browserFiles.length} php_owner=exactly_one peer_dynamic_loaders=0`);

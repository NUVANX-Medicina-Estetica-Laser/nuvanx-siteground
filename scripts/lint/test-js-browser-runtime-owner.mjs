#!/usr/bin/env node
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

const root = process.cwd();
const tracked = execFileSync('git', ['ls-files', '-z'], { cwd: root, encoding: 'utf8' }).split('\0').filter(Boolean);
const phpFiles = tracked.filter((file) => file.endsWith('.php')).sort();
const browserFiles = tracked.filter((file) => file.startsWith('wp-content/themes/nuvanx-medical/assets/js/') && file.endsWith('.js')).sort();

const sourceCache = new Map();
const read = (file) => {
  if (!sourceCache.has(file)) sourceCache.set(file, fs.readFileSync(path.join(root, file), 'utf8'));
  return sourceCache.get(file);
};

for (const file of browserFiles) {
  const themeRelative = file.replace('wp-content/themes/nuvanx-medical', '');
  const references = (candidate) => {
    const source = read(candidate);
    return source.includes(file) || source.includes(themeRelative);
  };

  const owners = phpFiles.filter(references);
  assert.equal(owners.length, 1, `Browser runtime must have exactly one PHP asset owner: ${file}; owners=${owners.join(',')}`);

  const parallelRuntimeLoaders = browserFiles.filter((candidate) => candidate !== file && references(candidate));
  assert.equal(parallelRuntimeLoaders.length, 0, `Browser asset may not dynamically reload peer runtime: ${file}`);
}

assert.ok(browserFiles.length > 0, 'Browser runtime inventory must not be empty');
console.log(`JS_BROWSER_RUNTIME_OWNER=PASS entrypoints=${browserFiles.length} php_owner=exactly_one peer_dynamic_loaders=0`);

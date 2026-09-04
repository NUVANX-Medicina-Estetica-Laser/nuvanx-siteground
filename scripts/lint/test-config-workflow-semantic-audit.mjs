#!/usr/bin/env node

import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

const repoRoot = process.cwd();
const fail = (message) => { throw new Error(message); };

function scanJsonRaw(raw, label) {
  let i = 0;
  const pointer = [];
  const ws = () => { while (i < raw.length && /\s/.test(raw[i])) i += 1; };

  const stringToken = () => {
    const start = i;
    if (raw[i] !== '"') fail(`${label}: expected JSON string at byte ${i}`);
    i += 1;
    let escaped = false;
    while (i < raw.length) {
      const ch = raw[i];
      if (escaped) {
        escaped = false;
        i += 1;
        continue;
      }
      if (ch === '\\') {
        escaped = true;
        i += 1;
        continue;
      }
      if (ch === '"') {
        i += 1;
        const token = raw.slice(start, i);
        try {
          return JSON.parse(token);
        } catch (error) {
          fail(`${label}: invalid JSON string at byte ${start}: ${error.message}`);
        }
      }
      if (ch.charCodeAt(0) < 0x20) fail(`${label}: control character in JSON string at byte ${i}`);
      i += 1;
    }
    fail(`${label}: unterminated JSON string at byte ${start}`);
  };

  const scalarToken = () => {
    const start = i;
    while (i < raw.length && !/[\s,\]}]/.test(raw[i])) i += 1;
    const token = raw.slice(start, i);
    try {
      JSON.parse(token);
    } catch {
      fail(`${label}: invalid JSON token '${token}' at byte ${start}`);
    }
  };

  const value = () => {
    ws();
    if (i >= raw.length) fail(`${label}: unexpected EOF`);
    if (raw[i] === '{') return object();
    if (raw[i] === '[') return array();
    if (raw[i] === '"') {
      stringToken();
      return;
    }
    scalarToken();
  };

  const object = () => {
    i += 1;
    ws();
    const keys = new Set();
    if (raw[i] === '}') {
      i += 1;
      return;
    }
    while (i < raw.length) {
      ws();
      const key = stringToken();
      if (keys.has(key)) {
        const where = `/${[...pointer, key]
          .map((part) => String(part).replace(/~/g, '~0').replace(/\//g, '~1'))
          .join('/')}`;
        fail(`${label}: duplicate JSON key '${key}' at ${where}`);
      }
      keys.add(key);
      ws();
      if (raw[i] !== ':') fail(`${label}: expected ':' after key '${key}' at byte ${i}`);
      i += 1;
      pointer.push(key);
      value();
      pointer.pop();
      ws();
      if (raw[i] === '}') {
        i += 1;
        return;
      }
      if (raw[i] !== ',') fail(`${label}: expected ',' or '}' at byte ${i}`);
      i += 1;
    }
    fail(`${label}: unterminated object`);
  };

  const array = () => {
    i += 1;
    ws();
    let index = 0;
    if (raw[i] === ']') {
      i += 1;
      return;
    }
    while (i < raw.length) {
      pointer.push(index);
      value();
      pointer.pop();
      index += 1;
      ws();
      if (raw[i] === ']') {
        i += 1;
        return;
      }
      if (raw[i] !== ',') fail(`${label}: expected ',' or ']' at byte ${i}`);
      i += 1;
    }
    fail(`${label}: unterminated array`);
  };

  value();
  ws();
  if (i !== raw.length) fail(`${label}: trailing content at byte ${i}`);
}

function yamlMeaningfulLines(raw, label) {
  const out = [];
  const lines = raw.split(/\r?\n/);
  let blockParentIndent = null;

  for (let index = 0; index < lines.length; index += 1) {
    const original = lines[index];
    if (/^\t+/.test(original) || /^ +\t/.test(original)) {
      fail(`${label}:${index + 1}: tabs are forbidden in YAML indentation`);
    }
    const indent = original.match(/^ */)[0].length;
    const trimmed = original.trim();

    if (blockParentIndent !== null) {
      if (!trimmed || indent > blockParentIndent) continue;
      blockParentIndent = null;
    }
    if (!trimmed || trimmed.startsWith('#')) continue;

    out.push({ lineNo: index + 1, indent, text: original.slice(indent) });
    const mapping = original
      .slice(indent)
      .match(/^([A-Za-z0-9_.-]+):\s*([|>][+-]?\d*)\s*(?:#.*)?$/);
    if (mapping) blockParentIndent = indent;
  }

  return out;
}

function scanYamlDuplicateKeys(raw, label) {
  const lines = yamlMeaningfulLines(raw, label);
  const scopes = [];

  const resetScope = (indent) => {
    while (scopes.length && scopes.at(-1).indent > indent) scopes.pop();
    if (scopes.length && scopes.at(-1).indent === indent) scopes.pop();
    const scope = { indent, keys: new Map() };
    scopes.push(scope);
    return scope;
  };

  const scopeFor = (indent) => {
    while (scopes.length && scopes.at(-1).indent > indent) scopes.pop();
    if (!scopes.length || scopes.at(-1).indent < indent) {
      scopes.push({ indent, keys: new Map() });
    }
    return scopes.at(-1);
  };

  for (const row of lines) {
    let { indent, text } = row;
    let sequenceItem = false;

    if (text.startsWith('- ')) {
      sequenceItem = true;
      text = text.slice(2);
      indent += 2;
    } else if (text === '-') {
      resetScope(indent + 2);
      continue;
    }

    const match = text.match(/^([A-Za-z0-9_.-]+):(?:\s|$)(.*)$/);
    if (!match) {
      if (sequenceItem) resetScope(indent);
      continue;
    }

    const [, key] = match;
    const scope = sequenceItem ? resetScope(indent) : scopeFor(indent);
    if (scope.keys.has(key)) {
      fail(`${label}:${row.lineNo}: duplicate YAML key '${key}' (first declared at line ${scope.keys.get(key)})`);
    }
    scope.keys.set(key, row.lineNo);
  }
}

function workflowDispatchInputs(raw, label) {
  const lines = raw.split(/\r?\n/);
  let dispatch = -1;
  let inputs = -1;

  for (let i = 0; i < lines.length; i += 1) {
    if (/^  workflow_dispatch:\s*$/.test(lines[i])) dispatch = i;
    if (dispatch >= 0 && i > dispatch && /^    inputs:\s*$/.test(lines[i])) {
      inputs = i;
      break;
    }
    if (dispatch >= 0 && i > dispatch && /^  [A-Za-z0-9_.-]+:\s*$/.test(lines[i])) break;
  }
  if (inputs < 0) return [];

  const names = [];
  for (let i = inputs + 1; i < lines.length; i += 1) {
    if (/^    \S/.test(lines[i]) || /^  \S/.test(lines[i]) || /^\S/.test(lines[i])) break;
    const match = lines[i].match(/^      ([A-Za-z0-9_.-]+):\s*$/);
    if (match) names.push(match[1]);
  }

  for (const name of names) {
    const escapedName = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const usage = new RegExp(`(?:\\binputs|github\\.event\\.inputs)\\.${escapedName}\\b`);
    if (!usage.test(raw)) fail(`${label}: workflow_dispatch input '${name}' has no executable caller`);
  }

  return names;
}

function workflowJobs(raw, label) {
  const lines = raw.split(/\r?\n/);
  const jobsIndex = lines.findIndex((line) => /^jobs:\s*$/.test(line));
  if (jobsIndex < 0) fail(`${label}: missing top-level jobs map`);

  const jobs = [];
  for (let i = jobsIndex + 1; i < lines.length; i += 1) {
    if (/^\S/.test(lines[i]) && lines[i].trim()) break;
    const match = lines[i].match(/^  ([A-Za-z0-9_-]+):\s*$/);
    if (match) jobs.push({ name: match[1], line: i });
  }
  if (jobs.length === 0) fail(`${label}: jobs map is empty`);

  const jobNames = new Set(jobs.map((job) => job.name));
  const dependencies = new Map(jobs.map((job) => [job.name, []]));

  for (let j = 0; j < jobs.length; j += 1) {
    const start = jobs[j].line + 1;
    const end = j + 1 < jobs.length ? jobs[j + 1].line : lines.length;
    for (let i = start; i < end; i += 1) {
      const match = lines[i].match(/^    needs:\s*(.+?)\s*$/);
      if (!match) continue;

      const value = match[1].trim();
      const refs = value.startsWith('[') && value.endsWith(']')
        ? value.slice(1, -1).split(',').map((part) => part.trim()).filter(Boolean)
        : [value];

      for (const ref of refs) {
        if (!/^[A-Za-z0-9_-]+$/.test(ref)) {
          fail(`${label}:${i + 1}: unsupported dynamic needs expression '${ref}'`);
        }
        if (!jobNames.has(ref)) {
          fail(`${label}:${i + 1}: job '${jobs[j].name}' needs missing job '${ref}'`);
        }
        if (ref === jobs[j].name) {
          fail(`${label}:${i + 1}: job '${jobs[j].name}' cannot need itself`);
        }
        dependencies.get(jobs[j].name).push(ref);
      }
    }
  }

  const visiting = new Set();
  const visited = new Set();
  const visit = (job) => {
    if (visiting.has(job)) fail(`${label}: cyclic job needs graph at '${job}'`);
    if (visited.has(job)) return;
    visiting.add(job);
    for (const dependency of dependencies.get(job) || []) visit(dependency);
    visiting.delete(job);
    visited.add(job);
  };
  for (const job of jobNames) visit(job);

  return jobs.map((job) => job.name);
}

function runSelfTests() {
  scanJsonRaw('{"a":1,"b":{"c":2}}', 'selftest-valid-json');
  let caught = false;
  try {
    scanJsonRaw('{"a":1,"nested":{"x":1,"x":2}}', 'selftest-json');
  } catch (error) {
    caught = /duplicate JSON key 'x'/.test(error.message);
  }
  if (!caught) fail('selftest: nested duplicate JSON key was not rejected');

  scanYamlDuplicateKeys(
    'jobs:\n  a:\n    name: A\n    steps:\n      - name: one\n        run: echo one\n      - name: two\n        run: echo two\n',
    'selftest-valid-yaml',
  );
  caught = false;
  try {
    scanYamlDuplicateKeys('jobs:\n  a:\n    name: A\n    name: B\n', 'selftest-yaml');
  } catch (error) {
    caught = /duplicate YAML key 'name'/.test(error.message);
  }
  if (!caught) fail('selftest: duplicate YAML key was not rejected');
}

runSelfTests();

const tracked = execFileSync('git', ['ls-files', '-z'], {
  cwd: repoRoot,
  encoding: 'utf8',
}).split('\0').filter(Boolean);
const jsonFiles = tracked.filter((file) => file.endsWith('.json'));
const workflowFiles = tracked
  .filter((file) => /^\.github\/workflows\/.*\.ya?ml$/i.test(file))
  .sort();

if (workflowFiles.join(' ') !== '.github/workflows/production.yml .github/workflows/staging.yml') {
  fail(`Canonical workflow ownership drift: ${workflowFiles.join(' ') || '(none)'}`);
}

for (const file of jsonFiles) {
  scanJsonRaw(fs.readFileSync(path.join(repoRoot, file), 'utf8'), file);
}

let inputCount = 0;
let jobCount = 0;
for (const file of workflowFiles) {
  const raw = fs.readFileSync(path.join(repoRoot, file), 'utf8');
  scanYamlDuplicateKeys(raw, file);
  inputCount += workflowDispatchInputs(raw, file).length;
  jobCount += workflowJobs(raw, file).length;
}

console.log(
  `CONFIG_WORKFLOW_RAW_SOURCE_AUDIT=PASS json=${jsonFiles.length} workflows=${workflowFiles.length} inputs=${inputCount} jobs=${jobCount}`,
);

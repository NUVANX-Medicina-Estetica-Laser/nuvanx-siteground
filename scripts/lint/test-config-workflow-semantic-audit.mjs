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

function stripYamlComment(text) {
  let quote = null;
  let escaped = false;
  for (let i = 0; i < text.length; i += 1) {
    const ch = text[i];
    if (quote === '"') {
      if (escaped) {
        escaped = false;
      } else if (ch === '\\') {
        escaped = true;
      } else if (ch === '"') {
        quote = null;
      }
      continue;
    }
    if (quote === "'") {
      if (ch === "'" && text[i + 1] === "'") {
        i += 1;
      } else if (ch === "'") {
        quote = null;
      }
      continue;
    }
    if (ch === '"' || ch === "'") {
      quote = ch;
      continue;
    }
    if (ch === '#' && (i === 0 || /\s/.test(text[i - 1]))) {
      return text.slice(0, i).trimEnd();
    }
  }
  return text.trimEnd();
}

function decodeYamlKey(token, label, lineNo) {
  const value = token.trim();
  if (!value) fail(`${label}:${lineNo}: empty YAML mapping key`);
  if (value.startsWith("'")) {
    if (!value.endsWith("'") || value.length < 2) fail(`${label}:${lineNo}: unterminated single-quoted YAML key`);
    return value.slice(1, -1).replace(/''/g, "'");
  }
  if (value.startsWith('"')) {
    if (!value.endsWith('"') || value.length < 2) fail(`${label}:${lineNo}: unterminated double-quoted YAML key`);
    try {
      return JSON.parse(value);
    } catch (error) {
      fail(`${label}:${lineNo}: unsupported double-quoted YAML key: ${error.message}`);
    }
  }
  return value;
}

function findYamlSeparator(text, separator = ':') {
  let quote = null;
  let escaped = false;
  let square = 0;
  let curly = 0;
  for (let i = 0; i < text.length; i += 1) {
    const ch = text[i];
    if (quote === '"') {
      if (escaped) escaped = false;
      else if (ch === '\\') escaped = true;
      else if (ch === '"') quote = null;
      continue;
    }
    if (quote === "'") {
      if (ch === "'" && text[i + 1] === "'") i += 1;
      else if (ch === "'") quote = null;
      continue;
    }
    if (ch === '"' || ch === "'") {
      quote = ch;
      continue;
    }
    if (ch === '[') square += 1;
    else if (ch === ']') square = Math.max(0, square - 1);
    else if (ch === '{') curly += 1;
    else if (ch === '}') curly = Math.max(0, curly - 1);
    else if (ch === separator && square === 0 && curly === 0) return i;
  }
  return -1;
}

function parseLeadingMapping(text, label, lineNo) {
  const clean = stripYamlComment(text);
  const colon = findYamlSeparator(clean, ':');
  if (colon < 0) return null;
  const keyToken = clean.slice(0, colon).trim();
  if (!keyToken || keyToken.startsWith('?')) return null;
  const key = decodeYamlKey(keyToken, label, lineNo);
  return { key, value: clean.slice(colon + 1).trim() };
}

function splitFlowItems(text) {
  const items = [];
  let start = 0;
  let quote = null;
  let escaped = false;
  let square = 0;
  let curly = 0;
  for (let i = 0; i < text.length; i += 1) {
    const ch = text[i];
    if (quote === '"') {
      if (escaped) escaped = false;
      else if (ch === '\\') escaped = true;
      else if (ch === '"') quote = null;
      continue;
    }
    if (quote === "'") {
      if (ch === "'" && text[i + 1] === "'") i += 1;
      else if (ch === "'") quote = null;
      continue;
    }
    if (ch === '"' || ch === "'") {
      quote = ch;
      continue;
    }
    if (ch === '[') square += 1;
    else if (ch === ']') square -= 1;
    else if (ch === '{') curly += 1;
    else if (ch === '}') curly -= 1;
    else if (ch === ',' && square === 0 && curly === 0) {
      items.push(text.slice(start, i).trim());
      start = i + 1;
    }
  }
  items.push(text.slice(start).trim());
  return items.filter(Boolean);
}

function maskGithubExpressions(text) {
  const chars = [...text];
  for (let i = 0; i < text.length - 2; i += 1) {
    if (text.slice(i, i + 3) !== '${{') continue;
    const end = text.indexOf('}}', i + 3);
    if (end < 0) break;
    for (let j = i; j < end + 2; j += 1) chars[j] = ' ';
    i = end + 1;
  }
  return chars.join('');
}

function scanFlowMapDuplicates(text, label, lineNo) {
  const source = maskGithubExpressions(stripYamlComment(text));
  const stack = [];
  let quote = null;
  let escaped = false;
  for (let i = 0; i < source.length; i += 1) {
    const ch = source[i];
    if (quote === '"') {
      if (escaped) escaped = false;
      else if (ch === '\\') escaped = true;
      else if (ch === '"') quote = null;
      continue;
    }
    if (quote === "'") {
      if (ch === "'" && source[i + 1] === "'") i += 1;
      else if (ch === "'") quote = null;
      continue;
    }
    if (ch === '"' || ch === "'") {
      quote = ch;
      continue;
    }
    if (ch === '{') {
      stack.push(i);
      continue;
    }
    if (ch !== '}' || stack.length === 0) continue;
    const start = stack.pop();
    const inner = source.slice(start + 1, i);
    const items = splitFlowItems(inner);
    const keys = new Map();
    for (const item of items) {
      const colon = findYamlSeparator(item, ':');
      if (colon < 0) continue;
      const key = decodeYamlKey(item.slice(0, colon), label, lineNo);
      if (keys.has(key)) {
        fail(`${label}:${lineNo}: duplicate YAML flow key '${key}'`);
      }
      keys.set(key, true);
    }
  }
  if (stack.length > 0) fail(`${label}:${lineNo}: unbalanced YAML flow mapping`);
}

function yamlStructuralRows(raw, label) {
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

    const text = original.slice(indent);
    const clean = stripYamlComment(text);
    if (!clean.trim()) continue;
    scanFlowMapDuplicates(clean, label, index + 1);
    out.push({ lineNo: index + 1, indent, text, clean });

    const mapping = parseLeadingMapping(clean.startsWith('- ') ? clean.slice(2) : clean, label, index + 1);
    if (mapping && /^[|>][+-]?\d*$/.test(mapping.value)) blockParentIndent = indent;
  }

  return out;
}

function scanYamlDuplicateKeys(raw, label) {
  const rows = yamlStructuralRows(raw, label);
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

  for (const row of rows) {
    let { indent, clean } = row;
    let sequenceItem = false;

    if (clean.startsWith('- ')) {
      sequenceItem = true;
      clean = clean.slice(2).trimStart();
      indent += 2;
    } else if (clean === '-') {
      resetScope(indent + 2);
      continue;
    }

    const mapping = parseLeadingMapping(clean, label, row.lineNo);
    if (!mapping) {
      if (sequenceItem) resetScope(indent);
      continue;
    }

    const scope = sequenceItem ? resetScope(indent) : scopeFor(indent);
    if (scope.keys.has(mapping.key)) {
      fail(`${label}:${row.lineNo}: duplicate YAML key '${mapping.key}' (first declared at line ${scope.keys.get(mapping.key)})`);
    }
    scope.keys.set(mapping.key, row.lineNo);
  }
}

function executableInputReferences(rows, label) {
  const refs = new Set();
  const addRefs = (expression) => {
    const re = /(?:\binputs|github\.event\.inputs)\.([A-Za-z0-9_.-]+)\b/g;
    let match;
    while ((match = re.exec(expression)) !== null) refs.add(match[1]);
  };

  for (const row of rows) {
    let clean = row.clean;
    if (clean.startsWith('- ')) clean = clean.slice(2).trimStart();
    const mapping = parseLeadingMapping(clean, label, row.lineNo);
    if (mapping && mapping.key === 'description') continue;

    const expressionRe = /\$\{\{([\s\S]*?)\}\}/g;
    let expressionMatch;
    while ((expressionMatch = expressionRe.exec(clean)) !== null) addRefs(expressionMatch[1]);

    if (mapping && mapping.key === 'if' && !mapping.value.includes('${{')) {
      addRefs(mapping.value);
    }
  }

  return refs;
}

function workflowDispatchInputs(raw, label) {
  const rows = yamlStructuralRows(raw, label);
  let inDispatch = false;
  let inInputs = false;
  const names = [];

  for (const row of rows) {
    const mapping = parseLeadingMapping(row.clean, label, row.lineNo);
    if (!mapping) continue;

    if (row.indent === 2 && mapping.key === 'workflow_dispatch') {
      inDispatch = true;
      inInputs = false;
      continue;
    }
    if (inDispatch && row.indent <= 2) {
      inDispatch = false;
      inInputs = false;
    }
    if (inDispatch && row.indent === 4 && mapping.key === 'inputs') {
      inInputs = true;
      continue;
    }
    if (inInputs && row.indent <= 4) {
      inInputs = false;
    }
    if (inInputs && row.indent === 6) names.push(mapping.key);
  }

  const refs = executableInputReferences(rows, label);
  for (const name of names) {
    if (!refs.has(name)) fail(`${label}: workflow_dispatch input '${name}' has no executable caller`);
  }

  return names;
}

function normalizeNeedRef(value) {
  const ref = value.trim();
  if ((ref.startsWith("'") && ref.endsWith("'")) || (ref.startsWith('"') && ref.endsWith('"'))) {
    return decodeYamlKey(ref, 'workflow-needs', 0);
  }
  return ref;
}

function workflowJobs(raw, label) {
  const rows = yamlStructuralRows(raw, label);
  const jobsRowIndex = rows.findIndex((row) => {
    const mapping = row.indent === 0 ? parseLeadingMapping(row.clean, label, row.lineNo) : null;
    return mapping?.key === 'jobs';
  });
  if (jobsRowIndex < 0) fail(`${label}: missing top-level jobs map`);

  const jobs = [];
  for (let i = jobsRowIndex + 1; i < rows.length; i += 1) {
    const row = rows[i];
    if (row.indent === 0) break;
    if (row.indent !== 2) continue;
    const mapping = parseLeadingMapping(row.clean, label, row.lineNo);
    if (mapping) jobs.push({ name: mapping.key, rowIndex: i, lineNo: row.lineNo });
  }
  if (jobs.length === 0) fail(`${label}: jobs map is empty`);

  const jobNames = new Set(jobs.map((job) => job.name));
  const dependencies = new Map(jobs.map((job) => [job.name, []]));

  const validateRef = (jobName, ref, lineNo) => {
    const normalized = normalizeNeedRef(ref);
    if (!/^[A-Za-z0-9_-]+$/.test(normalized)) {
      fail(`${label}:${lineNo}: unsupported dynamic needs expression '${normalized}'`);
    }
    if (!jobNames.has(normalized)) {
      fail(`${label}:${lineNo}: job '${jobName}' needs missing job '${normalized}'`);
    }
    if (normalized === jobName) {
      fail(`${label}:${lineNo}: job '${jobName}' cannot need itself`);
    }
    dependencies.get(jobName).push(normalized);
  };

  for (let j = 0; j < jobs.length; j += 1) {
    const start = jobs[j].rowIndex + 1;
    const end = j + 1 < jobs.length ? jobs[j + 1].rowIndex : rows.length;
    for (let i = start; i < end; i += 1) {
      const row = rows[i];
      if (row.indent !== 4) continue;
      const mapping = parseLeadingMapping(row.clean, label, row.lineNo);
      if (!mapping || mapping.key !== 'needs') continue;

      if (mapping.value) {
        if (mapping.value.startsWith('[') && mapping.value.endsWith(']')) {
          for (const ref of splitFlowItems(mapping.value.slice(1, -1))) {
            validateRef(jobs[j].name, ref, row.lineNo);
          }
        } else {
          validateRef(jobs[j].name, mapping.value, row.lineNo);
        }
        continue;
      }

      let foundBlockRef = false;
      for (let k = i + 1; k < end; k += 1) {
        const child = rows[k];
        if (child.indent <= 4) break;
        if (child.indent !== 6 || !child.clean.startsWith('- ')) continue;
        const ref = stripYamlComment(child.clean.slice(2)).trim();
        if (!ref) continue;
        foundBlockRef = true;
        validateRef(jobs[j].name, ref, child.lineNo);
      }
      if (!foundBlockRef) fail(`${label}:${row.lineNo}: job '${jobs[j].name}' declares empty needs`);
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

function expectFailure(fn, pattern, name) {
  let caught = false;
  try {
    fn();
  } catch (error) {
    caught = pattern.test(error.message);
  }
  if (!caught) fail(`selftest: ${name}`);
}

function runSelfTests() {
  scanJsonRaw('{"a":1,"b":{"c":2}}', 'selftest-valid-json');
  expectFailure(
    () => scanJsonRaw('{"a":1,"nested":{"x":1,"x":2}}', 'selftest-json'),
    /duplicate JSON key 'x'/,
    'nested duplicate JSON key was not rejected',
  );

  scanYamlDuplicateKeys(
    'jobs:\n  a:\n    name: A\n    steps:\n      - name: one\n        run: echo one\n      - name: two\n        run: echo two\n',
    'selftest-valid-yaml',
  );
  expectFailure(
    () => scanYamlDuplicateKeys('jobs:\n  a:\n    "name": A\n    "name": B\n', 'selftest-quoted-yaml'),
    /duplicate YAML key 'name'/,
    'quoted duplicate YAML key was not rejected',
  );
  expectFailure(
    () => scanYamlDuplicateKeys('root: {name: one, "name": two}\n', 'selftest-flow-yaml'),
    /duplicate YAML flow key 'name'/,
    'flow-map duplicate YAML key was not rejected',
  );

  const validInputs = [
    'on:',
    '  workflow_dispatch:',
    '    inputs:',
    '      live:',
    '        description: Live input',
    'jobs:',
    '  a:',
    "    if: inputs.live == 'yes' # executable use",
    '    runs-on: ubuntu-latest',
  ].join('\n');
  workflowDispatchInputs(validInputs, 'selftest-valid-input');

  const deadInput = [
    'on:',
    '  workflow_dispatch:',
    '    inputs:',
    '      dead:',
    '        description: "${{ inputs.dead }} is only documentation"',
    'jobs:',
    '  a:',
    '    runs-on: ubuntu-latest',
  ].join('\n');
  expectFailure(
    () => workflowDispatchInputs(deadInput, 'selftest-dead-input'),
    /input 'dead' has no executable caller/,
    'description-only input reference was accepted',
  );

  workflowJobs(
    [
      'jobs:',
      '  build:',
      '    runs-on: ubuntu-latest',
      '  release:',
      '    needs: build # valid inline comment',
      '    runs-on: ubuntu-latest',
      '  audit:',
      '    needs:',
      '      - build',
      '      - release',
      '    runs-on: ubuntu-latest',
    ].join('\n'),
    'selftest-valid-needs',
  );
  expectFailure(
    () => workflowJobs(
      [
        'jobs:',
        '  build:',
        '    needs:',
        '      - missing',
        '    runs-on: ubuntu-latest',
      ].join('\n'),
      'selftest-block-needs',
    ),
    /needs missing job 'missing'/,
    'block-list missing dependency was not rejected',
  );
  expectFailure(
    () => workflowJobs(
      [
        'jobs:',
        '  a:',
        '    needs: b',
        '    runs-on: ubuntu-latest',
        '  b:',
        '    needs:',
        '      - a',
        '    runs-on: ubuntu-latest',
      ].join('\n'),
      'selftest-cycle-needs',
    ),
    /cyclic job needs graph/,
    'block-list dependency cycle was not rejected',
  );
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

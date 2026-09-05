#!/usr/bin/env node

import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

const repoRoot = process.cwd();
const fail = (message) => { throw new Error(message); };
const JSON_WS = new Set([' ', '\t', '\r', '\n']);
const isJsonWs = (ch) => JSON_WS.has(ch);
const isPlainKey = (key) => /^[A-Za-z0-9_.-]+$/.test(key);
const isCanonicalInputName = (name) => /^[A-Za-z_][A-Za-z0-9_]*$/.test(name);
const isBlockScalarHeader = (value) => /^[|>](?:(?:[+-][1-9]?)|(?:[1-9][+-]?))?$/.test(value.trim());

function scanJsonRaw(raw, label) {
  let i = 0;
  const pointer = [];
  const ws = () => { while (i < raw.length && isJsonWs(raw[i])) i += 1; };

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
    while (
      i < raw.length
      && !isJsonWs(raw[i])
      && raw[i] !== ','
      && raw[i] !== ']'
      && raw[i] !== '}'
    ) i += 1;
    const token = raw.slice(start, i);
    if (!token) fail(`${label}: empty JSON token at byte ${start}`);
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
  try {
    JSON.parse(raw);
  } catch (error) {
    fail(`${label}: JSON.parse rejected source after raw audit: ${error.message}`);
  }
}

function stripYamlComment(text) {
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
    else if (ch === '#' && square === 0 && curly === 0 && (i === 0 || /\s/.test(text[i - 1]))) {
      return text.slice(0, i).trimEnd();
    }
  }
  return text.trimEnd();
}

function maskGithubExpressions(text) {
  const chars = [...text];
  let cursor = 0;
  while (cursor < text.length) {
    const start = text.indexOf('${{', cursor);
    if (start < 0) break;
    const end = text.indexOf('}}', start + 3);
    if (end < 0) fail('workflow: unterminated GitHub expression');
    for (let i = start; i < end + 2; i += 1) chars[i] = ' ';
    cursor = end + 2;
  }
  return chars.join('');
}

function findTopLevelColon(text) {
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
    else if (ch === ':' && square === 0 && curly === 0) return i;
  }
  return -1;
}

function parsePlainMapping(text, label, lineNo) {
  const clean = stripYamlComment(text);
  const colon = findTopLevelColon(clean);
  if (colon < 0) return null;
  const keyToken = clean.slice(0, colon).trim();
  if (!keyToken) fail(`${label}:${lineNo}: empty YAML mapping key`);
  if (!isPlainKey(keyToken)) {
    fail(`${label}:${lineNo}: non-canonical YAML mapping key '${keyToken}'; workflow keys must be plain [A-Za-z0-9_.-]+`);
  }
  return { key: keyToken, value: clean.slice(colon + 1).trim() };
}

function assertCanonicalStructuralSyntax(clean, label, lineNo) {
  const masked = maskGithubExpressions(clean);
  const trimmed = masked.trim();
  if (trimmed === '---' || trimmed === '...') fail(`${label}:${lineNo}: multi-document YAML is forbidden`);
  if (/^(?:-|\s)*[?]/.test(trimmed)) fail(`${label}:${lineNo}: explicit/complex YAML mapping keys are forbidden`);
  if (trimmed.includes('{') || trimmed.includes('}')) fail(`${label}:${lineNo}: YAML flow mappings are forbidden; use block mappings`);
  if (/(^|\s)<<\s*:/.test(trimmed)) fail(`${label}:${lineNo}: YAML merge keys are forbidden`);
  if (/(^|[\s:\[,])&[A-Za-z0-9_-]+\b/.test(trimmed) || /(^|[\s:\[,])\*[A-Za-z0-9_-]+\b/.test(trimmed)) {
    fail(`${label}:${lineNo}: YAML anchors/aliases are forbidden in canonical workflows`);
  }
}

function structuralRows(raw, label) {
  const lines = raw.split(/\r?\n/);
  const rows = [];
  let blockParentIndent = null;

  for (let i = 0; i < lines.length; i += 1) {
    const original = lines[i];
    if (/^\t+/.test(original) || /^ +\t/.test(original)) fail(`${label}:${i + 1}: tabs are forbidden in YAML indentation`);
    const indent = original.match(/^ */)[0].length;
    const trimmed = original.trim();

    if (blockParentIndent !== null) {
      if (!trimmed || indent > blockParentIndent) continue;
      blockParentIndent = null;
    }
    if (!trimmed || trimmed.startsWith('#')) continue;
    if (indent % 2 !== 0) fail(`${label}:${i + 1}: canonical workflow indentation must use multiples of two spaces`);

    const clean = stripYamlComment(original.slice(indent));
    if (!clean.trim()) continue;
    assertCanonicalStructuralSyntax(clean, label, i + 1);
    rows.push({ lineNo: i + 1, indent, clean });

    const mappingText = clean.startsWith('- ') ? clean.slice(2).trimStart() : clean;
    const mapping = parsePlainMapping(mappingText, label, i + 1);
    if (mapping && isBlockScalarHeader(mapping.value)) blockParentIndent = indent;
  }
  return rows;
}

function scanYamlDuplicateKeys(raw, label) {
  const rows = structuralRows(raw, label);
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
    if (!scopes.length || scopes.at(-1).indent < indent) scopes.push({ indent, keys: new Map() });
    return scopes.at(-1);
  };

  for (const row of rows) {
    let { indent, clean } = row;
    let sequenceItem = false;
    if (clean === '-') {
      resetScope(indent + 2);
      continue;
    }
    if (clean.startsWith('- ')) {
      sequenceItem = true;
      clean = clean.slice(2).trimStart();
      indent += 2;
    }
    const mapping = parsePlainMapping(clean, label, row.lineNo);
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
  return rows;
}

function collectBlockBody(lines, startIndex, parentIndent) {
  const body = [];
  let i = startIndex;
  while (i + 1 < lines.length) {
    const next = lines[i + 1];
    const nextTrimmed = next.trim();
    const nextIndent = next.match(/^ */)[0].length;
    if (nextTrimmed && nextIndent <= parentIndent) break;
    i += 1;
    if (nextTrimmed) body.push(next.slice(nextIndent));
  }
  return { body: body.join('\n'), endIndex: i };
}

function executableInputReferences(raw, label) {
  const refs = new Set();
  const lines = raw.split(/\r?\n/);
  const sanitized = [];
  const addRefs = (text) => {
    const dot = /(?:\binputs|github\.event\.inputs)\.([A-Za-z_][A-Za-z0-9_]*)\b/g;
    let match;
    while ((match = dot.exec(text)) !== null) refs.add(match[1]);
  };

  for (let i = 0; i < lines.length; i += 1) {
    const original = lines[i];
    const indent = original.match(/^ */)[0].length;
    const clean = stripYamlComment(original.slice(indent));
    if (!clean.trim()) {
      sanitized.push('');
      continue;
    }
    const mappingText = clean.startsWith('- ') ? clean.slice(2).trimStart() : clean;
    const mapping = parsePlainMapping(mappingText, label, i + 1);

    if (mapping?.key === 'description') {
      if (isBlockScalarHeader(mapping.value)) {
        const block = collectBlockBody(lines, i, indent);
        for (let j = i; j <= block.endIndex; j += 1) sanitized.push('');
        i = block.endIndex;
      } else sanitized.push('');
      continue;
    }

    if (mapping?.key === 'if' && isBlockScalarHeader(mapping.value)) {
      const block = collectBlockBody(lines, i, indent);
      addRefs(block.body);
      sanitized.push(clean);
      for (let j = i + 1; j <= block.endIndex; j += 1) sanitized.push(lines[j]);
      i = block.endIndex;
      continue;
    }

    if (mapping?.key === 'if' && mapping.value && !mapping.value.includes('${{')) addRefs(mapping.value);
    sanitized.push(stripYamlComment(original));
  }

  const source = sanitized.join('\n');
  const expressionRe = /\$\{\{([\s\S]*?)\}\}/g;
  let expression;
  while ((expression = expressionRe.exec(source)) !== null) addRefs(expression[1]);
  return refs;
}

function workflowDispatchInputs(raw, label) {
  const rows = structuralRows(raw, label);
  let inDispatch = false;
  let inInputs = false;
  const names = [];

  for (const row of rows) {
    const mappingText = row.clean.startsWith('- ') ? row.clean.slice(2).trimStart() : row.clean;
    const mapping = parsePlainMapping(mappingText, label, row.lineNo);
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
    if (inInputs && row.indent <= 4) inInputs = false;
    if (inInputs && row.indent === 6) {
      if (!isCanonicalInputName(mapping.key)) {
        fail(`${label}:${row.lineNo}: workflow_dispatch input '${mapping.key}' must use canonical identifier syntax`);
      }
      names.push(mapping.key);
    }
  }

  const refs = executableInputReferences(raw, label);
  for (const name of names) {
    if (!refs.has(name)) fail(`${label}: workflow_dispatch input '${name}' has no executable caller`);
  }
  return names;
}

function splitFlowSequence(value, label, lineNo) {
  const source = stripYamlComment(value).trim();
  if (!source.startsWith('[') || !source.endsWith(']')) return null;
  const inner = source.slice(1, -1).trim();
  if (!inner) return [];
  const refs = inner.split(',').map((part) => part.trim()).filter(Boolean);
  for (const ref of refs) {
    if (!/^[A-Za-z0-9_-]+$/.test(ref)) fail(`${label}:${lineNo}: non-canonical flow-sequence dependency '${ref}'`);
  }
  return refs;
}

function workflowJobs(raw, label) {
  const rows = structuralRows(raw, label);
  const lines = raw.split(/\r?\n/);
  const jobsRoot = rows.findIndex((row) => row.indent === 0 && parsePlainMapping(row.clean, label, row.lineNo)?.key === 'jobs');
  if (jobsRoot < 0) fail(`${label}: missing top-level jobs map`);

  const jobs = [];
  for (let i = jobsRoot + 1; i < rows.length; i += 1) {
    const row = rows[i];
    if (row.indent === 0) break;
    if (row.indent !== 2) continue;
    const mapping = parsePlainMapping(row.clean, label, row.lineNo);
    if (mapping) jobs.push({ name: mapping.key, rowIndex: i, lineNo: row.lineNo });
  }
  if (!jobs.length) fail(`${label}: jobs map is empty`);

  const jobNames = new Set(jobs.map((job) => job.name));
  if (jobNames.size !== jobs.length) fail(`${label}: duplicate semantic job IDs detected`);
  const dependencies = new Map(jobs.map((job) => [job.name, []]));

  const addDependency = (job, ref, lineNo) => {
    if (!/^[A-Za-z0-9_-]+$/.test(ref)) fail(`${label}:${lineNo}: unsupported needs reference '${ref}'`);
    if (!jobNames.has(ref)) fail(`${label}:${lineNo}: job '${job}' needs missing job '${ref}'`);
    if (ref === job) fail(`${label}:${lineNo}: job '${job}' cannot need itself`);
    if (dependencies.get(job).includes(ref)) fail(`${label}:${lineNo}: job '${job}' duplicates needs '${ref}'`);
    dependencies.get(job).push(ref);
  };

  for (let j = 0; j < jobs.length; j += 1) {
    const start = jobs[j].rowIndex + 1;
    const end = j + 1 < jobs.length ? jobs[j + 1].rowIndex : rows.length;
    for (let i = start; i < end; i += 1) {
      const row = rows[i];
      if (row.indent !== 4) continue;
      const mapping = parsePlainMapping(row.clean, label, row.lineNo);
      if (!mapping || mapping.key !== 'needs') continue;
      if (mapping.value) {
        const flow = splitFlowSequence(mapping.value, label, row.lineNo);
        if (flow !== null) {
          for (const ref of flow) addDependency(jobs[j].name, ref, row.lineNo);
        } else addDependency(jobs[j].name, mapping.value, row.lineNo);
        continue;
      }
      let found = false;
      for (let k = i + 1; k < end; k += 1) {
        const child = rows[k];
        if (child.indent <= 4) break;
        if (child.indent !== 6 || !child.clean.startsWith('- ')) continue;
        const ref = stripYamlComment(child.clean.slice(2)).trim();
        if (!ref) continue;
        found = true;
        addDependency(jobs[j].name, ref, child.lineNo);
      }
      if (!found) fail(`${label}:${row.lineNo}: job '${jobs[j].name}' declares empty needs`);
    }
  }

  const visiting = new Set();
  const visited = new Set();
  const visit = (job) => {
    if (visiting.has(job)) fail(`${label}: cyclic job needs graph at '${job}'`);
    if (visited.has(job)) return;
    visiting.add(job);
    for (const dependency of dependencies.get(job)) visit(dependency);
    visiting.delete(job);
    visited.add(job);
  };
  for (const job of jobNames) visit(job);

  for (let j = 0; j < jobs.length; j += 1) {
    const startLine = jobs[j].lineNo;
    const endLine = j + 1 < jobs.length ? jobs[j + 1].lineNo - 1 : lines.length;
    const jobText = lines.slice(startLine - 1, endLine).join('\n');
    const expressionRefs = new Set();
    const refRe = /\bneeds\.([A-Za-z0-9_-]+)\b/g;
    let match;
    while ((match = refRe.exec(jobText)) !== null) expressionRefs.add(match[1]);
    for (const ref of expressionRefs) {
      if (!jobNames.has(ref)) fail(`${label}:${startLine}: job '${jobs[j].name}' references unknown needs context '${ref}'`);
      if (!dependencies.get(jobs[j].name).includes(ref)) {
        fail(`${label}:${startLine}: job '${jobs[j].name}' references needs.${ref} without declaring '${ref}' in needs`);
      }
    }
  }

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
  expectFailure(() => scanJsonRaw('{"a":1,"nested":{"x":1,"x":2}}', 'selftest-json-dup'), /duplicate JSON key 'x'/, 'nested duplicate JSON key was not rejected');
  expectFailure(() => scanJsonRaw('{"a":1,\u00a0"b":2}', 'selftest-json-nbsp-middle'), /expected JSON string|invalid JSON token|expected ','|trailing content/, 'NBSP between JSON tokens was accepted');
  expectFailure(() => scanJsonRaw('{"a":1}\u00a0', 'selftest-json-nbsp-tail'), /trailing content/, 'NBSP after JSON root was accepted');

  scanYamlDuplicateKeys('jobs:\n  a:\n    name: A\n    steps:\n      - name: one\n        run: echo one\n      - name: two\n        run: echo two\n', 'selftest-valid-yaml');
  expectFailure(() => scanYamlDuplicateKeys('jobs:\n  a:\n    name: A\n    name: B\n', 'selftest-yaml-dup'), /duplicate YAML key 'name'/, 'duplicate YAML key was not rejected');
  expectFailure(() => scanYamlDuplicateKeys('jobs:\n  "a":\n    name: A\n', 'selftest-quoted-key'), /non-canonical YAML mapping key/, 'quoted YAML mapping key was accepted');
  expectFailure(() => scanYamlDuplicateKeys('root: {name: one, name: two}\n', 'selftest-flow-map'), /flow mappings are forbidden/, 'YAML flow mapping was accepted');

  const liveInput = ['on:', '  workflow_dispatch:', '    inputs:', '      live:', '        description: Live input', 'jobs:', '  a:', "    if: inputs.live == 'yes'", '    runs-on: ubuntu-latest'].join('\n');
  workflowDispatchInputs(liveInput, 'selftest-live-input');
  const multilineInput = ['on:', '  workflow_dispatch:', '    inputs:', '      live:', '        description: Live input', 'jobs:', '  a:', '    if: >-', '      always() &&', '      inputs.live &&', '      true', '    runs-on: ubuntu-latest'].join('\n');
  workflowDispatchInputs(multilineInput, 'selftest-multiline-input');
  const deadDescription = ['on:', '  workflow_dispatch:', '    inputs:', '      dead:', '        description: >2-', '          ${{ inputs.dead }} appears only in documentation', 'jobs:', '  a:', '    runs-on: ubuntu-latest'].join('\n');
  expectFailure(() => workflowDispatchInputs(deadDescription, 'selftest-dead-description'), /input 'dead' has no executable caller/, 'description-only >2- reference was accepted');
  const runExpression = ['on:', '  workflow_dispatch:', '    inputs:', '      live:', '        description: Live input', 'jobs:', '  a:', '    runs-on: ubuntu-latest', '    steps:', '      - run: |', '          echo "${{ inputs.live }}"'].join('\n');
  workflowDispatchInputs(runExpression, 'selftest-run-expression');

  workflowJobs(['jobs:', '  build:', '    runs-on: ubuntu-latest', '  release:', '    needs: build # wait', '    runs-on: ubuntu-latest', '  audit:', '    needs:', '      - build', '      - release', '    if: >-', '      always() &&', "      needs.release.result == 'success'", '    runs-on: ubuntu-latest'].join('\n'), 'selftest-valid-needs');
  expectFailure(() => workflowJobs(['jobs:', '  build:', '    needs:', '      - missing', '    runs-on: ubuntu-latest'].join('\n'), 'selftest-missing-needs'), /needs missing job 'missing'/, 'missing block dependency was not rejected');
  expectFailure(() => workflowJobs(['jobs:', '  a:', '    needs: b', '    runs-on: ubuntu-latest', '  b:', '    needs: a', '    runs-on: ubuntu-latest'].join('\n'), 'selftest-cycle'), /cyclic job needs graph/, 'dependency cycle was not rejected');
  expectFailure(() => workflowJobs(['jobs:', '  a:', '    runs-on: ubuntu-latest', '  b:', '    if: needs.a.result == \'success\'', '    runs-on: ubuntu-latest'].join('\n'), 'selftest-undeclared-needs-context'), /without declaring 'a' in needs/, 'undeclared needs context was accepted');
}

runSelfTests();

const tracked = execFileSync('git', ['ls-files', '-z'], { cwd: repoRoot, encoding: 'utf8' }).split('\0').filter(Boolean);
const jsonFiles = tracked.filter((file) => file.endsWith('.json'));
const workflowFiles = tracked.filter((file) => /^\.github\/workflows\/.*\.ya?ml$/i.test(file)).sort();

if (workflowFiles.join(' ') !== '.github/workflows/production.yml .github/workflows/staging.yml') {
  fail(`Canonical workflow ownership drift: ${workflowFiles.join(' ') || '(none)'}`);
}
for (const file of jsonFiles) scanJsonRaw(fs.readFileSync(path.join(repoRoot, file), 'utf8'), file);

let inputCount = 0;
let jobCount = 0;
for (const file of workflowFiles) {
  const raw = fs.readFileSync(path.join(repoRoot, file), 'utf8');
  scanYamlDuplicateKeys(raw, file);
  inputCount += workflowDispatchInputs(raw, file).length;
  jobCount += workflowJobs(raw, file).length;
}

console.log(`CONFIG_WORKFLOW_RAW_SOURCE_AUDIT=PASS json=${jsonFiles.length} workflows=${workflowFiles.length} inputs=${inputCount} jobs=${jobCount}`);

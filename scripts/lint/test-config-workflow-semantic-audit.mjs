#!/usr/bin/env node

import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

const repoRoot = process.cwd();
const fail = (message) => { throw new Error(message); };

const JSON_WS = new Set([' ', '\t', '\r', '\n']);
const PLAIN_KEY_RE = /^[A-Za-z0-9_.-]+$/;
const INPUT_RE = /^[A-Za-z_][A-Za-z0-9_]*$/;
const JOB_RE = /^[A-Za-z0-9_-]+$/;
const BLOCK_HEADER_RE = /^[|>](?:[+-]|[1-9]|[+-][1-9]|[1-9][+-])?$/;

function scanJsonRaw(raw, label) {
  let i = 0;
  const pointer = [];
  const ws = () => { while (i < raw.length && JSON_WS.has(raw[i])) i += 1; };

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
      && !JSON_WS.has(raw[i])
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

function topLevelColon(text) {
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

function parseMapping(text, label, lineNo) {
  const clean = stripYamlComment(text);
  const colon = topLevelColon(clean);
  if (colon < 0) return null;
  const key = clean.slice(0, colon).trim();
  if (!key) fail(`${label}:${lineNo}: empty YAML mapping key`);
  if (!PLAIN_KEY_RE.test(key)) {
    fail(`${label}:${lineNo}: non-canonical YAML mapping key '${key}'; use plain keys only`);
  }
  return { key, value: clean.slice(colon + 1).trim() };
}

function isBlockHeader(value) {
  return BLOCK_HEADER_RE.test(value.trim());
}

function assertCanonicalMapping(mapping, label, lineNo) {
  const masked = maskGithubExpressions(mapping.value).trim();
  if (masked.startsWith('{') && masked !== '{}') {
    fail(`${label}:${lineNo}: non-empty YAML flow mappings are forbidden in canonical workflows`);
  }
  if (mapping.key === '<<' || /^<<\s*:/.test(masked)) {
    fail(`${label}:${lineNo}: YAML merge keys are forbidden`);
  }
  if (/(^|[\s\[,])&[A-Za-z0-9_-]+\b/.test(masked)
    || /(^|[\s\[,])\*[A-Za-z0-9_-]+\b/.test(masked)) {
    fail(`${label}:${lineNo}: YAML anchors/aliases are forbidden in canonical workflows`);
  }
}

function workflowStructure(raw, label) {
  const lines = raw.split(/\r?\n/);
  const rows = [];
  let blockIndent = null;

  for (let index = 0; index < lines.length; index += 1) {
    const original = lines[index];
    if (/^\t+/.test(original) || /^ +\t/.test(original)) {
      fail(`${label}:${index + 1}: tabs are forbidden in YAML indentation`);
    }
    const indent = original.match(/^ */)[0].length;
    const trimmed = original.trim();

    if (blockIndent !== null) {
      if (!trimmed || indent > blockIndent) continue;
      blockIndent = null;
    }
    if (!trimmed || trimmed.startsWith('#')) continue;
    if (indent % 2 !== 0) {
      fail(`${label}:${index + 1}: canonical workflow indentation must use multiples of two spaces`);
    }

    const clean = stripYamlComment(original.slice(indent));
    if (!clean.trim()) continue;
    if (clean === '---' || clean === '...') fail(`${label}:${index + 1}: multi-document YAML is forbidden`);

    let sequence = false;
    let mappingText = clean;
    let effectiveIndent = indent;
    if (clean === '-') {
      rows.push({ lineNo: index + 1, indent, effectiveIndent: indent + 2, clean, sequence: true, mapping: null });
      continue;
    }
    if (clean.startsWith('- ')) {
      sequence = true;
      mappingText = clean.slice(2).trimStart();
      effectiveIndent = indent + 2;
    }
    if (mappingText.startsWith('? ')) {
      fail(`${label}:${index + 1}: explicit/complex YAML mapping keys are forbidden`);
    }

    const mapping = parseMapping(mappingText, label, index + 1);
    if (mapping) {
      assertCanonicalMapping(mapping, label, index + 1);
      if (isBlockHeader(mapping.value)) blockIndent = effectiveIndent;
    }
    rows.push({ lineNo: index + 1, indent, effectiveIndent, clean, sequence, mapping });
  }
  return rows;
}

function scanYamlDuplicateKeys(raw, label) {
  const rows = workflowStructure(raw, label);
  const scopes = [];

  const scopeFor = (indent, reset = false) => {
    while (scopes.length && scopes.at(-1).indent > indent) scopes.pop();
    if (reset && scopes.length && scopes.at(-1).indent === indent) scopes.pop();
    if (!scopes.length || scopes.at(-1).indent < indent) scopes.push({ indent, keys: new Map() });
    return scopes.at(-1);
  };

  for (const row of rows) {
    if (!row.mapping) continue;
    const scope = scopeFor(row.effectiveIndent, row.sequence);
    if (scope.keys.has(row.mapping.key)) {
      fail(`${label}:${row.lineNo}: duplicate YAML key '${row.mapping.key}' (first at line ${scope.keys.get(row.mapping.key)})`);
    }
    scope.keys.set(row.mapping.key, row.lineNo);
  }
  return rows;
}

function dispatchDescriptionHeaders(raw, label) {
  const rows = workflowStructure(raw, label);
  const pathByIndent = new Map();
  const headers = new Set();

  for (const row of rows) {
    if (!row.mapping || row.sequence) continue;
    for (const depth of [...pathByIndent.keys()]) {
      if (depth >= row.indent) pathByIndent.delete(depth);
    }
    pathByIndent.set(row.indent, row.mapping.key);
    if (
      row.indent === 8
      && row.mapping.key === 'description'
      && pathByIndent.get(0) === 'on'
      && pathByIndent.get(2) === 'workflow_dispatch'
      && pathByIndent.get(4) === 'inputs'
      && INPUT_RE.test(pathByIndent.get(6) || '')
    ) {
      headers.add(row.lineNo);
    }
  }
  return headers;
}

function contextBases(kind) {
  return kind === 'inputs' ? ['inputs', 'github.event.inputs'] : ['needs'];
}

function contextIdentifier(kind) {
  return kind === 'inputs' ? '[A-Za-z_][A-Za-z0-9_]*' : '[A-Za-z0-9_-]+';
}

function escapeRegex(value) {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function scanContextRefs(text, kind, refs) {
  const identifier = contextIdentifier(kind);
  for (const base of contextBases(kind)) {
    const escaped = escapeRegex(base);
    const dot = new RegExp(`\\b${escaped}\\.(${identifier})\\b`, 'g');
    const bracket = new RegExp(`\\b${escaped}\\s*\\[\\s*['\"](${identifier})['\"]\\s*\\]`, 'g');
    let match;
    while ((match = dot.exec(text)) !== null) refs.add(match[1]);
    while ((match = bracket.exec(text)) !== null) refs.add(match[1]);
  }
}

function scanGithubExpressions(text, kind, refs) {
  const expression = /\$\{\{([\s\S]*?)\}\}/g;
  let match;
  while ((match = expression.exec(text)) !== null) scanContextRefs(match[1], kind, refs);
}

function executableContextReferences(raw, label, kind) {
  const lines = raw.split(/\r?\n/);
  const refs = new Set();
  const ignoredDescriptions = kind === 'inputs' ? dispatchDescriptionHeaders(raw, label) : new Set();
  let block = null;

  const flushBlock = () => {
    if (!block) return;
    if (!block.ignored) {
      const body = block.body.join('\n');
      scanGithubExpressions(body, kind, refs);
      if (block.key === 'if') scanContextRefs(body, kind, refs);
    }
    block = null;
  };

  for (let index = 0; index < lines.length; index += 1) {
    const original = lines[index];
    const lineNo = index + 1;
    const indent = original.match(/^ */)[0].length;
    const trimmed = original.trim();

    if (block) {
      if (!trimmed || indent > block.indent) {
        block.body.push(original.slice(Math.min(original.length, block.indent + 2)));
        continue;
      }
      flushBlock();
    }
    if (!trimmed || trimmed.startsWith('#')) continue;

    const clean = stripYamlComment(original.slice(indent));
    let mappingText = clean;
    let effectiveIndent = indent;
    if (clean.startsWith('- ')) {
      mappingText = clean.slice(2).trimStart();
      effectiveIndent = indent + 2;
    } else if (clean === '-') {
      continue;
    }

    const mapping = parseMapping(mappingText, label, lineNo);
    if (!mapping) {
      scanGithubExpressions(clean, kind, refs);
      continue;
    }

    const ignored = ignoredDescriptions.has(lineNo);
    if (!ignored) {
      scanGithubExpressions(clean, kind, refs);
      if (mapping.key === 'if' && !isBlockHeader(mapping.value) && !mapping.value.includes('${{')) {
        scanContextRefs(mapping.value, kind, refs);
      }
    }
    if (isBlockHeader(mapping.value)) {
      block = { key: mapping.key, indent: effectiveIndent, body: [], ignored };
    }
  }
  flushBlock();
  return refs;
}

function workflowDispatchInputs(raw, label) {
  const rows = workflowStructure(raw, label);
  const names = [];
  let dispatch = false;
  let inputs = false;

  for (const row of rows) {
    if (!row.mapping) continue;
    if (row.indent === 2 && row.mapping.key === 'workflow_dispatch') {
      dispatch = true;
      inputs = false;
      continue;
    }
    if (dispatch && row.indent <= 2) {
      dispatch = false;
      inputs = false;
    }
    if (dispatch && row.indent === 4 && row.mapping.key === 'inputs') {
      inputs = true;
      continue;
    }
    if (inputs && row.indent <= 4) inputs = false;
    if (inputs && row.indent === 6) {
      if (!INPUT_RE.test(row.mapping.key)) {
        fail(`${label}:${row.lineNo}: non-canonical workflow_dispatch input '${row.mapping.key}'`);
      }
      names.push(row.mapping.key);
    }
  }

  const refs = executableContextReferences(raw, label, 'inputs');
  for (const name of names) {
    if (!refs.has(name)) fail(`${label}: workflow_dispatch input '${name}' has no executable caller`);
  }
  return names;
}

function splitNeeds(value, label, lineNo) {
  const source = stripYamlComment(value).trim();
  if (!source) return null;
  if (source.startsWith('[')) {
    if (!source.endsWith(']')) fail(`${label}:${lineNo}: malformed needs flow sequence`);
    const refs = source.slice(1, -1).split(',').map((part) => part.trim()).filter(Boolean);
    for (const ref of refs) if (!JOB_RE.test(ref)) fail(`${label}:${lineNo}: invalid needs reference '${ref}'`);
    return refs;
  }
  if (!JOB_RE.test(source)) fail(`${label}:${lineNo}: invalid needs reference '${source}'`);
  return [source];
}

function workflowJobs(raw, label) {
  const rows = workflowStructure(raw, label);
  const lines = raw.split(/\r?\n/);
  const jobsRoot = rows.findIndex((row) => row.indent === 0 && row.mapping?.key === 'jobs');
  if (jobsRoot < 0) fail(`${label}: missing top-level jobs map`);

  const jobs = [];
  for (let index = jobsRoot + 1; index < rows.length; index += 1) {
    const row = rows[index];
    if (row.indent === 0) break;
    if (row.indent === 2 && row.mapping) jobs.push({ name: row.mapping.key, rowIndex: index, lineNo: row.lineNo });
  }
  if (!jobs.length) fail(`${label}: jobs map is empty`);

  const jobNames = new Set(jobs.map((job) => job.name));
  if (jobNames.size !== jobs.length) fail(`${label}: duplicate semantic job IDs`);
  const dependencies = new Map(jobs.map((job) => [job.name, []]));

  const addDependency = (job, ref, lineNo) => {
    if (!JOB_RE.test(ref)) fail(`${label}:${lineNo}: invalid needs reference '${ref}'`);
    if (!jobNames.has(ref)) fail(`${label}:${lineNo}: job '${job}' needs missing job '${ref}'`);
    if (ref === job) fail(`${label}:${lineNo}: job '${job}' cannot need itself`);
    if (dependencies.get(job).includes(ref)) fail(`${label}:${lineNo}: job '${job}' duplicates needs '${ref}'`);
    dependencies.get(job).push(ref);
  };

  for (let jobIndex = 0; jobIndex < jobs.length; jobIndex += 1) {
    const start = jobs[jobIndex].rowIndex + 1;
    const end = jobIndex + 1 < jobs.length ? jobs[jobIndex + 1].rowIndex : rows.length;
    for (let index = start; index < end; index += 1) {
      const row = rows[index];
      if (row.indent !== 4 || row.mapping?.key !== 'needs') continue;
      const direct = splitNeeds(row.mapping.value, label, row.lineNo);
      if (direct !== null) {
        for (const ref of direct) addDependency(jobs[jobIndex].name, ref, row.lineNo);
        continue;
      }
      let found = false;
      for (let childIndex = index + 1; childIndex < end; childIndex += 1) {
        const child = rows[childIndex];
        if (child.indent <= 4) break;
        if (child.indent !== 6 || !child.clean.startsWith('- ')) continue;
        const ref = stripYamlComment(child.clean.slice(2)).trim();
        if (!ref) continue;
        found = true;
        addDependency(jobs[jobIndex].name, ref, child.lineNo);
      }
      if (!found) fail(`${label}:${row.lineNo}: job '${jobs[jobIndex].name}' declares empty needs`);
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

  for (let jobIndex = 0; jobIndex < jobs.length; jobIndex += 1) {
    const startLine = jobs[jobIndex].lineNo - 1;
    const endLine = jobIndex + 1 < jobs.length ? jobs[jobIndex + 1].lineNo - 1 : lines.length;
    const jobText = lines.slice(startLine, endLine).join('\n');
    const refs = executableContextReferences(jobText, `${label}:${jobs[jobIndex].name}`, 'needs');
    for (const ref of refs) {
      if (!jobNames.has(ref)) fail(`${label}:${jobs[jobIndex].lineNo}: job '${jobs[jobIndex].name}' references unknown needs.${ref}`);
      if (!dependencies.get(jobs[jobIndex].name).includes(ref)) {
        fail(`${label}:${jobs[jobIndex].lineNo}: job '${jobs[jobIndex].name}' references needs.${ref} without declaring it`);
      }
    }
  }

  return jobs.map((job) => job.name);
}

function expectFailure(fn, pattern, name) {
  let matched = false;
  try {
    fn();
  } catch (error) {
    matched = pattern.test(error.message);
  }
  if (!matched) fail(`selftest: ${name}`);
}

function runSelfTests() {
  scanJsonRaw('{"a":1,"b":{"c":2}}', 'selftest-json-valid');
  expectFailure(() => scanJsonRaw('{"a":1,"nested":{"x":1,"x":2}}', 'selftest-json-dup'), /duplicate JSON key 'x'/, 'nested duplicate JSON key');
  expectFailure(() => scanJsonRaw('{"a":1,\u00a0"b":2}', 'selftest-json-nbsp-middle'), /expected JSON string|invalid JSON token|expected ','/, 'NBSP between JSON tokens');
  expectFailure(() => scanJsonRaw('{"a":1}\u00a0', 'selftest-json-nbsp-tail'), /trailing content/, 'NBSP after JSON root');

  scanYamlDuplicateKeys('permissions: {}\njobs:\n  a:\n    runs-on: ubuntu-latest\n    steps:\n      - run: |\n          echo "http://example.test:a"\n          x="${value:-fallback}"\n        shell: bash\n', 'selftest-yaml-valid');
  expectFailure(() => scanYamlDuplicateKeys('jobs:\n  a:\n    name: A\n    name: B\n', 'selftest-yaml-dup'), /duplicate YAML key 'name'/, 'duplicate YAML key');
  expectFailure(() => scanYamlDuplicateKeys('jobs:\n  a:\n    steps:\n      - run: |\n          echo ok\n        shell: bash\n        shell: sh\n', 'selftest-yaml-block-sibling'), /duplicate YAML key 'shell'/, 'duplicate sibling after block scalar');
  expectFailure(() => scanYamlDuplicateKeys('jobs:\n  "a":\n    runs-on: ubuntu-latest\n', 'selftest-yaml-quoted'), /non-canonical YAML mapping key/, 'quoted key accepted');
  expectFailure(() => scanYamlDuplicateKeys('root: {a: 1, a: 2}\n', 'selftest-yaml-flow-map'), /non-empty YAML flow mappings are forbidden/, 'non-empty flow mapping accepted');

  const liveInput = ['on:', '  workflow_dispatch:', '    inputs:', '      live:', '        description: Live input', 'jobs:', '  a:', "    if: inputs.live == 'yes'", '    runs-on: ubuntu-latest'].join('\n');
  workflowDispatchInputs(liveInput, 'selftest-input-live');

  const foldedInput = ['on:', '  workflow_dispatch:', '    inputs:', '      live:', '        description: Live input', 'jobs:', '  a:', '    if: >-', '      always() &&', '      inputs.live &&', '      true', '    runs-on: ubuntu-latest'].join('\n');
  workflowDispatchInputs(foldedInput, 'selftest-input-folded');

  const deadDescription = ['on:', '  workflow_dispatch:', '    inputs:', '      dead:', '        description: >2-', '          ${{ inputs.dead }} docs only', 'jobs:', '  a:', '    runs-on: ubuntu-latest'].join('\n');
  expectFailure(() => workflowDispatchInputs(deadDescription, 'selftest-input-doc'), /input 'dead' has no executable caller/, 'dispatch description counted as executable');

  const actionDescriptionInput = ['on:', '  workflow_dispatch:', '    inputs:', '      live:', '        description: Live input', 'jobs:', '  a:', '    runs-on: ubuntu-latest', '    steps:', '      - uses: owner/action@sha', '        with:', '          description: ${{ inputs.live }}'].join('\n');
  workflowDispatchInputs(actionDescriptionInput, 'selftest-input-action-description');

  const actionDescriptionBlockInput = ['on:', '  workflow_dispatch:', '    inputs:', '      live:', '        description: Live input', 'jobs:', '  a:', '    runs-on: ubuntu-latest', '    steps:', '      - uses: owner/action@sha', '        with:', '          description: >-', '            ${{ inputs.live }}'].join('\n');
  workflowDispatchInputs(actionDescriptionBlockInput, 'selftest-input-action-description-block');

  workflowJobs(['jobs:', '  build:', '    runs-on: ubuntu-latest', '  release:', '    needs: build # wait', '    runs-on: ubuntu-latest', '  audit:', '    needs:', '      - build', '      - release', '    if: >-', '      always() &&', "      needs.release.result == 'success'", '    runs-on: ubuntu-latest'].join('\n'), 'selftest-needs-valid');
  expectFailure(() => workflowJobs(['jobs:', '  a:', '    needs: missing', '    runs-on: ubuntu-latest'].join('\n'), 'selftest-needs-missing'), /needs missing job 'missing'/, 'missing needs');
  expectFailure(() => workflowJobs(['jobs:', '  a:', '    needs: b', '    runs-on: ubuntu-latest', '  b:', '    needs: a', '    runs-on: ubuntu-latest'].join('\n'), 'selftest-needs-cycle'), /cyclic job needs graph/, 'cyclic needs');
  expectFailure(() => workflowJobs(['jobs:', '  a:', '    runs-on: ubuntu-latest', '  b:', "    if: needs.a.result == 'success'", '    runs-on: ubuntu-latest'].join('\n'), 'selftest-needs-context'), /without declaring it/, 'undeclared needs context');

  workflowJobs(['jobs:', '  a:', '    runs-on: ubuntu-latest', '    steps:', '      - run: |', '          echo "needs.phantom is shell text"', '          # needs.ghost is shell text too'].join('\n'), 'selftest-needs-shell-text');
  workflowJobs(['jobs:', '  a:', '    runs-on: ubuntu-latest', '  b:', '    needs: a', '    runs-on: ubuntu-latest', '    steps:', '      - uses: owner/action@sha', '        with:', '          description: ${{ needs.a.result }}'].join('\n'), 'selftest-needs-action-description');
  workflowJobs(['jobs:', '  a:', '    runs-on: ubuntu-latest', '  b:', '    needs: a', '    runs-on: ubuntu-latest', '    steps:', '      - uses: owner/action@sha', '        with:', '          description: >-', '            ${{ needs.a.result }}'].join('\n'), 'selftest-needs-action-description-block');
  expectFailure(() => workflowJobs(['jobs:', '  a:', '    runs-on: ubuntu-latest', '  b:', '    runs-on: ubuntu-latest', '    steps:', '      - uses: owner/action@sha', '        with:', '          description: ${{ needs.a.result }}'].join('\n'), 'selftest-needs-action-description-undeclared'), /without declaring it/, 'undeclared needs in action description');
  expectFailure(() => workflowJobs(['jobs:', '  a:', '    runs-on: ubuntu-latest', '  b:', '    runs-on: ubuntu-latest', '    steps:', '      - uses: owner/action@sha', '        with:', '          description: >-', '            ${{ needs.a.result }}'].join('\n'), 'selftest-needs-action-description-block-undeclared'), /without declaring it/, 'undeclared needs in action description block');
}

runSelfTests();

const tracked = execFileSync('git', ['ls-files', '-z'], { cwd: repoRoot, encoding: 'utf8' })
  .split('\0')
  .filter(Boolean);
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

console.log(
  `CONFIG_WORKFLOW_RAW_SOURCE_AUDIT=PASS json=${jsonFiles.length} workflows=${workflowFiles.length} inputs=${inputCount} jobs=${jobCount}`,
);

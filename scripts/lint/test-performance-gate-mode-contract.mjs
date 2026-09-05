#!/usr/bin/env node
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import { resolvePerformanceGateMode } from '../staging2/performance-gate-mode.mjs';

assert.equal(resolvePerformanceGateMode({ eventName: 'push', requestedMode: 'baseline' }), 'enforce');
assert.equal(resolvePerformanceGateMode({ eventName: 'push', requestedMode: 'enforce' }), 'enforce');
assert.equal(resolvePerformanceGateMode({ eventName: 'workflow_dispatch', requestedMode: 'baseline' }), 'baseline');
assert.equal(resolvePerformanceGateMode({ eventName: 'workflow_dispatch', requestedMode: 'enforce' }), 'enforce');
assert.equal(resolvePerformanceGateMode({ eventName: 'workflow_dispatch', requestedMode: '' }), 'enforce');
assert.equal(resolvePerformanceGateMode({ eventName: '', requestedMode: '' }), 'enforce');
assert.equal(resolvePerformanceGateMode({ eventName: 'workflow_dispatch', requestedMode: 'invalid' }), 'invalid');

const entrypoint = await fs.readFile(new URL('../staging2/lighthouse-performance-gate.mjs', import.meta.url), 'utf8');
const core = await fs.readFile(new URL('../staging2/lighthouse-performance-gate-core.mjs', import.meta.url), 'utf8');
const workflow = await fs.readFile(new URL('../../.github/workflows/staging.yml', import.meta.url), 'utf8');

assert.match(entrypoint, /resolvePerformanceGateMode/);
assert.match(entrypoint, /process\.env\.GITHUB_EVENT_NAME/);
assert.match(entrypoint, /process\.env\.PERFORMANCE_GATE_MODE = effectiveMode/);
assert.match(core, /gateMode !== 'baseline' && gateMode !== 'enforce'/);
assert.match(core, /PERF_GATE=PASS mode=enforce/);
assert.match(core, /PERF_GATE=PASS mode=baseline cells=.*non-blocking/);
assert.match(workflow, /performance_gate_mode:/);
assert.match(workflow, /- baseline[\s\S]*- enforce/);
assert.match(workflow, /GATE_MODE:\s*\$\{\{\s*github\.event_name\s*==\s*'workflow_dispatch'\s*&&\s*inputs\.performance_gate_mode\s*\|\|.*\}\}/,
  'Workflow may retain its historical fallback only because the canonical entrypoint overrides every push to enforce');

console.log('PERFORMANCE_GATE_MODE_CONTRACT=PASS push=enforce manual_baseline=explicit default=enforce');

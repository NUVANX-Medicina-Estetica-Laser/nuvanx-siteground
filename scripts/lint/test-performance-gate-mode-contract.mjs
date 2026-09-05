#!/usr/bin/env node
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import { resolvePerformanceGateMode } from '../staging2/performance-gate-mode.mjs';
import {
  StagingEgressError,
  classifyStagingEgressFailure,
  shouldUseAuthenticatedStagingEgress,
  stagingEgressExitCode,
} from '../staging2/lighthouse-network-path.mjs';

assert.equal(resolvePerformanceGateMode({ eventName: 'push', requestedMode: 'baseline' }), 'enforce');
assert.equal(resolvePerformanceGateMode({ eventName: 'push', requestedMode: 'enforce' }), 'enforce');
assert.equal(resolvePerformanceGateMode({ eventName: 'workflow_dispatch', requestedMode: 'baseline' }), 'baseline');
assert.equal(resolvePerformanceGateMode({ eventName: 'workflow_dispatch', requestedMode: 'enforce' }), 'enforce');
assert.equal(resolvePerformanceGateMode({ eventName: 'workflow_dispatch', requestedMode: '' }), 'enforce');
assert.equal(resolvePerformanceGateMode({ eventName: '', requestedMode: '' }), 'enforce');
assert.equal(resolvePerformanceGateMode({ eventName: 'workflow_dispatch', requestedMode: 'invalid' }), 'invalid');

assert.equal(shouldUseAuthenticatedStagingEgress({
  GITHUB_ACTIONS: 'true',
  BASE_URL: 'https://staging2.nuvanx.com',
}), true);
assert.equal(shouldUseAuthenticatedStagingEgress({
  GITHUB_ACTIONS: 'false',
  BASE_URL: 'https://staging2.nuvanx.com',
}), false);
assert.equal(shouldUseAuthenticatedStagingEgress({
  GITHUB_ACTIONS: 'true',
  BASE_URL: 'https://nuvanx.com',
}), false);
assert.equal(shouldUseAuthenticatedStagingEgress({
  GITHUB_ACTIONS: 'true',
  BASE_URL: 'http://staging2.nuvanx.com',
}), false);

assert.equal(stagingEgressExitCode(classifyStagingEgressFailure(new Error('Connection timed out'))), 75);
assert.equal(stagingEgressExitCode(classifyStagingEgressFailure(new Error('sudo: a password is required'))), 78);
assert.equal(stagingEgressExitCode(classifyStagingEgressFailure(new Error('Host key verification failed'))), 1);
assert.equal(stagingEgressExitCode(new StagingEgressError('transient_infrastructure', 'sgcaptcha challenge')), 75);
assert.equal(stagingEgressExitCode(new StagingEgressError('configuration', 'bad canonical URL')), 78);
assert.equal(stagingEgressExitCode(new StagingEgressError('deterministic_failure', 'host escaped')), 1);

const entrypoint = await fs.readFile(new URL('../staging2/lighthouse-performance-gate.mjs', import.meta.url), 'utf8');
const core = await fs.readFile(new URL('../staging2/lighthouse-performance-gate-core.mjs', import.meta.url), 'utf8');
const networkPath = await fs.readFile(new URL('../staging2/lighthouse-network-path.mjs', import.meta.url), 'utf8');
const workflow = await fs.readFile(new URL('../../.github/workflows/staging.yml', import.meta.url), 'utf8');

assert.match(entrypoint, /resolvePerformanceGateMode/);
assert.match(entrypoint, /process\.env\.GITHUB_EVENT_NAME/);
assert.match(entrypoint, /process\.env\.PERFORMANCE_GATE_MODE = effectiveMode/);
assert.match(entrypoint, /startAuthenticatedStagingEgress/);
assert.match(entrypoint, /stagingEgressExitCode/);
assert.match(entrypoint, /PERF_NETWORK_PATH=AUTHENTICATED_SSH_HTTPS/);
assert.match(entrypoint, /PERF_NETWORK_PATH=FAIL_\$\{classification\}/);
assert.match(entrypoint, /exitCode === 75 \? 'TRANSIENT' : exitCode === 78 \? 'CONFIG' : 'DETERMINISTIC'/);
assert.match(entrypoint, /spawnSync\(process\.execPath/,
  'The wrapper must keep the authenticated tunnel alive while the canonical core runs as a child process');
assert.doesNotMatch(entrypoint, /PERFORMANCE_CHROME_PROXY_SERVER/,
  'The fix must not change Lighthouse or Chromium proxy flags');

assert.match(networkPath, /ExitOnForwardFailure=yes/);
assert.match(networkPath, /127\.0\.0\.1:\$\{LOCAL_HTTPS_PORT\}:\$\{CANONICAL_STAGING_HOSTNAME\}:443/);
assert.match(networkPath, /--resolve/);
assert.match(networkPath, /effective\.pathname\.startsWith\('\/\.well-known\/sgcaptcha\/'\)/);
assert.match(networkPath, /127\.0\.0\.1 \$\{CANONICAL_STAGING_HOSTNAME\} \$\{HOSTS_MARKER\}/);
assert.match(networkPath, /timer = setTimeout\(probe, Math\.min\(250, timeoutMs - elapsed\)\)/,
  'Tunnel readiness must use a finite one-shot wait governed by the existing bounded-wait authority');
assert.doesNotMatch(networkPath, /setInterval\s*\(/,
  'The harness must not introduce an ungoverned periodic timer for tunnel readiness');
assert.doesNotMatch(networkPath, /disable.*antibot|allowlist|whitelist/i,
  'The performance harness must not disable or bypass SiteGround security policy');

assert.match(core, /gateMode !== 'baseline' && gateMode !== 'enforce'/);
assert.match(core, /PERF_GATE=PASS mode=enforce/);
assert.match(core, /PERF_GATE=PASS mode=baseline cells=.*non-blocking/);
assert.match(core, /--chrome-flags=--headless --disable-dev-shm-usage/,
  'Canonical Lighthouse Chrome flags must remain unchanged');
assert.match(workflow, /performance_gate_mode:/);
assert.match(workflow, /- baseline[\s\S]*- enforce/);
assert.match(workflow, /Configure strict Staging2 SSH \(read-only\)[\s\S]*StrictHostKeyChecking yes/,
  'The authenticated network path must reuse the host-key-pinned Staging SSH owner');
assert.match(workflow, /GATE_MODE:\s*\$\{\{\s*github\.event_name\s*==\s*'workflow_dispatch'\s*&&\s*inputs\.performance_gate_mode\s*\|\|.*\}\}/,
  'Workflow may retain its historical fallback only because the canonical entrypoint overrides every push to enforce');

console.log('PERFORMANCE_GATE_MODE_CONTRACT=PASS push=enforce manual_baseline=explicit default=enforce network=authenticated_ssh_https taxonomy=75_78_1');

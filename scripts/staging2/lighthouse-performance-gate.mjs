#!/usr/bin/env node
/**
 * Canonical performance-gate entrypoint.
 *
 * Normal GitHub Staging acceptance uses the already host-key-pinned SSH
 * identity as the network egress owner for Lighthouse. This prevents
 * SiteGround Antibot from classifying a burst of browser navigations from an
 * ephemeral Azure runner IP as anonymous traffic while preserving the exact
 * HTTPS URL, TLS hostname and unmodified Lighthouse/Chrome measurement stack.
 * There is deliberately no direct-network fallback in GitHub Actions.
 */

import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { resolvePerformanceGateMode } from './performance-gate-mode.mjs';
import {
  shouldUseAuthenticatedStagingEgress,
  stagingEgressExitCode,
  startAuthenticatedStagingEgress,
} from './lighthouse-network-path.mjs';

const requestedMode = process.env.PERFORMANCE_GATE_MODE || '';
const eventName = process.env.GITHUB_EVENT_NAME || '';
const effectiveMode = resolvePerformanceGateMode({ eventName, requestedMode });

process.env.PERFORMANCE_GATE_MODE = effectiveMode;
console.log(
  `PERF_GATE_MODE_RESOLVED event=${eventName || 'unknown'} requested=${requestedMode || 'unset'} effective=${effectiveMode}`,
);

let tunnel = null;
let cleaned = false;
const cleanup = () => {
  if (cleaned) return;
  cleaned = true;
  if (tunnel) tunnel.close();
};
process.once('exit', cleanup);
process.once('SIGINT', () => {
  cleanup();
  process.exit(130);
});
process.once('SIGTERM', () => {
  cleanup();
  process.exit(143);
});

if (shouldUseAuthenticatedStagingEgress(process.env)) {
  try {
    tunnel = await startAuthenticatedStagingEgress({ baseUrl: process.env.BASE_URL });
    console.log(
      `PERF_NETWORK_PATH=AUTHENTICATED_SSH_HTTPS path_owner=${tunnel.networkPath} status=${tunnel.probe.status} path=${tunnel.probe.effectivePath} local_peer=${tunnel.probe.localPeer}`,
    );
  } catch (error) {
    const exitCode = stagingEgressExitCode(error);
    const classification = exitCode === 75 ? 'TRANSIENT' : exitCode === 78 ? 'CONFIG' : 'DETERMINISTIC';
    console.error(
      `PERF_NETWORK_PATH=FAIL_${classification} exit=${exitCode} reason=${String(error?.message || error).slice(0, 1500)}`,
    );
    process.exit(exitCode);
  }
} else if (String(process.env.GITHUB_ACTIONS || '').toLowerCase() === 'true') {
  console.error('PERF_NETWORK_PATH=FAIL_CONFIG exit=78 reason=github_actions_staging_tunnel_not_selected');
  process.exit(78);
}

const corePath = fileURLToPath(new URL('./lighthouse-performance-gate-core.mjs', import.meta.url));
const core = spawnSync(process.execPath, [corePath], {
  stdio: 'inherit',
  env: process.env,
  timeout: 29 * 60 * 1000,
});

cleanup();
if (core.error) {
  console.error(`PERF_GATE_WRAPPER=FAIL reason=${String(core.error.message || core.error)}`);
  process.exit(1);
}
if (core.signal) {
  console.error(`PERF_GATE_WRAPPER=FAIL signal=${core.signal}`);
  process.exit(1);
}
process.exit(core.status ?? 1);

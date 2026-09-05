#!/usr/bin/env node
/**
 * Canonical performance-gate entrypoint.
 *
 * Normal GitHub Staging acceptance uses the already host-key-pinned SSH
 * identity as the network egress owner for Lighthouse. This prevents
 * SiteGround Antibot from classifying a burst of browser navigations from an
 * ephemeral Azure runner IP as anonymous traffic while preserving the real
 * HTTPS URL/TLS/WordPress path. There is deliberately no direct-network
 * fallback in GitHub Actions: an unavailable or challenged tunnel fails closed.
 */

import { resolvePerformanceGateMode } from './performance-gate-mode.mjs';
import {
  shouldUseAuthenticatedStagingEgress,
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
const cleanup = () => {
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
    process.env.PERFORMANCE_CHROME_PROXY_SERVER = tunnel.proxyServer;
    console.log(
      `PERF_NETWORK_PATH=AUTHENTICATED_SSH_SOCKS status=${tunnel.probe.status} path=${tunnel.probe.effectivePath}`,
    );
  } catch (error) {
    console.error(`PERF_NETWORK_PATH=FAIL_CLOSED reason=${String(error?.message || error).slice(0, 1500)}`);
    process.exit(1);
  }
} else if (String(process.env.GITHUB_ACTIONS || '').toLowerCase() === 'true') {
  console.error('PERF_NETWORK_PATH=FAIL_CONFIG reason=github_actions_staging_tunnel_not_selected');
  process.exit(78);
}

try {
  await import('./lighthouse-performance-gate-core.mjs');
} finally {
  cleanup();
}

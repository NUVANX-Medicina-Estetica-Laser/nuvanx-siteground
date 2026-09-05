#!/usr/bin/env node
/**
 * Canonical performance-gate entrypoint.
 *
 * The historical workflow input remains available for explicit manual
 * calibration, but normal push acceptance is always enforcing. Keep the heavy
 * Lighthouse implementation in the core module so mode resolution has one
 * small, deterministic owner.
 */

import { resolvePerformanceGateMode } from './performance-gate-mode.mjs';

const requestedMode = process.env.PERFORMANCE_GATE_MODE || '';
const eventName = process.env.GITHUB_EVENT_NAME || '';
const effectiveMode = resolvePerformanceGateMode({ eventName, requestedMode });

process.env.PERFORMANCE_GATE_MODE = effectiveMode;
console.log(
  `PERF_GATE_MODE_RESOLVED event=${eventName || 'unknown'} requested=${requestedMode || 'unset'} effective=${effectiveMode}`,
);

await import('./lighthouse-performance-gate-core.mjs');

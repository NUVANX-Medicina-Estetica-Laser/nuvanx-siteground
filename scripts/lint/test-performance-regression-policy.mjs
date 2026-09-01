#!/usr/bin/env node
import assert from 'node:assert/strict';
import {
  evaluatePerformanceRegression,
  validatePerformanceBaselineContract,
} from '../staging2/performance-regression-policy.mjs';

const requiredCells = ['home/mobile'];
const approved = {
  schema: 1,
  status: 'approved',
  lighthouse_version: '12.8.2',
  policy: {
    lcp_ms: { relative_increase: 0.35, absolute_delta: 400, absolute_max: 4500 },
    tbt_ms: { absolute_delta: 150, absolute_max: 600 },
    cls: { absolute_delta: 0.05, absolute_max: 0.25 },
    ttfb_ms: { relative_increase: 1.0, absolute_delta: 250, absolute_max: 1200 },
    performance_score: { drop_points: 8, absolute_min: 70 },
  },
  cells: {
    'home/mobile': {
      reference: {
        performance_score: 81,
        lcp_ms: 3451,
        tbt_ms: 87,
        cls: 0.032,
        ttfb_ms: 288,
      },
    },
  },
};

assert.deepEqual(
  validatePerformanceBaselineContract(approved, {
    lighthouseVersion: '12.8.2',
    requiredCells,
    requireApproved: true,
  }),
  { ok: true },
);

const calibrating = structuredClone(approved);
calibrating.status = 'calibrating';
assert.equal(
  validatePerformanceBaselineContract(calibrating, {
    lighthouseVersion: '12.8.2',
    requiredCells,
    requireApproved: true,
  }).reason,
  'baseline_not_approved',
);

const healthy = [{
  page: 'home',
  mode: 'mobile',
  status: 'success',
  median: {
    performance_score: 79,
    lcp_ms: 3895,
    tbt_ms: 114,
    cls: 0.032,
    ttfb_ms: 294,
  },
}];
const healthyEvaluation = evaluatePerformanceRegression(healthy, approved);
assert.equal(healthyEvaluation.violations.length, 0);

const regressed = structuredClone(healthy);
regressed[0].median.lcp_ms = 5000;
regressed[0].median.performance_score = 60;
regressed[0].median.tbt_ms = 700;
const regressionEvaluation = evaluatePerformanceRegression(regressed, approved);
assert.ok(regressionEvaluation.violations.some((violation) => violation.metric === 'lcp_ms'));
assert.ok(regressionEvaluation.violations.some((violation) => violation.metric === 'performance_score'));
assert.ok(regressionEvaluation.violations.some((violation) => violation.metric === 'tbt_ms'));

console.log('PERFORMANCE_REGRESSION_POLICY_TEST=PASS');

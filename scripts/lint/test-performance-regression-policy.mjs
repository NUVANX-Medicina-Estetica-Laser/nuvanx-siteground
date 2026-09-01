#!/usr/bin/env node
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {
  evaluatePerformanceRegression,
  validatePerformanceBaselineContract,
} from '../staging2/performance-regression-policy.mjs';

const requiredCells = [
  'home/mobile', 'home/desktop',
  'endolift/mobile', 'endolift/desktop',
  'endolaser/mobile', 'endolaser/desktop',
  'faciales/mobile', 'faciales/desktop',
  'valoracion/mobile', 'valoracion/desktop',
  'blog/mobile', 'blog/desktop',
];

const committed = JSON.parse(await fs.readFile(
  new URL('../staging2/performance-baseline.json', import.meta.url),
  'utf8',
));

assert.deepEqual(
  validatePerformanceBaselineContract(committed, {
    lighthouseVersion: '12.8.2',
    requiredCells,
    requireApproved: true,
  }),
  { ok: true },
);
assert.equal(committed.generated_from.length, 3);
assert.equal(new Set(committed.generated_from.map((source) => source.sha)).size, 3);

const calibrating = structuredClone(committed);
calibrating.status = 'calibrating';
assert.equal(
  validatePerformanceBaselineContract(calibrating, {
    lighthouseVersion: '12.8.2',
    requiredCells,
    requireApproved: true,
  }).reason,
  'baseline_not_approved',
);

const insufficientEvidence = structuredClone(committed);
insufficientEvidence.generated_from = insufficientEvidence.generated_from.slice(0, 2);
assert.equal(
  validatePerformanceBaselineContract(insufficientEvidence, {
    lighthouseVersion: '12.8.2',
    requiredCells,
    requireApproved: true,
  }).reason,
  'insufficient_baseline_evidence',
);

const duplicateEvidence = structuredClone(committed);
duplicateEvidence.generated_from[2] = structuredClone(duplicateEvidence.generated_from[1]);
assert.equal(
  validatePerformanceBaselineContract(duplicateEvidence, {
    lighthouseVersion: '12.8.2',
    requiredCells,
    requireApproved: true,
  }).reason,
  'duplicate_baseline_evidence',
);

const impossibleReference = structuredClone(committed);
impossibleReference.cells['home/mobile'].reference.lcp_ms = 5000;
assert.equal(
  validatePerformanceBaselineContract(impossibleReference, {
    lighthouseVersion: '12.8.2',
    requiredCells,
    requireApproved: true,
  }).reason,
  'invalid_reference_range_home/mobile',
);

const healthy = [
  ['home','mobile',79,3895,114,0.032,288],
  ['home','desktop',90,1579,0,0.0242,289],
  ['endolift','mobile',92,3156,85,0,354],
  ['endolift','desktop',91,1501,0,0,292],
  ['endolaser','mobile',89,3467,70,0,537],
  ['endolaser','desktop',94,1315,0,0,293],
  ['faciales','mobile',92,3034,73,0,293],
  ['faciales','desktop',92,1297,0,0,293],
  ['valoracion','mobile',87,3261,89,0,289],
  ['valoracion','desktop',94,1207,0,0.0009,289],
  ['blog','mobile',89,2838,80,0.0024,574],
  ['blog','desktop',91,1306,0,0.0001,308],
].map(([page, mode, performance_score, lcp_ms, tbt_ms, cls, ttfb_ms]) => ({
  page,
  mode,
  status: 'success',
  median: { performance_score, lcp_ms, tbt_ms, cls, ttfb_ms },
}));
const healthyEvaluation = evaluatePerformanceRegression(healthy, committed);
assert.equal(healthyEvaluation.violations.length, 0);
assert.equal(healthyEvaluation.evaluations.length, 60);

const regressed = structuredClone(healthy);
regressed[0].median.lcp_ms = 5000;
regressed[0].median.performance_score = 60;
regressed[0].median.tbt_ms = 700;
const regressionEvaluation = evaluatePerformanceRegression(regressed, committed);
assert.ok(regressionEvaluation.violations.some((violation) => violation.metric === 'lcp_ms'));
assert.ok(regressionEvaluation.violations.some((violation) => violation.metric === 'performance_score'));
assert.ok(regressionEvaluation.violations.some((violation) => violation.metric === 'tbt_ms'));

console.log('PERFORMANCE_REGRESSION_POLICY_TEST=PASS');

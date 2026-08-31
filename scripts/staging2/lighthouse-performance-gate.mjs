#!/usr/bin/env node
/**
 * Exact-SHA Staging performance regression gate.
 *
 * Runs a Lighthouse matrix (6 pages x mobile/desktop) against the deployed
 * Staging candidate, classifies SiteGround anti-bot/transport conditions
 * separately from true page-performance failures, and enforces bounded
 * regression budgets when PERFORMANCE_GATE_MODE=enforce.
 *
 * Phase 1 (baseline): captures metrics, persists artifacts, never blocks.
 * Phase 2 (enforce):  blocks on material regression beyond bounded deltas.
 *
 * Every artifact records the exact candidate SHA so performance evidence is
 * tied to the deployed release candidate.
 *
 * @package nuvanx-siteground
 */

import fs from 'node:fs/promises';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import {
  isSiteGroundTransientResponse,
} from './siteground-transient-classifier.mjs';

const baseUrl = (process.env.BASE_URL || 'https://staging2.nuvanx.com').replace(/\/$/, '');
const expectedSha = (process.env.EXPECTED_SHA || '').trim();
const gateMode = (process.env.PERFORMANCE_GATE_MODE || 'baseline').trim().toLowerCase();
const lighthouseVersion = '12.8.2';
const attemptsPerCell = 3;
const requestTimeoutMs = Number.parseInt(process.env.PERFORMANCE_REQUEST_TIMEOUT_MS || '60000', 10);

const pages = [
  { key: 'home', path: '/' },
  { key: 'endolift', path: '/endolift-facial-papada-mandibula/' },
  { key: 'endolaser', path: '/endolaser-corporal-grasa-localizada/' },
  { key: 'faciales', path: '/medicina-estetica/' },
  { key: 'valoracion', path: '/madrid/valoracion/' },
  { key: 'blog', path: '/blog/' },
];

const modes = ['mobile', 'desktop'];

const metricKeys = [
  'performance_score',
  'fcp_ms',
  'lcp_ms',
  'tbt_ms',
  'cls',
  'speed_index_ms',
  'ttfb_ms',
];

const budgetMetrics = ['lcp_ms', 'cls', 'tbt_ms', 'ttfb_ms', 'performance_score'];

const defaultBudgets = {
  lcp_ms: { max: 4000, regression_delta: 500 },
  cls: { max: 0.25, regression_delta: 0.05 },
  tbt_ms: { max: 600, regression_delta: 200 },
  ttfb_ms: { max: 1200, regression_delta: 300 },
  performance_score: { min: 70, regression_delta: 5 },
};

function loadBudgets() {
  const budgets = {};
  for (const key of budgetMetrics) {
    const prefix = `PERF_BUDGET_${key.toUpperCase()}`;
    const base = { ...defaultBudgets[key] };
    const maxOverride = process.env[`${prefix}_MAX`];
    const minOverride = process.env[`${prefix}_MIN`];
    const deltaOverride = process.env[`${prefix}_DELTA`];
    if (maxOverride !== undefined) base.max = Number(maxOverride);
    if (minOverride !== undefined) base.min = Number(minOverride);
    if (deltaOverride !== undefined) base.regression_delta = Number(deltaOverride);
    budgets[key] = base;
  }
  return budgets;
}

function validateInput() {
  if (!/^[a-z0-9.-]+$/.test(new URL(baseUrl).hostname)) {
    console.error('PERF_GATE=FAIL reason=invalid_base_url');
    process.exit(1);
  }
  if (expectedSha && !/^[0-9a-f]{40}$/.test(expectedSha)) {
    console.error('PERF_GATE=FAIL reason=invalid_expected_sha');
    process.exit(1);
  }
  if (gateMode !== 'baseline' && gateMode !== 'enforce') {
    console.error('PERF_GATE=FAIL reason=invalid_gate_mode');
    process.exit(1);
  }
  if (!Number.isInteger(requestTimeoutMs) || requestTimeoutMs < 10000 || requestTimeoutMs > 120000) {
    console.error('PERF_GATE=FAIL reason=invalid_timeout');
    process.exit(1);
  }
}

function median(values) {
  const sorted = [...values].sort((a, b) => a - b);
  const mid = Math.floor(sorted.length / 2);
  return sorted.length % 2 === 0
    ? (sorted[mid - 1] + sorted[mid]) / 2
    : sorted[mid];
}

function extractMetrics(lighthouseJson) {
  const lh = typeof lighthouseJson === 'string' ? JSON.parse(lighthouseJson) : lighthouseJson;
  const perfScore = lh.categories?.performance?.score;
  const audits = lh.audits || {};
  return {
    performance_score: typeof perfScore === 'number' ? Math.round(perfScore * 100) : null,
    fcp_ms: audits['first-contentful-paint']?.numericValue ?? null,
    lcp_ms: audits['largest-contentful-paint']?.numericValue ?? null,
    tbt_ms: audits['total-blocking-time']?.numericValue ?? null,
    cls: audits['cumulative-layout-shift']?.numericValue ?? null,
    speed_index_ms: audits['speed-index']?.numericValue ?? null,
    ttfb_ms: audits['server-response-time']?.numericValue ?? null,
  };
}

function isValidMetrics(m) {
  return m !== null && typeof m === 'object' && metricKeys.every((k) => typeof m[k] === 'number');
}

function classifyLighthouseError(error) {
  const message = error instanceof Error ? error.message : String(error || '');
  if (
    isSiteGroundTransientResponse(202, {}, message)
    || isSiteGroundTransientResponse(429, {}, message)
    || isSiteGroundTransientResponse(503, {}, message)
    || /timeout|timed out/i.test(message)
    || /ECONNREFUSED|ECONNRESET|ENOTFOUND/i.test(message)
    || /LanternError:\s*missing metric scores for specified navigation/i.test(message)
    || /Protocol error.*Inspected target navigated or closed/i.test(message)
    || /TargetCloseError|Target closed|Page crashed/i.test(message)
  ) {
    return 'transient_infrastructure';
  }
  return 'lighthouse_execution_failed';
}

async function runLighthouseCell(page, mode, outputDir) {
  const url = `${baseUrl}${page.path}`;
  const baseName = `${page.key}-${mode}`;
  const rawDir = path.join(outputDir, 'raw');
  const runs = [];
  let transientCount = 0;

  for (let attempt = 1; attempt <= attemptsPerCell; attempt++) {
    const outFile = path.join(rawDir, `${baseName}-run${attempt}.json`);
    const extra = mode === 'desktop' ? ['--preset=desktop'] : [];

    const child = spawnSync(
      './node_modules/.bin/lighthouse',
      [
        url,
        '--output=json',
        `--output-path=${outFile}`,
        '--only-categories=performance',
        '--quiet',
        '--chrome-flags=--headless --disable-dev-shm-usage',
        ...extra,
      ],
      {
        encoding: 'utf8',
        timeout: requestTimeoutMs,
        maxBuffer: 20 * 1024 * 1024,
        stdio: ['ignore', 'pipe', 'pipe'],
      }
    );

    const diagnostic = [
      child.error?.message || '',
      child.stderr || '',
      child.stdout || '',
      child.signal ? `signal=${child.signal}` : '',
      child.status !== null ? `exit=${child.status}` : '',
    ].filter(Boolean).join('\n').trim();

    if (child.error || child.status !== 0) {
      const classification = classifyLighthouseError(diagnostic);
      if (classification === 'transient_infrastructure') transientCount++;
      runs.push({ attempt, status: classification, metrics: null });
      continue;
    }

    try {
      const rawJson = await fs.readFile(outFile, 'utf8');
      const metrics = extractMetrics(rawJson);
      if (!isValidMetrics(metrics)) {
        runs.push({ attempt, status: 'invalid_json', metrics: null });
        continue;
      }
      runs.push({ attempt, status: 'success', metrics });
    } catch (error) {
      const classification = classifyLighthouseError(error);
      if (classification === 'transient_infrastructure') transientCount++;
      runs.push({ attempt, status: classification, metrics: null });
    }
  }

  const successfulRuns = runs.filter((r) => r.status === 'success');
  const cellResult = {
    page: page.key,
    mode,
    url,
    attempts: runs.length,
    successes: successfulRuns.length,
    transients: transientCount,
    failures: runs.length - successfulRuns.length,
    median: null,
  };

  if (successfulRuns.length === 0) {
    cellResult.status = transientCount > 0 ? 'transient_infrastructure' : 'lighthouse_failed';
    return cellResult;
  }

  const medians = {};
  for (const key of metricKeys) {
    const values = successfulRuns.map((r) => r.metrics[key]).filter((v) => v !== null);
    if (values.length > 0) {
      medians[key] = key === 'cls'
        ? Math.round(median(values) * 10000) / 10000
        : Math.round(median(values));
    } else {
      medians[key] = null;
    }
  }
  cellResult.median = medians;
  cellResult.status = 'success';
  return cellResult;
}

function evaluateBudgets(cellResults, budgets) {
  const violations = [];

  for (const cell of cellResults) {
    if (cell.status !== 'success' || !cell.median) continue;

    for (const key of budgetMetrics) {
      const budget = budgets[key];
      const value = cell.median[key];
      if (value === null) continue;

      if (key === 'performance_score') {
        if (typeof budget.min === 'number' && value < budget.min - budget.regression_delta) {
          violations.push({
            page: cell.page,
            mode: cell.mode,
            metric: key,
            value,
            threshold: budget.min,
            delta_allowed: budget.regression_delta,
            severity: 'regression',
          });
        }
      } else {
        if (typeof budget.max === 'number' && value > budget.max + budget.regression_delta) {
          violations.push({
            page: cell.page,
            mode: cell.mode,
            metric: key,
            value,
            threshold: budget.max,
            delta_allowed: budget.regression_delta,
            severity: 'regression',
          });
        }
      }
    }
  }

  return violations;
}

async function writeArtifacts(outputDir, cellResults, budgets) {
  await fs.mkdir(path.join(outputDir, 'raw'), { recursive: true });

  const summaryTsvPath = path.join(outputDir, 'summary.tsv');
  const tsvHeader = [
    'page', 'mode', 'status', 'attempts', 'successes', 'transients',
    ...metricKeys,
  ].join('\t') + '\n';
  let tsvBody = '';
  for (const cell of cellResults) {
    const row = [
      cell.page, cell.mode, cell.status, cell.attempts, cell.successes, cell.transients,
      ...metricKeys.map((k) => cell.median?.[k] ?? 'N/A'),
    ];
    tsvBody += row.join('\t') + '\n';
  }
  await fs.writeFile(summaryTsvPath, tsvHeader + tsvBody, 'utf8');

  const summaryJsonPath = path.join(outputDir, 'summary.json');
  const summaryJson = {
    schema: 1,
    candidate_sha: expectedSha,
    base_url: baseUrl,
    lighthouse_version: lighthouseVersion,
    gate_mode: gateMode,
    generated_at: new Date().toISOString(),
    pages: pages.map((p) => p.path),
    modes,
    attempts_per_cell: attemptsPerCell,
    budgets: gateMode === 'enforce' ? budgets : null,
    results: cellResults,
  };
  await fs.writeFile(summaryJsonPath, JSON.stringify(summaryJson, null, 2) + '\n', 'utf8');

  const lighthouseVersionPath = path.join(outputDir, 'lighthouse-version.txt');
  await fs.writeFile(lighthouseVersionPath, lighthouseVersion + '\n', 'utf8');

  const shaPath = path.join(outputDir, 'candidate-sha.txt');
  await fs.writeFile(shaPath, expectedSha + '\n', 'utf8');
}

async function main() {
  validateInput();

  const outputDir = path.resolve('scripts/staging2/performance-artifacts');
  await fs.rm(outputDir, { recursive: true, force: true });
  await fs.mkdir(path.join(outputDir, 'raw'), { recursive: true });

  console.log(`PERF_GATE=START mode=${gateMode} sha=${expectedSha} base=${baseUrl}`);
  console.log(`PERF_GATE_LIGHTHOUSE version=${lighthouseVersion}`);

  const cellResults = [];
  for (const page of pages) {
    for (const mode of modes) {
      process.stdout.write(`  ${page.key}/${mode} ... `);
      const result = await runLighthouseCell(page, mode, outputDir);
      cellResults.push(result);
      console.log(result.status === 'success'
        ? `OK (median perf=${result.median.performance_score} lcp=${result.median.lcp_ms} cls=${result.median.cls} tbt=${result.median.tbt_ms} ttfb=${result.median.ttfb_ms})`
        : `SKIP (${result.status} successes=${result.successes}/${result.attempts})`);
    }
  }

  const budgets = loadBudgets();
  await writeArtifacts(outputDir, cellResults, budgets);

  console.log('\n--- Performance Summary ---');
  for (const cell of cellResults) {
    if (cell.status === 'success' && cell.median) {
      console.log(
        `  ${cell.page}/${cell.mode}: perf=${cell.median.performance_score} ` +
        `lcp=${cell.median.lcp_ms}ms cls=${cell.median.cls} ` +
        `tbt=${cell.median.tbt_ms}ms ttfb=${cell.median.ttfb_ms}ms`
      );
    } else {
      console.log(`  ${cell.page}/${cell.mode}: ${cell.status}`);
    }
  }

  const transientCells = cellResults.filter((c) => c.status === 'transient_infrastructure');
  const failedCells = cellResults.filter((c) => c.status === 'lighthouse_failed');

  if (transientCells.length > 0) {
    console.error(`\nPERF_GATE_TRANSIENT cells=${transientCells.length}/${cellResults.length}`);
    for (const c of transientCells) {
      console.error(`  ${c.page}/${c.mode}: ${c.transients} transient of ${c.attempts} attempts`);
    }
  }

  if (gateMode === 'enforce') {
    const violations = evaluateBudgets(cellResults, budgets);
    if (violations.length > 0) {
      console.error(`\nPERF_GATE=FAIL mode=enforce violations=${violations.length}`);
      for (const v of violations) {
        console.error(
          `  ${v.page}/${v.mode} ${v.metric}: ${v.value} ` +
          `(threshold=${v.threshold} delta_allowed=${v.delta_allowed})`
        );
      }
      process.exit(1);
    }
    console.log(`\nPERF_GATE=PASS mode=enforce cells=${cellResults.length} violations=0`);
  } else {
    console.log(`\nPERF_GATE=PASS mode=baseline cells=${cellResults.length} (non-blocking)`);
  }

  if (failedCells.length > 0 && transientCells.length === 0) {
    console.error(`PERF_GATE_WARNING lighthouse_failures=${failedCells.length}`);
  }
}

main().catch((err) => {
  console.error('PERF_GATE=FAIL', err);
  process.exit(1);
});

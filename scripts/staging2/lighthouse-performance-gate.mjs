#!/usr/bin/env node
/**
 * Exact-SHA Staging performance regression gate.
 *
 * Baseline mode captures a 6-route x mobile/desktop Lighthouse matrix.
 * Enforce mode measures the same exact-SHA matrix and compares every valid
 * cell against the approved empirical per-route/mode baseline contract.
 *
 * SiteGround CAPTCHA/HTTP 202, transport and other recoverable infrastructure
 * conditions are classified separately from deterministic application or
 * performance regressions.
 */

import fs from 'node:fs/promises';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import {
  isSiteGroundTransientResponse,
} from './siteground-transient-classifier.mjs';
import {
  evaluatePerformanceRegression,
  validatePerformanceBaselineContract,
} from './performance-regression-policy.mjs';

const baseUrl = (process.env.BASE_URL || 'https://staging2.nuvanx.com').replace(/\/$/, '');
const expectedSha = (process.env.EXPECTED_SHA || '').trim();
const gateMode = (process.env.PERFORMANCE_GATE_MODE || 'baseline').trim().toLowerCase();
const lighthouseVersion = '12.8.2';
const attemptsPerCell = 3;
const requestTimeoutMs = Number.parseInt(process.env.PERFORMANCE_REQUEST_TIMEOUT_MS || '60000', 10);
const baselineContractPath = process.env.PERFORMANCE_BASELINE_PATH
  || 'scripts/staging2/performance-baseline.json';

const pages = [
  { key: 'home', path: '/' },
  { key: 'endolift', path: '/endolift-facial-papada-mandibula/' },
  { key: 'endolaser', path: '/endolaser-corporal-grasa-localizada/' },
  { key: 'faciales', path: '/medicina-estetica/' },
  { key: 'valoracion', path: '/madrid/valoracion/' },
  { key: 'blog', path: '/blog/' },
];

const modes = ['mobile', 'desktop'];
const requiredCells = pages.flatMap((page) => modes.map((mode) => `${page.key}/${mode}`));

const metricKeys = [
  'performance_score',
  'fcp_ms',
  'lcp_ms',
  'tbt_ms',
  'cls',
  'speed_index_ms',
  'ttfb_ms',
];

function validateInput() {
  let parsed;
  try {
    parsed = new URL(baseUrl);
  } catch {
    console.error('PERF_GATE=FAIL_CONFIG reason=invalid_base_url');
    process.exit(78);
  }
  if (!/^[a-z0-9.-]+$/.test(parsed.hostname)) {
    console.error('PERF_GATE=FAIL_CONFIG reason=invalid_base_url');
    process.exit(78);
  }
  if (expectedSha && !/^[0-9a-f]{40}$/.test(expectedSha)) {
    console.error('PERF_GATE=FAIL_CONFIG reason=invalid_expected_sha');
    process.exit(78);
  }
  if (gateMode !== 'baseline' && gateMode !== 'enforce') {
    console.error('PERF_GATE=FAIL_CONFIG reason=invalid_gate_mode');
    process.exit(78);
  }
  if (!Number.isInteger(requestTimeoutMs) || requestTimeoutMs < 10000 || requestTimeoutMs > 120000) {
    console.error('PERF_GATE=FAIL_CONFIG reason=invalid_timeout');
    process.exit(78);
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

function isValidMetrics(metrics) {
  return metrics !== null
    && typeof metrics === 'object'
    && metricKeys.every((key) => typeof metrics[key] === 'number' && Number.isFinite(metrics[key]));
}

function normalizeNavigationUrl(value) {
  if (typeof value !== 'string' || value.trim() === '') return '';
  try {
    const url = new URL(value);
    url.hash = '';
    return url.href;
  } catch {
    return '';
  }
}

function classifyLighthouseNavigation(lighthouseJson, expectedUrl) {
  const lh = typeof lighthouseJson === 'string' ? JSON.parse(lighthouseJson) : lighthouseJson;
  const expected = normalizeNavigationUrl(expectedUrl);
  const requested = normalizeNavigationUrl(lh.requestedUrl);
  const finalUrl = normalizeNavigationUrl(lh.finalUrl);
  const mainDocumentUrl = normalizeNavigationUrl(lh.mainDocumentUrl);
  const networkItems = lh.audits?.['network-requests']?.details?.items || [];
  const documentRequests = networkItems.filter((item) => item?.resourceType === 'Document');
  const observedUrls = [
    finalUrl,
    mainDocumentUrl,
    ...documentRequests.map((item) => normalizeNavigationUrl(item?.url)),
  ].filter(Boolean);

  const challengeUrl = observedUrls.find((value) => {
    try {
      return new URL(value).pathname.startsWith('/.well-known/sgcaptcha/');
    } catch {
      return false;
    }
  });
  const initial202 = documentRequests.find((item) => (
    Number(item?.statusCode) === 202
    && normalizeNavigationUrl(item?.url) === expected
  ));

  if (challengeUrl || initial202) {
    return {
      status: 'transient_infrastructure',
      diagnostic: `SiteGround challenge intercepted Lighthouse navigation expected=${expected} final=${finalUrl || 'missing'} main=${mainDocumentUrl || 'missing'} challenge=${challengeUrl || 'http_202'}`,
    };
  }

  if (!requested || requested !== expected) {
    return {
      status: 'lighthouse_execution_failed',
      diagnostic: `Lighthouse requested URL mismatch expected=${expected} requested=${requested || 'missing'}`,
    };
  }

  if (!finalUrl || !mainDocumentUrl || finalUrl !== expected || mainDocumentUrl !== expected) {
    return {
      status: 'lighthouse_execution_failed',
      diagnostic: `Lighthouse navigation escaped expected route expected=${expected} final=${finalUrl || 'missing'} main=${mainDocumentUrl || 'missing'}`,
    };
  }

  return null;
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
    || /TargetCloseError|Target closed/i.test(message)
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
      },
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
      if (classification === 'transient_infrastructure') transientCount += 1;
      runs.push({ attempt, status: classification, metrics: null, diagnostic });
      continue;
    }

    try {
      const rawJson = await fs.readFile(outFile, 'utf8');
      const lighthouse = JSON.parse(rawJson);
      const navigation = classifyLighthouseNavigation(lighthouse, url);
      if (navigation) {
        if (navigation.status === 'transient_infrastructure') transientCount += 1;
        runs.push({
          attempt,
          status: navigation.status,
          metrics: null,
          diagnostic: navigation.diagnostic,
        });
        continue;
      }

      const metrics = extractMetrics(lighthouse);
      if (!isValidMetrics(metrics)) {
        runs.push({
          attempt,
          status: 'invalid_json',
          metrics: null,
          diagnostic: 'JSON validation failed',
        });
        continue;
      }
      runs.push({ attempt, status: 'success', metrics });
    } catch (error) {
      const classification = classifyLighthouseError(error);
      if (classification === 'transient_infrastructure') transientCount += 1;
      runs.push({
        attempt,
        status: classification,
        metrics: null,
        diagnostic: String(error.message || error),
      });
    }
  }

  const successfulRuns = runs.filter((run) => run.status === 'success');
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
    cellResult.status = transientCount === cellResult.failures
      ? 'transient_infrastructure'
      : 'lighthouse_failed';
    const lastFailure = runs[runs.length - 1];
    if (lastFailure?.diagnostic) {
      const fullDiagnostic = lastFailure.diagnostic;
      cellResult.diagnostic = fullDiagnostic.length > 1000
        ? `${fullDiagnostic.slice(0, 1000)}... (truncated)`
        : fullDiagnostic;
    }
    return cellResult;
  }

  const medians = {};
  for (const key of metricKeys) {
    const values = successfulRuns
      .map((run) => run.metrics[key])
      .filter((value) => value !== null);
    medians[key] = values.length === 0
      ? null
      : key === 'cls'
        ? Math.round(median(values) * 10000) / 10000
        : Math.round(median(values));
  }

  cellResult.median = medians;
  cellResult.status = 'success';
  return cellResult;
}

async function readBaselineContract() {
  const raw = await fs.readFile(baselineContractPath, 'utf8');
  return JSON.parse(raw);
}

async function writeArtifacts(outputDir, cellResults, baselineContract, evaluation) {
  await fs.mkdir(path.join(outputDir, 'raw'), { recursive: true });

  const summaryTsvPath = path.join(outputDir, 'summary.tsv');
  const tsvHeader = [
    'page', 'mode', 'status', 'attempts', 'successes', 'transients',
    ...metricKeys,
  ].join('\t') + '\n';
  let tsvBody = '';
  for (const cell of cellResults) {
    const row = [
      cell.page,
      cell.mode,
      cell.status,
      cell.attempts,
      cell.successes,
      cell.transients,
      ...metricKeys.map((key) => cell.median?.[key] ?? 'N/A'),
    ];
    tsvBody += row.join('\t') + '\n';
  }
  await fs.writeFile(summaryTsvPath, tsvHeader + tsvBody, 'utf8');

  const summaryJson = {
    schema: 2,
    candidate_sha: expectedSha,
    base_url: baseUrl,
    lighthouse_version: lighthouseVersion,
    gate_mode: gateMode,
    generated_at: new Date().toISOString(),
    pages: pages.map((page) => page.path),
    modes,
    attempts_per_cell: attemptsPerCell,
    baseline_contract: baselineContract
      ? {
          path: baselineContractPath,
          schema: baselineContract.schema,
          status: baselineContract.status,
          approved_at: baselineContract.approved_at || null,
          generated_from: baselineContract.generated_from || [],
        }
      : null,
    results: cellResults,
  };
  await fs.writeFile(
    path.join(outputDir, 'summary.json'),
    JSON.stringify(summaryJson, null, 2) + '\n',
    'utf8',
  );

  if (evaluation) {
    await fs.writeFile(
      path.join(outputDir, 'regression-evaluation.json'),
      JSON.stringify(evaluation, null, 2) + '\n',
      'utf8',
    );
  }

  await fs.writeFile(
    path.join(outputDir, 'lighthouse-version.txt'),
    lighthouseVersion + '\n',
    'utf8',
  );
  await fs.writeFile(
    path.join(outputDir, 'candidate-sha.txt'),
    expectedSha + '\n',
    'utf8',
  );
}

async function main() {
  validateInput();

  const outputDir = path.resolve('scripts/staging2/performance-artifacts');
  await fs.rm(outputDir, { recursive: true, force: true });
  await fs.mkdir(path.join(outputDir, 'raw'), { recursive: true });

  console.log(`PERF_GATE=START mode=${gateMode} sha=${expectedSha} base=${baseUrl}`);
  console.log(`PERF_GATE_LIGHTHOUSE version=${lighthouseVersion}`);

  let baselineContract = null;
  if (gateMode === 'enforce') {
    try {
      baselineContract = await readBaselineContract();
    } catch (error) {
      const diagnostic = String(error.message || error);
      await writeArtifacts(outputDir, [], null, {
        schema: 1,
        status: 'config_error',
        reason: 'baseline_read_failed',
        path: baselineContractPath,
        diagnostic,
      });
      console.error(`PERF_GATE=FAIL_CONFIG reason=baseline_read_failed baseline=${baselineContractPath} diagnostic=${diagnostic}`);
      process.exit(78);
    }

    const validation = validatePerformanceBaselineContract(baselineContract, {
      lighthouseVersion,
      requiredCells,
      requireApproved: true,
    });
    if (!validation.ok) {
      await writeArtifacts(outputDir, [], baselineContract, {
        schema: 1,
        status: 'config_error',
        reason: validation.reason,
        path: baselineContractPath,
      });
      console.error(`PERF_GATE=FAIL_CONFIG reason=${validation.reason} baseline=${baselineContractPath}`);
      process.exit(78);
    }
    console.log(`PERF_GATE_BASELINE=PASS path=${baselineContractPath} sources=${baselineContract.generated_from.length} cells=${requiredCells.length}`);
  }

  const cellResults = [];
  for (const page of pages) {
    for (const mode of modes) {
      process.stdout.write(`  ${page.key}/${mode} ... `);
      const result = await runLighthouseCell(page, mode, outputDir);
      cellResults.push(result);
      console.log(
        result.status === 'success'
          ? `OK (median perf=${result.median.performance_score} lcp=${result.median.lcp_ms} cls=${result.median.cls} tbt=${result.median.tbt_ms} ttfb=${result.median.ttfb_ms})`
          : `SKIP (${result.status} successes=${result.successes}/${result.attempts})`,
      );
    }
  }

  console.log('\n--- Performance Summary ---');
  for (const cell of cellResults) {
    if (cell.status === 'success' && cell.median) {
      console.log(
        `  ${cell.page}/${cell.mode}: perf=${cell.median.performance_score} `
        + `lcp=${cell.median.lcp_ms}ms cls=${cell.median.cls} `
        + `tbt=${cell.median.tbt_ms}ms ttfb=${cell.median.ttfb_ms}ms`,
      );
    } else {
      console.log(`  ${cell.page}/${cell.mode}: ${cell.status}`);
    }
  }

  const transientCells = cellResults.filter((cell) => cell.status === 'transient_infrastructure');
  const incompleteCells = cellResults.filter((cell) => cell.status !== 'success');

  if (transientCells.length > 0) {
    console.error(`\nPERF_GATE_TRANSIENT cells=${transientCells.length}/${cellResults.length}`);
    for (const cell of transientCells) {
      console.error(`  ${cell.page}/${cell.mode}: ${cell.transients} transient of ${cell.attempts} attempts`);
    }
  }

  if (incompleteCells.length > 0) {
    const incompleteEvaluation = gateMode === 'enforce'
      ? {
          schema: 1,
          status: 'incomplete',
          reason: incompleteCells.every((cell) => cell.status === 'transient_infrastructure') ? 'transient_infrastructure' : 'incomplete_measurement',
          valid_cells: cellResults.length - incompleteCells.length,
          total_cells: cellResults.length,
          transient_cells: transientCells.length,
          incomplete_cells: incompleteCells.map((cell) => ({
            page: cell.page,
            mode: cell.mode,
            status: cell.status,
            successes: cell.successes,
            attempts: cell.attempts,
            transients: cell.transients,
          })),
        }
      : null;
    await writeArtifacts(outputDir, cellResults, baselineContract, incompleteEvaluation);

    if (gateMode === 'enforce') {
      if (incompleteCells.every((cell) => cell.status === 'transient_infrastructure')) {
        console.error(`\nPERF_GATE=INCOMPLETE mode=enforce valid_cells=${cellResults.length - incompleteCells.length}/${cellResults.length} transient_cells=${transientCells.length}`);
        process.exit(75);
      }
      console.error(`\nPERF_GATE=FAIL mode=enforce reason=incomplete_measurement valid_cells=${cellResults.length - incompleteCells.length}/${cellResults.length}`);
      process.exit(1);
    }
    console.error(`\nPERF_GATE=INCOMPLETE mode=baseline valid_cells=${cellResults.length - incompleteCells.length}/${cellResults.length} transient_cells=${transientCells.length}`);
    return;
  }

  if (gateMode === 'enforce') {
    const evaluation = evaluatePerformanceRegression(cellResults, baselineContract);
    await writeArtifacts(outputDir, cellResults, baselineContract, {
      schema: 1,
      status: evaluation.violations.length === 0 ? 'pass' : 'fail',
      ...evaluation,
    });

    if (evaluation.violations.length > 0) {
      console.error(`\nPERF_GATE=FAIL mode=enforce violations=${evaluation.violations.length}`);
      for (const violation of evaluation.violations) {
        console.error(
          `  ${violation.page}/${violation.mode} ${violation.metric}: ${violation.value} `
          + `(baseline=${violation.baseline ?? 'N/A'} allowed=${violation.allowed ?? 'N/A'} severity=${violation.severity})`,
        );
      }
      process.exit(1);
    }

    console.log(`\nPERF_GATE=PASS mode=enforce cells=${cellResults.length} violations=0 baseline=${baselineContractPath}`);
    return;
  }

  await writeArtifacts(outputDir, cellResults, null, null);
  console.log(`\nPERF_GATE=PASS mode=baseline cells=${cellResults.length} (non-blocking)`);
}

main().catch((error) => {
  console.error('PERF_GATE=FAIL', error);
  process.exit(1);
});

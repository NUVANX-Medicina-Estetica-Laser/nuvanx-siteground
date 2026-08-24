#!/usr/bin/env node

import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const {
  queryAll,
  buildAnalyses,
  redactReport,
  assertPrivateOutputBoundary,
} = require('../seo/gsc-full-analysis.js');
const { READONLY_SCOPE, resolveGscAuthOptions } = require('../seo/gsc-auth-options.js');
const { persistRedactedSearchAnalytics } = require('../seo/gsc-report-retention.js');

function row(query, page, clicks = 1, impressions = 10, position = 8) {
  return { keys: [query, page, 'esp', 'MOBILE'], clicks, impressions, ctr: clicks / impressions, position };
}

async function testPaginationSentinel() {
  const requests = [];
  const responses = [
    [{ keys: ['q1'] }, { keys: ['q2'] }],
    [],
  ];
  const queryFn = async (_sc, _site, body) => {
    requests.push(body);
    return responses.shift() ?? [];
  };

  const result = await queryAll(queryFn, {}, 'https://nuvanx.com/', { dimensions: ['query'] }, { rowLimit: 2, maxPages: 1 });
  assert.equal(result.rows.length, 2);
  assert.equal(result.pages, 1);
  assert.equal(result.pageExhausted, true);
  assert.equal(result.sentinelRequests, 1);
  assert.deepEqual(
    requests.map(({ rowLimit, startRow, dataState }) => ({ rowLimit, startRow, dataState })),
    [
      { rowLimit: 2, startRow: 0, dataState: 'final' },
      { rowLimit: 1, startRow: 2, dataState: 'final' },
    ]
  );
}

async function testPaginationCap() {
  let call = 0;
  const queryFn = async () => {
    call += 1;
    return call === 1 ? [{ keys: ['q1'] }, { keys: ['q2'] }] : [{ keys: ['q3'] }];
  };
  await assert.rejects(
    () => queryAll(queryFn, {}, 'https://nuvanx.com/', { dimensions: ['query'] }, { rowLimit: 2, maxPages: 1 }),
    /GSC_PAGINATION_CAP_REACHED_1x2/
  );
}

function testAuthSelection(root) {
  const authDir = join(root, 'auth');
  const { mkdirSync } = require('node:fs');
  mkdirSync(authDir, { recursive: true });

  const adc = resolveGscAuthOptions(authDir);
  assert.equal(adc.source, 'ADC');
  assert.deepEqual(adc.options.scopes, [READONLY_SCOPE]);
  assert.equal('credentials' in adc.options, false);

  const credentials = { type: 'service_account', client_email: 'runtime-test@example.invalid', private_key: 'not-a-real-key' };
  writeFileSync(join(authDir, 'credentials.json'), JSON.stringify(credentials));
  const json = resolveGscAuthOptions(authDir);
  assert.equal(json.source, 'PRIVATE_JSON');
  assert.deepEqual(json.options.scopes, [READONLY_SCOPE]);
  assert.equal(json.options.credentials.client_email, credentials.client_email);
}

function testPrivacyAndRetention(root) {
  const secretQuery = 'private-patient-intent-query-should-never-persist';
  const secretPage = 'https://nuvanx.com/private-query-page-marker/';
  const currentRows = [row(secretQuery, secretPage, 3, 30, 7)];
  const previousRows = [row(secretQuery, secretPage, 1, 20, 9)];
  const analyses = buildAnalyses(currentRows, previousRows, [], []);
  const windows = {
    current: { startDate: '2026-05-24', endDate: '2026-08-21' },
    previous: { startDate: '2026-02-23', endDate: '2026-05-23' },
  };
  const dataset = (rows) => ({ rows, pages: 1, pageExhausted: true, sentinelRequests: 0 });
  const redacted = redactReport(windows, {
    currentWeb: dataset(currentRows),
    previousWeb: dataset(previousRows),
    currentDaily: dataset([]),
    image: dataset([]),
    video: dataset([]),
  }, analyses);

  const serialized = JSON.stringify(redacted);
  assert.equal(serialized.includes(secretQuery), false);
  assert.equal(serialized.includes(secretPage), false);
  assert.equal(redacted.privacy.rawQueriesPersisted, false);
  assert.equal(redacted.privacy.exactClicksImpressionsCtrPositionPersisted, false);
  assert.equal(redacted.analyses.queryUrlOwnership.uniqueQueryCount, 1);
  assert.equal(redacted.analyses.previousWindowComparison.metricDeltasComputedInMemory, true);

  const indexingPath = join(root, 'indexing-results.json');
  writeFileSync(indexingPath, JSON.stringify({ generatedAt: 'test', totals: { urls: 1 } }));
  const retained = persistRedactedSearchAnalytics(indexingPath, redacted);
  assert.equal(retained.totals.urls, 1);
  assert.deepEqual(retained.searchAnalyticsRedacted, redacted);
  const retainedSerialized = readFileSync(indexingPath, 'utf8');
  assert.equal(retainedSerialized.includes(secretQuery), false);
  assert.equal(retainedSerialized.includes(secretPage), false);
}

function testPrivateOutputBoundary(root) {
  const workspace = join(root, 'public-workspace');
  const inside = join(workspace, 'gsc-private.json');
  const outside = join(root, 'private-output', 'gsc-private.json');
  const env = { GITHUB_ACTIONS: 'true', GITHUB_WORKSPACE: workspace };
  assert.throws(() => assertPrivateOutputBoundary(inside, env), /GSC_PRIVATE_OUTPUT_MUST_BE_OUTSIDE_PUBLIC_WORKSPACE/);
  assert.doesNotThrow(() => assertPrivateOutputBoundary(outside, env));
}

const root = mkdtempSync(join(tmpdir(), 'nvx-gsc-runtime-'));
try {
  await testPaginationSentinel();
  await testPaginationCap();
  testAuthSelection(root);
  testPrivacyAndRetention(root);
  testPrivateOutputBoundary(root);
  console.log('GSC_SEARCH_ANALYTICS_RUNTIME=PASS pagination=executed auth=executed redaction=executed retention=executed boundary=executed');
} finally {
  rmSync(root, { recursive: true, force: true });
}

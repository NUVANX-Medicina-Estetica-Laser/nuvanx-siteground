#!/usr/bin/env node

import { readFile } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../..');
const analysisPath = resolve(root, 'scripts/seo/gsc-full-analysis.js');
const clientPath = resolve(root, 'scripts/seo/gsc-client.js');
const authPath = resolve(root, 'scripts/seo/gsc-auth-options.js');
const inspectionPath = resolve(root, 'scripts/seo/index-pages.js');
const workflowPath = resolve(root, '.github/workflows/production.yml');
const runtimeContractPath = resolve(root, 'scripts/lint/test-gsc-search-analytics-runtime.mjs');

const [analysis, client, auth, inspection, workflow] = await Promise.all([
  readFile(analysisPath, 'utf8'),
  readFile(clientPath, 'utf8'),
  readFile(authPath, 'utf8'),
  readFile(inspectionPath, 'utf8'),
  readFile(workflowPath, 'utf8'),
]);

function fail(reason) {
  console.error(`GSC_SEARCH_ANALYTICS_CONTRACT=FAIL reason=${reason}`);
  process.exit(1);
}

for (const scriptPath of [analysisPath, clientPath, authPath, inspectionPath, runtimeContractPath]) {
  const syntax = spawnSync(process.execPath, ['--check', scriptPath], { encoding: 'utf8' });
  if (syntax.status !== 0) fail(`node_syntax:${scriptPath.split('/').pop()}`);
}

const requiredAnalysisMarkers = [
  'const ROW_LIMIT = 25000;',
  'startRow,',
  "dataState: 'final'",
  "['query', 'page', 'country', 'device']",
  "['query', 'page'], 'image'",
  "['query', 'page'], 'video'",
  'valoracion_medica_madrid',
  'endolift_papada_mandibula',
  'endolaser_corporal',
  'laser_co2_fraccionado',
  'exion:',
  'queryUrlOwnership',
  'positions4To20',
  'highImpressionLowCtr',
  'brandedVsNonBranded',
  'previousWindowComparison',
  'rawQueriesPersisted: false',
  'exactClicksImpressionsCtrPositionPersisted: false',
  'GSC_PRIVATE_OUTPUT_MUST_BE_OUTSIDE_PUBLIC_WORKSPACE',
  "apiAcquisition: 'unsupported_by_search_analytics_type_enum'",
  "since: '2026-08-13'",
  'GSC_SEARCH_ANALYTICS=PASS',
  'public_raw=0',
  'module.exports =',
];
for (const marker of requiredAnalysisMarkers) {
  if (!analysis.includes(marker)) fail(`analysis_marker_missing:${marker}`);
}

for (const forbidden of [
  'gsc-full-analysis.json',
  "type: 'generative'",
  "type: 'genAi'",
  'console.log(JSON.stringify',
  'console.dir(currentWeb',
]) {
  if (analysis.includes(forbidden)) fail(`unsafe_or_false_analysis_marker:${forbidden}`);
}

// Authentication ownership is centralized in gsc-auth-options.js. Validate the
// actual owner instead of coupling this contract to an implementation literal
// that no longer belongs in gsc-client.js.
for (const marker of [
  "const READONLY_SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';",
  "path.resolve(baseDir, 'credentials.json')",
  'const options = { scopes: [READONLY_SCOPE] };',
  'options.credentials = credentials;',
  "source: 'ADC'",
  "source: 'PRIVATE_JSON'",
]) {
  if (!auth.includes(marker)) fail(`auth_owner_contract_missing:${marker}`);
}

for (const marker of [
  "require('./gsc-auth-options')",
  'resolveGscAuthOptions(__dirname)',
  'new google.auth.GoogleAuth(options)',
  'searchanalytics.query',
]) {
  if (!client.includes(marker)) fail(`client_contract_missing:${marker}`);
}

for (const marker of [
  'async function runSearchAnalyticsProbe(',
  "require('./gsc-full-analysis')",
  'await runFullGscAnalysis();',
  'persistRedactedSearchAnalytics(indexingResultsPath, redacted);',
  'GSC_SEARCH_ANALYTICS_REDACTED_RETENTION=PASS target=indexing-results.json public_raw=0',
  'GSC_SEARCH_ANALYTICS_PROBE=PASS mode=non_blocking public_raw=0',
  'GSC_SEARCH_ANALYTICS_PROBE=FAIL mode=non_blocking public_raw=0',
  'await runSearchAnalyticsProbe(indexingResultsPath);',
]) {
  if (!inspection.includes(marker)) fail(`inspection_probe_missing:${marker}`);
}
if (inspection.includes('process.env.GSC_PRIVATE_OUTPUT')) fail('inspection_must_not_set_private_output');

for (const marker of [
  'google-github-actions/auth@7c6bc770dae815cd3e89ee6cdf493a5fab2cc093',
  'GCP_WORKLOAD_IDENTITY_PROVIDER',
  'GCP_SEARCH_CONSOLE_SERVICE_ACCOUNT',
  'SEARCH_CONSOLE_SERVICE_ACCOUNT_JSON',
  'SEARCH_CONSOLE_AUTH=WIF_ADC',
  'SEARCH_CONSOLE_AUTH=SERVICE_ACCOUNT_JSON_FALLBACK',
  'node scripts/seo/index-pages.js --property "$SEARCH_CONSOLE_PROPERTY"',
  'scripts/seo/artifacts/indexing-results.json',
]) {
  if (!workflow.includes(marker)) fail(`production_wif_contract_missing:${marker}`);
}

// Production artifacts are public-repository evidence. Never add raw Search
// Analytics files or a private-output path to that upload surface.
for (const forbidden of [
  'GSC_PRIVATE_OUTPUT:',
  'gsc-full-analysis.json',
  'gsc-search-analytics-private',
]) {
  if (workflow.includes(forbidden)) fail(`workflow_private_data_forbidden:${forbidden}`);
}

// Execute behavior, not only source markers. The runtime contract mocks Search
// Console pagination and tests auth selection, redaction, retention into the
// already-uploaded indexing evidence, and the private-output workspace boundary.
const runtime = spawnSync(process.execPath, [runtimeContractPath], { encoding: 'utf8' });
if (runtime.stdout) process.stdout.write(runtime.stdout);
if (runtime.stderr) process.stderr.write(runtime.stderr);
if (runtime.status !== 0) fail(`runtime_contract_exit:${runtime.status}`);
if (!runtime.stdout.includes('GSC_SEARCH_ANALYTICS_RUNTIME=PASS')) fail('runtime_contract_pass_marker_missing');

console.log('GSC_SEARCH_ANALYTICS_CONTRACT=PASS api=first_party data_state=final row_limit=25000 auth=wif_or_private_json privacy=redacted generative=ui_separate syntax=checked runtime=executed');

import fs from 'node:fs/promises';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { assertCanonicalPublishedPaths, loadPublishedPagesManifest, VIEWPORTS } from './published-pages-contract.mjs';
import { ensureTrustedPagesFile } from './trusted-pages-origin.mjs';
import {
  SITEGROUND_CAPTCHA_PATH,
  SITEGROUND_TRANSIENT_HTTP_STATUSES,
  EX_TEMPFAIL,
  isSiteGroundTransientResponse,
} from './siteground-transient-classifier.mjs';

const VIEWPORT_COUNT = VIEWPORTS.length;

// The historical outer-attempt setting is retained for diagnostics and backward
// compatibility, but transient-only evidence no longer causes another complete
// 228-case matrix inside the same runner. Repeating the entire matrix turned one
// SiteGround edge challenge into a CI time-budget failure. The wrapper now owns
// targeted transient recovery for only the affected route/viewport evidence.
const configuredMaxAttempts = Number.parseInt(process.env.BLOCK_C_MAX_ATTEMPTS || '1', 10) || 1;
const baseUrl = (process.env.BASE_URL || 'https://staging2.nuvanx.com').replace(/\/$/, '');
const expectedSha = (process.env.EXPECTED_SHA || '').trim();
const attemptScript = fileURLToPath(new URL('./block-c-matrix.mjs', import.meta.url));
const resultsUrl = new URL('./block-c-artifacts/block-c-results.json', import.meta.url);
const preloadDir = new URL('./block-c-artifacts/', import.meta.url);
const preloadUrl = new URL('./block-c-artifacts/trusted-pages-preload.mjs', import.meta.url);

async function prepareTrustedPagesPreload() {
  const pagesFile = (process.env.WORDPRESS_PAGES_FILE || '').trim();
  if (!pagesFile) {
    console.log('BLOCK_C_INVENTORY_SOURCE=public-rest (no WORDPRESS_PAGES_FILE provided)');
    return null;
  }

  const pages = JSON.parse(await fs.readFile(pagesFile, 'utf8'));
  if (!Array.isArray(pages)) throw new TypeError('Trusted WordPress page inventory must be an array');

  const normalizedPages = pages.map((page) => ({
    id: Number(page.id),
    link: String(page.link || ''),
    slug: String(page.slug || ''),
    post_type: page.post_type === 'post' ? 'post' : 'page',
    title: {
      rendered: typeof page.title === 'string' ? page.title : String(page.title?.rendered || ''),
    },
  }));

  for (const page of normalizedPages) {
    if (!page.link) {
      throw new Error(`Trusted WordPress content ${page.id} has empty link field`);
    }
    const url = new URL(page.link);
    if (url.hostname !== new URL(baseUrl).hostname) {
      throw new Error(`Trusted WordPress content ${page.id} points outside staging: ${page.link}`);
    }
  }

  const manifest = await loadPublishedPagesManifest();
  assertCanonicalPublishedPaths(
    normalizedPages.map((page) => new URL(page.link).pathname),
    manifest,
    'Trusted WordPress published-content inventory'
  );

  const pagePayload = JSON.stringify(normalizedPages.filter((page) => page.post_type === 'page'));
  const postPayload = JSON.stringify(normalizedPages.filter((page) => page.post_type === 'post'));
  const pagesEndpoint = `${baseUrl}/wp-json/wp/v2/pages`;
  const postsEndpoint = `${baseUrl}/wp-json/wp/v2/posts`;
  const source = `const nativeFetch = globalThis.fetch.bind(globalThis);\nconst pagesEndpoint = ${JSON.stringify(pagesEndpoint)};\nconst postsEndpoint = ${JSON.stringify(postsEndpoint)};\nconst pagesPayload = ${JSON.stringify(pagePayload)};\nconst postsPayload = ${JSON.stringify(postPayload)};\nglobalThis.fetch = async (input, init) => {\n  const rawUrl = typeof input === 'string' ? input : (input && typeof input.url === 'string' ? input.url : String(input));\n  if (rawUrl.startsWith(pagesEndpoint)) {\n    return new Response(pagesPayload, { status: 200, headers: { 'content-type': 'application/json; charset=utf-8', 'x-nvx-inventory-source': 'trusted-wp-cli' } });\n  }\n  if (rawUrl.startsWith(postsEndpoint)) {\n    return new Response(postsPayload, { status: 200, headers: { 'content-type': 'application/json; charset=utf-8', 'x-nvx-inventory-source': 'trusted-wp-cli' } });\n  }\n  return nativeFetch(input, init);\n};\n`;

  await fs.mkdir(preloadDir, { recursive: true });
  await fs.writeFile(preloadUrl, source, 'utf8');
  console.log(`BLOCK_C_INVENTORY_SOURCE=trusted-wp-cli pages=${JSON.parse(pagePayload).length} posts=${JSON.parse(postPayload).length}`);
  return preloadUrl.href;
}

async function runAttempt() {
  console.log(`BLOCK_C_ATTEMPT=1/${configuredMaxAttempts} mode=full-matrix-once targeted_recovery=wrapper`);
  const preloadModule = await prepareTrustedPagesPreload();
  const args = preloadModule ? ['--import', preloadModule, attemptScript] : [attemptScript];

  return new Promise((resolve, reject) => {
    const child = spawn(process.execPath, args, {
      env: process.env,
      stdio: 'inherit',
    });
    child.once('error', reject);
    child.once('exit', (code, signal) => {
      if (signal) {
        reject(new Error(`Block C full matrix terminated by signal ${signal}`));
        return;
      }
      resolve(Number.isInteger(code) ? code : 1);
    });
  });
}

function isAllowedSiteGroundAbort(networkErrors, route) {
  const expectedDocumentUrl = `${baseUrl}${String(route || '')}`;
  const captchaPrefix = `${baseUrl}${SITEGROUND_CAPTCHA_PATH}`;
  return (
    networkErrors.length === 0 ||
    networkErrors.every((msg) => {
      const message = String(msg || '').trim();
      if (!/net::ERR_ABORTED/i.test(message)) return false;
      return message.startsWith(expectedDocumentUrl) || message.startsWith(captchaPrefix);
    })
  );
}

function isAntiBotOnly(result, blockers, issues, status) {
  return (
    result.status === 'BLOCKED' &&
    blockers.length > 0 &&
    blockers.every((message) => /SiteGround Antibot challenge prevented visual validation/i.test(message)) &&
    issues.length === 0 &&
    (SITEGROUND_TRANSIENT_HTTP_STATUSES.has(status) ||
      (typeof result.finalUrl === 'string' && result.finalUrl.includes(SITEGROUND_CAPTCHA_PATH)))
  );
}

function isNavigationNoResponseOnly(result, blockers, issues, networkErrors, status) {
  return (
    result.status === 'BLOCKED' &&
    status === 0 &&
    result.geometry == null &&
    blockers.length > 0 &&
    blockers.every((message) => /^Navigation returned no HTTP response$/i.test(message)) &&
    issues.length === 0 &&
    isAllowedSiteGroundAbort(networkErrors, result.route) &&
    typeof result.finalUrl === 'string' &&
    result.finalUrl.startsWith(`${baseUrl}/`)
  );
}

function isNetworkIssueOnly(result, blockers, issues, networkErrors) {
  return (
    result.status === 'FIX' &&
    blockers.length === 0 &&
    issues.length > 0 &&
    issues.every((message) => /^\d+ same-origin network error\(s\)$/i.test(message)) &&
    networkErrors.length > 0
  );
}

function isRetryAbortOnly(networkErrors, expectedDocumentUrl) {
  return (
    networkErrors.length > 0 &&
    networkErrors.every((msg) => {
      const message = String(msg || '').trim();
      return /net::ERR_ABORTED/i.test(message) && message.startsWith(expectedDocumentUrl);
    })
  );
}

function isSiteGroundCaptchaRequestAbortOnly(networkErrors) {
  const captchaPrefix = `${baseUrl}${SITEGROUND_CAPTCHA_PATH}`;
  return (
    networkErrors.length > 0 &&
    networkErrors.every((msg) => {
      const message = String(msg || '').trim();
      return /net::ERR_ABORTED/i.test(message) && message.startsWith(captchaPrefix);
    })
  );
}

function isTransientFailure(result) {
  if (!result || result.status === 'PASS') return true;

  const blockers = Array.isArray(result.blockers) ? result.blockers.map(String) : [];
  const issues = Array.isArray(result.issues) ? result.issues.map(String) : [];
  const networkErrors = Array.isArray(result.networkErrors) ? result.networkErrors.map(String) : [];
  const status = Number(result.edgeHttpStatus ?? result.httpStatus ?? 0);

  if (isAntiBotOnly(result, blockers, issues, status)) return true;
  if (isNavigationNoResponseOnly(result, blockers, issues, networkErrors, status)) return true;

  const expectedDocumentUrl = `${baseUrl}${String(result.route || '')}`;
  const networkIssueOnly = isNetworkIssueOnly(result, blockers, issues, networkErrors);
  if (networkIssueOnly) {
    const retryAbortOnly = isRetryAbortOnly(networkErrors, expectedDocumentUrl);
    const siteGroundCaptchaRequestAbortOnly = isSiteGroundCaptchaRequestAbortOnly(networkErrors);
    return retryAbortOnly || siteGroundCaptchaRequestAbortOnly;
  }

  return false;
}

function isOriginVerifiedVisualInconclusive(result) {
  if (!result || result.status !== 'PASS') return false;
  if (result.externalInconclusive !== true || result.originVerified !== true) return false;
  if (result.visualValidation !== 'inconclusive-siteground-antibot' || result.geometry != null) return false;
  if (Number(result.originStatus || 0) !== 200) return false;
  if (expectedSha && String(result.originDeploySha || '') !== expectedSha) return false;

  const edgeStatus = Number(result.edgeHttpStatus ?? 0);
  const finalUrl = String(result.finalUrl || '');
  const networkErrors = Array.isArray(result.networkErrors) ? result.networkErrors.map(String) : [];

  if (isSiteGroundTransientResponse(edgeStatus, result.edgeHeaders || {}, finalUrl)) return true;
  if (edgeStatus === 0 && isAllowedSiteGroundAbort(networkErrors, result.route)) return true;
  return false;
}

async function readValidatedResults() {
  let results;
  try {
    results = JSON.parse(await fs.readFile(resultsUrl, 'utf8'));
  } catch (error) {
    console.error(`BLOCK_C_RESULTS_VALIDATION=RESULTS_UNAVAILABLE reason=${error.message}`);
    return null;
  }

  let manifest;
  try {
    manifest = await loadPublishedPagesManifest();
  } catch (error) {
    console.error(`BLOCK_C_RESULTS_VALIDATION=MANIFEST_INVALID reason=${error.message}`);
    return null;
  }

  const expectedResultsCount = manifest.length * VIEWPORT_COUNT;
  if (!Array.isArray(results) || results.length < expectedResultsCount || results.length % VIEWPORT_COUNT !== 0) {
    console.error(
      `BLOCK_C_RESULTS_VALIDATION=INVALID_RESULTS count=${Array.isArray(results) ? results.length : 'non-array'} min_expected=${expectedResultsCount}`
    );
    return null;
  }

  return results;
}

async function successfulResultsAreComplete() {
  const results = await readValidatedResults();
  if (!results) return { valid: false, complete: false, transientOnly: false, count: 0 };

  const nonPass = results.filter((result) => result.status !== 'PASS');
  if (nonPass.length > 0) {
    console.error(`BLOCK_C_PRODUCTION_ELIGIBILITY=INVALID_SUCCESS non_pass=${nonPass.length}`);
    return { valid: false, complete: false, transientOnly: false, count: nonPass.length };
  }

  const inconclusive = results.filter((result) => result.externalInconclusive === true);
  if (inconclusive.length === 0) {
    return { valid: true, complete: true, transientOnly: false, count: 0 };
  }

  const transientOnly = inconclusive.every(isOriginVerifiedVisualInconclusive);
  console.log(`BLOCK_C_PRODUCTION_ELIGIBILITY=${transientOnly ? 'TRANSIENT_INCONCLUSIVE' : 'INVALID_INCONCLUSIVE'} cases=${inconclusive.length}`);
  for (const result of inconclusive) {
    console.log(`BLOCK_C_INCONCLUSIVE route=${result.route} viewport=${result.viewport?.key || 'unknown'} edge_http=${result.edgeHttpStatus ?? 0} origin_http=${result.originStatus ?? 0}`);
  }

  return { valid: transientOnly, complete: false, transientOnly, count: inconclusive.length };
}

async function failedResultsAreTransient() {
  const results = await readValidatedResults();
  if (!results) return { transient: false, count: 0 };

  const failed = results.filter((result) => result.status !== 'PASS');
  if (failed.length === 0) return { transient: false, count: 0 };

  const transient = failed.every(isTransientFailure);
  console.log(`BLOCK_C_RETRY_CLASSIFICATION=${transient ? 'TRANSIENT_INFRASTRUCTURE' : 'REAL_FAILURE'} failed=${failed.length}${transient ? ' candidate_defect=not_established' : ' candidate_defect=established'}`);
  if (transient) {
    for (const result of failed) {
      console.log(`BLOCK_C_TRANSIENT route=${result.route} viewport=${result.viewport?.key || 'unknown'} status=${result.status} edge_http=${result.edgeHttpStatus ?? 0} effective_http=${result.httpStatus ?? 0}`);
    }
  }
  return { transient, count: failed.length };
}

try {
  await ensureTrustedPagesFile();
} catch (error) {
  console.error(`BLOCK_C_INVENTORY_BOOTSTRAP=FAIL reason=${error instanceof Error ? error.message : String(error)}`);
  process.exit(1);
}

let code;
try {
  code = await runAttempt();
} catch (error) {
  console.error(`BLOCK_C_RESILIENT=FAIL_REAL reason=${error.message}`);
  console.error(`BLOCK_C_RETRY_CLASSIFICATION=PRELOAD_OR_PROCESS_ERROR reason=${error.message} candidate_defect=unknown`);
  process.exit(1);
}

if (code === 0) {
  const completion = await successfulResultsAreComplete();
  if (completion.valid && completion.complete) {
    console.log('BLOCK_C_RESILIENT=PASS attempt=1');
    process.exit(0);
  }
  if (!completion.valid || !completion.transientOnly) {
    console.error('BLOCK_C_RESILIENT=FAIL_REAL attempt=1 reason=incomplete-or-invalid-success-evidence candidate_defect=established');
    process.exit(1);
  }

  console.warn(`BLOCK_C_RETRY_CLASSIFICATION=TRANSIENT_INFRASTRUCTURE cases=${completion.count} reason=siteground-antibot-visual-inconclusive candidate_defect=not_established`);
  console.warn('BLOCK_C_RESILIENT=DELEGATE_TARGETED_TRANSIENT_RECOVERY full_matrix_replay=disabled wrapper_exit=75');
  process.exit(EX_TEMPFAIL);
}

const failed = await failedResultsAreTransient();
if (!failed.transient) {
  console.error(`BLOCK_C_RESILIENT=FAIL_REAL attempt=1 wrapper_exit=${code || 1} candidate_defect=established`);
  process.exit(code || 1);
}

console.warn(`BLOCK_C_RETRY_CLASSIFICATION=TRANSIENT_INFRASTRUCTURE cases=${failed.count} reason=transient-network-or-challenge-failure candidate_defect=not_established`);
console.warn('BLOCK_C_RESILIENT=DELEGATE_TARGETED_TRANSIENT_RECOVERY full_matrix_replay=disabled wrapper_exit=75');
process.exit(EX_TEMPFAIL);

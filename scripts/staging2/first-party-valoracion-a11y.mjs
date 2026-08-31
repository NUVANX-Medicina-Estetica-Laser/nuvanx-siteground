import { chromium } from 'playwright';
import fs from 'node:fs/promises';
import path from 'node:path';
import {
  EX_TEMPFAIL,
  SITEGROUND_CAPTCHA_PATH,
  isSiteGroundCaptchaInterruption,
  isSiteGroundTransientResponse,
} from './siteground-transient-classifier.mjs';

const baseUrl = (process.env.BASE_URL || 'https://staging2.nuvanx.com').replace(/\/$/, '');
const expectedSha = (process.env.EXPECTED_SHA || '').trim();
const target = `${baseUrl}/madrid/valoracion/`;
const maxAttempts = 3;
const outDir = path.resolve('scripts/staging2/valoracion-artifacts');
const outFile = path.join(outDir, 'first-party-valoracion-a11y.json');
const expectedControls = ['firstname', 'lastname', 'phone', 'email', 'message', 'privacy'];

if (!/^[0-9a-f]{40}$/.test(expectedSha)) {
  console.error('VALORACION_FIRST_PARTY_A11Y=FAIL_REAL reason=EXPECTED_SHA_must_be_40_hex');
  process.exit(1);
}

await fs.mkdir(outDir, { recursive: true });
await fs.rm(outFile, { force: true });

function transient(attempt, reason) {
  return { transient: true, realFailure: false, attempt, reason };
}

function failure(attempt, reason, details = {}) {
  return { transient: false, realFailure: true, attempt, reason, ...details };
}

async function persistResult(result) {
  await fs.writeFile(outFile, `${JSON.stringify(result, null, 2)}\n`, 'utf8');
}

async function auditAttempt(browser, attempt) {
  const context = await browser.newContext({
    viewport: { width: 390, height: 844 },
    ignoreHTTPSErrors: true,
    userAgent: 'Mozilla/5.0 AppleWebKit/537.36 Chrome/151 Safari/537.36 NUVANX-Valoracion-A11y/1.0',
  });
  const page = await context.newPage();
  try {
    let response;
    try {
      response = await page.goto(target, { waitUntil: 'domcontentloaded', timeout: 45000 });
    } catch (error) {
      const currentUrl = page.url() || target;
      if (isSiteGroundCaptchaInterruption(error, currentUrl)) {
        return transient(attempt, `siteground_navigation_challenge:${error instanceof Error ? error.message : String(error)}`);
      }
      return failure(attempt, `navigation_failed:${error instanceof Error ? error.message : String(error)}`);
    }

    const status = Number(response?.status() || 0);
    const headers = response ? await response.allHeaders() : {};
    const currentUrl = page.url() || target;
    const transientHttp = isSiteGroundTransientResponse(status, headers, currentUrl);
    if (currentUrl.includes(SITEGROUND_CAPTCHA_PATH)) return transient(attempt, `siteground_captcha:${currentUrl}`);

    const deploySha = (await page.locator('meta[name="nvx-deploy-sha"]').getAttribute('content').catch(() => '')) || '';
    if (transientHttp && deploySha !== expectedSha) return transient(attempt, `siteground_http_challenge:${status}:sha=${deploySha || 'missing'}`);
    if (status !== 200 && !transientHttp) return failure(attempt, `unexpected_status:${status}`);
    if (deploySha !== expectedSha) return failure(attempt, `sha_mismatch:${deploySha || 'missing'}:${expectedSha}`);

    await page.waitForLoadState('load').catch(() => {});
    const form = page.locator('#nvx-valoracion-first-party-form[data-nvx-first-party-owner="1"] form[data-nvx-direct-form]').first();
    await form.scrollIntoViewIfNeeded().catch(() => {});
    if (await form.count() !== 1) return failure(attempt, 'first_party_form_missing', { status, deploySha });

    const state = await page.evaluate((names) => {
      const ownerCount = document.querySelectorAll('#nvx-valoracion-first-party-form[data-nvx-first-party-owner="1"]').length;
      const forms = document.querySelectorAll('#nvx-valoracion-first-party-form[data-nvx-first-party-owner="1"] form[data-nvx-direct-form]');
      const formNode = forms[0] || null;
      const controls = names.map((name) => {
        const node = formNode?.querySelector(`[name="${name}"]`) || null;
        const labels = node ? Array.from(node.labels || []) : [];
        const labelText = labels.map((label) => label.textContent || '').join(' ').replace(/\s+/g, ' ').trim();
        return {
          name,
          exists: Boolean(node),
          required: Boolean(node?.required || node?.getAttribute('aria-required') === 'true'),
          labelText,
          hasLabel: Boolean(labelText),
          type: String(node?.getAttribute('type') || node?.tagName || '').toLowerCase(),
        };
      });
      const submit = formNode?.querySelector('button[type="submit"], input[type="submit"]') || null;
      return {
        ownerCount,
        formCount: forms.length,
        controls,
        submitExists: Boolean(submit),
        browserHubSpotFrames: document.querySelectorAll('iframe[data-test-id^="embedded-form-"], .hs-form-frame, .hbspt-form, form.hs-form').length,
        browserHubSpotLoaders: Array.from(document.scripts).filter((script) => /forms\/embed\/|forms\/v2\.js/i.test(script.getAttribute('src') || '')).length,
        browserQaFields: document.querySelectorAll('input[name="nvx_is_test_lead"], input[name="nvx_test_run_id"]').length,
      };
    }, expectedControls);

    const issues = [];
    if (state.ownerCount !== 1) issues.push(`owner_count:${state.ownerCount}`);
    if (state.formCount !== 1) issues.push(`form_count:${state.formCount}`);
    for (const control of state.controls) {
      if (!control.exists) issues.push(`control_missing:${control.name}`);
      else {
        if (!control.required) issues.push(`required_state_missing:${control.name}`);
        if (!control.hasLabel) issues.push(`label_missing:${control.name}`);
      }
    }
    if (!state.submitExists) issues.push('submit_missing');
    if (state.browserHubSpotFrames !== 0) issues.push(`browser_hubspot_surface:${state.browserHubSpotFrames}`);
    if (state.browserHubSpotLoaders !== 0) issues.push(`browser_hubspot_loader:${state.browserHubSpotLoaders}`);
    if (state.browserQaFields !== 0) issues.push(`browser_qa_fields:${state.browserQaFields}`);

    let postRequests = 0;
    page.on('request', (request) => {
      if (request.method() !== 'POST') return;
      try {
        if (new URL(request.url()).pathname === '/madrid/valoracion/') postRequests += 1;
      } catch {
        // Non-URL request targets are irrelevant to the first-party form boundary.
      }
    });
    await page.evaluate(() => {
      const formNode = document.querySelector('#nvx-valoracion-first-party-form[data-nvx-first-party-owner="1"] form[data-nvx-direct-form]');
      const submit = formNode?.querySelector('button[type="submit"], input[type="submit"]');
      if (formNode && submit) formNode.requestSubmit(submit);
    });
    await page.waitForTimeout(250);

    const validation = await page.evaluate((names) => {
      const formNode = document.querySelector('#nvx-valoracion-first-party-form[data-nvx-first-party-owner="1"] form[data-nvx-direct-form]');
      return {
        invalidNames: names.filter((name) => {
          const node = formNode?.querySelector(`[name="${name}"]`);
          return Boolean(node && typeof node.matches === 'function' && node.matches(':invalid'));
        }),
        formInvalid: Boolean(formNode && typeof formNode.matches === 'function' && formNode.matches(':invalid')),
        activeName: document.activeElement?.getAttribute?.('name') || '',
      };
    }, expectedControls);

    for (const name of expectedControls) {
      if (!validation.invalidNames.includes(name)) issues.push(`native_invalid_state_missing:${name}`);
    }
    if (!validation.formInvalid) issues.push('form_invalid_state_missing');
    if (postRequests !== 0) issues.push(`unsafe_blank_submit_post_observed:${postRequests}`);

    return {
      transient: false,
      realFailure: issues.length > 0,
      attempt,
      status,
      deploySha,
      recoveredTransientHttp: Boolean(transientHttp),
      owner: 'first-party',
      state,
      validation,
      postRequests,
      issues,
    };
  } catch (error) {
    if (isSiteGroundCaptchaInterruption(error, page.url())) {
      return transient(attempt, `siteground_inspection_challenge:${error instanceof Error ? error.message : String(error)}`);
    }
    return failure(attempt, `audit_exception:${error instanceof Error ? error.message : String(error)}`);
  } finally {
    await context.close().catch(() => {});
  }
}

const browser = await chromium.launch({ headless: true, args: ['--no-sandbox'] });
let finalResult = null;
try {
  for (let attempt = 1; attempt <= maxAttempts; attempt += 1) {
    const result = await auditAttempt(browser, attempt);
    finalResult = result;
    await persistResult(result);
    if (!result.transient) break;
    console.warn(`VALORACION_FIRST_PARTY_A11Y=RETRY attempt=${attempt}/${maxAttempts} reason=${result.reason}`);
    if (attempt < maxAttempts) await new Promise((resolve) => setTimeout(resolve, 3000 * attempt));
  }
} finally {
  await browser.close().catch(() => {});
}

if (!finalResult) {
  const result = failure(0, 'no_result');
  await persistResult(result).catch(() => {});
  console.error('VALORACION_FIRST_PARTY_A11Y=FAIL_REAL reason=no_result');
  process.exit(1);
}
if (finalResult.transient) {
  console.error(`VALORACION_FIRST_PARTY_A11Y=TRANSIENT_EXHAUSTED attempts=${maxAttempts}`);
  process.exit(EX_TEMPFAIL);
}
if (finalResult.realFailure) {
  console.error(`VALORACION_FIRST_PARTY_A11Y=FAIL_REAL issues=${finalResult.issues.join(',')}`);
  process.exit(1);
}

console.log(`VALORACION_FIRST_PARTY_A11Y=PASS controls=${expectedControls.length} owner=1 browser_iframe=0 blank_submit_post=0 sha=${expectedSha}`);

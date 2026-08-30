import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import { randomUUID } from 'node:crypto';
import { chromium } from 'playwright';
import {
  assertHubSpotV4RuntimeContract,
  inspectHubSpotV4RuntimeContract,
} from './hubspot-v4-runtime-contract.mjs';
import { EX_TEMPFAIL, getGitHubEventPath, EXECUTION_PATHS } from './siteground-transient-classifier.mjs';

const BASE_URL = (process.env.BASE_URL || '').replace(/\/+$/, '');
const EXPECTED_BASE = 'https://staging2.nuvanx.com';
const ARTIFACT_PATH = new URL('./valoracion-artifacts/attribution-lineage-e2e.json', import.meta.url);
const EVENT_NAME = process.env.GITHUB_EVENT_NAME || '';
const REF_NAME = process.env.GITHUB_REF_NAME || '';
const EVENT_PATH = getGitHubEventPath(EVENT_NAME, REF_NAME);

if (EVENT_PATH === EXECUTION_PATHS.UNSUPPORTED_EVENT) {
  console.log(`ATTRIBUTION_LINEAGE_E2E=SKIP reason=unsupported_event event=${EVENT_NAME} ref=${REF_NAME}`);
  process.exit(0);
}

console.log(`ATTRIBUTION_LINEAGE_E2E=PATH path=${EVENT_PATH} event=${EVENT_NAME} ref=${REF_NAME}`);

if (!BASE_URL) throw new Error('BASE_URL environment variable is required for lineage E2E');
assert.equal(BASE_URL, EXPECTED_BASE, 'Lineage E2E is allowed only against canonical Staging2');

const qaToken = randomUUID().replaceAll('-', '');
const gclid = `NVXQA${qaToken}`;
const query = `utm_source=google&utm_medium=cpc&utm_campaign=nvx_lineage_e2e&gclid=${encodeURIComponent(gclid)}`;
const managedTarget = `${BASE_URL}/madrid/valoracion/?${query}`;
const modalTarget = `${BASE_URL}/?${query}`;

function delay(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function transientError(message) {
  const error = new Error(message);
  error.exitCode = EX_TEMPFAIL;
  return error;
}

function isTransient(error) {
  if (error?.exitCode === EX_TEMPFAIL) return true;
  if (error instanceof assert.AssertionError) return false;
  const message = error instanceof Error ? error.message : String(error);
  if (/CANDIDATE_REGRESSION|AssertionError/i.test(message)) return false;
  return /ERR_(?:CONNECTION|NAME|TIMED_OUT)|navigation.*timeout|net::|429|502|503|504|temporar/i.test(message);
}

async function assertHealthyNavigation(page, target) {
  const response = await page.goto(target, { waitUntil: 'domcontentloaded', timeout: 30_000 });
  if (!response || response.status() >= 500) {
    throw new Error(`Staging2 navigation unavailable: status=${response ? response.status() : 'none'}`);
  }
}

async function enableConsentAndSync(page) {
  const contractReady = await page.waitForFunction(
    () => Boolean(window.NUVANXAttributionContract?.getLeadId),
    null,
    { timeout: 15_000 }
  ).catch(() => null);
  assert.ok(contractReady, 'CANDIDATE_REGRESSION: NUVANXAttributionContract unavailable in page runtime');

  return page.evaluate(() => {
    window.wp_has_consent = (type) => type === 'marketing' || type === 'statistics';
    window.cmplz_has_consent = (type) => type === 'marketing' || type === 'statistics';
    document.dispatchEvent(new Event('wp_listen_for_consent_change'));
    document.dispatchEvent(new Event('cmplz_enable_category'));
    window.NUVANXAttributionContract?.getFirstTouch?.();
    document.dispatchEvent(new Event('wp_listen_for_consent_change'));
    window.NUVANXHubSpotAttributionSync?.syncExistingForms?.();
    return {
      env: window.nvxConversionEvents?.env || '',
      qa: window.nvxConversionEvents?.qa || {},
      formId: String(window.nvxConversionEvents?.forms?.valoracion || '').toLowerCase(),
      leadId: String(window.NUVANXAttributionContract?.getLeadId?.() || '').toLowerCase(),
    };
  });
}

function isQaTrue(val) {
  if (Array.isArray(val)) val = val[0];
  if (val === true || val === 1) return true;
  const str = String(val ?? '').toLowerCase();
  return str === 'true' || str === '1';
}

async function readNativeFields(page, formId) {
  return page.evaluate(async (expectedFormId) => {
    const forms = window.HubSpotFormsV4?.getForms?.() || [];
    const form = forms.find(
      (candidate) => String(candidate?.getFormId?.() || '').toLowerCase() === expectedFormId
    );
    if (!form) return null;

    await window.NUVANXHubSpotAttributionSync?.syncForm?.(form);
    const values = {};
    for (const field of await form.getFormFieldValues() || []) {
      const actual = String(field?.name || '');
      const canonical = actual.replace(/^\d+-\d+\//, '');
      if (!canonical) continue;
      let val = field?.value;
      if (Array.isArray(val) && val.length === 1) val = val[0];
      values[canonical] = val;
    }
    return values;
  }, formId);
}

async function resolveHubSpotFrame(page, formId) {
  const iframe = page.locator(
    `#nvx-hubspot-form iframe[data-test-id*="${formId}"], #nvx-hubspot-form iframe[data-test-id^="embedded-form-"], #nvx-hubspot-form iframe`
  ).first();

  try {
    await iframe.waitFor({ state: 'attached', timeout: 20_000 });
  } catch (error) {
    throw transientError(
      `HubSpot runtime iframe temporarily unavailable: ${error instanceof Error ? error.message : String(error)}`
    );
  }

  const handle = await iframe.elementHandle();
  const frame = handle ? await handle.contentFrame() : null;
  if (!frame) throw transientError('HubSpot runtime content frame temporarily unavailable');

  try {
    await frame.locator('form').first().waitFor({ state: 'attached', timeout: 20_000 });
  } catch (error) {
    throw transientError(
      `HubSpot runtime form temporarily unavailable: ${error instanceof Error ? error.message : String(error)}`
    );
  }

  return frame;
}

async function readVisibleFieldNames(frame) {
  return frame.locator(
    'input:not([type="hidden"]):not([type="submit"]):not([type="button"]):not([type="reset"]), textarea, select'
  ).evaluateAll((nodes) => {
    const isVisible = (node) => {
      const style = getComputedStyle(node);
      const rect = node.getBoundingClientRect();
      return style.display !== 'none'
        && style.visibility !== 'hidden'
        && Number.parseFloat(style.opacity || '1') > 0.01
        && rect.width > 1
        && rect.height > 1;
    };

    return nodes
      .filter(isVisible)
      .map((node) => String(node.getAttribute('name') || '').trim())
      .filter(Boolean);
  });
}

function sanitizedFrameSource(frame) {
  try {
    const url = new URL(frame.url());
    return `${url.origin}${url.pathname}`;
  } catch {
    return '';
  }
}

await fs.mkdir(new URL('./valoracion-artifacts/', import.meta.url), { recursive: true });
await fs.rm(ARTIFACT_PATH, { force: true });

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext();
const page = await context.newPage();

try {
  // Surface 1: canonical valoración landing. It intentionally owns only the native HubSpot form.
  await assertHealthyNavigation(page, managedTarget);
  assert.equal(
    await page.locator('[data-nvx-direct-form]').count(),
    0,
    'Canonical /madrid/valoracion/ must not render the first-party fallback beside HubSpot'
  );

  const managedState = await enableConsentAndSync(page);
  assert.equal(managedState.env, 'staging2', 'E2E must execute with server-owned staging2 context');
  assert.match(
    managedState.formId,
    /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/,
    'Browser form identity must be a server-provided canonical UUID'
  );
  assert.equal(managedState.qa?.is_test_lead, true, 'Staging2 QA identity must be server-owned and enabled');
  assert.match(
    String(managedState.qa?.test_run_id || ''),
    /^staging2-sha-[0-9a-f]{12}$/,
    'Staging2 QA run id must be deterministic and SHA-scoped'
  );
  assert.match(
    managedState.leadId,
    /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/,
    'Attribution contract must provide one session UUID v4'
  );

  const hubspotFormDiscovered = await page.waitForFunction((formId) => {
    const api = window.HubSpotFormsV4;
    if (!api || typeof api.getForms !== 'function') return false;
    return api.getForms().some(
      (candidate) => String(candidate?.getFormId?.() || '').toLowerCase() === formId
    );
  }, managedState.formId, { timeout: 20_000 }).catch(() => null);
  assert.ok(
    hubspotFormDiscovered,
    `CANDIDATE_REGRESSION: HubSpot V4 form ${managedState.formId} not registered in page runtime`
  );

  const hubspotFrame = await resolveHubSpotFrame(page, managedState.formId);
  const visibleFieldNames = await readVisibleFieldNames(hubspotFrame);
  const frameSource = sanitizedFrameSource(hubspotFrame);

  let nativeFields = null;
  const nativeDeadline = Date.now() + 10_000;
  while (Date.now() < nativeDeadline) {
    nativeFields = await readNativeFields(page, managedState.formId);
    const qaReady = isQaTrue(nativeFields?.nvx_is_test_lead);
    const nativeLineageReady = String(nativeFields?.nvx_lead_id || '').toLowerCase() === managedState.leadId
      && qaReady
      && String(nativeFields?.nvx_test_run_id || '') === String(managedState.qa.test_run_id)
      && String(nativeFields?.nvx_utm_source || '') === 'google'
      && String(nativeFields?.nvx_google_click_id || '') === gclid;
    if (nativeLineageReady) break;
    await delay(250);
  }

  const runtimeContract = inspectHubSpotV4RuntimeContract(nativeFields, visibleFieldNames);
  await fs.writeFile(
    ARTIFACT_PATH,
    `${JSON.stringify({
      schema: 5,
      environment: 'staging2',
      mode: 'zero_submit_v4_runtime_readback',
      form_id: managedState.formId,
      frame_source: frameSource,
      runtime_contract: runtimeContract,
      native_lineage_presence: {
        nvx_lead_id: Object.hasOwn(nativeFields || {}, 'nvx_lead_id'),
        nvx_is_test_lead: Object.hasOwn(nativeFields || {}, 'nvx_is_test_lead'),
        nvx_test_run_id: Object.hasOwn(nativeFields || {}, 'nvx_test_run_id'),
        nvx_utm_source: Object.hasOwn(nativeFields || {}, 'nvx_utm_source'),
        nvx_google_click_id: Object.hasOwn(nativeFields || {}, 'nvx_google_click_id'),
      },
      native_qa_value: nativeFields?.nvx_is_test_lead ?? null,
      submission_performed: false,
      verified_at: new Date().toISOString(),
    }, null, 2)}\n`,
    'utf8'
  );

  assert.ok(nativeFields, 'CANDIDATE_REGRESSION: canonical HubSpot V4 form readback unavailable');
  assertHubSpotV4RuntimeContract(runtimeContract);
  console.log(
    `HUBSPOT_V4_RUNTIME_CONTRACT=PASS form_id=${managedState.formId} required=${runtimeContract.required_fields.length} visible_lineage=0 frame=${frameSource || 'unknown'}`
  );

  assert.equal(String(nativeFields.nvx_lead_id || '').toLowerCase(), managedState.leadId);
  assert.ok(
    isQaTrue(nativeFields.nvx_is_test_lead),
    'Native HubSpot form must contain server-owned QA=true'
  );
  assert.equal(String(nativeFields.nvx_test_run_id || ''), String(managedState.qa.test_run_id));
  assert.equal(String(nativeFields.nvx_utm_source || ''), 'google');
  assert.equal(String(nativeFields.nvx_google_click_id || ''), gclid);
  console.log('HUBSPOT_NATIVE_READBACK=PASS lineage_fields=5 values_verified=5');

  // Surface 2: a normal public page owns the site-wide modal with the first-party fallback.
  // Reuse the same browser context/session to prove one lineage UUID crosses both surfaces.
  await assertHealthyNavigation(page, modalTarget);
  const directFormAttached = await page.waitForSelector(
    '[data-nvx-direct-form]',
    { state: 'attached', timeout: 15_000 }
  ).catch(() => null);
  assert.ok(
    directFormAttached,
    'CANDIDATE_REGRESSION: First-party fallback form [data-nvx-direct-form] missing from public modal page'
  );

  const modalState = await enableConsentAndSync(page);
  assert.equal(modalState.env, 'staging2');
  assert.equal(String(modalState.qa?.test_run_id || ''), String(managedState.qa.test_run_id));

  await page.evaluate(() => {
    document.dispatchEvent(new Event('wp_listen_for_consent_change'));
  });

  const directStateReady = await page.waitForFunction(
    ({ expectedLeadId, expectedGclid }) => {
      const form = document.querySelector('[data-nvx-direct-form]');
      if (!form) return false;
      const leadId = String(form.querySelector('[name="nvx_lead_id"]')?.value || '').toLowerCase();
      const marketing = String(form.querySelector('[name="nvx_marketing_consent"]')?.value || '');
      const gclidVal = String(form.querySelector('[name="gclid"]')?.value || '');
      const utmSource = String(form.querySelector('[name="utm_source"]')?.value || '');
      return leadId === expectedLeadId
        && marketing === '1'
        && gclidVal === expectedGclid
        && utmSource === 'google';
    },
    { expectedLeadId: managedState.leadId, expectedGclid: gclid },
    { timeout: 10_000 }
  ).catch(() => null);

  assert.ok(
    directStateReady,
    'CANDIDATE_REGRESSION: First-party fallback form did not synchronize attribution fields within deadline'
  );

  const directState = await page.evaluate(() => {
    const form = document.querySelector('[data-nvx-direct-form]');
    if (!form) return null;
    return {
      leadId: String(form.querySelector('[name="nvx_lead_id"]')?.value || '').toLowerCase(),
      marketing: String(form.querySelector('[name="nvx_marketing_consent"]')?.value || ''),
      gclid: String(form.querySelector('[name="gclid"]')?.value || ''),
      utmSource: String(form.querySelector('[name="utm_source"]')?.value || ''),
    };
  });

  assert.ok(directState, 'First-party modal fallback must remain present on eligible public pages');
  assert.equal(
    directState.leadId,
    managedState.leadId,
    'Native HubSpot and first-party fallback must share exactly one browser lineage UUID'
  );
  assert.equal(directState.marketing, '1');
  assert.equal(directState.gclid, gclid);
  assert.equal(directState.utmSource, 'google');

  // Deliberately zero-submit: acceptance must not create QA contacts in the commercial HubSpot portal
  // or production attribution rows. Server relay behavior is covered by deterministic contract tests.
  const evidence = {
    schema: 5,
    environment: 'staging2',
    source: EVENT_NAME,
    mode: 'zero_submit',
    form_id: managedState.formId,
    nvx_lead_id: managedState.leadId,
    gclid,
    test_run_id: String(managedState.qa.test_run_id),
    managed_surface: '/madrid/valoracion/',
    fallback_surface: '/',
    hubspot_frame_source: frameSource,
    hubspot_v4_runtime_contract: runtimeContract,
    native_form_lineage: true,
    direct_form_lineage: true,
    submission_performed: false,
    production_persistence_expected: false,
    verified_at: new Date().toISOString(),
  };
  await fs.writeFile(ARTIFACT_PATH, `${JSON.stringify(evidence, null, 2)}\n`, 'utf8');
  console.log(
    `ATTRIBUTION_LINEAGE_E2E=PASS mode=zero_submit source=hubspot_v4_runtime nvx_lead_id=${managedState.leadId} test_run_id=${evidence.test_run_id}`
  );
} catch (error) {
  const message = error instanceof Error ? error.message : String(error);
  console.error(`ATTRIBUTION_LINEAGE_E2E=${isTransient(error) ? 'TRANSIENT' : 'FAIL'} reason=${message}`);
  process.exitCode = isTransient(error) ? EX_TEMPFAIL : 1;
} finally {
  await context.close().catch(() => {});
  await browser.close().catch(() => {});
}

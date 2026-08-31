import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import { randomUUID } from 'node:crypto';
import { chromium } from 'playwright';
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
    document.dispatchEvent(new Event('wp_consent_type_defined'));

    return {
      env: window.nvxConversionEvents?.env || '',
      qa: window.nvxConversionEvents?.qa || {},
      formId: String(window.nvxConversionEvents?.forms?.valoracion || '').toLowerCase(),
      leadId: String(window.NUVANXAttributionContract?.getLeadId?.() || '').toLowerCase(),
    };
  });
}

async function waitForDirectLineage(page, expectedLeadId, expectedGclid, timeout = 10_000) {
  const ready = await page.waitForFunction(
    ({ leadId, clickId }) => {
      const form = document.querySelector('[data-nvx-direct-form]');
      if (!form) return false;
      const value = (name) => String(form.querySelector(`[name="${name}"]`)?.value || '');
      return value('nvx_lead_id').toLowerCase() === leadId
        && value('nvx_marketing_consent') === '1'
        && value('utm_source') === 'google'
        && value('gclid') === clickId;
    },
    { leadId: expectedLeadId, clickId: expectedGclid },
    { timeout }
  ).catch(() => null);

  assert.ok(ready, 'CANDIDATE_REGRESSION: first-party form did not synchronize canonical lineage within deadline');

  return page.evaluate(() => {
    const form = document.querySelector('[data-nvx-direct-form]');
    if (!form) return null;
    const value = (name) => String(form.querySelector(`[name="${name}"]`)?.value || '');
    return {
      leadId: value('nvx_lead_id').toLowerCase(),
      marketing: value('nvx_marketing_consent'),
      utmSource: value('utm_source'),
      utmMedium: value('utm_medium'),
      gclid: value('gclid'),
      browserQaFields: form.querySelectorAll('[name="nvx_is_test_lead"],[name="nvx_test_run_id"]').length,
    };
  });
}

await fs.mkdir(new URL('./valoracion-artifacts/', import.meta.url), { recursive: true });
await fs.rm(ARTIFACT_PATH, { force: true });

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext();
const page = await context.newPage();
const managedHubSpotFormRequests = [];

page.on('request', (request) => {
  const url = request.url();
  if (/hsforms\.(?:net|com)\/forms\/embed\//i.test(url)) {
    managedHubSpotFormRequests.push(url);
  }
});

try {
  // Surface 1: the managed landing owns exactly one first-party form. It must
  // not depend on the HubSpot browser iframe to preserve lineage.
  await assertHealthyNavigation(page, managedTarget);

  assert.equal(
    await page.locator('#nvx-hubspot-form [data-nvx-direct-form]').count(),
    1,
    'Canonical /madrid/valoracion/ must render exactly one first-party form'
  );
  assert.equal(
    await page.locator('#nvx-hubspot-form .hs-form-frame[data-form-id], #nvx-hubspot-form iframe[src*="hsforms"]').count(),
    0,
    'Canonical /madrid/valoracion/ must not expose a browser-owned HubSpot form'
  );
  assert.equal(
    await page.locator('#nvx-valoracion-first-party-form[data-nvx-first-party-owner="1"]').count(),
    1,
    'Canonical first-party output owner marker must be present'
  );

  const managedState = await enableConsentAndSync(page);
  assert.equal(managedState.env, 'staging2', 'E2E must execute with server-owned staging2 context');
  assert.match(
    managedState.formId,
    /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/,
    'Browser form identity must remain server-provided even though transport is server-side'
  );
  assert.equal(managedState.qa?.is_test_lead, true, 'Staging2 QA identity must remain server-owned and enabled');
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

  const managedDirect = await waitForDirectLineage(page, managedState.leadId, gclid);
  assert.ok(managedDirect, 'Managed first-party form must remain available');
  assert.equal(managedDirect.leadId, managedState.leadId);
  assert.equal(managedDirect.marketing, '1');
  assert.equal(managedDirect.utmSource, 'google');
  assert.equal(managedDirect.utmMedium, 'cpc');
  assert.equal(managedDirect.gclid, gclid);
  assert.equal(
    managedDirect.browserQaFields,
    0,
    'QA classification must not be browser-submittable; the secure bridge owns QA fields server-side'
  );

  // Allow deferred loaders to settle and prove the removed native owner is not
  // silently recreated after consent is granted.
  await page.waitForTimeout(1000);
  assert.equal(
    await page.locator('#nvx-hubspot-form .hs-form-frame[data-form-id], #nvx-hubspot-form iframe[src*="hsforms"]').count(),
    0,
    'Native HubSpot form must remain retired after consent events'
  );
  assert.equal(
    managedHubSpotFormRequests.length,
    0,
    `Managed landing unexpectedly requested HubSpot browser form assets: ${managedHubSpotFormRequests.join(',')}`
  );

  // Surface 2: a normal public page keeps the site-wide modal. Reuse the same
  // browser context/session and prove the same lineage UUID crosses both surfaces.
  await assertHealthyNavigation(page, modalTarget);
  const directFormAttached = await page.waitForSelector('[data-nvx-direct-form]', { state: 'attached', timeout: 15_000 }).catch(() => null);
  assert.ok(directFormAttached, 'CANDIDATE_REGRESSION: first-party modal form missing from public page');

  const modalState = await enableConsentAndSync(page);
  assert.equal(modalState.env, 'staging2');
  assert.equal(String(modalState.qa?.test_run_id || ''), String(managedState.qa.test_run_id));
  assert.equal(modalState.leadId, managedState.leadId, 'Managed landing and modal must share one browser lineage UUID');

  const modalDirect = await waitForDirectLineage(page, managedState.leadId, gclid);
  assert.ok(modalDirect, 'First-party modal form must remain present');
  assert.equal(modalDirect.leadId, managedState.leadId);
  assert.equal(modalDirect.marketing, '1');
  assert.equal(modalDirect.gclid, gclid);
  assert.equal(modalDirect.utmSource, 'google');
  assert.equal(modalDirect.browserQaFields, 0);

  // Deliberately zero-submit. The secure bridge, QA injection and capture relay
  // are covered by deterministic source contracts; acceptance must not create a
  // synthetic patient/contact in the commercial HubSpot portal.
  const evidence = {
    schema: 5,
    environment: 'staging2',
    source: EVENT_NAME,
    mode: 'zero_submit_first_party_secure_bridge',
    form_id: managedState.formId,
    nvx_lead_id: managedState.leadId,
    gclid,
    test_run_id: String(managedState.qa.test_run_id),
    managed_surface: '/madrid/valoracion/',
    modal_surface: '/',
    first_party_output_owner: true,
    native_hubspot_browser_owner: false,
    managed_browser_hubspot_form_requests: managedHubSpotFormRequests.length,
    direct_form_lineage: true,
    same_session_lineage: true,
    browser_qa_fields: false,
    server_qa_owner_expected: true,
    submission_performed: false,
    production_persistence_expected: false,
    verified_at: new Date().toISOString(),
  };
  await fs.writeFile(ARTIFACT_PATH, `${JSON.stringify(evidence, null, 2)}\n`, 'utf8');

  console.log(
    `ATTRIBUTION_LINEAGE_E2E=PASS mode=zero_submit_first_party_secure_bridge nvx_lead_id=${managedState.leadId} test_run_id=${evidence.test_run_id} browser_hubspot_requests=0`
  );
} catch (error) {
  const message = error instanceof Error ? error.message : String(error);
  console.error(`ATTRIBUTION_LINEAGE_E2E=${isTransient(error) ? 'TRANSIENT' : 'FAIL'} reason=${message}`);
  process.exitCode = isTransient(error) ? EX_TEMPFAIL : 1;
} finally {
  await context.close().catch(() => {});
  await browser.close().catch(() => {});
}

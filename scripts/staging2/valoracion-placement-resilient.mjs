import { chromium } from 'playwright';
import fs from 'node:fs/promises';
import path from 'node:path';
import {
  SITEGROUND_CAPTCHA_PATH,
  SITEGROUND_TRANSIENT_HTTP_STATUSES,
  EX_TEMPFAIL,
  isSiteGroundCaptchaInterruption,
  isSiteGroundTransientResponse,
} from './siteground-transient-classifier.mjs';

const baseUrl = (process.env.BASE_URL || 'https://staging2.nuvanx.com').replace(/\/$/, '');
const expectedSha = (process.env.EXPECTED_SHA || '').trim();
const valuationUrl = `${baseUrl}/madrid/valoracion/`;
const transientExitCode = EX_TEMPFAIL;
const maxAttempts = 5;
const outDir = path.resolve('scripts/staging2/valoracion-artifacts');

if (!/^[0-9a-f]{40}$/.test(expectedSha)) {
  console.error('VALORACION_PLACEMENT=FAIL_REAL reason=EXPECTED_SHA_must_be_40_hex');
  process.exit(1);
}

const viewports = [
  { key: 'desktop', width: 1440, height: 1100 },
  { key: 'tablet', width: 1024, height: 768 },
  { key: 'mobile', width: 390, height: 844 },
];

const expectedRequiredControls = ['firstname', 'lastname', 'phone', 'email', 'message', 'privacy'];
await fs.mkdir(outDir, { recursive: true });

function formatTransientReason(status, headers, currentUrl) {
  const reasons = [];
  if (SITEGROUND_TRANSIENT_HTTP_STATUSES.has(Number(status || 0))) reasons.push(`HTTP status ${status}`);
  if (headers && headers['sg-captcha']) reasons.push(`sg-captcha header (${headers['sg-captcha']})`);
  if (String(currentUrl).includes(SITEGROUND_CAPTCHA_PATH)) reasons.push(`captcha URL path (${SITEGROUND_CAPTCHA_PATH})`);
  return reasons.length ? `SiteGround challenge detected via: ${reasons.join(', ')}` : `SiteGround challenge HTTP ${status}`;
}

async function saveScreenshot(page, viewportKey, attempt, isTransient = false) {
  const filename = isTransient
    ? `valoracion-${viewportKey}-attempt-${attempt}-transient.jpg`
    : `valoracion-${viewportKey}-attempt-${attempt}.jpg`;
  await page.screenshot({
    path: path.join(outDir, filename),
    type: 'jpeg',
    quality: 78,
    fullPage: true,
  }).catch((error) => {
    if (!isTransient) throw error;
  });
}

function createTransientResult(status, currentUrl, reason, placement = null) {
  return {
    transient: true,
    status,
    currentUrl,
    reason,
    placement,
    issues: [reason],
  };
}

async function collectPlacement(page) {
  return page.evaluate((requiredNames) => {
    const visible = (element) => {
      if (!element) return false;
      const style = getComputedStyle(element);
      const rect = element.getBoundingClientRect();
      return style.display !== 'none'
        && style.visibility !== 'hidden'
        && Number.parseFloat(style.opacity || '1') > 0.01
        && rect.width > 1
        && rect.height > 1;
    };
    const root = document.getElementById('nvx-valoracion-main');
    const header = document.querySelector('header, .nvx-header, .nvx-site-header');
    const hero = root?.querySelector(':scope > .nvx-valoracion-hero, :scope > .nvx-brand-hero');
    const section = document.getElementById('nvx-hubspot-form');
    const owners = Array.from(document.querySelectorAll('#nvx-valoracion-first-party-form[data-nvx-first-party-owner="1"]'));
    const owner = owners[0] || null;
    const directForms = owner ? Array.from(owner.querySelectorAll('form[data-nvx-direct-form]')) : [];
    const directForm = directForms[0] || null;
    const requiredControls = requiredNames.map((name) => {
      const control = directForm?.querySelector(`[name="${name}"]`) || null;
      const labels = control ? Array.from(control.labels || []) : [];
      const labelText = labels.map((label) => label.textContent || '').join(' ').replace(/\s+/g, ' ').trim();
      return {
        name,
        exists: Boolean(control),
        visible: visible(control),
        required: Boolean(control?.required || control?.getAttribute('aria-required') === 'true'),
        labelText,
        hasLabel: Boolean(labelText),
      };
    });
    const submit = directForm?.querySelector('button[type="submit"], input[type="submit"]') || null;
    const heroRect = hero?.getBoundingClientRect();
    const sectionRect = section?.getBoundingClientRect();
    const browserHubSpotFrames = document.querySelectorAll(
      'iframe[data-test-id^="embedded-form-"], .hs-form-frame, .hbspt-form, form.hs-form'
    ).length;
    const browserHubSpotLoaders = Array.from(document.scripts).filter((script) => {
      const src = script.getAttribute('src') || '';
      return /forms\/embed\/|forms\/v2\.js/i.test(src);
    }).length;
    const browserQaFields = document.querySelectorAll(
      'input[name="nvx_is_test_lead"], input[name="nvx_test_run_id"]'
    ).length;

    return {
      headerVisible: visible(header),
      heroVisible: visible(hero),
      sectionVisible: visible(section),
      ownerCount: owners.length,
      ownerInsideSection: Boolean(owner && section?.contains(owner)),
      ownerVisible: visible(owner),
      directFormCount: directForms.length,
      directFormVisible: visible(directForm),
      requiredControls,
      submitExists: Boolean(submit),
      submitVisible: visible(submit),
      browserHubSpotFrames,
      browserHubSpotLoaders,
      browserQaFields,
      adjacent: Boolean(hero && section && hero.nextElementSibling === section),
      heroBottom: heroRect ? Math.round(heroRect.bottom) : null,
      sectionTop: sectionRect ? Math.round(sectionRect.top) : null,
    };
  }, expectedRequiredControls);
}

function validatePlacement(placement) {
  const issues = [];
  if (!placement.headerVisible) issues.push('Header/menu is not visible');
  if (!placement.heroVisible) issues.push('Valuation heading block is not visible');
  if (!placement.sectionVisible) issues.push('Conversion section #nvx-hubspot-form is not visible');
  if (placement.ownerCount !== 1) issues.push(`Expected one first-party owner, found ${placement.ownerCount}`);
  if (!placement.ownerInsideSection) issues.push('First-party owner is not inside the canonical conversion section');
  if (!placement.ownerVisible) issues.push('First-party owner is not visible');
  if (placement.directFormCount !== 1) issues.push(`Expected one direct first-party form, found ${placement.directFormCount}`);
  if (!placement.directFormVisible) issues.push('Direct first-party form is not visible');

  for (const control of placement.requiredControls) {
    if (!control.exists) issues.push(`Required control missing: ${control.name}`);
    else {
      if (!control.visible) issues.push(`Required control is not visible: ${control.name}`);
      if (!control.required) issues.push(`Required state is not programmatically exposed: ${control.name}`);
      if (!control.hasLabel) issues.push(`Accessible label missing: ${control.name}`);
    }
  }

  if (!placement.submitExists) issues.push('Submit control is missing');
  else if (!placement.submitVisible) issues.push('Submit control is not visible');
  if (placement.browserHubSpotFrames !== 0) issues.push(`Retired browser HubSpot form surface detected: ${placement.browserHubSpotFrames}`);
  if (placement.browserHubSpotLoaders !== 0) issues.push(`Retired browser HubSpot loader detected: ${placement.browserHubSpotLoaders}`);
  if (placement.browserQaFields !== 0) issues.push(`Server-owned QA fields leaked into browser form: ${placement.browserQaFields}`);
  if (!placement.adjacent) issues.push('Conversion section is not the immediate sibling after the page heading');
  if (placement.sectionTop !== null && placement.heroBottom !== null && placement.sectionTop < placement.heroBottom - 2) {
    issues.push('Conversion section overlaps the page heading');
  }
  return issues;
}

async function validateAttempt(context, viewport, attempt) {
  const page = await context.newPage();
  try {
    let response = null;
    let navError = null;
    try {
      response = await page.goto(valuationUrl, { waitUntil: 'domcontentloaded', timeout: 40000 });
    } catch (error) {
      navError = error;
    }

    const currentUrl = page.url() || '';
    if (navError) {
      const message = navError instanceof Error ? navError.message : String(navError);
      if (isSiteGroundCaptchaInterruption(navError, currentUrl)) {
        await saveScreenshot(page, viewport.key, attempt, true);
        return createTransientResult(0, currentUrl, `Captcha interruption: ${message}`);
      }
      await saveScreenshot(page, viewport.key, attempt, false);
      return { transient: false, status: 0, currentUrl, reason: message, placement: null, issues: [`Valuation navigation failed: ${message}`] };
    }

    const headers = response ? await response.allHeaders() : {};
    const status = response?.status() || 0;
    const transientStatus = isSiteGroundTransientResponse(status, headers, currentUrl);
    if (currentUrl.includes(SITEGROUND_CAPTCHA_PATH)) {
      await saveScreenshot(page, viewport.key, attempt, true);
      return createTransientResult(status, currentUrl, `SiteGround captcha challenge URL: ${currentUrl}`);
    }

    const issues = [];
    if (!response) issues.push('Valuation navigation returned no HTTP response');
    else if (status !== 200 && !transientStatus) issues.push(`Expected HTTP 200, got ${status}`);

    const metaSha = (await page.locator('meta[name="nvx-deploy-sha"]').getAttribute('content').catch(() => '')) || '';
    if (metaSha !== expectedSha) issues.push(`SHA mismatch ${metaSha || 'missing'} != ${expectedSha}`);

    await page.evaluate(async () => { if (document.fonts) await document.fonts.ready; }).catch(() => {});
    await page.waitForLoadState('load').catch(() => {});
    const section = page.locator('#nvx-hubspot-form');
    await section.scrollIntoViewIfNeeded().catch(() => {});
    await page.waitForTimeout(250).catch(() => {});

    const placement = await collectPlacement(page);
    issues.push(...validatePlacement(placement));

    if (transientStatus && issues.length > 0) {
      await saveScreenshot(page, viewport.key, attempt, true);
      return createTransientResult(status, page.url(), formatTransientReason(status, headers, page.url()), placement);
    }

    await saveScreenshot(page, viewport.key, attempt, false);
    if (transientStatus && issues.length === 0) {
      console.log(`RECOVERED /madrid/valoracion/ ${viewport.width}x${viewport.height} attempt=${attempt} HTTP ${status} -> exact first-party page`);
    }
    return {
      transient: false,
      status,
      recoveredTransientHttp: Boolean(transientStatus && issues.length === 0),
      currentUrl: page.url(),
      reason: '',
      placement,
      issues,
    };
  } catch (error) {
    if (isSiteGroundCaptchaInterruption(error, page.url())) {
      const message = error instanceof Error ? error.message : String(error);
      await saveScreenshot(page, viewport.key, attempt, true);
      return createTransientResult(0, page.url(), `Captcha redirection during inspection: ${message}`);
    }
    throw error;
  } finally {
    await page.close().catch(() => {});
  }
}

async function runViewport(browser, viewport) {
  const context = await browser.newContext({
    viewport: { width: viewport.width, height: viewport.height },
    ignoreHTTPSErrors: true,
    userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/151 Safari/537.36 NUVANX-Valoracion-QA/3.0',
  });
  try {
    const attempts = [];
    for (let attempt = 1; attempt <= maxAttempts; attempt += 1) {
      console.log(`VALORACION_ATTEMPT viewport=${viewport.key} attempt=${attempt}/${maxAttempts}`);
      const result = await validateAttempt(context, viewport, attempt);
      attempts.push({ attempt, ...result });
      const finalResult = { viewport, attempt, attempts, ...result };
      if (!result.transient || attempt === maxAttempts) return finalResult;
      const backoff = 2500 * attempt;
      console.warn(`VALORACION_TRANSIENT viewport=${viewport.key} attempt=${attempt} reason=${result.reason}`);
      console.log(`VALORACION_BACKOFF viewport=${viewport.key} delay_ms=${backoff}`);
      await new Promise((resolve) => setTimeout(resolve, backoff));
    }
    throw new Error('unreachable');
  } finally {
    await context.close();
  }
}

function reportViewport(result) {
  if (result.transient) {
    console.error(`VALORACION_PLACEMENT=TRANSIENT_EXHAUSTED viewport=${result.viewport.key} attempts=${maxAttempts}`);
    return 'transient';
  }
  if (result.issues.length) {
    console.error(`FIX /madrid/valoracion/ ${result.viewport.width}x${result.viewport.height}`);
    result.issues.forEach((issue) => console.error(`  ${issue}`));
    return 'real';
  }
  console.log(`PASS /madrid/valoracion/ ${result.viewport.width}x${result.viewport.height} owner=first-party browser_iframe=0`);
  return 'pass';
}

const browser = await chromium.launch({ headless: true, args: ['--no-sandbox'] });
const results = [];
let realFailure = false;
let transientExhausted = false;
try {
  for (const viewport of viewports) {
    const result = await runViewport(browser, viewport);
    results.push(result);
    const classification = reportViewport(result);
    realFailure ||= classification === 'real';
    transientExhausted ||= classification === 'transient';
  }
} finally {
  await browser.close().catch(() => {});
  await fs.writeFile(path.join(outDir, 'results.json'), `${JSON.stringify(results, null, 2)}\n`, 'utf8').catch((error) => {
    console.error(`Failed to write results.json: ${error instanceof Error ? error.message : String(error)}`);
  });
}

if (realFailure) {
  console.error('VALORACION_PLACEMENT=FAIL_REAL');
  process.exit(1);
}
if (transientExhausted) {
  if (process.env.GITHUB_ENV) {
    await fs.appendFile(process.env.GITHUB_ENV, 'STAGING_ACCEPTANCE_TRANSIENT=1\n', 'utf8').catch(() => {});
  }
  console.error('VALORACION_PLACEMENT=TRANSIENT_ONLY');
  process.exit(transientExitCode);
}

console.log('VALORACION_FIRST_PARTY_INTERACTIVITY=PASS owner=1 direct_form=1 browser_iframe=0 qa_browser_fields=0');
console.log('VALORACION_PLACEMENT=PASS');

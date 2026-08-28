import { chromium } from 'playwright';
import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  EX_CONFIG,
  EX_NOT_APPLICABLE,
  EX_TEMPFAIL,
  SITEGROUND_CAPTCHA_PATH,
  isSiteGroundTransientResponse,
} from './siteground-transient-classifier.mjs';
import { isIgnorableExternalConsoleError } from './console-error-classifier.mjs';
import { isExpectedClientResourceAbort } from './browser-request-failure-classifier.mjs';
import {
  BLOCK_C_BROWSER_CONFIG,
  BLOCK_C_BROWSER_UA,
  getCanonicalViewport,
} from './block-c-browser-config.mjs';
import {
  activateLazyImages,
  collectHomeGeometry,
  handleCookieConsent,
  waitForVisualStability,
} from './block-c-browser-visual-contract.mjs';
import { renderBlockCEvidence, writeEvidenceBundle } from './block-c-evidence.mjs';

const moduleDir = path.dirname(fileURLToPath(import.meta.url));
const artifactsDir = path.join(moduleDir, 'block-c-artifacts');
const screenshotDir = path.join(artifactsDir, 'screenshots');
const resultsPath = path.join(artifactsDir, 'block-c-results.json');
const matrixPath = path.join(artifactsDir, 'block-c-matrix.md');
const summaryPath = path.join(artifactsDir, 'block-c-summary.md');
const csvPath = path.join(artifactsDir, 'block-c-results.csv');
const baseUrl = (process.env.BASE_URL || 'https://staging2.nuvanx.com').replace(/\/$/, '');
const baseOrigin = new URL(baseUrl).origin;
const expectedHost = process.env.EXPECTED_HOST || new URL(baseUrl).hostname;
const expectedSha = (process.env.EXPECTED_SHA || '').trim();
// Keep targeted recovery intentionally small. A larger cluster of transient cases
// is treated as infrastructure instability and escalated to a fresh exact-SHA run
// instead of consuming an unbounded browser budget inside one runner.
const maxRecoveryCases = positiveIntegerEnv('BLOCK_C_TARGETED_RECOVERY_MAX_CASES', 3);

const shortContentRoutes = new Set([
  '/gracias/',
  '/politica-de-cookies-ue/',
  '/politica-privacidad/',
  '/aviso-legal/',
  '/politica-de-cookies/',
  '/mas-informacion-sobre-las-cookies/',
  '/eliminacion-datos-meta/',
]);

function positiveIntegerEnv(name, fallback) {
  const value = Number.parseInt(process.env[name] || '', 10);
  return Number.isInteger(value) && value > 0 ? value : fallback;
}

function sanitize(value) {
  return String(value ?? '').replace(/\s+/g, '_').slice(0, 600);
}

function logTransient(reason) {
  console.error(`BLOCK_C_TRANSIENT_RECOVERY=TRANSIENT_INFRASTRUCTURE reason=${sanitize(reason)} candidate_defect=not_established wrapper_exit=75`);
  return EX_TEMPFAIL;
}

function logRealFailure(reason) {
  console.error(`BLOCK_C_TRANSIENT_RECOVERY=FAIL_REAL reason=${sanitize(reason)} candidate_defect=established`);
  return 1;
}

function isSameOrigin(value) {
  try {
    return new URL(value).origin === baseOrigin;
  } catch {
    return false;
  }
}

function requestUrlFromError(message) {
  const normalized = String(message || '').trim();
  const separator = normalized.lastIndexOf(': ');
  return separator >= 0 ? normalized.slice(0, separator) : normalized;
}

function isAllowedSiteGroundAbort(message, documentUrl) {
  const normalized = String(message || '').trim();
  if (!/net::ERR_ABORTED/i.test(normalized)) return false;
  const requestUrl = requestUrlFromError(normalized);
  const captchaPrefix = `${baseUrl}${SITEGROUND_CAPTCHA_PATH}`;
  try {
    const request = new URL(requestUrl);
    const document = new URL(documentUrl);
    if (request.origin === document.origin && request.pathname === document.pathname) return true;
  } catch {
    // Fall through to the captcha-prefix check.
  }
  return requestUrl.startsWith(captchaPrefix);
}

function splitNetworkErrors(networkErrors, documentUrl) {
  const transient = [];
  const real = [];
  for (const message of networkErrors) {
    if (isAllowedSiteGroundAbort(message, documentUrl)) transient.push(message);
    else real.push(message);
  }
  return { transient, real };
}

async function findVisibleLocator(page, selector) {
  const locator = page.locator(selector);
  const count = await locator.count();
  for (let index = 0; index < count; index += 1) {
    const candidate = locator.nth(index);
    if (await candidate.isVisible().catch(() => false)) return candidate;
  }
  return null;
}

async function testResponsiveMenu(page, viewport, geometry, issues) {
  const compactNavigationExpected = viewport.width <= 480 || (!geometry.navVisible && geometry.navToggleVisible);
  if (!compactNavigationExpected) return;

  const label = viewport.width <= 480 ? 'Mobile' : `Tablet ${viewport.width}px`;
  const toggle = await findVisibleLocator(
    page,
    'button[aria-label*="menu" i], button[data-nvx-menu-toggle], .nvx-menu-toggle, .nav-toggle, button[aria-expanded]'
  );
  if (!toggle) {
    issues.push(`${label}: no visible menu toggle found`);
    return;
  }

  try {
    const beforeExpanded = await toggle.getAttribute('aria-expanded');
    const beforeVisibleMenuItems = await page.locator('header nav a:visible, .nvx-mobile-nav a:visible, .nvx-mobile-menu a:visible, [data-nvx-mobile-menu] a:visible').count();
    await toggle.click({ timeout: BLOCK_C_BROWSER_CONFIG.menuClickTimeoutMs });
    await page.waitForTimeout(BLOCK_C_BROWSER_CONFIG.menuSettleMs);
    const afterExpanded = await toggle.getAttribute('aria-expanded');
    const afterVisibleMenuItems = await page.locator('header nav a:visible, .nvx-mobile-nav a:visible, .nvx-mobile-menu a:visible, [data-nvx-mobile-menu] a:visible').count();
    const ariaOpened = beforeExpanded !== 'true' && afterExpanded === 'true';
    const linksExposed = afterVisibleMenuItems > beforeVisibleMenuItems && afterVisibleMenuItems > 0;
    if (!ariaOpened && !linksExposed) issues.push(`${label}: menu toggle did not expose navigation`);
    if (afterVisibleMenuItems === 0) issues.push(`${label}: compact navigation opened without visible links`);
    await page.keyboard.press('Escape').catch(() => {});
    await page.waitForTimeout(100);
  } catch (error) {
    issues.push(`${label}: menu toggle interaction failed: ${error instanceof Error ? error.message : String(error)}`);
  }
}

async function evaluateVisualContract({
  page,
  route,
  viewport,
  geometry,
  consoleErrors,
  networkErrors,
  imageHttpErrors,
  productionMediaLeaks,
}) {
  const issues = [];
  const tolerance = BLOCK_C_BROWSER_CONFIG.layoutTolerancePx;
  const shortContent = shortContentRoutes.has(route);

  if (!geometry.headerVisible) issues.push('Header is not visibly rendered');
  if (!geometry.footerVisible) issues.push('Footer is not visibly rendered');
  if (!geometry.mainVisible) issues.push('Main content is not visibly rendered');
  if (geometry.visibleH1Count !== 1) issues.push(`Expected 1 visible H1, found ${geometry.visibleH1Count}`);
  if (!geometry.h1Text) issues.push('H1 is empty or unreadable');
  if (geometry.h1Clipped) issues.push('H1 is clipped/truncated by its container');
  if (geometry.h1Rect && (geometry.h1Rect.left < -tolerance || geometry.h1Rect.right > viewport.width + tolerance)) issues.push('H1 extends outside viewport');
  if (geometry.h1Rect && geometry.h1Rect.top < -tolerance) issues.push(`H1 starts above viewport (${geometry.h1Rect.top}px)`);
  if (geometry.horizontalOverflowPx > tolerance) issues.push(`Horizontal viewport overflow: ${geometry.horizontalOverflowPx}px`);
  if (geometry.headerRect && (geometry.headerRect.left < -tolerance || geometry.headerRect.right > viewport.width + tolerance)) issues.push('Header extends outside viewport bounds');
  if (geometry.footerRect && (geometry.footerRect.left < -tolerance || geometry.footerRect.right > viewport.width + tolerance)) issues.push('Footer extends outside viewport bounds');
  if (!geometry.heroVisible && !shortContent) issues.push('Hero/intro is not visibly rendered');
  if (geometry.visibleCtaCount === 0 && !shortContent) issues.push('No visible CTA found');
  if (geometry.invalidCtas.length > 0) issues.push(`Invalid visible CTA href (#/empty): ${geometry.invalidCtas.join(' | ')}`);
  if (geometry.brokenImages.length > 0) issues.push(`Broken visible images: ${geometry.brokenImages.join(' | ')}`);
  if (geometry.unresolvedLazyImages.length > 0) issues.push(`Lazy images unresolved after full-page activation: ${geometry.unresolvedLazyImages.join(' | ')}`);
  if (imageHttpErrors.length > 0) issues.push(`Image request errors: ${[...new Set(imageHttpErrors)].slice(0, BLOCK_C_BROWSER_CONFIG.errorPreviewLimit).join(' | ')}`);
  if (productionMediaLeaks.length > 0) issues.push(`Staging media leaked to production host: ${[...new Set(productionMediaLeaks)].slice(0, BLOCK_C_BROWSER_CONFIG.errorPreviewLimit).join(' | ')}`);
  if (geometry.fontsStatus !== 'loaded') issues.push(`Fonts did not reach loaded state (${geometry.fontsStatus})`);
  if (!geometry.bodyFontFamily) issues.push('Body computed font-family is empty');
  if (geometry.runtimeDiagnostics?.length > 0) issues.push(`Visible PHP/runtime diagnostics: ${geometry.runtimeDiagnostics.join(' | ')}`);
  if (geometry.mainTextLength < BLOCK_C_BROWSER_CONFIG.minimumMainTextChars && !shortContent) issues.push(`Main readable text unexpectedly short (${geometry.mainTextLength} chars)`);
  if (geometry.visibleSectionCount < BLOCK_C_BROWSER_CONFIG.minimumSemanticSections && geometry.mainTextLength < BLOCK_C_BROWSER_CONFIG.minimumSectionFallbackTextChars && !shortContent) {
    issues.push(`Later sections may be missing; only ${geometry.visibleSectionCount} visible semantic sections and ${geometry.mainTextLength} chars`);
  }

  if (route === '/remodelacion-corporal-laser-madrid/' || route === '/tratamiento-postparto-abdomen-contorno-corporal-madrid/') {
    const signatureText = (await page.locator('main#nvx-main, main, [role="main"]').first().innerText().catch(() => ''))
      .replace(/\s+/g, ' ')
      .trim();
    const signatureRequirements = route === '/remodelacion-corporal-laser-madrid/'
      ? ['Cómo se decide el plan corporal', 'Zonas de valoración', 'Tu primera valoración clínica']
      : ['Qué se valora en postparto', 'Límites y cuándo esperamos o derivamos', 'Rutas relacionadas', 'Tu primera valoración clínica'];
    const minimumSections = route === '/remodelacion-corporal-laser-madrid/' ? 4 : 5;
    for (const phrase of signatureRequirements) {
      if (!signatureText.includes(phrase)) issues.push(`QA-10 Signature hub missing canonical section: ${phrase}`);
    }
    if (geometry.visibleSectionCount < minimumSections) issues.push(`QA-10 Signature hub too sparse (${geometry.visibleSectionCount} visible sections; expected at least ${minimumSections})`);
    if (geometry.mainTextLength < 1800) issues.push(`QA-10 Signature hub copy unexpectedly thin (${geometry.mainTextLength} characters)`);
    const canonicalValuationLinks = await page.locator('a[href*="/madrid/valoracion/"]').count();
    const staleValuationLinks = await page.locator('a[href*="/valoracion-medica/"]').count();
    if (canonicalValuationLinks < 1) issues.push('QA-10 Signature hub missing canonical /madrid/valoracion/ CTA');
    if (staleValuationLinks > 0) issues.push(`QA-10 Signature hub still exposes ${staleValuationLinks} stale /valoracion-medica/ CTA(s)`);
  }

  if (viewport.width >= 1024 && !geometry.navVisible && !geometry.navToggleVisible) issues.push('Desktop/tablet header navigation or menu toggle is not visible');
  await testResponsiveMenu(page, viewport, geometry, issues);
  if (consoleErrors.length > 0) issues.push(`${consoleErrors.length} browser console error(s)`);
  if (networkErrors.length > 0) issues.push(`${networkErrors.length} same-origin network error(s)`);
  return issues;
}

function targetIsEligible(result) {
  if (!result || !result.route || !result.viewport?.key) return false;
  if (result.route === '/') return false; // Home keeps its stricter dedicated recovery contract.
  if (result.externalInconclusive === true && result.originVerified === true) return true;
  return result.status !== 'PASS';
}

async function recoverTarget(browser, target) {
  const route = String(target.route || '');
  let viewport;
  try {
    viewport = getCanonicalViewport(target.viewport?.key || '');
  } catch (error) {
    return { type: 'config', error: error instanceof Error ? error.message : String(error) };
  }
  const url = `${baseUrl}${route}`;

  for (let attempt = 1; attempt <= BLOCK_C_BROWSER_CONFIG.maxAttempts; attempt += 1) {
    let context;
    try {
      context = await browser.newContext({
        viewport: { width: viewport.width, height: viewport.height },
        screen: { width: viewport.width, height: viewport.height },
        deviceScaleFactor: 1,
        ignoreHTTPSErrors: true,
        userAgent: BLOCK_C_BROWSER_UA,
        locale: 'es-ES',
        extraHTTPHeaders: {
          'Cache-Control': 'no-cache',
          Pragma: 'no-cache',
          'Accept-Language': 'es-ES,es;q=0.9,en;q=0.8',
        },
      });
      await context.addCookies([{ name: 'wpSGCacheBypass', value: '1', url }]);
      const page = await context.newPage();
      const consoleErrors = [];
      const ignoredConsoleErrors = [];
      const networkErrors = [];
      const imageHttpErrors = [];
      const productionMediaLeaks = [];

      page.on('console', (msg) => {
        if (msg.type() !== 'error') return;
        const text = msg.text();
        if (isIgnorableExternalConsoleError(text)) ignoredConsoleErrors.push(text);
        else if (!/Failed to load resource/i.test(text)) consoleErrors.push(text);
      });
      page.on('pageerror', (error) => consoleErrors.push(error.message));
      page.on('requestfailed', (request) => {
        const requestUrl = request.url();
        const resourceType = request.resourceType();
        const failureText = request.failure()?.errorText || 'request failed';
        const expectedAbort = isExpectedClientResourceAbort(resourceType, failureText);
        if (isSameOrigin(requestUrl) && !expectedAbort) networkErrors.push(`${requestUrl}: ${failureText}`);
        if (resourceType === 'image' && isSameOrigin(requestUrl) && !expectedAbort) imageHttpErrors.push(`${requestUrl}: ${failureText}`);
      });
      page.on('response', (resourceResponse) => {
        const resourceType = resourceResponse.request().resourceType();
        if (resourceType !== 'image' && resourceType !== 'media') return;
        const resourceUrl = resourceResponse.url();
        let parsed;
        try {
          parsed = new URL(resourceUrl);
        } catch {
          return;
        }
        if (isSameOrigin(resourceUrl) && resourceResponse.status() >= 400) {
          const message = `${resourceUrl}: HTTP ${resourceResponse.status()}`;
          if (resourceType === 'image') imageHttpErrors.push(message);
          else networkErrors.push(message);
        }
        if ((parsed.hostname === 'nuvanx.com' || parsed.hostname === 'www.nuvanx.com') && parsed.pathname.includes('/wp-content/uploads/')) {
          productionMediaLeaks.push(resourceUrl);
        }
      });

      let response = null;
      try {
        response = await page.goto(url, {
          waitUntil: 'domcontentloaded',
          timeout: BLOCK_C_BROWSER_CONFIG.navigationTimeoutMs,
        });
      } catch (error) {
        console.warn(`BLOCK_C_TRANSIENT_RECOVERY=RETRY route=${route} viewport=${viewport.key} attempt=${attempt} reason=navigation_error detail=${sanitize(error instanceof Error ? error.message : error)}`);
        await context.close().catch(() => {});
        if (attempt < BLOCK_C_BROWSER_CONFIG.maxAttempts) {
          await new Promise((resolve) => setTimeout(resolve, BLOCK_C_BROWSER_CONFIG.navigationErrorBackoffBaseMs * attempt));
          continue;
        }
        return { type: 'transient', reason: 'navigation_error_exhausted' };
      }

      const edgeHttpStatus = response?.status() || 0;
      const headers = response ? response.headers() : {};
      const finalUrl = page.url();
      const challenge = !response || isSiteGroundTransientResponse(edgeHttpStatus, headers, finalUrl);
      if (challenge) {
        console.warn(`BLOCK_C_TRANSIENT_RECOVERY=RETRY route=${route} viewport=${viewport.key} attempt=${attempt} reason=siteground_challenge edge_http=${edgeHttpStatus}`);
        await context.close().catch(() => {});
        if (attempt < BLOCK_C_BROWSER_CONFIG.maxAttempts) {
          await new Promise((resolve) => setTimeout(resolve, BLOCK_C_BROWSER_CONFIG.transientBackoffBaseMs * attempt));
          continue;
        }
        return { type: 'transient', reason: 'siteground_challenge_exhausted' };
      }

      const blockers = [];
      if (edgeHttpStatus !== 200) blockers.push(`Expected public HTTP 200, got ${edgeHttpStatus}`);
      if (new URL(finalUrl).hostname !== expectedHost) blockers.push(`Final hostname ${new URL(finalUrl).hostname} != ${expectedHost}`);

      await handleCookieConsent(page, BLOCK_C_BROWSER_CONFIG);
      await waitForVisualStability(page, BLOCK_C_BROWSER_CONFIG);
      await activateLazyImages(page, BLOCK_C_BROWSER_CONFIG);

      const metaSha = (await page.locator('meta[name="nvx-deploy-sha"]').getAttribute('content').catch(() => '')) || '';
      if (metaSha !== expectedSha) blockers.push(`Deployment SHA mismatch: ${metaSha || 'missing'} != ${expectedSha}`);
      const robots = (await page.locator('meta[name="robots"]').getAttribute('content').catch(() => '')) || '';
      const xRobots = headers['x-robots-tag'] || '';
      if (!robots.toLowerCase().includes('noindex') && !xRobots.toLowerCase().includes('noindex')) blockers.push('Staging noindex protection missing');

      const geometry = await collectHomeGeometry(page, BLOCK_C_BROWSER_CONFIG);
      const splitErrors = splitNetworkErrors(networkErrors, url);
      const issues = await evaluateVisualContract({
        page,
        route,
        viewport,
        geometry,
        consoleErrors,
        networkErrors: splitErrors.real,
        imageHttpErrors,
        productionMediaLeaks,
      });

      if (splitErrors.transient.length > 0 && blockers.length === 0 && issues.length === 0) {
        console.warn(`BLOCK_C_TRANSIENT_RECOVERY=RETRY route=${route} viewport=${viewport.key} attempt=${attempt} reason=siteground_network_abort`);
        await context.close().catch(() => {});
        if (attempt < BLOCK_C_BROWSER_CONFIG.maxAttempts) {
          await new Promise((resolve) => setTimeout(resolve, BLOCK_C_BROWSER_CONFIG.transientBackoffBaseMs * attempt));
          continue;
        }
        return { type: 'transient', reason: 'siteground_network_abort_exhausted' };
      }

      const screenshotStem = `${String(target.pageId || 'page')}-${route.replace(/^\/+|\/+$/g, '').replace(/[^a-z0-9]+/gi, '-').toLowerCase() || 'home'}--${viewport.key}--transient-recovery.jpg`;
      const screenshotPath = path.join(screenshotDir, screenshotStem);
      await fs.mkdir(screenshotDir, { recursive: true });
      try {
        await page.screenshot({
          path: screenshotPath,
          type: 'jpeg',
          quality: BLOCK_C_BROWSER_CONFIG.screenshotQuality,
          fullPage: true,
        });
      } catch (error) {
        console.warn(`BLOCK_C_TRANSIENT_RECOVERY=RETRY route=${route} viewport=${viewport.key} attempt=${attempt} reason=screenshot_error detail=${sanitize(error instanceof Error ? error.message : error)}`);
        await context.close().catch(() => {});
        if (attempt < BLOCK_C_BROWSER_CONFIG.maxAttempts) continue;
        return { type: 'transient', reason: 'screenshot_error_exhausted' };
      }

      await context.close().catch(() => {});
      if (blockers.length > 0 || issues.length > 0) {
        return {
          type: 'real',
          reason: [...blockers, ...issues].join('; '),
        };
      }

      return {
        type: 'pass',
        recovered: {
          ...target,
          status: 'PASS',
          httpStatus: edgeHttpStatus,
          edgeHttpStatus,
          finalUrl,
          metaSha,
          externalInconclusive: false,
          visualValidation: 'complete',
          recoveredExternalInconclusive: target.externalInconclusive === true,
          recoveredByTargetedPublicBrowser: true,
          recoveryAttempt: attempt,
          geometry,
          blockers: [],
          issues: [],
          consoleErrors: [],
          networkErrors: [],
          imageHttpErrors: [],
          productionMediaLeaks: [],
          screenshot: path.relative(artifactsDir, screenshotPath),
          fatal: null,
          notes: [
            ...(Array.isArray(target.notes) ? target.notes : []),
            `Targeted public-browser recovery completed full visual validation after transient infrastructure evidence (${route} · ${viewport.label}).`,
            ...(ignoredConsoleErrors.length > 0 ? [`${ignoredConsoleErrors.length} known third-party console error(s) ignored during targeted recovery.`] : []),
          ],
        },
      };
    } catch (error) {
      await context?.close().catch(() => {});
      return { type: 'transient', reason: `runner_exception ${error instanceof Error ? error.message : String(error)}` };
    }
  }

  return { type: 'transient', reason: 'attempt_budget_exhausted' };
}

async function main() {
  if (expectedHost !== 'staging2.nuvanx.com' || !/^[0-9a-f]{40}$/.test(expectedSha)) {
    console.error(`BLOCK_C_TRANSIENT_RECOVERY=FAIL_CONFIG host=${expectedHost} sha=${expectedSha || 'missing'} wrapper_exit=78`);
    return EX_CONFIG;
  }

  let results;
  try {
    results = JSON.parse(await fs.readFile(resultsPath, 'utf8'));
  } catch (error) {
    return logTransient(`results_unreadable ${error instanceof Error ? error.message : String(error)}`);
  }
  if (!Array.isArray(results) || results.length === 0) return logTransient('results_empty_or_invalid');

  const targets = results.filter(targetIsEligible);
  if (targets.length === 0) {
    console.error('BLOCK_C_TRANSIENT_RECOVERY=NOT_APPLICABLE cases=0 wrapper_exit=69');
    return EX_NOT_APPLICABLE;
  }
  if (targets.length > maxRecoveryCases) {
    return logTransient(`too_many_targeted_cases count=${targets.length} max=${maxRecoveryCases}`);
  }

  console.log(`BLOCK_C_TRANSIENT_RECOVERY=START cases=${targets.length} classification=transient_infrastructure candidate_defect=not_established`);

  let browser;
  try {
    browser = await chromium.launch({ headless: true, args: ['--no-sandbox', '--disable-setuid-sandbox'] });
  } catch (error) {
    return logTransient(`browser_launch ${error instanceof Error ? error.message : String(error)}`);
  }

  const recoveredByKey = new Map();
  try {
    for (const target of targets) {
      const outcome = await recoverTarget(browser, target);
      const key = `${target.route}::${target.viewport?.key || ''}`;
      if (outcome.type === 'pass') {
        recoveredByKey.set(key, outcome.recovered);
        console.log(`BLOCK_C_TRANSIENT_RECOVERY=CASE_PASS route=${target.route} viewport=${target.viewport?.key || 'unknown'} candidate_defect=not_established`);
        continue;
      }
      if (outcome.type === 'config') {
        console.error(`BLOCK_C_TRANSIENT_RECOVERY=FAIL_CONFIG route=${target.route} viewport=${target.viewport?.key || 'unknown'} reason=${sanitize(outcome.error)} wrapper_exit=78`);
        return EX_CONFIG;
      }
      if (outcome.type === 'real') return logRealFailure(`route=${target.route} viewport=${target.viewport?.key || 'unknown'} ${outcome.reason}`);
      return logTransient(`route=${target.route} viewport=${target.viewport?.key || 'unknown'} ${outcome.reason}`);
    }
  } finally {
    await browser.close().catch(() => {});
  }

  const recoveredResults = results.map((result) => recoveredByKey.get(`${result.route}::${result.viewport?.key || ''}`) || result);
  const remaining = recoveredResults.filter((result) => result.status !== 'PASS' || result.externalInconclusive === true);
  if (remaining.length > 0) return logTransient(`remaining_incomplete_cases count=${remaining.length}`);

  const recoverySummary = targets.map((target) => `- \`${target.route}\` · ${target.viewport?.label || target.viewport?.key || 'unknown'}: prior SiteGround/transient evidence revalidated through the public browser; candidate defect was not established by the transient event.`);
  const derived = renderBlockCEvidence(recoveredResults, {
    expectedSha,
    recoverySummary,
    recoverySectionTitle: 'Targeted transient visual recovery',
  });
  await writeEvidenceBundle([
    [matrixPath, derived.matrix],
    [summaryPath, derived.summary],
    [csvPath, derived.csv],
    [resultsPath, `${JSON.stringify(recoveredResults, null, 2)}\n`],
  ]);

  console.log(`BLOCK_C_TRANSIENT_RECOVERY=PASS cases=${targets.length} classification=recovered_transient_infrastructure candidate_defect=not_established visual_contract=complete`);
  return 0;
}

const code = await main().catch((error) => {
  console.error(`BLOCK_C_TRANSIENT_RECOVERY=FAIL_REAL reason=${sanitize(error instanceof Error ? error.message : String(error))} candidate_defect=unknown`);
  return 1;
});
process.exit(code);

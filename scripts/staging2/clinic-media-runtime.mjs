import { chromium } from 'playwright';
import fs from 'node:fs/promises';
import path from 'node:path';
import { VIEWPORTS } from './published-pages-contract.mjs';
import {
  SITEGROUND_CAPTCHA_PATH,
  isSiteGroundCaptchaInterruption,
  isSiteGroundTransientResponse,
} from './siteground-transient-classifier.mjs';

const baseUrl = (process.env.BASE_URL || 'https://staging2.nuvanx.com').replace(/\/$/, '');
const expectedSha = String(process.env.EXPECTED_SHA || '').trim();
const expectedHost = process.env.EXPECTED_HOST || new URL(baseUrl).hostname;
const outputPath = path.resolve('scripts/staging2/block-c-artifacts/clinic-media-runtime.json');

if (!/^[0-9a-f]{40}$/.test(expectedSha)) {
  console.error('CLINIC_MEDIA_RUNTIME=FAIL reason=expected_sha_invalid');
  process.exit(1);
}

const performanceRoutes = [
  { key: 'home', path: '/' },
  { key: 'clinics-hub', path: '/clinicas-de-medicina-estetica-nuvanx/' },
];
const clinicRoutes = [
  { key: 'chamberi', path: '/medicina-estetica-chamberi/', expectedGalleryCount: 4 },
  { key: 'goya', path: '/clinicas-de-medicina-estetica-nuvanx/medicina-estetica-goya-barrio-salamanca/', expectedGalleryCount: 2 },
];
const requiredViewportKeys = new Set(['desktop-1440x1100', 'tablet-1024x768', 'mobile-390x844']);
const viewports = VIEWPORTS.filter(({ key }) => requiredViewportKeys.has(key));
if (viewports.length !== requiredViewportKeys.size || ![...requiredViewportKeys].every((key) => viewports.some((viewport) => viewport.key === key))) {
  console.error(`CLINIC_MEDIA_RUNTIME=FAIL reason=viewport_contract count=${viewports.length}`);
  process.exit(1);
}

function fail(reason) { throw new Error(reason); }

function sameCanonicalPath(actualUrl, expectedPath) {
  try {
    const url = new URL(actualUrl);
    const actualPath = url.pathname.endsWith('/') ? url.pathname : `${url.pathname}/`;
    return url.hostname === expectedHost && actualPath === expectedPath;
  } catch { return false; }
}

async function installPerformanceObservers(page) {
  await page.addInitScript(() => {
    const describeElement = (element) => {
      if (!(element instanceof Element)) return null;
      return {
        tagName: element.tagName,
        id: element.id || '',
        className: typeof element.className === 'string' ? element.className : '',
        currentSrc: element instanceof HTMLImageElement ? element.currentSrc : '',
        text: String(element.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 180),
        inClinicGallery: Boolean(element.closest('.nvx-clinic-gallery')),
      };
    };
    window.__nvxClinicPerf = { lcp: null, cls: 0, clsEntries: 0 };
    try {
      new PerformanceObserver((list) => {
        for (const entry of list.getEntries()) {
          window.__nvxClinicPerf.lcp = {
            startTime: entry.startTime,
            renderTime: entry.renderTime,
            loadTime: entry.loadTime,
            size: entry.size,
            url: entry.url || '',
            element: describeElement(entry.element),
          };
        }
      }).observe({ type: 'largest-contentful-paint', buffered: true });
    } catch {}
    try {
      new PerformanceObserver((list) => {
        for (const entry of list.getEntries()) {
          if (!entry.hadRecentInput) {
            window.__nvxClinicPerf.cls += entry.value;
            window.__nvxClinicPerf.clsEntries += 1;
          }
        }
      }).observe({ type: 'layout-shift', buffered: true });
    } catch {}
  });
}

async function navigateForMeasurement(page, route, viewport) {
  const expectedUrl = `${baseUrl}${route.path}`;
  let response;
  try {
    response = await page.goto(expectedUrl, { waitUntil: 'domcontentloaded', timeout: 40000 });
  } catch (error) {
    if (isSiteGroundCaptchaInterruption(error, page.url())) {
      console.error(`CLINIC_MEDIA_RUNTIME=TRANSIENT route=${route.path} viewport=${viewport.key} reason=navigation_captcha`);
      return { transient: true, response: null };
    }
    throw error;
  }
  if (!response) {
    console.error(`CLINIC_MEDIA_RUNTIME=TRANSIENT route=${route.path} viewport=${viewport.key} reason=no_http_response`);
    return { transient: true, response: null };
  }
  const responseHeaders = response.headers();
  if (isSiteGroundTransientResponse(response.status(), responseHeaders, page.url()) || page.url().includes(SITEGROUND_CAPTCHA_PATH)) {
    console.error(`CLINIC_MEDIA_RUNTIME=TRANSIENT route=${route.path} viewport=${viewport.key} http=${response.status()}`);
    return { transient: true, response };
  }
  if (response.status() !== 200) fail(`route_http_${response.status()}:${route.key}:${viewport.key}`);
  if (!sameCanonicalPath(page.url(), route.path)) fail(`canonical_path_mismatch:${route.key}:${viewport.key}:${page.url()}`);
  await page.waitForLoadState('load', { timeout: 15000 }).catch(() => {});
  await page.waitForTimeout(1800);
  return { transient: false, response };
}

function assertInitialPerf(perf, route, viewport) {
  if (!perf?.lcp) fail(`lcp_unavailable:${route.key}:${viewport.key}`);
  if (typeof perf.cls !== 'number' || !Number.isFinite(perf.cls)) fail(`cls_unavailable:${route.key}:${viewport.key}`);
}

async function loadSelectedBodyBytes(page, url) {
  return page.evaluate(async (selectedUrl) => {
    const controller = new AbortController();
    const FETCH_TIMEOUT_MS = 15_000;
    const timer = setTimeout(() => controller.abort(), FETCH_TIMEOUT_MS);
    try {
      const response = await fetch(selectedUrl, { cache: 'reload', credentials: 'same-origin', signal: controller.signal });
      const bytes = (await response.arrayBuffer()).byteLength;
      return {
        status: response.status,
        url: response.url,
        headers: Object.fromEntries(response.headers.entries()),
        bytes,
        contentType: response.headers.get('content-type') || '',
        syntheticError: '',
      };
    } catch (err) {
      return {
        status: 0,
        url: selectedUrl,
        headers: {},
        bytes: 0,
        contentType: '',
        syntheticError: err instanceof Error ? err.name : String(err),
      };
    } finally { clearTimeout(timer); }
  }, url);
}

async function primeLazyImages(page, images) {
  const count = await images.count();
  for (let index = 0; index < count; index += 1) {
    await images.nth(index).scrollIntoViewIfNeeded();
    await page.waitForTimeout(75);
  }
}

async function readLoadedImage(image, includeResourceMetrics = false) {
  return image.evaluate(async (element, options) => {
    if (!(element instanceof HTMLImageElement)) return { isImage: false, outcome: 'missing' };
    let outcome = element.complete ? (element.naturalWidth > 0 ? 'load' : 'error') : 'pending';
    if (!element.complete) {
      outcome = await new Promise((resolve) => {
        let settled = false;
        let timer = null;
        const settle = (value) => {
          if (settled) return;
          settled = true;
          if (timer !== null) clearTimeout(timer);
          element.removeEventListener('load', onLoad);
          element.removeEventListener('error', onError);
          resolve(value);
        };
        const onLoad = () => settle('load');
        const onError = () => settle('error');
        timer = setTimeout(() => settle('timeout'), options.timeoutMs);
        element.addEventListener('load', onLoad, { once: true });
        element.addEventListener('error', onError, { once: true });
      });
    }
    const resource = options.includeResourceMetrics && element.currentSrc
      ? performance.getEntriesByName(element.currentSrc).filter((entry) => entry.entryType === 'resource').at(-1)
      : null;
    return {
      isImage: true,
      outcome,
      alt: element.getAttribute('alt') || '',
      loading: element.getAttribute('loading') || '',
      decoding: element.getAttribute('decoding') || '',
      widthAttr: Number.parseInt(element.getAttribute('width') || '0', 10) || 0,
      heightAttr: Number.parseInt(element.getAttribute('height') || '0', 10) || 0,
      naturalWidth: element.naturalWidth,
      naturalHeight: element.naturalHeight,
      src: element.src,
      currentSrc: element.currentSrc,
      srcset: element.getAttribute('srcset') || '',
      sizes: element.getAttribute('sizes') || '',
      complete: element.complete,
      perfTransferSize: Number(resource?.transferSize || 0),
      perfEncodedBodySize: Number(resource?.encodedBodySize || 0),
      perfDecodedBodySize: Number(resource?.decodedBodySize || 0),
    };
  }, { timeoutMs: 5000, includeResourceMetrics });
}

async function imageLoadFailureHasTransientEvidence(page, image, routePath, viewportKey, subject, index, outcome) {
  const probeUrl = image.currentSrc || image.src || '';
  if (!probeUrl) return false;
  let parsed;
  try { parsed = new URL(probeUrl); } catch { return false; }
  if (parsed.hostname !== expectedHost) return false;

  const body = await loadSelectedBodyBytes(page, probeUrl);
  if (body.syntheticError) {
    if (body.syntheticError !== 'AbortError') return false;
    console.error(
      `CLINIC_MEDIA_RUNTIME=TRANSIENT route=${routePath} viewport=${viewportKey}`
      + ` ${subject}=${index} reason=image_probe_timeout trigger=${outcome}`
    );
    return true;
  }
  if (isSiteGroundTransientResponse(body.status, body.headers, body.url) || body.url.includes(SITEGROUND_CAPTCHA_PATH)) {
    console.error(
      `CLINIC_MEDIA_RUNTIME=TRANSIENT route=${routePath} viewport=${viewportKey}`
      + ` ${subject}=${index} reason=image_${outcome}_transport_evidence http=${body.status}`
    );
    return true;
  }
  return false;
}

async function inspectEquipmentSection(page, route, viewport) {
  if (route.key !== 'clinics-hub') return null;
  const section = page.locator('section[data-nvx-approved-equipment-section]');
  if (await section.count() !== 1) fail(`equipment_section_count:${route.key}:${viewport.key}`);
  if (await section.getAttribute('data-nvx-approved-equipment-section') !== 'clinic-hub-v1') fail(`equipment_section_incomplete:${route.key}:${viewport.key}`);
  const cards = section.locator('article.nvx-clinics-equipment__card');
  if (await cards.count() !== 7) fail(`equipment_card_count:${route.key}:${viewport.key}:${await cards.count()}`);
  const equipmentImages = cards.locator('img.nvx-clinics-equipment__image');
  if (await equipmentImages.count() !== 7) fail(`equipment_image_count:${route.key}:${viewport.key}:${await equipmentImages.count()}`);
  await primeLazyImages(page, equipmentImages);

  const equipment = [];
  for (let index = 0; index < 7; index += 1) {
    const card = cards.nth(index);
    const copy = await card.evaluate((node) => ({
      title: node.querySelector('h3')?.textContent?.trim() || '',
      description: node.querySelector('p')?.textContent?.trim() || '',
    }));
    const image = await readLoadedImage(equipmentImages.nth(index));
    if (image.outcome === 'timeout' || image.outcome === 'error') {
      if (await imageLoadFailureHasTransientEvidence(page, image, route.path, viewport.key, 'equipment', index, image.outcome)) return { transient: true };
      if (image.outcome === 'timeout') fail(`equipment_image_load_timeout:${route.key}:${viewport.key}:${index}`);
      fail(`equipment_image_not_loaded:${route.key}:${viewport.key}:${index}`);
    }
    equipment.push({ ...copy, ...image });
  }
  for (const [index, item] of equipment.entries()) {
    if (!item.isImage) fail(`equipment_image_missing:${route.key}:${viewport.key}:${index}`);
    if (!item.title || !item.description || !item.alt) fail(`equipment_copy_missing:${route.key}:${viewport.key}:${index}`);
    if (item.loading !== 'lazy') fail(`equipment_not_lazy:${route.key}:${viewport.key}:${index}`);
    if (item.decoding !== 'async') fail(`equipment_not_async:${route.key}:${viewport.key}:${index}`);
    if (item.widthAttr < 1 || item.heightAttr < 1) fail(`equipment_intrinsic_attrs_missing:${route.key}:${viewport.key}:${index}`);
    if (!item.complete || item.naturalWidth < 1 || item.naturalHeight < 1 || !item.currentSrc) fail(`equipment_image_not_loaded:${route.key}:${viewport.key}:${index}`);
    const selectedUrl = new URL(item.currentSrc);
    if (selectedUrl.hostname !== expectedHost) fail(`equipment_current_src_cross_origin:${route.key}:${viewport.key}:${index}`);
    const body = await loadSelectedBodyBytes(page, item.currentSrc);
    if (body.syntheticError === 'AbortError' || isSiteGroundTransientResponse(body.status, body.headers, body.url) || body.url.includes(SITEGROUND_CAPTCHA_PATH)) {
      console.error(`CLINIC_MEDIA_RUNTIME=TRANSIENT route=${route.path} viewport=${viewport.key} equipment=${index} http=${body.status}`);
      return { transient: true };
    }
    if (body.syntheticError || body.status !== 200 || body.bytes < 1 || !/^image\//i.test(body.contentType)) {
      fail(`equipment_selected_resource_invalid:${route.key}:${viewport.key}:${index}:http=${body.status}:bytes=${body.bytes}:type=${body.contentType}:error=${body.syntheticError}`);
    }
  }
  return { transient: false, count: equipment.length };
}

async function inspectInitialRoute(browser, route, viewport) {
  const context = await browser.newContext({ viewport: { width: viewport.width, height: viewport.height }, ignoreHTTPSErrors: true, userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36' });
  const page = await context.newPage();
  await installPerformanceObservers(page);
  try {
    const navigation = await navigateForMeasurement(page, route, viewport);
    if (navigation.transient) return { transient: true };
    const perf = await page.evaluate(() => window.__nvxClinicPerf);
    assertInitialPerf(perf, route, viewport);
    const equipment = await inspectEquipmentSection(page, route, viewport);
    if (equipment?.transient) return { transient: true };
    return { transient: false, kind: 'performance', routeKey: route.key, route: route.path, viewport, finalUrl: page.url(), initialLcp: perf.lcp, initialCls: perf.cls, initialClsEntries: perf.clsEntries, equipment };
  } finally { await context.close(); }
}

async function inspectClinic(browser, clinic, viewport) {
  const context = await browser.newContext({ viewport: { width: viewport.width, height: viewport.height }, ignoreHTTPSErrors: true, userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36' });
  const page = await context.newPage();
  await installPerformanceObservers(page);
  try {
    const navigation = await navigateForMeasurement(page, clinic, viewport);
    if (navigation.transient) return { transient: true };
    const initial = await page.evaluate(() => {
      const gallery = document.querySelector('.nvx-clinic-gallery');
      const rect = gallery?.getBoundingClientRect() || null;
      return { perf: window.__nvxClinicPerf, gallery: gallery ? { top: rect.top, bottom: rect.bottom, initiallyBelowFold: rect.top >= window.innerHeight, imageCount: gallery.querySelectorAll('img.nvx-clinic-gallery__image').length } : null };
    });
    if (!initial.gallery) fail(`gallery_missing:${clinic.key}:${viewport.key}`);
    if (initial.gallery.imageCount !== clinic.expectedGalleryCount) fail(`gallery_image_count:${clinic.key}:${viewport.key}:${initial.gallery.imageCount}:expected=${clinic.expectedGalleryCount}`);
    assertInitialPerf(initial.perf, clinic, viewport);
    if (initial.perf.lcp.element?.inClinicGallery) fail(`gallery_is_initial_lcp:${clinic.key}:${viewport.key}`);
    const galleryImages = page.locator('img.nvx-clinic-gallery__image');
    if (await galleryImages.count() !== clinic.expectedGalleryCount) fail(`gallery_image_count_after_load:${clinic.key}:${viewport.key}:${await galleryImages.count()}:expected=${clinic.expectedGalleryCount}`);
    await primeLazyImages(page, galleryImages);
    const images = [];
    for (let index = 0; index < clinic.expectedGalleryCount; index += 1) {
      const image = await readLoadedImage(galleryImages.nth(index), true);
      if (image.outcome === 'timeout' || image.outcome === 'error') {
        if (await imageLoadFailureHasTransientEvidence(page, image, clinic.path, viewport.key, 'gallery', index, image.outcome)) return { transient: true };
        if (image.outcome === 'timeout') fail(`gallery_image_load_timeout:${clinic.key}:${viewport.key}:${index}`);
        fail(`gallery_image_not_loaded:${clinic.key}:${viewport.key}:${index}`);
      }
      images.push(image);
    }
    for (const [index, image] of images.entries()) {
      if (!image.isImage) fail(`gallery_image_missing:${clinic.key}:${viewport.key}:${index}`);
      if (image.loading !== 'lazy') fail(`gallery_not_lazy:${clinic.key}:${viewport.key}:${index}`);
      if (image.decoding !== 'async') fail(`gallery_not_async:${clinic.key}:${viewport.key}:${index}`);
      if (!image.currentSrc) fail(`gallery_current_src_missing:${clinic.key}:${viewport.key}:${index}`);
      if (!image.srcset) fail(`gallery_srcset_missing:${clinic.key}:${viewport.key}:${index}`);
      if (!image.sizes) fail(`gallery_sizes_missing:${clinic.key}:${viewport.key}:${index}`);
      if (image.widthAttr < 1 || image.heightAttr < 1) fail(`gallery_intrinsic_attrs_missing:${clinic.key}:${viewport.key}:${index}`);
      if (!image.complete || image.naturalWidth < 1 || image.naturalHeight < 1) fail(`gallery_image_not_loaded:${clinic.key}:${viewport.key}:${index}`);
      const selectedUrl = new URL(image.currentSrc);
      if (selectedUrl.hostname !== expectedHost) fail(`gallery_current_src_cross_origin:${clinic.key}:${viewport.key}:${index}`);
      const body = await loadSelectedBodyBytes(page, image.currentSrc);
      if (body.syntheticError === 'AbortError' || isSiteGroundTransientResponse(body.status, body.headers, body.url) || body.url.includes(SITEGROUND_CAPTCHA_PATH)) {
        console.error(`CLINIC_MEDIA_RUNTIME=TRANSIENT route=${clinic.path} viewport=${viewport.key} resource=${index} http=${body.status}`);
        return { transient: true };
      }
      if (body.syntheticError || body.status !== 200 || body.bytes < 1 || !/^image\//i.test(body.contentType)) fail(`gallery_selected_resource_invalid:${clinic.key}:${viewport.key}:${index}:http=${body.status}:bytes=${body.bytes}:type=${body.contentType}:error=${body.syntheticError}`);
      image.selectedBodyBytes = body.bytes;
      image.selectedContentType = body.contentType;
      image.over500KiB = body.bytes > (500 * 1024);
    }
    const totalSelectedBodyBytes = images.reduce((sum, image) => sum + image.selectedBodyBytes, 0);
    const selectedOver500KiB = images.filter((image) => image.over500KiB).length;
    return { transient: false, kind: 'clinic', clinic: clinic.key, route: clinic.path, viewport, finalUrl: page.url(), expectedGalleryCount: clinic.expectedGalleryCount, initialLcp: initial.perf.lcp, initialCls: initial.perf.cls, initialClsEntries: initial.perf.clsEntries, galleryInitiallyBelowFold: initial.gallery.initiallyBelowFold, galleryTop: initial.gallery.top, images, totalSelectedBodyBytes, selectedOver500KiB };
  } finally { await context.close(); }
}

await fs.mkdir(path.dirname(outputPath), { recursive: true });
const browser = await chromium.launch({ headless: true, args: ['--no-sandbox', '--disable-setuid-sandbox'] });
const results = [];
let transient = false;
try {
  for (const route of performanceRoutes) {
    for (const viewport of viewports) {
      const result = await inspectInitialRoute(browser, route, viewport);
      if (result.transient) { transient = true; continue; }
      results.push(result);
      console.log(`CLINIC_MEDIA_RUNTIME_CASE=PASS kind=performance route=${route.key} viewport=${viewport.key} lcp_tag=${result.initialLcp.element?.tagName || 'unknown'} cls=${result.initialCls.toFixed(4)} equipment=${result.equipment?.count || 0}`);
    }
  }
  for (const clinic of clinicRoutes) {
    for (const viewport of viewports) {
      const result = await inspectClinic(browser, clinic, viewport);
      if (result.transient) { transient = true; continue; }
      results.push(result);
      console.log(`CLINIC_MEDIA_RUNTIME_CASE=PASS kind=clinic clinic=${clinic.key} viewport=${viewport.key} gallery_count=${result.images.length} expected_gallery_count=${result.expectedGalleryCount} lcp_tag=${result.initialLcp.element?.tagName || 'unknown'} lcp_gallery=${result.initialLcp.element?.inClinicGallery ? 1 : 0} cls=${result.initialCls.toFixed(4)} selected_bytes=${result.totalSelectedBodyBytes} over_500k=${result.selectedOver500KiB}`);
    }
  }
} finally { await browser.close(); }

const expectedCases = (performanceRoutes.length + clinicRoutes.length) * viewports.length;
await fs.writeFile(outputPath, `${JSON.stringify({ schema: 2, expectedSha, cases: results }, null, 2)}\n`, 'utf8');
if (transient) process.exit(75);
if (results.length !== expectedCases) {
  console.error(`CLINIC_MEDIA_RUNTIME=FAIL reason=incomplete_cases actual=${results.length} expected=${expectedCases}`);
  process.exit(1);
}
console.log(`CLINIC_MEDIA_RUNTIME=PASS cases=${results.length} artifact=${outputPath}`);

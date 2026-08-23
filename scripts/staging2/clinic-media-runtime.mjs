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

const clinicRoutes = [
  { key: 'chamberi', path: '/medicina-estetica-chamberi/' },
  { key: 'goya', path: '/clinicas-de-medicina-estetica-nuvanx/medicina-estetica-goya-barrio-salamanca/' },
];

const viewports = VIEWPORTS.filter(({ key }) => key === 'desktop-1440x1100' || key === 'mobile-390x844');
if (viewports.length !== 2) {
  console.error(`CLINIC_MEDIA_RUNTIME=FAIL reason=viewport_contract count=${viewports.length}`);
  process.exit(1);
}

function fail(reason) {
  throw new Error(reason);
}

function sameCanonicalPath(actualUrl, expectedPath) {
  try {
    const url = new URL(actualUrl);
    const actualPath = url.pathname.endsWith('/') ? url.pathname : `${url.pathname}/`;
    return url.hostname === expectedHost && actualPath === expectedPath;
  } catch {
    return false;
  }
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

    window.__nvxClinicPerf = {
      lcp: null,
      cls: 0,
      clsEntries: 0,
    };

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
    } catch {
      // Structural validation below fails if LCP remains unavailable.
    }

    try {
      new PerformanceObserver((list) => {
        for (const entry of list.getEntries()) {
          if (!entry.hadRecentInput) {
            window.__nvxClinicPerf.cls += entry.value;
            window.__nvxClinicPerf.clsEntries += 1;
          }
        }
      }).observe({ type: 'layout-shift', buffered: true });
    } catch {
      // Structural validation below fails if CLS cannot be read as a number.
    }
  });
}

async function loadSelectedBodyBytes(page, url) {
  return page.evaluate(async (selectedUrl) => {
    const response = await fetch(selectedUrl, { cache: 'reload', credentials: 'same-origin' });
    const bytes = (await response.arrayBuffer()).byteLength;
    return {
      status: response.status,
      url: response.url,
      headers: Object.fromEntries(response.headers.entries()),
      bytes,
      contentType: response.headers.get('content-type') || '',
    };
  }, url);
}

async function inspectClinic(browser, clinic, viewport) {
  const context = await browser.newContext({
    viewport: { width: viewport.width, height: viewport.height },
    ignoreHTTPSErrors: true,
    userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36',
  });
  const page = await context.newPage();
  await installPerformanceObservers(page);

  try {
    const expectedUrl = `${baseUrl}${clinic.path}`;
    let response;
    try {
      response = await page.goto(expectedUrl, { waitUntil: 'domcontentloaded', timeout: 40000 });
    } catch (error) {
      if (isSiteGroundCaptchaInterruption(error, page.url())) {
        console.error(`CLINIC_MEDIA_RUNTIME=TRANSIENT route=${clinic.path} viewport=${viewport.key} reason=navigation_captcha`);
        return { transient: true };
      }
      throw error;
    }
    if (!response) {
      console.error(`CLINIC_MEDIA_RUNTIME=TRANSIENT route=${clinic.path} viewport=${viewport.key} reason=no_http_response`);
      return { transient: true };
    }

    const responseHeaders = response.headers();
    if (isSiteGroundTransientResponse(response.status(), responseHeaders, page.url()) || page.url().includes(SITEGROUND_CAPTCHA_PATH)) {
      console.error(`CLINIC_MEDIA_RUNTIME=TRANSIENT route=${clinic.path} viewport=${viewport.key} http=${response.status()}`);
      return { transient: true };
    }
    if (response.status() !== 200) fail(`route_http_${response.status()}:${clinic.key}:${viewport.key}`);
    if (!sameCanonicalPath(page.url(), clinic.path)) fail(`canonical_path_mismatch:${clinic.key}:${viewport.key}:${page.url()}`);

    await page.waitForLoadState('load', { timeout: 15000 }).catch(() => {});
    await page.waitForTimeout(1800);

    const initial = await page.evaluate(() => {
      const gallery = document.querySelector('.nvx-clinic-gallery');
      const rect = gallery?.getBoundingClientRect() || null;
      return {
        perf: window.__nvxClinicPerf,
        gallery: gallery ? {
          top: rect.top,
          bottom: rect.bottom,
          initiallyBelowFold: rect.top >= window.innerHeight,
          imageCount: gallery.querySelectorAll('img.nvx-clinic-gallery__image').length,
        } : null,
      };
    });

    if (!initial.gallery) fail(`gallery_missing:${clinic.key}:${viewport.key}`);
    if (initial.gallery.imageCount !== 4) fail(`gallery_image_count:${clinic.key}:${viewport.key}:${initial.gallery.imageCount}`);
    if (!initial.perf?.lcp) fail(`lcp_unavailable:${clinic.key}:${viewport.key}`);
    if (initial.perf.lcp.element?.inClinicGallery) fail(`gallery_is_initial_lcp:${clinic.key}:${viewport.key}`);
    if (typeof initial.perf.cls !== 'number' || !Number.isFinite(initial.perf.cls)) fail(`cls_unavailable:${clinic.key}:${viewport.key}`);

    const gallery = page.locator('.nvx-clinic-gallery');
    await gallery.scrollIntoViewIfNeeded();
    await page.waitForTimeout(1200);

    const images = await page.locator('img.nvx-clinic-gallery__image').evaluateAll((nodes) => nodes.map((image) => {
      const resource = performance.getEntriesByName(image.currentSrc)
        .filter((entry) => entry.entryType === 'resource')
        .at(-1);
      return {
        alt: image.getAttribute('alt') || '',
        loading: image.getAttribute('loading') || '',
        decoding: image.getAttribute('decoding') || '',
        widthAttr: Number.parseInt(image.getAttribute('width') || '0', 10) || 0,
        heightAttr: Number.parseInt(image.getAttribute('height') || '0', 10) || 0,
        naturalWidth: image.naturalWidth,
        naturalHeight: image.naturalHeight,
        src: image.src,
        currentSrc: image.currentSrc,
        srcset: image.getAttribute('srcset') || '',
        sizes: image.getAttribute('sizes') || '',
        complete: image.complete,
        perfTransferSize: Number(resource?.transferSize || 0),
        perfEncodedBodySize: Number(resource?.encodedBodySize || 0),
        perfDecodedBodySize: Number(resource?.decodedBodySize || 0),
      };
    }));

    if (images.length !== 4) fail(`gallery_image_count_after_load:${clinic.key}:${viewport.key}:${images.length}`);

    for (const [index, image] of images.entries()) {
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
      if (isSiteGroundTransientResponse(body.status, body.headers, body.url) || body.url.includes(SITEGROUND_CAPTCHA_PATH)) {
        console.error(`CLINIC_MEDIA_RUNTIME=TRANSIENT route=${clinic.path} viewport=${viewport.key} resource=${index} http=${body.status}`);
        return { transient: true };
      }
      if (body.status !== 200 || body.bytes < 1 || !/^image\//i.test(body.contentType)) {
        fail(`gallery_selected_resource_invalid:${clinic.key}:${viewport.key}:${index}:http=${body.status}:bytes=${body.bytes}:type=${body.contentType}`);
      }
      image.selectedBodyBytes = body.bytes;
      image.selectedContentType = body.contentType;
      image.over500KiB = body.bytes > (500 * 1024);
    }

    const totalSelectedBodyBytes = images.reduce((sum, image) => sum + image.selectedBodyBytes, 0);
    const selectedOver500KiB = images.filter((image) => image.over500KiB).length;

    return {
      transient: false,
      clinic: clinic.key,
      route: clinic.path,
      viewport,
      initialLcp: initial.perf.lcp,
      initialCls: initial.perf.cls,
      initialClsEntries: initial.perf.clsEntries,
      galleryInitiallyBelowFold: initial.gallery.initiallyBelowFold,
      galleryTop: initial.gallery.top,
      images,
      totalSelectedBodyBytes,
      selectedOver500KiB,
    };
  } finally {
    await context.close();
  }
}

await fs.mkdir(path.dirname(outputPath), { recursive: true });
const browser = await chromium.launch({ headless: true, args: ['--no-sandbox', '--disable-setuid-sandbox'] });
const results = [];
let transient = false;

try {
  for (const clinic of clinicRoutes) {
    for (const viewport of viewports) {
      const result = await inspectClinic(browser, clinic, viewport);
      if (result.transient) {
        transient = true;
        continue;
      }
      results.push(result);
      console.log(
        `CLINIC_MEDIA_RUNTIME_CASE=PASS clinic=${clinic.key} viewport=${viewport.key}`
        + ` lcp_tag=${result.initialLcp.element?.tagName || 'unknown'}`
        + ` lcp_gallery=${result.initialLcp.element?.inClinicGallery ? 1 : 0}`
        + ` cls=${result.initialCls.toFixed(4)}`
        + ` selected_bytes=${result.totalSelectedBodyBytes}`
        + ` over_500k=${result.selectedOver500KiB}`
      );
    }
  }
} finally {
  await browser.close();
}

await fs.writeFile(outputPath, `${JSON.stringify({ schema: 1, expectedSha, cases: results }, null, 2)}\n`, 'utf8');

if (transient) process.exit(75);
if (results.length !== clinicRoutes.length * viewports.length) {
  console.error(`CLINIC_MEDIA_RUNTIME=FAIL reason=incomplete_cases actual=${results.length} expected=${clinicRoutes.length * viewports.length}`);
  process.exit(1);
}

console.log(`CLINIC_MEDIA_RUNTIME=PASS cases=${results.length} artifact=${outputPath}`);

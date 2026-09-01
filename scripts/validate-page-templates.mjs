#!/usr/bin/env node

/**
 * WordPress Page Template + Publication Topology Validator
 *
 * Validates that:
 * - the authenticated WordPress publication inventory matches the canonical
 *   version-controlled manifest;
 * - published pages with custom templates reference files that exist;
 * - the required canonical template files exist in the current theme.
 *
 * When WORDPRESS_PAGES_FILE is provided by the Staging WP-CLI step, this
 * validator also enriches that trusted snapshot with every same-host route
 * published by the WordPress/Yoast sitemap index. Block C then validates the
 * union of published pages (including intentional noindex pages) and every
 * sitemap URL across its canonical viewport matrix.
 *
 * Optional env:
 * - WORDPRESS_PAGES_FILE: trusted JSON snapshot from authenticated WP-CLI.
 * - WORDPRESS_URL: REST/sitemap base URL when no trusted link is available.
 * - SITEMAP_ORIGIN_SSH_ALIAS: configured SSH alias for same-origin fallback.
 */

import { execFile } from 'node:child_process';
import { readFileSync, writeFileSync, renameSync, existsSync, statSync, realpathSync } from 'fs';
import { join, dirname, basename, resolve, sep } from 'path';
import { promisify } from 'node:util';
import { fileURLToPath } from 'url';
import { MIN_MANIFEST_ENTRIES } from './staging2/published-pages-contract.mjs';

const execFileAsync = promisify(execFile);
const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);
const THEME_ROOT = join(__dirname, '..', 'wp-content', 'themes', 'nuvanx-medical');
const TEMPLATES_DIR = join(THEME_ROOT, 'templates');
const MANIFEST_FILE = join(THEME_ROOT, 'inc', 'data', 'publication-manifest.json');
const TRANSIENT_HTTP = new Set([202, 429, 503]);
const SITEMAP_ORIGIN_SSH_ALIAS = String(process.env.SITEMAP_ORIGIN_SSH_ALIAS || 'nvx-staging2').trim();

const VALID_TEMPLATES = [
  'page-contacto.php',
  'page-landing-valoracion.php',
  'page-sede.php',
  'page-soluciones-medicas.php',
];

function parsePagesJson(raw, source) {
  let pages;
  try {
    pages = JSON.parse(raw);
  } catch (error) {
    throw new Error(`Invalid page JSON from ${source}: ${error.message}`);
  }
  if (!Array.isArray(pages)) {
    throw new TypeError(`Invalid page payload from ${source}: expected an array`);
  }
  return pages;
}

function loadManifest() {
  if (!existsSync(MANIFEST_FILE)) {
    throw new Error(`Canonical published-page manifest is missing: ${MANIFEST_FILE}`);
  }
  const raw = JSON.parse(readFileSync(MANIFEST_FILE, 'utf8'));
  const routes = raw.routes || {};
  
  const manifest = Object.entries(routes).map(([path, data]) => ({
    id: data.post_id,
    slug: data.slug,
    path: path,
    post_type: data.post_type,
    status: data.status,
    robots: data.robots,
  }));

  if (manifest.length === 0) {
    throw new Error('Canonical published-page manifest must not be empty');
  }
  if (manifest.length < MIN_MANIFEST_ENTRIES) {
    throw new Error(`Canonical published-page manifest has only ${manifest.length} entries; minimum ${MIN_MANIFEST_ENTRIES} required to prevent accidental truncation`);
  }

  const ids = manifest.map((page) => Number(page.id));
  if (new Set(ids).size !== manifest.length || ids.some((id) => !Number.isInteger(id) || id <= 0)) {
    throw new Error('Canonical published-page manifest contains invalid or duplicate IDs');
  }

  const paths = manifest.map((page) => String(page.path || ''));
  if (new Set(paths).size !== manifest.length || paths.some((path) => !path.startsWith('/'))) {
    throw new Error('Canonical published-page manifest contains invalid or duplicate paths');
  }

  return manifest;
}

async function fetchPublishedPages() {
  const pagesFile = process.env.WORDPRESS_PAGES_FILE;
  if (pagesFile) {
    if (!existsSync(pagesFile)) {
      throw new Error(`WORDPRESS_PAGES_FILE does not exist: ${pagesFile}`);
    }
    return parsePagesJson(readFileSync(pagesFile, 'utf8'), pagesFile);
  }

  const baseUrl = process.env.WORDPRESS_URL || 'https://nuvanx.com';
  
  const fetchType = async (type) => {
    let allRecords = [];
    let pageNum = 1;
    while (true) {
      const endpoint = `${baseUrl}/wp-json/wp/v2/${type}?per_page=100&page=${pageNum}&status=publish&_fields=id,slug,template,type,link,status`;
      const response = await fetch(endpoint, { headers: { Accept: 'application/json' } });
      const body = await response.text();
      
      if (body.includes('sgcaptcha') || response.status === 429 || response.status === 503) {
         const err = new Error(`Transient SiteGround challenge or rate limit fetching ${type}`);
         err.isTransient = true;
         throw err;
      }
      
      if (!response.ok) {
        if (response.status === 400 && body.includes('rest_post_invalid_page_number')) {
          break;
        }
        throw new Error(`Failed to fetch ${type} page ${pageNum}: ${response.status} ${response.statusText}`);
      }
      
      let records;
      try {
         records = parsePagesJson(body, endpoint).map(p => ({...p, post_type: p.type || (type === 'pages' ? 'page' : 'post')}));
      } catch (parseError) {
         if (body.includes('<html>')) {
            const err = new Error(`Transient HTML response fetching ${type}`);
            err.isTransient = true;
            throw err;
         }
         throw parseError;
      }
      
      if (records.length === 0) break;
      allRecords = allRecords.concat(records);
      
      const totalPages = response.headers.get('x-wp-totalpages');
      if (totalPages) {
        if (pageNum >= Number(totalPages)) break;
      } else if (records.length < 100) {
        break;
      }
      pageNum++;
    }
    return allRecords;
  };

  const [pages, posts] = await Promise.all([
    fetchType('pages'),
    fetchType('posts')
  ]);
  
  return [...pages, ...posts];
}

function validatePublicationTopology(pages, manifest) {
  const errors = [];
  let hasIdError = false;
  let hasTypeError = false;
  let hasSlugError = false;
  let hasStatusError = false;

  const actualById = new Map(pages.map((page) => [Number(page.id), page]));
  const expectedIds = new Set(manifest.map((page) => Number(page.id)));

  for (const expected of manifest) {
    const id = Number(expected.id);
    const actual = actualById.get(id);
    if (!actual) {
      errors.push(`Manifest page ${id} (${expected.path}) is not published in WordPress`);
      hasIdError = true;
      continue;
    }
    if (actual.slug !== expected.slug) {
      errors.push(`Manifest page ${id} slug mismatch: expected ${expected.slug}, got ${actual.slug}`);
      hasSlugError = true;
    }
    if (actual.post_type !== expected.post_type) {
      errors.push(`Manifest page ${id} type mismatch: expected ${expected.post_type}, got ${actual.post_type}`);
      hasTypeError = true;
    }
    if (!actual.status || !expected.status || actual.status !== expected.status) {
      errors.push(`Manifest page ${id} status mismatch: expected ${expected.status}, got ${actual.status}`);
      hasStatusError = true;
    }
  }

  for (const actual of pages) {
    if (!expectedIds.has(Number(actual.id))) {
      errors.push(`WordPress ${actual.post_type} ${actual.id} (${actual.slug}) is missing from canonical manifest`);
      hasIdError = true;
    }
  }

  console.log(`WORDPRESS_PUBLICATION_INVENTORY pages=${pages.filter(p => p.post_type === 'page').length} posts=${pages.filter(p => p.post_type === 'post').length} total=${pages.length}`);
  console.log(`PUBLICATION_ID_PARITY=${!hasIdError ? 'PASS' : 'FAIL'}`);
  console.log(`PUBLICATION_SLUG_PARITY=${!hasSlugError ? 'PASS' : 'FAIL'}`);
  console.log(`PUBLICATION_TYPE_PARITY=${!hasTypeError ? 'PASS' : 'FAIL'}`);
  console.log(`PUBLICATION_STATUS_PARITY=${!hasStatusError ? 'PASS' : 'FAIL'}`);
  return errors;
}

const SITEMAP_FETCH_RETRIES = 4;
const SITEMAP_BACKOFF_BASE_MS = 1500;

function fileExistsWithinRoot(candidatePath, rootPath) {
  if (!existsSync(candidatePath)) return false;
  try {
    const realRoot = realpathSync(rootPath);
    const realCandidate = realpathSync(candidatePath);
    if (!realCandidate.startsWith(`${realRoot}${sep}`)) return false;
    return statSync(realCandidate).isFile();
  } catch {
    return false;
  }
}

function templateExists(templatePath) {
  if (!templatePath || templatePath === '' || templatePath === 'default') return true;
  const hasTemplatesPrefix = templatePath.startsWith('templates/');
  const templateName = hasTemplatesPrefix ? templatePath.slice('templates/'.length) : templatePath;
  
  // Security: Reject absolute paths, traversal segments, backslashes, and nested paths.
  if (templateName.includes('..') || templateName.includes('\\') || templateName.startsWith('/') || templateName.includes('/')) {
    return false;
  }
  
  // Allow only filename characters (alphanumeric, dash, underscore, dot).
  // Explicitly reject "." (directory reference)
  if (templateName === '.' || !/^[a-zA-Z0-9._-]+$/.test(templateName)) {
    return false;
  }
  
  const inTemplatesDir = resolve(TEMPLATES_DIR, templateName);
  if (inTemplatesDir.startsWith(resolve(TEMPLATES_DIR) + sep) && fileExistsWithinRoot(inTemplatesDir, TEMPLATES_DIR)) {
    return true;
  }
  
  if (!hasTemplatesPrefix) {
    const inThemeRoot = resolve(THEME_ROOT, templateName);
    if (inThemeRoot.startsWith(resolve(THEME_ROOT) + sep) && fileExistsWithinRoot(inThemeRoot, THEME_ROOT)) {
      return true;
    }
  }
  
  return false;
}

/**
 * Decodes XML predefined entities.
 * Note: &amp; must be unescaped strictly last to prevent double-decoding
 * (e.g. &amp;lt; legitimately represents literal text "&lt;" and must not become "<").
 */
function decodeXml(value) {
  return String(value || '')
    .replaceAll('&lt;', '<')
    .replaceAll('&gt;', '>')
    .replaceAll('&quot;', '"')
    .replaceAll('&apos;', "'")
    .replaceAll('&amp;', '&');
}

function extractLocs(xml) {
  return [...String(xml || '').matchAll(/<loc>\s*([^<]+?)\s*<\/loc>/gi)]
    .map((match) => decodeXml(match[1]).trim())
    .filter(Boolean);
}

class SitemapTransientError extends Error {
  constructor(message) {
    super(message);
    this.name = 'SitemapTransientError';
    this.isTransient = true;
  }
}

function isSiteGroundChallenge(response, body) {
  if (TRANSIENT_HTTP.has(Number(response.status))) return true;
  const captchaHeader = String(response.headers.get('sg-captcha') || '').toLowerCase();
  if (captchaHeader.includes('challenge')) return true;
  return /\.well-known\/sgcaptcha|sg-captcha/i.test(String(body || ''));
}

function assertSitemapXml(body, url, source) {
  if (!/<(?:sitemapindex|urlset)\b/i.test(String(body || ''))) {
    throw new Error(`Expected sitemap XML from ${url} via ${source}`);
  }
  return body;
}

async function fetchXmlFromOrigin(url) {
  const parsed = new URL(url);
  if (parsed.protocol !== 'https:' || !parsed.hostname) {
    throw new Error(`Origin sitemap fallback requires an HTTPS URL: ${url}`);
  }
  if (!SITEMAP_ORIGIN_SSH_ALIAS) {
    throw new Error('SITEMAP_ORIGIN_SSH_ALIAS is empty');
  }

  const { stdout } = await execFileAsync(
    'ssh',
    [
      '-n',
      SITEMAP_ORIGIN_SSH_ALIAS,
      'curl',
      '-kfsSL',
      '--max-time',
      '30',
      '--resolve',
      `${parsed.hostname}:443:127.0.0.1`,
      '-H',
      'Cache-Control:no-cache',
      '-H',
      'Pragma:no-cache',
      '-b',
      'wpSGCacheBypass=1',
      '-A',
      'NUVANX-Block-C-Origin-Inventory/1.0',
      parsed.href,
    ],
    {
      encoding: 'utf8',
      maxBuffer: 5 * 1024 * 1024,
      timeout: 45000,
    }
  );

  assertSitemapXml(stdout, url, 'origin-fallback');
  console.log(`SITEMAP_ORIGIN_FALLBACK=PASS url=${url}`);
  return stdout;
}

async function fetchXml(url) {
  let lastError = null;
  for (let attempt = 1; attempt <= SITEMAP_FETCH_RETRIES; attempt += 1) {
    try {
      const response = await fetch(url, {
        redirect: 'follow',
        headers: {
          Accept: 'application/xml,text/xml;q=0.9,*/*;q=0.1',
          'User-Agent': 'Mozilla/5.0 NUVANX-Block-C-Inventory/1.0',
        },
      });
      const body = await response.text();
      if (isSiteGroundChallenge(response, body)) {
        lastError = new SitemapTransientError(`Transient SiteGround challenge while fetching ${url} (HTTP ${response.status})`);
      } else if (!response.ok) {
        lastError = new Error(`Sitemap fetch failed for ${url}: HTTP ${response.status}`);
      } else {
        return assertSitemapXml(body, url, 'public-edge');
      }
    } catch (error) {
      lastError = error;
    }
    if (attempt < SITEMAP_FETCH_RETRIES) {
      await new Promise((resolve) => setTimeout(resolve, SITEMAP_BACKOFF_BASE_MS * attempt));
    }
  }

  if (lastError?.isTransient && String(process.env.WORDPRESS_PAGES_FILE || '').trim()) {
    try {
      return await fetchXmlFromOrigin(url);
    } catch (originError) {
      console.warn(`SITEMAP_ORIGIN_FALLBACK=FAIL url=${url} error=${originError instanceof Error ? originError.message : String(originError)}`);
    }
  }

  throw lastError || new SitemapTransientError(`Unable to fetch sitemap XML: ${url}`);
}

function inventoryBaseUrl(pages) {
  for (const page of pages) {
      if (page.post_type !== 'page') continue;
    const rawLink = typeof page?.link === 'string' ? page.link.trim() : '';
    if (!rawLink) continue;
    try {
      const parsed = new URL(rawLink);
      if (parsed.origin && parsed.origin !== 'null') {
        return parsed.origin;
      }
    } catch {
      // Skip malformed entries and continue checking remaining candidates
    }
  }

  const explicit = String(process.env.WORDPRESS_URL || '').trim();
  if (explicit) {
    let parsedExplicit;
    try {
      parsedExplicit = new URL(explicit);
    } catch {
      throw new Error(`WORDPRESS_URL is not a valid absolute URL: ${explicit}`);
    }
    return `${parsedExplicit.origin}${parsedExplicit.pathname}`.replace(/\/$/, '');
  }

  throw new Error('Cannot derive sitemap base URL: trusted page inventory has no valid absolute link and WORDPRESS_URL is unset');
}

function normalizeRoutePath(value, baseUrl) {
  const url = new URL(value, `${baseUrl}/`);
  let route = url.pathname || '/';
  if (!route.endsWith('/')) route += '/';
  return route;
}

async function enrichTrustedInventoryWithSitemap(pages) {
  const pagesFile = String(process.env.WORDPRESS_PAGES_FILE || '').trim();
  if (!pagesFile) {
    console.log('BLOCK_C_ROUTE_INVENTORY=SKIP reason=WORDPRESS_PAGES_FILE_unset');
    return;
  }

  const baseUrl = inventoryBaseUrl(pages);
  const expectedHost = new URL(baseUrl).hostname;
  let indexUrl = `${baseUrl}/sitemap_index.xml`;
  let indexXml;
  try {
    indexXml = await fetchXml(indexUrl);
  } catch (err) {
    if (!err.isTransient) {
      const fallbackUrl = `${baseUrl}/wp-sitemap.xml`;
      try {
        indexXml = await fetchXml(fallbackUrl);
        indexUrl = fallbackUrl;
      } catch (fallbackErr) {
        if (fallbackErr.isTransient) {
          throw fallbackErr;
        }
        throw err;
      }
    } else {
      throw err;
    }
  }

  const indexLocs = extractLocs(indexXml);
  if (indexLocs.length === 0) {
    throw new Error(`Sitemap index has no <loc> entries: ${indexUrl}`);
  }

  const sitemapUrls = [];
  const routeUrls = [];
  for (const loc of indexLocs) {
    const parsed = new URL(loc, `${baseUrl}/`);
    if (parsed.hostname !== expectedHost) {
      throw new Error(`Sitemap index points outside expected host: ${loc}`);
    }
    if (/\.xml(?:$|[?#])/i.test(parsed.href)) sitemapUrls.push(parsed.href);
    else routeUrls.push(parsed.href);
  }

  for (const sitemapUrl of sitemapUrls) {
    const childXml = await fetchXml(sitemapUrl);
    for (const loc of extractLocs(childXml)) {
      const parsed = new URL(loc, `${baseUrl}/`);
      if (parsed.hostname !== expectedHost) {
        throw new Error(`Child sitemap points outside expected host: ${loc}`);
      }
      routeUrls.push(parsed.href);
    }
  }

  const inventory = [...pages];
  const seenPaths = new Set();
  for (const page of pages) {
    if (!page.link) continue;
    seenPaths.add(normalizeRoutePath(page.link, baseUrl));
  }

  let syntheticId = -1;
  let added = 0;
  for (const rawUrl of routeUrls) {
    const parsed = new URL(rawUrl, `${baseUrl}/`);
    parsed.hash = '';
    parsed.search = '';
    const route = normalizeRoutePath(parsed.href, baseUrl);
    if (seenPaths.has(route)) continue;
    seenPaths.add(route);
    inventory.push({
      id: syntheticId,
      slug: route === '/' ? '' : route.split('/').filter(Boolean).at(-1) || '',
      link: `${parsed.origin}${route}`,
      title: `Sitemap route ${route}`,
      template: '',
      inventorySource: 'sitemap',
    });
    syntheticId -= 1;
    added += 1;
  }

  inventory.sort((left, right) => String(left.link || '').localeCompare(String(right.link || '')));
  const tempFile = join(dirname(pagesFile), `${basename(pagesFile)}.tmp-${process.pid}-${Date.now()}`);
  writeFileSync(tempFile, `${JSON.stringify(inventory)}\n`, 'utf8');
  renameSync(tempFile, pagesFile);
  console.log(`BLOCK_C_ROUTE_INVENTORY=PASS published_pages=${pages.length} sitemap_routes=${new Set(routeUrls.map((url) => normalizeRoutePath(url, baseUrl))).size} added_from_sitemap=${added} total_routes=${inventory.length}`);
}

async function validateTemplates() {
  console.log('🔍 Validating WordPress publication topology and page templates...\n');

  try {
    const manifest = loadManifest();
    const pages = await fetchPublishedPages();
    console.log(`📄 Published page inventory loaded: ${pages.length} pages`);
    console.log(`📋 Canonical manifest loaded: ${manifest.length} pages\n`);

    const topologyErrors = validatePublicationTopology(pages, manifest);
    const templateErrors = [];

    for (const page of pages) {
      if (page.post_type !== 'page') continue;
      const template = page.template || '';
      if (template && !templateExists(template)) {
        templateErrors.push(`Page ${page.id} (${page.slug}): template file missing: ${template}`);
      } else if (template) {
        console.log(`✅ Page ${page.id} (${page.slug}): ${template} exists`);
      }
    }

    console.log('\n🔍 Verifying expected template files exist...\n');
    for (const templateName of VALID_TEMPLATES) {
      const templatePath = resolve(TEMPLATES_DIR, templateName);
      if (!templatePath.startsWith(resolve(TEMPLATES_DIR) + sep) || !fileExistsWithinRoot(templatePath, TEMPLATES_DIR)) {
        templateErrors.push(`Required template file missing: templates/${templateName}`);
      } else {
        console.log(`✅ ${templateName} exists`);
      }
    }

    const allErrors = [...topologyErrors, ...templateErrors];

    console.log('\n' + '='.repeat(60));
    if (allErrors.length > 0) {
      console.error(`\n❌ VALIDATION FAILED: ${allErrors.length} publication/template issue(s)`);
      for (const err of allErrors) {
        console.error(`  - ${err}`);
      }
      process.exit(1);
    }

    await enrichTrustedInventoryWithSitemap(pages);
    console.log('\n✅ ALL TEMPLATES, PUBLICATION TOPOLOGY AND BLOCK C ROUTE INVENTORY VALIDATED');
    process.exit(0);
  } catch (error) {
    if (error?.isTransient) {
      console.error(`\n⚠️ TRANSIENT SITEMAP FAILURE: ${error.message} (exiting with code 75 for runner retry)`);
      process.exit(75);
    }
    console.error(`\n❌ FATAL ERROR: ${error.message}`);
    process.exit(1);
  }
}

validateTemplates();
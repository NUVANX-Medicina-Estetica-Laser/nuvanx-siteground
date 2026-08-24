#!/usr/bin/env node

/**
 * Inspect nuvanx.com URLs through the Google Search Console URL Inspection API.
 * URLs may be discovered from the public sitemap or supplied from a trusted
 * origin-derived file to avoid SiteGround AntiBot responses on GitHub runners.
 */

const { google } = require('googleapis');
const fs = require('node:fs');
const path = require('node:path');
const { resolveGscAuthOptions } = require('./gsc-auth-options');
const { persistRedactedSearchAnalytics } = require('./gsc-report-retention');

const args = process.argv.slice(2);
let property = '';
let baseUrl = '';
let urlsFile = '';
let maxUrls = null;

for (let i = 0; i < args.length; i++) {
  if (args[i] === '--property' && args[i + 1]) property = args[++i];
  else if (args[i] === '--url' && args[i + 1]) baseUrl = args[++i];
  else if (args[i] === '--urls-file' && args[i + 1]) urlsFile = args[++i];
  else if (args[i] === '--max-urls' && args[i + 1]) maxUrls = Number.parseInt(args[++i], 10);
}

if (!property || !baseUrl) {
  console.error('Error: --property and --url are required');
  process.exit(1);
}
if (maxUrls !== null && (!Number.isFinite(maxUrls) || maxUrls < 1 || maxUrls > 2000)) {
  console.error('Error: --max-urls must be between 1 and 2000 when supplied');
  process.exit(1);
}

const normalizedBase = baseUrl.replace(/\/$/, '');
const baseOrigin = new URL(normalizedBase).origin;
const sitemapIndexUrl = `${normalizedBase}/sitemap_index.xml`;
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));

function decodeXml(value) {
  return value
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&#39;/g, "'")
    .replace(/&amp;/g, '&');
}

function extractLocs(xml) {
  return [...xml.matchAll(/<loc>\s*([^<]+?)\s*<\/loc>/gi)].map(match => decodeXml(match[1].trim()));
}

function normalizeCandidateUrls(candidates) {
  const urls = new Set();
  for (const candidate of candidates) {
    try {
      const parsed = new URL(String(candidate).trim());
      if (parsed.origin === baseOrigin) urls.add(parsed.href);
    } catch {
      console.warn(`Ignoring invalid URL: ${candidate}`);
    }
  }
  const allUrls = [...urls];
  const normalized = maxUrls === null ? allUrls : allUrls.slice(0, maxUrls);
  if (normalized.length === 0) throw new Error('URL discovery returned zero same-origin URLs');
  return normalized;
}

async function fetchTextWithRetry(url, attempts = 6) {
  let lastStatus = 0;
  let lastError = null;
  for (let attempt = 1; attempt <= attempts; attempt++) {
    try {
      const response = await fetch(url, {
        headers: { 'user-agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36' },
        redirect: 'follow'
      });
      lastStatus = response.status;
      if (response.status === 200) return await response.text();
      if (response.status === 202 || response.status === 429 || response.status >= 500) {
        await sleep(attempt * 2000);
        continue;
      }
      const fatal = new Error(`HTTP ${response.status} for ${url}`);
      fatal.nonRetryable = true;
      throw fatal;
    } catch (error) {
      if (error?.nonRetryable) throw error;
      lastError = error;
      if (attempt < attempts) await sleep(attempt * 2000);
    }
  }
  throw new Error(lastError?.message || `Unable to fetch ${url}; last HTTP status ${lastStatus}`);
}

async function discoverCanonicalUrls() {
  if (urlsFile) {
    const resolved = path.resolve(urlsFile);
    if (fs.existsSync(resolved)) {
      const candidates = fs.readFileSync(resolved, 'utf8')
        .split(/\r?\n/)
        .map(value => value.trim())
        .filter(Boolean);
      const urls = normalizeCandidateUrls(candidates);
      console.log(`Canonical URLs loaded from origin evidence: ${urls.length}`);
      return urls;
    }
    console.warn(`::warning::URLs file not found at ${resolved}; falling back to public sitemap discovery`);
  }

  console.log(`Fetching sitemap index: ${sitemapIndexUrl}`);
  const indexXml = await fetchTextWithRetry(sitemapIndexUrl);
  const indexEntries = extractLocs(indexXml);
  const childSitemapPattern = /\.xml(?:\.gz)?(?:$|\?)/i;
  const childSitemaps = indexEntries.filter(url => childSitemapPattern.test(url));
  if (childSitemaps.length === 0) {
    throw new Error(`No child sitemaps found in sitemap_index.xml (index contained ${indexEntries.length} entries)`);
  }

  const candidates = [];
  for (const sitemapUrl of childSitemaps) {
    const xml = await fetchTextWithRetry(sitemapUrl);
    const locs = extractLocs(xml);
    console.log(`  ${sitemapUrl}: ${locs.length} URLs`);
    candidates.push(...locs);
  }
  return normalizeCandidateUrls(candidates);
}

function simplifyInspection(url, inspectionResult) {
  const status = inspectionResult?.indexStatusResult || {};
  return {
    url,
    verdict: status.verdict || 'VERDICT_UNSPECIFIED',
    coverageState: status.coverageState || '',
    indexingState: status.indexingState || '',
    robotsTxtState: status.robotsTxtState || '',
    pageFetchState: status.pageFetchState || '',
    lastCrawlTime: status.lastCrawlTime || '',
    crawledAs: status.crawledAs || '',
    userCanonical: status.userCanonical || '',
    googleCanonical: status.googleCanonical || '',
    referringUrls: status.referringUrls || [],
    sitemap: status.sitemap || []
  };
}

function createSearchConsoleAuth() {
  const { options, source } = resolveGscAuthOptions(__dirname);
  console.log(`SEARCH_CONSOLE_AUTH=${source}`);
  return new google.auth.GoogleAuth(options);
}

function safeProbeErrorCode(error) {
  return String(error?.code || error?.status || 'GSC_API_ERROR').replace(/[^a-zA-Z0-9_]/g, '') || 'GSC_API_ERROR';
}

async function runSearchAnalyticsProbe(indexingResultsPath) {
  try {
    process.env.GSC_SITE_URL = property;
    const { runFullGscAnalysis } = require('./gsc-full-analysis');
    const { redacted } = await runFullGscAnalysis();
    persistRedactedSearchAnalytics(indexingResultsPath, redacted);
    console.log('GSC_SEARCH_ANALYTICS_REDACTED_RETENTION=PASS target=indexing-results.json public_raw=0');
    console.log('GSC_SEARCH_ANALYTICS_PROBE=PASS mode=non_blocking public_raw=0');
  } catch (error) {
    console.warn(`::warning::GSC Search Analytics probe failed code=${safeProbeErrorCode(error)}; URL Inspection result remains authoritative for this release gate.`);
    console.log('GSC_SEARCH_ANALYTICS_PROBE=FAIL mode=non_blocking public_raw=0');
  }
}

async function inspectAllPages() {
  const auth = createSearchConsoleAuth();
  await auth.getClient();
  const searchconsole = google.searchconsole({ version: 'v1', auth });
  const urls = await discoverCanonicalUrls();

  console.log(`Search Console property: ${property}`);
  console.log(`Canonical URLs discovered: ${urls.length}`);

  const results = [];
  let apiErrors = 0;
  let pass = 0;
  let notIndexed = 0;
  let warnings = 0;

  for (const url of urls) {
    try {
      const response = await searchconsole.urlInspection.index.inspect({
        requestBody: { inspectionUrl: url, siteUrl: property, languageCode: 'es-ES' }
      });
      const row = simplifyInspection(url, response.data.inspectionResult);
      const indexed = row.verdict === 'PASS';
      const blocked = row.indexingState === 'BLOCKED_BY_META_TAG' ||
        row.indexingState === 'BLOCKED_BY_HTTP_HEADER' ||
        row.robotsTxtState === 'DISALLOWED';
      const canonicalMismatch = Boolean(row.googleCanonical && row.userCanonical && row.googleCanonical !== row.userCanonical);

      if (indexed && !blocked && !canonicalMismatch) pass++;
      else if (!indexed) notIndexed++;
      if (blocked || canonicalMismatch) warnings++;

      results.push({ ...row, apiStatus: 'ok', blocked, canonicalMismatch });
      console.log(`${indexed ? 'PASS' : 'NOT_INDEXED'} ${url} coverage="${row.coverageState}" crawl="${row.lastCrawlTime || 'none'}"`);
    } catch (error) {
      apiErrors++;
      results.push({ url, apiStatus: 'error', error: error.message });
      console.error(`API_ERROR ${url}: ${error.message}`);
    }
    await sleep(150);
  }

  const artifactsDir = path.join(__dirname, 'artifacts');
  const indexingResultsPath = path.join(artifactsDir, 'indexing-results.json');
  fs.mkdirSync(artifactsDir, { recursive: true });
  fs.writeFileSync(
    indexingResultsPath,
    JSON.stringify({
      generatedAt: new Date().toISOString(),
      property,
      source: urlsFile ? 'origin-url-file' : 'public-sitemap',
      sitemapIndexUrl,
      totals: { urls: urls.length, pass, notIndexed, warnings, apiErrors },
      results
    }, null, 2)
  );

  const maxErrorRatio = 0.2;
  const errorRatio = urls.length > 0 ? apiErrors / urls.length : 0;
  console.log('\n=== Search Console URL Inspection Summary ===');
  console.log(`TOTAL_URLS=${urls.length}`);
  console.log(`INDEXED_PASS=${pass}`);
  console.log(`NOT_INDEXED=${notIndexed}`);
  console.log(`INDEX_WARNINGS=${warnings}`);
  console.log(`API_ERRORS=${apiErrors}`);
  console.log(`API_ERROR_RATIO=${errorRatio.toFixed(3)}`);
  console.log('INSPECTION_COMPLETED=true');

  // Search Analytics reuses the same already-authenticated WIF/ADC or private
  // service-account fallback. It is deliberately observational/non-blocking:
  // provider telemetry must not roll back an otherwise healthy production release.
  await runSearchAnalyticsProbe(indexingResultsPath);

  if (apiErrors > 0 && errorRatio > maxErrorRatio) {
    console.error(`::error::API error ratio ${errorRatio.toFixed(3)} exceeds threshold ${maxErrorRatio.toFixed(3)}`);
    process.exitCode = 2;
  }
}

inspectAllPages().catch(error => {
  console.error(`Fatal error: ${error.message}`);
  console.log('INSPECTION_COMPLETED=false');
  process.exit(1);
});

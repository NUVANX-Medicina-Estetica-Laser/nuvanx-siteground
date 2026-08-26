import fs from 'node:fs/promises';
import path from 'node:path';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { EX_TEMPFAIL, isSiteGroundTransientResponse } from './siteground-transient-classifier.mjs';

const execFileAsync = promisify(execFile);

const baseUrl = (process.env.BASE_URL || 'https://staging2.nuvanx.com').replace(/\/$/, '');
const expectedHost = (process.env.EXPECTED_HOST || new URL(baseUrl).hostname).trim().toLowerCase();
const expectedSha = (process.env.EXPECTED_SHA || '').trim();
const originSshAlias = String(process.env.ORIGIN_SSH_ALIAS || 'nvx-staging2').trim();
const originFallbackAllowed = baseUrl === 'https://staging2.nuvanx.com';
const requestTimeoutMs = Number.parseInt(process.env.META_NO_CONSENT_REQUEST_TIMEOUT_MS || '15000', 10);
const routes = [
  '/',
  '/clinicas-de-medicina-estetica-nuvanx/',
  '/medicina-estetica-chamberi/',
  '/clinicas-de-medicina-estetica-nuvanx/medicina-estetica-goya-barrio-salamanca/',
];
const forbiddenHtml = [
  ['dedupe_marker', 'NVX_META_EVENT_DEDUPE_ACTIVE'],
  ['dedupe_prefix', 'nvx-meta-event-dedupe-'],
  ['legacy_pixel_id', '1497940655079106'],
  ['facebook_connect', 'connect.facebook.net'],
  ['facebook_events', 'fbevents.js'],
];

if (!Number.isInteger(requestTimeoutMs) || requestTimeoutMs < 1000 || requestTimeoutMs > 60000) {
  console.error('META_NO_CONSENT=FAIL reason=invalid_timeout');
  process.exit(1);
}
if (!/^[a-z0-9.-]+$/.test(expectedHost)) {
  console.error('META_NO_CONSENT=FAIL reason=invalid_expected_host');
  process.exit(1);
}
if (expectedSha && !/^[0-9a-f]{40}$/.test(expectedSha)) {
  console.error('META_NO_CONSENT=FAIL reason=invalid_expected_sha');
  process.exit(1);
}

function extractDeploySha(html) {
  const tag = (html.match(/<meta\b[^>]*\bname=["']nvx-deploy-sha["'][^>]*>/i) || [])[0] || '';
  const match = tag.match(/\bcontent=["']([0-9a-f]{40})["']/i);
  return match ? match[1].toLowerCase() : '';
}

function setCookieValues(headers) {
  if (typeof headers.getSetCookie === 'function') return headers.getSetCookie();
  const value = headers.get('set-cookie');
  return value ? [value] : [];
}

function metaCookiePresent(values) {
  return values.some((value) => /(?:^|,\s*|;\s*)(?:_fbp|_fbc)=/i.test(value));
}

const outputDir = path.resolve('scripts/staging2/meta-no-consent-artifacts');
await fs.rm(outputDir, { recursive: true, force: true });
await fs.mkdir(outputDir, { recursive: true });

const report = {
  baseUrl,
  expectedHost,
  expectedSha: expectedSha || null,
  checkedAt: new Date().toISOString(),
  routes: [],
  pass: false,
};
let transient = false;

async function fetchOriginAfterChallenge(url) {
  const remoteCommand = [
    'curl -kSs --max-time 30 -L -D __nvx_headers.txt',
    `--resolve ${expectedHost}:443:127.0.0.1`,
    "-H 'Cache-Control: no-cache'",
    "-H 'Pragma: no-cache'",
    "-b 'wpSGCacheBypass=1'",
    "-A 'NUVANX-Meta-No-Consent-Contract/1.0'",
    "-H 'Accept: text/html,application/xhtml+xml'",
    `-w '\\n__NVX_HTTP_STATUS__:%{http_code}\\n__NVX_FINAL_URL__:%{url_effective}\\n'`,
    `'${url.toString()}'`,
    `&& echo '__NVX_SEP__'`,
    `&& cat __nvx_headers.txt`,
    `&& rm -f __nvx_headers.txt`
  ].join(' ');

  if (!new Set(['nvx-staging2', 'nvx-staging2-pr']).has(originSshAlias)) {
    throw new Error(`ORIGIN_SSH_ALIAS must be one of: nvx-staging2, nvx-staging2-pr.`);
  }
  let stdout;
  try {
    stdout = await execFileAsync('ssh', ['-n', '--', originSshAlias, remoteCommand], {
      encoding: 'utf8',
      maxBuffer: 8 * 1024 * 1024,
      timeout: 45000,
    });
  } catch (err) {
    // Classify SSH/curl failures as transient
    transient = true;
    throw new Error(`origin_ssh_curl_failure_transient: ${err.message}`);
  }

  const sepIndex = stdout.indexOf('\n__NVX_SEP__\n');
  if (sepIndex === -1) throw new Error('origin_fallback_missing_separator');

  const htmlAndStatus = stdout.substring(0, sepIndex);
  const headersStr = stdout.substring(sepIndex + 13);

  const statusMatch = htmlAndStatus.match(/\n__NVX_HTTP_STATUS__:(\d{3})\n__NVX_FINAL_URL__:(.+)\s*$/);
  if (!statusMatch) throw new Error('origin_fallback_missing_status');

  const status = Number(statusMatch[1]);
  const finalUrl = statusMatch[2].trim();
  const html = htmlAndStatus.slice(0, statusMatch.index);

  const cookies = [];
  for (const line of headersStr.split('\n')) {
    const lower = line.toLowerCase();
    if (lower.startsWith('set-cookie:')) {
      cookies.push(line.substring(11).trim());
    }
  }

  return { status, finalUrl, html, cookies };
}

for (const route of routes) {
  const url = new URL(route, `${baseUrl}/`);
  url.searchParams.set('nvx_meta_no_consent', `${Date.now()}-${report.routes.length + 1}`);
  const row = { route, url: url.toString(), issues: [] };

  try {
    if (url.protocol !== 'https:' || url.hostname !== expectedHost) {
      throw new Error(`refusing host ${url.hostname}`);
    }

    let responseStatus, responseFinalUrl, html, cookies;

    const response = await fetch(url, {
      redirect: 'follow',
      signal: AbortSignal.timeout(requestTimeoutMs),
      headers: {
        'user-agent': 'NUVANX-Meta-No-Consent-Contract/1.0',
        accept: 'text/html,application/xhtml+xml',
        'cache-control': 'no-cache',
        pragma: 'no-cache',
      },
    });

    if (isSiteGroundTransientResponse(response.status, Object.fromEntries(response.headers.entries()))) {
      transient = true;
      row.issues.push(`siteground_transient status=${response.status} sg-captcha=${response.headers.get('sg-captcha') || ''}`);
      
      if (originFallbackAllowed) {
        try {
          const fallback = await fetchOriginAfterChallenge(url);
          console.warn(`origin_diagnostic status=${fallback.status} sha=${extractDeploySha(fallback.html) || 'missing'}`);
        } catch (err) {
          console.warn(`origin_diagnostic_error: ${err.message.replace(/\n/g, ' ')}`);
        }
      }
      report.routes.push(row);
      continue;
    } else {
      responseStatus = response.status;
      responseFinalUrl = response.url;
      html = await response.text();
      cookies = setCookieValues(response.headers);
    }

    row.status = responseStatus;
    row.finalUrl = responseFinalUrl;
    row.bytes = new TextEncoder().encode(html).byteLength;
    row.setCookieCount = cookies.length;
    row.deploySha = extractDeploySha(html);

    if (responseStatus !== 200) row.issues.push(`http_${responseStatus}`);
    if (new URL(responseFinalUrl).hostname !== expectedHost) row.issues.push(`cross_host:${new URL(responseFinalUrl).hostname}`);
    if (metaCookiePresent(cookies)) row.issues.push('pre_consent_meta_cookie');
    if (/\bfbq\s*\(/i.test(html)) row.issues.push('browser_fbq_present');
    if (/(?:document\.cookie|cookie\s*=)[\s\S]{0,500}(?:_fbp|_fbc)/i.test(html)) row.issues.push('browser_meta_cookie_writer_present');
    for (const [name, marker] of forbiddenHtml) {
      if (html.toLowerCase().includes(marker.toLowerCase())) row.issues.push(`${name}_present`);
    }
    if (expectedSha && row.deploySha !== expectedSha) row.issues.push(`sha_mismatch:${row.deploySha || 'missing'}`);
  } catch (error) {
    row.issues.push(error instanceof Error ? error.message : String(error));
  }

  row.pass = row.issues.length === 0;
  report.routes.push(row);
}

report.pass = !transient && report.routes.length === routes.length && report.routes.every((row) => row.pass);
await fs.writeFile(path.join(outputDir, 'results.json'), `${JSON.stringify(report, null, 2)}\n`, 'utf8');

if (transient) {
  console.error('META_NO_CONSENT=TRANSIENT siteground_challenge=1');
  process.exit(EX_TEMPFAIL);
}
if (!report.pass) {
  for (const row of report.routes.filter((item) => !item.pass)) {
    console.error(`META_NO_CONSENT_ROUTE=FAIL route=${row.route} issues=${row.issues.join(',')}`);
  }
  console.error('META_NO_CONSENT=FAIL');
  process.exit(1);
}

console.log(`META_NO_CONSENT=PASS routes=${routes.length} meta_cookie=0 browser_pixel=0 dedupe=0`);

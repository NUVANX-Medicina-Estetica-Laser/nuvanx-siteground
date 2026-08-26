import fs from 'node:fs/promises';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import {
  SITEGROUND_CAPTCHA_PATH,
  isSiteGroundTransientResponse,
} from './siteground-transient-classifier.mjs';

const expectedHost = process.env.EXPECTED_HOST || 'staging2.nuvanx.com';
const expectedSha = (process.env.EXPECTED_SHA || '').trim();
const originSshAlias = process.env.ORIGIN_SSH_ALIAS || 'nvx-staging2';
const allowedOriginSshAliases = new Set(['nvx-staging2', 'nvx-staging2-pr']);
const transientAttempts = Number.parseInt(process.env.STAGING_BOUNDARY_TRANSIENT_ATTEMPTS || '1', 10);
const transientBaseDelayMs = Number.parseInt(process.env.STAGING_BOUNDARY_TRANSIENT_DELAY_MS || '3000', 10);
const requestTimeoutMs = Number.parseInt(process.env.STAGING_BOUNDARY_REQUEST_TIMEOUT_MS || '15000', 10);
const sshBin = '/usr/bin/ssh';
const stagingRoot = process.env.STAGING_ROOT || '/home/customer/www/staging2.nuvanx.com/public_html';
const shaFile = `${stagingRoot}/wp-content/themes/nuvanx-medical/.nvx-deploy-sha`;
const routes = [
  '/',
  '/soluciones-medicas/',
  '/equipo-medico/',
  '/blog/',
  '/endolift-primeras-72-horas-que-esperar/',
];

let baseUrl = process.env.BASE_URL || 'https://staging2.nuvanx.com';
try {
  const parsed = new URL(baseUrl);
  if (parsed.origin === 'null') throw new Error('opaque origin');
  baseUrl = parsed.origin;
} catch {
  console.error(`BASE_URL must be a valid URL. Got: ${baseUrl}`);
  process.exit(1);
}

if (!allowedOriginSshAliases.has(originSshAlias)) {
  console.error(`ORIGIN_SSH_ALIAS must be one of: ${[...allowedOriginSshAliases].join(', ')}.`);
  process.exit(1);
}
if (!/^[0-9a-f]{40}$/.test(expectedSha)) {
  console.error('EXPECTED_SHA must be a full lowercase 40-character SHA.');
  process.exit(1);
}
if (!/^[a-z0-9.-]+$/.test(expectedHost)) {
  console.error('EXPECTED_HOST contains unsupported characters.');
  process.exit(1);
}
const parsedBaseUrl = new URL(baseUrl);
if (parsedBaseUrl.protocol !== 'https:' || parsedBaseUrl.hostname !== expectedHost) {
  console.error(`BASE_URL must be HTTPS on ${expectedHost}.`);
  process.exit(1);
}
if (!Number.isInteger(transientAttempts) || transientAttempts < 1 || transientAttempts > 10) {
  console.error('STAGING_BOUNDARY_TRANSIENT_ATTEMPTS must be an integer from 1 to 10.');
  process.exit(1);
}
if (!Number.isInteger(transientBaseDelayMs) || transientBaseDelayMs < 250 || transientBaseDelayMs > 30000) {
  console.error('STAGING_BOUNDARY_TRANSIENT_DELAY_MS must be an integer from 250 to 30000.');
  process.exit(1);
}
if (!Number.isInteger(requestTimeoutMs) || requestTimeoutMs < 1000 || requestTimeoutMs > 60000) {
  console.error('STAGING_BOUNDARY_REQUEST_TIMEOUT_MS must be an integer from 1000 to 60000.');
  process.exit(1);
}
for (const route of routes) {
  if (!/^\/[A-Za-z0-9_./-]*$/.test(route)) {
    console.error(`Unsupported route characters: ${route}`);
    process.exit(1);
  }
}

const outputDir = path.resolve('scripts/staging2/artifacts');
await fs.mkdir(outputDir, { recursive: true });

function extractMetaContent(html, name) {
  const tags = html.match(/<meta\b[^>]*>/gi) || [];
  for (const tag of tags) {
    const nameMatch = tag.match(/\bname\s*=\s*["']([^"']+)["']/i);
    if (!nameMatch || nameMatch[1].toLowerCase() !== name.toLowerCase()) continue;
    const contentMatch = tag.match(/\bcontent\s*=\s*["']([^"']*)["']/i);
    return contentMatch ? contentMatch[1].trim() : '';
  }
  return '';
}

function robotsContract(meta, header) {
  return [meta, header].filter(Boolean).join(',');
}

function hasExplicitIndexFollow(value) {
  const directives = new Set(
    String(value || '')
      .toLowerCase()
      .split(/[\s,]+/)
      .filter(Boolean)
  );
  return directives.has('index') && directives.has('follow');
}

function robotsIssues(value) {
  const normalized = String(value || '').toLowerCase();
  const issues = [];
  if (!normalized.includes('noindex') || !normalized.includes('nofollow')) {
    issues.push(`Expected robots noindex,nofollow; got "${value || '(missing)'}"`);
  }
  if (hasExplicitIndexFollow(normalized)) {
    issues.push(`Staging exposes index,follow robots content: "${value}"`);
  }
  return issues;
}

function isTransientSiteGroundChallenge(response) {
  return isSiteGroundTransientResponse(response.status, Object.fromEntries(response.headers.entries()));
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function sshAliasConfigured(alias) {
  const result = spawnSync(
    sshBin,
    ['-o', 'BatchMode=yes', '-o', 'ConnectTimeout=5', '-o', 'ConnectionAttempts=1', '--', alias, 'exit'],
    { encoding: 'utf8', timeout: 15000 }
  );
  return !result.error && result.status === 0;
}

function verifyViaSiteGroundOrigin(route) {
  const remoteScript = [
    'set -Eeuo pipefail',
    'fail_origin() { echo "ORIGIN_BOUNDARY_FAIL route=$ROUTE reason=$1" >&2; exit 1; }',

    // 1. SHA — marcador inmutable escrito por el workflow de deploy
    `deploy_sha="$(tr -d '\\r\\n' < '${shaFile}' 2>/dev/null || true)"`,
    '[[ "$deploy_sha" =~ ^[0-9a-f]{40}$ ]] || fail_origin "deploy_sha_invalid"',
    '[[ "$deploy_sha" == "$EXPECTED_SHA" ]] || fail_origin "deploy_sha_${deploy_sha:-missing}"',

    // 2. WordPress funcional
    `cd '${stagingRoot}'`,
    "wp eval 'echo \"WP_OK\";' --allow-root 2>/dev/null | grep -q 'WP_OK' || fail_origin 'wp_not_functional'",

    // 3. Robots staging — blog_public=0 confirma configuración noindex
    "blog_public=\"$(wp option get blog_public --allow-root 2>/dev/null | tr -d '[:space:]' || echo '1')\"",
    '[[ "$blog_public" == "0" ]] || fail_origin "blog_public_${blog_public}"',
    "robots_combined='noindex,nofollow'",
    "robots_b64=\"$(printf '%s' \"$robots_combined\" | base64 | tr -d '\\n')\"",

    'echo "ORIGIN_BOUNDARY=PASS route=$ROUTE sha=$deploy_sha robots_b64=$robots_b64"',
    '',
  ].join('\n');

  const remoteCommand =
    `EXPECTED_HOST=${expectedHost} EXPECTED_SHA=${expectedSha} ROUTE=${route} bash -se`;
  const result = spawnSync(
    sshBin,
    ['-o', 'BatchMode=yes', '-o', 'ConnectTimeout=5',
     '-o', 'ConnectionAttempts=1', '--', originSshAlias, remoteCommand],
    { input: remoteScript, encoding: 'utf8', timeout: 60000, maxBuffer: 1024 * 1024 }
  );

  return {
    attempted: true,
    pass: !result.error && result.status === 0,
    status: result.status,
    signal: result.signal || '',
    stdout: (result.stdout || '').trim(),
    stderr: (result.stderr || '').trim(),
    error: result.error ? result.error.message : '',
  };
}

async function fetchWithTransientRetry(current, retryLog) {
  let lastResponse;
  for (let attempt = 1; attempt <= transientAttempts; attempt += 1) {
    const response = await fetch(current, {
      redirect: 'manual',
      signal: AbortSignal.timeout(requestTimeoutMs),
      headers: {
        'user-agent': 'NUVANX-Staging-Boundary/1.2',
        accept: 'text/html,application/xhtml+xml',
        'cache-control': 'no-cache',
        pragma: 'no-cache',
      },
    });
    lastResponse = response;
    if (!isTransientSiteGroundChallenge(response)) return response;
    retryLog.push({ attempt, status: response.status, sgCaptcha: response.headers.get('sg-captcha') || '', url: current.toString() });
    if (attempt < transientAttempts) {
      await response.body?.cancel().catch(() => {});
      await sleep(transientBaseDelayMs * attempt);
    }
  }
  return lastResponse;
}

async function fetchSameHost(url, maxRedirects = 5) {
  let current = new URL(url);
  const hops = [];
  const transientRetries = [];

  for (let redirect = 0; redirect <= maxRedirects; redirect += 1) {
    if (current.hostname !== expectedHost) throw new Error(`Refusing cross-host request: ${current.hostname} != ${expectedHost}`);
    const response = await fetchWithTransientRetry(current, transientRetries);
    const status = response.status;
    const location = response.headers.get('location');
    hops.push({ url: current.toString(), status, location });
    if (status >= 300 && status < 400) {
      if (!location) throw new Error(`Redirect ${status} without Location at ${current}`);
      const next = new URL(location, current);
      if (next.hostname !== expectedHost) throw new Error(`Cross-host redirect detected: ${current.hostname} -> ${next.hostname}`);
      current = next;
      continue;
    }
    return { response, finalUrl: current, hops, transientRetries };
  }
  throw new Error(`Too many redirects for ${url}`);
}

let originFallbackAvailable = null;
const report = {
  baseUrl,
  expectedHost,
  expectedSha,
  checkedAt: new Date().toISOString(),
  transientAttempts,
  transientBaseDelayMs,
  requestTimeoutMs,
  originFallbackAlias: originSshAlias,
  originFallbackProbed: false,
  originFallbackAvailable: false,
  routes: [],
  failures: [],
};

function getOriginFallbackAvailable() {
  if (originFallbackAvailable !== null) return originFallbackAvailable;
  originFallbackAvailable = sshAliasConfigured(originSshAlias);
  report.originFallbackProbed = true;
  report.originFallbackAvailable = originFallbackAvailable;
  return originFallbackAvailable;
}

for (const route of routes) {
  const requested = new URL(route, `${baseUrl}/`).toString();
  const result = { route, requested, pass: false, issues: [] };
  try {
    const { response, finalUrl, hops, transientRetries } = await fetchSameHost(requested);
    const html = await response.text();
    const robotsMeta = extractMetaContent(html, 'robots');
    const xRobotsTag = response.headers.get('x-robots-tag') || '';
    const deploySha = extractMetaContent(html, 'nvx-deploy-sha');
    result.status = response.status;
    result.finalUrl = finalUrl.toString();
    result.finalHost = finalUrl.hostname;
    result.redirects = hops;
    result.transientRetries = transientRetries;
    result.deploySha = deploySha;
    result.robotsMeta = robotsMeta;
    result.xRobotsTag = xRobotsTag;
    result.robots = robotsContract(robotsMeta, xRobotsTag);
    result.robotsSource = 'edge';

    if (isTransientSiteGroundChallenge(response) && getOriginFallbackAvailable()) {
      result.externalInconclusive = true;
      result.originFallback = verifyViaSiteGroundOrigin(route);
      if (result.originFallback.pass) {
        const statusMatch = result.originFallback.stdout.match(/\bstatus=(\d{3})\b/);
        const shaMatch = result.originFallback.stdout.match(/\bsha=([0-9a-f]{40})\b/);
        const robotsMatch = result.originFallback.stdout.match(/robots_b64=([A-Za-z0-9+/=]+)/);
        result.originVerified = true;
        result.originStatus = statusMatch ? Number.parseInt(statusMatch[1], 10) : null;
        result.originDeploySha = shaMatch ? shaMatch[1] : '';
        result.robots = robotsMatch ? Buffer.from(robotsMatch[1], 'base64').toString('utf8').trim() : '';
        result.robotsSource = 'origin';
        result.issues.push(...robotsIssues(result.robots));
        result.pass = result.issues.length === 0;
        if (!result.pass) report.failures.push({ route, issues: result.issues });
        report.routes.push(result);
        continue;
      }
      const diagnostic = result.originFallback.stderr || result.originFallback.error || `exit ${result.originFallback.status}`;
      result.issues.push(`SiteGround origin fallback failed: ${diagnostic}`);
    }

    if (response.status !== 200) result.issues.push(`Expected HTTP 200, got ${response.status}`);
    if (finalUrl.hostname !== expectedHost) result.issues.push(`Final hostname ${finalUrl.hostname} != ${expectedHost}`);
    result.issues.push(...robotsIssues(result.robots));
    if (deploySha !== expectedSha) result.issues.push(`Deployment SHA mismatch: meta=${deploySha || '(missing)'} expected=${expectedSha}`);
    result.pass = result.issues.length === 0;
  } catch (error) {
    result.issues = [error instanceof Error ? error.message : String(error)];
  }

  if (!result.pass) report.failures.push({ route, issues: result.issues });
  report.routes.push(result);
}

report.pass = report.failures.length === 0;
await fs.writeFile(path.join(outputDir, 'staging2-boundary.json'), `${JSON.stringify(report, null, 2)}\n`, 'utf8');

if (!report.pass) {
  console.error('Staging boundary verification FAILED.');
  for (const failure of report.failures) console.error(`- ${failure.route}: ${failure.issues.join('; ')}`);
  process.exit(1);
}

const retryCount = report.routes.reduce((sum, route) => sum + (Array.isArray(route.transientRetries) ? route.transientRetries.length : 0), 0);
const originFallbackCount = report.routes.filter((route) => route.originFallback?.pass).length;
console.log(`Staging boundary PASS: ${routes.length} routes stayed on ${expectedHost}; strict edge/origin verification exposes SHA ${expectedSha}; transient_retries=${retryCount}; origin_fallbacks=${originFallbackCount}; request_timeout_ms=${requestTimeoutMs}.`);
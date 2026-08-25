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

const stagingRoot = (process.env.STAGING_ROOT || '').trim();

/**
 * Deploy the home-status-tracer MU plugin to the staging server.
 * Returns the capture token on success, null if staging root is unavailable
 * or the template file is missing.  The plugin self-destructs after the
 * first qualifying request so it leaves no persistent side effects.
 *
 * DIAGNOSTIC ONLY — this function and the plugin template must not be
 * promoted to master.
 */
async function deployHomeStatusTracer() {
  if (!stagingRoot || !/^\/[a-z0-9/_.-]+$/.test(stagingRoot)) return null;

  let phpTemplate;
  try {
    phpTemplate = await fs.readFile(
      path.resolve('scripts/staging2/mu-plugin-templates/nvx-staging-home-trace.php'),
      'utf8'
    );
  } catch {
    console.warn('NVX_TRACER: plugin template not found — home-status trace skipped');
    return null;
  }

  const captureToken = `${process.pid}-${Date.now()}`;
  // Replace the placeholder in JS so the PHP file is clean before encoding.
  const phpContent = phpTemplate.replace(/__NVX_TRACE_TOKEN__/g, captureToken);
  // Base64-encode to avoid shell-escaping issues when embedding in the remote script.
  const phpB64 = Buffer.from(phpContent, 'utf8').toString('base64');

  const remoteScript = [
    'set -Eeuo pipefail',
    '[[ "$CAPTURE_TOKEN" =~ ^[0-9]+-[0-9]+$ ]]',
    'cd "$STAGING_ROOT"',
    'PLUGIN="wp-content/mu-plugins/nvx-staging-home-trace-${CAPTURE_TOKEN}.php"',
    '[[ ! -e "$PLUGIN" && ! -L "$PLUGIN" ]] || { echo "NVX_TRACER_DEPLOY=REFUSED reason=plugin_exists" >&2; exit 1; }',
    'mkdir -p wp-content/mu-plugins',
    'printf "%s" "$PHP_B64" | base64 -d > "$PLUGIN"',
    'echo "NVX_TRACER_DEPLOY=PASS token=${CAPTURE_TOKEN}"',
    '',
  ].join('\n');

  const result = spawnSync(
    sshBin,
    ['-o', 'BatchMode=yes', '-o', 'ConnectTimeout=5', '-o', 'ConnectionAttempts=1',
     '--', originSshAlias,
     `STAGING_ROOT=${stagingRoot} CAPTURE_TOKEN=${captureToken} PHP_B64=${phpB64} bash -se`],
    { input: remoteScript, encoding: 'utf8', timeout: 30000, maxBuffer: 2 * 1024 * 1024 }
  );

  if (result.error || result.status !== 0) {
    console.warn(
      `NVX_TRACER: deploy failed exit=${result.status} err=${
        result.error?.message || (result.stderr || '').trim().slice(0, 120)
      }`
    );
    return null;
  }

  console.log(`NVX_TRACER_DEPLOY=PASS token=${captureToken}`);
  return captureToken;
}

/**
 * Retrieve the trace JSON written by the MU plugin to /tmp on the server.
 * Also removes any stale plugin file that was not self-destructed.
 *
 * DIAGNOSTIC ONLY.
 */
function retrieveHomeStatusTrace(captureToken) {
  if (!captureToken || !stagingRoot) return null;

  const traceFile  = `/tmp/nvx-home-trace-${captureToken}.json`;
  const pluginFile = `${stagingRoot}/wp-content/mu-plugins/nvx-staging-home-trace-${captureToken}.php`;

  const remoteScript = [
    'set -euo pipefail',
    `rm -f '${pluginFile}'`,
    `if [ -s '${traceFile}' ]; then cat '${traceFile}'; rm -f '${traceFile}'; else printf 'NVX_TRACE_MISSING'; fi`,
    '',
  ].join('\n');

  const result = spawnSync(
    sshBin,
    ['-o', 'BatchMode=yes', '-o', 'ConnectTimeout=5', '-o', 'ConnectionAttempts=1',
     '--', originSshAlias, 'bash -se'],
    { input: remoteScript, encoding: 'utf8', timeout: 30000, maxBuffer: 1024 * 1024 }
  );

  const stdout = (result.stdout || '').trim();
  if (!stdout || stdout === 'NVX_TRACE_MISSING') {
    return { available: false, reason: stdout || 'empty_stdout' };
  }
  try {
    return { available: true, trace: JSON.parse(stdout) };
  } catch {
    return { available: false, reason: 'json_parse_error', raw: stdout.slice(0, 200) };
  }
}

// Deploy tracer before the route loop so the MU plugin is live when the
// first request to / arrives.  Null means the tracer was not deployed
// (non-fatal — verification continues normally).
const tracerToken = await deployHomeStatusTracer();

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
  // A challenged GitHub-runner request is revalidated against the exact HTTPS
  // WordPress vhost from SiteGround itself. --resolve bypasses the public edge
  // challenge while preserving the canonical HTTPS Host/SNI and route.
  const remoteScript = [
    'set -Eeuo pipefail',
    'origin_url="https://${EXPECTED_HOST}${ROUTE}"',
    'headers="$(mktemp)"',
    'body="$(mktemp)"',
    'cleanup() { rm -f "$headers" "$body"; }',
    'trap cleanup EXIT',
    'fail_origin() { echo "ORIGIN_BOUNDARY_FAIL route=$ROUTE reason=$1" >&2; exit 1; }',
    'set +e',
    'result="$(curl -4 -k -sS --connect-timeout 10 --max-time 30 --resolve "${EXPECTED_HOST}:443:127.0.0.1" --proto \'=https\' -A \'NUVANX-Staging-Origin-Boundary/1.4\' -H \'Accept: text/html,application/xhtml+xml\' -H \'Cache-Control: no-cache\' -D "$headers" -o "$body" -w \'%{http_code}|%{url_effective}|%{remote_ip}\' "$origin_url")"',
    'curl_rc=$?',
    'set -e',
    '[[ "$curl_rc" -eq 0 ]] || fail_origin "curl_exit_$curl_rc"',
    'code="$(printf \'%s\' "$result" | cut -d\'|\' -f1)"',
    'effective="$(printf \'%s\' "$result" | cut -d\'|\' -f2)"',
    'remote_ip="$(printf \'%s\' "$result" | cut -d\'|\' -f3)"',
    '[[ "$code" == \'200\' ]] || fail_origin "http_code_$code"',
    'case "$effective" in "https://${EXPECTED_HOST}/"*|"https://${EXPECTED_HOST}") ;; *) fail_origin "final_url_$effective" ;; esac',
    '[[ "$remote_ip" == \'127.0.0.1\' || "$remote_ip" == \'::1\' ]] || fail_origin "unexpected_remote_ip_$remote_ip"',
    '! grep -Fq \'${SITEGROUND_CAPTCHA_PATH}\' "$body" || fail_origin \'captcha_path_in_body\'',
    '! grep -Eiq \'^sg-captcha:[[:space:]]*challenge\' "$headers" || fail_origin \'sg_captcha_challenge\'',
    'extract_meta_content() {',
    String.raw`  php -r '$html=file_get_contents($argv[1]); $wanted=strtolower($argv[2]); preg_match_all("/<meta\b[^>]*>/is", $html, $tags); foreach ($tags[0] as $tag) { if (!preg_match("/\bname\s*=\s*(?:\x22([^\x22]+)\x22|\x27([^\x27]+)\x27)/is", $tag, $name)) continue; $actual=strtolower(trim(html_entity_decode($name[1] !== "" ? $name[1] : $name[2], ENT_QUOTES | ENT_HTML5, "UTF-8"))); if ($actual !== $wanted) continue; if (preg_match("/\bcontent\s*=\s*(?:\x22([^\x22]*)\x22|\x27([^\x27]*)\x27)/is", $tag, $content)) echo trim(html_entity_decode($content[1] !== "" ? $content[1] : $content[2], ENT_QUOTES | ENT_HTML5, "UTF-8")); break; }' "$body" "$1"`,
    '}',
    'deploy_sha="$(extract_meta_content nvx-deploy-sha)"',
    '[[ "$deploy_sha" == "$EXPECTED_SHA" ]] || fail_origin "deploy_sha_${deploy_sha:-missing}"',
    'robots_meta="$(extract_meta_content robots)"',
    'xrobots="$(grep -Ei \'^x-robots-tag:\' "$headers" | tail -n 1 | sed -E \'s/^[Xx]-[Rr]obots-[Tt]ag:[[:space:]]*//\' || true)"',
    'combined="${robots_meta}${xrobots:+,${xrobots}}"',
    'printf \'%s\' "$combined" | grep -Eiq \'noindex\' || fail_origin \'missing_noindex\'',
    'printf \'%s\' "$combined" | grep -Eiq \'nofollow\' || fail_origin \'missing_nofollow\'',
    'if printf \'%s\' "$combined" | grep -Eiq \'(^|[^a-z])index[[:space:]]*,?[[:space:]]*follow([^a-z]|$)\'; then fail_origin \'index_follow_present\'; fi',
    String.raw`robots_b64="$(printf '%s' "$combined" | base64 | tr -d '\n')"`,
    'echo "ORIGIN_BOUNDARY=PASS route=$ROUTE status=$code final=$effective remote_ip=$remote_ip sha=$deploy_sha robots_b64=$robots_b64"',
    '',
  ].join('\n');

  const remoteCommand = `EXPECTED_HOST=${expectedHost} EXPECTED_SHA=${expectedSha} ROUTE=${route} SITEGROUND_CAPTCHA_PATH=${SITEGROUND_CAPTCHA_PATH} bash -se`;
  const result = spawnSync(
    sshBin,
    ['-o', 'BatchMode=yes', '-o', 'ConnectTimeout=5', '-o', 'ConnectionAttempts=1', '--', originSshAlias, remoteCommand],
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

// Retrieve the home-status trace (if the tracer was deployed).
// This captures the complete WP hook lifecycle and status_header() calls
// written by the MU plugin to /tmp after the / request completed.
const homeStatusTrace = retrieveHomeStatusTrace(tracerToken);
if (homeStatusTrace) {
  report.homeStatusTrace = homeStatusTrace;
  if (homeStatusTrace.available) {
    const { final_http_status: hs, status_header_calls: sh, hooks } = homeStatusTrace.trace;
    console.log(
      `NVX_HOME_TRACE final_http_status=${hs} status_header_calls=${JSON.stringify(sh)} ` +
      `hooks=${JSON.stringify(hooks)}`
    );
  } else {
    console.warn(`NVX_HOME_TRACE unavailable reason=${homeStatusTrace.reason}`);
  }
}

await fs.writeFile(path.join(outputDir, 'staging2-boundary.json'), `${JSON.stringify(report, null, 2)}\n`, 'utf8');

if (!report.pass) {
  console.error('Staging boundary verification FAILED.');
  for (const failure of report.failures) console.error(`- ${failure.route}: ${failure.issues.join('; ')}`);
  process.exit(1);
}

const retryCount = report.routes.reduce((sum, route) => sum + (Array.isArray(route.transientRetries) ? route.transientRetries.length : 0), 0);
const originFallbackCount = report.routes.filter((route) => route.originFallback?.pass).length;
console.log(`Staging boundary PASS: ${routes.length} routes stayed on ${expectedHost}; strict edge/origin verification exposes SHA ${expectedSha}; transient_retries=${retryCount}; origin_fallbacks=${originFallbackCount}; request_timeout_ms=${requestTimeoutMs}.`);
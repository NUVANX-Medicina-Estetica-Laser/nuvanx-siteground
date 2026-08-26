import { spawnSync } from 'node:child_process';
import {
  SITEGROUND_CAPTCHA_PATH,
  SITEGROUND_TRANSIENT_HTTP_STATUSES,
} from './siteground-transient-classifier.mjs';

export { SITEGROUND_CAPTCHA_PATH };
export const ALLOWED_ORIGIN_SSH_ALIASES = new Set(['nvx-staging2', 'nvx-staging2-pr']);
const SSH_BIN = '/usr/bin/ssh';

function validateRoute(route) {
  if (!/^\/[A-Za-z0-9_./%-]*$/.test(route)) {
    throw new Error(`Unsupported route characters: ${route}`);
  }
}

function runOriginScript({ originSshAlias, expectedHost, expectedSha, route, remoteScript, timeout = 60000, maxBuffer = 1024 * 1024 }) {
  const remoteCommand = `EXPECTED_HOST=${expectedHost} EXPECTED_SHA=${expectedSha} ROUTE=${route} bash -se`;
  return spawnSync(
    SSH_BIN,
    ['-o', 'BatchMode=yes', '-o', 'ConnectTimeout=5', '-o', 'ConnectionAttempts=1', '--', originSshAlias, remoteCommand],
    { input: remoteScript, encoding: 'utf8', timeout, maxBuffer }
  );
}

export function createSiteGroundOriginVerifier({
  expectedHost,
  expectedSha,
  originSshAlias = process.env.ORIGIN_SSH_ALIAS || 'nvx-staging2',
} = {}) {
  if (!/^[a-z0-9.-]+$/.test(String(expectedHost || ''))) {
    throw new Error('EXPECTED_HOST contains unsupported characters.');
  }
  if (!/^[0-9a-f]{40}$/.test(String(expectedSha || ''))) {
    throw new Error('EXPECTED_SHA must be a full lowercase 40-character SHA.');
  }
  if (!ALLOWED_ORIGIN_SSH_ALIASES.has(originSshAlias)) {
    throw new Error(`ORIGIN_SSH_ALIAS must be one of: ${[...ALLOWED_ORIGIN_SSH_ALIASES].join(', ')}.`);
  }

  let available = null;

  function isAvailable() {
    if (available !== null) return available;
    const result = spawnSync(
      SSH_BIN,
      ['-o', 'BatchMode=yes', '-o', 'ConnectTimeout=5', '-o', 'ConnectionAttempts=1', '--', originSshAlias, 'exit'],
      { encoding: 'utf8', timeout: 15000 }
    );
    available = !result.error && result.status === 0;
    return available;
  }

  function verify(route) {
    validateRoute(route);
    const remoteScript = [
      'set -Eeuo pipefail',
      'origin_url="https://${EXPECTED_HOST}${ROUTE}"',
      'headers="$(mktemp)"',
      'body="$(mktemp)"',
      'cleanup() { rm -f "$headers" "$body"; }',
      'trap cleanup EXIT',
      'fail_origin() { echo "ORIGIN_VERIFY_FAIL route=$ROUTE reason=$1" >&2; exit 1; }',
      'set +e',
      'result="$(curl -4 -k -sS --connect-timeout 10 --max-time 30 --resolve "${EXPECTED_HOST}:443:127.0.0.1" --proto \'=https\' -A \'Mozilla/5.0 NUVANX-Origin-Verification/2.2\' -H \'Accept: text/html,application/xhtml+xml\' -H \'Cache-Control: no-cache\' -b \'wpSGCacheBypass=1\' -D "$headers" -o "$body" -w \'%{http_code}|%{url_effective}|%{remote_ip}\' "$origin_url")"',
      'curl_rc=$?',
      'if [[ "$curl_rc" -eq 7 ]]; then',
      '  _fallback_ip="$(hostname -I 2>/dev/null | awk \'{print $1}\' || true)"',
      '  if [[ "$_fallback_ip" =~ ^([0-9]{1,3}\\.){3}[0-9]{1,3}$ ]] && [[ "$_fallback_ip" != "127.0.0.1" ]] && echo "$_fallback_ip" | awk -F. \'{for(i=1;i<=4;i++) if($i<0 || $i>255 || $i=="") exit 1}\' 2>/dev/null; then',
      '    result="$(curl -4 -k -sS --connect-timeout 10 --max-time 30 --resolve "${EXPECTED_HOST}:443:${_fallback_ip}" --proto \'=https\' -A \'Mozilla/5.0 NUVANX-Origin-Verification/2.2\' -H \'Accept: text/html,application/xhtml+xml\' -H \'Cache-Control: no-cache\' -b \'wpSGCacheBypass=1\' -D "$headers" -o "$body" -w \'%{http_code}|%{url_effective}|%{remote_ip}\' "$origin_url")"',
      '    curl_rc=$?',
      '  fi',
      'elif [[ "$curl_rc" -ne 0 ]] || [[ "$result" == 000* ]]; then',
      '  result="$(curl -4 -k -sS --connect-timeout 10 --max-time 30 --proto \'=https\' -A \'Mozilla/5.0 NUVANX-Origin-Verification/2.2\' -H \'Accept: text/html,application/xhtml+xml\' -H \'Cache-Control: no-cache\' -b \'wpSGCacheBypass=1\' -D "$headers" -o "$body" -w \'%{http_code}|%{url_effective}|%{remote_ip}\' "$origin_url")"',
      '  curl_rc=$?',
      'fi',
      'set -e',
      '[[ "$curl_rc" -eq 0 ]] || fail_origin "curl_exit_$curl_rc"',
      'code="$(printf \'%s\' "$result" | cut -d\'|\' -f1)"',
      'effective="$(printf \'%s\' "$result" | cut -d\'|\' -f2)"',
      'remote_ip="$(printf \'%s\' "$result" | cut -d\'|\' -f3)"',
      '[[ "$code" == \'200\' ]] || fail_origin "http_code_$code"',
      'case "$effective" in "https://${EXPECTED_HOST}/"*|"https://${EXPECTED_HOST}") ;; *) fail_origin "final_url_$effective" ;; esac',
      '[[ "$remote_ip" == \'127.0.0.1\' || "$remote_ip" == \'::1\' || "${_fallback_ip:-}" == "$remote_ip" ]] || fail_origin "unexpected_remote_ip_$remote_ip"',
      `if grep -Fq '${SITEGROUND_CAPTCHA_PATH}' "$body"; then fail_origin 'captcha-body'; fi`,
      'if grep -Eiq \'^sg-captcha:[[:space:]]*challenge\' "$headers"; then fail_origin \'captcha-header\'; fi',
      'extract_meta_content() {',
      String.raw`  php -r '$html=file_get_contents($argv[1]); $wanted=strtolower($argv[2]); preg_match_all("/<meta\b[^>]*>/is", $html, $tags); foreach ($tags[0] as $tag) { if (!preg_match("/\bname\s*=\s*(?:\x22([^\x22]+)\x22|\x27([^\x27]+)\x27)/is", $tag, $name)) continue; $actual=strtolower(trim(html_entity_decode($name[1] !== "" ? $name[1] : $name[2], ENT_QUOTES | ENT_HTML5, "UTF-8"))); if ($actual !== $wanted) continue; if (preg_match("/\bcontent\s*=\s*(?:\x22([^\x22]*)\x22|\x27([^\x27]*)\x27)/is", $tag, $content)) echo trim(html_entity_decode($content[1] !== "" ? $content[1] : $content[2], ENT_QUOTES | ENT_HTML5, "UTF-8")); break; }' "$body" "$1"`,
      '}',
      'deploy_sha="$(extract_meta_content nvx-deploy-sha)"',
      '[[ "$deploy_sha" == "$EXPECTED_SHA" ]] || fail_origin "deploy_sha_${deploy_sha:-missing}"',
      'robots_meta="$(extract_meta_content robots)"',
      'xrobots="$(grep -Ei \'^x-robots-tag:\' "$headers" | sed -E \'s/^[Xx]-[Rr]obots-[Tt]ag:[[:space:]]*//\' | paste -sd, - || true)"',
      'combined="${robots_meta}${xrobots:+,${xrobots}}"',
      'printf \'%s\' "$combined" | grep -Eiq \'noindex\' || fail_origin \'missing-noindex\'',
      'printf \'%s\' "$combined" | grep -Eiq \'nofollow\' || fail_origin \'missing-nofollow\'',
      'if printf \'%s\' "$combined" | grep -Eiq \'(^|[^a-z])index[[:space:]]*,?[[:space:]]*follow([^a-z]|$)\'; then fail_origin \'index-follow\'; fi',
      String.raw`robots_b64="$(printf '%s' "$combined" | base64 | tr -d '\n')"`,
      'echo "ORIGIN_VERIFY=PASS route=$ROUTE status=$code final=$effective remote_ip=$remote_ip sha=$deploy_sha robots_b64=$robots_b64"',
      '',
    ].join('\n');

    const result = runOriginScript({ originSshAlias, expectedHost, expectedSha, route, remoteScript });
    const stdout = (result.stdout || '').trim();
    const stderr = (result.stderr || '').trim();
    const statusMatch = stdout.match(/\bstatus=(\d{3})\b/);
    const shaMatch = stdout.match(/\bsha=([0-9a-f]{40})\b/);
    const robotsMatch = stdout.match(/robots_b64=([A-Za-z0-9+/=]+)/);

    return {
      attempted: true,
      pass: !result.error && result.status === 0,
      status: result.status,
      signal: result.signal || '',
      stdout,
      stderr,
      error: result.error ? result.error.message : '',
      originStatus: statusMatch ? Number.parseInt(statusMatch[1], 10) : null,
      originDeploySha: shaMatch ? shaMatch[1] : '',
      originRobots: robotsMatch ? Buffer.from(robotsMatch[1], 'base64').toString('utf8').trim() : '',
    };
  }

  function fetchHtml(route) {
    validateRoute(route);
    const remoteScript = [
      'set -Eeuo pipefail',
      'origin_url="https://${EXPECTED_HOST}${ROUTE}"',
      'headers="$(mktemp)"',
      'body="$(mktemp)"',
      'cleanup() { rm -f "$headers" "$body"; }',
      'trap cleanup EXIT',
      'fail_origin() { echo "ORIGIN_HTML_FAIL route=$ROUTE reason=$1" >&2; exit 1; }',
      'set +e',
      'result="$(curl -4 -k -sS --connect-timeout 10 --max-time 30 --resolve "${EXPECTED_HOST}:443:127.0.0.1" --proto \'=https\' -A \'Mozilla/5.0 NUVANX-Origin-A11y/1.2\' -H \'Accept: text/html,application/xhtml+xml\' -H \'Cache-Control: no-cache\' -b \'wpSGCacheBypass=1\' -D "$headers" -o "$body" -w \'%{http_code}|%{url_effective}|%{remote_ip}\' "$origin_url")"',
      'curl_rc=$?',
      'if [[ "$curl_rc" -ne 0 ]] || [[ "$result" == 000* ]]; then',
      '  result="$(curl -4 -k -sS --connect-timeout 10 --max-time 30 --proto \'=https\' -A \'Mozilla/5.0 NUVANX-Origin-A11y/1.2\' -H \'Accept: text/html,application/xhtml+xml\' -H \'Cache-Control: no-cache\' -b \'wpSGCacheBypass=1\' -D "$headers" -o "$body" -w \'%{http_code}|%{url_effective}|%{remote_ip}\' "$origin_url")"',
      '  curl_rc=$?',
      'fi',
      'set -e',
      '[[ "$curl_rc" -eq 0 ]] || fail_origin "curl_exit_$curl_rc"',
      'code="$(printf \'%s\' "$result" | cut -d\'|\' -f1)"',
      'effective="$(printf \'%s\' "$result" | cut -d\'|\' -f2)"',
      'remote_ip="$(printf \'%s\' "$result" | cut -d\'|\' -f3)"',
      '[[ "$code" == \'200\' ]] || fail_origin "http_code_$code"',
      'case "$effective" in "https://${EXPECTED_HOST}/"*|"https://${EXPECTED_HOST}") ;; *) fail_origin "final_url_$effective" ;; esac',
      '[[ "$remote_ip" == \'127.0.0.1\' || "$remote_ip" == \'::1\' || "${_fallback_ip:-}" == "$remote_ip" ]] || fail_origin "unexpected_remote_ip_$remote_ip"',
      `if grep -Fq '${SITEGROUND_CAPTCHA_PATH}' "$body"; then fail_origin 'captcha-body'; fi`,
      'if grep -Eiq \'^sg-captcha:[[:space:]]*challenge\' "$headers"; then fail_origin \'captcha-header\'; fi',
      'deploy_sha="$(php -r \'$html=file_get_contents($argv[1]); if (preg_match("/<meta\\b[^>]*\\bname\\s*=\\s*[\\x22\\x27]nvx-deploy-sha[\\x22\\x27][^>]*\\bcontent\\s*=\\s*[\\x22\\x27]([^\\x22\\x27]+)[\\x22\\x27][^>]*>/is", $html, $m) || preg_match("/<meta\\b[^>]*\\bcontent\\s*=\\s*[\\x22\\x27]([^\\x22\\x27]+)[\\x22\\x27][^>]*\\bname\\s*=\\s*[\\x22\\x27]nvx-deploy-sha[\\x22\\x27][^>]*>/is", $html, $m)) echo trim($m[1]);\' "$body")"',
      '[[ "$deploy_sha" == "$EXPECTED_SHA" ]] || fail_origin "deploy_sha_${deploy_sha:-missing}"',
      String.raw`effective_b64="$(printf '%s' "$effective" | base64 | tr -d '\n')"`,
      String.raw`body_b64="$(base64 < "$body" | tr -d '\n')"`,
      'printf \'ORIGIN_HTML=PASS status=%s sha=%s effective_b64=%s body_b64=%s\\n\' "$code" "$deploy_sha" "$effective_b64" "$body_b64"',
      '',
    ].join('\n');

    const result = runOriginScript({
      originSshAlias,
      expectedHost,
      expectedSha,
      route,
      remoteScript,
      timeout: 90000,
      maxBuffer: 16 * 1024 * 1024,
    });
    const stdout = (result.stdout || '').trim();
    const stderr = (result.stderr || '').trim();
    const statusMatch = stdout.match(/\bstatus=(\d{3})\b/);
    const shaMatch = stdout.match(/\bsha=([0-9a-f]{40})\b/);
    const effectiveMatch = stdout.match(/\beffective_b64=([A-Za-z0-9+/=]+)/);
    const bodyMatch = stdout.match(/\bbody_b64=([A-Za-z0-9+/=]+)$/);
    const pass = !result.error && result.status === 0 && Boolean(bodyMatch) && shaMatch?.[1] === expectedSha;

    return {
      attempted: true,
      pass,
      status: result.status,
      signal: result.signal || '',
      stderr,
      error: result.error ? result.error.message : '',
      transportFailure: Boolean(result.error) || result.status === 255,
      originStatus: statusMatch ? Number.parseInt(statusMatch[1], 10) : null,
      originDeploySha: shaMatch ? shaMatch[1] : '',
      effectiveUrl: effectiveMatch ? Buffer.from(effectiveMatch[1], 'base64').toString('utf8') : '',
      html: bodyMatch ? Buffer.from(bodyMatch[1], 'base64').toString('utf8') : '',
    };
  }

  return { originSshAlias, isAvailable, verify, fetchHtml };
}

export function isBlockCTransientSiteGroundFailure(result, baseUrl) {
  if (!result || result.status === 'PASS') return false;
  const blockers = Array.isArray(result.blockers) ? result.blockers.map(String) : [];
  const issues = Array.isArray(result.issues) ? result.issues.map(String) : [];
  const networkErrors = Array.isArray(result.networkErrors) ? result.networkErrors.map(String) : [];
  const status = Number(result.httpStatus || 0);

  if (
    result.status === 'BLOCKED' &&
    blockers.length > 0 &&
    blockers.every((message) => /SiteGround Antibot challenge prevented visual validation/i.test(message)) &&
    issues.length === 0 &&
    (SITEGROUND_TRANSIENT_HTTP_STATUSES.has(status) || (typeof result.finalUrl === 'string' && result.finalUrl.includes(SITEGROUND_CAPTCHA_PATH)))
  ) return true;

  if (
    result.status === 'BLOCKED' &&
    status === 0 &&
    result.geometry == null &&
    blockers.length > 0 &&
    blockers.every((message) => /^Navigation returned no HTTP response$/i.test(message)) &&
    issues.length === 0 &&
    networkErrors.every((msg) => {
      const message = String(msg || '').trim();
      return /net::ERR_ABORTED/i.test(message) && message.startsWith(`${baseUrl}${SITEGROUND_CAPTCHA_PATH}`);
    })
  ) return true;

  if (
    result.status === 'FIX' &&
    blockers.length === 0 &&
    issues.length > 0 &&
    issues.every((message) => /^\d+ same-origin network error\(s\)$/i.test(message)) &&
    networkErrors.length > 0
  ) {
    const expectedDocumentUrl = `${baseUrl}${String(result.route || '')}`;
    const captchaPrefix = `${baseUrl}${SITEGROUND_CAPTCHA_PATH}`;
    return networkErrors.every((msg) => {
      const message = String(msg || '').trim();
      if (!/net::ERR_ABORTED/i.test(message)) return false;
      return message.startsWith(expectedDocumentUrl) || message.startsWith(captchaPrefix);
    });
  }

  return false;
}
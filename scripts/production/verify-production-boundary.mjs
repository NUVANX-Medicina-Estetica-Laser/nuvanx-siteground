import fs from 'node:fs/promises';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import {
  extractMetaContent,
  validGitHubRunId,
  validateDeployIdentity,
} from './deploy-identity-contract.mjs';
import { hasLegacyValoracionDirectForm } from './valoracion-form-contract.mjs';
import { hasValidSignatureFrame } from './signature-frame-contract.mjs';

const CANONICAL_PROD_ROOT = '/home/customer/www/nuvanx.com/public_html';
const ALLOWED_ORIGIN_ALIASES = new Set(['nvx-prod', 'nvx-prod-audit', 'nvx-prod-hubspot', 'production-siteground']);
const baseUrl = (process.env.BASE_URL || 'https://nuvanx.com').replace(/\/$/, '');
const expectedHost = process.env.EXPECTED_HOST || 'nuvanx.com';
const expectedSha = (process.env.EXPECTED_SHA || '').trim();
const expectedRunId = (process.env.EXPECTED_RUN_ID || '').trim();
const originSshAlias = (process.env.ORIGIN_SSH_ALIAS || 'nvx-prod').trim().toLowerCase();
const prodRoot = process.env.PROD_ROOT || CANONICAL_PROD_ROOT;
const prodDbName = (process.env.PROD_DB_NAME || 'db0ecrycwv2tgb').trim();
const requestTimeoutMs = Number.parseInt(process.env.PRODUCTION_BOUNDARY_REQUEST_TIMEOUT_MS || '15000', 10);
const routes = [
  '/',
  '/clinicas-de-medicina-estetica-nuvanx/',
  '/medicina-estetica-chamberi/',
  '/clinicas-de-medicina-estetica-nuvanx/medicina-estetica-goya-barrio-salamanca/',
  '/soluciones-medicas/',
  '/equipo-medico/',
  '/blog/',
  '/madrid/valoracion/',
  '/endolift-primeras-72-horas-que-esperar/',
  '/protocolos-signature/',
  '/remodelacion-corporal-laser-madrid/',
  '/tratamiento-postparto-abdomen-contorno-corporal-madrid/',
];
const SITEGROUND_CAPTCHA_PATH = '/.well-known/sgcaptcha/';
// MUST match HUBSPOT_FORM_ID in ../staging2/hubspot-config.mjs
const HUBSPOT_FORM_ID = '5042522a-0bc5-4381-ac3e-5aee8649b69c';
const META_BROWSER_FORBIDDEN = [
  ['dedupe marker', 'NVX_META_EVENT_DEDUPE_ACTIVE'],
  ['dedupe prefix', 'nvx-meta-event-dedupe-'],
  ['legacy Meta Pixel ID', '1497940655079106'],
  ['facebook loader', 'connect.facebook.net'],
  ['facebook events library', 'fbevents.js'],
];

if (!/^[0-9a-f]{40}$/.test(expectedSha)) {
  console.error('EXPECTED_SHA must be a full lowercase 40-character SHA.');
  process.exit(1);
}
if (expectedRunId && !validGitHubRunId(expectedRunId)) {
  console.error('EXPECTED_RUN_ID must be numeric when supplied.');
  process.exit(1);
}
if (!ALLOWED_ORIGIN_ALIASES.has(originSshAlias)) {
  console.error(`Unsupported ORIGIN_SSH_ALIAS: ${originSshAlias}`);
  process.exit(1);
}
if (prodRoot !== CANONICAL_PROD_ROOT) {
  console.error(`Refusing unexpected production root: ${prodRoot}`);
  process.exit(1);
}
if (!Number.isInteger(requestTimeoutMs) || requestTimeoutMs < 1000 || requestTimeoutMs > 60000) {
  console.error('PRODUCTION_BOUNDARY_REQUEST_TIMEOUT_MS must be an integer from 1000 to 60000.');
  process.exit(1);
}

const scriptDir = path.dirname(fileURLToPath(import.meta.url));
const outputDir = (process.env.PRODUCTION_BOUNDARY_ARTIFACTS_DIR || process.env.ARTIFACTS_DIR)
  ? path.resolve(process.env.PRODUCTION_BOUNDARY_ARTIFACTS_DIR || process.env.ARTIFACTS_DIR)
  : path.join(scriptDir, 'artifacts');
await fs.mkdir(outputDir, { recursive: true });

function robotsTokens(value) {
  return new Set(
    value
      .toLowerCase()
      .split(/[\s,]+/)
      .map((token) => token.trim())
      .filter(Boolean),
  );
}

function renderedDocumentIssues(html, route) {
  const issues = [];
  const bodyMatch = html.match(/<body\b[^>]*>([\s\S]*?)<\/body>/i);
  if (!bodyMatch || bodyMatch[1].trim() === '') issues.push('Missing or empty <body>');
  if (!/<main\b[^>]*>/i.test(html)) issues.push('Missing <main> landmark');
  if (route === '/' && !html.includes('id="nvx-home-v3"')) issues.push('Missing canonical home marker nvx-home-v3');
  if (route === '/blog/' && !html.includes('nvx-blog-index')) issues.push('Missing canonical blog archive marker nvx-blog-index');

  if (route === '/madrid/valoracion/') {
    if (!html.includes('id="nvx-hubspot-native-form"')) issues.push('Missing canonical HubSpot form host');
    if (!html.includes('data-nvx-consent="functional"')) issues.push('Missing functional-consent valuation marker');
    const frameCount = (html.match(/<div\b[^>]*class=["'][^"']*\bhs-form-frame\b[^"']*["'][^>]*>/gi) || []).length;
    if (frameCount !== 1) issues.push(`Expected exactly one HubSpot form frame, found ${frameCount}`);
    if (!html.includes(HUBSPOT_FORM_ID)) issues.push('Missing canonical HubSpot form ID');
    if (hasLegacyValoracionDirectForm(html)) {
      issues.push('Legacy first-party valoración form still present');
    }
  }

  if (['/protocolos-signature/', '/remodelacion-corporal-laser-madrid/', '/tratamiento-postparto-abdomen-contorno-corporal-madrid/'].includes(route)) {
    if (!hasValidSignatureFrame(html)) {
      issues.push('Missing valid Signature brand frame (requires standalone nvx-brand-page nvx-brand-page--signature root or outer nvx-brand-page with inner nvx-brand-page--signature nvx-brand-page__renderer-root)');
    }
  }
  return issues;
}

function metaNoConsentIssues(html, headers) {
  const issues = [];
  const setCookies = typeof headers.getSetCookie === 'function'
    ? headers.getSetCookie()
    : [headers.get('set-cookie')].filter(Boolean);
  if (setCookies.some((value) => /(?:^|,\s*|;\s*)(?:_fbp|_fbc)=/i.test(value))) {
    issues.push('Pre-consent Meta cookie _fbp/_fbc emitted');
  }
  if (/\bfbq\s*\(/i.test(html)) issues.push('Browser fbq() owner present');
  if (/(?:document\.cookie|cookie\s*=)[\s\S]{0,500}(?:_fbp|_fbc)/i.test(html)) {
    issues.push('Browser Meta cookie writer present');
  }
  for (const [label, marker] of META_BROWSER_FORBIDDEN) {
    if (html.toLowerCase().includes(marker.toLowerCase())) issues.push(`${label} present`);
  }
  return issues;
}

function parseProbeIdentity(output) {
  const match = output.match(
    /PRODUCTION_(?:ORIGIN|PUBLIC_EDGE)_IDENTITY=PASS sha=([0-9a-f]{40}) run_id=(\d+) timestamp=(\S+) release_id=([A-Za-z0-9_-]+)/,
  );
  if (!match) throw new Error('Production probe did not emit a parseable four-field deploy identity.');
  return {
    DEPLOY_SHA: match[1],
    DEPLOY_RUN_ID: match[2],
    DEPLOY_TIMESTAMP: match[3],
    RELEASE_ID: match[4],
  };
}

function sameIdentity(left, right) {
  return ['DEPLOY_SHA', 'DEPLOY_RUN_ID', 'DEPLOY_TIMESTAMP', 'RELEASE_ID'].every((key) => left?.[key] === right?.[key]);
}

function verifyFromSiteGroundProbe(probeMode = 'origin') {
  if (!['origin', 'public-edge'].includes(probeMode)) throw new Error(`Unsupported SiteGround probe mode: ${probeMode}`);
  const remoteScript = String.raw`set -Eeuo pipefail
cd "$PROD_ROOT"
probe_mode="$PROBE_MODE"
case "$probe_mode" in origin|public-edge) ;; *) echo "PRODUCTION_PROBE_FAIL reason=invalid_probe_mode mode=$probe_mode" >&2; exit 1 ;; esac
if [[ "$probe_mode" == 'public-edge' ]]; then
  identity_label='PRODUCTION_PUBLIC_EDGE_IDENTITY'
  route_label='PRODUCTION_PUBLIC_EDGE_ROUTE'
  boundary_label='PRODUCTION_PUBLIC_EDGE_BOUNDARY'
else
  identity_label='PRODUCTION_ORIGIN_IDENTITY'
  route_label='PRODUCTION_ORIGIN_ROUTE'
  boundary_label='PRODUCTION_ORIGIN_BOUNDARY'
fi

test "$(tr -d '\r\n' < wp-content/themes/nuvanx-medical/.nvx-deploy-sha)" = "$EXPECTED_SHA"
test "$(wp config get DB_NAME)" = "$PROD_DB_NAME"
test "$(wp option get home)" = 'https://nuvanx.com'
test "$(wp option get siteurl)" = 'https://nuvanx.com'
test "$(wp option get blog_public)" = '1'
test "$(wp theme list --status=active --field=name)" = 'nuvanx-medical'
test -f 'wp-content/themes/nuvanx-medical/.nvx-deploy-stamp.json'

read_stamp() {
  php -r '
    $path = $argv[1] ?? "";
    $key = $argv[2] ?? "";
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === "") { fwrite(STDERR, "stamp_unreadable path=".$path."\n"); exit(1); }
    $json = json_decode($raw, true);
    if (!is_array($json)) { fwrite(STDERR, "stamp_invalid_json\n"); exit(1); }
    $value = isset($json[$key]) ? trim((string) $json[$key]) : "";
    if ($value === "") { fwrite(STDERR, "stamp_empty_key=".$key."\n"); exit(1); }
    echo $value;
  ' -- wp-content/themes/nuvanx-medical/.nvx-deploy-stamp.json "$1"
}

stamp_sha="$(read_stamp DEPLOY_SHA)"
stamp_run_id="$(read_stamp DEPLOY_RUN_ID)"
stamp_timestamp="$(read_stamp DEPLOY_TIMESTAMP)"
stamp_release="$(read_stamp RELEASE_ID)"

test "$stamp_sha" = "$EXPECTED_SHA"
[[ "$stamp_run_id" =~ ^[0-9]+$ ]]
if [[ -n "$EXPECTED_RUN_ID" ]]; then test "$stamp_run_id" = "$EXPECTED_RUN_ID"; fi
php -r '$v=$argv[1]; $d=DateTimeImmutable::createFromFormat("Y-m-d\\TH:i:s\\Z", $v, new DateTimeZone("UTC")); if ($d === false || $d->format("Y-m-d\\TH:i:s\\Z") !== $v) { exit(1); }' "$stamp_timestamp"
[[ "$stamp_release" =~ ^[A-Za-z0-9_-]+$ ]]
echo "$identity_label=PASS sha=$stamp_sha run_id=$stamp_run_id timestamp=$stamp_timestamp release_id=$stamp_release"

escape_ere() {
  printf '%s' "$1" | sed 's/[][\\.^$*+?(){}|]/\\&/g'
}

assert_meta_equals() {
  local body="$1" name="$2" expected="$3" tag name_re expected_re
  name_re="$(escape_ere "$name")"
  expected_re="$(escape_ere "$expected")"
  tag="$(tr '\r\n' '  ' < "$body" | grep -Eio '<meta[[:space:]][^>]*>' | grep -Ei "name[[:space:]]*=[[:space:]]*['\"]$name_re['\"]" | head -n 1 || true)"
  if [[ -z "$tag" ]]; then
    echo "PRODUCTION_PROBE_FAIL reason=missing_meta name=$name mode=$probe_mode" >&2
    return 1
  fi
  if ! printf '%s' "$tag" | grep -Eq "content[[:space:]]*=[[:space:]]*['\"]$expected_re['\"]"; then
    echo "PRODUCTION_PROBE_FAIL reason=meta_mismatch name=$name expected=$expected mode=$probe_mode tag=$tag" >&2
    return 1
  fi
}

meta_tag_for() {
  local body="$1" name="$2" name_re
  name_re="$(escape_ere "$name")"
  tr '\r\n' '  ' < "$body" | grep -Eio '<meta[[:space:]][^>]*>' | grep -Ei "name[[:space:]]*=[[:space:]]*['\"]$name_re['\"]" | head -n 1 || true
}

require_body() {
  local body="$1" route="$2" marker="$3"
  grep -Fq "$marker" "$body" || {
    echo "PRODUCTION_PROBE_FAIL route=$route reason=missing_string marker=$marker mode=$probe_mode" >&2
    return 1
  }
}

legacy_valoracion_direct_form_count() {
  local body="$1"
  php -r '
    $html = @file_get_contents($argv[1]);
    if (!is_string($html)) { fwrite(STDERR, "legacy_form_body_unreadable\n"); exit(2); }
    $html = preg_replace("~<script\b[^>]*>.*?</script\s*>|<style\b[^>]*>.*?</style\s*>~is", "", $html);
    if (!is_string($html)) { fwrite(STDERR, "legacy_form_strip_failed\n"); exit(2); }
    preg_match_all("~<form\b[^>]*>~i", $html, $forms);
    $count = 0;
    foreach ($forms[0] as $tag) {
      if (preg_match("~(?:^|[^a-z0-9_-])nvx-valoracion-direct-form(?:[^a-z0-9_-]|$)~i", $tag)
          || preg_match("~\bdata-nvx-direct-form(?:\s*=|\s|/?>)~i", $tag)) {
        ++$count;
      }
    }
    echo $count;
  ' -- "$body"
}

validate_signature_frame() {
  local body="$1" route="$2"
  php -r '
    $html = @file_get_contents($argv[1]);
    if (!is_string($html) || trim($html) === "") { exit(1); }
    $clean = preg_replace("/<!--.*?-->/s", "", $html);
    $clean = preg_replace("~<script\b[^>]*>.*?</script\s*>|<style\b[^>]*>.*?</style\s*>~is", "", $clean);
    if (!is_string($clean)) { exit(1); }

    $voidElements = array_flip(["area", "base", "br", "col", "embed", "hr", "img", "input", "link", "meta", "param", "source", "track", "wbr"]);
    $stack = [];

    if (!preg_match_all("~<\s*(/)?\s*([a-zA-Z0-9:-]+)([^>]*?)(\/?)>~s", $clean, $matches, PREG_SET_ORDER)) {
      exit(1);
    }

    $standalone = false;
    $governed = false;

    foreach ($matches as $m) {
      $isClosing = $m[1] === "/";
      $tag = strtolower($m[2]);
      $attrStr = $m[3];
      $isSelfClosing = ($m[4] === "/") || isset($voidElements[$tag]);

      if ($isClosing) {
        for ($i = count($stack) - 1; $i >= 0; $i--) {
          if ($stack[$i]["tag"] === $tag) {
            array_splice($stack, $i);
            break;
          }
        }
        continue;
      }

      $classes = [];
      if (preg_match("/\bclass=[\"\x27]([^\"\x27]*)[\"\x27]/i", $attrStr, $cm)) {
        $tokens = preg_split("/\s+/", trim($cm[1]));
        $classes = array_values(array_filter($tokens));
      }

      if ($tag === "div" || $tag === "article") {
        $hasBrandPage = in_array("nvx-brand-page", $classes, true);
        $hasSignature = in_array("nvx-brand-page--signature", $classes, true);
        $hasRendererRoot = in_array("nvx-brand-page__renderer-root", $classes, true);

        $brandPageAncestorCount = 0;
        foreach ($stack as $parent) {
          if (in_array("nvx-brand-page", $parent["classes"], true)) {
            $brandPageAncestorCount++;
          }
        }

        if ($hasBrandPage && $hasSignature && $brandPageAncestorCount === 0) {
          $standalone = true;
        }
        if (!$hasBrandPage && $hasSignature && $hasRendererRoot && $brandPageAncestorCount === 1) {
          $governed = true;
        }
      }

      if (!$isSelfClosing) {
        $stack[] = ["tag" => $tag, "classes" => $classes];
      }
    }

    exit(($standalone || $governed) ? 0 : 1);
  ' -- "$body" || {
    echo "PRODUCTION_PROBE_FAIL route=$route reason=invalid_signature_frame mode=$probe_mode" >&2
    return 1
  }
}

ua='NUVANX-Production-Boundary/1.6'
for route in \
  '/' \
  '/clinicas-de-medicina-estetica-nuvanx/' \
  '/medicina-estetica-chamberi/' \
  '/clinicas-de-medicina-estetica-nuvanx/medicina-estetica-goya-barrio-salamanca/' \
  '/soluciones-medicas/' \
  '/equipo-medico/' \
  '/blog/' \
  '/madrid/valoracion/' \
  '/endolift-primeras-72-horas-que-esperar/' \
  '/protocolos-signature/' \
  '/remodelacion-corporal-laser-madrid/' \
  '/tratamiento-postparto-abdomen-contorno-corporal-madrid/'
do
  headers="$(mktemp)"
  body="$(mktemp)"
  cleanup() { rm -f "$headers" "$body"; }
  fallback_ip=''
  if [[ "$probe_mode" == 'origin' ]]; then
    is_valid_ipv4() {
      local ip="$1" a b c d extra octet
      IFS=. read -r a b c d extra <<< "$ip"
      [[ -z "$extra" && -n "$a" && -n "$b" && -n "$c" && -n "$d" ]] || return 1
      for octet in "$a" "$b" "$c" "$d"; do
        [[ "$octet" =~ ^[0-9]{1,3}$ ]] || return 1
        (( 10#$octet <= 255 )) || return 1
      done
    }
    set +e
    result="$(curl -4 -k -sS -L --max-redirs 5 --connect-timeout 10 --max-time 30 --resolve "$EXPECTED_HOST:443:127.0.0.1" --proto '=https' --proto-redir '=https' -A "$ua" -H 'Accept: text/html,application/xhtml+xml' -H 'Cache-Control: no-cache' -b 'wpSGCacheBypass=1' -D "$headers" -o "$body" -w '%{http_code}|%{url_effective}|%{remote_ip}' "$BASE_URL$route")"
    curl_rc=$?
    if [[ "$curl_rc" -eq 7 ]]; then
      for candidate in $(hostname -I 2>/dev/null || true); do
        if is_valid_ipv4 "$candidate" && [[ "$candidate" != 127.* ]]; then
          fallback_ip="$candidate"
          break
        fi
      done
      [[ -n "$fallback_ip" ]] || { echo "PRODUCTION_PROBE_FAIL route=$route reason=origin_fallback_ip_unavailable" >&2; cleanup; exit 1; }
      : > "$headers"
      : > "$body"
      result="$(curl -4 -k -sS -L --max-redirs 5 --connect-timeout 10 --max-time 30 --resolve "$EXPECTED_HOST:443:$fallback_ip" --proto '=https' --proto-redir '=https' -A "$ua" -H 'Accept: text/html,application/xhtml+xml' -H 'Cache-Control: no-cache' -b 'wpSGCacheBypass=1' -D "$headers" -o "$body" -w '%{http_code}|%{url_effective}|%{remote_ip}' "$BASE_URL$route")"
      curl_rc=$?
    fi
    set -e
    [[ "$curl_rc" -eq 0 ]] || { echo "PRODUCTION_PROBE_FAIL route=$route reason=origin_curl_exit_$curl_rc" >&2; cleanup; exit 1; }
  else
    result="$(curl -sS -L --max-redirs 5 --max-time 30 --proto '=https' --proto-redir '=https' -A "$ua" -H 'Accept: text/html,application/xhtml+xml' -H 'Cache-Control: no-cache' -D "$headers" -o "$body" -w '%{http_code}|%{url_effective}|%{remote_ip}' "$BASE_URL$route")"
  fi
  code="$(printf '%s' "$result" | cut -d'|' -f1)"
  effective="$(printf '%s' "$result" | cut -d'|' -f2)"
  remote_ip="$(printf '%s' "$result" | cut -d'|' -f3)"
  [[ "$code" == '200' ]] || { echo "PRODUCTION_PROBE_FAIL route=$route reason=http_code code=$code mode=$probe_mode" >&2; cleanup; exit 1; }
  case "$effective" in
    https://nuvanx.com/*|https://nuvanx.com) ;;
    *) echo "PRODUCTION_PROBE_FAIL route=$route final=$effective mode=$probe_mode" >&2; cleanup; exit 1 ;;
  esac
  if [[ "$probe_mode" == 'origin' ]]; then
    if [[ -n "$fallback_ip" ]]; then
      [[ "$remote_ip" == "$fallback_ip" ]] \
        || { echo "PRODUCTION_PROBE_FAIL route=$route reason=origin_remote_ip_mismatch expected=$fallback_ip actual=$remote_ip" >&2; cleanup; exit 1; }
    else
      [[ "$remote_ip" == '127.0.0.1' ]] \
        || { echo "PRODUCTION_PROBE_FAIL route=$route reason=origin_remote_ip_mismatch expected=127.0.0.1 actual=$remote_ip" >&2; cleanup; exit 1; }
    fi
  else
    [[ -n "$remote_ip" && "$remote_ip" != '127.0.0.1' && "$remote_ip" != '::1' ]] \
      || { echo "PRODUCTION_PROBE_FAIL route=$route reason=public_edge_loopback remote_ip=$remote_ip" >&2; cleanup; exit 1; }
  fi
  ! grep -Fq '${SITEGROUND_CAPTCHA_PATH}' "$body" || { echo "PRODUCTION_PROBE_FAIL route=$route reason=captcha_path_in_body mode=$probe_mode" >&2; cleanup; exit 1; }
  ! grep -Eiq '^sg-captcha:[[:space:]]*challenge' "$headers" || { echo "PRODUCTION_PROBE_FAIL route=$route reason=sg_captcha_challenge mode=$probe_mode" >&2; cleanup; exit 1; }
  php -r '$html=@file_get_contents($argv[1]); if (!is_string($html) || !preg_match("~<body\\b[^>]*>(.*?)</body>~is", $html, $m) || trim($m[1]) === "") { exit(1); }' "$body" \
    || { echo "PRODUCTION_PROBE_FAIL route=$route reason=missing_or_empty_body mode=$probe_mode" >&2; cleanup; exit 1; }
  grep -Eiq '<main([[:space:]>])' "$body" || { echo "PRODUCTION_PROBE_FAIL route=$route reason=missing_main mode=$probe_mode" >&2; cleanup; exit 1; }

  ! grep -Eiq '^set-cookie:[[:space:]]*(_fbp|_fbc)=' "$headers" \
    || { echo "PRODUCTION_PROBE_FAIL route=$route reason=pre_consent_meta_cookie mode=$probe_mode" >&2; cleanup; exit 1; }
  for marker in 'NVX_META_EVENT_DEDUPE_ACTIVE' 'nvx-meta-event-dedupe-' '1497940655079106' 'connect.facebook.net' 'fbevents.js'; do
    ! grep -Fiq "$marker" "$body" \
      || { echo "PRODUCTION_PROBE_FAIL route=$route reason=browser_meta_owner marker=$marker mode=$probe_mode" >&2; cleanup; exit 1; }
  done
  ! grep -Eiq 'fbq[[:space:]]*\(' "$body" \
    || { echo "PRODUCTION_PROBE_FAIL route=$route reason=browser_fbq_owner mode=$probe_mode" >&2; cleanup; exit 1; }

  assert_meta_equals "$body" 'nvx-deploy-sha' "$stamp_sha"
  assert_meta_equals "$body" 'nvx-deploy-run-id' "$stamp_run_id"
  assert_meta_equals "$body" 'nvx-deploy-timestamp' "$stamp_timestamp"
  assert_meta_equals "$body" 'nvx-release-id' "$stamp_release"

  robots_meta="$(meta_tag_for "$body" 'robots')"
  xrobots="$(grep -Ei '^x-robots-tag:' "$headers" | tail -n 1 || true)"
  combined="$robots_meta $xrobots"
  ! printf '%s' "$combined" | grep -Eiq 'noindex|nofollow' \
    || { echo "PRODUCTION_PROBE_FAIL route=$route reason=noindex-or-nofollow mode=$probe_mode robots=$combined" >&2; cleanup; exit 1; }
  printf '%s' "$combined" | grep -Eiq 'index' \
    || { echo "PRODUCTION_PROBE_FAIL route=$route reason=missing_index mode=$probe_mode robots=$combined" >&2; cleanup; exit 1; }
  printf '%s' "$combined" | grep -Eiq 'follow' \
    || { echo "PRODUCTION_PROBE_FAIL route=$route reason=missing_follow mode=$probe_mode robots=$combined" >&2; cleanup; exit 1; }

  case "$route" in
    '/')
      require_body "$body" "$route" 'id="nvx-home-v3"' || { cleanup; exit 1; }
      ;;
    '/blog/')
      require_body "$body" "$route" 'nvx-blog-index' || { cleanup; exit 1; }
      ;;
    '/madrid/valoracion/')
      require_body "$body" "$route" 'id="nvx-hubspot-native-form"' || { cleanup; exit 1; }
      require_body "$body" "$route" 'data-nvx-consent="functional"' || { cleanup; exit 1; }
      require_body "$body" "$route" 'hs-form-frame' || { cleanup; exit 1; }
      require_body "$body" "$route" '${HUBSPOT_FORM_ID}' || { cleanup; exit 1; }
      legacy_direct_forms="$(legacy_valoracion_direct_form_count "$body")"
      [[ "$legacy_direct_forms" == '0' ]] \
        || { echo "PRODUCTION_PROBE_FAIL route=$route reason=legacy_direct_form count=$legacy_direct_forms mode=$probe_mode" >&2; cleanup; exit 1; }
      ;;
    '/protocolos-signature/')
      validate_signature_frame "$body" "$route" || { cleanup; exit 1; }
      require_body "$body" "$route" 'Una ruta de decisión, no un paquete cerrado' || { cleanup; exit 1; }
      require_body "$body" "$route" 'Arquitecturas clínicas' || { cleanup; exit 1; }
      ;;
    '/remodelacion-corporal-laser-madrid/')
      validate_signature_frame "$body" "$route" || { cleanup; exit 1; }
      require_body "$body" "$route" 'Cómo se decide el plan corporal' || { cleanup; exit 1; }
      require_body "$body" "$route" 'Zonas de valoración' || { cleanup; exit 1; }
      require_body "$body" "$route" 'Tu primera valoración clínica' || { cleanup; exit 1; }
      ;;
    '/tratamiento-postparto-abdomen-contorno-corporal-madrid/')
      validate_signature_frame "$body" "$route" || { cleanup; exit 1; }
      require_body "$body" "$route" 'Qué se valora en postparto' || { cleanup; exit 1; }
      require_body "$body" "$route" 'Rutas relacionadas' || { cleanup; exit 1; }
      require_body "$body" "$route" 'Tu primera valoración clínica' || { cleanup; exit 1; }
      ;;
  esac

  cleanup
  reported_remote_ip="$remote_ip"
  [[ -n "$reported_remote_ip" ]] || reported_remote_ip='local'
  echo "$route_label=PASS route=$route render_contract=pass meta_no_consent=pass remote_ip=$reported_remote_ip"
done

echo "$boundary_label=PASS sha=$stamp_sha run_id=$stamp_run_id routes=12 identity_fields=4 render_contract=pass meta_no_consent=pass mode=$probe_mode"
`;

  try {
    return execFileSync(
      '/usr/bin/ssh',
      [
        originSshAlias,
        `PROD_ROOT=${prodRoot} BASE_URL=${baseUrl} EXPECTED_HOST=${expectedHost} EXPECTED_SHA=${expectedSha} EXPECTED_RUN_ID=${expectedRunId} SITEGROUND_CAPTCHA_PATH=${SITEGROUND_CAPTCHA_PATH} PROD_DB_NAME=${prodDbName} PROBE_MODE=${probeMode} bash -se`,
      ],
      { input: remoteScript, encoding: 'utf8', timeout: 180000, maxBuffer: 1024 * 1024 },
    ).trim();
  } catch (error) {
    const err = error instanceof Error ? error : new Error(String(error));
    const stderr = typeof err.stderr === 'string' ? err.stderr.trim() : '';
    const stdout = typeof err.stdout === 'string' ? err.stdout.trim() : '';
    throw new Error([err.message, stdout && `stdout: ${stdout}`, stderr && `stderr: ${stderr}`].filter(Boolean).join('\n'));
  }
}

function verifyFromSiteGroundOrigin() {
  return verifyFromSiteGroundProbe('origin');
}

function verifyFromSiteGroundPublicEdge() {
  return verifyFromSiteGroundProbe('public-edge');
}

async function fetchSameHost(url, maxRedirects = 5) {
  let current = new URL(url);
  const hops = [];
  for (let i = 0; i <= maxRedirects; i += 1) {
    if (current.hostname !== expectedHost) throw new Error(`Refusing cross-host request: ${current.hostname} != ${expectedHost}`);
    const response = await fetch(current, {
      redirect: 'manual',
      signal: AbortSignal.timeout(requestTimeoutMs),
      headers: {
        'user-agent': 'NUVANX-Production-Boundary/1.6',
        accept: 'text/html,application/xhtml+xml',
        'cache-control': 'no-cache',
        pragma: 'no-cache',
      },
    });
    const status = response.status;
    const location = response.headers.get('location');
    const sgCaptcha = response.headers.get('sg-captcha') || '';
    hops.push({ url: current.toString(), status, location, sgCaptcha });
    if (status === 202 || sgCaptcha) throw new Error(`SiteGround challenge at ${current}: HTTP ${status} sg-captcha=${sgCaptcha || '(missing)'}`);
    if (status >= 300 && status < 400) {
      if (!location) throw new Error(`Redirect ${status} without Location at ${current}`);
      const next = new URL(location, current);
      if (next.hostname !== expectedHost) throw new Error(`Cross-host redirect detected: ${current.hostname} -> ${next.hostname}`);
      current = next;
      continue;
    }
    return { response, finalUrl: current, hops };
  }
  throw new Error(`Too many redirects for ${url}`);
}

const report = {
  baseUrl,
  expectedHost,
  expectedSha,
  expectedRunId: expectedRunId || null,
  originSshAlias,
  checkedAt: new Date().toISOString(),
  requestTimeoutMs,
  origin: { pass: false, output: '', issue: '', identity: null },
  external: { pass: false, inconclusiveAntiBot: false },
  sitegroundPublicEdge: { attempted: false, pass: false, output: '', issue: '', identity: null },
  routes: [],
  failures: [],
  pass: false,
};

try {
  report.origin.output = verifyFromSiteGroundOrigin();
  report.origin.identity = parseProbeIdentity(report.origin.output);
  report.origin.pass = true;
  console.log(report.origin.output);
} catch (error) {
  report.origin.issue = error instanceof Error ? error.message : String(error);
  report.failures.push({ route: 'origin', issues: [report.origin.issue] });
}

if (report.origin.pass) {
  for (const route of routes) {
    const requested = new URL(route, `${baseUrl}/`).toString();
    const result = { route, requested, pass: false };
    try {
      const { response, finalUrl, hops } = await fetchSameHost(requested);
      const html = await response.text();
      const stagingLeaks = [];
      if (html.includes('staging2.nuvanx.com')) stagingLeaks.push('staging2.nuvanx.com');
      if (html.includes('nvx_env=staging')) stagingLeaks.push('nvx_env=staging');
      if (html.includes('debug:true')) stagingLeaks.push('debug:true');
      if (stagingLeaks.length > 0) {
        throw new Error(`Staging parameters leaked in production HTML: ${stagingLeaks.join(', ')}`);
      }
      const robots = extractMetaContent(html, 'robots');
      const identity = {
        DEPLOY_SHA: extractMetaContent(html, 'nvx-deploy-sha'),
        DEPLOY_RUN_ID: extractMetaContent(html, 'nvx-deploy-run-id'),
        DEPLOY_TIMESTAMP: extractMetaContent(html, 'nvx-deploy-timestamp'),
        RELEASE_ID: extractMetaContent(html, 'nvx-release-id'),
      };
      const xRobotsTag = response.headers.get('x-robots-tag') || '';
      const tokens = robotsTokens(`${robots},${xRobotsTag}`);

      Object.assign(result, {
        status: response.status,
        finalUrl: finalUrl.toString(),
        finalHost: finalUrl.hostname,
        redirects: hops,
        robots,
        xRobotsTag,
        deploySha: identity.DEPLOY_SHA,
        deployRunId: identity.DEPLOY_RUN_ID,
        deployTimestamp: identity.DEPLOY_TIMESTAMP,
        releaseId: identity.RELEASE_ID,
        issues: [],
      });

      if (response.status !== 200) result.issues.push(`Expected HTTP 200, got ${response.status}`);
      if (finalUrl.hostname !== expectedHost) result.issues.push(`Final hostname ${finalUrl.hostname} != ${expectedHost}`);
      result.issues.push(...renderedDocumentIssues(html, route));
      result.issues.push(...metaNoConsentIssues(html, response.headers));
      if (tokens.has('noindex') || tokens.has('nofollow')) result.issues.push(`Production exposes noindex/nofollow: meta="${robots}" x-robots="${xRobotsTag}"`);
      if (!tokens.has('index') || !tokens.has('follow')) result.issues.push(`Expected production robots index,follow; meta="${robots || '(missing)'}" x-robots="${xRobotsTag || '(missing)'}"`);
      result.issues.push(...validateDeployIdentity(identity, { expectedSha, expectedRunId }));
      if (identity.DEPLOY_RUN_ID !== report.origin.identity.DEPLOY_RUN_ID) result.issues.push(`Edge/origin run ID mismatch: edge=${identity.DEPLOY_RUN_ID || '(missing)'} origin=${report.origin.identity.DEPLOY_RUN_ID}`);
      if (identity.DEPLOY_TIMESTAMP !== report.origin.identity.DEPLOY_TIMESTAMP) result.issues.push(`Edge/origin timestamp mismatch: edge=${identity.DEPLOY_TIMESTAMP || '(missing)'} origin=${report.origin.identity.DEPLOY_TIMESTAMP}`);
      if (identity.RELEASE_ID !== report.origin.identity.RELEASE_ID) result.issues.push(`Edge/origin release ID mismatch: edge=${identity.RELEASE_ID || '(missing)'} origin=${report.origin.identity.RELEASE_ID}`);

      result.pass = result.issues.length === 0;
      if (!result.pass) report.failures.push({ route, issues: result.issues });
    } catch (error) {
      result.issues = [error instanceof Error ? error.message : String(error)];
      report.failures.push({ route, issues: result.issues });
    }
    report.routes.push(result);
  }

  const externalFailures = report.failures.filter((failure) => failure.route !== 'origin');
  report.external.pass = externalFailures.length === 0;
  report.external.inconclusiveAntiBot =
    externalFailures.length === routes.length &&
    externalFailures.every(
      (failure) =>
        Array.isArray(failure.issues) &&
        failure.issues.length === 1 &&
        failure.issues.every((issue) => /SiteGround challenge .*HTTP 202 .*sg-captcha=challenge/i.test(String(issue))),
    );

  if (report.external.inconclusiveAntiBot) {
    report.sitegroundPublicEdge.attempted = true;
    try {
      report.sitegroundPublicEdge.output = verifyFromSiteGroundPublicEdge();
      report.sitegroundPublicEdge.identity = parseProbeIdentity(report.sitegroundPublicEdge.output);
      if (!sameIdentity(report.origin.identity, report.sitegroundPublicEdge.identity)) {
        throw new Error('SiteGround public-edge identity does not exactly match the localhost origin identity.');
      }
      report.sitegroundPublicEdge.pass = true;
      console.log(report.sitegroundPublicEdge.output);
      console.log('PRODUCTION_EDGE_CHALLENGE=PASS classification=github-runner-specific siteground_public_edge=pass');
    } catch (error) {
      report.sitegroundPublicEdge.issue = error instanceof Error ? error.message : String(error);
      report.failures.push({ route: 'siteground-public-edge', issues: [report.sitegroundPublicEdge.issue] });
    }
  }

  report.pass = report.origin.pass && (
    report.external.pass ||
    (report.external.inconclusiveAntiBot && report.sitegroundPublicEdge.pass)
  );
}

await fs.writeFile(path.join(outputDir, 'production-boundary.json'), `${JSON.stringify(report, null, 2)}\n`, 'utf8');

if (!report.pass) {
  console.error('Production boundary verification FAILED.');
  for (const failure of report.failures) console.error(`- ${failure.route}: ${failure.issues.join('; ')}`);
  process.exit(1);
}

const verifiedRunId = report.origin.identity?.DEPLOY_RUN_ID || expectedRunId || '(unknown)';
if (report.external.pass) {
  console.log(
    `Production boundary PASS: origin and GitHub-runner external probes agree across ${routes.length} routes; public render, canonical functional HubSpot landing, no-consent Meta boundary, index/follow and exact 4-field identity SHA ${expectedSha} / run ${verifiedRunId} verified.`,
  );
} else {
  console.log(
    `Production boundary PASS: GitHub-runner edge returned only SiteGround 202 challenges across ${routes.length} routes; localhost origin and non-loopback SiteGround public-host probe both verified exact 4-field identity SHA ${expectedSha} / run ${verifiedRunId}, public render, canonical functional HubSpot landing, no-consent Meta boundary and index/follow.`,
  );
}

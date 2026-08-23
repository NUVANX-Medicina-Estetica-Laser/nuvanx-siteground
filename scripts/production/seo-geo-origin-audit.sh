#!/usr/bin/env bash
set -Eeuo pipefail

: "${PROD_ROOT:?Missing PROD_ROOT}"
BASE_URL="${BASE_URL:-https://nuvanx.com}"
EXPECTED_HOST="${EXPECTED_HOST:-nuvanx.com}"
BASE_URL="${BASE_URL%/}"
# PROD_DB_NAME is the canonical production DB identifier used as a boundary
# assertion (not a secret — no password). Default is the SiteGround DB name.
PROD_DB_NAME="${PROD_DB_NAME:-db0ecrycwv2tgb}"

failures=0
warnings=0
url_pass=0
url_fail=0
signature_rest_pass=0
signature_rest_fail=0

fail() { printf 'FAIL %s\n' "$*" >&2; failures=$((failures + 1)); }
warn() { printf 'WARN %s\n' "$*"; warnings=$((warnings + 1)); }
info() { printf 'INFO %s\n' "$*"; }
pass() { printf 'PASS %s\n' "$*"; }

tmpdir="$(mktemp -d)"
trap 'rm -rf "$tmpdir"' EXIT

cd "$PROD_ROOT"
release_sha="$(tr -d '\r\n[:space:]' < wp-content/themes/nuvanx-medical/.nvx-deploy-sha)"
[[ "$release_sha" =~ ^[0-9a-f]{40}$ ]] || { echo 'Invalid production deploy marker.' >&2; exit 1; }
[[ "$(wp config get DB_NAME)" == "$PROD_DB_NAME" ]]
[[ "$(wp option get home)" == "$BASE_URL" ]]
[[ "$(wp option get siteurl)" == "$BASE_URL" ]]
[[ "$(wp option get blog_public)" == '1' ]]
[[ "$(wp theme list --status=active --field=name)" == 'nuvanx-medical' ]]
pass "PRODUCTION_IDENTITY sha=$release_sha"

ua='NUVANX-SEO-GEO-Origin-Audit/1.3'
HTTP_CODE=''
EFFECTIVE_URL=''
HEADERS=''
BODY=''

fetch_url() {
  local url="$1" stem="$2" result
  HEADERS="$tmpdir/${stem}.headers"
  BODY="$tmpdir/${stem}.body"
  if ! result="$(curl -sS -L --max-redirs 5 --max-time 30 -A "$ua" -H 'Accept: text/html,application/xhtml+xml,application/xml,text/xml,text/plain,application/json' -D "$HEADERS" -o "$BODY" -w '%{http_code}|%{url_effective}' "$url")"; then
    HTTP_CODE='000'; EFFECTIVE_URL="$url"; return 1
  fi
  HTTP_CODE="${result%%|*}"
  EFFECTIVE_URL="${result#*|}"
  return 0
}

normalize_url() {
  local value="$1"
  [[ "$value" = "$BASE_URL" ]] && { printf '%s/' "$BASE_URL"; return; }
  printf '%s/' "${value%/}"
}

robots_root_blocked() {
  local wanted="$1" file="$2"
  awk -v wanted="$wanted" '
    BEGIN { IGNORECASE=1; active=0; blocked=0 }
    /^[[:space:]]*User-agent:/ {
      agent=$0
      sub(/^[^:]*:[[:space:]]*/, "", agent)
      gsub(/[[:space:]]+$/, "", agent)
      active=(tolower(agent)==tolower(wanted))
      next
    }
    active && /^[[:space:]]*Disallow:[[:space:]]*\/[[:space:]]*$/ { blocked=1 }
    END { exit blocked ? 0 : 1 }
  ' "$file"
}

cat > "$tmpdir/html-check.php" <<'PHP'
<?php
if ($argc < 5) exit(2);
[$script, $file, $expectedUrl, $expectedSha, $critical] = $argv;
$html = file_get_contents($file);
if ($html === false || $html === '') exit(2);
libxml_use_internal_errors(true);
$dom = new DOMDocument();
$dom->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
$xp = new DOMXPath($dom);
$attrs = static function (DOMXPath $xp, string $query, string $attr): array {
    $out = [];
    foreach ($xp->query($query) ?: [] as $node) {
        if ($node instanceof DOMElement) {
            $v = trim(html_entity_decode($node->getAttribute($attr), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($v !== '') $out[] = $v;
        }
    }
    return $out;
};
$texts = static function (DOMXPath $xp, string $query): array {
    $out = [];
    foreach ($xp->query($query) ?: [] as $node) {
        $v = trim(preg_replace('/\s+/u', ' ', html_entity_decode($node->textContent ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if ($v !== '') $out[] = $v;
    }
    return $out;
};
$norm = static fn(string $u): string => rtrim((string) preg_replace('/[#?].*$/', '', trim($u)), '/') . '/';
$titles = $texts($xp, '/html/head/title');
$desc = $attrs($xp, '//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="description"]', 'content');
$robots = $attrs($xp, '//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="robots"]', 'content');
$canon = $attrs($xp, '//link[contains(concat(" ", normalize-space(translate(@rel,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")), " "), " canonical ")]', 'href');
$og = $attrs($xp, '//meta[translate(@property,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="og:url"]', 'content');
$deploy = $attrs($xp, '//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="nvx-deploy-sha"]', 'content');
$h1 = $texts($xp, '//h1');
$schemas = $texts($xp, '//script[translate(@type,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="application/ld+json"]');
$issues = []; $warn = [];
if (count($titles) < 1 || trim($titles[0] ?? '') === '') $issues[] = 'missing-or-empty-title';
if (count($titles) > 1) $issues[] = 'duplicate-title';
if (count($canon) !== 1) $issues[] = 'canonical-count-' . count($canon);
elseif ($norm($canon[0]) !== $norm($expectedUrl)) $issues[] = 'canonical-mismatch:' . $canon[0];
if (count($og) !== 1) $issues[] = 'og-url-count-' . count($og);
elseif ($norm($og[0]) !== $norm($expectedUrl)) $issues[] = 'og-url-mismatch:' . $og[0];
if (count($deploy) !== 1 || $deploy[0] !== $expectedSha) $issues[] = 'deploy-sha-mismatch:' . ($deploy[0] ?? 'missing');
$robotsText = strtolower(implode(',', $robots));
if (str_contains($robotsText, 'noindex')) $issues[] = 'robots-noindex';
if (str_contains($robotsText, 'nofollow')) $issues[] = 'robots-nofollow';
if (count($h1) < 1) $issues[] = 'missing-h1';
if (count($schemas) < 1) $issues[] = 'missing-jsonld';
if (count($desc) === 0 || trim($desc[0] ?? '') === '') {
    if ($critical === '1') $issues[] = 'missing-meta-description'; else $warn[] = 'missing-meta-description';
}
if (count($desc) > 1) $issues[] = 'duplicate-meta-description';
echo json_encode([
    'url'=>$expectedUrl,
    'title'=>preg_replace('/[\t\r\n]+/u', ' ', $titles[0] ?? ''),
    'head_title_count'=>count($titles),
    'description_length'=>strlen($desc[0] ?? ''),
    'canonical'=>$canon[0] ?? '',
    'og_url'=>$og[0] ?? '',
    'h1_count'=>count($h1),
    'jsonld_blocks'=>count($schemas),
    'issues'=>$issues,
    'warnings'=>$warn,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
exit($issues ? 1 : 0);
PHP

cat > "$tmpdir/rest-check.php" <<'PHP'
<?php
if ($argc < 3) exit(2);
$data = json_decode(file_get_contents($argv[1]), true);
$expected = rtrim($argv[2], '/') . '/';
$issues = [];
if (!is_array($data) || count($data) !== 1) {
    $issues[] = 'rest-result-count';
} else {
    $seo = $data[0]['yoast_head_json'] ?? null;
    if (!is_array($seo)) {
        $issues[] = 'missing-yoast-head-json';
    } else {
        $norm = static fn($u) => rtrim((string) preg_replace('/[#?].*$/', '', trim((string)$u)), '/') . '/';
        if (trim((string)($seo['title'] ?? '')) === '') $issues[] = 'empty-title';
        if (trim((string)($seo['description'] ?? '')) === '') $issues[] = 'empty-description';
        $restCanonical = trim((string)($seo['canonical'] ?? ''));
        if ($restCanonical !== '' && $norm($restCanonical) !== $norm($expected)) {
            $issues[] = 'canonical-mismatch:' . $restCanonical;
        }
        if ($norm($seo['og_url'] ?? '') !== $norm($expected)) $issues[] = 'og-url-mismatch:' . ($seo['og_url'] ?? 'missing');
        $schema = json_encode($seo['schema'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!str_contains($schema, rtrim($expected, '/'))) $issues[] = 'schema-url-mismatch';
    }
}
echo json_encode(['url'=>$expected,'issues'=>$issues], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
exit($issues ? 1 : 0);
PHP

if fetch_url "$BASE_URL/robots.txt" robots; then
  if [[ "$HTTP_CODE" != '200' ]]; then
    fail "ROBOTS_HTTP status=$HTTP_CODE"
  else
    if robots_root_blocked '*' "$BODY"; then fail 'ROBOTS_PUBLIC_ROOT_BLOCKED'; else pass 'ROBOTS_PUBLIC_CRAWL'; fi
    if grep -Eiq '^Sitemap:[[:space:]]*https://nuvanx\.com/sitemap_index\.xml' "$BODY"; then pass 'ROBOTS_SITEMAP_DISCOVERY'; else warn 'ROBOTS_SITEMAP_DIRECTIVE_MISSING'; fi
    for bot in OAI-SearchBot ChatGPT-User PerplexityBot; do
      if robots_root_blocked "$bot" "$BODY"; then fail "AI_SEARCH_CRAWLER_BLOCKED bot=$bot"; else pass "AI_SEARCH_CRAWLER_OPEN bot=$bot"; fi
    done
    for bot in GPTBot Google-Extended; do
      if robots_root_blocked "$bot" "$BODY"; then info "AI_TRAINING_CRAWLER_BLOCKED bot=$bot"; else info "AI_TRAINING_CRAWLER_OPEN bot=$bot"; fi
    done
  fi
else
  fail 'ROBOTS_FETCH'
fi

if fetch_url "$BASE_URL/llms.txt" llms; then
  llms_bytes="$(wc -c < "$BODY" | tr -d ' ')"
  if [[ "$HTTP_CODE" != '200' ]]; then fail "LLMS_HTTP status=$HTTP_CODE"
  elif [[ "$llms_bytes" -lt 100 ]]; then fail "LLMS_TOO_SMALL bytes=$llms_bytes"
  elif ! grep -Eiq 'NUVANX|Medicina Estética|Medicina Estetica' "$BODY"; then fail 'LLMS_IDENTITY_MISSING'
  else pass "LLMS_DISCOVERY bytes=$llms_bytes"; fi
else
  fail 'LLMS_FETCH'
fi

if ! fetch_url "$BASE_URL/sitemap_index.xml" sitemap-index; then echo 'SITEMAP_INDEX_FETCH=FAIL' >&2; exit 1; fi
if [[ "$HTTP_CODE" != '200' ]]; then echo "SITEMAP_INDEX_HTTP=FAIL status=$HTTP_CODE" >&2; exit 1; fi
child_file="$tmpdir/child-sitemaps.txt"
grep -oE '<loc>[^<]+</loc>' "$BODY" | sed -E 's#</?loc>##g' | sed 's/&amp;/\&/g' | sort -u > "$child_file" || true
child_count="$(grep -c '^https://nuvanx\.com' "$child_file" || true)"
if [[ "$child_count" -lt 1 ]]; then echo 'SITEMAP_CHILDREN=0' >&2; exit 1; fi
if grep -Eiq '/(category|post_tag|tag)-sitemap\.xml' "$child_file"; then fail 'THIN_TAXONOMY_SITEMAP_PRESENT'; else pass 'THIN_TAXONOMY_SITEMAP_ABSENT'; fi

urls_file="$tmpdir/sitemap-urls.txt"
: > "$urls_file"
child_index=0
while IFS= read -r sitemap; do
  [[ -n "$sitemap" ]] || continue
  child_index=$((child_index + 1))
  if [[ "$sitemap" != "$BASE_URL"/* ]]; then fail "SITEMAP_CROSS_HOST url=$sitemap"; continue; fi
  if ! fetch_url "$sitemap" "sitemap-child-$child_index"; then fail "SITEMAP_CHILD_FETCH url=$sitemap"; continue; fi
  if [[ "$HTTP_CODE" != '200' ]]; then fail "SITEMAP_CHILD_HTTP url=$sitemap status=$HTTP_CODE"; continue; fi
  grep -oE '<loc>[^<]+</loc>' "$BODY" | sed -E 's#</?loc>##g' | sed 's/&amp;/\&/g' >> "$urls_file" || true
  pass "SITEMAP_CHILD url=$sitemap"
done < "$child_file"
sort -u "$urls_file" -o "$urls_file"
sitemap_urls="$(grep -c '^https://nuvanx\.com' "$urls_file" || true)"
if [[ "$sitemap_urls" -lt 40 ]]; then fail "SITEMAP_URL_COUNT count=$sitemap_urls"; else pass "SITEMAP_URL_COUNT count=$sitemap_urls"; fi

critical_paths=(
  '/soluciones-medicas/'
  '/protocolos-signature/'
  '/remodelacion-corporal-laser-madrid/'
  '/tratamiento-postparto-abdomen-contorno-corporal-madrid/'
  '/papada-definicion-mandibular-madrid/'
  '/madrid/valoracion/'
  '/clinicas-de-medicina-estetica-nuvanx/'
  '/clinicas-de-medicina-estetica-nuvanx/medicina-estetica-goya-barrio-salamanca/'
  '/medicina-estetica-chamberi/'
)
for path in "${critical_paths[@]}"; do
  expected="$(normalize_url "$BASE_URL$path")"
  if grep -Fxq "$expected" "$urls_file"; then pass "CRITICAL_URL_IN_SITEMAP url=$expected"; else fail "CRITICAL_URL_MISSING_FROM_SITEMAP url=$expected"; fi
done

page_index=0
while IFS= read -r raw_url; do
  [[ -n "$raw_url" ]] || continue
  page_index=$((page_index + 1))
  url="$(normalize_url "$raw_url")"
  if [[ "$url" != "$BASE_URL"/* && "$url" != "$BASE_URL/" ]]; then fail "URL_CROSS_HOST url=$url"; url_fail=$((url_fail + 1)); continue; fi
  if ! fetch_url "$url" "page-$page_index"; then fail "URL_FETCH url=$url"; url_fail=$((url_fail + 1)); continue; fi
  if [[ "$HTTP_CODE" != '200' ]]; then fail "URL_HTTP url=$url status=$HTTP_CODE"; url_fail=$((url_fail + 1)); continue; fi
  if grep -Eiq '^x-robots-tag:.*(noindex|nofollow)' "$HEADERS"; then fail "URL_X_ROBOTS_BLOCK url=$url"; url_fail=$((url_fail + 1)); continue; fi
  critical=0
  for path in "${critical_paths[@]}"; do [[ "$url" == "$(normalize_url "$BASE_URL$path")" ]] && critical=1; done
  set +e
  detail="$(php "$tmpdir/html-check.php" "$BODY" "$url" "$release_sha" "$critical" 2>&1)"; rc=$?
  set -e
  if [[ "$rc" -eq 0 ]]; then
    pass "URL_SEO url=$url detail=$detail"; url_pass=$((url_pass + 1))
    if printf '%s' "$detail" | grep -Fq 'missing-meta-description'; then warnings=$((warnings + 1)); fi
  else
    fail "URL_SEO url=$url detail=$detail"; url_fail=$((url_fail + 1))
  fi
done < "$urls_file"

signature_slugs=(
  'protocolos-signature'
  'remodelacion-corporal-laser-madrid'
  'tratamiento-postparto-abdomen-contorno-corporal-madrid'
  'papada-definicion-mandibular-madrid'
  'calidad-piel-firmeza-luminosidad-madrid'
  'cicatrices-acne-poros-textura-madrid'
  'manchas-rojeces-fotorejuvenecimiento-ipl-madrid'
  'grasa-localizada-abdomen-flancos-madrid'
  'flacidez-grasa-localizada-brazos-madrid'
  'grasa-espalda-zona-sujetador-madrid'
  'flacidez-muslos-internos-subgluteo-madrid'
  'tratamiento-rodillas-grasa-flacidez-madrid'
  'contorno-corporal-masculino-madrid'
)
rest_index=0
for slug in "${signature_slugs[@]}"; do
  rest_index=$((rest_index + 1))
  expected="$BASE_URL/$slug/"
  rest_url="$BASE_URL/wp-json/wp/v2/pages?slug=$slug&_fields=id,slug,link,yoast_head_json"
  if ! fetch_url "$rest_url" "rest-$rest_index"; then fail "SIGNATURE_REST_FETCH slug=$slug"; signature_rest_fail=$((signature_rest_fail + 1)); continue; fi
  if [[ "$HTTP_CODE" != '200' ]]; then fail "SIGNATURE_REST_HTTP slug=$slug status=$HTTP_CODE"; signature_rest_fail=$((signature_rest_fail + 1)); continue; fi
  set +e
  detail="$(php "$tmpdir/rest-check.php" "$BODY" "$expected" 2>&1)"; rc=$?
  set -e
  if [[ "$rc" -eq 0 ]]; then pass "SIGNATURE_REST slug=$slug detail=$detail"; signature_rest_pass=$((signature_rest_pass + 1))
  else fail "SIGNATURE_REST slug=$slug detail=$detail"; signature_rest_fail=$((signature_rest_fail + 1)); fi
done

if fetch_url "$BASE_URL/" schema-home && grep -Fq 'MedicalOrganization' "$BODY"; then pass 'SCHEMA_MEDICAL_ORGANIZATION'; else fail 'SCHEMA_MEDICAL_ORGANIZATION'; fi
if fetch_url "$BASE_URL/clinicas-de-medicina-estetica-nuvanx/" schema-clinics && grep -Fq 'MedicalClinic' "$BODY"; then pass 'SCHEMA_MEDICAL_CLINIC'; else fail 'SCHEMA_MEDICAL_CLINIC'; fi

printf 'SEO_GEO_RELEASE_SHA=%s\n' "$release_sha"
printf 'SITEMAP_CHILDREN=%s\n' "$child_count"
printf 'SITEMAP_URLS=%s\n' "$sitemap_urls"
printf 'URL_PASS=%s\n' "$url_pass"
printf 'URL_FAIL=%s\n' "$url_fail"
printf 'SIGNATURE_REST_PASS=%s\n' "$signature_rest_pass"
printf 'SIGNATURE_REST_FAIL=%s\n' "$signature_rest_fail"
printf 'SEO_GEO_WARNINGS=%s\n' "$warnings"
printf 'SEO_GEO_FAILURES=%s\n' "$failures"
if [[ "$failures" -ne 0 ]]; then echo 'SEO_GEO_ORIGIN_AUDIT=FAIL' >&2; exit 1; fi
echo 'SEO_GEO_ORIGIN_AUDIT=PASS'

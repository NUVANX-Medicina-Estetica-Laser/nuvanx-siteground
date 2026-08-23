#!/usr/bin/env bash
set -Eeuo pipefail

BASE_URL="${BASE_URL:-https://nuvanx.com}"
BASE_URL="${BASE_URL%/}"
ua='NUVANX-Document-Head-Title-Audit/1.2'
tmpdir="$(mktemp -d)"
trap 'rm -rf "$tmpdir"' EXIT

matrix_url="$BASE_URL/matriz-diagnostico-facial-estructura-piel-musculo-grasa/"
expected_matrix_title='Matriz de diagnóstico facial | NUVANX Madrid'
expected_matrix_h1='Matriz de diagnóstico facial: estructura, músculo, piel y grasa'
expected_runtime_contract='20260815-immutable-request-final-query-lock-v3'
neighbouring_slug='tratamientos-faciales-sin-cirugia-guia-medica-diagnostico'

fetch() {
  curl -fsSL --max-time 30 -A "$ua" "$1"
}

index="$tmpdir/index.xml"
fetch "$BASE_URL/sitemap_index.xml" > "$index"
children="$tmpdir/children.txt"
grep -oE '<loc>[^<]+</loc>' "$index" | sed -E 's#</?loc>##g' | sort -u > "$children"
urls="$tmpdir/urls.txt"
: > "$urls"
while IFS= read -r sitemap; do
  [[ -n "$sitemap" ]] || continue
  fetch "$sitemap" | grep -oE '<loc>[^<]+</loc>' | sed -E 's#</?loc>##g' >> "$urls"
done < "$children"
sort -u "$urls" -o "$urls"

pass=0
fail=0
matrix_contract_pass=0
matrix_contract_fail=0
index=0
while IFS= read -r url; do
  [[ -n "$url" ]] || continue
  index=$((index + 1))
  html="$tmpdir/page-$index.html"
  fetch "$url" > "$html"
  set +e
  result="$(php -r '
    $html=file_get_contents($argv[1]);
    libxml_use_internal_errors(true);
    $dom=new DOMDocument();
    $dom->loadHTML($html, LIBXML_NOWARNING|LIBXML_NOERROR);
    $xp=new DOMXPath($dom);
    $nodes=$xp->query("/html/head/title");
    $count=$nodes ? $nodes->length : 0;
    $title=$count ? trim(preg_replace("/\\s+/u"," ",$nodes->item(0)->textContent)) : "";
    echo json_encode(["head_title_count"=>$count,"title"=>$title], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),"\n";
    exit($count===1 && $title!=="" ? 0 : 1);
  ' "$html" 2>&1)"
  rc=$?
  set -e
  if [[ "$rc" -eq 0 ]]; then
    echo "PASS DOCUMENT_HEAD_TITLE url=$url detail=$result"
    pass=$((pass + 1))
  else
    echo "FAIL DOCUMENT_HEAD_TITLE url=$url detail=$result" >&2
    fail=$((fail + 1))
  fi

  if [[ "$url" == "$matrix_url" ]]; then
    set +e
    matrix_result="$(php -r '
      [$script,$file,$expectedUrl,$expectedTitle,$expectedH1,$expectedContract,$neighbourSlug]=$argv;
      $html=file_get_contents($file);
      if ($html===false || $html==="") exit(2);
      libxml_use_internal_errors(true);
      $dom=new DOMDocument();
      $dom->loadHTML($html, LIBXML_NOWARNING|LIBXML_NOERROR);
      $xp=new DOMXPath($dom);
      $texts=static function(string $query) use ($xp): array {
        $out=[];
        foreach ($xp->query($query) ?: [] as $node) {
          $value=trim(preg_replace("/\\s+/u"," ",html_entity_decode($node->textContent ?? "",ENT_QUOTES|ENT_HTML5,"UTF-8")));
          if ($value!=="") $out[]=$value;
        }
        return $out;
      };
      $attrs=static function(string $query,string $attr) use ($xp): array {
        $out=[];
        foreach ($xp->query($query) ?: [] as $node) {
          if ($node instanceof DOMElement) {
            $value=trim(html_entity_decode($node->getAttribute($attr),ENT_QUOTES|ENT_HTML5,"UTF-8"));
            if ($value!=="") $out[]=$value;
          }
        }
        return $out;
      };
      $norm=static fn(string $value): string => rtrim((string)preg_replace("/[#?].*$/","",trim($value)),"/")."/";
      $titles=$texts("/html/head/title");
      $canon=$attrs("//link[contains(concat(\" \", normalize-space(translate(@rel,\"ABCDEFGHIJKLMNOPQRSTUVWXYZ\",\"abcdefghijklmnopqrstuvwxyz\")), \" \"), \" canonical \")]","href");
      $og=$attrs("//meta[translate(@property,\"ABCDEFGHIJKLMNOPQRSTUVWXYZ\",\"abcdefghijklmnopqrstuvwxyz\")=\"og:url\"]","content");
      $runtime=$attrs("//meta[translate(@name,\"ABCDEFGHIJKLMNOPQRSTUVWXYZ\",\"abcdefghijklmnopqrstuvwxyz\")=\"nvx-governed-blog-runtime-contract\"]","content");
      $h1Nodes=$xp->query("//h1");
      $h1=[]; $h1Ids=[];
      foreach ($h1Nodes ?: [] as $node) {
        $h1[]=trim(preg_replace("/\\s+/u"," ",html_entity_decode($node->textContent ?? "",ENT_QUOTES|ENT_HTML5,"UTF-8")));
        $h1Ids[]=$node instanceof DOMElement ? trim($node->getAttribute("id")) : "";
      }
      $issues=[];
      if (count($titles)!==1 || ($titles[0] ?? "")!==$expectedTitle) $issues[]="title:".($titles[0] ?? "missing").":count=".count($titles);
      if (count($canon)!==1 || $norm($canon[0] ?? "")!==$norm($expectedUrl)) $issues[]="canonical:".($canon[0] ?? "missing").":count=".count($canon);
      if (count($og)!==1 || $norm($og[0] ?? "")!==$norm($expectedUrl)) $issues[]="og_url:".($og[0] ?? "missing").":count=".count($og);
      if (count($runtime)!==1 || ($runtime[0] ?? "")!==$expectedContract) $issues[]="runtime_contract:".($runtime[0] ?? "missing").":count=".count($runtime);
      if (count($h1)!==1 || ($h1[0] ?? "")!==$expectedH1 || !str_contains($h1Ids[0] ?? "","3334")) $issues[]="h1:".($h1[0] ?? "missing").":id=".($h1Ids[0] ?? "").":count=".count($h1);
      foreach ([$titles[0] ?? "",$canon[0] ?? "",$og[0] ?? "",$h1Ids[0] ?? ""] as $criticalValue) {
        if (str_contains($criticalValue,$neighbourSlug) || str_contains($criticalValue,"3310")) { $issues[]="neighbouring_post_leak"; break; }
      }
      echo json_encode([
        "title"=>$titles[0] ?? "",
        "canonical"=>$canon[0] ?? "",
        "canonical_count"=>count($canon),
        "og_url"=>$og[0] ?? "",
        "og_url_count"=>count($og),
        "h1"=>$h1[0] ?? "",
        "h1_id"=>$h1Ids[0] ?? "",
        "runtime_contract"=>$runtime[0] ?? "",
        "issues"=>$issues,
      ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),"\n";
      exit($issues ? 1 : 0);
    ' "$html" "$matrix_url" "$expected_matrix_title" "$expected_matrix_h1" "$expected_runtime_contract" "$neighbouring_slug" 2>&1)"
    matrix_rc=$?
    set -e
    if [[ "$matrix_rc" -eq 0 ]]; then
      echo "PASS GOVERNED_BLOG_MATRIX_RUNTIME url=$url detail=$matrix_result"
      matrix_contract_pass=$((matrix_contract_pass + 1))
    else
      echo "FAIL GOVERNED_BLOG_MATRIX_RUNTIME url=$url detail=$matrix_result" >&2
      matrix_contract_fail=$((matrix_contract_fail + 1))
    fi
  fi
done < "$urls"

printf 'DOCUMENT_HEAD_TITLE_URLS=%s\n' "$((pass + fail))"
printf 'DOCUMENT_HEAD_TITLE_PASS=%s\n' "$pass"
printf 'DOCUMENT_HEAD_TITLE_FAIL=%s\n' "$fail"
printf 'GOVERNED_BLOG_MATRIX_RUNTIME_PASS=%s\n' "$matrix_contract_pass"
printf 'GOVERNED_BLOG_MATRIX_RUNTIME_FAIL=%s\n' "$matrix_contract_fail"
if [[ "$fail" -ne 0 || "$matrix_contract_fail" -ne 0 || "$matrix_contract_pass" -ne 1 ]]; then
  echo 'DOCUMENT_HEAD_TITLE_AUDIT=FAIL' >&2
  exit 1
fi
echo 'DOCUMENT_HEAD_TITLE_AUDIT=PASS'

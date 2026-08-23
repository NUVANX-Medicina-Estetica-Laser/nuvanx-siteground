#!/usr/bin/env bash
set -Eeuo pipefail

: "${PROD_ROOT:?Missing PROD_ROOT}"
BASE_URL="${BASE_URL:-https://nuvanx.com}"
BASE_URL="${BASE_URL%/}"
cd "$PROD_ROOT"

printf '%s\n' '=== NUVANX TITLE EMITTER DIAGNOSTIC ==='
printf 'PROD_ROOT=%s\n' "$PROD_ROOT"
printf 'BASE_URL=%s\n' "$BASE_URL"
printf 'DEPLOY_SHA=%s\n' "$(tr -d '\r\n[:space:]' < wp-content/themes/nuvanx-medical/.nvx-deploy-sha)"
printf 'ACTIVE_THEME=%s\n' "$(wp theme list --status=active --field=name)"
printf 'TITLE_TAG_SUPPORT='; wp eval 'echo current_theme_supports("title-tag") ? "yes\n" : "no\n";'
printf 'CORE_TITLE_CALLBACK_PRIORITY='; wp eval '$p=has_action("wp_head","_wp_render_title_tag"); echo false === $p ? "absent\n" : $p."\n";'

printf '%s\n' '--- RAW TITLES: HOME ---'
curl -fsSL --max-time 30 -A 'NUVANX-Title-Diagnostic/1.0' "$BASE_URL/" | php -r '$h=stream_get_contents(STDIN); preg_match_all("~<title\\b[^>]*>.*?</title>~is",$h,$m); echo "count=".count($m[0])."\n"; foreach($m[0] as $i=>$t){echo ($i+1)."=".trim(preg_replace("~\\s+~u"," ",$t))."\n";}'

printf '%s\n' '--- RAW TITLES: SIGNATURE HUB ---'
curl -fsSL --max-time 30 -A 'NUVANX-Title-Diagnostic/1.0' "$BASE_URL/remodelacion-corporal-laser-madrid/" | php -r '$h=stream_get_contents(STDIN); preg_match_all("~<title\\b[^>]*>.*?</title>~is",$h,$m); echo "count=".count($m[0])."\n"; foreach($m[0] as $i=>$t){echo ($i+1)."=".trim(preg_replace("~\\s+~u"," ",$t))."\n";}'

printf '%s\n' '--- ACTIVE PLUGINS ---'
wp plugin list --status=active --fields=name,status,version --format=json

printf '%s\n' '--- MU PLUGINS ---'
wp plugin list --status=must-use --fields=name,status,version --format=json

printf '%s\n' '--- TITLE/HEAD HOOK CALLBACKS ---'
wp eval '
function nvx_diag_callable_label($cb) {
    if (is_string($cb)) return $cb;
    if (is_array($cb) && count($cb) >= 2) {
        $owner = is_object($cb[0]) ? get_class($cb[0]) : (string) $cb[0];
        return $owner . "::" . (string) $cb[1];
    }
    if ($cb instanceof Closure) {
        $r = new ReflectionFunction($cb);
        return "Closure@" . $r->getFileName() . ":" . $r->getStartLine();
    }
    if (is_object($cb) && method_exists($cb, "__invoke")) return get_class($cb) . "::__invoke";
    return gettype($cb);
}
global $wp_filter;
$hooks = ["wp_head","pre_get_document_title","document_title_parts","wpseo_title","wpseo_frontend_presentation"];
foreach ($hooks as $hook) {
    echo "HOOK=" . $hook . "\n";
    if (!isset($wp_filter[$hook]) || !($wp_filter[$hook] instanceof WP_Hook)) {
        echo "  (none)\n";
        continue;
    }
    foreach ($wp_filter[$hook]->callbacks as $priority => $callbacks) {
        foreach ($callbacks as $entry) {
            $label = nvx_diag_callable_label($entry["function"] ?? null);
            if ($hook !== "wp_head" || preg_match("/(title|seo|yoast|head|document|wp_render)/i", $label)) {
                echo "  priority=" . $priority . " callback=" . $label . "\n";
            }
        }
    }
}
'

printf '%s\n' '--- ALL WP_HEAD CALLBACKS (ORDERED) ---'
wp eval '
function nvx_diag_all_label($cb) {
    if (is_string($cb)) return $cb;
    if (is_array($cb) && count($cb) >= 2) return (is_object($cb[0]) ? get_class($cb[0]) : (string)$cb[0]) . "::" . (string)$cb[1];
    if ($cb instanceof Closure) { $r=new ReflectionFunction($cb); return "Closure@".$r->getFileName().":".$r->getStartLine(); }
    if (is_object($cb) && method_exists($cb,"__invoke")) return get_class($cb)."::__invoke";
    return gettype($cb);
}
global $wp_filter;
if (isset($wp_filter["wp_head"]) && $wp_filter["wp_head"] instanceof WP_Hook) {
    foreach ($wp_filter["wp_head"]->callbacks as $priority => $callbacks) {
        foreach ($callbacks as $entry) echo "priority=".$priority." callback=".nvx_diag_all_label($entry["function"] ?? null)."\n";
    }
}
'

printf '%s\n' '=== END TITLE EMITTER DIAGNOSTIC ==='

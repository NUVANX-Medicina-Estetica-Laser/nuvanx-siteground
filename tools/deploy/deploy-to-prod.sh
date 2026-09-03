#!/usr/bin/env bash
# MUTATING: deploy an already-accepted nuvanx-medical candidate to production.
# Requires --confirm or NUVANX_CONFIRM=yes.
#
# Safety model:
# - exact production identity and exact 40-char candidate SHA
# - source is staged and validated away from the live theme
# - mandatory SQL + theme backup before mutation
# - no production MU-plugin mutation; legacy ownership must already be clean
# - directory cutover avoids rsyncing partial files into the live theme
# - exact .nvx-deploy-sha marker is part of the staged release
# - shared content migration + divergence audit run inside the same transaction
# - any post-cutover failure restores the previous live theme AND database
# - SiteGround dynamic-cache purge restores the original Speed Optimizer state
set -Eeuo pipefail

PROD_ROOT=""
SOURCE_THEME=""
SHA=""
CONFIRM=0

usage() {
  cat >&2 <<'EOF'
Usage:
  deploy-to-prod.sh \
    --prod-root /home/customer/www/nuvanx.com/public_html \
    --source-theme /absolute/path/to/accepted/theme \
    --sha <full-lowercase-40-char-commit-sha> \
    --confirm
EOF
}

require_confirm() {
  [[ "$CONFIRM" -eq 1 || "${NUVANX_CONFIRM:-}" == "yes" ]] || {
    echo "Refusing to run without --confirm or NUVANX_CONFIRM=yes" >&2
    exit 1
  }
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --prod-root) PROD_ROOT="$2"; shift 2 ;;
    --source-theme) SOURCE_THEME="$2"; shift 2 ;;
    --sha) SHA="$2"; shift 2 ;;
    --confirm) CONFIRM=1; shift ;;
    *) usage; echo "Unknown arg: $1" >&2; exit 2 ;;
  esac
done

[[ -n "$PROD_ROOT" && -n "$SOURCE_THEME" && -n "$SHA" ]] || { usage; exit 2; }
[[ "$SHA" =~ ^[0-9a-f]{40}$ ]] || { echo "ERROR: SHA must be a full lowercase 40-character commit SHA" >&2; exit 2; }
[[ "$PROD_ROOT" == '/home/customer/www/nuvanx.com/public_html' ]] || {
  echo "ERROR: refusing unexpected production root: $PROD_ROOT" >&2
  exit 1
}

command -v wp >/dev/null 2>&1 || { echo "wp-cli required" >&2; exit 2; }
command -v rsync >/dev/null 2>&1 || { echo "rsync required" >&2; exit 2; }
command -v php >/dev/null 2>&1 || { echo "php required" >&2; exit 2; }
command -v tar >/dev/null 2>&1 || { echo "tar required" >&2; exit 2; }
require_confirm

DEPLOY_RUN_ID="${GITHUB_RUN_ID:-}"
[[ "$DEPLOY_RUN_ID" =~ ^[0-9]+$ ]] || {
  echo 'ERROR: GITHUB_RUN_ID must be a numeric GitHub Actions run ID for production deployment' >&2
  exit 2
}

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd -P)"
MIGRATION_SCRIPT=""
AUDIT_SCRIPT=""
BLOG_HYGIENE_SCRIPT=""
CONTENT_NORMALIZER_SCRIPT=""
ROBOTS_RECONCILIATION_SCRIPT=""
INDEXABLES_RECONCILIATION_SCRIPT=""
YOAST_INDEXABLE_REBUILD_SCRIPT=""
SITEMAP_SELECTION_AUDIT_SCRIPT=""
SITEMAP_CACHE_INVALIDATION_SCRIPT=""
for candidate_dir in "$SCRIPT_DIR/tools/migrations" "$SCRIPT_DIR/../migrations"; do
  if [[ -f "$candidate_dir/content-hygiene-shared.php" && -f "$candidate_dir/audit-content-divergence.php" && -f "$candidate_dir/reconcile-publication-robots.php" && -f "$candidate_dir/reconcile-publication-indexables.php" && -f "$candidate_dir/run-yoast-indexable-rebuild.php" && -f "$candidate_dir/audit-publication-sitemap-selection.php" && -f "$candidate_dir/invalidate-publication-sitemap-cache.php" ]]; then
    MIGRATION_SCRIPT="$candidate_dir/content-hygiene-shared.php"
    AUDIT_SCRIPT="$candidate_dir/audit-content-divergence.php"
    BLOG_HYGIENE_SCRIPT="$candidate_dir/governed-blog-markdown-hygiene.php"
    CONTENT_NORMALIZER_SCRIPT="$candidate_dir/content-normalizer.php"
    ROBOTS_RECONCILIATION_SCRIPT="$candidate_dir/reconcile-publication-robots.php"
    INDEXABLES_RECONCILIATION_SCRIPT="$candidate_dir/reconcile-publication-indexables.php"
    YOAST_INDEXABLE_REBUILD_SCRIPT="$candidate_dir/run-yoast-indexable-rebuild.php"
    SITEMAP_SELECTION_AUDIT_SCRIPT="$candidate_dir/audit-publication-sitemap-selection.php"
    SITEMAP_CACHE_INVALIDATION_SCRIPT="$candidate_dir/invalidate-publication-sitemap-cache.php"
    RULES_LIB="$candidate_dir/../../lib/nvx-content-hygiene-rules.php"
    break
  fi
done
[[ -f "$MIGRATION_SCRIPT" ]] || { echo "ERROR: shared content migration missing under $SCRIPT_DIR/tools/migrations or $SCRIPT_DIR/../migrations" >&2; exit 1; }
[[ -f "$AUDIT_SCRIPT" ]] || { echo "ERROR: content divergence audit missing under $SCRIPT_DIR/tools/migrations or $SCRIPT_DIR/../migrations" >&2; exit 1; }
[[ -f "$BLOG_HYGIENE_SCRIPT" ]] || { echo "ERROR: governed blog markdown hygiene missing under $SCRIPT_DIR/tools/migrations or $SCRIPT_DIR/../migrations" >&2; exit 1; }
[[ -f "$CONTENT_NORMALIZER_SCRIPT" ]] || { echo "ERROR: content normalizer missing under $SCRIPT_DIR/tools/migrations or $SCRIPT_DIR/../migrations" >&2; exit 1; }
[[ -f "$ROBOTS_RECONCILIATION_SCRIPT" ]] || { echo "ERROR: publication robots reconciliation missing under $SCRIPT_DIR/tools/migrations or $SCRIPT_DIR/../migrations" >&2; exit 1; }
[[ -f "$INDEXABLES_RECONCILIATION_SCRIPT" ]] || { echo "ERROR: publication indexables reconciliation missing under $SCRIPT_DIR/tools/migrations or $SCRIPT_DIR/../migrations" >&2; exit 1; }
[[ -f "$YOAST_INDEXABLE_REBUILD_SCRIPT" ]] || { echo "ERROR: Yoast indexable rebuild runner missing under $SCRIPT_DIR/tools/migrations or $SCRIPT_DIR/../migrations" >&2; exit 1; }
[[ -f "$SITEMAP_SELECTION_AUDIT_SCRIPT" ]] || { echo "ERROR: sitemap selection audit missing under $SCRIPT_DIR/tools/migrations or $SCRIPT_DIR/../migrations" >&2; exit 1; }
[[ -f "$SITEMAP_CACHE_INVALIDATION_SCRIPT" ]] || { echo "ERROR: sitemap cache invalidation missing under $SCRIPT_DIR/tools/migrations or $SCRIPT_DIR/../migrations" >&2; exit 1; }
[[ -f "$RULES_LIB" ]] || { echo "ERROR: shared content hygiene rules library missing at $RULES_LIB" >&2; exit 1; }

PROD_URL='https://nuvanx.com'
THEMES_ROOT="$PROD_ROOT/wp-content/themes"
LIVE_THEME="$THEMES_ROOT/nuvanx-medical"
[[ -d "$LIVE_THEME" ]] || { echo "ERROR: production theme missing at $LIVE_THEME" >&2; exit 1; }
[[ -d "$SOURCE_THEME" ]] || { echo "ERROR: source theme missing at $SOURCE_THEME" >&2; exit 1; }

live_real="$(cd "$LIVE_THEME" && pwd -P)"
source_real="$(cd "$SOURCE_THEME" && pwd -P)"
[[ "$live_real" != "$source_real" ]] || { echo "ERROR: source theme is the live production theme" >&2; exit 1; }

RUN_TOKEN="${NVX_RUN_TOKEN:-$(date +%Y%m%d-%H%M%S)-$$}"
[[ "$RUN_TOKEN" =~ ^[A-Za-z0-9._-]+$ ]] || { echo "ERROR: invalid NVX_RUN_TOKEN" >&2; exit 2; }
RELEASE_ROOT="$THEMES_ROOT/.nvx-prod-release-${SHA}-${RUN_TOKEN}"
STAGED_THEME="$RELEASE_ROOT/nuvanx-medical"
PREVIOUS_THEME="$THEMES_ROOT/.nvx-prod-previous-${RUN_TOKEN}"
FAILED_THEME="$THEMES_ROOT/.nvx-prod-failed-${RUN_TOKEN}"
PROD_PARENT="$(dirname "$PROD_ROOT")"
BACKUP_ROOT="$PROD_PARENT/.nvx-backups"
BACKUP_DIR="$BACKUP_ROOT/pre-prod-${RUN_TOKEN}-${SHA:0:12}"
MIGRATION_LOG="$BACKUP_DIR/migration-production.log"
AUDIT_LOG="$BACKUP_DIR/migration-audit-production.log"
ROBOTS_LOG="$BACKUP_DIR/migration-robots-production.log"
MIGRATION_WRITE_MARKER="$BACKUP_DIR/.migration-write-marker"
SWAPPED=0
PREVIOUS_MOVED=0
ROLLBACK_OK=1

cleanup_uncommitted_release() {
  if [[ "$SWAPPED" -eq 0 ]]; then
    rm -rf "$RELEASE_ROOT" 2>/dev/null || true
    if [[ "$PREVIOUS_MOVED" -eq 1 && ! -d "$LIVE_THEME" && -d "$PREVIOUS_THEME" ]]; then
      mv "$PREVIOUS_THEME" "$LIVE_THEME" 2>/dev/null || true
    fi
  else
    rm -rf "$RELEASE_ROOT" 2>/dev/null || true
  fi
}
trap cleanup_uncommitted_release EXIT

purge_siteground_dynamic_cache() {
  local plugin='sg-cachepress'
  local activated_temporarily=0
  local purge_rc=0
  local restore_rc=0

  cd "$PROD_ROOT"

  if wp help sg >/dev/null 2>&1; then
    wp sg purge
    echo 'SITEGROUND_DYNAMIC_PURGE=PASS mode=existing-command'
    return 0
  fi

  if ! wp plugin is-installed "$plugin" >/dev/null 2>&1; then
    echo 'SITEGROUND_DYNAMIC_PURGE=SKIPPED reason=sg-command-and-plugin-unavailable'
    return 0
  fi

  if ! wp plugin is-active "$plugin" >/dev/null 2>&1; then
    wp plugin activate "$plugin" --quiet
    activated_temporarily=1
  fi

  if wp help sg >/dev/null 2>&1; then
    wp sg purge || purge_rc=$?
  else
    echo "ERROR: SiteGround command unavailable after transient Speed Optimizer activation" >&2
    purge_rc=1
  fi

  if [[ "$activated_temporarily" -eq 1 ]]; then
    wp plugin deactivate "$plugin" --quiet || restore_rc=$?
    if wp plugin is-active "$plugin" >/dev/null 2>&1; then
      echo "ERROR: Speed Optimizer remained active after transient cache purge" >&2
      restore_rc=10
    fi
  fi

  if [[ "$restore_rc" -ne 0 ]]; then
    return "$restore_rc"
  fi
  if [[ "$purge_rc" -ne 0 ]]; then
    return "$purge_rc"
  fi

  if [[ "$activated_temporarily" -eq 1 ]]; then
    echo 'SITEGROUND_DYNAMIC_PURGE=PASS mode=transient-plugin-activation restored=inactive'
  else
    echo 'SITEGROUND_DYNAMIC_PURGE=PASS mode=plugin-already-active'
  fi
}

echo "== Guard production identity =="
(
  cd "$PROD_ROOT"
  db="$(wp config get DB_NAME)"
  siteurl="$(wp option get siteurl)"
  home="$(wp option get home)"
  blog_public="$(wp option get blog_public)"
  theme="$(wp theme list --status=active --field=name)"
  echo "prod db=$db siteurl=$siteurl home=$home blog_public=$blog_public theme=$theme"
  [[ "$db" == 'db0ecrycwv2tgb' ]] || { echo "ERROR: unexpected production DB=$db" >&2; exit 1; }
  [[ "$siteurl" == "$PROD_URL" ]] || { echo "ERROR: unexpected prod siteurl=$siteurl" >&2; exit 1; }
  [[ "$home" == "$PROD_URL" ]] || { echo "ERROR: unexpected prod home=$home" >&2; exit 1; }
  [[ "$blog_public" == '1' ]] || { echo "ERROR: production blog_public=$blog_public" >&2; exit 1; }
  [[ "$theme" == 'nuvanx-medical' ]] || { echo "ERROR: active theme is $theme" >&2; exit 1; }
  if ! wp config has NVX_HUBSPOT_PORTAL_ID 2>/dev/null; then
    wp config set NVX_HUBSPOT_PORTAL_ID '147416356'
  fi
  if ! wp config has NVX_HUBSPOT_VALORACION_FORM_ID 2>/dev/null; then
    wp config set NVX_HUBSPOT_VALORACION_FORM_ID '5042522a-0bc5-4381-ac3e-5aee8649b69c'
  fi
)

for legacy_mu in \
  nuvanx-valoracion-native-hubspot-form.php \
  nuvanx-contacto-hubspot-form.php \
  nvx-disable-public-facebook-pixel.php \
  nuvanx-google-attribution.php
do
  [[ ! -e "$PROD_ROOT/wp-content/mu-plugins/$legacy_mu" ]] || {
    echo "ERROR: legacy production MU plugin still present: $legacy_mu" >&2
    exit 1
  }
done
[[ ! -d "$PROD_ROOT/wp-content/mu-plugins/nuvanx-google-attribution" ]] || {
  echo "ERROR: legacy production attribution MU package still present" >&2
  exit 1
}

echo "== Guard obsolete production redirect drift =="
obsolete_marker='# NVX_REDIRECT_3334_TO_3310'
obsolete_redirect='Redirect 301 /matriz-diagnostico-facial-estructura-piel-musculo-grasa/ https://nuvanx.com/tratamientos-faciales-sin-cirugia-guia-medica-diagnostico/'
if [[ -f "$PROD_ROOT/.htaccess" ]] && {
  grep -Fqx "$obsolete_marker" "$PROD_ROOT/.htaccess" ||
  grep -Fqx "$obsolete_redirect" "$PROD_ROOT/.htaccess";
}; then
  echo "ERROR: obsolete matrix 3334 -> 3310 redirect drift is present in production .htaccess" >&2
  echo "PRODUCTION_HTACCESS_MATRIX_REDIRECT_GUARD=FAIL" >&2
  exit 1
fi
echo 'PRODUCTION_HTACCESS_MATRIX_REDIRECT_GUARD=PASS'

echo "== Stage accepted theme away from live production =="
[[ ! -e "$RELEASE_ROOT" ]]
[[ ! -e "$PREVIOUS_THEME" ]]
[[ ! -e "$FAILED_THEME" ]]
mkdir -p "$STAGED_THEME"
rsync -a --delete \
  --exclude='.git' --exclude='php_errorlog' --exclude='*.log' \
  --exclude='backups-nuvanx' --exclude='quarantine' \
  --exclude='_archive*' --exclude='_disabled*' --exclude='*.bak*' \
  "$SOURCE_THEME/" "$STAGED_THEME/"

[[ -f "$STAGED_THEME/tools/compile-theme-css.php" ]] || { echo 'ERROR: staged release missing canonical CSS compiler' >&2; exit 1; }
echo "== Materialize exact production CSS distribution =="
SOURCE_DATE_EPOCH=0 php "$STAGED_THEME/tools/compile-theme-css.php"
[[ -s "$STAGED_THEME/dist/manifest.json" ]] || { echo 'ERROR: staged release missing compiled CSS manifest' >&2; exit 1; }
echo 'PRODUCTION_CSS_RELEASE=PASS source=exact-release runtime=compiled-dist'

printf '%s\n' "$SHA" > "$STAGED_THEME/.nvx-deploy-sha"
[[ "$(tr -d '\r\n' < "$STAGED_THEME/.nvx-deploy-sha")" == "$SHA" ]]

DEPLOY_TIMESTAMP="$(date -u +'%Y-%m-%dT%H:%M:%SZ')"
RELEASE_ID="${RELEASE_ID:-${SHA:0:12}}"
[[ "$RELEASE_ID" =~ ^[A-Za-z0-9_-]+$ ]] || {
  echo 'ERROR: RELEASE_ID contains unsupported characters' >&2
  exit 2
}
cat > "$STAGED_THEME/.nvx-deploy-stamp.json" <<STAMP
{
  "DEPLOY_SHA": "$SHA",
  "DEPLOY_RUN_ID": "$DEPLOY_RUN_ID",
  "DEPLOY_TIMESTAMP": "$DEPLOY_TIMESTAMP",
  "RELEASE_ID": "$RELEASE_ID"
}
STAMP
[[ -f "$STAGED_THEME/.nvx-deploy-stamp.json" ]]

for required in \
  tools/compile-theme-css.php \
  dist/manifest.json \
  assets/css/nvx-fonts.css \
  assets/css/nvx-tokens.css \
  assets/css/nvx-base.css \
  assets/css/nvx-site-layout.css \
  assets/css/nvx-components.css \
  assets/css/nvx-patterns-editorial.css \
  assets/css/nvx-header.css \
  assets/css/nvx-footer.css \
  assets/css/nvx-posts.css \
  inc/nvx-blog-system.php \
  functions.php
do
  [[ -f "$STAGED_THEME/$required" ]] || { echo "ERROR: staged release missing $required" >&2; exit 1; }
done
grep -Fq 'nvx-patterns-editorial.css' "$STAGED_THEME/functions.php"
find "$STAGED_THEME" -path '*/vendor' -prune -o -name '*.php' -type f -print0 | xargs -0 -n1 php -l >/dev/null
find "$STAGED_THEME/assets/css" -maxdepth 1 -type f -name 'nvx-*.min.css' -delete 2>/dev/null || true

echo "== Mandatory pre-deploy rollback snapshot =="
umask 077
mkdir -p "$BACKUP_DIR"
cat > "$BACKUP_DIR/.htaccess" <<'HTACCESS'
<IfModule mod_authz_core.c>
  Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
  <IfModule mod_access_compat.c>
    Deny from all
  </IfModule>
</IfModule>
HTACCESS
rm -f "$MIGRATION_WRITE_MARKER" 2>/dev/null || true
(
  cd "$PROD_ROOT"
  wp db export "$BACKUP_DIR/db.sql" --quiet
)
tar -czf "$BACKUP_DIR/theme.tgz" -C "$PROD_ROOT" wp-content/themes/nuvanx-medical
if [[ -d "$PROD_ROOT/wp-content/mu-plugins" ]]; then
  tar -czf "$BACKUP_DIR/mu-plugins.tgz" -C "$PROD_ROOT" wp-content/mu-plugins
fi
[[ -s "$BACKUP_DIR/db.sql" ]]
[[ -s "$BACKUP_DIR/theme.tgz" ]]
if [[ -f "$LIVE_THEME/.nvx-deploy-sha" ]]; then
  tr -d '\r\n' < "$LIVE_THEME/.nvx-deploy-sha" > "$BACKUP_DIR/previous-sha.txt"
else
  : > "$BACKUP_DIR/previous-sha.txt"
fi

echo "ROLLBACK_SNAPSHOT=PASS path=$BACKUP_DIR"

ROLLBACK_IN_PROGRESS=0

rollback_on_int() { rollback_after_swap 130; }
rollback_on_term() { rollback_after_swap 143; }
rollback_on_hup() { rollback_after_swap 129; }
rollback_after_swap() {
  local rc="${1:-$?}"
  local rollback_ok=1
  local identity_rc=0
  ROLLBACK_OK=0
  trap - ERR INT TERM HUP
  if [[ "$ROLLBACK_IN_PROGRESS" -eq 1 ]]; then return; fi
  ROLLBACK_IN_PROGRESS=1
  set +e

  if [[ "$PREVIOUS_MOVED" -eq 1 && "$SWAPPED" -eq 0 ]]; then
    echo "ROLLBACK_TRIGGERED rc=$rc recovering from incomplete cutover" >&2
    if [[ -d "$LIVE_THEME" && ! -d "$PREVIOUS_THEME" ]]; then
      PREVIOUS_MOVED=0
    elif [[ -d "$PREVIOUS_THEME" ]]; then
      if [[ -d "$LIVE_THEME" ]]; then
        rm -rf "$FAILED_THEME" 2>/dev/null || true
        mv "$LIVE_THEME" "$FAILED_THEME" || rollback_ok=0
      fi
      if [[ "$rollback_ok" -eq 1 ]] && mv "$PREVIOUS_THEME" "$LIVE_THEME"; then
        PREVIOUS_MOVED=0
      else
        echo "ERROR: failed to restore previous theme during incomplete cutover" >&2
        rollback_ok=0
      fi
    else
      echo "ERROR: incomplete cutover has neither a recoverable previous theme nor an intact live theme" >&2
      rollback_ok=0
    fi
  fi

  if [[ "$SWAPPED" -eq 1 ]]; then
    echo "ROLLBACK_TRIGGERED rc=$rc previous=$PREVIOUS_THEME backup=$BACKUP_DIR" >&2
    rm -rf "$FAILED_THEME"

    if [[ -d "$LIVE_THEME" ]]; then mv "$LIVE_THEME" "$FAILED_THEME" || rollback_ok=0; fi
    if [[ -d "$PREVIOUS_THEME" ]]; then
      mv "$PREVIOUS_THEME" "$LIVE_THEME" || rollback_ok=0
    else
      echo "ERROR: previous production theme is unavailable during rollback" >&2
      rollback_ok=0
    fi

    if [[ -f "$MIGRATION_WRITE_MARKER" ]]; then
      echo "ROLLBACK_DB=RESTORED reason=write-marker-detected-db-was-modified" >&2
      if [[ -s "$BACKUP_DIR/db.sql" ]]; then
        ( cd "$PROD_ROOT" || exit 1; wp db import "$BACKUP_DIR/db.sql" --allow-root ) || rollback_ok=0
      else
        echo "ERROR: production DB backup is unavailable during rollback" >&2
        rollback_ok=0
      fi
    else
      echo "ROLLBACK_DB=SKIPPED reason=no-write-marker-db-not-modified-or-migration-not-started" >&2
    fi

    (
      cd "$PROD_ROOT" || exit 1
      wp cache flush || true
      purge_siteground_dynamic_cache || true
      rm -rf wp-content/uploads/siteground-optimizer-assets/siteground-optimizer-combined-* 2>/dev/null || true
      rm -rf wp-content/cache/sgo-cache/* wp-content/cache/* 2>/dev/null || true
      wp eval 'if (function_exists("opcache_reset")) { opcache_reset(); }' || true
    )

    restored="$(cat "$BACKUP_DIR/previous-sha.txt" 2>/dev/null || true)"
    if [[ -z "$restored" ]]; then
      echo "INFO: No previous SHA marker recorded (first deploy or hand-placed theme)" >&2
    elif [[ ! -f "$LIVE_THEME/.nvx-deploy-sha" ]]; then
      echo "ERROR: previous SHA recorded but restored theme has no marker" >&2
      rollback_ok=0
    else
      actual="$(tr -d '\r\n' < "$LIVE_THEME/.nvx-deploy-sha")"
      if [[ "$actual" != "$restored" ]]; then
        echo "ERROR: rollback marker $actual != expected $restored" >&2
        rollback_ok=0
      fi
    fi

    (
      cd "$PROD_ROOT" || exit 1
      db="$(wp config get DB_NAME)"
      siteurl="$(wp option get siteurl)"
      home="$(wp option get home)"
      blog_public="$(wp option get blog_public)"
      theme="$(wp theme list --status=active --field=name)"
      [[ "$db" == 'db0ecrycwv2tgb' ]] || { echo "ERROR: DB identity corrupted after rollback db=$db" >&2; exit 1; }
      [[ "$siteurl" == "$PROD_URL" ]] || { echo "ERROR: siteurl corrupted after rollback siteurl=$siteurl" >&2; exit 1; }
      [[ "$home" == "$PROD_URL" ]] || { echo "ERROR: home corrupted after rollback home=$home" >&2; exit 1; }
      [[ "$blog_public" == '1' ]] || { echo "ERROR: blog_public corrupted after rollback blog_public=$blog_public" >&2; exit 1; }
      [[ "$theme" == 'nuvanx-medical' ]] || { echo "ERROR: active theme corrupted after rollback theme=$theme" >&2; exit 1; }
    ) || identity_rc=$?
    [[ "$identity_rc" -eq 0 ]] || rollback_ok=0

    if [[ "$rollback_ok" -eq 1 ]]; then
      echo "ROLLBACK_PRODUCTION=PASS scope=theme+db" >&2
      ROLLBACK_OK=1
    else
      echo "ROLLBACK_PRODUCTION=FAIL scope=theme+db" >&2
      ROLLBACK_OK=0
    fi
  fi

  if [[ "$rc" -eq 0 ]]; then rc=1; fi
  if [[ "$rollback_ok" -ne 1 ]]; then rc=2; fi
  if [[ "$SWAPPED" -eq 1 ]]; then
    if [[ "$rollback_ok" -eq 1 ]]; then
      rm -rf "$FAILED_THEME" 2>/dev/null || true
    else
      echo "INFO: Keeping failed theme at $FAILED_THEME for investigation" >&2
    fi
  fi

  SWAPPED=0
  exit "$rc"
}
trap rollback_after_swap ERR
trap rollback_on_int INT
trap rollback_on_term TERM
trap rollback_on_hup HUP

echo "== Pre-cutover content audit (read-only) =="
(
  trap - ERR
  cd "$PROD_ROOT"
  wp eval-file "$AUDIT_SCRIPT" --allow-root 2>&1 | tee "$BACKUP_DIR/pre-cutover-audit.log"
  grep -qE 'Status: (AUDIT_CLEAN|AUDIT_PENDING_MIGRABLE)' "$BACKUP_DIR/pre-cutover-audit.log"
)
echo 'PRE_CUTOVER_AUDIT=PASS'

echo "== Directory cutover =="
PREVIOUS_MOVED=1
mv "$LIVE_THEME" "$PREVIOUS_THEME"
mv "$STAGED_THEME" "$LIVE_THEME"
SWAPPED=1
PREVIOUS_MOVED=0

echo "== Verify exact production release on disk =="
(
  trap - ERR
  cd "$PROD_ROOT"
  [[ "$(tr -d '\r\n' < wp-content/themes/nuvanx-medical/.nvx-deploy-sha)" == "$SHA" ]]
  [[ -s wp-content/themes/nuvanx-medical/dist/manifest.json ]]
  php wp-content/themes/nuvanx-medical/tools/compile-theme-css.php --verify-only
  [[ "$(wp config get DB_NAME)" == 'db0ecrycwv2tgb' ]]
  [[ "$(wp option get home)" == "$PROD_URL" ]]
  [[ "$(wp option get siteurl)" == "$PROD_URL" ]]
  [[ "$(wp option get blog_public)" == '1' ]]
  [[ "$(wp theme list --status=active --field=name)" == 'nuvanx-medical' ]]
  echo 'PRODUCTION_CSS_RUNTIME=PASS source=live-theme runtime=compiled-dist fallback=not-required'
)

echo "== Run shared production content migration and divergence audit =="
(
  trap - ERR
  cd "$PROD_ROOT"
  MIGRATION_WRITE_MARKER="$MIGRATION_WRITE_MARKER" wp eval-file "$MIGRATION_SCRIPT" --allow-root 2>&1 | tee "$MIGRATION_LOG"
  grep -Fq 'Status: MIGRATION_OK' "$MIGRATION_LOG"
  MIGRATION_WRITE_MARKER="$MIGRATION_WRITE_MARKER" wp eval-file "$ROBOTS_RECONCILIATION_SCRIPT" --allow-root 2>&1 | tee "$ROBOTS_LOG"
  grep -Fq 'PUBLICATION_ROBOTS_RECONCILIATION=PASS' "$ROBOTS_LOG"
  NVX_ALLOW_STAGING_YOAST_INDEXABLE_REBUILD=1 wp eval-file "$YOAST_INDEXABLE_REBUILD_SCRIPT" --allow-root
  NVX_ALLOW_STAGING_YOAST_INDEXABLE_REBUILD=1 wp eval-file "$INDEXABLES_RECONCILIATION_SCRIPT" --allow-root
  wp eval-file "$SITEMAP_CACHE_INVALIDATION_SCRIPT" --allow-root
  wp eval-file "$SITEMAP_SELECTION_AUDIT_SCRIPT" --allow-root
  wp eval-file "$AUDIT_SCRIPT" --allow-root 2>&1 | tee "$AUDIT_LOG"
  grep -Fq 'Status: AUDIT_CLEAN' "$AUDIT_LOG"
)
echo 'PRODUCTION_CONTENT_MIGRATION=PASS audit=clean robots=reconciled'
echo 'SHARED_MIGRATION=PASS audit=clean'
echo 'PRODUCTION_ROBOTS_RECONCILIATION=PASS'

[[ "$(tr -d '\r\n' < "$LIVE_THEME/.nvx-deploy-sha")" == "$SHA" ]]

trap - ERR INT TERM HUP

echo "== Purge production caches =="
purge_rc=0
(
  trap - ERR
  cd "$PROD_ROOT"
  inner_rc=0
  wp cache flush || inner_rc=$?
  purge_siteground_dynamic_cache || inner_rc=$?
  rm -rf wp-content/uploads/siteground-optimizer-assets/siteground-optimizer-combined-* 2>/dev/null || inner_rc=$?
  rm -rf wp-content/cache/sgo-cache/* wp-content/cache/* 2>/dev/null || inner_rc=$?
  wp eval 'if (function_exists("opcache_reset")) { if ( ! opcache_reset() ) { echo "opcache_reset failed\n"; exit(1); } echo "opcache=ok\n"; }' || inner_rc=$?
  exit "$inner_rc"
) || purge_rc=$?

if [[ "$purge_rc" -eq 10 ]]; then
  echo "ERROR: Speed Optimizer plugin restoration failed - this changes production state" >&2
  exit 1
fi
[[ "$purge_rc" -eq 0 ]] || echo "WARN: production cache purge reported a non-fatal error rc=$purge_rc" >&2

trap - ERR INT TERM HUP
rm -f "$MIGRATION_WRITE_MARKER" 2>/dev/null || true
rm -rf "$PREVIOUS_THEME" "$RELEASE_ROOT"
SWAPPED=0
trap - EXIT

echo "DEPLOY_PRODUCTION_OK sha=$SHA backup=$BACKUP_DIR"

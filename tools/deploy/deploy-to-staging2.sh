#!/usr/bin/env bash
# MUTATING: deploy a checked-out nuvanx-medical theme snapshot to staging2 only.
# Intended for the protected manual GitHub Actions workflow or an authorized
# SiteGround operator. Never accepts a production root.
set -Eeuo pipefail

EXPECTED_ROOT='/home/customer/www/staging2.nuvanx.com/public_html'
EXPECTED_URL='https://staging2.nuvanx.com'
PROD_ROOT='/home/customer/www/nuvanx.com/public_html'
PROD_URL='https://nuvanx.com'
THEME_REL='wp-content/themes/nuvanx-medical'
WP_ROOT=''
SOURCE_THEME=''
DEPLOY_SHA=''
CONFIRM=0
BACKUP_DIR=''
MUTATION_STARTED=0
CONFIG_BACKUP=''
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MIGRATIONS_DIR=''
PUBLIC_INTEGRATION_CONFIG=''

if [[ -d "$SCRIPT_DIR/tools/migrations" ]]; then
  # Immutable GitHub Actions release layout: deploy script copied to release root.
  MIGRATIONS_DIR="$SCRIPT_DIR/tools/migrations"
elif [[ -d "$SCRIPT_DIR/../migrations" ]]; then
  # Repository/operator layout: tools/deploy/deploy-to-staging2.sh.
  MIGRATIONS_DIR="$(cd "$SCRIPT_DIR/../migrations" && pwd)"
fi

if [[ -f "$SCRIPT_DIR/lib/staging-public-integration-identities.json" ]]; then
  # Immutable GitHub Actions release layout: lib/ is copied beside this script.
  PUBLIC_INTEGRATION_CONFIG="$SCRIPT_DIR/lib/staging-public-integration-identities.json"
elif [[ -f "$SCRIPT_DIR/../../lib/staging-public-integration-identities.json" ]]; then
  # Repository/operator layout: tools/deploy/deploy-to-staging2.sh.
  PUBLIC_INTEGRATION_CONFIG="$(cd "$SCRIPT_DIR/../.." && pwd)/lib/staging-public-integration-identities.json"
fi

usage() {
  cat >&2 <<'EOF'
Usage:
  deploy-to-staging2.sh \
    --wp-root /home/customer/www/staging2.nuvanx.com/public_html \
    --source-theme /home/customer/www/staging2.nuvanx.com/public_html/wp-content/.nuvanx-deployments/<release>/theme \
    --sha <40-character-git-sha> \
    --confirm
EOF
}

fail() {
  echo "ERROR: $*" >&2
  exit 1
}

fail_config() {
  echo "FAIL_CONFIG: $*" >&2
  exit 78
}

provision_staging_hubspot_identity() {
  local portal=''
  local form=''
  local scope=''
  local classification=''
  local private_credentials=''
  local production_mutation=''

  [[ -n "$PUBLIC_INTEGRATION_CONFIG" && -f "$PUBLIC_INTEGRATION_CONFIG" ]] \
    || fail_config 'Staging public integration identity manifest is unavailable'

  scope="$(jq -r '.scope // empty' "$PUBLIC_INTEGRATION_CONFIG")"
  classification="$(jq -r '.classification // empty' "$PUBLIC_INTEGRATION_CONFIG")"
  portal="$(jq -r '.hubspot.portal_id // empty' "$PUBLIC_INTEGRATION_CONFIG")"
  form="$(jq -r '.hubspot.form_id // empty' "$PUBLIC_INTEGRATION_CONFIG")"
  private_credentials="$(jq -r 'if .guardrails | has("contains_private_credentials") then .guardrails.contains_private_credentials else true end' "$PUBLIC_INTEGRATION_CONFIG")"
  production_mutation="$(jq -r 'if .guardrails | has("production_mutation") then .guardrails.production_mutation else true end' "$PUBLIC_INTEGRATION_CONFIG")"

  [[ "$scope" == 'staging2' ]] || fail_config "HubSpot identity manifest has unexpected scope=$scope"
  [[ "$classification" == 'public_integration_identity' ]] || fail_config "HubSpot identity manifest classification is invalid: $classification"
  [[ "$private_credentials" == 'false' ]] || fail_config 'HubSpot identity manifest must not contain private credentials'
  [[ "$production_mutation" == 'false' ]] || fail_config 'HubSpot identity manifest must forbid Production mutation'
  [[ "$portal" =~ ^[0-9]{1,20}$ ]] || fail_config 'HubSpot portal identity in Staging manifest is malformed'
  [[ "$form" =~ ^[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[1-5][0-9A-Fa-f]{3}-[89AaBb][0-9A-Fa-f]{3}-[0-9A-Fa-f]{12}$ ]] \
    || fail_config 'HubSpot form identity in Staging manifest is malformed'

  (
    cd "$WP_ROOT"
    wp config set NVX_HUBSPOT_PORTAL_ID "$portal"
    wp config set NVX_HUBSPOT_VALORACION_FORM_ID "$form"
    php -l wp-config.php >/dev/null
    [[ "$(wp config get NVX_HUBSPOT_PORTAL_ID)" == "$portal" ]]
    [[ "$(wp config get NVX_HUBSPOT_VALORACION_FORM_ID)" == "$form" ]]
  )

  echo 'STAGING_HUBSPOT_PROVISION=PASS source=governed_public_manifest target=canonical_wp_config private_credentials=none production_mutation=none'
}

verify_staging_hubspot_embed_contract() {
  local secure_file="$SOURCE_THEME/inc/nvx-hubspot-secure-attribution.php"
  local modal_file="$SOURCE_THEME/inc/nvx-valoracion-modal.php"
  local portal=''
  local form=''
  local source=''

  # Source contract: account/form identity belongs to the validated resolver,
  # never to a theme-owned Production fallback in the modal or embed markup.
  [[ -f "$secure_file" ]] || fail 'HubSpot secure identity resolver is missing from the immutable theme payload'
  [[ -f "$modal_file" ]] || fail 'HubSpot modal source is missing from the immutable theme payload'
  grep -Fq 'function nvx_hubspot_secure_identity' "$secure_file" || fail 'HubSpot canonical identity resolver contract is missing'
  grep -Fq 'nvx_hubspot_secure_identity_configured' "$modal_file" || fail 'HubSpot modal does not enforce the canonical configured-identity contract'
  grep -Fq 'nvx_hubspot_secure_portal_id' "$modal_file" || fail 'HubSpot modal does not consume the canonical portal resolver'
  grep -Fq 'nvx_hubspot_secure_form_id' "$modal_file" || fail 'HubSpot modal does not consume the canonical form resolver'

  # Runtime precondition: Staging2 must provision one complete identity pair in
  # wp-config.php. wp config get intentionally inspects environment configuration
  # rather than loading the active theme, so a theme fallback cannot satisfy it.
  (
    cd "$WP_ROOT"
    portal="$(wp config get NVX_HUBSPOT_PORTAL_ID 2>/dev/null || true)"
    form="$(wp config get NVX_HUBSPOT_VALORACION_FORM_ID 2>/dev/null || true)"
    if [[ -n "$portal" || -n "$form" ]]; then
      source='canonical_wp_config'
    else
      portal="$(wp config get NVX_VALORACION_HS_FRAME_PORTAL_ID 2>/dev/null || true)"
      form="$(wp config get NVX_VALORACION_HS_FRAME_FORM_ID 2>/dev/null || true)"
      if [[ -n "$portal" || -n "$form" ]]; then
        source='legacy_wp_config'
      fi
    fi

    [[ -n "$source" ]] || fail_config 'Staging2 HubSpot portal/form identity is not provisioned in wp-config.php'
    [[ "$portal" =~ ^[0-9]{1,20}$ ]] || fail_config "Staging2 HubSpot portal identity is malformed source=$source"
    [[ "$form" =~ ^[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[1-5][0-9A-Fa-f]{3}-[89AaBb][0-9A-Fa-f]{3}-[0-9A-Fa-f]{12}$ ]] || fail_config "Staging2 HubSpot form identity is malformed source=$source"
    echo "STAGING_HUBSPOT_CONFIG=PASS source=$source runtime_fallback=disabled"
  )
}

purge_siteground_cache_if_available() {
  local plugin_slug='sg-cachepress'
  local was_active=0
  local purge_ok=0

  if wp plugin is-active "$plugin_slug" >/dev/null 2>&1; then
    was_active=1
    wp sg purge
    echo 'siteground_wp_cli_purge=PASS mode=already-active'
    return 0
  fi

  if ! wp plugin is-installed "$plugin_slug" >/dev/null 2>&1; then
    echo 'ERROR: SiteGround Speed Optimizer is not installed; cannot purge Dynamic Cache reproducibly.' >&2
    return 1
  fi

  # Loading the inactive plugin with --require exposes the command but does not
  # invalidate the host Dynamic Cache. SiteGround only performs the real purge
  # while Speed Optimizer is active. Activate it only for the purge, then return
  # staging to its original inactive state.
  wp plugin activate "$plugin_slug" --quiet
  if ! wp plugin is-active "$plugin_slug" >/dev/null 2>&1; then
    echo 'ERROR: Speed Optimizer did not become active for the transient purge.' >&2
    return 1
  fi

  if wp sg purge; then
    purge_ok=1
  fi

  wp plugin deactivate "$plugin_slug" --quiet || {
    echo 'ERROR: failed to restore Speed Optimizer to its original inactive state.' >&2
    return 1
  }

  if wp plugin is-active "$plugin_slug" >/dev/null 2>&1; then
    echo 'ERROR: Speed Optimizer remained active after transient purge.' >&2
    return 1
  fi

  [[ "$purge_ok" -eq 1 ]] || {
    echo 'ERROR: SiteGround Dynamic Cache purge command failed.' >&2
    return 1
  }

  [[ "$was_active" -eq 0 ]] || return 0
  echo 'siteground_wp_cli_purge=PASS mode=transient-activation-restored-inactive'
}

sync_publication_topology() {
  local snapshot report
  snapshot="$(mktemp)"
  report="$(mktemp)"

  cleanup_publication_sync() {
    rm -f "$snapshot" "$report"
  }
  trap cleanup_publication_sync RETURN

  echo '== Synchronize publication topology from production read-only source =='
  (
    cd "$PROD_ROOT"
    PUBLICATION_MANIFEST_FILE="$SOURCE_THEME/inc/data/publication-manifest.json" \
      wp eval-file "$MIGRATIONS_DIR/export-production-publication-snapshot.php" --allow-root > "$snapshot"
  )
  [[ -s "$snapshot" ]] || fail 'production publication snapshot is empty'
  jq -e '.schema == "nuvanx-production-publication-snapshot" and (.routes | type == "object")' "$snapshot" >/dev/null \
    || fail 'production publication snapshot is invalid'

  (
    cd "$WP_ROOT"
    PUBLICATION_SNAPSHOT_FILE="$snapshot" \
      wp eval-file "$MIGRATIONS_DIR/prepare-staging-publication-collisions.php" --allow-root >&2
    PUBLICATION_SNAPSHOT_FILE="$snapshot" \
      wp eval-file "$MIGRATIONS_DIR/sync-staging-publication-parity.php" --allow-root > "$report"
  )
  [[ -s "$report" ]] || fail 'staging publication parity report is empty'
  jq -e --argjson expected "$(jq '.routes | length' "$snapshot")" \
    '.schema == "nuvanx-staging-publication-parity" and .route_count == $expected' "$report" >/dev/null \
    || fail 'staging publication parity report does not match production snapshot'

  echo "STAGING_PUBLICATION_TOPOLOGY=PASS routes=$(jq -r '.route_count' "$report") created=$(jq -r '.created' "$report") updated=$(jq -r '.updated' "$report") drafted=$(jq -r '.drafted_surplus' "$report")"
  cleanup_publication_sync
  trap - RETURN
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --wp-root) WP_ROOT="${2:-}"; shift 2 ;;
    --source-theme) SOURCE_THEME="${2:-}"; shift 2 ;;
    --sha) DEPLOY_SHA="${2:-}"; shift 2 ;;
    --confirm) CONFIRM=1; shift ;;
    *) usage; fail "unknown argument: $1" ;;
  esac
done

[[ "$CONFIRM" -eq 1 || "${NUVANX_CONFIRM:-}" == 'yes' ]] || fail 'explicit confirmation is required'
[[ "$WP_ROOT" == "$EXPECTED_ROOT" ]] || fail "refusing unexpected WordPress root: $WP_ROOT"
[[ "$WP_ROOT" != "$PROD_ROOT" ]] || fail 'staging root must never equal production root'
[[ "$DEPLOY_SHA" =~ ^[0-9a-f]{40}$ ]] || fail 'SHA must contain 40 lowercase hexadecimal characters'
[[ -n "$SOURCE_THEME" ]] || fail 'source theme path is required'
[[ "$SOURCE_THEME" == "$WP_ROOT"/wp-content/.nuvanx-deployments/*/theme ]] || fail 'source theme must be inside the staging2 deployment area'
[[ -n "$MIGRATIONS_DIR" && -d "$MIGRATIONS_DIR" ]] || fail 'migration directory cannot be resolved from repository or immutable release layout'
[[ -n "$PUBLIC_INTEGRATION_CONFIG" && -f "$PUBLIC_INTEGRATION_CONFIG" ]] || fail_config 'public Staging integration identity manifest cannot be resolved from repository or immutable release layout'

for command_name in wp rsync tar php find mktemp sha256sum awk jq; do
  command -v "$command_name" >/dev/null 2>&1 || fail "required command is unavailable: $command_name"
done

[[ -d "$WP_ROOT" ]] || fail "WordPress root does not exist: $WP_ROOT"
[[ -f "$WP_ROOT/wp-config.php" ]] || fail 'wp-config.php not found in staging2 root'
[[ -d "$SOURCE_THEME" ]] || fail "source theme does not exist: $SOURCE_THEME"
[[ -f "$MIGRATIONS_DIR/ensure-governed-blog-parity.php" ]] || fail 'governed blog parity migration is missing from immutable release tooling'
for migration_file in export-production-publication-snapshot.php prepare-staging-publication-collisions.php sync-staging-publication-parity.php; do
  [[ -f "$MIGRATIONS_DIR/$migration_file" ]] || fail "publication parity migration is missing: $migration_file"
done

SOURCE_REQUIRED_FILES=(
  style.css
  functions.php
  header.php
  assets/css/nvx-fonts.css
  assets/css/nvx-tokens.css
  assets/css/nvx-base.css
  assets/css/nvx-site-layout.css
  assets/css/nvx-components.css
  assets/css/nvx-patterns-editorial.css
  assets/css/nvx-header.css
  assets/css/nvx-footer.css
  assets/css/nvx-accessibility-governance.css
  assets/js/nvx-runtime-governance.js
  assets/css/nvx-soluciones-medicas.css
  template-parts/content/nvx-soluciones-medicas.php
  templates/page-soluciones-medicas.php
  inc/nvx-blog-system.php
  inc/nvx-document-governance.php
  inc/nvx-page-hygiene.php
  inc/nvx-solutions-page.php
  inc/nvx-clinics-hub.php
  inc/nvx-hubspot-secure-attribution.php
  inc/nvx-valoracion-modal.php
  inc/data/publication-manifest.json
)

for required_file in "${SOURCE_REQUIRED_FILES[@]}"; do
  [[ -f "$SOURCE_THEME/$required_file" ]] || fail "source theme is missing $required_file"
done

LIVE_THEME="$WP_ROOT/$THEME_REL"
[[ -d "$LIVE_THEME" ]] || fail "live staging2 theme does not exist: $LIVE_THEME"

rollback() {
  local exit_code=$?
  trap - ERR
  if [[ "$MUTATION_STARTED" -eq 1 && -n "$BACKUP_DIR" && -f "$BACKUP_DIR/theme.tgz" ]]; then
    echo 'SAFETY_RESTORE: restoring the pre-deploy staging2 theme after rejected deployment' >&2
    rm -rf "$LIVE_THEME"
    tar -xzf "$BACKUP_DIR/theme.tgz" -C "$WP_ROOT"
    (
      cd "$WP_ROOT"
      wp cache flush || true
      purge_siteground_cache_if_available || true
    )
    echo "SAFETY_RESTORE_COMPLETE backup=$BACKUP_DIR" >&2
  fi
  if [[ -n "$CONFIG_BACKUP" && -f "$CONFIG_BACKUP" ]]; then
    cp -p "$CONFIG_BACKUP" "$WP_ROOT/wp-config.php"
    php -l "$WP_ROOT/wp-config.php" >/dev/null
    echo 'SAFETY_RESTORE_CONFIG=PASS' >&2
  fi
  exit "$exit_code"
}
trap rollback ERR

echo '== Guard staging2 identity =='
(
  cd "$WP_ROOT"
  siteurl="$(wp option get siteurl)"
  home="$(wp option get home)"
  blog_public="$(wp option get blog_public)"
  theme="$(wp theme list --status=active --field=name)"
  nvx_env="$(wp eval 'echo defined("NVX_ENV") ? NVX_ENV : "";')"
  wp_environment="$(wp eval 'echo function_exists("wp_get_environment_type") ? wp_get_environment_type() : "";')"

  echo "siteurl=$siteurl home=$home active_theme=$theme blog_public=$blog_public nvx_env=$nvx_env wp_environment=$wp_environment"

  [[ "$siteurl" == "$EXPECTED_URL" ]] || fail "unexpected siteurl: $siteurl"
  [[ "$home" == "$EXPECTED_URL" ]] || fail "unexpected home URL: $home"
  [[ "$theme" == 'nuvanx-medical' ]] || fail "unexpected active theme: $theme"
  [[ "$blog_public" == '0' ]] || fail "staging2 must have blog_public=0; got: $blog_public"
  [[ "$nvx_env" == 'staging' ]] || fail "staging2 must define NVX_ENV=staging; got: ${nvx_env:-undefined}"
  [[ "$wp_environment" == 'staging' ]] || fail "staging2 must report WP environment type staging; got: ${wp_environment:-undefined}"
)

echo '== Guard production read-only source identity =='
(
  cd "$PROD_ROOT"
  [[ "$(wp config get DB_NAME)" == 'db0ecrycwv2tgb' ]] || fail 'unexpected production DB identity while sourcing governed post'
  [[ "$(wp option get home)" == "$PROD_URL" ]] || fail 'unexpected production home while sourcing governed post'
  [[ "$(wp option get siteurl)" == "$PROD_URL" ]] || fail 'unexpected production siteurl while sourcing governed post'
  [[ "$(wp option get blog_public)" == '1' ]] || fail 'production source must remain public'
  [[ "$(wp theme list --status=active --field=name)" == 'nuvanx-medical' ]] || fail 'unexpected production active theme'
)

CONFIG_BACKUP="$(mktemp)"
cp -p "$WP_ROOT/wp-config.php" "$CONFIG_BACKUP"
echo '== Provision Staging2 HubSpot public identity from governed manifest =='
provision_staging_hubspot_identity

echo '== Verify Staging2 HubSpot fail-closed identity contract =='
verify_staging_hubspot_embed_contract

echo '== Validate source PHP =='
PHP_LINT_LOG="$(mktemp)"
if ! find "$SOURCE_THEME" -type f -name '*.php' -print0 | xargs -0 -n1 php -l >"$PHP_LINT_LOG" 2>&1; then
  cat "$PHP_LINT_LOG" >&2
  rm -f "$PHP_LINT_LOG"
  fail 'source theme PHP lint failed'
fi
php -l "$MIGRATIONS_DIR/ensure-governed-blog-parity.php" >/dev/null
php -l "$MIGRATIONS_DIR/export-production-publication-snapshot.php" >/dev/null
php -l "$MIGRATIONS_DIR/prepare-staging-publication-collisions.php" >/dev/null
php -l "$MIGRATIONS_DIR/sync-staging-publication-parity.php" >/dev/null
rm -f "$PHP_LINT_LOG"

DATE="$(date +%Y%m%d-%H%M%S)"
SHORT_SHA="${DEPLOY_SHA:0:12}"
BACKUP_DIR="$WP_ROOT/wp-content/backups-nuvanx/pre-staging2-${DATE}-${SHORT_SHA}"

echo "== Backup staging2 theme to $BACKUP_DIR =="
mkdir -p "$BACKUP_DIR"
tar -czf "$BACKUP_DIR/theme.tgz" -C "$WP_ROOT" "$THEME_REL"
printf '%s\n' "$DEPLOY_SHA" > "$BACKUP_DIR/intended-sha.txt"

MUTATION_STARTED=1

echo '== Synchronize theme to staging2 =='
rsync -a --delete \
  --exclude='.git' \
  --exclude='php_errorlog' \
  --exclude='*.log' \
  --exclude='backups-nuvanx' \
  --exclude='quarantine' \
  --exclude='_archive*' \
  --exclude='_disabled*' \
  --exclude='*.bak*' \
  "$SOURCE_THEME/" \
  "$LIVE_THEME/"

find "$LIVE_THEME/assets/css" -maxdepth 1 -type f -name 'nvx-*.min.css' -delete
printf '%s\n' "$DEPLOY_SHA" > "$LIVE_THEME/.nvx-deploy-sha"

echo '== Verify deployed files and marker =='
for required_file in "${SOURCE_REQUIRED_FILES[@]}"; do
  [[ -f "$LIVE_THEME/$required_file" ]] || fail "deployed theme is missing $required_file"
done
[[ "$(tr -d '\r\n' < "$LIVE_THEME/.nvx-deploy-sha")" == "$DEPLOY_SHA" ]] || fail 'deployed SHA marker does not match'
grep -Fq 'nvx-patterns-editorial.css' "$LIVE_THEME/functions.php" || fail 'functions.php does not enqueue the canonical editorial stylesheet'
grep -Fq 'nvx-document-governance.php' "$LIVE_THEME/functions.php" || fail 'functions.php does not load document governance'
grep -Fq 'nvx_document_governance_print_head_contract' "$LIVE_THEME/inc/nvx-document-governance.php" || fail 'document governance missing head contract emitter'
grep -Fq 'window.nvxValoracionModal' "$LIVE_THEME/inc/nvx-valoracion-modal.php" || fail 'valoracion modal boot config is missing'

sync_publication_topology

echo '== Synchronize governed matrix post identity from production read-only source =='
PROD_POST_JSON="$(mktemp)"
trap 'rm -f "$PROD_POST_JSON"' RETURN
(
  cd "$PROD_ROOT"
  wp post get 3334 --format=json > "$PROD_POST_JSON"
)
[[ -s "$PROD_POST_JSON" ]] || fail 'production governed post export is empty'
(
  cd "$WP_ROOT"
  PRODUCTION_POST_JSON_FILE="$PROD_POST_JSON" wp eval-file "$MIGRATIONS_DIR/ensure-governed-blog-parity.php" --allow-root
)
rm -f "$PROD_POST_JSON"
trap - RETURN

echo '== Purge staging2 caches =='
(
  cd "$WP_ROOT"
  wp cache flush
  purge_siteground_cache_if_available
  rm -rf wp-content/uploads/siteground-optimizer-assets/siteground-optimizer-combined-*
  rm -rf wp-content/cache/sgo-cache/*
  rm -rf wp-content/cache/*
  wp eval 'if (function_exists("opcache_reset")) { opcache_reset(); echo "opcache=ok\n"; }'
)

rm -f "$CONFIG_BACKUP"
CONFIG_BACKUP=''
trap - ERR
MUTATION_STARTED=0

echo "DEPLOY_STAGING2_OK sha=$DEPLOY_SHA backup=$BACKUP_DIR"

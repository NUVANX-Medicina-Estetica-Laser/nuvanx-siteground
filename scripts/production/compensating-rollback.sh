#!/usr/bin/env bash
set -Eeuo pipefail

: "${PROD_ROOT:?Missing PROD_ROOT}"
: "${SITEGROUND_CACHE_HELPER:?Missing SITEGROUND_CACHE_HELPER}"
BASE_URL="${BASE_URL:-https://nuvanx.com}"
BASE_URL="${BASE_URL%/}"
# PROD_DB_NAME is the canonical production DB identifier used as a boundary
# assertion (not a secret — no password). Default is the SiteGround DB name.
PROD_DB_NAME="${PROD_DB_NAME:-db0ecrycwv2tgb}"

LIVE_THEME="$PROD_ROOT/wp-content/themes/nuvanx-medical"
PROD_PARENT="${PROD_ROOT%/public_html}"
BACKUP_ROOT="$PROD_PARENT/.nvx-backups"

printf '%s\n' '=== NUVANX POST-CUTOVER COMPENSATING ROLLBACK ==='

[[ "$PROD_ROOT" == '/home/customer/www/nuvanx.com/public_html' ]] || {
  echo "PRODUCTION_COMPENSATING_ROLLBACK=FAIL reason=unexpected_prod_root root=$PROD_ROOT" >&2
  exit 2
}
[[ "$PROD_PARENT" == '/home/customer/www/nuvanx.com' ]] || {
  echo "PRODUCTION_COMPENSATING_ROLLBACK=FAIL reason=unexpected_prod_parent parent=$PROD_PARENT" >&2
  exit 2
}
[[ -s "$SITEGROUND_CACHE_HELPER" ]] || {
  echo "PRODUCTION_COMPENSATING_ROLLBACK=FAIL reason=cache_helper_missing helper=$SITEGROUND_CACHE_HELPER" >&2
  exit 2
}
# shellcheck source=/dev/null
source "$SITEGROUND_CACHE_HELPER"
[[ "$(type -t siteground_cache_purge || true)" == 'function' ]] || {
  echo 'PRODUCTION_COMPENSATING_ROLLBACK=FAIL reason=canonical_cache_function_missing' >&2
  exit 2
}
[[ -d "$BACKUP_ROOT" ]] || {
  echo "PRODUCTION_COMPENSATING_ROLLBACK=FAIL reason=backup_root_missing root=$BACKUP_ROOT" >&2
  exit 2
}
[[ -f "$LIVE_THEME/.nvx-deploy-sha" ]] || {
  echo 'PRODUCTION_COMPENSATING_ROLLBACK=FAIL reason=live_sha_missing' >&2
  exit 2
}

CURRENT_SHA="$(tr -d '\r\n[:space:]' < "$LIVE_THEME/.nvx-deploy-sha")"
[[ "$CURRENT_SHA" =~ ^[0-9a-f]{40}$ ]] || {
  echo "PRODUCTION_COMPENSATING_ROLLBACK=FAIL reason=invalid_current_sha sha=$CURRENT_SHA" >&2
  exit 2
}
SHORT_SHA="${CURRENT_SHA:0:12}"

BACKUP_DIR="$(find "$BACKUP_ROOT" -mindepth 1 -maxdepth 1 -type d -name "pre-prod-*-${SHORT_SHA}" -printf '%T@ %p\n' 2>/dev/null | sort -nr | head -n 1 | cut -d' ' -f2-)"
[[ -n "$BACKUP_DIR" && "$BACKUP_DIR" == "$BACKUP_ROOT/pre-prod-"* ]] || {
  echo "PRODUCTION_COMPENSATING_ROLLBACK=FAIL reason=matching_snapshot_not_found current_sha=$CURRENT_SHA" >&2
  exit 2
}
[[ -s "$BACKUP_DIR/theme.tgz" ]] || {
  echo "PRODUCTION_COMPENSATING_ROLLBACK=FAIL reason=theme_snapshot_missing backup=$BACKUP_DIR" >&2
  exit 2
}
[[ -s "$BACKUP_DIR/db.sql" ]] || {
  echo "PRODUCTION_COMPENSATING_ROLLBACK=FAIL reason=db_snapshot_missing backup=$BACKUP_DIR" >&2
  exit 2
}
[[ -f "$BACKUP_DIR/previous-sha.txt" ]] || {
  echo "PRODUCTION_COMPENSATING_ROLLBACK=FAIL reason=previous_sha_missing backup=$BACKUP_DIR" >&2
  exit 2
}

PREVIOUS_SHA="$(tr -d '\r\n[:space:]' < "$BACKUP_DIR/previous-sha.txt")"
[[ "$PREVIOUS_SHA" =~ ^[0-9a-f]{40}$ ]] || {
  echo "PRODUCTION_COMPENSATING_ROLLBACK=FAIL reason=invalid_previous_sha sha=$PREVIOUS_SHA backup=$BACKUP_DIR" >&2
  exit 2
}
[[ "$PREVIOUS_SHA" != "$CURRENT_SHA" ]] || {
  echo "PRODUCTION_COMPENSATING_ROLLBACK=FAIL reason=snapshot_not_previous sha=$PREVIOUS_SHA backup=$BACKUP_DIR" >&2
  exit 2
}

echo "PRODUCTION_COMPENSATING_ROLLBACK=ARMED current_sha=$CURRENT_SHA previous_sha=$PREVIOUS_SHA backup=$BACKUP_DIR"

ROLLBACK_TMP="$BACKUP_ROOT/.compensating-${CURRENT_SHA:0:12}-$$"
FAILED_THEME="$PROD_ROOT/wp-content/themes/.nuvanx-failed-post-audit-${CURRENT_SHA:0:12}-$$"
rm -rf "$ROLLBACK_TMP" "$FAILED_THEME"
mkdir -p "$ROLLBACK_TMP"
tar -xzf "$BACKUP_DIR/theme.tgz" -C "$ROLLBACK_TMP"
RESTORED_THEME="$ROLLBACK_TMP/wp-content/themes/nuvanx-medical"

[[ -d "$RESTORED_THEME" && -f "$RESTORED_THEME/.nvx-deploy-sha" ]] || {
  echo 'PRODUCTION_COMPENSATING_ROLLBACK=FAIL reason=restored_theme_invalid' >&2
  rm -rf "$ROLLBACK_TMP"
  exit 2
}
RESTORED_DISK_SHA="$(tr -d '\r\n[:space:]' < "$RESTORED_THEME/.nvx-deploy-sha")"
[[ "$RESTORED_DISK_SHA" == "$PREVIOUS_SHA" ]] || {
  echo "PRODUCTION_COMPENSATING_ROLLBACK=FAIL reason=snapshot_sha_mismatch actual=$RESTORED_DISK_SHA expected=$PREVIOUS_SHA" >&2
  rm -rf "$ROLLBACK_TMP"
  exit 2
}

mv "$LIVE_THEME" "$FAILED_THEME"
if ! mv "$RESTORED_THEME" "$LIVE_THEME"; then
  echo 'PRODUCTION_COMPENSATING_ROLLBACK=FAIL reason=theme_restore_move_failed' >&2
  rm -rf "$LIVE_THEME" 2>/dev/null || true
  mv "$FAILED_THEME" "$LIVE_THEME" 2>/dev/null || true
  rm -rf "$ROLLBACK_TMP"
  exit 2
fi

DB_ROLLBACK='skipped-no-release-db-writes'
MIGRATION_LOG="$BACKUP_DIR/migration-production.log"
if [[ -f "$MIGRATION_LOG" ]] && grep -Fq 'MIGRATION_WRITE_MARKER_CREATED' "$MIGRATION_LOG"; then
  echo 'PRODUCTION_COMPENSATING_ROLLBACK_DB=RESTORE reason=release-migration-wrote-database'
  if ! ( cd "$PROD_ROOT" && wp db import "$BACKUP_DIR/db.sql" --allow-root ); then
    echo 'PRODUCTION_COMPENSATING_ROLLBACK=FAIL reason=db_restore_failed' >&2
    exit 2
  fi
  DB_ROLLBACK='restored-release-snapshot'
else
  echo 'PRODUCTION_COMPENSATING_ROLLBACK_DB=SKIP reason=no-release-db-write-marker'
fi

if ! siteground_cache_purge "$PROD_ROOT" preserve; then
  echo 'PRODUCTION_COMPENSATING_ROLLBACK=FAIL reason=canonical_cache_purge_failed' >&2
  exit 2
fi
echo 'PRODUCTION_COMPENSATING_CACHE=PASS owner=tools/deploy/siteground-cache-purge.sh fail_closed=true'

cd "$PROD_ROOT"
[[ "$(wp config get DB_NAME)" == "$PROD_DB_NAME" ]] || { echo 'PRODUCTION_COMPENSATING_ROLLBACK=FAIL reason=db_identity' >&2; exit 2; }
[[ "$(wp option get home)" == "$BASE_URL" ]] || { echo 'PRODUCTION_COMPENSATING_ROLLBACK=FAIL reason=home_identity' >&2; exit 2; }
[[ "$(wp option get siteurl)" == "$BASE_URL" ]] || { echo 'PRODUCTION_COMPENSATING_ROLLBACK=FAIL reason=siteurl_identity' >&2; exit 2; }
[[ "$(wp option get blog_public)" == '1' ]] || { echo 'PRODUCTION_COMPENSATING_ROLLBACK=FAIL reason=blog_public_identity' >&2; exit 2; }
[[ "$(wp theme list --status=active --field=name)" == 'nuvanx-medical' ]] || { echo 'PRODUCTION_COMPENSATING_ROLLBACK=FAIL reason=theme_identity' >&2; exit 2; }
[[ "$(tr -d '\r\n[:space:]' < "$LIVE_THEME/.nvx-deploy-sha")" == "$PREVIOUS_SHA" ]] || { echo 'PRODUCTION_COMPENSATING_ROLLBACK=FAIL reason=restored_disk_sha' >&2; exit 2; }

PUBLIC_SHA=''
for attempt in {1..12}; do
  set +e
  PUBLIC_SHA="$(curl -fsSL --max-time 30 -A 'NUVANX-Compensating-Rollback/1.0' -H 'Cache-Control: no-cache' -H 'Pragma: no-cache' "$BASE_URL/" | php -r '$h=stream_get_contents(STDIN); if (preg_match("~<meta[^>]+name=[\"\x27]nvx-deploy-sha[\"\x27][^>]+content=[\"\x27]([0-9a-f]{40})[\"\x27]~i",$h,$m)) echo $m[1];')"
  public_rc=$?
  set -e
  if [[ "$public_rc" -eq 0 && "$PUBLIC_SHA" == "$PREVIOUS_SHA" ]]; then
    break
  fi
  sleep 5
done
[[ "$PUBLIC_SHA" == "$PREVIOUS_SHA" ]] || {
  echo "PRODUCTION_COMPENSATING_ROLLBACK=FAIL reason=public_sha_not_restored expected=$PREVIOUS_SHA actual=${PUBLIC_SHA:-missing}" >&2
  exit 2
}

rm -rf "$FAILED_THEME" "$ROLLBACK_TMP"
echo "PRODUCTION_COMPENSATING_ROLLBACK=PASS restored_sha=$PREVIOUS_SHA failed_sha=$CURRENT_SHA db=$DB_ROLLBACK backup=$BACKUP_DIR"

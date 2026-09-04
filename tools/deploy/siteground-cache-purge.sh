#!/usr/bin/env bash
# Canonical SiteGround cache purge capability for NUVANX release tooling.
# Can be sourced for siteground_cache_purge() or executed directly.
set -Eeuo pipefail

siteground_cache_purge() {
  local wp_root="${1:-}"
  local final_state="${2:-preserve}"
  local plugin_slug='sg-cachepress'
  local initial_state='inactive'

  [[ -n "$wp_root" && -d "$wp_root" ]] || { echo "SITEGROUND_CACHE_PURGE=FAIL reason=invalid_wp_root root=${wp_root:-missing}" >&2; return 1; }
  [[ "$final_state" == 'preserve' || "$final_state" == 'active' || "$final_state" == 'inactive' ]] \
    || { echo "SITEGROUND_CACHE_PURGE=FAIL reason=invalid_final_state state=$final_state" >&2; return 1; }

  (
    set -Eeuo pipefail
    # Callers may themselves run under errtrace or invoke this function from a
    # conditional shell context. Clear inherited ERR handlers and propagate
    # critical command failures explicitly instead of relying on errexit alone.
    trap - ERR

    cd "$wp_root" || exit $?
    command -v wp >/dev/null 2>&1 \
      || { echo 'SITEGROUND_CACHE_PURGE=FAIL reason=wp_cli_missing' >&2; exit 1; }
    wp plugin is-installed "$plugin_slug" >/dev/null 2>&1 \
      || { echo 'SITEGROUND_CACHE_PURGE=FAIL reason=optimizer_not_installed' >&2; exit 1; }

    if wp plugin is-active "$plugin_slug" >/dev/null 2>&1; then
      initial_state='active'
    fi

    restore_requested_state_on_failure() {
      local rc=$?
      local restore_rc=0
      local expected_state="$initial_state"

      case "$final_state" in
        active) expected_state='active' ;;
        inactive) expected_state='inactive' ;;
        preserve) expected_state="$initial_state" ;;
      esac

      if [[ "$rc" -ne 0 ]]; then
        set +e
        if [[ "$expected_state" == 'active' ]]; then
          if ! wp plugin is-active "$plugin_slug" >/dev/null 2>&1; then
            wp plugin activate "$plugin_slug" --quiet
            restore_rc=$?
          fi
          if [[ "$restore_rc" -eq 0 ]] && ! wp plugin is-active "$plugin_slug" >/dev/null 2>&1; then
            restore_rc=10
          fi
        else
          if wp plugin is-active "$plugin_slug" >/dev/null 2>&1; then
            wp plugin deactivate "$plugin_slug" --quiet
            restore_rc=$?
          fi
          if [[ "$restore_rc" -eq 0 ]] && wp plugin is-active "$plugin_slug" >/dev/null 2>&1; then
            restore_rc=10
          fi
        fi
        set -e

        if [[ "$restore_rc" -ne 0 ]]; then
          echo "SITEGROUND_CACHE_PURGE_RESTORE=FAIL original_rc=$rc restore_rc=$restore_rc expected=$expected_state" >&2
        else
          echo "SITEGROUND_CACHE_PURGE_RESTORE=PASS original_rc=$rc restored=$expected_state" >&2
        fi
      fi

      trap - EXIT
      exit "$rc"
    }
    trap restore_requested_state_on_failure EXIT

    wp cache flush || exit $?

    if [[ "$initial_state" != 'active' ]]; then
      wp plugin activate "$plugin_slug" --quiet || exit $?
    fi
    wp plugin is-active "$plugin_slug" >/dev/null 2>&1 \
      || { echo 'SITEGROUND_CACHE_PURGE=FAIL reason=optimizer_activation_failed' >&2; exit 1; }

    # A fresh WP-CLI process is required after activation so the plugin can
    # register the `sg` command. This invocation is deliberately fail-closed.
    wp sg purge || exit $?

    rm -rf wp-content/uploads/siteground-optimizer-assets/siteground-optimizer-combined-* || exit $?
    rm -rf wp-content/cache/sgo-cache/* || exit $?
    rm -rf wp-content/cache/* || exit $?
    # WP-CLI itself can exit 0 even when opcache_reset() returns false. Convert
    # that PHP-level failure into a non-zero process status so release callers
    # cannot report a successful purge with stale opcode cache still present.
    wp eval 'if (function_exists("opcache_reset") && ! opcache_reset()) { fwrite(STDERR, "opcache_reset failed\n"); exit(1); }' || exit $?

    case "$final_state" in
      preserve)
        if [[ "$initial_state" == 'active' ]]; then
          wp plugin is-active "$plugin_slug" >/dev/null 2>&1 || exit $?
        else
          wp plugin deactivate "$plugin_slug" --quiet || exit $?
          if wp plugin is-active "$plugin_slug" >/dev/null 2>&1; then
            exit 1
          fi
        fi
        ;;
      active)
        wp plugin is-active "$plugin_slug" >/dev/null 2>&1 || exit $?
        ;;
      inactive)
        if wp plugin is-active "$plugin_slug" >/dev/null 2>&1; then
          wp plugin deactivate "$plugin_slug" --quiet || exit $?
        fi
        if wp plugin is-active "$plugin_slug" >/dev/null 2>&1; then
          exit 1
        fi
        ;;
    esac

    trap - EXIT
    echo "SITEGROUND_CACHE_PURGE=PASS initial=$initial_state final=$final_state capability=wp-sg-purge"
  )
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  WP_ROOT=''
  FINAL_STATE='preserve'

  while [[ $# -gt 0 ]]; do
    case "$1" in
      --wp-root) WP_ROOT="${2:-}"; shift 2 ;;
      --final-state) FINAL_STATE="${2:-}"; shift 2 ;;
      *) echo "Unknown argument: $1" >&2; exit 2 ;;
    esac
  done

  siteground_cache_purge "$WP_ROOT" "$FINAL_STATE"
fi

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
    cd "$wp_root"
    command -v wp >/dev/null 2>&1
    wp plugin is-installed "$plugin_slug" >/dev/null 2>&1 \
      || { echo 'SITEGROUND_CACHE_PURGE=FAIL reason=optimizer_not_installed' >&2; exit 1; }

    if wp plugin is-active "$plugin_slug" >/dev/null 2>&1; then
      initial_state='active'
    fi

    wp cache flush

    if [[ "$initial_state" != 'active' ]]; then
      wp plugin activate "$plugin_slug" --quiet
    fi
    wp plugin is-active "$plugin_slug" >/dev/null 2>&1 \
      || { echo 'SITEGROUND_CACHE_PURGE=FAIL reason=optimizer_activation_failed' >&2; exit 1; }

    # A fresh WP-CLI process is required after activation so the plugin can
    # register the `sg` command. This invocation is deliberately fail-closed.
    wp sg purge

    rm -rf wp-content/uploads/siteground-optimizer-assets/siteground-optimizer-combined-*
    rm -rf wp-content/cache/sgo-cache/*
    rm -rf wp-content/cache/*
    wp eval 'if (function_exists("opcache_reset")) { opcache_reset(); }'

    case "$final_state" in
      preserve)
        if [[ "$initial_state" == 'active' ]]; then
          wp plugin is-active "$plugin_slug" >/dev/null 2>&1
        else
          wp plugin deactivate "$plugin_slug" --quiet
          ! wp plugin is-active "$plugin_slug" >/dev/null 2>&1
        fi
        ;;
      active)
        wp plugin is-active "$plugin_slug" >/dev/null 2>&1
        ;;
      inactive)
        if wp plugin is-active "$plugin_slug" >/dev/null 2>&1; then
          wp plugin deactivate "$plugin_slug" --quiet
        fi
        ! wp plugin is-active "$plugin_slug" >/dev/null 2>&1
        ;;
    esac

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

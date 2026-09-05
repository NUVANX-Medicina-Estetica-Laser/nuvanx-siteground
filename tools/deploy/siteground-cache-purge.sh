#!/usr/bin/env bash
# Canonical SiteGround cache purge capability for NUVANX release tooling.
# Can be sourced for siteground_cache_purge() or executed directly.
set -Eeuo pipefail

siteground_cache_purge() {
  local wp_root="${1:-}"
  local final_state="${2:-preserve}"
  local plugin_slug='sg-cachepress'
  local plugin_installed=0
  local initial_state='uninstalled'
  local sg_command_available=0

  [[ -n "$wp_root" && -d "$wp_root" ]] || { echo "SITEGROUND_CACHE_PURGE=FAIL reason=invalid_wp_root root=${wp_root:-missing}" >&2; return 1; }
  [[ "$final_state" == 'preserve' || "$final_state" == 'active' || "$final_state" == 'inactive' ]] \
    || { echo "SITEGROUND_CACHE_PURGE=FAIL reason=invalid_final_state state=$final_state" >&2; return 1; }

  (
    set -Eeuo pipefail
    trap - ERR

    cd "$wp_root" || exit $?
    command -v wp >/dev/null 2>&1 \
      || { echo 'SITEGROUND_CACHE_PURGE=FAIL reason=wp_cli_missing' >&2; exit 1; }

    # Read optimizer state explicitly. `wp plugin is-active` intentionally uses
    # a non-zero exit for the ordinary inactive state, which makes it unsuitable
    # as a fail-closed state probe because operational WP-CLI/DB failures are also
    # non-zero. `plugin get --field=status` separates data from process failure.
    optimizer_observed_state() {
      local observed=''
      observed="$(wp plugin get "$plugin_slug" --field=status 2>/dev/null)" || return $?
      case "$observed" in
        active|active-network) printf 'active\n' ;;
        inactive) printf 'inactive\n' ;;
        *) return 10 ;;
      esac
    }

    # Plugin mutations and their verification occur in separate WP-CLI
    # processes. Persistent object cache can otherwise expose the pre-mutation
    # `active_plugins` option to the verifier. Flush after the mutation, then
    # require an explicit state read; mutation, flush, and verification are all
    # fail-closed.
    optimizer_set_state_coherent() {
      local requested="$1"
      local current=''
      local observed=''
      [[ "$requested" == 'active' || "$requested" == 'inactive' ]] || return 10

      current="$(optimizer_observed_state)" || return $?
      if [[ "$current" == "$requested" ]]; then
        return 0
      fi

      if [[ "$requested" == 'active' ]]; then
        wp plugin activate "$plugin_slug" --quiet || return $?
      else
        wp plugin deactivate "$plugin_slug" --quiet || return $?
      fi

      wp cache flush >/dev/null || return $?
      observed="$(optimizer_observed_state)" || return $?
      [[ "$observed" == "$requested" ]]
    }

    if wp plugin is-installed "$plugin_slug" >/dev/null 2>&1; then
      plugin_installed=1
    fi

    # Invalidate persistent object cache before snapshotting any state that may
    # later be restored. Otherwise preserve mode can turn an already-stale
    # active_plugins cache entry into the authoritative restoration target and
    # actively reverse the database state. This single initial flush also makes
    # the subsequent `wp help sg` capability probe coherent.
    wp cache flush || exit $?

    if [[ "$plugin_installed" -eq 1 ]]; then
      initial_state="$(optimizer_observed_state)" \
        || { echo 'SITEGROUND_CACHE_PURGE=FAIL reason=optimizer_state_probe_failed' >&2; exit 1; }
    fi

    # SiteGround can expose `wp sg` as a host capability even when WP-CLI does
    # not report sg-cachepress as installed. Preserve that supported command-first
    # path. Explicit active/inactive final states still require a real plugin whose
    # state can be verified and restored.
    if wp help sg >/dev/null 2>&1; then
      sg_command_available=1
    fi
    if [[ "$plugin_installed" -eq 0 && "$final_state" != 'preserve' ]]; then
      echo "SITEGROUND_CACHE_PURGE=FAIL reason=optimizer_not_installed_for_requested_state state=$final_state" >&2
      exit 1
    fi
    if [[ "$plugin_installed" -eq 0 && "$sg_command_available" -eq 0 ]]; then
      echo 'SITEGROUND_CACHE_PURGE=FAIL reason=no_siteground_purge_capability' >&2
      exit 1
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
        if [[ "$plugin_installed" -eq 0 ]]; then
          # The command-only path never installs or mutates plugin state.
          if wp plugin is-installed "$plugin_slug" >/dev/null 2>&1; then
            restore_rc=10
          fi
        else
          optimizer_set_state_coherent "$expected_state"
          restore_rc=$?
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

    if [[ "$sg_command_available" -eq 0 ]]; then
      optimizer_set_state_coherent active \
        || { echo 'SITEGROUND_CACHE_PURGE=FAIL reason=optimizer_activation_failed' >&2; exit 1; }

      # Activation occurs in a different WP-CLI process; after coherent state
      # verification, require the command registered by the active plugin.
      wp help sg >/dev/null 2>&1 \
        || { echo 'SITEGROUND_CACHE_PURGE=FAIL reason=sg_command_missing_after_activation' >&2; exit 1; }
    fi

    wp sg purge || exit $?

    rm -rf wp-content/uploads/siteground-optimizer-assets/siteground-optimizer-combined-* || exit $?
    rm -rf wp-content/cache/sgo-cache/* || exit $?
    rm -rf wp-content/cache/* || exit $?
    # WP-CLI itself can exit 0 even when opcache_reset() returns false. Convert
    # that PHP-level failure into a non-zero process status so release callers
    # cannot report a successful purge with stale opcode cache still present.
    # Exclude environments where OPcache is disabled in CLI, preventing false
    # failure when opcache_get_status() reports false.
    wp eval 'if (function_exists("opcache_get_status") && opcache_get_status() !== false && function_exists("opcache_reset") && ! opcache_reset()) { fwrite(STDERR, "opcache_reset failed\n"); exit(1); }' || exit $?

    case "$final_state" in
      preserve)
        if [[ "$plugin_installed" -eq 0 ]]; then
          if wp plugin is-installed "$plugin_slug" >/dev/null 2>&1; then
            exit 1
          fi
        else
          optimizer_set_state_coherent "$initial_state" || exit $?
        fi
        ;;
      active)
        optimizer_set_state_coherent active || exit $?
        ;;
      inactive)
        optimizer_set_state_coherent inactive || exit $?
        ;;
    esac

    trap - EXIT
    mode='plugin-assisted'
    [[ "$sg_command_available" -eq 1 ]] && mode='command-first'
    echo "SITEGROUND_CACHE_PURGE=PASS initial=$initial_state final=$final_state capability=wp-sg-purge mode=$mode"
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
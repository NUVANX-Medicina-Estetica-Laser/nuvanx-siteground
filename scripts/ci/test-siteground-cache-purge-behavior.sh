#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
HELPER="$ROOT/tools/deploy/siteground-cache-purge.sh"
TMP="$(mktemp -d)"
WP_ROOT="$TMP/wp-root"
STATE_FILE="$TMP/plugin-state"
MOCK_BIN="$TMP/bin"

cleanup() {
  rm -rf "$TMP"
}
trap cleanup EXIT

fail() {
  echo "SITEGROUND_CACHE_BEHAVIOR=FAIL $*" >&2
  exit 1
}

[[ -s "$HELPER" ]] || fail "missing_helper=$HELPER"
grep -Fq '! opcache_reset()' "$HELPER" \
  || fail 'opcache_reset_return_value_must_be_checked'
grep -Fq 'exit(1);' "$HELPER" \
  || fail 'opcache_reset_false_must_produce_nonzero_wp_cli_status'
mkdir -p "$MOCK_BIN" "$WP_ROOT/wp-content/uploads/siteground-optimizer-assets" "$WP_ROOT/wp-content/cache"

cat > "$MOCK_BIN/wp" <<'MOCK'
#!/usr/bin/env bash
set -euo pipefail

state_file="${WP_MOCK_STATE_FILE:?missing_state_file}"
fail_cmd="${WP_MOCK_FAIL_CMD:-}"
installed="${WP_MOCK_PLUGIN_INSTALLED:-1}"
command_available="${WP_MOCK_SG_COMMAND_AVAILABLE:-0}"
cmd="${1:-}"
sub="${2:-}"

case "$cmd:$sub" in
  plugin:is-installed)
    [[ "$installed" == '1' ]]
    ;;
  plugin:is-active)
    [[ "$installed" == '1' && "$(cat "$state_file")" == 'active' ]]
    ;;
  plugin:get)
    [[ "$installed" == '1' ]] || exit 44
    cat "$state_file"
    exit 0
    ;;
  plugin:activate)
    [[ "$installed" == '1' ]] || exit 41
    printf '%s\n' active > "$state_file"
    exit 0
    ;;
  plugin:deactivate)
    [[ "$installed" == '1' ]] || exit 42
    printf '%s\n' inactive > "$state_file"
    exit 0
    ;;
  help:sg)
    # When the plugin exists but the command was not initially available, a
    # fresh WP-CLI process after activation exposes it.
    if [[ "$command_available" == '1' ]]; then exit 0; fi
    if [[ "$installed" == '1' && "$(cat "$state_file")" == 'active' ]]; then exit 0; fi
    exit 1
    ;;
  cache:flush)
    [[ "$fail_cmd" != 'cache-flush' ]] || exit 31
    exit 0
    ;;
  sg:purge)
    if [[ "$command_available" != '1' && !( "$installed" == '1' && "$(cat "$state_file")" == 'active' ) ]]; then
      exit 43
    fi
    [[ "$fail_cmd" != 'sg-purge' ]] || exit 32
    exit 0
    ;;
  eval:*)
    if [[ "$fail_cmd" == 'opcache-reset-fail' ]]; then
      echo "opcache_reset failed" >&2
      exit 1
    fi
    [[ "$fail_cmd" != 'eval' ]] || exit 33
    exit 0
    ;;
  *)
    echo "unexpected wp invocation: $*" >&2
    exit 97
    ;;
esac
MOCK
chmod +x "$MOCK_BIN/wp"

# shellcheck source=../../tools/deploy/siteground-cache-purge.sh
source "$HELPER"

set_mock_capability() {
  export WP_MOCK_PLUGIN_INSTALLED="$1"
  export WP_MOCK_SG_COMMAND_AVAILABLE="$2"
}

run_failure_case() {
  local name="$1"
  local initial="$2"
  local final="$3"
  local fail_cmd="$4"
  local expected_state="$5"
  local expected_rc="$6"
  local rc=0
  local actual_state=''

  printf '%s\n' "$initial" > "$STATE_FILE"
  export WP_MOCK_STATE_FILE="$STATE_FILE"
  export WP_MOCK_FAIL_CMD="$fail_cmd"
  set_mock_capability 1 0

  if PATH="$MOCK_BIN:$PATH" siteground_cache_purge "$WP_ROOT" "$final" >/dev/null 2>"$TMP/$name.err"; then
    fail "case=$name expected_failure=1"
  else
    rc=$?
  fi

  actual_state="$(cat "$STATE_FILE")"
  [[ "$rc" -eq "$expected_rc" ]] || fail "case=$name rc=$rc expected_rc=$expected_rc"
  [[ "$actual_state" == "$expected_state" ]] \
    || fail "case=$name state=$actual_state expected_state=$expected_state"
  grep -Fq "SITEGROUND_CACHE_PURGE_RESTORE=PASS original_rc=$expected_rc restored=$expected_state" "$TMP/$name.err" \
    || fail "case=$name missing_restore_evidence"
}

run_success_case() {
  local name="$1"
  local initial="$2"
  local final="$3"
  local expected_state="$4"
  local actual_state=''

  printf '%s\n' "$initial" > "$STATE_FILE"
  export WP_MOCK_STATE_FILE="$STATE_FILE"
  unset WP_MOCK_FAIL_CMD
  set_mock_capability 1 0

  PATH="$MOCK_BIN:$PATH" siteground_cache_purge "$WP_ROOT" "$final" >/dev/null \
    || fail "case=$name expected_success=1"
  actual_state="$(cat "$STATE_FILE")"
  [[ "$actual_state" == "$expected_state" ]] \
    || fail "case=$name state=$actual_state expected_state=$expected_state"
}

run_command_first_case() {
  local name="$1"
  local fail_cmd="${2:-}"
  local rc=0

  printf '%s\n' uninstalled > "$STATE_FILE"
  export WP_MOCK_STATE_FILE="$STATE_FILE"
  set_mock_capability 0 1
  if [[ -n "$fail_cmd" ]]; then export WP_MOCK_FAIL_CMD="$fail_cmd"; else unset WP_MOCK_FAIL_CMD; fi

  if [[ -z "$fail_cmd" ]]; then
    PATH="$MOCK_BIN:$PATH" siteground_cache_purge "$WP_ROOT" preserve >"$TMP/$name.out" 2>"$TMP/$name.err" \
      || fail "case=$name expected_success=1"
    grep -Fq 'initial=uninstalled final=preserve capability=wp-sg-purge mode=command-first' "$TMP/$name.out" \
      || fail "case=$name missing_command_first_evidence"
  else
    if PATH="$MOCK_BIN:$PATH" siteground_cache_purge "$WP_ROOT" preserve >"$TMP/$name.out" 2>"$TMP/$name.err"; then
      fail "case=$name expected_failure=1"
    else
      rc=$?
    fi
    [[ "$rc" -eq 32 ]] || fail "case=$name rc=$rc expected_rc=32"
    grep -Fq 'SITEGROUND_CACHE_PURGE_RESTORE=PASS original_rc=32 restored=uninstalled' "$TMP/$name.err" \
      || fail "case=$name missing_uninstalled_restore_evidence"
  fi
  [[ "$(cat "$STATE_FILE")" == 'uninstalled' ]] || fail "case=$name plugin_state_mutated"
}

run_missing_capability_case() {
  local rc=0
  printf '%s\n' uninstalled > "$STATE_FILE"
  export WP_MOCK_STATE_FILE="$STATE_FILE"
  unset WP_MOCK_FAIL_CMD
  set_mock_capability 0 0
  if PATH="$MOCK_BIN:$PATH" siteground_cache_purge "$WP_ROOT" preserve >/dev/null 2>"$TMP/no-capability.err"; then
    fail 'case=no_capability expected_failure=1'
  else
    rc=$?
  fi
  [[ "$rc" -eq 1 ]] || fail "case=no_capability rc=$rc expected_rc=1"
  grep -Fq 'SITEGROUND_CACHE_PURGE=FAIL reason=no_siteground_purge_capability' "$TMP/no-capability.err" \
    || fail 'case=no_capability missing_fail_closed_evidence'
}

# Failures after optimizer activation/purge must restore the caller-requested state
# while preserving the original failing exit code.
run_failure_case inactive_preserve_sg inactive preserve sg-purge inactive 32
run_failure_case active_inactive_eval active inactive eval inactive 33
run_failure_case active_preserve_eval active preserve eval active 33
run_failure_case inactive_active_eval inactive active eval active 33
run_failure_case active_preserve_opcache_fail active preserve opcache-reset-fail active 1
grep -Fq 'opcache_reset failed' "$TMP/active_preserve_opcache_fail.err" || fail 'case=active_preserve_opcache_fail missing opcache_reset stderr'

# Success-path state transitions use the same contract.
run_success_case inactive_preserve_success inactive preserve inactive
run_success_case inactive_active_success inactive active active
run_success_case active_inactive_success active inactive inactive

# Host-provided `wp sg` is a first-class capability even without sg-cachepress in
# the plugin registry. Preserve mode must never install or mutate plugin state.
run_command_first_case command_only_success
run_command_first_case command_only_failure sg-purge
run_missing_capability_case

echo 'SITEGROUND_CACHE_BEHAVIOR=PASS failure_cases=6 success_cases=4 original_rc=preserved requested_state=restored opcache_return=fail_closed command_first=verified missing_capability=fail_closed explicit_state_probe=verified'
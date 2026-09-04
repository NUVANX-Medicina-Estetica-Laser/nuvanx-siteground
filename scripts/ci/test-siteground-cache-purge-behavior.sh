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
cmd="${1:-}"
sub="${2:-}"

case "$cmd:$sub" in
  plugin:is-installed)
    exit 0
    ;;
  plugin:is-active)
    [[ "$(cat "$state_file")" == 'active' ]]
    ;;
  plugin:activate)
    printf '%s\n' active > "$state_file"
    exit 0
    ;;
  plugin:deactivate)
    printf '%s\n' inactive > "$state_file"
    exit 0
    ;;
  cache:flush)
    [[ "$fail_cmd" != 'cache-flush' ]] || exit 31
    exit 0
    ;;
  sg:purge)
    [[ "$fail_cmd" != 'sg-purge' ]] || exit 32
    exit 0
    ;;
  eval:*)
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

  PATH="$MOCK_BIN:$PATH" siteground_cache_purge "$WP_ROOT" "$final" >/dev/null \
    || fail "case=$name expected_success=1"
  actual_state="$(cat "$STATE_FILE")"
  [[ "$actual_state" == "$expected_state" ]] \
    || fail "case=$name state=$actual_state expected_state=$expected_state"
}

# Failures after optimizer activation/purge must restore the caller-requested state
# while preserving the original failing exit code.
run_failure_case inactive_preserve_sg inactive preserve sg-purge inactive 32
run_failure_case active_inactive_eval active inactive eval inactive 33
run_failure_case active_preserve_eval active preserve eval active 33
run_failure_case inactive_active_eval inactive active eval active 33

# Success-path state transitions use the same contract.
run_success_case inactive_preserve_success inactive preserve inactive
run_success_case inactive_active_success inactive active active
run_success_case active_inactive_success active inactive inactive

echo 'SITEGROUND_CACHE_BEHAVIOR=PASS failure_cases=4 success_cases=3 original_rc=preserved requested_state=restored opcache_return=fail_closed'

#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
HELPER="$ROOT/tools/deploy/siteground-cache-purge.sh"
TMP="$(mktemp -d)"
WP_ROOT="$TMP/wp-root"
MOCK_BIN="$TMP/bin"
DB_STATE="$TMP/db-plugin-state"
CACHE_STATE="$TMP/cached-plugin-state"
FLUSH_COUNT="$TMP/flush-count"

cleanup() { rm -rf "$TMP"; }
trap cleanup EXIT

fail() {
  echo "SITEGROUND_CACHE_STATE_COHERENCE=FAIL $*" >&2
  exit 1
}

mkdir -p "$MOCK_BIN" "$WP_ROOT/wp-content/uploads/siteground-optimizer-assets" "$WP_ROOT/wp-content/cache"
printf '0\n' > "$FLUSH_COUNT"

cat > "$MOCK_BIN/wp" <<'MOCK'
#!/usr/bin/env bash
set -Eeuo pipefail

db_state="${WP_MOCK_DB_STATE:?missing_db_state}"
cache_state="${WP_MOCK_CACHE_STATE:?missing_cache_state}"
flush_count="${WP_MOCK_FLUSH_COUNT:?missing_flush_count}"
fail_activation="${WP_MOCK_FAIL_ACTIVATION:-0}"
fail_state_probe="${WP_MOCK_FAIL_STATE_PROBE:-0}"
cmd="${1:-}"
sub="${2:-}"

effective_state() {
  if [[ -f "$cache_state" ]]; then
    cat "$cache_state"
  else
    cat "$db_state"
  fi
}

case "$cmd:$sub" in
  plugin:is-installed)
    exit 0
    ;;
  plugin:is-active)
    [[ "$(effective_state)" == 'active' ]]
    ;;
  plugin:get)
    [[ "$fail_state_probe" != '1' ]] || exit 55
    effective_state
    exit 0
    ;;
  plugin:activate)
    [[ "$fail_activation" != '1' ]] || exit 41
    old="$(cat "$db_state")"
    printf 'active\n' > "$db_state"
    # Reproduce a shared-object-cache refill that still contains the pre-write
    # active_plugins value when the next WP-CLI process starts.
    printf '%s\n' "$old" > "$cache_state"
    exit 0
    ;;
  plugin:deactivate)
    old="$(cat "$db_state")"
    printf 'inactive\n' > "$db_state"
    printf '%s\n' "$old" > "$cache_state"
    exit 0
    ;;
  cache:flush)
    count="$(cat "$flush_count")"
    printf '%s\n' "$((count + 1))" > "$flush_count"
    rm -f "$cache_state"
    exit 0
    ;;
  help:sg)
    [[ "$(effective_state)" == 'active' ]]
    ;;
  sg:purge)
    [[ "$(effective_state)" == 'active' ]]
    ;;
  eval:*)
    exit 0
    ;;
  *)
    echo "unexpected wp invocation: $*" >&2
    exit 97
    ;;
esac
MOCK
chmod +x "$MOCK_BIN/wp"

export WP_MOCK_DB_STATE="$DB_STATE"
export WP_MOCK_CACHE_STATE="$CACHE_STATE"
export WP_MOCK_FLUSH_COUNT="$FLUSH_COUNT"

# shellcheck source=../../tools/deploy/siteground-cache-purge.sh
source "$HELPER"

run_case() {
  local name="$1"
  local initial="$2"
  local final="$3"
  local expected="$4"
  printf '%s\n' "$initial" > "$DB_STATE"
  rm -f "$CACHE_STATE"
  printf '0\n' > "$FLUSH_COUNT"
  unset WP_MOCK_FAIL_ACTIVATION WP_MOCK_FAIL_STATE_PROBE

  PATH="$MOCK_BIN:$PATH" siteground_cache_purge "$WP_ROOT" "$final" >"$TMP/$name.out" 2>"$TMP/$name.err" \
    || fail "case=$name expected_success=1 stderr=$(tr '\n' ' ' < "$TMP/$name.err")"

  [[ "$(cat "$DB_STATE")" == "$expected" ]] \
    || fail "case=$name final_state=$(cat "$DB_STATE") expected=$expected"
  [[ ! -f "$CACHE_STATE" ]] \
    || fail "case=$name stale_cache_survived=$(cat "$CACHE_STATE")"
  grep -Fq 'SITEGROUND_CACHE_PURGE=PASS' "$TMP/$name.out" \
    || fail "case=$name missing_pass_evidence"
}

# These cases failed with cross-process stale active_plugins state before the
# helper invalidated cache after each plugin state mutation.
run_case inactive_to_active inactive active active
run_case inactive_preserve inactive preserve inactive
run_case active_to_inactive active inactive inactive

# State coherence must never convert a genuine activation failure into PASS.
printf 'inactive\n' > "$DB_STATE"
rm -f "$CACHE_STATE"
printf '0\n' > "$FLUSH_COUNT"
unset WP_MOCK_FAIL_STATE_PROBE
export WP_MOCK_FAIL_ACTIVATION=1
if PATH="$MOCK_BIN:$PATH" siteground_cache_purge "$WP_ROOT" active >"$TMP/real-failure.out" 2>"$TMP/real-failure.err"; then
  fail 'case=real_activation_failure expected_failure=1'
fi
grep -Fq 'SITEGROUND_CACHE_PURGE=FAIL reason=optimizer_activation_failed' "$TMP/real-failure.err" \
  || fail 'case=real_activation_failure missing_fail_closed_evidence'
[[ "$(cat "$DB_STATE")" == 'inactive' ]] \
  || fail 'case=real_activation_failure state_mutated'

# An operational WP-CLI/DB error while reading state must not be interpreted as
# ordinary inactivity.
printf 'inactive\n' > "$DB_STATE"
rm -f "$CACHE_STATE"
unset WP_MOCK_FAIL_ACTIVATION
export WP_MOCK_FAIL_STATE_PROBE=1
if PATH="$MOCK_BIN:$PATH" siteground_cache_purge "$WP_ROOT" preserve >"$TMP/probe-failure.out" 2>"$TMP/probe-failure.err"; then
  fail 'case=state_probe_failure expected_failure=1'
fi
grep -Fq 'SITEGROUND_CACHE_PURGE=FAIL reason=optimizer_state_probe_failed' "$TMP/probe-failure.err" \
  || fail 'case=state_probe_failure missing_fail_closed_evidence'

echo 'SITEGROUND_CACHE_STATE_COHERENCE=PASS stale_cross_process_cases=3 genuine_activation_failure=fail_closed state_probe_failure=fail_closed'
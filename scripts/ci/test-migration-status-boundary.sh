#!/usr/bin/env bash
set -euo pipefail

workflow='.github/workflows/staging.yml'
[[ -f "$workflow" ]] || { echo 'MIGRATION_STATUS_BOUNDARY=FAIL reason=workflow_missing' >&2; exit 1; }

# Both canonical mutation paths (master Staging + trusted PR preview) must invoke
# the staging-only wrapper through remote bash with pipefail. A plain
# `wp eval-file ... | tee` over SSH returns tee's status and can hide wrapper
# failure.
pipefail_calls="$(grep -F "content-hygiene-staging-only.php' --allow-root" "$workflow" | grep -F 'set -o pipefail' | wc -l | tr -d ' ')"
[[ "$pipefail_calls" -eq 2 ]] || {
  echo "MIGRATION_STATUS_BOUNDARY=FAIL reason=pipefail_call_count actual=$pipefail_calls expected=2" >&2
  exit 1
}

# Never authorize a migration because any historical/nested line contains
# MIGRATION_OK. The final Status line is the wrapper's authoritative result.
unsafe="$(grep -F "grep -q 'Status: MIGRATION_OK'" "$workflow" | wc -l | tr -d ' ')"
[[ "$unsafe" -eq 0 ]] || {
  echo "MIGRATION_STATUS_BOUNDARY=FAIL reason=unsafe_any_line_grep count=$unsafe" >&2
  exit 1
}

final_checks="$(grep -F 'tail -n 1' "$workflow" | grep -F 'Status: MIGRATION_OK' | wc -l | tr -d ' ')"
[[ "$final_checks" -ge 2 ]] || {
  echo "MIGRATION_STATUS_BOUNDARY=FAIL reason=final_status_checks actual=$final_checks expected_min=2" >&2
  exit 1
}

# Behavioral demonstration of the historical false positive: an inner PASS
# followed by an outer FAIL must be rejected by the canonical final-line rule.
fixture="$(mktemp)"
trap 'rm -f "$fixture"' EXIT
printf '%s\n' 'Status: MIGRATION_OK' 'H1_SEED_RECONCILIATION=FAIL reason=apply' 'Status: MIGRATION_FAIL' > "$fixture"
grep -q 'Status: MIGRATION_OK' "$fixture" # historical predicate would pass
last_status="$(grep '^Status: ' "$fixture" | tail -n 1)"
[[ "$last_status" == 'Status: MIGRATION_FAIL' ]]
[[ "$last_status" != 'Status: MIGRATION_OK' ]]

echo 'MIGRATION_STATUS_BOUNDARY=PASS mutation_paths=2 exit=pipefail status=final-line historical_false_positive=blocked'

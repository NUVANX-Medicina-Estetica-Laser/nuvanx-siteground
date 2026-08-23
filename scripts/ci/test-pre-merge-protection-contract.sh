#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
GATE="$ROOT/scripts/ci/pre-merge-protection.sh"

fail() {
  echo "PRE_MERGE_PROTECTION_CONTRACT=FAIL reason=$1" >&2
  exit 1
}

[[ -s "$GATE" ]] || fail 'gate_missing'
bash -n "$GATE" || fail 'gate_shell_syntax'

grep -Fq 'cd "$PROJECT_ROOT"' "$GATE" || fail 'project_root_not_enforced'
grep -Fq 'if output=$("$@" 2>&1); then' "$GATE" || fail 'argv_execution_contract_missing'
grep -Fq 'run_check "$check_name" bash -o pipefail -c "$script"' "$GATE" || fail 'shell_pipeline_wrapper_missing'
grep -Fq 'merge|promotion)' "$GATE" || fail 'environment_allowlist_missing'
grep -Fq 'THEME_DIR="$PROJECT_ROOT/wp-content/themes/nuvanx-medical"' "$GATE" || fail 'theme_root_missing'
grep -Fq 'gitleaks dir . --no-banner --redact --exit-code 1' "$GATE" || fail 'gitleaks_current_tree_scanner_missing'
if grep -Fq 'gitleaks detect --source .' "$GATE"; then
  fail 'gitleaks_history_scope_forbidden'
fi

if grep -Eq 'run_check[[:space:]]+"[^"]+"[[:space:]]+"[^"[:space:]]+[[:space:]][^"]+"' "$GATE"; then
  fail 'quoted_command_string_reintroduced'
fi
if grep -Eq 'run_check[^\n]*\|[[:space:]]*run_check' "$GATE"; then
  fail 'concatenated_run_check_pipeline'
fi
if grep -Fq 'if \"$@\"' "$GATE"; then
  fail 'literal_argv_execution_reintroduced'
fi

echo 'PRE_MERGE_PROTECTION_CONTRACT=PASS argv=direct shell_pipeline=explicit root=canonical secrets=current_tree_high_signal'

#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
GATE="$ROOT/scripts/ci/pre-merge-protection.sh"
PRODUCTION_WORKFLOW="$ROOT/.github/workflows/production.yml"
DEPLOY="$ROOT/tools/deploy/deploy-to-prod.sh"
MIGRATIONS_DIR="$ROOT/tools/migrations"

fail() {
  echo "PRE_MERGE_PROTECTION_CONTRACT=FAIL reason=$1" >&2
  exit 1
}

[[ -s "$GATE" ]] || fail 'gate_missing'
[[ -s "$PRODUCTION_WORKFLOW" ]] || fail 'production_workflow_missing'
[[ -s "$DEPLOY" ]] || fail 'production_deploy_missing'
[[ -d "$MIGRATIONS_DIR" ]] || fail 'migrations_dir_missing'
bash -n "$GATE" || fail 'gate_shell_syntax'
bash -n "$DEPLOY" || fail 'production_deploy_shell_syntax'

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

# The production deploy script owns the exact migration set. The workflow must
# expose one matching inventory inside the canonical upload step so accepted
# tooling cannot drift between CI payload validation and host-side execution.
assignment_count="$(grep -Ec '^[[:space:]]*required_migrations=\(' "$PRODUCTION_WORKFLOW" || true)"
[[ "$assignment_count" == '1' ]] || fail "production_required_migrations_assignment_count:$assignment_count"

upload_step="$(
  awk '
    /^[[:space:]]*- name: Upload exact accepted payload and tooling[[:space:]]*$/ {
      in_step = 1
      print
      next
    }
    in_step && /^[[:space:]]*- name:[[:space:]]/ { exit }
    in_step { print }
  ' "$PRODUCTION_WORKFLOW"
)"
[[ -n "$upload_step" ]] || fail 'production_upload_step_missing'

step_assignment_count="$(printf '%s\n' "$upload_step" | grep -Ec '^[[:space:]]*required_migrations=\(' || true)"
[[ "$step_assignment_count" == '1' ]] || fail "production_upload_required_migrations_assignment_count:$step_assignment_count"
consumer_count="$(printf '%s\n' "$upload_step" | grep -Fc 'for migration in "${required_migrations[@]}"; do' || true)"
(( consumer_count > 0 )) || fail 'production_upload_required_migrations_not_consumed'

required_migrations=()
while IFS= read -r line; do
  [[ -n "$line" ]] && required_migrations+=("$line")
done < <(
  printf '%s\n' "$upload_step" | awk '
    /^[[:space:]]*required_migrations=\(/ { in_required = 1; next }
    in_required && /^[[:space:]]*\)/ { exit }
    in_required {
      value = $0
      gsub(/[[:space:]]/, "", value)
      if ( value != "" ) print value
    }
  '
)
(( ${#required_migrations[@]} > 0 )) || fail 'production_required_migrations_empty'

# Derive the owner set from deploy-to-prod.sh itself: every migration assigned
# from $candidate_dir to a *_SCRIPT variable must also be guarded by -f.
deploy_pairs=()
while IFS= read -r line; do
  [[ -n "$line" ]] && deploy_pairs+=("$line")
done < <(
  sed -nE 's/^[[:space:]]*([A-Z0-9_]+_SCRIPT)="\$candidate_dir\/([^"[:space:]]+\.php)"[[:space:]]*$/\1|\2/p' "$DEPLOY"
)
(( ${#deploy_pairs[@]} > 0 )) || fail 'production_deploy_migration_assignments_empty'

deploy_vars=()
deploy_migrations=()
for pair in "${deploy_pairs[@]}"; do
  var="${pair%%|*}"
  migration="${pair#*|}"
  [[ "$var" =~ ^[A-Z0-9_]+_SCRIPT$ ]] || fail "production_deploy_migration_variable_invalid:$var"
  [[ "$migration" =~ ^[A-Za-z0-9._-]+\.php$ ]] || fail "production_deploy_migration_invalid:$migration"
  guard="[[ -f \"\$$var\" ]]"
  grep -Fq "$guard" "$DEPLOY" || fail "production_deploy_migration_guard_missing:$var"
  deploy_vars+=( "$var" )
  deploy_migrations+=( "$migration" )
done

required_unique_count="$(printf '%s\n' "${required_migrations[@]}" | LC_ALL=C sort -u | wc -l | tr -d '[:space:]')"
deploy_var_unique_count="$(printf '%s\n' "${deploy_vars[@]}" | LC_ALL=C sort -u | wc -l | tr -d '[:space:]')"
deploy_migration_unique_count="$(printf '%s\n' "${deploy_migrations[@]}" | LC_ALL=C sort -u | wc -l | tr -d '[:space:]')"
[[ "$required_unique_count" == "${#required_migrations[@]}" ]] || fail 'production_required_migrations_duplicate'
[[ "$deploy_var_unique_count" == "${#deploy_vars[@]}" ]] || fail 'production_deploy_migration_variable_duplicate'
[[ "$deploy_migration_unique_count" == "${#deploy_migrations[@]}" ]] || fail 'production_deploy_migration_duplicate'

required_set="$(printf '%s\n' "${required_migrations[@]}" | LC_ALL=C sort)"
deploy_set="$(printf '%s\n' "${deploy_migrations[@]}" | LC_ALL=C sort)"
[[ "$required_set" == "$deploy_set" ]] || fail 'production_migration_inventory_owner_drift'

for migration in "${deploy_migrations[@]}"; do
  [[ -f "$MIGRATIONS_DIR/$migration" && ! -L "$MIGRATIONS_DIR/$migration" && -s "$MIGRATIONS_DIR/$migration" ]] || fail "production_required_migration_missing:$migration"
done

echo "PRE_MERGE_PROTECTION_CONTRACT=PASS argv=direct shell_pipeline=explicit root=canonical secrets=current_tree_high_signal required_migrations=${#required_migrations[@]} deploy_migrations=${#deploy_migrations[@]}"

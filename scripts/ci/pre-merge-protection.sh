#!/usr/bin/env bash
#
# Pre-Merge/Promotion Protection Gate
#
# Required minimum checks before merging to master or promoting to production.
# Without all greens → no merge/promotion.
#
# Usage: ./scripts/ci/pre-merge-protection.sh [environment]
# environment: "merge" (to master) or "promotion" (to production)
#

set -Eeuo pipefail

ENVIRONMENT="${1:-merge}"
PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
THEME_DIR="$PROJECT_ROOT/wp-content/themes/nuvanx-medical"

case "$ENVIRONMENT" in
  merge|promotion) ;;
  *)
    printf 'Invalid environment: %s (expected merge|promotion)\n' "$ENVIRONMENT" >&2
    exit 2
    ;;
esac

cd "$PROJECT_ROOT"

echo "=== PRE-MERGE/PROMOTION PROTECTION ==="
echo "Environment: ${ENVIRONMENT}"
echo "Project root: ${PROJECT_ROOT}"
echo ""

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

OVERALL_STATUS=0
CHECKS_PASSED=0
CHECKS_FAILED=0

run_check() {
  local check_name="$1"
  shift
  local output=''
  local status=0

  echo "Running: ${check_name}..."

  if output=$("$@" 2>&1); then
    echo -e "${GREEN}✓${NC} ${check_name}: PASS"
    CHECKS_PASSED=$((CHECKS_PASSED + 1))
    return 0
  else
    status=$?
    echo -e "${RED}✗${NC} ${check_name}: FAIL (exit ${status})"
    [[ -z "$output" ]] || printf '%s\n' "$output" >&2
    CHECKS_FAILED=$((CHECKS_FAILED + 1))
    OVERALL_STATUS=1
    return 0
  fi
}

run_shell_check() {
  local check_name="$1"
  local script="$2"
  run_check "$check_name" bash -o pipefail -c "$script"
}

scan_high_signal_secrets() {
  if command -v gitleaks >/dev/null 2>&1; then
    gitleaks dir . --no-banner --redact --exit-code 1
    return
  fi

  local pattern='(AKIA[0-9A-Z]{16}|ASIA[0-9A-Z]{16}|gh[pousr]_[A-Za-z0-9_]{30,}|github_pat_[A-Za-z0-9_]{30,}|sk-(proj-)?[A-Za-z0-9_-]{20,}|-----BEGIN (RSA|OPENSSH|EC|DSA) PRIVATE KEY-----)'
  if git grep -nEI "$pattern" -- ':!*.lock' ':!vendor/**' ':!node_modules/**'; then
    return 1
  fi
  return 0
}

# === 1. Syntax Checks ===
echo "=== SYNTAX CHECKS ==="
run_check "PHP Syntax (functions.php)" php -l "$THEME_DIR/functions.php"
run_shell_check "PHP Syntax (all theme files)" "find '$THEME_DIR' -path '*/vendor' -prune -o -name '*.php' -type f -print0 | xargs -0 -r -n1 php -l"
run_shell_check "Node syntax (theme scripts)" "find '$THEME_DIR' -path '*/node_modules' -prune -o -name '*.js' -type f -print0 | xargs -0 -r -n1 node --check"

# === 2. PHPCS ===
echo ""
echo "=== PHPCS ==="
if [[ -f "$THEME_DIR/phpcs.xml.dist" || -f "$THEME_DIR/phpcs.xml" ]]; then
  PHPCS_BIN="$THEME_DIR/vendor/bin/phpcs"
  if [[ -x "$PHPCS_BIN" ]]; then
    run_check "PHPCS Code Style" "$PHPCS_BIN" --standard="$THEME_DIR/phpcs.xml.dist" "$THEME_DIR"
  elif command -v phpcs >/dev/null 2>&1; then
    run_check "PHPCS Code Style" phpcs --standard="$THEME_DIR/phpcs.xml.dist" "$THEME_DIR"
  else
    echo -e "${RED}✗${NC} PHPCS: FAIL (configured but binary unavailable)"
    CHECKS_FAILED=$((CHECKS_FAILED + 1))
    OVERALL_STATUS=1
  fi
else
  echo -e "${YELLOW}⊘${NC} PHPCS: SKIP (no theme PHPCS config found)"
fi

# === 3. PHPStan ===
echo ""
echo "=== PHPSTAN ==="
if [[ -f "$THEME_DIR/phpstan.neon" || -f "$THEME_DIR/phpstan.neon.dist" ]]; then
  PHPSTAN_BIN="$THEME_DIR/vendor/bin/phpstan"
  if [[ -x "$PHPSTAN_BIN" ]]; then
    run_check "PHPStan Static Analysis" "$PHPSTAN_BIN" analyse --configuration="$THEME_DIR/phpstan.neon" --memory-limit=2G
  elif command -v phpstan >/dev/null 2>&1; then
    run_check "PHPStan Static Analysis" phpstan analyse --configuration="$THEME_DIR/phpstan.neon" --memory-limit=2G
  else
    echo -e "${RED}✗${NC} PHPStan: FAIL (configured but binary unavailable)"
    CHECKS_FAILED=$((CHECKS_FAILED + 1))
    OVERALL_STATUS=1
  fi
else
  echo -e "${YELLOW}⊘${NC} PHPStan: SKIP (no theme PHPStan config found)"
fi

# === 4. Security/Secrets ===
echo ""
echo "=== SECURITY/SECRETS ==="
run_check "No high-signal secrets in tracked code" scan_high_signal_secrets

# === 5. Theme Hygiene ===
echo ""
echo "=== THEME HYGIENE ==="
if [[ -f "tools/migrations/diagnose-jsonld-storage.php" && $(command -v wp || true) ]]; then
  run_check "Theme Hygiene" wp eval-file "$PROJECT_ROOT/tools/migrations/diagnose-jsonld-storage.php" --allow-root
else
  echo -e "${YELLOW}⊘${NC} Theme Hygiene: SKIP (WP-CLI or diagnose script unavailable)"
fi

# === 6. Data/Catalog Validation ===
echo ""
echo "=== DATA/CATALOG VALIDATION ==="
run_check "Catalog JSON validation" jq empty "$THEME_DIR/inc/data/treatments-catalog.json"
run_check "SEO Metadata validation" jq empty "$THEME_DIR/inc/data/seo-metadata.json"

# === 7. Publication Topology ===
echo ""
echo "=== PUBLICATION TOPOLOGY ==="
if [[ -f "tools/migrations/generate-publication-manifest.php" && $(command -v wp || true) ]]; then
  run_check "Publication Manifest" wp eval-file "$PROJECT_ROOT/tools/migrations/generate-publication-manifest.php" --allow-root
else
  echo -e "${YELLOW}⊘${NC} Publication Manifest: SKIP (WP-CLI or generation script unavailable)"
fi

# === 8. SEO/Schema ===
echo ""
echo "=== SEO/SCHEMA ==="
run_check "Schema validation" jq empty "$THEME_DIR/inc/data/treatment-hub-schema.json"
run_check "Aesthetic treatment schema" jq empty "$THEME_DIR/inc/data/aesthetic-treatment-pages.json"

# === 9. Block C ===
echo ""
echo "=== BLOCK C ==="
if [[ -f "scripts/staging2/block-c-origin-browser-fallback.mjs" ]]; then
  run_check "Block C contract" node scripts/staging2/block-c-origin-browser-fallback.mjs
else
  echo -e "${YELLOW}⊘${NC} Block C: SKIP (contract not found)"
fi

# === 10. HubSpot ===
echo ""
echo "=== HUBSPOT ==="
if [[ -f "scripts/staging2/test-functional-consent-host-contract.mjs" ]]; then
  run_check "Functional consent host marker" node scripts/staging2/test-functional-consent-host-contract.mjs
fi
if [[ -f "scripts/staging2/test-hubspot-specific-gate.mjs" ]]; then
  if command -v agent-browser >/dev/null 2>&1; then
    run_check "HubSpot specific gate" node scripts/staging2/test-hubspot-specific-gate.mjs
  else
    echo -e "${YELLOW}⊘${NC} HubSpot: SKIP (agent-browser not installed)"
  fi
else
  echo -e "${YELLOW}⊘${NC} HubSpot: SKIP (gate not found)"
fi

# === 11. Visual States ===
echo ""
echo "=== VISUAL STATES ==="
if [[ -f "scripts/staging2/visual-qa-by-state.mjs" ]]; then
  if command -v agent-browser >/dev/null 2>&1; then
    run_check "Visual QA by state" node scripts/staging2/visual-qa-by-state.mjs
  else
    echo -e "${YELLOW}⊘${NC} Visual QA: SKIP (agent-browser not installed)"
  fi
else
  echo -e "${YELLOW}⊘${NC} Visual QA: SKIP (script not found)"
fi

# === 12. Production Parity (only for promotion) ===
echo ""
echo "=== PRODUCTION PARITY ==="
if [[ "$ENVIRONMENT" == "promotion" ]]; then
  if [[ -f "scripts/production/verify-production-identity.mjs" ]]; then
    run_check "Production identity verification" node scripts/production/verify-production-identity.mjs
  else
    echo -e "${YELLOW}⊘${NC} Production Parity: SKIP (verification script not found)"
  fi
else
  echo -e "${YELLOW}⊘${NC} Production Parity: SKIP (not promotion environment)"
fi

echo ""
echo "=== SUMMARY ==="
echo "Checks passed: ${CHECKS_PASSED}"
echo "Checks failed: ${CHECKS_FAILED}"
echo ""

if [[ ${OVERALL_STATUS} -eq 0 ]]; then
  echo -e "${GREEN}✓${NC} ALL CHECKS PASSED"
  echo "Pre-merge/promotion protection: PASSED"
  exit 0
fi

echo -e "${RED}✗${NC} SOME CHECKS FAILED"
echo "Pre-merge/promotion protection: FAILED"
echo "Without all greens → no merge/promotion"
exit 1

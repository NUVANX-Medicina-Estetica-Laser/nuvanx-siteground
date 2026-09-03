import assert from 'node:assert/strict';
import fs from 'node:fs';

const root = 'wp-content/themes/nuvanx-medical/';
const read = (path) => {
  assert.equal(fs.existsSync(path), true, `Missing file: ${path}`);
  return fs.readFileSync(path, 'utf8');
};

const strategy = read(`${root}inc/nvx-strategy-pages.php`);
const aesthetic = read(`${root}inc/nvx-aesthetic-treatment-pages.php`);
const journal = read(`${root}inc/nvx-journal-laserlipolisis-vs-lipo.php`);
const catalog = read(`${root}inc/nvx-catalog-json.php`);
const orchestrator = read('tools/migrations/content-hygiene-staging-only.php');
const helper = read('tools/migrations/h1-content-seed-reconciliation.php');
const core = read('tools/migrations/content-hygiene-staging-core.php');

const forbiddenRuntimeSymbols = [
  [strategy, 'nvx_strategy_seed_staging2_pages'],
  [aesthetic, 'nvx_aesthetic_treatment_seed_pages'],
  [journal, 'nvx_journal_tech_article_seed_staging2'],
  [catalog, 'nvx_catalog_retire_unapproved_bridal_seed'],
];

for (const [source, symbol] of forbiddenRuntimeSymbols) {
  assert.equal(source.includes(symbol), false, `Runtime mutation owner still present: ${symbol}`);
}
for (const source of [strategy, aesthetic, journal, catalog]) {
  assert.doesNotMatch(source, /add_action\s*\(\s*['"]init['"][\s\S]{0,200}(?:seed|retire)/i);
}

assert.equal(fs.existsSync('tools/migrations/reconcile-staging-content-seeds.php'), false, 'Orphan standalone H1 migration must remain retired');
assert.match(orchestrator, /content-hygiene-staging-core\.php/);
assert.match(orchestrator, /nvx_h1_build_plan\s*\(/);
assert.match(orchestrator, /nvx_h1_apply_plan\s*\(/);
assert.match(orchestrator, /H1_SEED_PLAN=PASS/);
assert.match(orchestrator, /H1_SEED_RECONCILIATION=PASS/);
assert.match(orchestrator, /H1_SEED_RECONCILIATION=NOOP/);
assert.match(orchestrator, /H1_SEED_RECONCILIATION=FAIL/);
assert.match(orchestrator, /\/wp-content\/\.nuvanx-deployments\//, 'Canonical release path must own implicit live mode');
assert.match(orchestrator, /Ad-hoc\/manual executions default to dry-run/);

// The outer orchestrator is the sole owner of the public `Status:` contract.
// Child output must be streamed through the wrapper so historical Status lines
// are fenced without losing diagnostics during a hang or forced termination.
assert.doesNotMatch(orchestrator, /\b(?:passthru|system|exec)\s*\(/,
  'Nested core output must never use a direct-output or fully buffered executor');
assert.match(orchestrator, /popen\s*\(\s*\$core_command\s*\.\s*' 2>&1'\s*,\s*'r'\s*\)/,
  'Wrapper must open a streaming child process');
assert.match(orchestrator, /fgets\s*\(\s*\$core_handle\s*\)/,
  'Wrapper must stream child diagnostics line by line');
assert.match(orchestrator, /pclose\s*\(\s*\$core_handle\s*\)/,
  'Wrapper must retain the real child exit status');
assert.match(orchestrator, /str_starts_with\(\s*\$core_line,\s*'Status: '\s*\)/,
  'Wrapper must identify nested child Status lines');
assert.match(orchestrator, /H1_HYGIENE_CORE_STATUS=%s/,
  'Nested child status must be re-labeled as diagnostic output');
assert.match(orchestrator, /hygiene_core_start/,
  'Child process startup failure must fail closed');
assert.match(orchestrator, /if \( 0 !== \$core_status \)/,
  'Child non-zero exit must fail the outer migration');

// The historical core may normalize legacy seed content and excerpts, but it
// must never write the H1 provenance/review metadata. Those values are planned,
// applied and verified only by h1-content-seed-reconciliation.php in the outer
// owner, avoiding cross-process cache/write races.
assert.doesNotMatch(
  core,
  /update_post_meta\s*\(\s*\$pid\s*,\s*'_nvx_aesthetic_treatment_key'/,
  'Legacy hygiene core must not write aesthetic treatment provenance meta',
);
assert.doesNotMatch(
  core,
  /update_post_meta\s*\(\s*\$pid\s*,\s*'_nvx_medical_review_status'/,
  'Legacy hygiene core must not write medical review status',
);
assert.match(core, /outer H1\s+\/\/ reconciliation transaction|outer H1[\s\S]{0,120}reconciliation transaction/,
  'Legacy core must document the canonical H1 metadata owner');

// Bridal partial provenance is repairable only in two exact, bounded states:
// stale legacy meta without marker, or exact legacy marker with an empty meta.
// A conflicting non-empty key must remain fail-closed and dry-run must not write.
assert.match(orchestrator, /function\s+nvx_h1_bridal_partial_provenance_repair\s*\(/);
assert.match(orchestrator, /'clear_stale_meta'/);
assert.match(orchestrator, /'restore_seed_meta'/);
assert.match(orchestrator, /delete_post_meta\s*\(\s*\$post_id\s*,\s*'_nvx_aesthetic_treatment_key'\s*\)/);
assert.match(orchestrator, /update_post_meta\s*\(\s*\$post_id\s*,\s*'_nvx_aesthetic_treatment_key'\s*,\s*'bridal_protocol'\s*\)/);
assert.match(orchestrator, /bridal_partial_provenance_ambiguous/);
assert.match(orchestrator, /H1_BRIDAL_PROVENANCE_REPAIR=%s action=%s writes=%d/);
assert.match(orchestrator, /\$nvx_dry_run\s*\?\s*0\s*:\s*1/);
assert.match(orchestrator, /!\s*empty\s*\(\s*\$nvx_other_errors\s*\)/, 'Unrelated H1 errors must block before Bridal repair');
assert.match(orchestrator, /\$nvx_h1_plan\s*=\s*nvx_h1_build_plan\s*\(\s*\)\s*;/, 'Plan must be rebuilt after live Bridal repair');
assert.match(core, /Status: MIGRATION_OK/, 'Historical hygiene core may keep its internal terminal contract because the wrapper now fences it');

assert.match(helper, /nvx_medical_review_record/);
assert.match(helper, /approved_review_provenance_changed/);
assert.match(helper, /provenance_mismatch/);
assert.match(helper, /START TRANSACTION/);
assert.match(helper, /ROLLBACK/);
assert.match(helper, /COMMIT/);
assert.match(helper, /update_post_meta\s*\(/);
assert.match(helper, /get_post_meta\s*\(/);
assert.match(helper, /wp_insert_post\s*\(/);
assert.match(helper, /wp_update_post\s*\(/);

console.log('RUNTIME_SEED_BOUNDARY=PASS runtime_mutators=0 canonical_owner=content-hygiene-staging-only child_status=fenced streaming=required direct_output=forbidden prevalidate_all=1 h1_meta_owner=single bridal_partial_provenance=bounded_fail_closed approvals=preserved');

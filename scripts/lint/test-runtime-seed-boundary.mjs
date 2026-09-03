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

assert.doesNotMatch(core, /update_post_meta\s*\(\s*\$pid\s*,\s*'_nvx_aesthetic_treatment_key'/,
  'Legacy hygiene core must not write aesthetic treatment provenance meta');
assert.doesNotMatch(core, /update_post_meta\s*\(\s*\$pid\s*,\s*'_nvx_medical_review_status'/,
  'Legacy hygiene core must not write medical review status');
assert.match(core, /outer H1\s+\/\/ reconciliation transaction|outer H1[\s\S]{0,120}reconciliation transaction/,
  'Legacy core must document the canonical H1 metadata owner');

assert.match(helper, /\$wpdb->postmeta/, 'H1 metadata verification must inspect durable postmeta storage');
assert.match(helper, /post_meta_durable_value_missing/);
assert.match(helper, /post_meta_durable_verification_failed/);
assert.match(helper, /function\s+nvx_h1_verify_meta_after_commit\s*\(\s*int\s+\$post_id,\s*string\s+\$meta_key,\s*string\s+\$expected\s*\)/,
  'H1 must verify each metadata value after COMMIT through one explicit boundary');
assert.match(helper, /post_meta_postcommit_durable_value_missing_/,
  'Post-commit verification must distinguish missing committed storage');
assert.match(helper, /post_meta_postcommit_durable_verification_failed_/,
  'Post-commit verification must distinguish committed durable mismatch');
assert.match(helper, /wp_cache_delete\s*\(\s*\$post_id\s*,\s*'post_meta'\s*\)/,
  'Post-commit verification must invalidate the exact post_meta cache key');
assert.match(helper, /clean_post_cache\s*\(\s*\$post_id\s*\)/,
  'Post-commit verification must invalidate the exact post object cache');
assert.match(helper, /function\s+nvx_h1_verify_runtime_plan\s*\(\s*array\s+\$plan,\s*array\s+\$created_ids\s*\)/,
  'H1 must have a dedicated post-commit runtime verifier with exact inserted IDs');
assert.match(helper, /nvx_h1_verify_meta_after_commit\s*\(\s*\$post_id,\s*'_nvx_aesthetic_treatment_key'/,
  'Aesthetic provenance must pass the post-commit durable/cache/runtime boundary');
assert.match(helper, /nvx_h1_verify_meta_after_commit\s*\(\s*\$post_id,\s*'_nvx_medical_review_status'/,
  'Medical review status must pass the post-commit durable/cache/runtime boundary');
assert.match(helper, /\$created_ids\s*=\s*array\s*\(\s*\)/,
  'Apply owner must track IDs returned by successful inserts');
assert.match(helper, /\$created_ids\s*\[\s*\$scope\s*\.\s*'\|'\s*\.\s*\$slug\s*\]\s*=\s*\(int\)\s*\$result/,
  'Created strategy/aesthetic seeds must carry the exact wp_insert_post ID');
assert.match(helper, /\$created_ids\s*\[\s*\$scope\s*\.\s*'\|'\s*\.\s*\$slug\s*\]\s*\?\?\s*0/,
  'Post-commit verification must consume the exact inserted ID, not re-resolve by slug');
assert.match(helper, /post_meta_postcommit_runtime_verification_failed_/,
  'Post-commit verification must distinguish a stale WordPress runtime view');
assert.match(helper, /function\s+nvx_h1_invalidate_post_cache\s*\(\s*int\s+\$post_id\s*\)/,
  'H1 must provide bounded post and query cache invalidation');
assert.match(helper, /wp_cache_delete\s*\(\s*'last_changed',\s*'posts'\s*\)/,
  'Post-commit verification must invalidate post-query cache via last_changed');
assert.match(helper, /journal_postcommit_runtime_verification_failed/,
  'Post-commit verification must verify journal creation at runtime');
assert.match(helper, /bridal_postcommit_runtime_verification_failed/,
  'Post-commit verification must verify bridal retirement at runtime');
const commitOffset = helper.indexOf("$wpdb->query( 'COMMIT' )");
const runtimeVerifyOffset = helper.indexOf('nvx_h1_verify_runtime_plan( $plan, $created_ids )');
assert.ok(commitOffset >= 0 && runtimeVerifyOffset > commitOffset,
  'Runtime metadata verification must execute only after COMMIT');
assert.match(helper, /\$committed\s*=\s*false/,
  'Apply owner must track whether the transaction has committed');
assert.match(helper, /if \( ! \$committed \) \{\s*\$wpdb->query\( 'ROLLBACK' \);/,
  'A post-commit runtime failure must not pretend the committed transaction can be rolled back');

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

console.log('RUNTIME_SEED_BOUNDARY=PASS runtime_mutators=0 canonical_owner=content-hygiene-staging-only child_status=fenced streaming=required direct_output=forbidden prevalidate_all=1 h1_meta_owner=single meta_verification=durable-in-tx+durable-postcommit+targeted-cache+runtime-postcommit created_ids=exact bridal_partial_provenance=bounded_fail_closed approvals=preserved');

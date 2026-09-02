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
assert.match(core, /Status: MIGRATION_OK/, 'Historical hygiene core must preserve its terminal contract');

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

console.log('RUNTIME_SEED_BOUNDARY=PASS runtime_mutators=0 canonical_owner=content-hygiene-staging-only prevalidate_all=1 transactional_apply=1 approvals=preserved');

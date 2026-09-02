import { execFileSync } from 'node:child_process';
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
const migration = read('tools/migrations/reconcile-staging-content-seeds.php');

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
  assert.doesNotMatch(
    source,
    /add_action\s*\(\s*['"]init['"][\s\S]{0,200}(?:seed|retire)/i,
    'Seed/retirement mutation must not run on init',
  );
}

assert.doesNotMatch(aesthetic, /staging-only page seeder/i, 'Aesthetic pages doc must not reference staging-only page seeder');
assert.doesNotMatch(aesthetic, /Seed governed pages/i, 'Aesthetic pages must not contain stale seed comments');

assert.doesNotMatch(migration, /declare\s*\(\s*strict_types\s*=\s*1\s*\)/, 'wp eval-file migration must not declare strict_types');
assert.match(migration, /defined\s*\(\s*['"]WP_CLI['"]\s*\)/, 'Migration must be WP-CLI-only');
assert.match(migration, /['"]dbshcocboodiwr['"]/, 'Migration must pin the canonical Staging2 database');
assert.match(migration, /['"]https:\/\/staging2\.nuvanx\.com['"]/, 'Migration must pin canonical Staging2 URLs');
assert.match(migration, /defined\s*\(\s*['"]NVX_ENV['"]\s*\)/, 'Migration must verify NVX_ENV');
assert.match(migration, /MIGRATION_DRY_RUN/, 'Migration must expose the canonical dry-run control');
assert.match(migration, /['"]0['"]\s*!==\s*getenv\s*\(\s*['"]MIGRATION_DRY_RUN['"]\s*\)/, 'Migration must default fail-safe to dry-run');
assert.match(migration, /existing_editorial/, 'Migration must preserve existing editorial pages');
assert.match(migration, /provenance_mismatch/, 'Bridal retirement must fail closed on mixed provenance');
assert.match(migration, /wp_insert_post\s*\(/, 'Explicit migration must own seed creation');
assert.match(migration, /wp_update_post\s*\(/, 'Explicit migration must own Bridal retirement');
assert.match(migration, /wp_delete_post\s*\(/, 'Explicit migration must roll back inserted posts on metadata write failure');
assert.match(migration, /nvx_h1_has_valid_medical_approval/, 'Migration must detect valid medical approval');
assert.match(migration, /nvx_h1_update_post_meta_checked/, 'Migration must check update_post_meta return value');
assert.match(migration, /post_write_verification/, 'Every live mutation path must expose post-write verification failure');
assert.match(migration, /H1_SEED_RECONCILIATION=PASS/, 'Migration must emit PASS');
assert.match(migration, /H1_SEED_RECONCILIATION=NOOP/, 'Migration must emit NOOP');
assert.match(migration, /H1_SEED_RECONCILIATION=FAIL/, 'Migration must emit FAIL');

execFileSync('php', ['scripts/lint/test-staging-seed-reconciliation.php'], { stdio: 'inherit' });

console.log('RUNTIME_SEED_BOUNDARY=PASS runtime_mutators=0 explicit_migration=1 dry_run_default=1 approval_preserved=1');

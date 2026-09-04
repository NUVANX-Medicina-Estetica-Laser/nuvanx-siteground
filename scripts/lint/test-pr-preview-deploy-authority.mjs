import assert from 'node:assert/strict';
import fs from 'node:fs';

const deployPath = 'tools/deploy/deploy-to-staging2.sh';
const source = fs.readFileSync(deployPath, 'utf8');

assert.match(source, /function|verify_pr_preview_authority_if_applicable\(\)/, 'trusted deployer must own PR authority validation');
assert.match(source, /\^pr-\(\[0-9\]\+\)-\(\[0-9a-f\]\{40\}\)-\(\[0-9\]\+\)-\(\[0-9\]\+\)\$/, 'preview release path must bind PR, preview SHA, run id and attempt');
assert.match(source, /actions\/runs\/\$run_id/, 'deployer must recover the immutable workflow-run head');
assert.match(source, /pulls\/\$pr_number/, 'deployer must re-read current PR authority');
assert.match(source, /run_event.*pull_request_target/s, 'only pull_request_target runs may exercise preview authority');
assert.match(source, /current_pr_sha.*run_head_sha/s, 'current PR head must still equal the triggering run head');
assert.match(source, /base_ref.*master/s, 'preview PR must still target master');
assert.match(source, /MUTATION_FIFO=SUPERSEDED[\s\S]*stage=deploy-boundary[\s\S]*mutation=forbidden/, 'superseded previews must fail closed at deploy boundary');
assert.doesNotMatch(source, /Authorization:|GH_TOKEN|GITHUB_TOKEN/, 'SiteGround authority check must not receive a GitHub credential');

const authorityCall = source.indexOf('\nverify_pr_preview_authority_if_applicable\n');
const configBackup = source.indexOf('\nCONFIG_BACKUP="$(mktemp)"');
const hubspotMutation = source.indexOf('\nprovision_staging_hubspot_identity\n');
const backupMutation = source.indexOf('\nmkdir -p "$BACKUP_DIR"');
const liveMutation = source.indexOf('\nMUTATION_STARTED=1');
const liveRsync = source.indexOf('\nrsync -a --delete');

assert.ok(authorityCall >= 0, 'deploy authority call must be discoverable');
for (const [name, offset] of [
  ['wp-config backup boundary', configBackup],
  ['HubSpot config mutation', hubspotMutation],
  ['live backup creation', backupMutation],
  ['mutation arm', liveMutation],
  ['live theme rsync', liveRsync],
]) {
  assert.ok(offset > authorityCall, `${name} must occur only after final PR authority validation`);
}

const materialize = source.indexOf('SOURCE_DATE_EPOCH=0 php "$SOURCE_THEME/tools/compile-theme-css.php"');
assert.ok(materialize >= 0 && materialize < authorityCall, 'candidate-only CSS materialization may precede authority validation because it does not mutate live Staging2');

console.log('PR_PREVIEW_DEPLOY_AUTHORITY=PASS owner=trusted-deployer github_secret=none run_binding=1 current_pr_binding=1 pre_live_mutation=1 fail_closed=1');

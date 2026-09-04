import assert from 'node:assert/strict';
import fs from 'node:fs';

const deployPath = 'tools/deploy/deploy-to-staging2.sh';
const workflowPath = '.github/workflows/staging.yml';
const source = fs.readFileSync(deployPath, 'utf8');
const workflow = fs.readFileSync(workflowPath, 'utf8');

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

// Authority cannot be proven during a GitHub transport/API outage, so the
// trusted mutator must remain fail-closed without misclassifying infrastructure
// as a candidate defect. Only a valid API response may establish supersession.
assert.match(source, /PR_PREVIEW_AUTHORITY=TRANSIENT/, 'authority transport/API failures need an explicit transient classification');
assert.match(source, /exit\s+75/, 'transient authority failures must use the repository transient exit code');
assert.match(source, /403[|, )].*408[|, )].*425[|, )].*429[|, )].*500[|, )].*502[|, )].*503[|, )].*504/s, 'temporary GitHub API statuses must be classified as transient');
assert.doesNotMatch(source, /\|\|\s*fail\s+'Unable to verify (?:PR preview workflow-run|current PR preview) authority from GitHub'/, 'raw GitHub request failures must not collapse into candidate failure');

// The workflow snapshot is intentionally created before the trusted deployer,
// but a pre-live 75/78 must not arm the full snapshot rollback. Otherwise a
// superseded preview can import an older DB snapshot despite never mutating
// live Staging2. Capture the deploy result and explicitly disarm those two
// pre-live classifications before the step exits.
const previewStepStart = workflow.indexOf('- name: Snapshot and deploy PR candidate with trusted tooling');
const previewStepEnd = workflow.indexOf('\n      - name: Verify PR preview boundary and browser acceptance', previewStepStart);
assert.ok(previewStepStart >= 0 && previewStepEnd > previewStepStart, 'PR preview deploy workflow step must be discoverable');
const previewStep = workflow.slice(previewStepStart, previewStepEnd);
const deployCommand = "ssh nvx-staging2-pr \"NUVANX_CONFIRM=yes bash '$REMOTE_RELEASE/deploy-to-staging2.sh'";
const deployOffset = previewStep.indexOf(deployCommand);
assert.ok(deployOffset >= 0, 'trusted deploy invocation must be present in PR preview step');
assert.match(previewStep.slice(0, deployOffset), /STAGING_MUTATION_ARMED=1/, 'snapshot rollback must remain armed for real deploy failures');
assert.match(previewStep.slice(deployOffset), /deploy_(?:rc|result)=\$\?/, 'workflow must capture the trusted deployer exit code');
assert.match(previewStep.slice(deployOffset), /75\|78|75\s+78|75\)|78\)/, 'workflow must distinguish transient/superseded pre-live exits');
assert.match(previewStep.slice(deployOffset), /STAGING_MUTATION_ARMED=0/, 'pre-live 75/78 must disarm full snapshot rollback');
assert.match(previewStep.slice(deployOffset), /exit\s+"?\$deploy_(?:rc|result)"?/, 'workflow must preserve the original deployer classification after disarming rollback');

console.log('PR_PREVIEW_DEPLOY_AUTHORITY=PASS owner=trusted-deployer github_secret=none run_binding=1 current_pr_binding=1 pre_live_mutation=1 transient=75 superseded=78 rollback=mutation_only fail_closed=1');

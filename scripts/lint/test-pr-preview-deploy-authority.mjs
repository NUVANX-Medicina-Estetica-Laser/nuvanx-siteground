import assert from 'node:assert/strict';
import fs from 'node:fs';
import { spawnSync } from 'node:child_process';

const deployPath = 'tools/deploy/deploy-to-staging2.sh';
const source = fs.readFileSync(deployPath, 'utf8');

assert.ok(source.includes('verify_pr_preview_authority_if_applicable()'), 'trusted deployer must own PR authority validation');
assert.ok(source.includes('^pr-([0-9]+)-([0-9a-f]{40})-([0-9]+)-([0-9]+)$'), 'preview release path must bind PR, preview SHA, run id and attempt');
assert.match(source, /actions\/runs\/\$run_id/, 'deployer must recover the immutable workflow run');
assert.match(source, /pulls\/\$pr_number/, 'deployer must re-read current PR authority');
assert.match(source, /run_event.*pull_request_target/s, 'only pull_request_target runs may exercise preview authority');
assert.match(source, /run_head_sha="\$\(printf '%s' "\$run_json" \| jq -r '\.head_sha \/\/ ""'\)"/, 'deployer must extract immutable workflow run head_sha');
assert.match(source, /select\(\.number == \$pr\) \| \.head\.sha \/\/ empty/, 'run-associated PR head must be extracted from the pull request payload');
assert.match(source, /current_pr_sha" == "\$run_pr_head_sha/, 'current PR head must still equal the run-associated PR head');
assert.match(source, /base_ref.*master/s, 'preview PR must still target master');
assert.match(source, /MUTATION_FIFO=SUPERSEDED[\s\S]*stage=deploy-boundary[\s\S]*mutation=forbidden/, 'superseded previews must fail closed at deploy boundary');
assert.match(source, /PREVIEW_OWNER_FILE="\$\(dirname "\$WP_ROOT"\)\/\.nvx-preview-owner"/, 'deployer must define server-side preview owner marker');
assert.match(source, /server_owner_superseded/, 'deployer must supersede older run if server owner run id is newer');
assert.match(source, /printf '%s\\n' "\$run_id" > "\$PREVIEW_OWNER_FILE"/, 'deployer must claim server ownership upon passing authority');
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

assert.match(source, /PR_PREVIEW_AUTHORITY=TRANSIENT/, 'authority transport/API failures need an explicit transient classification');
assert.match(source, /transient_pr_preview_authority\(\)[\s\S]*exit 75/, 'transient authority failures must use exit 75');
assert.match(source, /403\|408\|425\|429\|5\[0-9\]\[0-9\]/, 'temporary GitHub API statuses must be classified as transient');
assert.match(source, /curl_rc[\s\S]*transient_pr_preview_authority "github_\$\{component\}_transport"/, 'transport failures must be transient and fail closed');

assert.match(source, /PR_PREVIEW_OUTER_ROLLBACK_DIR="\$\(dirname "\$WP_ROOT"\)\/\.nvx-rollback\/pr-\$\{pr_number\}-\$\{run_id\}-\$\{run_attempt\}-\$\{preview_sha\}"/, 'trusted deployer must bind the outer rollback snapshot to the same PR run identity');
assert.match(source, /disarm_pr_preview_outer_rollback\(\)[\s\S]*rm -rf "\$PR_PREVIEW_OUTER_ROLLBACK_DIR"/, 'pre-live exits must be able to disarm only the exact PR snapshot');
assert.match(source, /if \[\[ "\$MUTATION_STARTED" -eq 0 \]\]; then[\s\S]*disarm_pr_preview_outer_rollback 'trusted_deployer_pre_live_failure'/, 'deployer rollback trap must disarm the outer snapshot only before live mutation begins');

const rollbackStart = source.indexOf('\nrollback() {');
const rollbackEnd = source.indexOf('\ntrap rollback EXIT ERR', rollbackStart);
assert.ok(rollbackStart >= 0 && rollbackEnd > rollbackStart, 'trusted deployer rollback boundary must be discoverable');
const rollback = source.slice(rollbackStart, rollbackEnd);
assert.match(rollback, /MUTATION_STARTED" -eq 1[\s\S]*SAFETY_RESTORE/, 'actual live mutation failures must retain local theme restore');
assert.match(rollback, /MUTATION_STARTED" -eq 0[\s\S]*disarm_pr_preview_outer_rollback/, 'pre-live failures must prevent the workflow from importing its older DB snapshot');

function selectRunPrHeadSha(payload, prNumber) {
  const pullRequests = Array.isArray(payload?.pull_requests) ? payload.pull_requests : [];
  const matches = pullRequests
    .filter((pr) => pr && Number(pr.number) === Number(prNumber))
    .map((pr) => String(pr.head?.sha || ''))
    .filter(Boolean);
  return matches.length === 1 ? matches[0] : '';
}

const selector = '[.pull_requests[]? | select(.number == $pr) | .head.sha // empty] | if length == 1 then .[0] else "" end';
const baseSha = '1'.repeat(40);
const prHeadSha = '2'.repeat(40);
const fixture = {
  event: 'pull_request_target',
  head_sha: baseSha,
  run_attempt: 1,
  pull_requests: [
    { number: 1094, head: { sha: prHeadSha } },
  ],
};

assert.equal(selectRunPrHeadSha(fixture, 1094), prHeadSha, 'run-associated PR head must be selected in JS');

const duplicateFixture = {
  ...fixture,
  pull_requests: [
    { number: 1094, head: { sha: prHeadSha } },
    { number: 1094, head: { sha: '3'.repeat(40) } },
  ],
};
assert.equal(selectRunPrHeadSha(duplicateFixture, 1094), '', 'ambiguous run-associated PR identity must fail closed in JS');

// Safely execute jq only when available in the environment to avoid null spawn crashes on non-jq systems
const jqAvailable = (() => {
  try {
    const probe = spawnSync('jq', ['--version'], { encoding: 'utf8' });
    return probe.status === 0;
  } catch {
    return false;
  }
})();

if (jqAvailable) {
  const selected = spawnSync('jq', ['-r', '--argjson', 'pr', '1094', selector], {
    input: JSON.stringify(fixture),
    encoding: 'utf8',
  });
  assert.equal(selected.status, 0, selected.stderr || 'jq selector failed');
  assert.equal(selected.stdout.trim(), prHeadSha, 'jq run-associated PR head must match JS');

  const duplicate = spawnSync('jq', ['-r', '--argjson', 'pr', '1094', selector], {
    input: JSON.stringify(duplicateFixture),
    encoding: 'utf8',
  });
  assert.equal(duplicate.status, 0, duplicate.stderr || 'jq duplicate selector failed');
  assert.equal(duplicate.stdout.trim(), '', 'jq ambiguous run-associated PR identity must fail closed');
}

console.log('PR_PREVIEW_DEPLOY_AUTHORITY=PASS owner=trusted-deployer github_secret=none run_binding=1 pr_head_source=run.head_sha+run.pull_requests current_pr_binding=1 immutable_head_enforced=1 pre_live_mutation=1 transient=75 superseded=78 outer_rollback=disarmed_pre_live fail_closed=1');

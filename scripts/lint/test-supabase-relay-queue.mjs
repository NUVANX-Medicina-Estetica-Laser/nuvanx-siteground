import assert from 'node:assert/strict';
import fs from 'node:fs';
import { spawnSync } from 'node:child_process';

const queuePath = 'wp-content/themes/nuvanx-medical/inc/nvx-supabase-relay-queue.php';
const gtmPath = 'wp-content/themes/nuvanx-medical/inc/nvx-gtm-integration.php';
const integrationPath = 'wp-content/themes/nuvanx-medical/inc/nvx-attribution-integration.php';
const relayPath = 'wp-content/themes/nuvanx-medical/inc/nvx-lead-captured-relay.php';
const stagingPath = '.github/workflows/staging.yml';

for (const path of [queuePath, gtmPath, integrationPath, relayPath, stagingPath]) {
  assert.equal(fs.existsSync(path), true, `Missing relay-queue contract file: ${path}`);
}

const queue = fs.readFileSync(queuePath, 'utf8');
const gtm = fs.readFileSync(gtmPath, 'utf8');
const integration = fs.readFileSync(integrationPath, 'utf8');
const relay = fs.readFileSync(relayPath, 'utf8');
const staging = fs.readFileSync(stagingPath, 'utf8');

assert.match(gtm, /require_once __DIR__ \. '\/nvx-supabase-relay-queue\.php';/);
const queueRequire = gtm.indexOf("require_once __DIR__ . '/nvx-supabase-relay-queue.php';");
const leadRequire = gtm.indexOf("require_once __DIR__ . '/nvx-lead-captured-relay.php';");
assert.ok(queueRequire >= 0 && leadRequire > queueRequire, 'Outbox must load before the lead-captured relay');

assert.match(queue, /register_post_type\(\s*NVX_SUPABASE_RELAY_QUEUE_CPT/);
assert.match(queue, /'public'\s*=>\s*false/);
assert.match(queue, /'publicly_queryable'\s*=>\s*false/);
assert.match(queue, /'show_ui'\s*=>\s*false/);
assert.match(queue, /'show_in_rest'\s*=>\s*false/);
assert.match(queue, /NVX_SUPABASE_RELAY=.*endpoint=/);
assert.match(queue, /SUCCESS.*HTTP_4XX.*HTTP_429.*HTTP_5XX.*TRANSPORT.*QUEUED.*DRAINED.*DEAD/);
assert.match(queue, /function nvx_supabase_relay_dispatch\(/);
assert.match(queue, /wp_schedule_event\( time\(\) \+ 60, 'nvx_relay_outbox_5min'/);
assert.match(queue, /add_action\( 'shutdown', 'nvx_supabase_relay_queue_shutdown_drain' \)/);
assert.match(queue, /NVX_GOOGLE_CLICK_HMAC_CONTEXT\s*=\s*'nuvanx-google-click-attribution-hmac-key-v1'/,
  'Google click signing context must match the Supabase collector contract');
assert.match(queue, /function nvx_supabase_relay_google_click_hmac_key\(/,
  'Google click relay must derive a dedicated HMAC key');
assert.match(queue, /hash_hmac\( 'sha256', NVX_GOOGLE_CLICK_HMAC_CONTEXT, \$token \)/,
  'Derived Google click HMAC key must use the existing server-only HubSpot token as root');
assert.match(queue, /'x-nvx-timestamp'\s*=>\s*\$timestamp/,
  'Google click requests must carry replay-bounded timestamps');
assert.match(queue, /'x-nvx-signature'\s*=>\s*\$signature/,
  'Google click requests must carry HMAC signatures');
assert.match(queue, /if \( 'google_click' === \$endpoint \)/,
  'Google click sends must take the authenticated transport path');
assert.match(queue, /nvx_supabase_relay_google_click_post_signed\( \$url, \$body, \$origin, \$token \)/,
  'Google click retries must be signed at send time instead of persisting signatures');
assert.doesNotMatch(queue, /email|phone|firstname|authorization/i);

assert.match(integration, /nvx_supabase_relay_dispatch\(\s*'google_click'/);
assert.match(integration, /'timeout'\s*=>\s*3/);
assert.match(integration, /'blocking'\s*=>\s*true/);
assert.match(relay, /nvx_supabase_relay_queue_enqueue\(\s*'lead_captured'/);

assert.match(staging, /attribution-lineage-e2e\.mjs/, 'Staging must own a dedicated attribution lineage phase');

const idHarness = spawnSync('php', ['scripts/lint/test-attribution-submission-id.php'], { encoding: 'utf8' });
assert.equal(
  idHarness.status,
  0,
  `Deterministic submission_id harness failed:\n${idHarness.stdout}\n${idHarness.stderr}`,
);
assert.match(idHarness.stdout, /ATTRIBUTION_SUBMISSION_ID=PASS/);

console.log('SUPABASE_RELAY_QUEUE=PASS blocking=1 outbox=1 telemetry=1 google_click_hmac=1 submission_id=deterministic');

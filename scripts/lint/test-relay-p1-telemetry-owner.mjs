import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = (path) => fs.readFileSync(path, 'utf8');
const bootstrap = read('wp-content/themes/nuvanx-medical/inc/nvx-theme-bootstrap.php');
const policy = read('wp-content/themes/nuvanx-medical/inc/nvx-supabase-relay-queue-policy.php');
const queue = read('wp-content/themes/nuvanx-medical/inc/nvx-supabase-relay-queue.php');
const observability = read('wp-content/themes/nuvanx-medical/inc/nvx-observability.php');
const operations = read('wp-content/themes/nuvanx-medical/inc/nvx-supabase-relay-operations.php');

const policyEntry = "'inc/nvx-supabase-relay-queue-policy.php'";
const queueEntry = "'inc/nvx-supabase-relay-queue.php'";
assert.ok(bootstrap.includes(policyEntry), 'Queue policy must be in canonical bootstrap');
assert.ok(bootstrap.indexOf(policyEntry) < bootstrap.indexOf(queueEntry), 'Queue policy must load before queue core fallbacks');

for (const fn of ['nvx_supabase_relay_queue_lock_owned', 'nvx_supabase_relay_queue_record_existing_attempt']) {
  assert.match(policy, new RegExp(`if \\( ! function_exists\\( '${fn}' \\) \\)`), `${fn} policy owner must be guarded`);
  assert.match(queue, new RegExp(`if \\( ! function_exists\\( '${fn}' \\) \\)`), `${fn} queue compatibility fallback must remain guarded`);
}

assert.match(policy, /nvx_observability_log\(\s*'supabase_relay_ops',\s*'dedupe_reused'/s,
  'Dedupe winner reuse must emit explicit bounded telemetry');
assert.match(policy, /nvx_observability_log\(\s*'supabase_relay_ops',\s*'drain_lease_lost'/s,
  'Post-I/O lease loss must emit explicit bounded telemetry');
assert.ok((policy.match(/'retry_state_conflict'/g) || []).length >= 2,
  'Attempt and next-attempt CAS conflicts must both emit retry_state_conflict');

const postIoFence = /nvx_supabase_relay_queue_send[\s\S]*?if \( ! nvx_supabase_relay_queue_lock_owned\( \$lock \) \) \{\s*break;/;
assert.match(queue, postIoFence, 'Queue must check the instrumented lease owner after synchronous I/O');
assert.match(queue, /function nvx_supabase_relay_queue_record_existing_attempt[\s\S]*nvx_supabase_relay_queue_atomic_add_attempts/,
  'Queue compatibility fallback must preserve dedupe retry semantics');

assert.match(operations, /stale_building_recovered/);
assert.match(operations, /stale_building_quarantined/);
assert.match(operations, /NVX_SUPABASE_RELAY_GOOGLE_CLICK_MAX_BODY_BYTES[^\n]*8192/);
assert.match(operations, /NVX_SUPABASE_RELAY_DEAD_RETENTION_SECONDS/);
assert.match(operations, /nvx_supabase_relay_operations_shutdown_schedule/);

assert.match(observability, /function nvx_supabase_relay_log\(/,
  'Canonical observability owner must predefine relay logging before queue fallback');
assert.match(observability, /function nvx_observability_log\(/);

console.log('OUTBOX_P1_TELEMETRY_OWNER=PASS dedupe_reused=policy post_io_lease=policy retry_conflict=policy stale_recovery=operations payload_limit=8192 retention=owned shutdown=scheduled');

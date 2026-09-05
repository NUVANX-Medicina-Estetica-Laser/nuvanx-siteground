import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = (path) => fs.readFileSync(path, 'utf8');
const ops = read('wp-content/themes/nuvanx-medical/inc/nvx-supabase-relay-operations.php');
const queue = read('wp-content/themes/nuvanx-medical/inc/nvx-supabase-relay-queue.php');
const bootstrap = read('wp-content/themes/nuvanx-medical/inc/nvx-theme-bootstrap.php');

assert.match(ops, /declare\(strict_types=1\);/, 'Operational owner must use strict types');
assert.match(ops, /NVX_SUPABASE_RELAY_GOOGLE_CLICK_MAX_BODY_BYTES', 8192/, 'google_click ceiling must be 8192 bytes');
assert.match(queue, /NVX_SUPABASE_RELAY_QUEUE_MAX_BODY_BYTES', 32768/, 'Shared outbox ceiling must remain 32768 bytes');
assert.match(ops, /function nvx_supabase_relay_validate_payload\(/, 'Endpoint payload validation owner missing');
assert.match(ops, /'google_click' === \$endpoint[\s\S]*NVX_SUPABASE_RELAY_GOOGLE_CLICK_MAX_BODY_BYTES/, 'google_click must use endpoint-specific ceiling');

// Operations must not replace canonical queue orchestration. The endpoint policy
// preempts invalid Google payloads at the HTTP boundary with a terminal 4xx,
// which the queue's existing classifier already treats as non-retryable.
for (const canonical of [
  'nvx_supabase_relay_classify',
  'nvx_supabase_relay_queue_send',
  'nvx_supabase_relay_dispatch',
  'nvx_supabase_relay_queue_record_existing_attempt',
  'nvx_supabase_relay_queue_lock_owned',
  'nvx_supabase_relay_queue_mark_dead',
  'nvx_supabase_relay_queue_shutdown_drain',
]) {
  assert.ok(!ops.includes(`function ${canonical}(`), `Operations must not redeclare queue owner ${canonical}`);
  assert.ok(queue.includes(`function ${canonical}(`), `Queue canonical owner missing ${canonical}`);
}
assert.match(ops, /add_filter\( 'pre_http_request', 'nvx_supabase_relay_operations_preflight_google_click', 5, 3 \)/,
  'Google payload policy must execute before network I/O');
assert.match(ops, /'code'\s*=>\s*422/, 'Rejected deterministic payloads must surface as terminal HTTP 4xx');
assert.match(queue, /\$status >= 400[\s\S]*'retryable' => false/, 'Canonical queue classifier must keep HTTP 4xx terminal');

for (const event of [
  'payload_rejected',
  'stale_building_recovered',
  'stale_building_quarantined',
  'fast_drain_scheduled',
  'dead_timestamped',
  'dead_cleanup_deleted',
]) {
  assert.ok(ops.includes(`'${event}'`), `Missing bounded operational event: ${event}`);
}
assert.doesNotMatch(ops, /NVX_RELAY_OP[^\n]*(?:body|payload|dedupe_key|token)=/,
  'Operational telemetry must not log payloads, dedupe identities or tokens');

// Blocking shutdown I/O remains in queue only as legacy implementation, but
// operations removes that action after module bootstrap and registers a unique
// scheduler callback. No function-name override is used.
assert.match(ops, /remove_action\( 'shutdown', 'nvx_supabase_relay_queue_shutdown_drain' \)/,
  'Operational policy must detach the blocking queue shutdown callback');
assert.match(ops, /add_action\( 'shutdown', 'nvx_supabase_relay_operations_shutdown_schedule', 10 \)/,
  'Operational policy must install scheduled shutdown owner');
const shutdownStart = ops.indexOf('function nvx_supabase_relay_operations_shutdown_schedule');
const rewireStart = ops.indexOf('function nvx_supabase_relay_operations_rewire_shutdown', shutdownStart);
assert.ok(shutdownStart >= 0 && rewireStart > shutdownStart, 'Unable to isolate shutdown scheduler');
const shutdownBody = ops.slice(shutdownStart, rewireStart);
assert.doesNotMatch(shutdownBody, /nvx_supabase_relay_queue_drain\s*\(/,
  'Request shutdown must never perform relay network drain directly');
assert.match(shutdownBody, /wp_schedule_single_event\([\s\S]*NVX_SUPABASE_RELAY_QUEUE_FAST_CRON/,
  'Request shutdown must schedule bounded fast drain work');

assert.match(ops, /NVX_SUPABASE_RELAY_DEAD_RETENTION_SECONDS', 30 \* DAY_IN_SECONDS/,
  'Dead-letter retention must be explicit and bounded');
assert.match(ops, /NVX_SUPABASE_RELAY_DEAD_CLEANUP_BATCH', 100/,
  'Dead-letter cleanup must have a fixed batch ceiling');
assert.match(ops, /function nvx_supabase_relay_operations_stamp_dead_transition\(/,
  'Terminal transition timestamp owner missing');
assert.match(ops, /update_post_meta\([\s\S]*'_nvx_relay_dead_at'/,
  'Dead terminal rows need explicit terminal timestamp');
assert.doesNotMatch(queue, /update_post_meta\([^;\n]*'_nvx_relay_dead_at'/,
  'Dead-letter retention clock must remain owned by the operations module, not queue protocol paths');
assert.match(queue, /wp_transition_post_status\(\s*\$new_status,\s*\$expected_status,\s*\$post_snapshot\s*\)/,
  'Direct-SQL status CAS in queue must dispatch transition_post_status with snapshot to operations listener');
assert.match(ops, /'meta_query'[\s\S]*'_nvx_relay_dead_at'[\s\S]*'compare'\s*=>\s*'<='[\s\S]*'type'\s*=>\s*'NUMERIC'/,
  'Cleanup retention must use terminal timestamp, not post creation date');
assert.doesNotMatch(ops, /post_date_gmt|date_query/,
  'Dead-letter retention must never use post creation time');
assert.match(ops, /wp_schedule_event\( time\(\) \+ HOUR_IN_SECONDS, 'daily', NVX_SUPABASE_RELAY_QUEUE_CLEANUP_CRON \)/,
  'Dead-letter cleanup must have one daily schedule owner');
assert.match(ops, /wp_clear_scheduled_hook\( NVX_SUPABASE_RELAY_QUEUE_FAST_CRON \)/,
  'Fast drain schedule must be removed on theme switch');
assert.match(ops, /wp_clear_scheduled_hook\( NVX_SUPABASE_RELAY_QUEUE_CLEANUP_CRON \)/,
  'Cleanup schedule must be removed on theme switch');

assert.match(queue, /if \( wp_next_scheduled\( NVX_SUPABASE_RELAY_QUEUE_CRON \) \) \{[\s\S]*return;[\s\S]*wp_schedule_event/,
  'Canonical recurring drain must remain duplicate-safe');

const attributionIndex = bootstrap.indexOf("'inc/nvx-attribution-integration.php'");
const operationsIndex = bootstrap.indexOf("'inc/nvx-supabase-relay-operations.php'");
const queueIndex = bootstrap.indexOf("'inc/nvx-supabase-relay-queue.php'");
assert.ok(attributionIndex >= 0 && operationsIndex > attributionIndex && queueIndex > operationsIndex,
  'Operational policy must load after attribution config and before queue implementation');

console.log('OUTBOX_OPERATIONS_OWNER=PASS queue_owner=single payload_google_click=8192 rejection=http_422 retention_clock=dead_at shutdown_io=scheduled telemetry=bounded');

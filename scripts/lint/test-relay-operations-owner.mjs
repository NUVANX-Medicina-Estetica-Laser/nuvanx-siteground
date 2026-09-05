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
assert.match(ops, /'google_click' === \$endpoint[\s\S]*NVX_SUPABASE_RELAY_GOOGLE_CLICK_MAX_BODY_BYTES/, 'google_click must use the endpoint-specific ceiling');
assert.match(ops, /nvx_relay_payload_too_large[\s\S]*'retryable' => false/, 'Oversized payloads must be terminal, not retryable');

for (const event of [
  'dedupe_reuse',
  'lease_lost',
  'stale_building_recovered',
  'stale_building_quarantined',
  'retry_state_conflict',
  'payload_rejected',
  'dead_cleanup_deleted',
]) {
  assert.ok(ops.includes(`'${event}'`), `Missing bounded operational event: ${event}`);
}
assert.doesNotMatch(ops, /NVX_RELAY_OP[^\n]*(?:body|payload|dedupe_key|token)=/,
  'Operational telemetry must not log payloads, dedupe identities or tokens');

assert.match(ops, /function nvx_supabase_relay_queue_shutdown_drain\([\s\S]*wp_schedule_single_event\([\s\S]*NVX_SUPABASE_RELAY_QUEUE_FAST_CRON/,
  'Shutdown owner must schedule fast drain work');
const shutdownStart = ops.indexOf('function nvx_supabase_relay_queue_shutdown_drain');
const cleanupStart = ops.indexOf('/** Schedule dead-letter retention', shutdownStart);
assert.ok(shutdownStart >= 0 && cleanupStart > shutdownStart, 'Unable to isolate shutdown owner');
const shutdownBody = ops.slice(shutdownStart, cleanupStart);
assert.doesNotMatch(shutdownBody, /nvx_supabase_relay_queue_drain\s*\(/,
  'Request shutdown must never perform relay network drain directly');

assert.match(ops, /NVX_SUPABASE_RELAY_DEAD_RETENTION_SECONDS', 30 \* DAY_IN_SECONDS/,
  'Dead-letter retention must be explicit and bounded');
assert.match(ops, /NVX_SUPABASE_RELAY_DEAD_CLEANUP_BATCH', 100/,
  'Dead-letter cleanup must have a fixed batch ceiling');
assert.match(ops, /'_nvx_relay_dead_at'/, 'Dead terminal rows need an explicit terminal timestamp');
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
  'Operational policy must load after attribution config and before the queue implementation');

for (const overridden of [
  'nvx_supabase_relay_classify',
  'nvx_supabase_relay_queue_send',
  'nvx_supabase_relay_dispatch',
  'nvx_supabase_relay_queue_record_existing_attempt',
  'nvx_supabase_relay_queue_lock_owned',
  'nvx_supabase_relay_queue_mark_dead',
  'nvx_supabase_relay_queue_shutdown_drain',
]) {
  assert.ok(ops.includes(`function ${overridden}(`), `Operational owner missing override ${overridden}`);
  assert.match(queue, new RegExp(`if \\( ! function_exists\\( '${overridden}' \\) \\) \\{`),
    `Queue implementation must preserve function_exists boundary for ${overridden}`);
}

console.log('OUTBOX_OPERATIONS_OWNER=PASS payload_google_click=8192 retention_days=30 shutdown_io=scheduled telemetry=bounded');

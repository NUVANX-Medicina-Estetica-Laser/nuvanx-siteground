<?php
/**
 * Outbox v4 post-merge race regressions.
 *
 * Extends the canonical v2 harness with defects found after #1077:
 * - a prepared row with any live publication generation must not be quarantined,
 *   including the interval after the publish claim is written but before dedupe
 *   identity has been persisted;
 * - recovery must make the due marker durable before attempting the publication
 *   fence, so interruption cannot turn a row pending while still invisible;
 * - quarantine telemetry may report DEAD only after a confirmed draft
 *   transition; failed wp_update_post() remains non-terminal.
 *
 * The normal SUCCESS lifecycle is intentionally not given a terminal marker:
 * complete_terminal_state(..., true) hard-deletes the delivered row before
 * releasing its claim, so there is no completed ready row to republish.
 *
 * @package nuvanx-medical
 */

declare(strict_types=1);

require __DIR__ . '/test-relay-concurrency-v2.php';

$failures_v4 = array();
$require_v4  = static function ( bool $condition, string $name ) use ( &$failures_v4 ): void {
	if ( ! $condition ) {
		$failures_v4[] = $name;
		fwrite( STDERR, "FAIL: {$name}\n" );
	}
};

$queue_path   = dirname( __DIR__, 2 ) . '/wp-content/themes/nuvanx-medical/inc/nvx-supabase-relay-queue.php';
$queue_source = file_get_contents( $queue_path );
$queue_source = is_string( $queue_source ) ? $queue_source : '';

// ── Regression 0: LIVE_CLAIM_PRECEDES_IDENTITY_AND_MUST_BE_PRESERVED ────────
// Enqueue publishes its generation claim before the dedupe identity. Recovery
// is allowed to classify metadata only after proving no live publisher owns the
// row; otherwise a normal in-flight publication can be quarantined as corrupt.
$preidentity_generation = ( $GLOBALS['nvx_mock_time'] + 60 ) . '|publisher_before_identity';
$preidentity_post_id     = wp_insert_post(
	array(
		'post_type'    => NVX_SUPABASE_RELAY_QUEUE_CPT,
		'post_status'  => NVX_SUPABASE_RELAY_QUEUE_PREPARED_STATUS,
		'post_content' => wp_slash( '{"submission_id":"v4-preidentity-live"}' ),
	),
	true
);
$preidentity_post_id = absint( $preidentity_post_id );
add_post_meta( $preidentity_post_id, '_nvx_relay_publish_claim', $preidentity_generation, true );
add_post_meta( $preidentity_post_id, '_nvx_relay_endpoint', 'lead_captured', true );
add_post_meta( $preidentity_post_id, '_nvx_relay_attempts', '1', true );

$require_v4(
	false === nvx_supabase_relay_queue_recover_prepared_without_due( $preidentity_post_id ),
	'PREIDENTITY_LIVE_RECOVERY_DEFERS'
);
$preidentity_post = get_post( $preidentity_post_id );
$require_v4(
	$preidentity_post instanceof WP_Post
	&& NVX_SUPABASE_RELAY_QUEUE_PREPARED_STATUS === $preidentity_post->post_status,
	'PREIDENTITY_LIVE_NOT_QUARANTINED'
);
$require_v4(
	! in_array( $preidentity_post_id, $GLOBALS['nvx_mock_deleted_posts'], true ),
	'PREIDENTITY_LIVE_NOT_DELETED'
);

// ── Regression 1: ANY_LIVE_PUBLISHER_IS_PRESERVED ──────────────────────────
$body_live_mismatch      = '{"submission_id":"v4-live-mismatch"}';
$dedupe_live_mismatch    = nvx_supabase_relay_dedupe_key( 'lead_captured', $body_live_mismatch, '' );
$claim_key_live_mismatch = nvx_supabase_relay_queue_claim_key( $dedupe_live_mismatch );
$live_generation         = ( $GLOBALS['nvx_mock_time'] + 60 ) . '|publisher_a';
$other_owner             = ( $GLOBALS['nvx_mock_time'] + 60 ) . '|publisher_b';
$live_mismatch_post_id   = wp_insert_post(
	array(
		'post_type'    => NVX_SUPABASE_RELAY_QUEUE_CPT,
		'post_status'  => NVX_SUPABASE_RELAY_QUEUE_PREPARED_STATUS,
		'post_content' => wp_slash( $body_live_mismatch ),
	),
	true
);
$live_mismatch_post_id = absint( $live_mismatch_post_id );
add_post_meta( $live_mismatch_post_id, '_nvx_relay_dedupe_key', $dedupe_live_mismatch, true );
add_post_meta( $live_mismatch_post_id, '_nvx_relay_publish_claim', $live_generation, true );
add_post_meta( $live_mismatch_post_id, '_nvx_relay_endpoint', 'lead_captured', true );
add_post_meta( $live_mismatch_post_id, '_nvx_relay_attempts', '1', true );
add_post_meta( $live_mismatch_post_id, '_nvx_relay_next_attempt', (string) ( $GLOBALS['nvx_mock_time'] - 1 ), true );
$GLOBALS['nvx_mock_options'][ $claim_key_live_mismatch ] = $other_owner;

$require_v4(
	false === nvx_supabase_relay_queue_item_due( $live_mismatch_post_id, 'unused-lock', 60 ),
	'LIVE_MISMATCH_NOT_DRAINABLE'
);
$live_mismatch_post = get_post( $live_mismatch_post_id );
$require_v4(
	$live_mismatch_post instanceof WP_Post
	&& NVX_SUPABASE_RELAY_QUEUE_PREPARED_STATUS === $live_mismatch_post->post_status,
	'LIVE_MISMATCH_NOT_QUARANTINED'
);
$require_v4(
	! in_array( $live_mismatch_post_id, $GLOBALS['nvx_mock_deleted_posts'], true ),
	'LIVE_MISMATCH_NOT_DELETED'
);

// ── Regression 2: DUE_VISIBILITY_PRECEDES_RECOVERY_FENCE ───────────────────
$recover_start = strpos( $queue_source, 'function nvx_supabase_relay_queue_recover_prepared_without_due' );
$recover_end   = false !== $recover_start
	? strpos( $queue_source, 'function nvx_supabase_relay_queue_recover_invisible_prepared', $recover_start )
	: false;
$recover_body  = false !== $recover_start && false !== $recover_end
	? substr( $queue_source, $recover_start, $recover_end - $recover_start )
	: '';
$due_offset    = strpos( $recover_body, "add_post_meta( \$post_id, '_nvx_relay_next_attempt', \$due, true )" );
$fence_offset  = strpos( $recover_body, 'nvx_supabase_relay_queue_acquire_publication_fence' );
$require_v4(
	false !== $due_offset && false !== $fence_offset && $due_offset < $fence_offset,
	'RECOVERY_WRITES_DUE_BEFORE_FENCE'
);

// Runtime side of the same contract: when a successor prevents fencing, the
// recovered row must still carry a due marker and remain discoverable.
$body_recovery        = '{"submission_id":"v4-recovery-order"}';
$dedupe_recovery      = nvx_supabase_relay_dedupe_key( 'lead_captured', $body_recovery, '' );
$claim_key_recovery   = nvx_supabase_relay_queue_claim_key( $dedupe_recovery );
$expired_generation   = ( $GLOBALS['nvx_mock_time'] - 10 ) . '|expired_recovery_generation';
$successor_generation = ( $GLOBALS['nvx_mock_time'] + 60 ) . '|successor_generation';
$recovery_post_id     = wp_insert_post(
	array(
		'post_type'    => NVX_SUPABASE_RELAY_QUEUE_CPT,
		'post_status'  => NVX_SUPABASE_RELAY_QUEUE_PREPARED_STATUS,
		'post_content' => wp_slash( $body_recovery ),
	),
	true
);
$recovery_post_id = absint( $recovery_post_id );
add_post_meta( $recovery_post_id, '_nvx_relay_dedupe_key', $dedupe_recovery, true );
add_post_meta( $recovery_post_id, '_nvx_relay_publish_claim', $expired_generation, true );
add_post_meta( $recovery_post_id, '_nvx_relay_endpoint', 'lead_captured', true );
add_post_meta( $recovery_post_id, '_nvx_relay_attempts', '1', true );
add_post_meta( $recovery_post_id, '_nvx_relay_ready', '1', true );
$GLOBALS['nvx_mock_options'][ $claim_key_recovery ] = $successor_generation;

$require_v4(
	false === nvx_supabase_relay_queue_recover_prepared_without_due( $recovery_post_id ),
	'RECOVERY_SUCCESSOR_FENCE_REJECTED'
);
$require_v4(
	absint( get_post_meta( $recovery_post_id, '_nvx_relay_next_attempt', true ) ) > 0,
	'RECOVERY_FAILURE_REMAINS_DUE_VISIBLE'
);
$recovery_post = get_post( $recovery_post_id );
$require_v4(
	$recovery_post instanceof WP_Post
	&& NVX_SUPABASE_RELAY_QUEUE_PREPARED_STATUS === $recovery_post->post_status,
	'RECOVERY_FAILURE_REMAINS_PREPARED'
);

// ── Regression 3: FAILED_QUARANTINE_NEVER_REPORTS_DEAD ──────────────────────
$previous_error_log = (string) ini_get( 'error_log' );
$log_file           = tempnam( sys_get_temp_dir(), 'nvx-relay-v4-' );
if ( false !== $log_file ) {
	ini_set( 'error_log', $log_file );
}

$read_new_log = static function () use ( $log_file ): string {
	if ( false === $log_file || ! is_file( $log_file ) ) {
		return '';
	}
	$content = file_get_contents( $log_file );
	return is_string( $content ) ? $content : '';
};

// Invalid dedupe quarantine fails its draft transition.
$invalid_post_id = wp_insert_post(
	array(
		'post_type'    => NVX_SUPABASE_RELAY_QUEUE_CPT,
		'post_status'  => NVX_SUPABASE_RELAY_QUEUE_PREPARED_STATUS,
		'post_content' => wp_slash( '{"submission_id":"v4-invalid-dedupe"}' ),
	),
	true
);
$invalid_post_id = absint( $invalid_post_id );
add_post_meta( $invalid_post_id, '_nvx_relay_dedupe_key', 'invalid', true );
add_post_meta( $invalid_post_id, '_nvx_relay_endpoint', 'lead_captured', true );
$GLOBALS['nvx_mock_update_failure_on_post'] = $invalid_post_id;
$before_invalid_log = $read_new_log();
nvx_supabase_relay_queue_recover_prepared_without_due( $invalid_post_id );
$after_invalid_log = substr( $read_new_log(), strlen( $before_invalid_log ) );
$GLOBALS['nvx_mock_update_failure_on_post'] = 0;
$invalid_post = get_post( $invalid_post_id );
$require_v4(
	$invalid_post instanceof WP_Post
	&& NVX_SUPABASE_RELAY_QUEUE_PREPARED_STATUS === $invalid_post->post_status,
	'INVALID_DEDUPE_FAILED_QUARANTINE_REMAINS_PREPARED'
);
$require_v4(
	! str_contains( $after_invalid_log, 'NVX_SUPABASE_RELAY=DEAD endpoint=lead_captured reason=invalid_dedupe_metadata' ),
	'INVALID_DEDUPE_FAILED_QUARANTINE_NO_FALSE_DEAD'
);

// Incomplete publication quarantine fails its draft transition.
$body_incomplete    = '{"submission_id":"v4-incomplete"}';
$dedupe_incomplete  = nvx_supabase_relay_dedupe_key( 'lead_captured', $body_incomplete, '' );
$incomplete_post_id = wp_insert_post(
	array(
		'post_type'    => NVX_SUPABASE_RELAY_QUEUE_CPT,
		'post_status'  => NVX_SUPABASE_RELAY_QUEUE_PREPARED_STATUS,
		'post_content' => wp_slash( $body_incomplete ),
	),
	true
);
$incomplete_post_id = absint( $incomplete_post_id );
add_post_meta( $incomplete_post_id, '_nvx_relay_dedupe_key', $dedupe_incomplete, true );
add_post_meta( $incomplete_post_id, '_nvx_relay_publish_claim', ( $GLOBALS['nvx_mock_time'] - 10 ) . '|abandoned', true );
add_post_meta( $incomplete_post_id, '_nvx_relay_endpoint', 'lead_captured', true );
$GLOBALS['nvx_mock_update_failure_on_post'] = $incomplete_post_id;
$before_incomplete_log = $read_new_log();
nvx_supabase_relay_queue_recover_prepared_without_due( $incomplete_post_id );
$after_incomplete_log = substr( $read_new_log(), strlen( $before_incomplete_log ) );
$GLOBALS['nvx_mock_update_failure_on_post'] = 0;
$incomplete_post = get_post( $incomplete_post_id );
$require_v4(
	$incomplete_post instanceof WP_Post
	&& NVX_SUPABASE_RELAY_QUEUE_PREPARED_STATUS === $incomplete_post->post_status,
	'INCOMPLETE_FAILED_QUARANTINE_REMAINS_PREPARED'
);
$require_v4(
	! str_contains( $after_incomplete_log, 'NVX_SUPABASE_RELAY=DEAD endpoint=lead_captured reason=publication_incomplete' ),
	'INCOMPLETE_FAILED_QUARANTINE_NO_FALSE_DEAD'
);

if ( false !== $log_file ) {
	@unlink( $log_file );
}
ini_set( 'error_log', $previous_error_log );

if ( $failures_v4 ) {
	fwrite( STDERR, 'OUTBOX_POSTMERGE_RACES_V4=FAIL count=' . count( $failures_v4 ) . "\n" );
	exit( 1 );
}

echo "OUTBOX_POSTMERGE_RACES_V4=PASS preidentity_live_safe=1 live_claim_safe=1 due_before_fence=1 failed_quarantine_nonterminal=1 success_hard_delete_contract=1\n";

<?php
/**
 * Outbox v4 post-merge race regressions.
 *
 * Extends the canonical v2 harness with defects found after #1077:
 * - BUILDING rows must have a bounded recovery owner, not become permanent
 *   stranded state after an interrupted publisher;
 * - recovery and publisher must arbitrate BUILDING transitions through the same
 *   compare-and-swap status boundary so neither can resurrect/erase the other;
 * - a newly inserted publication must remain outside the prepared recovery/drain
 *   domain until all publication metadata is durable;
 * - enqueue must fail closed when the final prepared->pending transition fails;
 * - a prepared row with any live publication generation must not be quarantined,
 *   including the interval after the publish claim is written but before dedupe
 *   identity has been persisted;
 * - recovery must make the due marker durable before attempting the publication
 *   fence, so interruption cannot turn a row pending while still invisible;
 * - quarantine telemetry may report DEAD only after a confirmed state transition.
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

$enqueue_start = strpos( $queue_source, 'function nvx_supabase_relay_queue_enqueue' );
$enqueue_end   = false !== $enqueue_start
	? strpos( $queue_source, 'function nvx_supabase_relay_queue_send', $enqueue_start )
	: false;
$enqueue_body  = false !== $enqueue_start && false !== $enqueue_end
	? substr( $queue_source, $enqueue_start, $enqueue_end - $enqueue_start )
	: '';

// ── Regression -3: BUILDING_HAS_BOUNDED_RECOVERY_OWNER ──────────────────────
$building_recovery_available = function_exists( 'nvx_supabase_relay_queue_recover_building_item' );
$require_v4( $building_recovery_available, 'BUILDING_RECOVERY_FUNCTION_PRESENT' );
$require_v4(
	str_contains( $queue_source, 'nvx_supabase_relay_queue_recover_building_rows' )
	&& str_contains( $queue_source, 'NVX_SUPABASE_RELAY_QUEUE_BUILDING_STATUS' )
	&& str_contains( $queue_source, "'post_status'            => array( NVX_SUPABASE_RELAY_QUEUE_BUILDING_STATUS )" ),
	'BUILDING_RECOVERY_DISCOVERY_OWNER_PRESENT'
);
$require_v4(
	str_contains( $queue_source, 'function nvx_supabase_relay_queue_compare_and_swap_status' )
	&& str_contains( $queue_source, 'AND post_status = %s' )
	&& str_contains( $enqueue_body, 'nvx_supabase_relay_queue_compare_and_swap_status(' ),
	'BUILDING_PUBLISHER_RECOVERY_SHARE_STATUS_CAS'
);
$drain_start = strpos( $queue_source, 'function nvx_supabase_relay_queue_drain' );
$drain_end   = false !== $drain_start
	? strpos( $queue_source, "add_action( NVX_SUPABASE_RELAY_QUEUE_CRON", $drain_start )
	: false;
$drain_body  = false !== $drain_start && false !== $drain_end
	? substr( $queue_source, $drain_start, $drain_end - $drain_start )
	: '';
$building_recovery_offset = strpos( $drain_body, 'nvx_supabase_relay_queue_recover_building_rows( $limit )' );
$prepared_recovery_offset = strpos( $drain_body, 'nvx_supabase_relay_queue_recover_invisible_prepared( $limit )' );
$require_v4(
	false !== $building_recovery_offset
	&& false !== $prepared_recovery_offset
	&& $building_recovery_offset < $prepared_recovery_offset,
	'BUILDING_RECOVERY_RUNS_BEFORE_PREPARED_RECOVERY'
);

if ( $building_recovery_available ) {
	// Crash immediately after INSERT: the row is fresh and therefore still owned
	// by the publisher construction window; recovery must not classify it.
	$fresh_building_id = wp_insert_post(
		array(
			'post_type'    => NVX_SUPABASE_RELAY_QUEUE_CPT,
			'post_status'  => NVX_SUPABASE_RELAY_QUEUE_BUILDING_STATUS,
			'post_content' => wp_slash( '{"submission_id":"v4-building-fresh"}' ),
		),
		true
	);
	$fresh_building_id = absint( $fresh_building_id );
	$fresh_building    = get_post( $fresh_building_id );
	if ( $fresh_building instanceof WP_Post ) {
		$fresh_building->post_date_gmt = gmdate( 'Y-m-d H:i:s', $GLOBALS['nvx_mock_time'] );
	}
	$require_v4(
		false === nvx_supabase_relay_queue_recover_building_item( $fresh_building_id ),
		'FRESH_BUILDING_PUBLISHER_PRESERVED'
	);
	$require_v4(
		get_post( $fresh_building_id ) instanceof WP_Post
		&& NVX_SUPABASE_RELAY_QUEUE_BUILDING_STATUS === get_post( $fresh_building_id )->post_status,
		'FRESH_BUILDING_REMAINS_BUILDING'
	);

	// Crash during metadata: after the bounded construction lease, an incomplete
	// row is quarantined through the BUILDING CAS. A simulated stale publisher
	// attempting BUILDING->PREPARED from the draft hook must lose that CAS.
	$partial_building_id = wp_insert_post(
		array(
			'post_type'    => NVX_SUPABASE_RELAY_QUEUE_CPT,
			'post_status'  => NVX_SUPABASE_RELAY_QUEUE_BUILDING_STATUS,
			'post_content' => wp_slash( '{"submission_id":"v4-building-partial"}' ),
		),
		true
	);
	$partial_building_id = absint( $partial_building_id );
	$partial_building    = get_post( $partial_building_id );
	if ( $partial_building instanceof WP_Post ) {
		$partial_building->post_date_gmt = gmdate(
			'Y-m-d H:i:s',
			$GLOBALS['nvx_mock_time'] - NVX_SUPABASE_RELAY_QUEUE_CLAIM_LEASE_SECONDS - 1
		);
	}
	add_post_meta( $partial_building_id, '_nvx_relay_endpoint', 'lead_captured', true );
	$GLOBALS['nvx_mock_stale_building_resurrection'] = null;
	$GLOBALS['nvx_mock_hook_on_status_draft'] = static function ( int $post_id ) use ( $partial_building_id ): void {
		if ( $post_id === $partial_building_id ) {
			$GLOBALS['nvx_mock_stale_building_resurrection'] = nvx_supabase_relay_queue_compare_and_swap_status(
				$post_id,
				NVX_SUPABASE_RELAY_QUEUE_BUILDING_STATUS,
				NVX_SUPABASE_RELAY_QUEUE_PREPARED_STATUS
			);
		}
	};
	$require_v4(
		false === nvx_supabase_relay_queue_recover_building_item( $partial_building_id ),
		'PARTIAL_BUILDING_NOT_PROMOTED'
	);
	unset( $GLOBALS['nvx_mock_hook_on_status_draft'] );
	$require_v4(
		false === $GLOBALS['nvx_mock_stale_building_resurrection'],
		'PARTIAL_BUILDING_STALE_PUBLISHER_CANNOT_RESURRECT'
	);
	$require_v4(
		get_post( $partial_building_id ) instanceof WP_Post
		&& 'draft' === get_post( $partial_building_id )->post_status,
		'PARTIAL_BUILDING_QUARANTINED'
	);

	// Crash after all metadata is durable but before BUILDING->PREPARED: recovery
	// promotes the exact row and routes it through the existing publication fence.
	$body_complete_building   = '{"submission_id":"v4-building-complete"}';
	$dedupe_complete_building = nvx_supabase_relay_dedupe_key( 'lead_captured', $body_complete_building, '' );
	$claim_complete_building  = nvx_supabase_relay_queue_claim_key( $dedupe_complete_building );
	$expired_complete_claim   = ( $GLOBALS['nvx_mock_time'] - 1 ) . '|complete_building_generation';
	$complete_building_id     = wp_insert_post(
		array(
			'post_type'    => NVX_SUPABASE_RELAY_QUEUE_CPT,
			'post_status'  => NVX_SUPABASE_RELAY_QUEUE_BUILDING_STATUS,
			'post_content' => wp_slash( $body_complete_building ),
		),
		true
	);
	$complete_building_id = absint( $complete_building_id );
	$complete_building    = get_post( $complete_building_id );
	if ( $complete_building instanceof WP_Post ) {
		$complete_building->post_date_gmt = gmdate(
			'Y-m-d H:i:s',
			$GLOBALS['nvx_mock_time'] - NVX_SUPABASE_RELAY_QUEUE_CLAIM_LEASE_SECONDS - 1
		);
	}
	add_post_meta( $complete_building_id, '_nvx_relay_endpoint', 'lead_captured', true );
	add_post_meta( $complete_building_id, '_nvx_relay_attempts', '1', true );
	add_post_meta( $complete_building_id, '_nvx_relay_publish_claim', $expired_complete_claim, true );
	add_post_meta( $complete_building_id, '_nvx_relay_dedupe_key', $dedupe_complete_building, true );
	add_post_meta( $complete_building_id, '_nvx_relay_next_attempt', (string) ( $GLOBALS['nvx_mock_time'] - 1 ), true );
	add_post_meta( $complete_building_id, '_nvx_relay_ready', '1', true );
	$GLOBALS['nvx_mock_options'][ $claim_complete_building ] = $expired_complete_claim;
	$require_v4(
		nvx_supabase_relay_queue_recover_building_item( $complete_building_id ),
		'COMPLETE_BUILDING_RECOVERED'
	);
	$require_v4(
		get_post( $complete_building_id ) instanceof WP_Post
		&& 'pending' === get_post( $complete_building_id )->post_status,
		'COMPLETE_BUILDING_PROMOTED_PENDING'
	);
	$require_v4(
		(string) $complete_building_id === (string) get_option( $claim_complete_building, '' ),
		'COMPLETE_BUILDING_BINDS_NUMERIC_FENCE'
	);

	// Crash in the exact next_attempt -> ready window. Identity, attempts, claim
	// and due visibility are already durable. Recovery must not discard the payload;
	// it wins BUILDING->PREPARED first, then the existing finalizer repairs ready.
	$body_missing_ready   = '{"submission_id":"v4-building-missing-ready"}';
	$dedupe_missing_ready = nvx_supabase_relay_dedupe_key( 'lead_captured', $body_missing_ready, '' );
	$claim_missing_ready  = nvx_supabase_relay_queue_claim_key( $dedupe_missing_ready );
	$expired_missing_ready_claim = ( $GLOBALS['nvx_mock_time'] - 1 ) . '|missing_ready_generation';
	$missing_ready_id     = wp_insert_post(
		array(
			'post_type'    => NVX_SUPABASE_RELAY_QUEUE_CPT,
			'post_status'  => NVX_SUPABASE_RELAY_QUEUE_BUILDING_STATUS,
			'post_content' => wp_slash( $body_missing_ready ),
		),
		true
	);
	$missing_ready_id = absint( $missing_ready_id );
	$missing_ready    = get_post( $missing_ready_id );
	if ( $missing_ready instanceof WP_Post ) {
		$missing_ready->post_date_gmt = gmdate(
			'Y-m-d H:i:s',
			$GLOBALS['nvx_mock_time'] - NVX_SUPABASE_RELAY_QUEUE_CLAIM_LEASE_SECONDS - 1
		);
	}
	add_post_meta( $missing_ready_id, '_nvx_relay_endpoint', 'lead_captured', true );
	add_post_meta( $missing_ready_id, '_nvx_relay_attempts', '1', true );
	add_post_meta( $missing_ready_id, '_nvx_relay_publish_claim', $expired_missing_ready_claim, true );
	add_post_meta( $missing_ready_id, '_nvx_relay_dedupe_key', $dedupe_missing_ready, true );
	add_post_meta( $missing_ready_id, '_nvx_relay_next_attempt', (string) ( $GLOBALS['nvx_mock_time'] - 1 ), true );
	$GLOBALS['nvx_mock_options'][ $claim_missing_ready ] = $expired_missing_ready_claim;
	$require_v4(
		nvx_supabase_relay_queue_recover_building_item( $missing_ready_id ),
		'MISSING_READY_BUILDING_RECOVERED'
	);
	$require_v4(
		nvx_supabase_relay_queue_item_ready( $missing_ready_id ),
		'MISSING_READY_REPAIRED_AFTER_BUILDING_CAS'
	);
	$require_v4(
		get_post( $missing_ready_id ) instanceof WP_Post
		&& 'pending' === get_post( $missing_ready_id )->post_status,
		'MISSING_READY_BUILDING_PROMOTED_PENDING'
	);
	$require_v4(
		(string) $missing_ready_id === (string) get_option( $claim_missing_ready, '' ),
		'MISSING_READY_BINDS_NUMERIC_FENCE'
	);
}

// ── Regression -2: BUILDING_ROWS_ARE_NOT_PREPARED_RECOVERY_VISIBLE ─────────
$building_offset = strpos( $enqueue_body, "'post_status'  => NVX_SUPABASE_RELAY_QUEUE_BUILDING_STATUS" );
$ready_offset    = strpos( $enqueue_body, "add_post_meta( \$post_id, '_nvx_relay_ready', '1', true )" );
$status_cas_offset = strpos( $enqueue_body, 'nvx_supabase_relay_queue_compare_and_swap_status(' );
$bind_offset     = strpos( $enqueue_body, 'nvx_supabase_relay_compare_and_swap_option( $claim_key, $in_flight_value, (string) $post_id )' );
$require_v4( str_contains( $queue_source, 'NVX_SUPABASE_RELAY_QUEUE_BUILDING_STATUS' ), 'BUILDING_STATUS_DEFINED' );
$require_v4(
	false !== $building_offset
	&& false !== $ready_offset
	&& false !== $status_cas_offset
	&& false !== $bind_offset
	&& $building_offset < $ready_offset
	&& $ready_offset < $status_cas_offset
	&& $status_cas_offset < $bind_offset,
	'BUILDING_METADATA_PRECEDES_PUBLICATION_VISIBILITY'
);

$building_post_id = wp_insert_post(
	array(
		'post_type'    => NVX_SUPABASE_RELAY_QUEUE_CPT,
		'post_status'  => NVX_SUPABASE_RELAY_QUEUE_BUILDING_STATUS,
		'post_content' => wp_slash( '{"submission_id":"v4-building-hidden"}' ),
	),
	true
);
$building_post_id = absint( $building_post_id );
$require_v4(
	false === nvx_supabase_relay_queue_recover_prepared_without_due( $building_post_id ),
	'BUILDING_ROW_NOT_PREPARED_RECOVERED'
);
$require_v4(
	false === nvx_supabase_relay_queue_item_due( $building_post_id, 'unused-lock', 60 ),
	'BUILDING_ROW_NOT_DRAINABLE'
);
$building_post = get_post( $building_post_id );
$require_v4(
	$building_post instanceof WP_Post
	&& NVX_SUPABASE_RELAY_QUEUE_BUILDING_STATUS === $building_post->post_status,
	'BUILDING_ROW_NOT_PREPARED_QUARANTINED'
);

// ── Regression -1: ENQUEUE_FINALIZATION_FAILURE_IS_NOT_SUCCESS ──────────────
$body_finalize_fail       = '{"submission_id":"v4-enqueue-finalize-fail"}';
$dedupe_finalize_fail     = nvx_supabase_relay_dedupe_key( 'lead_captured', $body_finalize_fail, '' );
$claim_key_finalize_fail  = nvx_supabase_relay_queue_claim_key( $dedupe_finalize_fail );
$expected_finalize_post   = $GLOBALS['nvx_mock_next_post_id'] + 1;
$GLOBALS['nvx_mock_update_failure_on_post'] = $expected_finalize_post;
$GLOBALS['nvx_mock_update_failure_status']  = 'pending';
$finalize_fail_result = nvx_supabase_relay_queue_enqueue( 'lead_captured', $body_finalize_fail, array(), 1 );
$GLOBALS['nvx_mock_update_failure_on_post'] = 0;
$GLOBALS['nvx_mock_update_failure_status']  = '';
$require_v4( 0 === $finalize_fail_result, 'ENQUEUE_FINALIZE_FAILURE_RETURNS_ZERO' );
$finalize_failed_post = get_post( $expected_finalize_post );
$require_v4(
	$finalize_failed_post instanceof WP_Post
	&& NVX_SUPABASE_RELAY_QUEUE_PREPARED_STATUS === $finalize_failed_post->post_status,
	'ENQUEUE_FINALIZE_FAILURE_REMAINS_PREPARED'
);
$require_v4(
	(string) $expected_finalize_post === (string) get_option( $claim_key_finalize_fail, '' ),
	'ENQUEUE_FINALIZE_FAILURE_RETAINS_NUMERIC_FENCE'
);
$require_v4(
	str_contains( $enqueue_body, 'if ( ! nvx_supabase_relay_queue_finalize_publication( $post_id, $dedupe_key ) )' )
	&& str_contains( $enqueue_body, "'publication_finalize_failed'" ),
	'ENQUEUE_FINALIZATION_FAIL_CLOSED_SOURCE'
);

// ── Regression 0: LIVE_CLAIM_PRECEDES_IDENTITY_AND_MUST_BE_PRESERVED ────────
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

echo "OUTBOX_POSTMERGE_RACES_V4=PASS building_recovery=1 building_status_cas=1 crash_after_insert_safe=1 crash_mid_metadata_quarantined=1 crash_after_metadata_recovered=1 crash_before_ready_recovered=1 building_hidden=1 finalize_fail_closed=1 preidentity_live_safe=1 live_claim_safe=1 due_before_fence=1 failed_quarantine_nonterminal=1\n";

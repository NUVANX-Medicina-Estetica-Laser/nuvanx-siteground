<?php
/**
 * Outbox v3 ownership contract.
 *
 * Extends the canonical v2 concurrency harness with regressions for the three
 * post-#1056 races tracked by #1072:
 * - stale duplicate cleanup after claim ownership changed;
 * - late rows from a completed generation becoming adoptable after release;
 * - active prepared publication being quarantined before its readiness marker.
 *
 * @package nuvanx-medical
 */

declare(strict_types=1);

require __DIR__ . '/test-relay-concurrency-v2.php';

$failures_v3 = array();
$require_v3  = static function ( bool $condition, string $name ) use ( &$failures_v3 ): void {
	if ( ! $condition ) {
		$failures_v3[] = $name;
		fwrite( STDERR, "FAIL: {$name}\n" );
	}
};

$queue_source = file_get_contents(
	dirname( __DIR__, 2 ) . '/wp-content/themes/nuvanx-medical/inc/nvx-supabase-relay-queue.php'
);
$queue_source = is_string( $queue_source ) ? $queue_source : '';

// ── Contract 1: identity and readiness are separate durable concepts. ───────
$require_v3(
	str_contains( $queue_source, "'_nvx_relay_publish_claim'" ),
	'PUBLISH_GENERATION_META_EXISTS'
);
$require_v3(
	str_contains( $queue_source, "'_nvx_relay_ready'" ),
	'EXPLICIT_READINESS_MARKER_EXISTS'
);

$enqueue_start = strpos( $queue_source, 'function nvx_supabase_relay_queue_enqueue' );
$enqueue_end   = false !== $enqueue_start
	? strpos( $queue_source, '/**\n * Send one persisted payload.', $enqueue_start )
	: false;
$enqueue_body  = false !== $enqueue_start && false !== $enqueue_end
	? substr( $queue_source, $enqueue_start, $enqueue_end - $enqueue_start )
	: '';
$dedupe_offset = strpos( $enqueue_body, "'_nvx_relay_dedupe_key'" );
$next_offset   = strpos( $enqueue_body, "'_nvx_relay_next_attempt'", $dedupe_offset === false ? 0 : $dedupe_offset );
$ready_offset  = strpos( $enqueue_body, "'_nvx_relay_ready'", $next_offset === false ? 0 : $next_offset );
$bind_comment  = strpos( $enqueue_body, '// Bind claim to published post_id atomically via CAS.' );
$require_v3(
	false !== $dedupe_offset
	&& false !== $next_offset
	&& false !== $ready_offset
	&& false !== $bind_comment
	&& $dedupe_offset < $next_offset
	&& $next_offset < $ready_offset
	&& $ready_offset < $bind_comment,
	'IDENTITY_PRECEDES_DUE_AND_READINESS_IS_LAST_BEFORE_BIND'
);

$require_v3(
	function_exists( 'nvx_supabase_relay_queue_item_ready' ),
	'ROW_READINESS_OWNER_EXISTS'
);
$require_v3(
	function_exists( 'nvx_supabase_relay_queue_item_adoptable_for_claim' ),
	'GENERATION_ADOPTION_OWNER_EXISTS'
);

// ── Contract 2: active prepared row is not quarantined before readiness. ─────
$body_active       = '{"submission_id":"v3-active-prepared"}';
$dedupe_active     = nvx_supabase_relay_dedupe_key( 'lead_captured', $body_active, '' );
$claim_key_active  = nvx_supabase_relay_queue_claim_key( $dedupe_active );
$publish_claim     = ( $GLOBALS['nvx_mock_time'] + 60 ) . '|v3_active_publisher';
$active_post_id    = wp_insert_post(
	array(
		'post_type'    => NVX_SUPABASE_RELAY_QUEUE_CPT,
		'post_status'  => NVX_SUPABASE_RELAY_QUEUE_PREPARED_STATUS,
		'post_content' => wp_slash( $body_active ),
	),
	true
);
$active_post_id = absint( $active_post_id );
add_post_meta( $active_post_id, '_nvx_relay_dedupe_key', $dedupe_active, true );
add_post_meta( $active_post_id, '_nvx_relay_publish_claim', $publish_claim, true );
add_post_meta( $active_post_id, '_nvx_relay_endpoint', 'lead_captured', true );
add_post_meta( $active_post_id, '_nvx_relay_attempts', '1', true );
add_post_meta( $active_post_id, '_nvx_relay_next_attempt', (string) ( $GLOBALS['nvx_mock_time'] - 1 ), true );
$GLOBALS['nvx_mock_options'][ $claim_key_active ] = $publish_claim;

$active_due = nvx_supabase_relay_queue_item_due( $active_post_id, 'unused-lock', 60 );
$require_v3( false === $active_due, 'ACTIVE_PREPARED_NOT_DRAINABLE' );
$active_post = get_post( $active_post_id );
$require_v3(
	$active_post instanceof WP_Post
	&& NVX_SUPABASE_RELAY_QUEUE_PREPARED_STATUS === $active_post->post_status,
	'ACTIVE_PREPARED_NOT_QUARANTINED'
);
$require_v3(
	! in_array( $active_post_id, $GLOBALS['nvx_mock_deleted_posts'], true ),
	'ACTIVE_PREPARED_NOT_DELETED'
);

// ── Contract 3: a completed generation cannot be adopted after release. ─────
$body_stale        = '{"submission_id":"v3-stale-generation"}';
$dedupe_stale      = nvx_supabase_relay_dedupe_key( 'lead_captured', $body_stale, '' );
$claim_key_stale   = nvx_supabase_relay_queue_claim_key( $dedupe_stale );
$stale_post_id     = wp_insert_post(
	array(
		'post_type'    => NVX_SUPABASE_RELAY_QUEUE_CPT,
		'post_status'  => NVX_SUPABASE_RELAY_QUEUE_PREPARED_STATUS,
		'post_content' => wp_slash( $body_stale ),
	),
	true
);
$stale_post_id = absint( $stale_post_id );
add_post_meta( $stale_post_id, '_nvx_relay_dedupe_key', $dedupe_stale, true );
add_post_meta( $stale_post_id, '_nvx_relay_publish_claim', ( $GLOBALS['nvx_mock_time'] - 10 ) . '|completed_generation', true );
add_post_meta( $stale_post_id, '_nvx_relay_endpoint', 'lead_captured', true );
add_post_meta( $stale_post_id, '_nvx_relay_attempts', '1', true );
add_post_meta( $stale_post_id, '_nvx_relay_next_attempt', (string) ( $GLOBALS['nvx_mock_time'] - 1 ), true );
add_post_meta( $stale_post_id, '_nvx_relay_ready', '1', true );
unset( $GLOBALS['nvx_mock_options'][ $claim_key_stale ] );

$new_generation_id = nvx_supabase_relay_queue_enqueue( 'lead_captured', $body_stale, array(), 1 );
$require_v3( $new_generation_id > 0, 'FRESH_GENERATION_ENQUEUES' );
$require_v3( $new_generation_id !== $stale_post_id, 'STALE_GENERATION_NOT_ADOPTED' );
$require_v3(
	! isset( $GLOBALS['nvx_mock_posts'][ $stale_post_id ] ),
	'STALE_GENERATION_RETIRED_BEFORE_FRESH_PUBLISH'
);
$require_v3(
	(string) $new_generation_id === (string) get_option( $claim_key_stale, '' ),
	'FRESH_GENERATION_OWNS_CLAIM'
);

// ── Contract 4: stale finalizer cannot delete a replacement publisher. ──────
$body_owner          = '{"submission_id":"v3-stale-finalizer"}';
$dedupe_owner        = nvx_supabase_relay_dedupe_key( 'lead_captured', $body_owner, '' );
$claim_key_owner     = nvx_supabase_relay_queue_claim_key( $dedupe_owner );
$canonical_owner_id  = wp_insert_post(
	array(
		'post_type'    => NVX_SUPABASE_RELAY_QUEUE_CPT,
		'post_status'  => 'pending',
		'post_content' => wp_slash( $body_owner ),
	),
	true
);
$canonical_owner_id = absint( $canonical_owner_id );
add_post_meta( $canonical_owner_id, '_nvx_relay_dedupe_key', $dedupe_owner, true );
add_post_meta( $canonical_owner_id, '_nvx_relay_ready', '1', true );

$replacement_claim = ( $GLOBALS['nvx_mock_time'] + 60 ) . '|replacement_generation';
$replacement_id    = wp_insert_post(
	array(
		'post_type'    => NVX_SUPABASE_RELAY_QUEUE_CPT,
		'post_status'  => NVX_SUPABASE_RELAY_QUEUE_PREPARED_STATUS,
		'post_content' => wp_slash( $body_owner ),
	),
	true
);
$replacement_id = absint( $replacement_id );
add_post_meta( $replacement_id, '_nvx_relay_dedupe_key', $dedupe_owner, true );
add_post_meta( $replacement_id, '_nvx_relay_publish_claim', $replacement_claim, true );
add_post_meta( $replacement_id, '_nvx_relay_ready', '1', true );
$GLOBALS['nvx_mock_options'][ $claim_key_owner ] = $replacement_claim;

$retire_reflection = new ReflectionFunction( 'nvx_supabase_relay_queue_retire_duplicate_rows' );
$require_v3(
	$retire_reflection->getNumberOfParameters() >= 3,
	'DUPLICATE_RETIREMENT_REQUIRES_EXPECTED_CLAIM'
);
if ( $retire_reflection->getNumberOfParameters() >= 3 ) {
	$retired = nvx_supabase_relay_queue_retire_duplicate_rows(
		$canonical_owner_id,
		$dedupe_owner,
		(string) $canonical_owner_id
	);
	$require_v3( false === $retired, 'STALE_FINALIZER_ABORTS_AFTER_OWNERSHIP_CHANGE' );
	$require_v3(
		isset( $GLOBALS['nvx_mock_posts'][ $replacement_id ] ),
		'REPLACEMENT_PUBLISHER_SURVIVES_STALE_CLEANUP'
	);
}

// Source ratchet: cleanup must refresh ownership; adoption must be generation-aware.
$require_v3(
	str_contains( $queue_source, 'nvx_supabase_relay_queue_item_adoptable_for_claim' )
	&& str_contains( $queue_source, '_nvx_relay_publish_claim' )
	&& str_contains( $queue_source, '_nvx_relay_ready' ),
	'OUTBOX_V3_SOURCE_INTEGRITY'
);

if ( ! empty( $failures_v3 ) ) {
	fwrite( STDERR, 'OUTBOX_TERMINAL_OWNERSHIP_V3=FAIL count=' . count( $failures_v3 ) . "\n" );
	exit( 1 );
}

echo "OUTBOX_TERMINAL_OWNERSHIP_V3=PASS generation_fenced=1 readiness_explicit=1 active_prepare_safe=1 stale_generation_rejected=1 stale_finalizer_safe=1\n";

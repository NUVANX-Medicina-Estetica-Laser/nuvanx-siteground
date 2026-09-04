<?php
/**
 * Outbox v3 ownership contract.
 *
 * Extends the canonical v2 concurrency harness with regressions for the
 * post-#1056 races tracked by #1072:
 * - stale duplicate cleanup after claim ownership changed;
 * - late rows from a completed generation becoming adoptable after release;
 * - active prepared publication being quarantined before its readiness marker;
 * - invisible prepared rows after interrupted metadata publication;
 * - SUCCESS/DEAD cleanup-to-release interleavings.
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

// ── Contract 1: identity, readiness and due visibility are ordered. ─────────
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
	? strpos( $queue_source, "if ( ! function_exists( 'nvx_supabase_relay_queue_send' ) )", $enqueue_start )
	: false;
$enqueue_body  = false !== $enqueue_start && false !== $enqueue_end
	? substr( $queue_source, $enqueue_start, $enqueue_end - $enqueue_start )
	: '';
$dedupe_offset = strpos( $enqueue_body, "'_nvx_relay_dedupe_key'" );
$next_offset   = strpos( $enqueue_body, "'_nvx_relay_next_attempt'", false === $dedupe_offset ? 0 : $dedupe_offset );
$ready_offset  = strpos( $enqueue_body, "'_nvx_relay_ready'", false === $next_offset ? 0 : $next_offset );
$bind_offset   = strpos(
	$enqueue_body,
	'nvx_supabase_relay_compare_and_swap_option( $claim_key, $in_flight_value, (string) $post_id )',
	false === $ready_offset ? 0 : $ready_offset
);
$require_v3(
	false !== $dedupe_offset
	&& false !== $next_offset
	&& false !== $ready_offset
	&& false !== $bind_offset
	&& $dedupe_offset < $next_offset
	&& $next_offset < $ready_offset
	&& $ready_offset < $bind_offset,
	'IDENTITY_READY_DUE_VISIBILITY_ORDERED'
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
// This fixture models legacy/corrupt ordering where next_attempt became visible
// before readiness. New publications write readiness last, so a row is never
// ready without due-visibility, while runtime still fails closed if it encounters
// a due but not-yet-ready row.
$body_active      = '{"submission_id":"v3-active-prepared"}';
$dedupe_active    = nvx_supabase_relay_dedupe_key( 'lead_captured', $body_active, '' );
$claim_key_active = nvx_supabase_relay_queue_claim_key( $dedupe_active );
$publish_claim    = ( $GLOBALS['nvx_mock_time'] + 60 ) . '|v3_active_publisher';
$active_post_id   = wp_insert_post(
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

// ── Contract 3: invisible prepared lifecycle has a deterministic recovery. ───
$require_v3(
	function_exists( 'nvx_supabase_relay_queue_recover_prepared_without_due' )
	&& function_exists( 'nvx_supabase_relay_queue_recover_invisible_prepared' ),
	'INVISIBLE_PREPARED_RECOVERY_OWNER_EXISTS'
);

// Ready generation crashed before writing the final due marker. Once the
// generation lease is expired, recovery must bind the row and make it visible.
$body_invisible       = '{"submission_id":"v3-invisible-ready"}';
$dedupe_invisible     = nvx_supabase_relay_dedupe_key( 'lead_captured', $body_invisible, '' );
$claim_key_invisible  = nvx_supabase_relay_queue_claim_key( $dedupe_invisible );
$expired_ready_claim  = ( $GLOBALS['nvx_mock_time'] - 10 ) . '|expired_ready_generation';
$invisible_post_id    = wp_insert_post(
	array(
		'post_type'    => NVX_SUPABASE_RELAY_QUEUE_CPT,
		'post_status'  => NVX_SUPABASE_RELAY_QUEUE_PREPARED_STATUS,
		'post_content' => wp_slash( $body_invisible ),
	),
	true
);
$invisible_post_id = absint( $invisible_post_id );
add_post_meta( $invisible_post_id, '_nvx_relay_dedupe_key', $dedupe_invisible, true );
add_post_meta( $invisible_post_id, '_nvx_relay_publish_claim', $expired_ready_claim, true );
add_post_meta( $invisible_post_id, '_nvx_relay_endpoint', 'lead_captured', true );
add_post_meta( $invisible_post_id, '_nvx_relay_attempts', '1', true );
add_post_meta( $invisible_post_id, '_nvx_relay_ready', '1', true );
$GLOBALS['nvx_mock_options'][ $claim_key_invisible ] = $expired_ready_claim;

$require_v3(
	nvx_supabase_relay_queue_recover_prepared_without_due( $invisible_post_id ),
	'INVISIBLE_READY_PREPARED_RECOVERED'
);
$invisible_post = get_post( $invisible_post_id );
$require_v3(
	$invisible_post instanceof WP_Post && 'pending' === $invisible_post->post_status,
	'INVISIBLE_READY_PREPARED_FINALIZED'
);
$require_v3(
	absint( get_post_meta( $invisible_post_id, '_nvx_relay_next_attempt', true ) ) > 0,
	'INVISIBLE_READY_PREPARED_DUE_VISIBLE'
);
$require_v3(
	(string) $invisible_post_id === (string) get_option( $claim_key_invisible, '' ),
	'INVISIBLE_READY_PREPARED_CLAIM_BOUND'
);

// Incomplete row owned by a live publisher is never quarantined by recovery.
$body_live_invisible      = '{"submission_id":"v3-invisible-live"}';
$dedupe_live_invisible    = nvx_supabase_relay_dedupe_key( 'lead_captured', $body_live_invisible, '' );
$claim_key_live_invisible = nvx_supabase_relay_queue_claim_key( $dedupe_live_invisible );
$live_invisible_claim     = ( $GLOBALS['nvx_mock_time'] + 60 ) . '|live_invisible_generation';
$live_invisible_post_id   = wp_insert_post(
	array(
		'post_type'    => NVX_SUPABASE_RELAY_QUEUE_CPT,
		'post_status'  => NVX_SUPABASE_RELAY_QUEUE_PREPARED_STATUS,
		'post_content' => wp_slash( $body_live_invisible ),
	),
	true
);
$live_invisible_post_id = absint( $live_invisible_post_id );
add_post_meta( $live_invisible_post_id, '_nvx_relay_dedupe_key', $dedupe_live_invisible, true );
add_post_meta( $live_invisible_post_id, '_nvx_relay_publish_claim', $live_invisible_claim, true );
add_post_meta( $live_invisible_post_id, '_nvx_relay_endpoint', 'lead_captured', true );
$GLOBALS['nvx_mock_options'][ $claim_key_live_invisible ] = $live_invisible_claim;
$require_v3(
	false === nvx_supabase_relay_queue_recover_prepared_without_due( $live_invisible_post_id ),
	'INVISIBLE_ACTIVE_PREPARED_PRESERVED'
);
$live_invisible_post = get_post( $live_invisible_post_id );
$require_v3(
	$live_invisible_post instanceof WP_Post
	&& NVX_SUPABASE_RELAY_QUEUE_PREPARED_STATUS === $live_invisible_post->post_status,
	'INVISIBLE_ACTIVE_PREPARED_STILL_PREPARED'
);

// Incomplete row whose generation is expired must be quarantined without
// stealing or releasing a successor claim.
$body_abandoned       = '{"submission_id":"v3-invisible-abandoned"}';
$dedupe_abandoned     = nvx_supabase_relay_dedupe_key( 'lead_captured', $body_abandoned, '' );
$claim_key_abandoned  = nvx_supabase_relay_queue_claim_key( $dedupe_abandoned );
$expired_abandon_claim = ( $GLOBALS['nvx_mock_time'] - 10 ) . '|expired_abandoned_generation';
$abandoned_post_id    = wp_insert_post(
	array(
		'post_type'    => NVX_SUPABASE_RELAY_QUEUE_CPT,
		'post_status'  => NVX_SUPABASE_RELAY_QUEUE_PREPARED_STATUS,
		'post_content' => wp_slash( $body_abandoned ),
	),
	true
);
$abandoned_post_id = absint( $abandoned_post_id );
add_post_meta( $abandoned_post_id, '_nvx_relay_dedupe_key', $dedupe_abandoned, true );
add_post_meta( $abandoned_post_id, '_nvx_relay_publish_claim', $expired_abandon_claim, true );
add_post_meta( $abandoned_post_id, '_nvx_relay_endpoint', 'lead_captured', true );
$GLOBALS['nvx_mock_options'][ $claim_key_abandoned ] = $expired_abandon_claim;
$require_v3(
	false === nvx_supabase_relay_queue_recover_prepared_without_due( $abandoned_post_id ),
	'INVISIBLE_ABANDONED_RECOVERY_NOT_DRAINABLE'
);
$abandoned_post = get_post( $abandoned_post_id );
$require_v3(
	$abandoned_post instanceof WP_Post && 'draft' === $abandoned_post->post_status,
	'INVISIBLE_ABANDONED_PREPARED_QUARANTINED'
);
$require_v3(
	$expired_abandon_claim === (string) get_option( $claim_key_abandoned, '' ),
	'INVISIBLE_ABANDONED_CLAIM_NOT_STOLEN'
);

// ── Contract 4: a completed generation cannot be adopted after release. ─────
$body_stale      = '{"submission_id":"v3-stale-generation"}';
$dedupe_stale    = nvx_supabase_relay_dedupe_key( 'lead_captured', $body_stale, '' );
$claim_key_stale = nvx_supabase_relay_queue_claim_key( $dedupe_stale );
$stale_post_id   = wp_insert_post(
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
add_post_meta( $stale_post_id, '_nvx_relay_ready', '1', true );
add_post_meta( $stale_post_id, '_nvx_relay_next_attempt', (string) ( $GLOBALS['nvx_mock_time'] - 1 ), true );
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

// ── Contract 5: stale finalizer cannot delete a replacement publisher. ──────
$body_owner         = '{"submission_id":"v3-stale-finalizer"}';
$dedupe_owner       = nvx_supabase_relay_dedupe_key( 'lead_captured', $body_owner, '' );
$claim_key_owner    = nvx_supabase_relay_queue_claim_key( $dedupe_owner );
$canonical_owner_id = wp_insert_post(
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

// ── Contract 5b: candidate retirement cannot cross an ownership transfer. ──
// A concurrent lifecycle path transfers ownership after the candidate check.
// The retirement lifecycle protocol must abort and preserve the candidate.
$body_race       = '{"submission_id":"v3-interleaved-retirement"}';
$dedupe_race     = nvx_supabase_relay_dedupe_key( 'lead_captured', $body_race, '' );
$claim_key_race  = nvx_supabase_relay_queue_claim_key( $dedupe_race );
$canonical_id    = wp_insert_post(
	array(
		'post_type'    => NVX_SUPABASE_RELAY_QUEUE_CPT,
		'post_status'  => 'pending',
		'post_content' => wp_slash( $body_race ),
	),
	true
);
$canonical_id = absint( $canonical_id );
add_post_meta( $canonical_id, '_nvx_relay_dedupe_key', $dedupe_race, true );
add_post_meta( $canonical_id, '_nvx_relay_ready', '1', true );
$GLOBALS['nvx_mock_options'][ $claim_key_race ] = (string) $canonical_id;

$candidate_race_id = wp_insert_post(
	array(
		'post_type'    => NVX_SUPABASE_RELAY_QUEUE_CPT,
		'post_status'  => 'pending',
		'post_content' => wp_slash( $body_race ),
	),
	true
);
$candidate_race_id = absint( $candidate_race_id );
add_post_meta( $candidate_race_id, '_nvx_relay_dedupe_key', $dedupe_race, true );
add_post_meta( $candidate_race_id, '_nvx_relay_ready', '1', true );

// When the candidate is transitioned to draft, simulate a concurrent successor
// that adopts the candidate and claims ownership before physical deletion.
$GLOBALS['nvx_mock_hook_on_status_draft'] = static function ( int $post_id ) use ( $claim_key_race, $candidate_race_id ) {
	if ( $post_id === $candidate_race_id ) {
		$GLOBALS['nvx_mock_options'][ $claim_key_race ] = (string) $candidate_race_id;
	}
};

$retired_race = nvx_supabase_relay_queue_retire_duplicate_rows(
	$canonical_id,
	$dedupe_race,
	(string) $canonical_id
);
unset( $GLOBALS['nvx_mock_hook_on_status_draft'] );

$require_v3( false === $retired_race, 'INTERLEAVED_RETIREMENT_ABORTS_ON_OWNERSHIP_TRANSFER' );
$require_v3(
	isset( $GLOBALS['nvx_mock_posts'][ $candidate_race_id ] ),
	'INTERLEAVED_CANDIDATE_SURVIVES_OWNERSHIP_TRANSFER'
);
$require_v3(
	'pending' === ( $GLOBALS['nvx_mock_posts'][ $candidate_race_id ]->post_status ?? '' ),
	'INTERLEAVED_CANDIDATE_RESTORED_TO_PENDING'
);

// ── Contract 6: terminal claim fences the SUCCESS cleanup/release window. ────
$require_v3(
	function_exists( 'nvx_supabase_relay_queue_begin_terminal_lifecycle' )
	&& function_exists( 'nvx_supabase_relay_queue_finish_terminal_lifecycle' ),
	'TERMINAL_LIFECYCLE_OWNER_EXISTS'
);

$body_success      = '{"submission_id":"v3-terminal-success"}';
$dedupe_success    = nvx_supabase_relay_dedupe_key( 'lead_captured', $body_success, '' );
$claim_key_success = nvx_supabase_relay_queue_claim_key( $dedupe_success );
$success_post_id   = wp_insert_post(
	array(
		'post_type'    => NVX_SUPABASE_RELAY_QUEUE_CPT,
		'post_status'  => 'pending',
		'post_content' => wp_slash( $body_success ),
	),
	true
);
$success_post_id = absint( $success_post_id );
add_post_meta( $success_post_id, '_nvx_relay_dedupe_key', $dedupe_success, true );
add_post_meta( $success_post_id, '_nvx_relay_endpoint', 'lead_captured', true );
add_post_meta( $success_post_id, '_nvx_relay_ready', '1', true );
$GLOBALS['nvx_mock_options'][ $claim_key_success ] = (string) $success_post_id;

$success_terminal = nvx_supabase_relay_queue_begin_terminal_lifecycle( $success_post_id, $dedupe_success );
$require_v3( '' !== $success_terminal, 'SUCCESS_TERMINAL_FENCE_ACQUIRED' );
$require_v3( nvx_supabase_relay_queue_publish_claim_live( $success_terminal ), 'SUCCESS_TERMINAL_FENCE_LIVE' );

$late_success_claim = ( $GLOBALS['nvx_mock_time'] + 60 ) . '|late_success_generation';
$late_success_id    = wp_insert_post(
	array(
		'post_type'    => NVX_SUPABASE_RELAY_QUEUE_CPT,
		'post_status'  => NVX_SUPABASE_RELAY_QUEUE_PREPARED_STATUS,
		'post_content' => wp_slash( $body_success ),
	),
	true
);
$late_success_id = absint( $late_success_id );
add_post_meta( $late_success_id, '_nvx_relay_dedupe_key', $dedupe_success, true );
add_post_meta( $late_success_id, '_nvx_relay_publish_claim', $late_success_claim, true );
add_post_meta( $late_success_id, '_nvx_relay_endpoint', 'lead_captured', true );
add_post_meta( $late_success_id, '_nvx_relay_attempts', '1', true );
add_post_meta( $late_success_id, '_nvx_relay_ready', '1', true );
add_post_meta( $late_success_id, '_nvx_relay_next_attempt', (string) ( $GLOBALS['nvx_mock_time'] - 1 ), true );
$require_v3(
	false === nvx_supabase_relay_queue_acquire_publication_fence( $late_success_id, $dedupe_success ),
	'LATE_SUCCESS_GENERATION_BLOCKED_BY_TERMINAL_FENCE'
);
$require_v3(
	nvx_supabase_relay_queue_retire_duplicate_rows( $success_post_id, $dedupe_success, $success_terminal ),
	'SUCCESS_TERMINAL_FINAL_SWEEP_OWNED'
);
$require_v3( ! isset( $GLOBALS['nvx_mock_posts'][ $late_success_id ] ), 'LATE_SUCCESS_GENERATION_RETIRED' );
wp_delete_post( $success_post_id, true );
$require_v3(
	nvx_supabase_relay_queue_finish_terminal_lifecycle( $success_post_id, $dedupe_success, $success_terminal ),
	'SUCCESS_TERMINAL_RELEASE_CONDITIONAL'
);
$require_v3(
	! array_key_exists( $claim_key_success, $GLOBALS['nvx_mock_options'] ),
	'SUCCESS_TERMINAL_CLAIM_RELEASED'
);

// A publisher materialized after the final sweep but before release still
// cannot bind its stale generation after the terminal owner releases.
$body_escaped      = '{"submission_id":"v3-terminal-escaped"}';
$dedupe_escaped    = nvx_supabase_relay_dedupe_key( 'lead_captured', $body_escaped, '' );
$claim_key_escaped = nvx_supabase_relay_queue_claim_key( $dedupe_escaped );
$escaped_owner_id  = wp_insert_post(
	array(
		'post_type'    => NVX_SUPABASE_RELAY_QUEUE_CPT,
		'post_status'  => 'pending',
		'post_content' => wp_slash( $body_escaped ),
	),
	true
);
$escaped_owner_id = absint( $escaped_owner_id );
add_post_meta( $escaped_owner_id, '_nvx_relay_dedupe_key', $dedupe_escaped, true );
$GLOBALS['nvx_mock_options'][ $claim_key_escaped ] = (string) $escaped_owner_id;
$escaped_terminal = nvx_supabase_relay_queue_begin_terminal_lifecycle( $escaped_owner_id, $dedupe_escaped );
$require_v3( '' !== $escaped_terminal, 'ESCAPED_TERMINAL_FENCE_ACQUIRED' );
$require_v3(
	nvx_supabase_relay_queue_retire_duplicate_rows( $escaped_owner_id, $dedupe_escaped, $escaped_terminal ),
	'ESCAPED_TERMINAL_FINAL_SWEEP_OWNED'
);
wp_delete_post( $escaped_owner_id, true );

$escaped_late_id = wp_insert_post(
	array(
		'post_type'    => NVX_SUPABASE_RELAY_QUEUE_CPT,
		'post_status'  => NVX_SUPABASE_RELAY_QUEUE_PREPARED_STATUS,
		'post_content' => wp_slash( $body_escaped ),
	),
	true
);
$escaped_late_id = absint( $escaped_late_id );
add_post_meta( $escaped_late_id, '_nvx_relay_dedupe_key', $dedupe_escaped, true );
add_post_meta( $escaped_late_id, '_nvx_relay_publish_claim', ( $GLOBALS['nvx_mock_time'] + 60 ) . '|escaped_old_generation', true );
add_post_meta( $escaped_late_id, '_nvx_relay_ready', '1', true );
add_post_meta( $escaped_late_id, '_nvx_relay_next_attempt', (string) ( $GLOBALS['nvx_mock_time'] - 1 ), true );
$require_v3(
	nvx_supabase_relay_queue_finish_terminal_lifecycle( $escaped_owner_id, $dedupe_escaped, $escaped_terminal ),
	'ESCAPED_TERMINAL_RELEASE_CONDITIONAL'
);
$require_v3(
	false === nvx_supabase_relay_queue_acquire_publication_fence( $escaped_late_id, $dedupe_escaped ),
	'ESCAPED_OLD_GENERATION_NOT_ADOPTABLE_AFTER_RELEASE'
);
$require_v3( ! isset( $GLOBALS['nvx_mock_posts'][ $escaped_late_id ] ), 'ESCAPED_OLD_GENERATION_QUARANTINED_AFTER_RELEASE' );

// ── Contract 7: DEAD uses the same terminal fence before release. ────────────
$body_dead      = '{"submission_id":"v3-terminal-dead"}';
$dedupe_dead    = nvx_supabase_relay_dedupe_key( 'lead_captured', $body_dead, '' );
$claim_key_dead = nvx_supabase_relay_queue_claim_key( $dedupe_dead );
$dead_post_id   = wp_insert_post(
	array(
		'post_type'    => NVX_SUPABASE_RELAY_QUEUE_CPT,
		'post_status'  => 'pending',
		'post_content' => wp_slash( $body_dead ),
	),
	true
);
$dead_post_id = absint( $dead_post_id );
add_post_meta( $dead_post_id, '_nvx_relay_dedupe_key', $dedupe_dead, true );
add_post_meta( $dead_post_id, '_nvx_relay_endpoint', 'lead_captured', true );
$GLOBALS['nvx_mock_options'][ $claim_key_dead ] = (string) $dead_post_id;
$dead_terminal = nvx_supabase_relay_queue_begin_terminal_lifecycle( $dead_post_id, $dedupe_dead );
$require_v3( '' !== $dead_terminal, 'DEAD_TERMINAL_FENCE_ACQUIRED' );
$require_v3(
	nvx_supabase_relay_queue_retire_duplicate_rows( $dead_post_id, $dedupe_dead, $dead_terminal ),
	'DEAD_TERMINAL_INITIAL_SWEEP_OWNED'
);
$dead_update = wp_update_post( array( 'ID' => $dead_post_id, 'post_status' => 'draft' ), true );
$require_v3( ! is_wp_error( $dead_update ) && absint( $dead_update ) === $dead_post_id, 'DEAD_CANONICAL_TRANSITION' );

$late_dead_id = wp_insert_post(
	array(
		'post_type'    => NVX_SUPABASE_RELAY_QUEUE_CPT,
		'post_status'  => NVX_SUPABASE_RELAY_QUEUE_PREPARED_STATUS,
		'post_content' => wp_slash( $body_dead ),
	),
	true
);
$late_dead_id = absint( $late_dead_id );
add_post_meta( $late_dead_id, '_nvx_relay_dedupe_key', $dedupe_dead, true );
add_post_meta( $late_dead_id, '_nvx_relay_publish_claim', ( $GLOBALS['nvx_mock_time'] + 60 ) . '|late_dead_generation', true );
add_post_meta( $late_dead_id, '_nvx_relay_ready', '1', true );
add_post_meta( $late_dead_id, '_nvx_relay_next_attempt', (string) ( $GLOBALS['nvx_mock_time'] - 1 ), true );
$require_v3(
	nvx_supabase_relay_queue_retire_duplicate_rows( $dead_post_id, $dedupe_dead, $dead_terminal ),
	'DEAD_TERMINAL_FINAL_SWEEP_OWNED'
);
$require_v3( ! isset( $GLOBALS['nvx_mock_posts'][ $late_dead_id ] ), 'LATE_DEAD_GENERATION_RETIRED' );
$require_v3(
	nvx_supabase_relay_queue_finish_terminal_lifecycle( $dead_post_id, $dedupe_dead, $dead_terminal ),
	'DEAD_TERMINAL_RELEASE_CONDITIONAL'
);
$require_v3(
	! array_key_exists( $claim_key_dead, $GLOBALS['nvx_mock_options'] ),
	'DEAD_TERMINAL_CLAIM_RELEASED'
);

// Source ratchet: both terminal outcomes use one atomic authority boundary and
// invisible prepared rows have an explicit NOT EXISTS recovery owner.
$require_v3(
	str_contains( $queue_source, 'nvx_supabase_relay_queue_complete_terminal_state' )
	&& str_contains( $queue_source, 'nvx_supabase_relay_queue_begin_terminal_lifecycle' )
	&& str_contains( $queue_source, 'nvx_supabase_relay_queue_finish_terminal_lifecycle' )
	&& str_contains( $queue_source, 'START TRANSACTION' )
	&& str_contains( $queue_source, 'FOR UPDATE' ),
	'TERMINAL_ATOMIC_BOUNDARY_SOURCE_INTEGRITY'
);
$require_v3(
	str_contains( $queue_source, 'nvx_supabase_relay_queue_recover_prepared_without_due' )
	&& str_contains( $queue_source, 'nvx_supabase_relay_queue_recover_invisible_prepared' )
	&& str_contains( $queue_source, "'compare' => 'NOT EXISTS'" ),
	'INVISIBLE_PREPARED_RECOVERY_SOURCE_INTEGRITY'
);
$require_v3(
	str_contains( $queue_source, 'nvx_supabase_relay_queue_item_adoptable_for_claim' )
	&& str_contains( $queue_source, '_nvx_relay_publish_claim' )
	&& str_contains( $queue_source, '_nvx_relay_ready' )
	&& str_contains( $queue_source, "post_status' => 'draft'" ),
	'OUTBOX_V3_SOURCE_INTEGRITY'
);

if ( ! empty( $failures_v3 ) ) {
	fwrite( STDERR, 'OUTBOX_TERMINAL_OWNERSHIP_V3=FAIL count=' . count( $failures_v3 ) . "\n" );
	exit( 1 );
}

echo "OUTBOX_TERMINAL_OWNERSHIP_V3=PASS generation_fenced=1 readiness_explicit=1 due_visibility_last=1 active_prepare_safe=1 invisible_prepare_recovered=1 active_invisible_preserved=1 abandoned_prepare_quarantined=1 stale_generation_rejected=1 stale_finalizer_safe=1 success_terminal_atomic=1 dead_terminal_atomic=1 escaped_generation_rejected=1 interleaved_retirement_safe=1\n";

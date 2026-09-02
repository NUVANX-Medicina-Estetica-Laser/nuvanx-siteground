<?php
/**
 * Behavioral contract for Supabase relay outbox — atomic-claim design.
 *
 * Ownership is acquired via add_option() whose option_name UNIQUE index makes the INSERT
 * atomic at the DB level. This harness models that design.
 *
 * Invariants tested:
 *   1. CLAIM_ACQUIRED          — first enqueue wins the add_option claim.
 *   2. CLAIM_IDEMPOTENT        — second enqueue for same body routes to existing item.
 *   3. CLAIM_BOUND_TO_POST_ID  — after publish, claim option value equals post_id.
 *   4. PENDING_SINGLE_PHASE    — post is inserted directly as pending (not draft).
 *   5. METADATA_FAIL_RELEASES  — meta failure deletes post and deletes claim option.
 *   6. ORPHAN_RECOVERY         — enqueue with stale claim=0 deletes orphan and re-owns.
 *   7. ATTEMPTS_MONOTONIC      — record_existing_attempt never decreases _nvx_relay_attempts.
 *   8. SOURCE_INTEGRITY        — claim_key prefix and atomic acquisition present in source;
 *                                no draft status in enqueue function.
 *
 * @package nuvanx-medical
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'NVX_SUPABASE_RELAY_QUEUE_LOCK_TTL', 60 );
define( 'NVX_SUPABASE_RELAY_QUEUE_CAS_MAX_ATTEMPTS', 4 );

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public $ID           = 0;
		public $post_status  = 'pending';
		public $post_content = '';
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	final class WP_Error {
		public function __construct(
			private string $code    = '',
			private string $message = ''
		) {}
		public function get_error_code(): string { return $this->code; }
	}
}

// WordPress stubs
function add_action( ...$args ): void { unset( $args ); }
function add_filter( ...$args ): void { unset( $args ); }
function register_post_type( ...$args ): void { unset( $args ); }
function wp_next_scheduled( ...$args ) { unset( $args ); return false; }
function wp_schedule_event( ...$args ): bool { unset( $args ); return true; }
function wp_clear_scheduled_hook( ...$args ): int { unset( $args ); return 1; }
function sanitize_key( $value ): string { return preg_replace( '/[^a-z0-9_-]/i', '', (string) $value ) ?? ''; }
function sanitize_text_field( $value ): string { return trim( (string) $value ); }
function wp_slash( $value ): string { return addslashes( (string) $value ); }
function absint( $value ): int { return abs( (int) $value ); }
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function nvx_lead_captured_endpoint(): string { return 'https://collector.example.test/functions/v1/web-lead-captured'; }

// Mock state
$GLOBALS['nvx_mock_options']                  = array();
$GLOBALS['nvx_mock_post_meta']                = array();
$GLOBALS['nvx_mock_meta_permanent_fail_keys'] = array();
$GLOBALS['nvx_mock_posts']                    = array();
$GLOBALS['nvx_mock_next_post_id']             = 100;
$GLOBALS['nvx_mock_deleted_options']          = array();
$GLOBALS['nvx_mock_deleted_posts']            = array();
$GLOBALS['nvx_mock_insert_failure']           = false;
$GLOBALS['nvx_mock_meta_failure_on_post']     = 0;

// Option mocks
function get_option( string $key, $default = false ) {
	return $GLOBALS['nvx_mock_options'][ $key ] ?? $default;
}
function add_option( string $key, $value, $deprecated = '', $autoload = 'yes' ): bool {
	if ( array_key_exists( $key, $GLOBALS['nvx_mock_options'] ) ) { return false; }
	$GLOBALS['nvx_mock_options'][ $key ] = (string) $value;
	return true;
}
function update_option( string $key, $value, $autoload = null ): bool {
	$GLOBALS['nvx_mock_options'][ $key ] = (string) $value;
	return true;
}
function delete_option( string $key ): bool {
	$GLOBALS['nvx_mock_deleted_options'][] = $key;
	unset( $GLOBALS['nvx_mock_options'][ $key ] );
	return true;
}

// Post / meta mocks
function get_post( $post_id ) {
	$post_id = (int) $post_id;
	return $GLOBALS['nvx_mock_posts'][ $post_id ] ?? null;
}
function get_posts( $args = array() ): array {
	$meta_query = $args['meta_query'] ?? array();
	$result     = array();
	foreach ( $GLOBALS['nvx_mock_posts'] as $id => $post ) {
		if ( ( $args['post_status'] ?? '' ) !== ( $post->post_status ?? '' ) ) { continue; }
		$match = true;
		foreach ( $meta_query as $mq ) {
			$key   = $mq['key']   ?? '';
			$val   = $mq['value'] ?? '';
			$found = $GLOBALS['nvx_mock_post_meta'][ $id ][ $key ] ?? '';
			if ( $found !== $val ) { $match = false; break; }
		}
		if ( $match ) { $result[] = $id; }
	}
	return $result;
}
function get_post_meta( $post_id, $key = '', $single = false ) {
	return $GLOBALS['nvx_mock_post_meta'][ (int) $post_id ][ (string) $key ] ?? '';
}
function update_post_meta( $post_id, $key, $value, $prev_value = '' ): bool {
	$post_id = (int) $post_id;
	$key     = (string) $key;
	if ( in_array( $key, $GLOBALS['nvx_mock_meta_permanent_fail_keys'], true ) ) { return false; }
	if ( '' !== $prev_value && ( $GLOBALS['nvx_mock_post_meta'][ $post_id ][ $key ] ?? '' ) !== (string) $prev_value ) { return false; }
	$GLOBALS['nvx_mock_post_meta'][ $post_id ][ $key ] = (string) $value;
	return true;
}
function add_post_meta( $post_id, $key, $value, $unique = false ): bool {
	$post_id = (int) $post_id;
	$key     = (string) $key;
	if ( $post_id === $GLOBALS['nvx_mock_meta_failure_on_post'] ) { return false; }
	if ( $unique && array_key_exists( $key, $GLOBALS['nvx_mock_post_meta'][ $post_id ] ?? array() ) ) { return false; }
	$GLOBALS['nvx_mock_post_meta'][ $post_id ][ $key ] = (string) $value;
	return true;
}
function wp_insert_post( $postarr = array(), $wp_error = false ) {
	if ( ! empty( $GLOBALS['nvx_mock_insert_failure'] ) ) {
		return $wp_error ? new WP_Error( 'mock_insert_failed', 'Mock insert failure.' ) : 0;
	}
	$id                               = ++$GLOBALS['nvx_mock_next_post_id'];
	$o                                = new WP_Post();
	$o->ID                            = $id;
	$o->post_status                   = (string) ( $postarr['post_status'] ?? 'pending' );
	$o->post_content                  = stripslashes( (string) ( $postarr['post_content'] ?? '' ) );
	$GLOBALS['nvx_mock_posts'][ $id ] = $o;
	return $id;
}
function wp_update_post( $postarr = array(), $wp_error = false ) {
	$id = (int) ( $postarr['ID'] ?? 0 );
	if ( $id < 1 || ! isset( $GLOBALS['nvx_mock_posts'][ $id ] ) ) {
		return $wp_error ? new WP_Error( 'mock_post_missing', 'Mock post missing.' ) : 0;
	}
	if ( isset( $postarr['post_status'] ) ) {
		$GLOBALS['nvx_mock_posts'][ $id ]->post_status = (string) $postarr['post_status'];
	}
	return $id;
}
function wp_delete_post( $post_id, $force_delete = false ) {
	$post_id                             = (int) $post_id;
	$GLOBALS['nvx_mock_deleted_posts'][] = $post_id;
	$post                                = $GLOBALS['nvx_mock_posts'][ $post_id ] ?? null;
	unset( $GLOBALS['nvx_mock_posts'][ $post_id ], $GLOBALS['nvx_mock_post_meta'][ $post_id ] );
	return $post;
}
function wp_cache_delete( $key, $group = '' ): void {}
function wp_cache_set( $key, $value, $group = '', $expire = 0 ): bool { return true; }

// Load production code
$queue_path = dirname( __DIR__, 2 )
	. '/wp-content/themes/nuvanx-medical/inc/nvx-supabase-relay-queue.php';
require_once $queue_path;

// Test helper
$failures = array();
$require  = static function ( bool $condition, string $name ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $name;
		fwrite( STDERR, "FAIL: {$name}\n" );
	}
};

// ── Invariant 1: CLAIM_ACQUIRED ──────────────────────────────────────────────
// First enqueue wins the add_option claim and returns a positive post_id.
$body1   = '{"submission_id":"inv1"}';
$post_id = nvx_supabase_relay_queue_enqueue( 'lead_captured', $body1, array(), 1 );
$require( $post_id > 0, 'CLAIM_ACQUIRED' );

$dedupe_key1 = nvx_supabase_relay_dedupe_key( 'lead_captured', $body1, '' );
$claim_key1  = nvx_supabase_relay_queue_claim_key( $dedupe_key1 );
$require( array_key_exists( $claim_key1, $GLOBALS['nvx_mock_options'] ), 'CLAIM_OPTION_EXISTS' );

// ── Invariant 2: CLAIM_IDEMPOTENT ────────────────────────────────────────────
// Second enqueue for same body returns the existing post_id; no new post created.
$post_id2 = nvx_supabase_relay_queue_enqueue( 'lead_captured', $body1, array(), 1 );
$require( $post_id2 === $post_id, 'CLAIM_IDEMPOTENT_RETURNS_EXISTING' );
$require( 1 === count( $GLOBALS['nvx_mock_posts'] ), 'NO_DUPLICATE_POST_CREATED' );

// ── Invariant 3: CLAIM_BOUND_TO_POST_ID ──────────────────────────────────────
// After successful enqueue the claim option value equals the post_id string.
$claim_val = (string) get_option( $claim_key1, '0' );
$require( $claim_val === (string) $post_id, 'CLAIM_BOUND_TO_POST_ID' );

// ── Invariant 4: PENDING_SINGLE_PHASE ────────────────────────────────────────
// Post is inserted directly as pending — no draft intermediate status.
$post_obj = get_post( $post_id );
$require( $post_obj instanceof WP_Post && 'pending' === $post_obj->post_status, 'PENDING_SINGLE_PHASE' );

// ── Invariant 5: METADATA_FAIL_RELEASES ──────────────────────────────────────
// When add_post_meta fails the post is deleted and the claim option is released.
$body5           = '{"submission_id":"inv5-meta-fail"}';
$post5_expected  = $GLOBALS['nvx_mock_next_post_id'] + 1;
$GLOBALS['nvx_mock_meta_failure_on_post'] = $post5_expected;

$result5 = nvx_supabase_relay_queue_enqueue( 'lead_captured', $body5, array(), 1 );
$GLOBALS['nvx_mock_meta_failure_on_post'] = 0;

$require( 0 === $result5, 'METADATA_FAIL_RETURNS_ZERO' );
$require( in_array( $post5_expected, $GLOBALS['nvx_mock_deleted_posts'], true ), 'METADATA_FAIL_DELETES_POST' );

$dedupe_key5 = nvx_supabase_relay_dedupe_key( 'lead_captured', $body5, '' );
$claim_key5  = nvx_supabase_relay_queue_claim_key( $dedupe_key5 );
$require( ! array_key_exists( $claim_key5, $GLOBALS['nvx_mock_options'] ), 'METADATA_FAIL_RELEASES_CLAIM' );

// ── Invariant 6: ORPHAN_RECOVERY ─────────────────────────────────────────────
// Claim option present with value '0' and no matching post → enqueue deletes
// orphan, re-acquires, and publishes successfully.
$body6       = '{"submission_id":"inv6-orphan"}';
$dedupe_key6 = nvx_supabase_relay_dedupe_key( 'lead_captured', $body6, '' );
$claim_key6  = nvx_supabase_relay_queue_claim_key( $dedupe_key6 );

// Plant an orphaned claim (value '0', no corresponding post).
$GLOBALS['nvx_mock_options'][ $claim_key6 ] = '0';

$result6 = nvx_supabase_relay_queue_enqueue( 'lead_captured', $body6, array(), 1 );
$require( $result6 > 0, 'ORPHAN_RECOVERY_SUCCEEDS' );
$require( (string) $result6 === (string) get_option( $claim_key6, '0' ), 'ORPHAN_RECOVERY_CLAIM_REBOUND' );
$require( in_array( $claim_key6, $GLOBALS['nvx_mock_deleted_options'], true ), 'ORPHAN_CLAIM_DELETED_BEFORE_REOWN' );

// ── Invariant 7: ATTEMPTS_MONOTONIC ──────────────────────────────────────────
// record_existing_attempt accumulates attempts and never decreases the counter.
$body7 = '{"submission_id":"inv7-monotonic"}';
$post7 = nvx_supabase_relay_queue_enqueue( 'lead_captured', $body7, array(), 1 );
$require( $post7 > 0, 'INV7_INITIAL_ENQUEUE' );

$before = absint( get_post_meta( $post7, '_nvx_relay_attempts', true ) );
nvx_supabase_relay_queue_record_existing_attempt( $post7, 'lead_captured', 2 );
$after = absint( get_post_meta( $post7, '_nvx_relay_attempts', true ) );
$require( $after >= $before, 'ATTEMPTS_MONOTONIC_NON_DECREASING' );
$require( $after === $before + 2, 'ATTEMPTS_MONOTONIC_CORRECT_INCREMENT' );

// ── Invariant 8: SOURCE_INTEGRITY ────────────────────────────────────────────
// Static guard: atomic acquisition primitives are present in the source; the
// enqueue function does not contain a draft post_status (single-phase design).
$src           = (string) file_get_contents( $queue_path );
$require( false !== strpos( $src, 'nvx_relay_claim_' ), 'CLAIM_KEY_PREFIX_IN_SOURCE' );
$require( false !== strpos( $src, 'add_option( $claim_key' ), 'ATOMIC_ACQUISITION_IN_SOURCE' );

// Locate enqueue body to verify no draft insertion remains.
$enqueue_fn_pos = strpos( $src, 'function nvx_supabase_relay_queue_enqueue' );
$post_id_return = strrpos( $src, 'return $post_id;' );
$enqueue_body   = ( false !== $enqueue_fn_pos && false !== $post_id_return )
	? substr( $src, (int) $enqueue_fn_pos, (int) $post_id_return - (int) $enqueue_fn_pos )
	: '';
$require( false === strpos( $enqueue_body, "'post_status' => 'draft'" ), 'NO_DRAFT_STATUS_IN_ENQUEUE' );

// Results
if ( ! empty( $failures ) ) {
	fwrite(
		STDERR,
		'OUTBOX_CONCURRENCY_V2=FAIL failures=' . implode( ',', $failures ) . "\n"
	);
	exit( 1 );
}

echo "OUTBOX_CONCURRENCY_V2=PASS atomic_claim=1 idempotent=1 single_phase=1 orphan_recovery=1 meta_fail_safe=1 attempts_monotonic=1 source_integrity=1\n";

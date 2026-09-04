<?php
/**
 * Behavioral contract for Supabase relay outbox — atomic-claim design.
 *
 * Ownership is acquired via add_option() with an in-flight lease/token ($expiry|$token).
 *
 * Invariants tested:
 *   1. CLAIM_ACQUIRED          — first enqueue wins the add_option claim.
 *   2. CLAIM_IDEMPOTENT        — second enqueue for same body routes to existing item.
 *   3. CLAIM_BOUND_TO_POST_ID  — after publish, claim option value equals post_id.
 *   4. PUBLICATION_FENCE       — pending status is not drain authority without the bound claim.
 *   5. METADATA_FAIL_RELEASES  — meta failure deletes post and releases claim option.
 *   6. ORPHAN_RECOVERY         — enqueue with expired in-flight claim takes over via CAS.
 *   7. ATTEMPTS_MONOTONIC      — record_existing_attempt never decreases _nvx_relay_attempts.
 *   8. INTERLEAVED_OWNER_SAFE  — active owner lease is never stolen or deleted by contender.
 *   9. LIFECYCLE_RELEASE       — completed/dead relays release claim so future retries succeed.
 *  10. ROLLOUT_ADOPTION        — legacy pending posts without claim option are safely adopted.
 *  11. SOURCE_INTEGRITY        — atomic primitives, release helpers, and publication fence in source.
 *  12. EXPIRED_PUBLISHER       — lease expiry produces exactly one fenced, drainable retry.
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
$GLOBALS['nvx_mock_time']                     = 1000000;
$GLOBALS['nvx_mock_options']                  = array();
$GLOBALS['nvx_mock_post_meta']                = array();
$GLOBALS['nvx_mock_meta_permanent_fail_keys'] = array();
$GLOBALS['nvx_mock_posts']                    = array();
$GLOBALS['nvx_mock_next_post_id']             = 100;
$GLOBALS['nvx_mock_deleted_options']          = array();
$GLOBALS['nvx_mock_deleted_posts']            = array();
$GLOBALS['nvx_mock_insert_failure']           = false;
$GLOBALS['nvx_mock_meta_failure_on_post']     = 0;
$GLOBALS['nvx_mock_adopt_inserted_post']      = false;
$GLOBALS['nvx_mock_update_failure_on_post']   = 0;

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
	$statuses   = (array) ( $args['post_status'] ?? array() );
	$result     = array();
	foreach ( $GLOBALS['nvx_mock_posts'] as $id => $post ) {
		if ( ! in_array( ( $post->post_status ?? '' ), $statuses, true ) ) { continue; }
		$match = true;
		foreach ( $meta_query as $mq ) {
			$key     = $mq['key']     ?? '';
			$val     = $mq['value']   ?? '';
			$compare = strtoupper( (string) ( $mq['compare'] ?? '=' ) );
			$exists  = array_key_exists( $key, $GLOBALS['nvx_mock_post_meta'][ $id ] ?? array() );
			if ( 'NOT EXISTS' === $compare ) {
				if ( $exists && '' !== (string) ( $GLOBALS['nvx_mock_post_meta'][ $id ][ $key ] ?? '' ) ) {
					$match = false;
					break;
				}
				continue;
			}
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
	if ( '_nvx_relay_dedupe_key' === $key && ! empty( $GLOBALS['nvx_mock_adopt_inserted_post'] ) ) {
		$GLOBALS['nvx_mock_options'][ 'nvx_relay_claim_' . (string) $value ] = (string) $post_id;
		$GLOBALS['nvx_mock_adopt_inserted_post'] = false;
	}
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
	if ( $id === $GLOBALS['nvx_mock_update_failure_on_post'] ) {
		return $wp_error ? new WP_Error( 'mock_update_failed', 'Mock update failure.' ) : 0;
	}
	if ( $id < 1 || ! isset( $GLOBALS['nvx_mock_posts'][ $id ] ) ) {
		return $wp_error ? new WP_Error( 'mock_post_missing', 'Mock post missing.' ) : 0;
	}
	if ( isset( $postarr['post_status'] ) ) {
		$GLOBALS['nvx_mock_posts'][ $id ]->post_status = (string) $postarr['post_status'];
		if ( 'draft' === $postarr['post_status'] && isset( $GLOBALS['nvx_mock_hook_on_status_draft'] ) && is_callable( $GLOBALS['nvx_mock_hook_on_status_draft'] ) ) {
			( $GLOBALS['nvx_mock_hook_on_status_draft'] )( $id );
		}
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
$body1   = '{"submission_id":"inv1"}';
$post_id = nvx_supabase_relay_queue_enqueue( 'lead_captured', $body1, array(), 1 );
$require( $post_id > 0, 'CLAIM_ACQUIRED' );

$dedupe_key1 = nvx_supabase_relay_dedupe_key( 'lead_captured', $body1, '' );
$claim_key1  = nvx_supabase_relay_queue_claim_key( $dedupe_key1 );
$require( array_key_exists( $claim_key1, $GLOBALS['nvx_mock_options'] ), 'CLAIM_OPTION_EXISTS' );

// ── Invariant 2: CLAIM_IDEMPOTENT ────────────────────────────────────────────
$post_id2 = nvx_supabase_relay_queue_enqueue( 'lead_captured', $body1, array(), 1 );
$require( $post_id2 === $post_id, 'CLAIM_IDEMPOTENT_RETURNS_EXISTING' );
$require( 1 === count( $GLOBALS['nvx_mock_posts'] ), 'NO_DUPLICATE_POST_CREATED' );

// ── Invariant 3: CLAIM_BOUND_TO_POST_ID ──────────────────────────────────────
$claim_val = (string) get_option( $claim_key1, '0' );
$require( $claim_val === (string) $post_id, 'CLAIM_BOUND_TO_POST_ID' );

// ── Invariant 4: PUBLICATION_FENCE ───────────────────────────────────────────
$post_obj = get_post( $post_id );
$require( $post_obj instanceof WP_Post && 'pending' === $post_obj->post_status, 'PENDING_ROW_PERSISTED' );
$require( nvx_supabase_relay_queue_acquire_publication_fence( $post_id, $dedupe_key1 ), 'BOUND_PENDING_IS_DRAINABLE' );

// ── Invariant 5: METADATA_FAIL_RELEASES ──────────────────────────────────────
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
// Abandoned claim (expired timestamp) is taken over via CAS and successfully published.
$body6       = '{"submission_id":"inv6-orphan"}';
$dedupe_key6 = nvx_supabase_relay_dedupe_key( 'lead_captured', $body6, '' );
$claim_key6  = nvx_supabase_relay_queue_claim_key( $dedupe_key6 );

// Plant an abandoned/expired in-flight claim (expired timestamp in past).
$GLOBALS['nvx_mock_options'][ $claim_key6 ] = ( $GLOBALS['nvx_mock_time'] - 10 ) . '|crashed_token';

$result6 = nvx_supabase_relay_queue_enqueue( 'lead_captured', $body6, array(), 1 );
$require( $result6 > 0, 'ORPHAN_RECOVERY_SUCCEEDS' );
$require( (string) $result6 === (string) get_option( $claim_key6, '0' ), 'ORPHAN_RECOVERY_CLAIM_REBOUND' );

// ── Invariant 7: ATTEMPTS_MONOTONIC ──────────────────────────────────────────
$body7 = '{"submission_id":"inv7-monotonic"}';
$post7 = nvx_supabase_relay_queue_enqueue( 'lead_captured', $body7, array(), 1 );
$require( $post7 > 0, 'INV7_INITIAL_ENQUEUE' );

$before = absint( get_post_meta( $post7, '_nvx_relay_attempts', true ) );
nvx_supabase_relay_queue_record_existing_attempt( $post7, 'lead_captured', 2 );
$after = absint( get_post_meta( $post7, '_nvx_relay_attempts', true ) );
$require( $after >= $before, 'ATTEMPTS_MONOTONIC_NON_DECREASING' );
$require( $after === $before + 2, 'ATTEMPTS_MONOTONIC_CORRECT_INCREMENT' );

// ── Invariant 8: INTERLEAVED_OWNER_SAFE (Devin Comment 1) ────────────────────
// An active publisher that pauses before insertion cannot have its claim stolen or deleted by a contender.
$body8       = '{"submission_id":"inv8-interleaved"}';
$dedupe_key8 = nvx_supabase_relay_dedupe_key( 'lead_captured', $body8, '' );
$claim_key8  = nvx_supabase_relay_queue_claim_key( $dedupe_key8 );

// Owner 1 claims the identity with an active lease.
$owner1_token   = 'owner_one_active_token';
$owner1_expiry  = $GLOBALS['nvx_mock_time'] + 60;
$owner1_inflight = $owner1_expiry . '|' . $owner1_token;
$GLOBALS['nvx_mock_options'][ $claim_key8 ] = $owner1_inflight;

// Owner 2 contends while Owner 1 is still in flight.
$contender_res = nvx_supabase_relay_queue_enqueue( 'lead_captured', $body8, array(), 1 );

// Contender must NOT have stolen, overwritten, or deleted Owner 1's claim.
$require( ( $GLOBALS['nvx_mock_options'][ $claim_key8 ] ?? '' ) === $owner1_inflight, 'CONTENDER_PRESERVED_OWNER1_CLAIM' );
$require( ! in_array( $claim_key8, $GLOBALS['nvx_mock_deleted_options'], true ), 'CONTENDER_DID_NOT_DELETE_ACTIVE_CLAIM' );

// Owner 1 completes publication and binds the claim.
$owner1_post_id = wp_insert_post( array( 'post_status' => 'pending', 'post_content' => $body8 ) );
add_post_meta( $owner1_post_id, '_nvx_relay_dedupe_key', $dedupe_key8, true );
add_post_meta( $owner1_post_id, '_nvx_relay_attempts', '1', true );
update_option( $claim_key8, (string) $owner1_post_id );

// Now a new enqueue routes cleanly to Owner 1's post.
$subsequent_res = nvx_supabase_relay_queue_enqueue( 'lead_captured', $body8, array(), 1 );
$require( $subsequent_res === $owner1_post_id, 'SUBSEQUENT_ROUTES_TO_OWNER1' );

// ── Invariant 9: LIFECYCLE_RELEASE (Devin Comment 2) ─────────────────────────
// Completed relays and dead relays release their claims so future retries succeed.
// Subcase 9a: Retrying after successful drain
$body9a   = '{"submission_id":"inv9a-drained"}';
$post_9a  = nvx_supabase_relay_queue_enqueue( 'lead_captured', $body9a, array(), 1 );
$dedupe9a = nvx_supabase_relay_dedupe_key( 'lead_captured', $body9a, '' );
$claim9a  = nvx_supabase_relay_queue_claim_key( $dedupe9a );

// Simulate successful drain: post deleted, claim released.
wp_delete_post( $post_9a, true );
nvx_supabase_relay_queue_release_claim( $dedupe9a, (string) $post_9a );

// New enqueue for the same body creates a new deliverable post.
$post_9a_retry = nvx_supabase_relay_queue_enqueue( 'lead_captured', $body9a, array(), 1 );
$require( $post_9a_retry > 0 && $post_9a_retry !== $post_9a, 'RETRY_AFTER_DRAIN_CREATES_NEW_POST' );
$require( get_post( $post_9a_retry ) instanceof WP_Post, 'RETRY_POST_EXISTS' );

// Subcase 9b: Retrying after terminal death (mark_dead)
$body9b   = '{"submission_id":"inv9b-dead"}';
$post_9b  = nvx_supabase_relay_queue_enqueue( 'lead_captured', $body9b, array(), 1 );
$dedupe9b = nvx_supabase_relay_dedupe_key( 'lead_captured', $body9b, '' );

nvx_supabase_relay_queue_mark_dead( $post_9b, 'lead_captured', 500, 'fatal_error' );
$require( 'draft' === get_post( $post_9b )->post_status, 'DEAD_ITEM_IS_DRAFT' );

// New enqueue for the same body creates a fresh deliverable pending post.
$post_9b_retry = nvx_supabase_relay_queue_enqueue( 'lead_captured', $body9b, array(), 1 );
$require( $post_9b_retry > 0 && $post_9b_retry !== $post_9b, 'RETRY_AFTER_DEAD_CREATES_NEW_POST' );
$require( 'pending' === get_post( $post_9b_retry )->post_status, 'NEW_RETRY_POST_IS_PENDING' );

// Subcase 9c: Stale claim option pointing to deleted post is atomically taken over.
$body9c   = '{"submission_id":"inv9c-stale-opt"}';
$dedupe9c = nvx_supabase_relay_dedupe_key( 'lead_captured', $body9c, '' );
$claim9c  = nvx_supabase_relay_queue_claim_key( $dedupe9c );
$GLOBALS['nvx_mock_options'][ $claim9c ] = '99999'; // Non-existent post ID

$post_9c = nvx_supabase_relay_queue_enqueue( 'lead_captured', $body9c, array(), 1 );
$require( $post_9c > 0 && 99999 !== $post_9c, 'STALE_OPTION_OVERTAKEN_BY_NEW_POST' );
$require( (string) $post_9c === (string) get_option( $claim9c, '' ), 'STALE_CLAIM_BOUND_TO_NEW_POST' );

// ── Invariant 10: ROLLOUT_ADOPTION (Devin Comment 3) ─────────────────────────
// Legacy pending post without claim option is safely adopted; no duplicate created.
$body10       = '{"submission_id":"inv10-legacy"}';
$dedupe_key10 = nvx_supabase_relay_dedupe_key( 'lead_captured', $body10, '' );
$claim_key10  = nvx_supabase_relay_queue_claim_key( $dedupe_key10 );

// Seed legacy pending post with no option.
$legacy_post_id = ++$GLOBALS['nvx_mock_next_post_id'];
$legacy_post    = new WP_Post();
$legacy_post->ID          = $legacy_post_id;
$legacy_post->post_status = 'pending';
$GLOBALS['nvx_mock_posts'][ $legacy_post_id ] = $legacy_post;
$GLOBALS['nvx_mock_post_meta'][ $legacy_post_id ]['_nvx_relay_dedupe_key'] = $dedupe_key10;
$GLOBALS['nvx_mock_post_meta'][ $legacy_post_id ]['_nvx_relay_attempts']   = '1';
unset( $GLOBALS['nvx_mock_options'][ $claim_key10 ] );

$total_posts_before = count( $GLOBALS['nvx_mock_posts'] );
$adopted_id         = nvx_supabase_relay_queue_enqueue( 'lead_captured', $body10, array(), 1 );

$require( $adopted_id === $legacy_post_id, 'LEGACY_POST_ADOPTED' );
$require( count( $GLOBALS['nvx_mock_posts'] ) === $total_posts_before, 'NO_DUPLICATE_POST_ON_ADOPTION' );
$require( (string) get_option( $claim_key10, '' ) === (string) $legacy_post_id, 'CLAIM_BOUND_TO_ADOPTED_POST' );

// ── Invariant 12: EXPIRED_PUBLISHER_LOST_BIND_CLEANS_UP (Devin Review) ───────
// If publication exceeds lease TTL and a contender takes over the claim,
// the original owner's CAS bind fails. The redundant pending post must be deleted
// and the caller must route to the winning contender's item without duplicate delivery.
$body12       = '{"submission_id":"inv12-expired-publish"}';
$dedupe_key12 = nvx_supabase_relay_dedupe_key( 'lead_captured', $body12, '' );
$claim_key12  = nvx_supabase_relay_queue_claim_key( $dedupe_key12 );

// Seed contender's valid pending post
$contender_post_id = ++$GLOBALS['nvx_mock_next_post_id'];
$contender_post    = new WP_Post();
$contender_post->ID          = $contender_post_id;
$contender_post->post_status = 'pending';
$GLOBALS['nvx_mock_posts'][ $contender_post_id ] = $contender_post;
$GLOBALS['nvx_mock_post_meta'][ $contender_post_id ]['_nvx_relay_dedupe_key'] = $dedupe_key12;
$GLOBALS['nvx_mock_post_meta'][ $contender_post_id ]['_nvx_relay_attempts']   = '1';

// When original publisher attempts to CAS bind the claim to its post_id,
// simulate a contender having taken over the option with $contender_post_id.
$GLOBALS['nvx_mock_option_cas_conflict_values'][ $claim_key12 ] = (string) $contender_post_id;

$posts_before = count( $GLOBALS['nvx_mock_posts'] );
$returned_id  = nvx_supabase_relay_queue_enqueue( 'lead_captured', $body12, array(), 1 );

$require( $returned_id === $contender_post_id, 'EXPIRED_PUBLISHER_ROUTES_TO_CONTENDER' );
$require( count( $GLOBALS['nvx_mock_posts'] ) === $posts_before, 'REDUNDANT_POST_DELETED_ON_FAILED_BIND' );

$pending_for_dedupe = 0;
foreach ( $GLOBALS['nvx_mock_posts'] as $p_id => $p_obj ) {
	if ( 'pending' === ( $p_obj->post_status ?? '' ) && ( $GLOBALS['nvx_mock_post_meta'][ $p_id ]['_nvx_relay_dedupe_key'] ?? '' ) === $dedupe_key12 ) {
		$pending_for_dedupe++;
	}
}
$require( 1 === $pending_for_dedupe, 'NO_DUPLICATE_PENDING_DELIVERIES_ON_EXPIRED_PUBLISHER' );

// ── Invariant 13: EXPIRED_PUBLISHER_SUCCESSOR_ADOPTS_SAME_POST ───────────────
// Interleave publication_duration > lease: after metadata exists, successor B
// takes over and binds A's exact pending row. A's failed bind must preserve it.
$body13       = '{"submission_id":"inv13-expired-adopted"}';
$dedupe_key13 = nvx_supabase_relay_dedupe_key( 'lead_captured', $body13, '' );
$claim_key13  = nvx_supabase_relay_queue_claim_key( $dedupe_key13 );
$GLOBALS['nvx_mock_adopt_inserted_post'] = true;

$posts_before13 = count( $GLOBALS['nvx_mock_posts'] );
$adopted13      = nvx_supabase_relay_queue_enqueue( 'lead_captured', $body13, array(), 1 );

$require( $adopted13 > 0, 'EXPIRED_SUCCESSOR_ADOPTION_RETURNS_POST' );
$require( isset( $GLOBALS['nvx_mock_posts'][ $adopted13 ] ), 'ADOPTED_POST_NOT_DELETED_BY_EXPIRED_OWNER' );
$require( (string) $adopted13 === (string) get_option( $claim_key13, '' ), 'ADOPTED_POST_OWNS_DURABLE_FENCE' );
$require( count( $GLOBALS['nvx_mock_posts'] ) === $posts_before13 + 1, 'EXPIRED_ADOPTION_PRESERVES_EXACTLY_ONE_ROW' );

$drainable13 = 0;
foreach ( $GLOBALS['nvx_mock_posts'] as $p_id => $p_obj ) {
	if (
		'pending' === ( $p_obj->post_status ?? '' )
		&& ( $GLOBALS['nvx_mock_post_meta'][ $p_id ]['_nvx_relay_dedupe_key'] ?? '' ) === $dedupe_key13
		&& (string) get_option( $claim_key13, '' ) === (string) $p_id
	) {
		$drainable13++;
	}
}
$require( 1 === $drainable13, 'PUBLICATION_DURATION_GT_LEASE_HAS_ONE_DRAINABLE_PENDING' );

// A live successor token makes a pending row non-drainable; after expiry the
// drainer can atomically recover that same row without creating a second copy.
$body14       = '{"submission_id":"inv14-fence-recovery"}';
$dedupe_key14 = nvx_supabase_relay_dedupe_key( 'lead_captured', $body14, '' );
$claim_key14  = nvx_supabase_relay_queue_claim_key( $dedupe_key14 );
$post14       = wp_insert_post( array( 'post_status' => 'pending', 'post_content' => $body14 ) );
add_post_meta( $post14, '_nvx_relay_dedupe_key', $dedupe_key14, true );
$GLOBALS['nvx_mock_options'][ $claim_key14 ] = ( $GLOBALS['nvx_mock_time'] + 10 ) . '|successor-live';
$require( ! nvx_supabase_relay_queue_acquire_publication_fence( $post14, $dedupe_key14 ), 'LIVE_SUCCESSOR_PREVENTS_DRAIN' );
$GLOBALS['nvx_mock_time'] += 11;
$require( nvx_supabase_relay_queue_acquire_publication_fence( $post14, $dedupe_key14 ), 'EXPIRED_SUCCESSOR_RECOVERED_BY_DRAINER' );
$require( (string) $post14 === (string) get_option( $claim_key14, '' ), 'RECOVERY_BINDS_EXACT_POST_FENCE' );

// A complete oldest-first batch of structurally incomplete prepared rows must
// be quarantined so the valid due row behind it can acquire its fence.
$invalid_batch = array();
for ( $index = 0; $index < NVX_SUPABASE_RELAY_QUEUE_BATCH; $index++ ) {
	$invalid_id = wp_insert_post(
		array(
			'post_status'  => NVX_SUPABASE_RELAY_QUEUE_PREPARED_STATUS,
			'post_content' => '{}',
		)
	);
	add_post_meta( $invalid_id, '_nvx_relay_next_attempt', '0', true );
	$invalid_batch[] = $invalid_id;
}

$drain_lock = 'invalid-batch-recovery-lock';
$GLOBALS['nvx_mock_options']['nvx_supabase_relay_drain_lock_v1'] = ( $GLOBALS['nvx_mock_time'] + 60 ) . '|' . $drain_lock;
foreach ( $invalid_batch as $invalid_id ) {
	$require( ! nvx_supabase_relay_queue_item_due( $invalid_id, $drain_lock, 60 ), 'INCOMPLETE_PREPARED_NOT_DUE_' . $invalid_id );
	$require( 'draft' === get_post( $invalid_id )->post_status, 'INCOMPLETE_PREPARED_QUARANTINED_' . $invalid_id );
}

$valid_body15   = '{"submission_id":"inv15-after-invalid-batch"}';
$valid_dedupe15 = nvx_supabase_relay_dedupe_key( 'lead_captured', $valid_body15, '' );
$valid_post15   = wp_insert_post( array( 'post_status' => 'pending', 'post_content' => $valid_body15 ) );
add_post_meta( $valid_post15, '_nvx_relay_dedupe_key', $valid_dedupe15, true );
add_post_meta( $valid_post15, '_nvx_relay_next_attempt', '0', true );
$require( nvx_supabase_relay_queue_item_due( $valid_post15, $drain_lock, 60 ), 'VALID_ROW_AFTER_INVALID_BATCH_IS_DUE' );
$require( (string) $valid_post15 === (string) get_option( nvx_supabase_relay_queue_claim_key( $valid_dedupe15 ), '' ), 'VALID_ROW_AFTER_BATCH_OWNS_FENCE' );

// If a successor misses an earlier private row before its readiness marker,
// the eventual claim winner must retire that row before becoming drainable.
$body16       = '{"submission_id":"inv16-superseded-prepared"}';
$dedupe_key16 = nvx_supabase_relay_dedupe_key( 'lead_captured', $body16, '' );
$claim_key16  = nvx_supabase_relay_queue_claim_key( $dedupe_key16 );
$loser16      = wp_insert_post( array( 'post_status' => NVX_SUPABASE_RELAY_QUEUE_PREPARED_STATUS, 'post_content' => $body16 ) );
add_post_meta( $loser16, '_nvx_relay_dedupe_key', $dedupe_key16, true );
$winner16 = wp_insert_post( array( 'post_status' => NVX_SUPABASE_RELAY_QUEUE_PREPARED_STATUS, 'post_content' => $body16 ) );
add_post_meta( $winner16, '_nvx_relay_dedupe_key', $dedupe_key16, true );
$GLOBALS['nvx_mock_options'][ $claim_key16 ] = (string) $winner16;

$require( nvx_supabase_relay_queue_finalize_publication( $winner16, $dedupe_key16 ), 'WINNER_FINALIZES_AFTER_MISSED_LOOKUP' );
$require( ! isset( $GLOBALS['nvx_mock_posts'][ $loser16 ] ), 'SUPERSEDED_PREPARED_ROW_RETIRED' );
$require( isset( $GLOBALS['nvx_mock_posts'][ $winner16 ] ) && 'pending' === $GLOBALS['nvx_mock_posts'][ $winner16 ]->post_status, 'ONLY_WINNER_BECOMES_PENDING' );

$remaining16 = 0;
foreach ( $GLOBALS['nvx_mock_posts'] as $p_id => $p_obj ) {
	if (
		in_array( ( $p_obj->post_status ?? '' ), array( 'pending', NVX_SUPABASE_RELAY_QUEUE_PREPARED_STATUS ), true )
		&& ( $GLOBALS['nvx_mock_post_meta'][ $p_id ]['_nvx_relay_dedupe_key'] ?? '' ) === $dedupe_key16
	) {
		$remaining16++;
	}
}
$require( 1 === $remaining16, 'WINNER_RETIRES_ALL_OTHER_READY_ROWS' );

$late_non_owner16 = wp_insert_post( array( 'post_status' => NVX_SUPABASE_RELAY_QUEUE_PREPARED_STATUS, 'post_content' => $body16 ) );
add_post_meta( $late_non_owner16, '_nvx_relay_dedupe_key', $dedupe_key16, true );
$require( ! nvx_supabase_relay_queue_acquire_publication_fence( $late_non_owner16, $dedupe_key16 ), 'LATE_NON_OWNER_CANNOT_ACQUIRE_FENCE' );
$require( ! isset( $GLOBALS['nvx_mock_posts'][ $late_non_owner16 ] ), 'LATE_NON_OWNER_RETIRES_ON_CANONICAL_FENCE' );

$pre_drain_duplicate16 = wp_insert_post( array( 'post_status' => 'pending', 'post_content' => $body16 ) );
add_post_meta( $pre_drain_duplicate16, '_nvx_relay_dedupe_key', $dedupe_key16, true );
nvx_supabase_relay_queue_retire_duplicate_rows( $winner16, $dedupe_key16 );
$require( ! isset( $GLOBALS['nvx_mock_posts'][ $pre_drain_duplicate16 ] ), 'DRAIN_COMPLETION_RETIRES_LATE_DUPLICATE' );
wp_delete_post( $winner16, true );
nvx_supabase_relay_queue_release_claim( $dedupe_key16, (string) $winner16 );

$after_drain16 = 0;
foreach ( $GLOBALS['nvx_mock_posts'] as $p_id => $p_obj ) {
	if ( ( $GLOBALS['nvx_mock_post_meta'][ $p_id ]['_nvx_relay_dedupe_key'] ?? '' ) === $dedupe_key16 ) {
		$after_drain16++;
	}
}
$require( 0 === $after_drain16, 'NO_DUPLICATE_SURVIVES_CANONICAL_DRAIN' );

// A claimed prepared row retains attempt accounting when its idempotent
// prepared→pending transition fails temporarily.
$body17       = '{"submission_id":"inv17-finalize-failure"}';
$dedupe_key17 = nvx_supabase_relay_dedupe_key( 'lead_captured', $body17, '' );
$claim_key17  = nvx_supabase_relay_queue_claim_key( $dedupe_key17 );
$prepared17   = wp_insert_post( array( 'post_status' => NVX_SUPABASE_RELAY_QUEUE_PREPARED_STATUS, 'post_content' => $body17 ) );
add_post_meta( $prepared17, '_nvx_relay_attempts', '1', true );
add_post_meta( $prepared17, '_nvx_relay_next_attempt', '0', true );
add_post_meta( $prepared17, '_nvx_relay_dedupe_key', $dedupe_key17, true );
$GLOBALS['nvx_mock_options'][ $claim_key17 ] = (string) $prepared17;
$GLOBALS['nvx_mock_update_failure_on_post'] = $prepared17;
$failed_finalize17 = nvx_supabase_relay_queue_enqueue( 'lead_captured', $body17, array(), 2 );
$GLOBALS['nvx_mock_update_failure_on_post'] = 0;

$require( $prepared17 === $failed_finalize17, 'FAILED_FINALIZATION_RETURNS_CANONICAL_PREPARED' );
$require( '3' === (string) get_post_meta( $prepared17, '_nvx_relay_attempts', true ), 'FAILED_FINALIZATION_PRESERVES_ATTEMPTS' );
$require( NVX_SUPABASE_RELAY_QUEUE_PREPARED_STATUS === get_post( $prepared17 )->post_status, 'FAILED_FINALIZATION_REMAINS_RECOVERABLE' );

// An abandoned prepared row whose claim was released (claim option is empty)
// must be retired by the fence and never adopted as a legacy row.
$body18         = '{"submission_id":"inv18-empty-claim-retired"}';
$dedupe_key18   = nvx_supabase_relay_dedupe_key( 'lead_captured', $body18, '' );
$claim_key18    = nvx_supabase_relay_queue_claim_key( $dedupe_key18 );
$abandoned_post = wp_insert_post(
	array(
		'post_status'  => NVX_SUPABASE_RELAY_QUEUE_PREPARED_STATUS,
		'post_content' => $body18,
	)
);
add_post_meta( $abandoned_post, '_nvx_relay_dedupe_key', $dedupe_key18, true );
unset( $GLOBALS['nvx_mock_options'][ $claim_key18 ] );

$require( ! nvx_supabase_relay_queue_acquire_publication_fence( $abandoned_post, $dedupe_key18 ), 'ABANDONED_PREPARED_NOT_ADOPTED_WHEN_CLAIM_EMPTY' );
$require( ! isset( $GLOBALS['nvx_mock_posts'][ $abandoned_post ] ), 'ABANDONED_PREPARED_DELETED_ON_EMPTY_CLAIM' );

// ── Invariant 11: SOURCE_INTEGRITY ───────────────────────────────────────────
$src = (string) file_get_contents( $queue_path );
$require( false !== strpos( $src, 'nvx_relay_claim_' ), 'CLAIM_KEY_PREFIX_IN_SOURCE' );
$require( false !== strpos( $src, 'add_option( $claim_key' ), 'ATOMIC_ACQUISITION_IN_SOURCE' );
$require( false !== strpos( $src, 'nvx_supabase_relay_queue_release_claim' ), 'RELEASE_CLAIM_HELPER_IN_SOURCE' );
$require( false !== strpos( $src, 'nvx_supabase_relay_queue_is_valid_pending_item' ), 'VALID_PENDING_HELPER_IN_SOURCE' );
$require( false !== strpos( $src, 'nvx_supabase_relay_queue_acquire_publication_fence' ), 'PUBLICATION_FENCE_IN_SOURCE' );
$require( false !== strpos( $src, 'NVX_SUPABASE_RELAY_QUEUE_PREPARED_STATUS' ), 'PRIVATE_PREPARED_STATUS_IN_SOURCE' );
$require( false !== strpos( $src, 'nvx_supabase_relay_queue_retire_duplicate_rows' ), 'SUPERSEDED_ROW_RETIREMENT_IN_SOURCE' );
$require( false !== strpos( $src, 'claim_lost_during_publish' ), 'CLAIM_LOST_HANDLED_IN_SOURCE' );

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

echo "OUTBOX_CONCURRENCY_V2=PASS atomic_claim=1 idempotent=1 two_phase_fence=1 private_prepare=1 orphan_recovery=1 meta_fail_safe=1 attempts_monotonic=1 interleaved_safe=1 lifecycle_release=1 rollout_adoption=1 expired_bind_cleanup=1 adopted_retry_preserved=1 drainable_exactly_one=1 incomplete_batch_quarantined=1 superseded_rows_retired=1 source_integrity=1\n";

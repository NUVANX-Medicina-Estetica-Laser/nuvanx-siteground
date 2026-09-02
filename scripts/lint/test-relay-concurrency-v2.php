<?php
/**
 * Behavioral/static contract for Supabase relay outbox concurrency v2.
 *
 * This harness deliberately uses the queue's mock-time, mock-option and
 * mock-post-meta fallbacks so the critical lease/CAS semantics can be verified
 * without a WordPress database.
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
		public $ID = 0;
		public $post_status = 'pending';
		public $post_content = '';
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	final class WP_Error {
		public function __construct(
			private string $code = '',
			private string $message = ''
		) {}

		public function get_error_code(): string {
			return $this->code;
		}
	}
}

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

$GLOBALS['nvx_mock_options']                        = array();
$GLOBALS['nvx_uuid_counter']                        = 0;
$GLOBALS['nvx_mock_post_meta']                      = array();
$GLOBALS['nvx_mock_meta_conflict_values']           = array();
$GLOBALS['nvx_mock_meta_permanent_fail_keys']       = array();
$GLOBALS['nvx_mock_meta_update_calls']              = array();
$GLOBALS['nvx_mock_option_cas_conflict_values']     = array();
$GLOBALS['nvx_mock_release_after_cache_delete']     = array();
$GLOBALS['nvx_mock_cache_delete_calls']             = array();
$GLOBALS['nvx_mock_posts']                          = array();
$GLOBALS['nvx_mock_next_post_id']                   = 100;
$GLOBALS['nvx_mock_deleted_posts']                  = array();
$GLOBALS['nvx_mock_publish_failure']                = false;

function get_post( $post_id ) {
	$post_id = (int) $post_id;
	if ( isset( $GLOBALS['nvx_mock_posts'][ $post_id ] ) ) {
		return $GLOBALS['nvx_mock_posts'][ $post_id ];
	}
	$post              = new WP_Post();
	$post->ID          = $post_id;
	$post->post_status = 'pending';
	return $post;
}

function get_posts( $args = array() ): array {
	$status     = $args['post_status'] ?? 'pending';
	$meta_query = $args['meta_query'] ?? array();
	$results    = array();

	// Hook for test: release reservation on existing check (simulating holder failure)
	foreach ( $meta_query as $clause ) {
		$v = $clause['value'] ?? '';
		if ( ! empty( $GLOBALS['nvx_mock_release_on_existing_check'][ $v ] ) ) {
			$res_key = $GLOBALS['nvx_mock_release_on_existing_check'][ $v ];
			unset( $GLOBALS['nvx_mock_release_on_existing_check'][ $v ] );
			unset( $GLOBALS['nvx_mock_options'][ $res_key ] );
		}
	}

	foreach ( $GLOBALS['nvx_mock_posts'] as $id => $post ) {
		if ( (string) $post->post_status !== (string) $status ) {
			continue;
		}
		$match = true;
		foreach ( $meta_query as $clause ) {
			$k = $clause['key'] ?? '';
			$v = $clause['value'] ?? '';
			if ( (string) ( $GLOBALS['nvx_mock_post_meta'][ $id ][ $k ] ?? '' ) !== (string) $v ) {
				$match = false;
				break;
			}
		}
		if ( $match ) {
			$results[] = $id;
		}
	}

	return $results;
}

function get_post_meta( $post_id, $key = '', $single = false ) {
	unset( $single );
	return $GLOBALS['nvx_mock_post_meta'][ (int) $post_id ][ (string) $key ] ?? '';
}

function update_post_meta( $post_id, $key, $value, $prev_value = '' ) {
	$post_id = (int) $post_id;
	$key     = (string) $key;
	$current = (string) ( $GLOBALS['nvx_mock_post_meta'][ $post_id ][ $key ] ?? '' );
	$GLOBALS['nvx_mock_meta_update_calls'][ $key ] = ( $GLOBALS['nvx_mock_meta_update_calls'][ $key ] ?? 0 ) + 1;

	if ( ! empty( $GLOBALS['nvx_mock_meta_permanent_fail_keys'][ $key ] ) ) {
		return false;
	}

	if ( isset( $GLOBALS['nvx_mock_meta_conflict_values'][ $key ] ) ) {
		$GLOBALS['nvx_mock_post_meta'][ $post_id ][ $key ] = (string) $GLOBALS['nvx_mock_meta_conflict_values'][ $key ];
		unset( $GLOBALS['nvx_mock_meta_conflict_values'][ $key ] );
		return false;
	}

	if ( '' !== (string) $prev_value && $current !== (string) $prev_value ) {
		return false;
	}

	$GLOBALS['nvx_mock_post_meta'][ $post_id ][ $key ] = (string) $value;
	return true;
}

function add_post_meta( $post_id, $key, $value, $unique = false ): bool {
	$post_id = (int) $post_id;
	$key     = (string) $key;
	if ( $unique && array_key_exists( $key, $GLOBALS['nvx_mock_post_meta'][ $post_id ] ?? array() ) ) {
		return false;
	}
	$GLOBALS['nvx_mock_post_meta'][ $post_id ][ $key ] = (string) $value;

	if (
		'_nvx_relay_dedupe_key' === $key
		&& ! empty( $GLOBALS['nvx_mock_expire_reservation_before_publish'] )
	) {
		$res_key = 'nvx_relay_dedupe_res_' . (string) $value;
		$GLOBALS['nvx_mock_options'][ $res_key ] = ( time() - 100 ) . '|delayed-expired';
	}

	return true;
}

function get_option( string $key, $default = false ) {
	return $GLOBALS['nvx_mock_options'][ $key ] ?? $default;
}

function add_option( string $key, $value, $deprecated = '', $autoload = 'yes' ): bool {
	unset( $deprecated, $autoload );
	if ( array_key_exists( $key, $GLOBALS['nvx_mock_options'] ) ) {
		return false;
	}
	$GLOBALS['nvx_mock_options'][ $key ] = (string) $value;
	return true;
}

function update_option( string $key, $value, $autoload = null ): bool {
	unset( $autoload );
	$GLOBALS['nvx_mock_options'][ $key ] = (string) $value;
	return true;
}

function delete_option( string $key ): bool {
	unset( $GLOBALS['nvx_mock_options'][ $key ] );
	return true;
}

function wp_cache_delete( $key, string $group = '' ): bool {
	$cache_key = $group . ':' . (string) $key;
	$GLOBALS['nvx_mock_cache_delete_calls'][ $cache_key ] = ( $GLOBALS['nvx_mock_cache_delete_calls'][ $cache_key ] ?? 0 ) + 1;
	$current = (string) ( $GLOBALS['nvx_mock_options'][ (string) $key ] ?? '' );
	if (
		'options' === $group
		&& ! empty( $GLOBALS['nvx_mock_release_after_cache_delete'][ (string) $key ] )
		&& str_ends_with( $current, '|winner-owner' )
	) {
		unset( $GLOBALS['nvx_mock_release_after_cache_delete'][ (string) $key ] );
		unset( $GLOBALS['nvx_mock_options'][ (string) $key ] );
	}
	return true;
}

function wp_generate_uuid4(): string {
	$GLOBALS['nvx_uuid_counter']++;
	return sprintf( '00000000-0000-4000-8000-%012d', $GLOBALS['nvx_uuid_counter'] );
}

function wp_insert_post( $postarr = array(), $wp_error = false ) {
	unset( $wp_error );
	$post_id              = ++$GLOBALS['nvx_mock_next_post_id'];
	$post                  = new WP_Post();
	$post->ID              = $post_id;
	$post->post_status     = (string) ( $postarr['post_status'] ?? 'draft' );
	$post->post_content    = stripslashes( (string) ( $postarr['post_content'] ?? '' ) );
	$GLOBALS['nvx_mock_posts'][ $post_id ] = $post;
	return $post_id;
}

function wp_update_post( $postarr = array(), $wp_error = false ) {
	$post_id = (int) ( $postarr['ID'] ?? 0 );
	if ( 'pending' === ( $postarr['post_status'] ?? '' ) && ! empty( $GLOBALS['nvx_mock_publish_failure'] ) ) {
		return $wp_error ? new WP_Error( 'mock_publish_failed', 'Mock publication failure.' ) : 0;
	}
	if ( $post_id < 1 || ! isset( $GLOBALS['nvx_mock_posts'][ $post_id ] ) ) {
		return $wp_error ? new WP_Error( 'mock_post_missing', 'Mock post missing.' ) : 0;
	}
	if ( isset( $postarr['post_status'] ) ) {
		$GLOBALS['nvx_mock_posts'][ $post_id ]->post_status = (string) $postarr['post_status'];
	}
	return $post_id;
}

function wp_delete_post( $post_id, $force_delete = false ) {
	unset( $force_delete );
	$post_id = (int) $post_id;
	$GLOBALS['nvx_mock_deleted_posts'][] = $post_id;
	$post = $GLOBALS['nvx_mock_posts'][ $post_id ] ?? null;
	unset( $GLOBALS['nvx_mock_posts'][ $post_id ], $GLOBALS['nvx_mock_post_meta'][ $post_id ] );
	return $post;
}

$queue_path = dirname( __DIR__, 2 ) . '/wp-content/themes/nuvanx-medical/inc/nvx-supabase-relay-queue.php';
$queue      = file_get_contents( $queue_path );

if ( ! is_string( $queue ) || '' === $queue ) {
	fwrite( STDERR, "OUTBOX_CONCURRENCY_V2=FAIL reason=queue_source_unavailable\n" );
	exit( 1 );
}

require_once $queue_path;

$failures = array();
$require  = static function ( bool $condition, string $name ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $name;
	}
};

$lock_key = 'nvx_supabase_relay_drain_lock_v1';
$now      = 1700000000;

// Invariant 1: a lease is expired at expiry <= now, not only expiry < now.
$GLOBALS['nvx_mock_time']                 = $now;
$GLOBALS['nvx_mock_options'][ $lock_key ] = $now . '|expired-owner';
$takeover = nvx_supabase_relay_queue_lock( 60 );
$require( '' !== $takeover, 'EXACT_EXPIRY_TAKEOVER' );
if ( '' !== $takeover ) {
	nvx_supabase_relay_queue_unlock( $takeover );
}

// Invariant 2: an owner may never resurrect its lease after expiry.
$GLOBALS['nvx_mock_time']                 = $now;
$GLOBALS['nvx_mock_options'][ $lock_key ] = ( $now - 1 ) . '|stale-owner';
$renewed = nvx_supabase_relay_queue_renew_lock( 'stale-owner', 60 );
$require( false === $renewed, 'EXPIRED_OWNER_RENEWAL_REJECTED' );
$require(
	( $now - 1 ) . '|stale-owner' === ( $GLOBALS['nvx_mock_options'][ $lock_key ] ?? '' ),
	'EXPIRED_OWNER_RENEWAL_NO_MUTATION'
);

// Invariant 3: an expired exact-body dedupe reservation is recovered by CAS.
$dedupe_key         = str_repeat( 'a', 64 );
$dedupe_res_key     = 'nvx_relay_dedupe_res_' . $dedupe_key;
$GLOBALS['nvx_mock_options'][ $dedupe_res_key ] = ( $now - 1 ) . '|dead-creator';
$reservation        = nvx_supabase_relay_queue_dedupe_reservation( $dedupe_key );
$reservation_value  = (string) ( $GLOBALS['nvx_mock_options'][ $dedupe_res_key ] ?? '' );
$require( $dedupe_res_key === ( $reservation['key'] ?? '' ), 'DEDUPE_RESERVATION_KEY' );
$require( '' !== ( $reservation['token'] ?? '' ), 'DEDUPE_RESERVATION_TOKEN' );
$require(
	str_ends_with( $reservation_value, '|' . ( $reservation['token'] ?? '' ) ),
	'DEDUPE_STALE_RESERVATION_RECOVERED'
);
nvx_supabase_relay_queue_unlock_dedupe(
	(string) ( $reservation['key'] ?? '' ),
	(string) ( $reservation['token'] ?? '' )
);
$require( ! isset( $GLOBALS['nvx_mock_options'][ $dedupe_res_key ] ), 'DEDUPE_RESERVATION_RELEASED' );

// Invariant 3b: a contender that loses stale-reservation CAS refreshes cache and can acquire after winner release.
$race_key     = str_repeat( 'b', 64 );
$race_res_key = 'nvx_relay_dedupe_res_' . $race_key;
$GLOBALS['nvx_mock_options'][ $race_res_key ]                    = ( $now - 1 ) . '|expired-shared-read';
$GLOBALS['nvx_mock_option_cas_conflict_values'][ $race_res_key ] = ( $now + 60 ) . '|winner-owner';
$GLOBALS['nvx_mock_release_after_cache_delete'][ $race_res_key ] = true;
$race_reservation = nvx_supabase_relay_queue_dedupe_reservation( $race_key );
$require( '' !== ( $race_reservation['token'] ?? '' ), 'DEDUPE_LOSER_REACQUIRES_AFTER_RELEASE' );
$require(
	( $GLOBALS['nvx_mock_cache_delete_calls'][ 'options:' . $race_res_key ] ?? 0 ) >= 2,
	'DEDUPE_LOST_CAS_INVALIDATES_AND_REREADS_OPTION_CACHE'
);
$require(
	! isset( $GLOBALS['nvx_mock_option_cas_conflict_values'][ $race_res_key ] ),
	'DEDUPE_LOST_CAS_WAS_EXERCISED'
);
nvx_supabase_relay_queue_unlock_dedupe(
	(string) ( $race_reservation['key'] ?? '' ),
	(string) ( $race_reservation['token'] ?? '' )
);

// Invariant 3c: an active reservation cannot hold a request indefinitely.
$bounded_key     = str_repeat( 'c', 64 );
$bounded_res_key = 'nvx_relay_dedupe_res_' . $bounded_key;
$GLOBALS['nvx_mock_options'][ $bounded_res_key ] = ( $now + 60 ) . '|active-owner';
$bounded_reservation = nvx_supabase_relay_queue_dedupe_reservation( $bounded_key );
$require( '' === ( $bounded_reservation['token'] ?? '' ), 'DEDUPE_ACQUISITION_BOUNDED' );
unset( $GLOBALS['nvx_mock_options'][ $bounded_res_key ] );

// Invariant 4: CAS retry preserves a concurrent attempt increment.
$post_id = 77;
$GLOBALS['nvx_mock_post_meta'][ $post_id ]['_nvx_relay_attempts'] = '2';
$GLOBALS['nvx_mock_meta_conflict_values']['_nvx_relay_attempts']  = '3';
$attempts = nvx_supabase_relay_queue_atomic_add_attempts( $post_id, 1 );
$require( 4 === $attempts, 'ATOMIC_ATTEMPTS_RETRY_ADDITIVE' );
$require(
	'4' === ( $GLOBALS['nvx_mock_post_meta'][ $post_id ]['_nvx_relay_attempts'] ?? '' ),
	'ATOMIC_ATTEMPTS_COMMITTED'
);

$GLOBALS['nvx_mock_post_meta'][ $post_id ]['_nvx_relay_attempts'] = '7';
$attempts = nvx_supabase_relay_queue_atomic_add_attempts( $post_id, 2 );
$require( 8 === $attempts, 'ATOMIC_ATTEMPTS_CAPPED' );

// Invariant 4b: permanent metadata failure terminates with an explicit failure result.
$GLOBALS['nvx_mock_post_meta'][ $post_id ]['_nvx_relay_attempts'] = '2';
$GLOBALS['nvx_mock_meta_permanent_fail_keys']['_nvx_relay_attempts'] = true;
$GLOBALS['nvx_mock_meta_update_calls']['_nvx_relay_attempts'] = 0;
$attempts_failed = nvx_supabase_relay_queue_atomic_add_attempts( $post_id, 1 );
$require( null === $attempts_failed, 'ATOMIC_ATTEMPTS_PERMANENT_FAILURE_EXPLICIT' );
$require(
	NVX_SUPABASE_RELAY_QUEUE_CAS_MAX_ATTEMPTS === ( $GLOBALS['nvx_mock_meta_update_calls']['_nvx_relay_attempts'] ?? 0 ),
	'ATOMIC_ATTEMPTS_RETRIES_BOUNDED'
);
unset( $GLOBALS['nvx_mock_meta_permanent_fail_keys']['_nvx_relay_attempts'] );

// Invariant 5: retry scheduling is monotonic even after a CAS conflict.
$GLOBALS['nvx_mock_post_meta'][ $post_id ]['_nvx_relay_next_attempt'] = '500';
$next_ok = nvx_supabase_relay_queue_set_next_attempt_monotonic( $post_id, 400 );
$require( true === $next_ok, 'NEXT_ATTEMPT_NOOP_SUCCESS' );
$require(
	'500' === ( $GLOBALS['nvx_mock_post_meta'][ $post_id ]['_nvx_relay_next_attempt'] ?? '' ),
	'NEXT_ATTEMPT_NO_REGRESSION'
);

$GLOBALS['nvx_mock_meta_conflict_values']['_nvx_relay_next_attempt'] = '650';
$next_ok = nvx_supabase_relay_queue_set_next_attempt_monotonic( $post_id, 600 );
$require( true === $next_ok, 'NEXT_ATTEMPT_CONCURRENT_FORWARD_RESULT' );
$require(
	'650' === ( $GLOBALS['nvx_mock_post_meta'][ $post_id ]['_nvx_relay_next_attempt'] ?? '' ),
	'NEXT_ATTEMPT_CONCURRENT_FORWARD_WINS'
);

$GLOBALS['nvx_mock_meta_conflict_values']['_nvx_relay_next_attempt'] = '650';
$GLOBALS['nvx_mock_post_meta'][ $post_id ]['_nvx_relay_next_attempt'] = '500';
$next_ok = nvx_supabase_relay_queue_set_next_attempt_monotonic( $post_id, 700 );
$require( true === $next_ok, 'NEXT_ATTEMPT_RETRY_RESULT' );
$require(
	'700' === ( $GLOBALS['nvx_mock_post_meta'][ $post_id ]['_nvx_relay_next_attempt'] ?? '' ),
	'NEXT_ATTEMPT_RETRY_COMMITS_MAX'
);

$GLOBALS['nvx_mock_post_meta'][ $post_id ]['_nvx_relay_next_attempt'] = '500';
$GLOBALS['nvx_mock_meta_permanent_fail_keys']['_nvx_relay_next_attempt'] = true;
$GLOBALS['nvx_mock_meta_update_calls']['_nvx_relay_next_attempt'] = 0;
$next_failed = nvx_supabase_relay_queue_set_next_attempt_monotonic( $post_id, 700 );
$require( false === $next_failed, 'NEXT_ATTEMPT_PERMANENT_FAILURE_EXPLICIT' );
$require(
	NVX_SUPABASE_RELAY_QUEUE_CAS_MAX_ATTEMPTS === ( $GLOBALS['nvx_mock_meta_update_calls']['_nvx_relay_next_attempt'] ?? 0 ),
	'NEXT_ATTEMPT_RETRIES_BOUNDED'
);
unset( $GLOBALS['nvx_mock_meta_permanent_fail_keys']['_nvx_relay_next_attempt'] );

// Invariant 6: due-state is re-read immediately before I/O and renewal.
$GLOBALS['nvx_mock_time']                 = $now;
$GLOBALS['nvx_mock_options'][ $lock_key ] = ( $now + 30 ) . '|due-owner';
$GLOBALS['nvx_mock_post_meta'][ $post_id ]['_nvx_relay_next_attempt'] = (string) ( $now + 1 );
$require(
	false === nvx_supabase_relay_queue_item_due( $post_id, 'due-owner', 60 ),
	'DUE_STATE_FUTURE_REJECTED'
);
$GLOBALS['nvx_mock_post_meta'][ $post_id ]['_nvx_relay_next_attempt'] = (string) $now;
$require(
	true === nvx_supabase_relay_queue_item_due( $post_id, 'due-owner', 60 ),
	'DUE_STATE_REVALIDATED'
);
$require(
	str_starts_with( (string) ( $GLOBALS['nvx_mock_options'][ $lock_key ] ?? '' ), (string) ( $now + 60 ) . '|' ),
	'DUE_STATE_RENEWS_OWNER_LEASE'
);

// Invariant 7: post-I/O fence rejects wrong or expired owners and reads fresh option bypassing stale cache.
$GLOBALS['nvx_mock_options'][ $lock_key ] = ( $now + 30 ) . '|fence-owner';
$initial_cache_deletes = $GLOBALS['nvx_mock_cache_delete_calls'][ 'options:' . $lock_key ] ?? 0;
$require( true === nvx_supabase_relay_queue_lock_owned( 'fence-owner' ), 'POST_IO_FENCE_VALID_OWNER' );
$require(
	( $GLOBALS['nvx_mock_cache_delete_calls'][ 'options:' . $lock_key ] ?? 0 ) > $initial_cache_deletes,
	'POST_IO_FENCE_FRESH_OPTION_READ'
);
$require( false === nvx_supabase_relay_queue_lock_owned( 'wrong-owner' ), 'POST_IO_FENCE_WRONG_OWNER' );
$GLOBALS['nvx_mock_options'][ $lock_key ] = $now . '|fence-owner';
$require( false === nvx_supabase_relay_queue_lock_owned( 'fence-owner' ), 'POST_IO_FENCE_EXPIRED_OWNER' );

// Invariant 8: a new item is non-drainable until all canonical metadata exists.
$enqueue_start = strpos( $queue, 'function nvx_supabase_relay_queue_enqueue(' );
$send_start    = strpos( $queue, 'function nvx_supabase_relay_queue_send(' );
$enqueue       = ( false !== $enqueue_start && false !== $send_start && $send_start > $enqueue_start )
	? substr( $queue, $enqueue_start, $send_start - $enqueue_start )
	: '';
$draft_pos     = strpos( $enqueue, "'post_status'  => 'draft'" );
$dedupe_pos    = strpos( $enqueue, "'_nvx_relay_dedupe_key'" );
$publish_pos   = strrpos( $enqueue, "'post_status' => 'pending'" );
$require( '' !== $enqueue, 'ENQUEUE_SOURCE_BOUNDARY' );
$require( false !== $draft_pos, 'NEW_ITEM_NON_DRAINABLE_UNTIL_COMPLETE' );
$require( false !== $dedupe_pos, 'NEW_ITEM_CANONICAL_METADATA_PRESENT' );
$require( false !== $publish_pos, 'NEW_ITEM_PUBLISHED_AFTER_METADATA' );
$require(
	false !== $draft_pos && false !== $dedupe_pos && false !== $publish_pos
	&& $draft_pos < $dedupe_pos && $dedupe_pos < $publish_pos,
	'NEW_ITEM_TWO_PHASE_ORDER'
);

// Invariant 8b: draft-to-pending publication failure is not reported as queued success.
$GLOBALS['nvx_mock_publish_failure'] = true;
unset( $GLOBALS['nvx_supabase_relay_queue_dirty'] );
$publish_failure_id = nvx_supabase_relay_queue_enqueue(
	'lead_captured',
	'{"submission_id":"publication-failure"}',
	array(),
	1
);
$failed_post_id = $GLOBALS['nvx_mock_next_post_id'];
$require( 0 === $publish_failure_id, 'PUBLICATION_FAILURE_RETURNS_ENQUEUE_FAILURE' );
$require( in_array( $failed_post_id, $GLOBALS['nvx_mock_deleted_posts'], true ), 'PUBLICATION_FAILURE_DELETES_INCOMPLETE_DRAFT' );
$require( ! isset( $GLOBALS['nvx_mock_posts'][ $failed_post_id ] ), 'PUBLICATION_FAILURE_LEAVES_NO_STRANDED_DRAFT' );
$require( empty( $GLOBALS['nvx_supabase_relay_queue_dirty'] ), 'PUBLICATION_FAILURE_NOT_MARKED_DIRTY' );
$GLOBALS['nvx_mock_publish_failure'] = false;

// Invariant 9: the post-I/O fence occurs after transport and before queue mutation.
$drain_start = strpos( $queue, 'function nvx_supabase_relay_queue_drain(' );
$shutdown_start = strpos( $queue, "add_action(\n\tNVX_SUPABASE_RELAY_QUEUE_CRON" );
$drain = ( false !== $drain_start && false !== $shutdown_start && $shutdown_start > $drain_start )
	? substr( $queue, $drain_start, $shutdown_start - $drain_start )
	: '';
$send_pos       = strpos( $drain, 'nvx_supabase_relay_queue_send(' );
$fence_pos      = strpos( $drain, 'nvx_supabase_relay_queue_lock_owned(' );
$delete_pos     = strpos( $drain, 'wp_delete_post(' );
$attempt_pos    = strpos( $drain, 'nvx_supabase_relay_queue_atomic_add_attempts(' );
$require( '' !== $drain, 'DRAIN_SOURCE_BOUNDARY' );
$require(
	false !== $send_pos && false !== $fence_pos && $send_pos < $fence_pos,
	'POST_IO_FENCE_AFTER_TRANSPORT'
);
$require(
	false !== $fence_pos && false !== $delete_pos && false !== $attempt_pos
	&& $fence_pos < $delete_pos && $fence_pos < $attempt_pos,
	'POST_IO_FENCE_BEFORE_MUTATION'
);

// Invariant 9b: enqueue ownership fence occurs after metadata and before publication.
$fence_enqueue_pos = strpos( $enqueue, 'nvx_supabase_relay_queue_renew_dedupe_reservation(' );
$require( false !== $fence_enqueue_pos, 'ENQUEUE_OWNERSHIP_FENCE_PRESENT' );
$require(
	false !== $dedupe_pos && false !== $fence_enqueue_pos && false !== $publish_pos
	&& $dedupe_pos < $fence_enqueue_pos && $fence_enqueue_pos < $publish_pos,
	'ENQUEUE_OWNERSHIP_FENCE_BEFORE_PUBLICATION'
);

// Invariant 10: holder failure after contender timeout preserves retryable delivery without dropping the event.
$contender_body     = '{"submission_id":"holder-fails-after-contender-timeout"}';
$contender_fail_key = nvx_supabase_relay_dedupe_key( 'lead_captured', $contender_body, '' );
$contender_res_key  = 'nvx_relay_dedupe_res_' . $contender_fail_key;
$GLOBALS['nvx_mock_options'][ $contender_res_key ] = ( $now + 60 ) . '|failing-holder';
$GLOBALS['nvx_mock_release_on_existing_check'][ $contender_fail_key ] = $contender_res_key;

$saved_post_id = nvx_supabase_relay_queue_enqueue(
	'lead_captured',
	$contender_body,
	array(),
	1
);
$require( $saved_post_id > 0, 'CONTENDER_RECOVERS_AND_PERSISTS_AFTER_HOLDER_FAILURE' );
$require(
	isset( $GLOBALS['nvx_mock_posts'][ $saved_post_id ] ) && 'pending' === $GLOBALS['nvx_mock_posts'][ $saved_post_id ]->post_status,
	'CONTENDER_RECOVERED_ITEM_PENDING'
);
$require(
	$contender_fail_key === ( $GLOBALS['nvx_mock_post_meta'][ $saved_post_id ]['_nvx_relay_dedupe_key'] ?? '' ),
	'CONTENDER_RECOVERED_ITEM_DEDUPE_KEY_MATCH'
);

// Invariant 11: delayed owner whose lease expired is fenced from publishing duplicate after takeover.
$takeover_key     = str_repeat( 'e', 64 );
$takeover_res_key = 'nvx_relay_dedupe_res_' . $takeover_key;

// 1. Takeover owner enqueues and publishes legitimate pending item.
$takeover_post_id = nvx_supabase_relay_queue_enqueue(
	'lead_captured',
	'{"submission_id":"takeover-winner"}',
	array(),
	1
);
$require( $takeover_post_id > 0, 'TAKEOVER_OWNER_ENQUEUE_SUCCESS' );

// 2. Ownership renewal rejects an expired owner token.
$delayed_token = 'delayed-expired-token';
$GLOBALS['nvx_mock_options'][ $takeover_res_key ] = ( $now - 10 ) . '|' . $delayed_token;
$fence_result = nvx_supabase_relay_queue_renew_dedupe_reservation( $takeover_res_key, $delayed_token );
$require( false === $fence_result, 'EXPIRED_OWNER_RENEWAL_FENCED' );

// 3. Ownership renewal rejects a token that was overtaken by a new winner.
$GLOBALS['nvx_mock_options'][ $takeover_res_key ] = ( $now + 60 ) . '|new-takeover-owner';
$fence_taken_over = nvx_supabase_relay_queue_renew_dedupe_reservation( $takeover_res_key, $delayed_token );
$require( false === $fence_taken_over, 'TAKEOVER_OWNER_RENEWAL_FENCED' );

// 4. An enqueueing owner whose lease expires before publish deletes its draft post and does not publish.
$GLOBALS['nvx_mock_expire_reservation_before_publish'] = true;
$resumed_result = nvx_supabase_relay_queue_enqueue(
	'lead_captured',
	'{"submission_id":"resume-after-expired-lease"}',
	array(),
	1
);
$require( 0 === $resumed_result, 'EXPIRED_OWNER_ENQUEUE_RETURNS_ZERO' );
$expired_post_id = $GLOBALS['nvx_mock_next_post_id'];
$require(
	in_array( $expired_post_id, $GLOBALS['nvx_mock_deleted_posts'], true ),
	'EXPIRED_OWNER_DRAFT_DELETED_ON_FENCE'
);
$require(
	! isset( $GLOBALS['nvx_mock_posts'][ $expired_post_id ] ),
	'EXPIRED_OWNER_LEAVES_NO_ORPHAN_POST'
);
unset( $GLOBALS['nvx_mock_expire_reservation_before_publish'] );

// Invariant 12: contention on an existing published item accumulates contender attempts through the shared accounting path.
$overlap_body = '{"submission_id":"takeover-winner"}';
$GLOBALS['nvx_mock_options'][ $takeover_res_key ] = ( $now + 60 ) . '|active-holder';
$prior_attempts = absint( $GLOBALS['nvx_mock_post_meta'][ $takeover_post_id ]['_nvx_relay_attempts'] ?? '1' );
$overlap_id = nvx_supabase_relay_queue_enqueue(
	'lead_captured',
	$overlap_body,
	array(),
	2
);
$require( $takeover_post_id === $overlap_id, 'CONTENTION_MATCHES_EXISTING_ITEM' );
$updated_attempts = absint( $GLOBALS['nvx_mock_post_meta'][ $takeover_post_id ]['_nvx_relay_attempts'] ?? '0' );
$require(
	$updated_attempts === ( $prior_attempts + 2 ),
	'CONTENTION_ACCUMULATES_CONTENDER_ATTEMPTS'
);
unset( $GLOBALS['nvx_mock_options'][ $takeover_res_key ] );

// Invariant 13: the post-publish ownership fence must appear in the source, between
// wp_update_post() to 'pending' and the nvx_supabase_relay_queue_unlock_dedupe() cleanup.
// This static guard verifies the guard exists so dead-code removal cannot silently drop it.
// strrpos() locates the call-site unlock in the finally block (last occurrence), not the
// function definition that precedes the enqueue implementation.
$src_post_fence = (string) file_get_contents( $queue_path );
$update_pos     = strpos( $src_post_fence, "'post_status' => 'pending'" );
$unlock_pos     = strrpos( $src_post_fence, 'nvx_supabase_relay_queue_unlock_dedupe' );
$fence_pos      = strpos( $src_post_fence, 'dedupe_reservation_lost_post_publish' );
$require( false !== $fence_pos, 'POSTPUB_FENCE_EXISTS_IN_SOURCE' );
$require(
	false !== $update_pos && false !== $fence_pos && false !== $unlock_pos
	&& $update_pos < $fence_pos && $fence_pos < $unlock_pos,
	'POSTPUB_FENCE_ORDERED_AFTER_PUBLISH_BEFORE_UNLOCK'
);

unset( $GLOBALS['nvx_mock_time'] );

if ( ! empty( $failures ) ) {
	fwrite(
		STDERR,
		'OUTBOX_CONCURRENCY_V2=FAIL failures=' . implode( ',', $failures ) . "\n"
	);
	exit( 1 );
}

echo "OUTBOX_CONCURRENCY_V2=PASS exact_expiry=1 stale_renewal=blocked dedupe=cas_recovery cache_refresh=1 acquisition=bounded attempts=cas_conflict_safe meta_failure=bounded next_attempt=monotonic due=revalidated publish=two_phase publication_failure=fail_closed fencing=ordered contender_recovery=1 takeover_fenced=1 contention_attempts=1 post_publish_fence=1\n";


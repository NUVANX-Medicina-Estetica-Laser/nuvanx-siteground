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

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public $ID = 0;
		public $post_status = 'pending';
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
function absint( $value ): int { return abs( (int) $value ); }

$GLOBALS['nvx_mock_options']              = array();
$GLOBALS['nvx_uuid_counter']              = 0;
$GLOBALS['nvx_mock_post_meta']            = array();
$GLOBALS['nvx_mock_meta_conflict_values'] = array();

function get_post( $post_id ) {
	$post              = new WP_Post();
	$post->ID          = (int) $post_id;
	$post->post_status = 'pending';
	return $post;
}

function get_post_meta( $post_id, $key = '', $single = false ) {
	unset( $single );
	return $GLOBALS['nvx_mock_post_meta'][ (int) $post_id ][ (string) $key ] ?? '';
}

function update_post_meta( $post_id, $key, $value, $prev_value = '' ) {
	$post_id = (int) $post_id;
	$key     = (string) $key;
	$current = (string) ( $GLOBALS['nvx_mock_post_meta'][ $post_id ][ $key ] ?? '' );

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

function wp_generate_uuid4(): string {
	$GLOBALS['nvx_uuid_counter']++;
	return sprintf( '00000000-0000-4000-8000-%012d', $GLOBALS['nvx_uuid_counter'] );
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

// Invariant 5: retry scheduling is monotonic even after a CAS conflict.
$GLOBALS['nvx_mock_post_meta'][ $post_id ]['_nvx_relay_next_attempt'] = '500';
nvx_supabase_relay_queue_set_next_attempt_monotonic( $post_id, 400 );
$require(
	'500' === ( $GLOBALS['nvx_mock_post_meta'][ $post_id ]['_nvx_relay_next_attempt'] ?? '' ),
	'NEXT_ATTEMPT_NO_REGRESSION'
);

$GLOBALS['nvx_mock_meta_conflict_values']['_nvx_relay_next_attempt'] = '650';
nvx_supabase_relay_queue_set_next_attempt_monotonic( $post_id, 600 );
$require(
	'650' === ( $GLOBALS['nvx_mock_post_meta'][ $post_id ]['_nvx_relay_next_attempt'] ?? '' ),
	'NEXT_ATTEMPT_CONCURRENT_FORWARD_WINS'
);

$GLOBALS['nvx_mock_meta_conflict_values']['_nvx_relay_next_attempt'] = '650';
$GLOBALS['nvx_mock_post_meta'][ $post_id ]['_nvx_relay_next_attempt'] = '500';
nvx_supabase_relay_queue_set_next_attempt_monotonic( $post_id, 700 );
$require(
	'700' === ( $GLOBALS['nvx_mock_post_meta'][ $post_id ]['_nvx_relay_next_attempt'] ?? '' ),
	'NEXT_ATTEMPT_RETRY_COMMITS_MAX'
);

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

// Invariant 7: post-I/O fence rejects wrong or expired owners.
$GLOBALS['nvx_mock_options'][ $lock_key ] = ( $now + 30 ) . '|fence-owner';
$require( true === nvx_supabase_relay_queue_lock_owned( 'fence-owner' ), 'POST_IO_FENCE_VALID_OWNER' );
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

unset( $GLOBALS['nvx_mock_time'] );

if ( ! empty( $failures ) ) {
	fwrite(
		STDERR,
		'OUTBOX_CONCURRENCY_V2=FAIL failures=' . implode( ',', $failures ) . "\n"
	);
	exit( 1 );
}

echo "OUTBOX_CONCURRENCY_V2=PASS exact_expiry=1 stale_renewal=blocked dedupe=cas_recovery attempts=cas_conflict_safe next_attempt=monotonic due=revalidated publish=two_phase fencing=ordered\n";

<?php
/**
 * Behavioral/static contract for Supabase relay outbox concurrency v2.
 *
 * This harness deliberately uses the queue's mock-time and mock-option fallbacks
 * so lease semantics can be verified without a WordPress database.
 *
 * @package nuvanx-medical
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'NVX_SUPABASE_RELAY_QUEUE_LOCK_TTL', 60 );

if ( ! class_exists( 'WP_Error' ) ) {
class WP_Post {
tpublic $ID = 0;
tpublic $post_status = 'pending';
}

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

$GLOBALS['nvx_mock_options'] = array();
$GLOBALS['nvx_uuid_counter'] = 0;

function get_post( $post_id ) {
t$post = new WP_Post();
t$post->ID = (int) $post_id;
t$post->post_status = 'pending';
treturn $post;
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
$GLOBALS['nvx_mock_time']                = $now;
$GLOBALS['nvx_mock_options'][ $lock_key ] = $now . '|expired-owner';
$takeover = nvx_supabase_relay_queue_lock( 60 );
$require( '' !== $takeover, 'EXACT_EXPIRY_TAKEOVER' );
if ( '' !== $takeover ) {
	nvx_supabase_relay_queue_unlock( $takeover );
}

// Invariant 2: an owner may never resurrect its lease after the lease expired.
$GLOBALS['nvx_mock_time']                 = $now;
$GLOBALS['nvx_mock_options'][ $lock_key ] = ( $now - 1 ) . '|stale-owner';
$renewed = nvx_supabase_relay_queue_renew_lock( 'stale-owner', 60 );
$require( false === $renewed, 'EXPIRED_OWNER_RENEWAL_REJECTED' );
$require(
	( $now - 1 ) . '|stale-owner' === ( $GLOBALS['nvx_mock_options'][ $lock_key ] ?? '' ),
	'EXPIRED_OWNER_RENEWAL_NO_MUTATION'
);

// Invariant 3: exact-body dedupe creation needs a DB-backed atomic reservation/index.
$require(
	str_contains( $queue, 'nvx_supabase_relay_queue_dedupe_reservation' ),
	'ATOMIC_DEDUPE_RESERVATION_PRIMITIVE'
);

// Invariant 4: attempt accumulation and retry scheduling must be atomic/monotonic.
$require(
	str_contains( $queue, 'nvx_supabase_relay_queue_atomic_add_attempts' ),
	'ATOMIC_ATTEMPT_ACCUMULATION_PRIMITIVE'
);
$require(
	str_contains( $queue, 'nvx_supabase_relay_queue_set_next_attempt_monotonic' ),
	'MONOTONIC_NEXT_ATTEMPT_PRIMITIVE'
);

// Invariant 5: new queue posts must not become drainable before canonical metadata exists.
$enqueue_start = strpos( $queue, 'function nvx_supabase_relay_queue_enqueue(' );
$send_start    = strpos( $queue, 'function nvx_supabase_relay_queue_send(' );
$enqueue       = ( false !== $enqueue_start && false !== $send_start && $send_start > $enqueue_start )
	? substr( $queue, $enqueue_start, $send_start - $enqueue_start )
	: '';
$require( '' !== $enqueue, 'ENQUEUE_SOURCE_BOUNDARY' );
$require(
	preg_match( "/'post_status'\\s*=>\\s*'draft'/", $enqueue ) === 1,
	'NEW_ITEM_NON_DRAINABLE_UNTIL_COMPLETE'
);
$require(
	preg_match( "/wp_update_post\\([\\s\\S]*?'post_status'\\s*=>\\s*'pending'/", $enqueue ) === 1,
	'NEW_ITEM_PUBLISHED_AFTER_METADATA'
);

// Invariant 6: drain must revalidate due state and ownership after network I/O.
$require(
	str_contains( $queue, 'nvx_supabase_relay_queue_item_due' ),
	'DUE_STATE_REVALIDATION_PRIMITIVE'
);
$require(
	str_contains( $queue, 'nvx_supabase_relay_queue_lock_owned' ),
	'POST_IO_FENCING_PRIMITIVE'
);

unset( $GLOBALS['nvx_mock_time'] );

if ( ! empty( $failures ) ) {
	fwrite(
		STDERR,
		'OUTBOX_CONCURRENCY_V2=FAIL failures=' . implode( ',', $failures ) . "\n"
	);
	exit( 1 );
}

echo "OUTBOX_CONCURRENCY_V2=PASS exact_expiry=1 stale_renewal=blocked dedupe=atomic attempts=atomic next_attempt=monotonic publish=two_phase due=revalidated fencing=post_io\n";

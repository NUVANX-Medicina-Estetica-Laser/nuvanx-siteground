<?php
/** Deterministic runtime coverage for Outbox P1 telemetry boundaries. */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );
define( 'NVX_SUPABASE_RELAY_QUEUE_MAX_TRIES', 8 );

$GLOBALS['nvx_test_events'] = array();
$GLOBALS['nvx_test_atomic_result'] = 2;
$GLOBALS['nvx_test_next_attempt_ok'] = true;
$GLOBALS['nvx_test_fresh_option'] = '';
$GLOBALS['nvx_test_time'] = 1000;

function sanitize_key( $value ): string {
	$value = strtolower( (string) $value );
	return preg_replace( '/[^a-z0-9_\-]/', '', $value ) ?? '';
}
function absint( $value ): int { return abs( (int) $value ); }
function nvx_observability_log( string $domain, string $event, array $context = array() ): void {
	$GLOBALS['nvx_test_events'][] = array( $domain, $event, $context );
}
function nvx_supabase_relay_log( string $endpoint, string $outcome, int $status = 0, string $reason = '' ): void {
	$GLOBALS['nvx_test_events'][] = array( 'relay', strtolower( $outcome ), array( 'endpoint' => $endpoint, 'status' => $status, 'reason' => $reason ) );
}
function nvx_supabase_relay_queue_atomic_add_attempts( int $post_id, int $attempts ): ?int {
	unset( $post_id, $attempts );
	return $GLOBALS['nvx_test_atomic_result'];
}
function nvx_supabase_relay_queue_backoff_seconds( int $attempt ): int { return max( 1, $attempt ); }
function nvx_supabase_relay_queue_set_next_attempt_monotonic( int $post_id, int $next ): bool {
	unset( $post_id, $next );
	return (bool) $GLOBALS['nvx_test_next_attempt_ok'];
}
function nvx_supabase_relay_queue_mark_dead( int $post_id, string $endpoint, int $status, string $reason ): void {
	$GLOBALS['nvx_test_events'][] = array( 'dead', $reason, array( 'post_id' => $post_id, 'endpoint' => $endpoint, 'status' => $status ) );
}
function nvx_supabase_relay_queue_fresh_option( string $key ): string {
	unset( $key );
	return (string) $GLOBALS['nvx_test_fresh_option'];
}
function nvx_supabase_relay_time(): int { return (int) $GLOBALS['nvx_test_time']; }

require_once dirname( __DIR__, 2 ) . '/wp-content/themes/nuvanx-medical/inc/nvx-supabase-relay-queue-policy.php';

$require = static function ( bool $condition, string $name ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "OUTBOX_P1_TELEMETRY_RUNTIME=FAIL {$name}\n" );
		exit( 1 );
	}
};
$has_event = static function ( string $domain, string $event ): bool {
	foreach ( $GLOBALS['nvx_test_events'] as $row ) {
		if ( $row[0] === $domain && $row[1] === $event ) {
			return true;
		}
	}
	return false;
};

// A canonical existing row reused by a second enqueue must emit an explicit signal.
$GLOBALS['nvx_test_events'] = array();
$GLOBALS['nvx_test_atomic_result'] = 2;
$GLOBALS['nvx_test_next_attempt_ok'] = true;
$result = nvx_supabase_relay_queue_record_existing_attempt( 42, 'lead_captured', 1 );
$require( 42 === $result, 'dedupe_reuse_result' );
$require( $has_event( 'supabase_relay_ops', 'dedupe_reused' ), 'dedupe_reused_event' );

// CAS exhaustion while recording retry state must not be silent.
$GLOBALS['nvx_test_events'] = array();
$GLOBALS['nvx_test_atomic_result'] = null;
nvx_supabase_relay_queue_record_existing_attempt( 42, 'lead_captured', 1 );
$require( $has_event( 'supabase_relay_ops', 'retry_state_conflict' ), 'retry_state_conflict_attempts' );

// A next-attempt monotonic write conflict is a distinct retry-state conflict.
$GLOBALS['nvx_test_events'] = array();
$GLOBALS['nvx_test_atomic_result'] = 3;
$GLOBALS['nvx_test_next_attempt_ok'] = false;
nvx_supabase_relay_queue_record_existing_attempt( 42, 'lead_captured', 1 );
$require( $has_event( 'supabase_relay_ops', 'retry_state_conflict' ), 'retry_state_conflict_next_attempt' );

// The post-I/O fence emits lease loss exactly when token/expiry ownership is gone.
$GLOBALS['nvx_test_events'] = array();
$GLOBALS['nvx_test_fresh_option'] = '1100|other-token';
$GLOBALS['nvx_test_time'] = 1000;
$require( ! nvx_supabase_relay_queue_lock_owned( 'worker-token' ), 'lease_lost_result' );
$require( $has_event( 'supabase_relay_ops', 'drain_lease_lost' ), 'drain_lease_lost_event' );

$GLOBALS['nvx_test_events'] = array();
$GLOBALS['nvx_test_fresh_option'] = '1100|worker-token';
$require( nvx_supabase_relay_queue_lock_owned( 'worker-token' ), 'lease_owned_result' );
$require( ! $has_event( 'supabase_relay_ops', 'drain_lease_lost' ), 'lease_owned_no_false_event' );

echo 'OUTBOX_P1_TELEMETRY_RUNTIME=PASS dedupe_reused=explicit retry_state_conflict=explicit drain_lease_lost=post_io_fence' . PHP_EOL;

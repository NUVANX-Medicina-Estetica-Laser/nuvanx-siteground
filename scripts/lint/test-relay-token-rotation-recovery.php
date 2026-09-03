<?php
/**
 * Behavioral regression for token rotation cache separation, 401 recovery,
 * and atomic CAS drain lock concurrency.
 *
 * @package nuvanx-medical
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'NVX_LEAD_CAPTURED_MAX_BODY_BYTES', 32768 );
define( 'NVX_SUPABASE_RELAY_QUEUE_LOCK_TTL', 60 );

if ( ! class_exists( 'WP_Error' ) ) {
	final class WP_Error {
		private string $code;
		private string $message;

		public function __construct( string $code, string $message ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

function is_wp_error( $thing ): bool {
	return $thing instanceof WP_Error;
}

function add_action( ...$args ): void { unset( $args ); }
function add_filter( ...$args ): void { unset( $args ); }
function register_post_type( ...$args ): void { unset( $args ); }
function add_rewrite_endpoint( ...$args ): void { unset( $args ); }
function wp_next_scheduled( ...$args ) { return false; }
function wp_schedule_event( ...$args ) { return true; }
function wp_clear_scheduled_hook( ...$args ) { return 1; }
function wp_parse_url( $url, int $component = -1 ) { return parse_url( (string) $url, $component ); }

function nvx_attribution_collector_canonical_endpoint(): string {
	return 'https://ssvvuuysgxyqvmovrlvk.supabase.co/functions/v1/google-click-attribution';
}

$GLOBALS['transients'] = array();
function get_transient( string $key ) {
	return $GLOBALS['transients'][ $key ] ?? false;
}
function set_transient( string $key, $value, int $expiration = 0 ): bool {
	unset( $expiration );
	$GLOBALS['transients'][ $key ] = (string) $value;
	return true;
}
function delete_transient( string $key ): bool {
	unset( $GLOBALS['transients'][ $key ] );
	return true;
}

$GLOBALS['nvx_mock_options'] = array();
function get_option( string $key, $default = false ) {
	return $GLOBALS['nvx_mock_options'][ $key ] ?? $default;
}
function add_option( string $key, $value, $deprecated = '', $autoload = 'yes' ): bool {
	unset( $deprecated, $autoload );
	if ( isset( $GLOBALS['nvx_mock_options'][ $key ] ) ) {
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

$GLOBALS['post_meta'] = array();
function get_post_meta( int $post_id, string $key = '', bool $single = false ) {
	unset( $single );
	return $GLOBALS['post_meta'][ $post_id ][ $key ] ?? '';
}
function update_post_meta( int $post_id, string $key, $value ): bool {
	$GLOBALS['post_meta'][ $post_id ][ $key ] = (string) $value;
	return true;
}
function add_post_meta( int $post_id, string $key, $value, bool $unique = false ): bool {
	unset( $unique );
	return update_post_meta( $post_id, $key, $value );
}
function wp_delete_post( int $post_id, bool $force = false ): bool {
	unset( $post_id, $force );
	return true;
}
function wp_update_post( array $args = array() ): int {
	return (int) ( $args['ID'] ?? 0 );
}
function wp_slash( $val ) { return $val; }
function wp_unslash( $val ) { return $val; }

$GLOBALS['nvx_next_post_id'] = 2000;
function wp_insert_post( array $args, bool $wp_error = false ) {
	unset( $wp_error );
	$id = ++$GLOBALS['nvx_next_post_id'];
	if ( isset( $args['meta_input'] ) && is_array( $args['meta_input'] ) ) {
		foreach ( $args['meta_input'] as $k => $v ) {
			update_post_meta( $id, $k, (string) $v );
		}
	}
	return $id;
}

if ( ! class_exists( 'WP_Post' ) ) {
	final class WP_Post {
		public int $ID = 0;
		public string $post_content = '';
		public string $post_status = 'pending';
	}
}

if ( ! class_exists( 'WP_Query' ) ) {
	class WP_Query {
		public array $posts = array();
		public function __construct( array $args = array() ) {
			$this->posts = $GLOBALS['mock_query_posts'] ?? array();
		}
	}
}

function get_post( $post_id ) {
	foreach ( ($GLOBALS['mock_query_posts'] ?? array()) as $p ) {
		if ( $p instanceof WP_Post && $p->ID == $post_id ) {
			$p->post_status = 'pending';
			return $p;
		}
	}
	$post = new WP_Post();
	$post->ID = (int) $post_id;
	$post->post_status = 'pending';
	return $post;
}

function get_posts( array $args = array() ): array {
	return $GLOBALS['mock_get_posts'] ?? array();
}

function sanitize_key( $key ): string {
	return preg_replace( '/[^a-z0-9_-]/i', '', (string) $key );
}
function sanitize_text_field( $str ): string {
	return trim( (string) $str );
}
function sanitize_url( $url ): string {
	return trim( (string) $url );
}
function absint( $maybeint ): int {
	return abs( (int) $maybeint );
}
function wp_remote_retrieve_response_code( $response ): int {
	if ( is_array( $response ) && isset( $response['response']['code'] ) ) {
		return (int) $response['response']['code'];
	}
	return 0;
}
function wp_remote_retrieve_body( $response ): string {
	if ( is_array( $response ) && isset( $response['body'] ) ) {
		return (string) $response['body'];
	}
	return '';
}
function wp_json_encode( $value ) {
	return json_encode( $value );
}

$GLOBALS['remote_post_log'] = array();
$GLOBALS['mock_responses']  = array();

function wp_remote_post( string $url, array $args = array() ) {
	$GLOBALS['remote_post_log'][] = array(
		'url'  => $url,
		'args' => $args,
	);

	if ( ! empty( $GLOBALS['mock_responses'] ) ) {
		return array_shift( $GLOBALS['mock_responses'] );
	}

	return array(
		'response' => array( 'code' => 200 ),
		'body'     => '{"ok":true}',
	);
}

// Load lead captured relay functions
require_once dirname( __DIR__, 2 ) . '/wp-content/themes/nuvanx-medical/inc/nvx-lead-captured-relay.php';

// Assert Test 1: Token-specific transients
$token1 = 'token-alpha-1111';
$token2 = 'token-beta-2222';

$GLOBALS['remote_post_log'] = array();
$res1 = nvx_lead_captured_bootstrap_runtime( $token1 );
assert( true === $res1, 'Token 1 bootstrap must succeed' );
assert( 1 === count( $GLOBALS['remote_post_log'] ), 'Token 1 must invoke bootstrap endpoint' );

$hash1 = substr( hash( 'sha256', 'nvx_runtime_bootstrap|' . $token1 ), 0, 16 );
assert( '1' === get_transient( 'nvx_rt_boot_' . $hash1 ), 'Token 1 transient must be set' );

// Second call with token 1 returns from cache (no HTTP call)
$res1_cached = nvx_lead_captured_bootstrap_runtime( $token1 );
assert( true === $res1_cached, 'Token 1 cached bootstrap must return true' );
assert( 1 === count( $GLOBALS['remote_post_log'] ), 'Token 1 second call must hit cache without HTTP' );

// Call with token 2: MUST NOT use token 1 cache!
$res2 = nvx_lead_captured_bootstrap_runtime( $token2 );
assert( true === $res2, 'Token 2 bootstrap must succeed' );
assert( 2 === count( $GLOBALS['remote_post_log'] ), 'Token 2 must invoke bootstrap HTTP endpoint despite Token 1 cached' );

$hash2 = substr( hash( 'sha256', 'nvx_runtime_bootstrap|' . $token2 ), 0, 16 );
assert( $hash1 !== $hash2, 'Token hashes must differ' );
assert( '1' === get_transient( 'nvx_rt_boot_' . $hash2 ), 'Token 2 transient must be set' );

// Forcing bootstrap with $force = true deletes cache and calls HTTP
$res1_forced = nvx_lead_captured_bootstrap_runtime( $token1, true );
assert( true === $res1_forced, 'Forced bootstrap must succeed' );
assert( 3 === count( $GLOBALS['remote_post_log'] ), 'Forced bootstrap must trigger HTTP call' );

// Set up mock token constant for testing
if ( ! defined( 'NVX_HUBSPOT_ACCESS_TOKEN' ) ) {
	define( 'NVX_HUBSPOT_ACCESS_TOKEN', 'test-hubspot-access-token-active' );
}

// Mark active token as initially bootstrapped in cache
$active_token = 'test-hubspot-access-token-active';
$active_hash  = substr( hash( 'sha256', 'nvx_runtime_bootstrap|' . $active_token ), 0, 16 );
set_transient( 'nvx_rt_boot_' . $active_hash, '1' );

// Load relay queue functions
require_once dirname( __DIR__, 2 ) . '/wp-content/themes/nuvanx-medical/inc/nvx-supabase-relay-queue.php';

// Assert Test 2: Bounded 401 recovery on dispatch(lead_captured)
$valid_body = '{"lead_id":"d1234567-89ab-4cde-0123-456789abcdef","client_timestamp":"2026-09-02T10:00:00Z"}';

// Reset logs and mock responses:
// 1st remote_post (signed send): returns 401
// 2nd remote_post (forced bootstrap): returns 200
// 3rd remote_post (retry signed send): returns 200
$GLOBALS['remote_post_log'] = array();
$GLOBALS['mock_responses']  = array(
	array( 'response' => array( 'code' => 401 ), 'body' => '{"error":"unauthorized"}' ),
	array( 'response' => array( 'code' => 200 ), 'body' => '{"ok":true}' ),
	array( 'response' => array( 'code' => 200 ), 'body' => '{"success":true}' ),
);

$dispatch_result = nvx_supabase_relay_dispatch( 'lead_captured', $valid_body );
assert( 'SUCCESS' === $dispatch_result['outcome'], 'Recovered dispatch must have SUCCESS outcome' );
assert( 200 === $dispatch_result['status'], 'Recovered dispatch must return status 200' );
assert( 0 === $dispatch_result['queued'], 'Recovered dispatch must not enqueue' );
assert( 3 === count( $GLOBALS['remote_post_log'] ), 'Expected 3 HTTP calls: send(401) -> bootstrap(200) -> resend(200)' );

// Assert Test 3: Bounded recovery when retry still returns 401 (must not infinite loop)
set_transient( 'nvx_rt_boot_' . $active_hash, '1' );
$GLOBALS['remote_post_log'] = array();
$GLOBALS['mock_responses']  = array(
	array( 'response' => array( 'code' => 401 ), 'body' => '{"error":"unauthorized"}' ),
	array( 'response' => array( 'code' => 200 ), 'body' => '{"ok":true}' ),
	array( 'response' => array( 'code' => 401 ), 'body' => '{"error":"unauthorized_still"}' ),
);

$dispatch_result_401 = nvx_supabase_relay_dispatch( 'lead_captured', $valid_body );
assert( 'HTTP_4XX' === $dispatch_result_401['outcome'], 'Exhausted retry yields HTTP_4XX' );
assert( 401 === $dispatch_result_401['status'], 'Exhausted retry yields 401' );
assert( 0 === $dispatch_result_401['queued'], 'Unrecoverable 401 must not be queued' );
assert( 3 === count( $GLOBALS['remote_post_log'] ), 'Expected strictly bounded 3 HTTP calls (1 send + 1 bootstrap + 1 retry)' );

// Assert Test 4: When forced bootstrap fails during 401 recovery, dispatch marks failure as retryable
set_transient( 'nvx_rt_boot_' . $active_hash, '1' );
$GLOBALS['remote_post_log'] = array();
$GLOBALS['mock_responses']  = array(
	array( 'response' => array( 'code' => 401 ), 'body' => '{"error":"unauthorized"}' ),
	array( 'response' => array( 'code' => 500 ), 'body' => '{"error":"server error"}' ),
);

$send_result_fail = nvx_supabase_relay_queue_send( 'lead_captured', $valid_body, '', true );
assert( is_wp_error( $send_result_fail ), 'Failed forced bootstrap must return WP_Error' );
assert( 'nvx_runtime_bootstrap_unavailable' === $send_result_fail->get_error_code(), 'Must return bootstrap unavailable code' );

$classified = nvx_supabase_relay_classify( $send_result_fail );
assert( true === $classified['retryable'], 'Failed bootstrap must be retryable (not dead)' );

// Assert Test 5: Bounded 401 recovery on dispatch(google_click)
set_transient( 'nvx_rt_boot_' . $active_hash, '1' );
$click_body = '{"gclid":"test-gclid-123","source":"adwords"}';
$origin = 'https://nuvanx.es';

$GLOBALS['remote_post_log'] = array();
$GLOBALS['mock_responses']  = array(
	array( 'response' => array( 'code' => 401 ), 'body' => '{"error":"unauthorized"}' ),
	array( 'response' => array( 'code' => 200 ), 'body' => '{"ok":true}' ),
	array( 'response' => array( 'code' => 200 ), 'body' => '{"success":true}' ),
);

$click_dispatch = nvx_supabase_relay_dispatch( 'google_click', $click_body, array( 'Origin' => $origin ) );
assert( 'SUCCESS' === $click_dispatch['outcome'], 'Recovered click dispatch must have SUCCESS outcome' );
assert( 200 === $click_dispatch['status'], 'Recovered click dispatch must return 200' );
assert( 3 === count( $GLOBALS['remote_post_log'] ), 'Expected 3 HTTP calls for google_click dispatch: send(401) -> bootstrap(200) -> resend(200)' );

// Assert Test 6: Concurrency regression test for two contenders observing the same expired lock
class MockWpdbAtomicCas {
	public string $options = 'wp_options';

	public function prepare( string $query, ...$args ): string {
		foreach ( $args as $arg ) {
			$val   = "'" . addslashes( (string) $arg ) . "'";
			$query = preg_replace( '/%s/', $val, $query, 1 );
		}
		return $query;
	}

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	public function query( string $query ): int {
		// Atomic CAS: UPDATE wp_options SET option_value = '...' WHERE option_name = '...' AND option_value = '...'
		if ( preg_match( "/UPDATE wp_options SET option_value = '(.*?)' WHERE option_name = '(.*?)' AND option_value = '(.*?)'/", $query, $m ) ) {
			$new_val  = $m[1];
			$key      = $m[2];
			$expected = $m[3];

			if ( ( $GLOBALS['nvx_mock_options'][ $key ] ?? null ) === $expected ) {
				$GLOBALS['nvx_mock_options'][ $key ] = $new_val;
				return 1;
			}
			return 0;
		}

		// Atomic release: DELETE FROM wp_options WHERE option_name = '...' AND option_value LIKE '%|...'
		if ( preg_match( "/DELETE FROM wp_options WHERE option_name = '(.*?)' AND option_value LIKE '(.*?)'/", $query, $m ) ) {
			$key     = $m[1];
			$pattern = $m[2];
			$suffix  = ltrim( $pattern, '%' );
			if ( isset( $GLOBALS['nvx_mock_options'][ $key ] ) && str_ends_with( $GLOBALS['nvx_mock_options'][ $key ], $suffix ) ) {
				unset( $GLOBALS['nvx_mock_options'][ $key ] );
				return 1;
			}
			return 0;
		}

		return 0;
	}
}

$GLOBALS['wpdb'] = new MockWpdbAtomicCas();

$lock_key = 'nvx_supabase_relay_drain_lock_v1';
$expired_token = 'expired-token-123';
$expired_time = time() - 60;
$GLOBALS['nvx_mock_options'][ $lock_key ] = $expired_time . '|' . $expired_token;

// Both contender A and contender B read the same expired lock
// Contender A attempts lock takeover:
$token_a = nvx_supabase_relay_queue_lock();
assert( '' !== $token_a, 'Contender A must succeed in taking over expired lock' );

// Contender B now attempts lock takeover against the same expired state (which is no longer in DB):
$token_b = nvx_supabase_relay_queue_lock();
assert( '' === $token_b, 'Contender B must lose the race and receive empty lock token' );

// Verify Contender A's lock was NOT deleted by Contender B
assert( isset( $GLOBALS['nvx_mock_options'][ $lock_key ] ), 'Winner lock must not be deleted by contender B' );
assert( str_ends_with( $GLOBALS['nvx_mock_options'][ $lock_key ], '|' . $token_a ), 'Stored lock must belong to contender A' );

// Losing contender B tries to unlock: must not delete winner A's lock
nvx_supabase_relay_queue_unlock( 'wrong-token-b' );
assert( isset( $GLOBALS['nvx_mock_options'][ $lock_key ] ), 'Wrong token unlock must not delete winner A lock' );

// Winning contender A unlocks: lock is cleanly released
nvx_supabase_relay_queue_unlock( $token_a );
assert( ! isset( $GLOBALS['nvx_mock_options'][ $lock_key ] ), 'Winner unlock must cleanly release lock' );

// Assert Test 7: Queued item with 401 whose forced bootstrap fails increments attempts by exactly 1
$post_fail = new WP_Post();
$post_fail->ID = 999;
$post_fail->post_content = $valid_body;
$GLOBALS['mock_query_posts'] = array( $post_fail );
$GLOBALS['post_meta'][999] = array(
	'_nvx_relay_endpoint' => 'lead_captured',
	'_nvx_relay_origin'   => '',
	'_nvx_relay_attempts' => '0',
);

// Clear lock so drain can acquire lock:
unset( $GLOBALS['nvx_mock_options']['nvx_supabase_relay_drain_lock_v1'] );
set_transient( 'nvx_rt_boot_' . $active_hash, '1' );

// Responses:
// 1st call: lead_captured send returns 401
// 2nd call: forced bootstrap returns 500 (fails before second delivery runs)
$GLOBALS['remote_post_log'] = array();
$GLOBALS['mock_responses']  = array(
	array( 'response' => array( 'code' => 401 ), 'body' => '{"error":"unauthorized"}' ),
	array( 'response' => array( 'code' => 500 ), 'body' => '{"error":"bootstrap fail"}' ),
);

nvx_supabase_relay_queue_drain( 1 );

// Assert that attempts is 1 (NOT 2)
assert( '1' === (string) get_post_meta( 999, '_nvx_relay_attempts', true ), 'Failed bootstrap must increment attempts by exactly 1' );
assert( 2 === count( $GLOBALS['remote_post_log'] ), 'Expected 2 HTTP calls: 1 failed delivery + 1 failed bootstrap' );

// Assert Test 8: Queued item with 401 whose forced bootstrap succeeds but second delivery fails increments attempts by 2
$post_retry = new WP_Post();
$post_retry->ID = 1000;
$post_retry->post_content = $valid_body;
$GLOBALS['mock_query_posts'] = array( $post_retry );
$GLOBALS['post_meta'][1000] = array(
	'_nvx_relay_endpoint' => 'lead_captured',
	'_nvx_relay_origin'   => '',
	'_nvx_relay_attempts' => '0',
);

unset( $GLOBALS['nvx_mock_options']['nvx_supabase_relay_drain_lock_v1'] );
set_transient( 'nvx_rt_boot_' . $active_hash, '1' );

// Responses:
// 1st call: send returns 401
// 2nd call: forced bootstrap returns 200
// 3rd call: second delivery returns 401
$GLOBALS['remote_post_log'] = array();
$GLOBALS['mock_responses']  = array(
	array( 'response' => array( 'code' => 401 ), 'body' => '{"error":"unauthorized"}' ),
	array( 'response' => array( 'code' => 200 ), 'body' => '{"ok":true}' ),
	array( 'response' => array( 'code' => 401 ), 'body' => '{"error":"unauthorized_still"}' ),
);

nvx_supabase_relay_queue_drain( 1 );

// Assert that attempts is 2 (because two actual delivery transports were attempted)
assert( '2' === (string) get_post_meta( 1000, '_nvx_relay_attempts', true ), 'Completed second delivery retry must increment attempts by 2' );
assert( 3 === count( $GLOBALS['remote_post_log'] ), 'Expected 3 HTTP calls: 1 failed delivery + 1 successful bootstrap + 1 retry delivery' );

// Assert Test 9: Dispatch 401 -> successful bootstrap -> retryable delivery failure enqueues with 2 attempts
$GLOBALS['remote_post_log'] = array();
$GLOBALS['mock_responses']  = array(
	array( 'response' => array( 'code' => 401 ), 'body' => '{"error":"unauthorized"}' ),
	array( 'response' => array( 'code' => 200 ), 'body' => '{"ok":true}' ),
	array( 'response' => array( 'code' => 500 ), 'body' => '{"error":"internal error"}' ),
);

set_transient( 'nvx_rt_boot_' . $active_hash, '1' );

$dispatch_result = nvx_supabase_relay_dispatch( 'lead_captured', $valid_body );
assert( 500 === $dispatch_result['status'], 'Expected retry delivery 500 status in dispatch' );
assert( $dispatch_result['queued'] > 0, 'Retryable failure must enqueue item from dispatch' );
$enqueued_id = $dispatch_result['queued'];
assert( '2' === (string) get_post_meta( $enqueued_id, '_nvx_relay_attempts', true ), 'Enqueued item must record 2 delivery attempts when retry was completed' );
assert( 3 === count( $GLOBALS['remote_post_log'] ), 'Expected 3 HTTP calls: 1 initial send + 1 bootstrap + 1 retry send' );

// Assert Test 10: Dispatch 401 -> bootstrap failure enqueues with 1 attempt
$fail_body = '{"lead_id":"d1234567-89ab-4cde-0123-bootstrap-fail","client_timestamp":"2026-09-02T10:00:00Z"}';
$GLOBALS['remote_post_log'] = array();
$GLOBALS['mock_responses']  = array(
	array( 'response' => array( 'code' => 401 ), 'body' => '{"error":"unauthorized"}' ),
	array( 'response' => array( 'code' => 500 ), 'body' => '{"error":"bootstrap fail"}' ),
);

set_transient( 'nvx_rt_boot_' . $active_hash, '1' );

$dispatch_fail_boot = nvx_supabase_relay_dispatch( 'lead_captured', $fail_body );
assert( 0 === $dispatch_fail_boot['status'], 'Bootstrap failure produces status 0' );
assert( $dispatch_fail_boot['queued'] > 0, 'Bootstrap failure is retryable so item must be enqueued' );
$enqueued_fail_id = $dispatch_fail_boot['queued'];
assert( '1' === (string) get_post_meta( $enqueued_fail_id, '_nvx_relay_attempts', true ), 'Failed bootstrap before retry must retain 1 attempt' );
assert( 2 === count( $GLOBALS['remote_post_log'] ), 'Expected 2 HTTP calls: 1 initial send + 1 failed bootstrap' );

// Assert Test 11: Drain lock renewal across worst-case batch (150s) prevents second worker from acquiring lock
unset( $GLOBALS['nvx_mock_options']['nvx_supabase_relay_drain_lock_v1'] );

$start_time = 1700000000;
$GLOBALS['nvx_mock_time'] = $start_time;

// Baseline verification: prove that without renewal, advancing clock past lease expiry allows Worker B to take over
$baseline_token = nvx_supabase_relay_queue_lock( 60 );
assert( '' !== $baseline_token, 'Worker A acquires 60s lease' );
$GLOBALS['nvx_mock_time'] = $start_time + 70; // 10s past 60s expiry
$takeover_token = nvx_supabase_relay_queue_lock( 60 );
assert( '' !== $takeover_token, 'Without renewal, advancing clock past expiry must permit takeover' );
nvx_supabase_relay_queue_unlock( $takeover_token );

// Now test batch with renewal: 10 items taking 15s each (total 150s)
$GLOBALS['nvx_mock_time'] = $start_time;
$batch_limit              = 10;
$derived_ttl              = nvx_supabase_relay_queue_lock_ttl( $batch_limit );
assert( $derived_ttl >= 180, 'Derived lease for 10 items must be >= 180s' );

// Worker 1 acquires lock
$worker1_lock = nvx_supabase_relay_queue_lock( $derived_ttl );
assert( '' !== $worker1_lock, 'Worker 1 must acquire initial drain lock' );

// Simulate 10 items in a worst-case batch advancing mock clock by 15s per item (total 150s)
for ( $i = 1; $i <= 10; $i++ ) {
	// Advance clock by 15 seconds simulating delivery, bootstrap, and retry transport
	$GLOBALS['nvx_mock_time'] += 15;

	// Worker 1 renews lease while draining:
	$renewed = nvx_supabase_relay_queue_renew_lock( $worker1_lock, $derived_ttl );
	assert( $renewed, "Lock renewal must succeed at batch step {$i}" );

	// Concurrently, Worker 2 attempts acquisition during draining:
	$worker2_attempt = nvx_supabase_relay_queue_lock( $derived_ttl );
	assert( '' === $worker2_attempt, "Worker 2 must NOT acquire lock while Worker 1 is draining at step {$i} (elapsed: " . ( $GLOBALS['nvx_mock_time'] - $start_time ) . "s)" );
}

// Verify that 150 seconds elapsed during the batch
assert( ( $start_time + 150 ) === $GLOBALS['nvx_mock_time'], 'Mock clock must have advanced 150s' );

// After batch completes, Worker 1 releases lock cleanly:
nvx_supabase_relay_queue_unlock( $worker1_lock );
assert( ! isset( $GLOBALS['nvx_mock_options']['nvx_supabase_relay_drain_lock_v1'] ), 'Worker 1 unlock must cleanly release lock' );

// Now Worker 2 can acquire lock:
$worker2_lock = nvx_supabase_relay_queue_lock( $derived_ttl );
assert( '' !== $worker2_lock, 'Worker 2 can acquire lock once Worker 1 unlocks' );
nvx_supabase_relay_queue_unlock( $worker2_lock );

unset( $GLOBALS['nvx_mock_time'] );

// Assert Test 12: Duplicate enqueue accumulates attempts and enforces max retry limits
$dedupe_test_body = '{"lead_id":"dedupe-test-99"}';
$existing_item_id = 3001;
$dedupe_test_key  = nvx_supabase_relay_dedupe_key( 'lead_captured', $dedupe_test_body, '' );
$GLOBALS['post_meta'][$existing_item_id] = array(
	'_nvx_relay_attempts'   => '2',
	'_nvx_relay_dedupe_key' => $dedupe_test_key,
);
$GLOBALS['mock_get_posts'] = array( $existing_item_id );

// Enqueue with 2 attempts:
$res1 = nvx_supabase_relay_queue_enqueue( 'lead_captured', $dedupe_test_body, array(), 2 );
assert( $res1 === $existing_item_id, 'Must return existing item ID' );
assert( '4' === (string) get_post_meta( $existing_item_id, '_nvx_relay_attempts', true ), 'Attempts must accumulate from 2 to 4' );

// Enqueue again with 4 attempts (4 + 4 = 8, reaching max tries):
$res2 = nvx_supabase_relay_queue_enqueue( 'lead_captured', $dedupe_test_body, array(), 4 );
assert( $res2 === $existing_item_id, 'Must return existing item ID' );
assert( '8' === (string) get_post_meta( $existing_item_id, '_nvx_relay_attempts', true ), 'Attempts must reach 8 and trigger mark_dead' );

$GLOBALS['mock_get_posts'] = array();

echo "RELAY_TOKEN_ROTATION_RECOVERY=PASS token_cache=isolated force_bootstrap=verified 401_recovery=bounded_retry retryable_on_bootstrap_fail=1 google_click_401=verified attempt_accounting=aligned expired_lock_cas=atomic drain_bootstrap_fail_accounting=1 dispatch_retry_accounting=aligned drain_lock_renewal=verified duplicate_enqueue_accounting=aligned\n";

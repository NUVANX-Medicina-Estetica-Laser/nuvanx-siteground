<?php
/**
 * Behavioral regression for token rotation cache separation and 401 recovery.
 *
 * @package nuvanx-medical
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'NVX_LEAD_CAPTURED_MAX_BODY_BYTES', 32768 );

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

// Assert Test 2: Bounded 401 recovery on lead_captured
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

$send_result = nvx_supabase_relay_queue_send( 'lead_captured', $valid_body );
assert( ! is_wp_error( $send_result ), 'Recovered send must not be WP_Error' );
assert( 200 === wp_remote_retrieve_response_code( $send_result ), 'Recovered send must return 200' );
assert( 3 === count( $GLOBALS['remote_post_log'] ), 'Expected 3 HTTP calls: send(401) -> bootstrap(200) -> resend(200)' );

// Assert Test 3: Bounded recovery when retry still returns 401 (must not infinite loop)
set_transient( 'nvx_rt_boot_' . $active_hash, '1' );
$GLOBALS['remote_post_log'] = array();
$GLOBALS['mock_responses']  = array(
	array( 'response' => array( 'code' => 401 ), 'body' => '{"error":"unauthorized"}' ),
	array( 'response' => array( 'code' => 200 ), 'body' => '{"ok":true}' ),
	array( 'response' => array( 'code' => 401 ), 'body' => '{"error":"unauthorized_still"}' ),
);

$send_result_401 = nvx_supabase_relay_queue_send( 'lead_captured', $valid_body );
assert( ! is_wp_error( $send_result_401 ), 'Exhausted retry returns array response' );
assert( 401 === wp_remote_retrieve_response_code( $send_result_401 ), 'Exhausted retry returns 401' );
assert( 3 === count( $GLOBALS['remote_post_log'] ), 'Expected strictly bounded 3 HTTP calls (no infinite loop)' );

// Assert Test 4: When forced bootstrap fails, returns retryable WP_Error
set_transient( 'nvx_rt_boot_' . $active_hash, '1' );
$GLOBALS['remote_post_log'] = array();
$GLOBALS['mock_responses']  = array(
	array( 'response' => array( 'code' => 401 ), 'body' => '{"error":"unauthorized"}' ),
	array( 'response' => array( 'code' => 500 ), 'body' => '{"error":"server error"}' ),
);

$send_result_fail = nvx_supabase_relay_queue_send( 'lead_captured', $valid_body );
assert( is_wp_error( $send_result_fail ), 'Failed forced bootstrap must return WP_Error' );
assert( 'nvx_runtime_bootstrap_unavailable' === $send_result_fail->get_error_code(), 'Must return bootstrap unavailable code' );

$classified = nvx_supabase_relay_classify( $send_result_fail );
assert( true === $classified['retryable'], 'Failed bootstrap must be retryable (not dead)' );

// Assert Test 5: Bounded 401 recovery on google_click
set_transient( 'nvx_rt_boot_' . $active_hash, '1' );
$click_body = '{"gclid":"test-gclid-123","source":"adwords"}';
$origin = 'https://nuvanx.es';

$GLOBALS['remote_post_log'] = array();
$GLOBALS['mock_responses']  = array(
	array( 'response' => array( 'code' => 401 ), 'body' => '{"error":"unauthorized"}' ),
	array( 'response' => array( 'code' => 200 ), 'body' => '{"ok":true}' ),
	array( 'response' => array( 'code' => 200 ), 'body' => '{"success":true}' ),
);

$click_result = nvx_supabase_relay_queue_send( 'google_click', $click_body, $origin );
assert( ! is_wp_error( $click_result ), 'Recovered click send must not be WP_Error' );
assert( 200 === wp_remote_retrieve_response_code( $click_result ), 'Recovered click send must return 200' );
assert( 3 === count( $GLOBALS['remote_post_log'] ), 'Expected 3 HTTP calls for google_click: send(401) -> bootstrap(200) -> resend(200)' );

echo "RELAY_TOKEN_ROTATION_RECOVERY=PASS token_cache=isolated force_bootstrap=verified 401_recovery=bounded_retry retryable_on_bootstrap_fail=1 google_click_401=verified\n";

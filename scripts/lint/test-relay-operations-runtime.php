<?php
/** Deterministic unit contract for relay endpoint payload ceilings. */

declare(strict_types=1);

const ABSPATH = __DIR__ . '/';
const DAY_IN_SECONDS = 86400;
const HOUR_IN_SECONDS = 3600;
const NVX_SUPABASE_RELAY_QUEUE_MAX_BODY_BYTES = 32768;

final class WP_Error {
	public function __construct( private string $code, private string $message = '' ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
}
class WP_Post {
	public int $ID = 1;
	public string $post_type = 'nvx_relay_outbox';
}

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function sanitize_key( string $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $value ) ?? '' ); }
function sanitize_url( string $value ): string { return trim( $value ); }
function absint( mixed $value ): int { return abs( (int) $value ); }
function add_action( mixed ...$args ): void { unset( $args ); }
function add_filter( mixed ...$args ): void { unset( $args ); }
function wp_json_encode( mixed $value ): string|false { return json_encode( $value ); }
function nvx_supabase_relay_queue_endpoints(): array { return array( 'google_click' => 'https://example.test/google-click' ); }

require_once dirname( __DIR__, 2 ) . '/wp-content/themes/nuvanx-medical/inc/nvx-supabase-relay-operations.php';

$require = static function ( bool $condition, string $label ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL {$label}\n" );
		exit( 1 );
	}
	fwrite( STDOUT, "PASS {$label}\n" );
};

$small_click = json_encode( array( 'payload' => str_repeat( 'a', 8000 ) ), JSON_THROW_ON_ERROR );
$large_click = json_encode( array( 'payload' => str_repeat( 'a', 9000 ) ), JSON_THROW_ON_ERROR );
$large_lead  = json_encode( array( 'payload' => str_repeat( 'a', 9000 ) ), JSON_THROW_ON_ERROR );

$require( true === nvx_supabase_relay_validate_payload( 'google_click', $small_click ), 'GOOGLE_CLICK_UNDER_8192_ACCEPTED' );
$click_reject = nvx_supabase_relay_validate_payload( 'google_click', $large_click );
$require( is_wp_error( $click_reject ), 'GOOGLE_CLICK_OVER_8192_REJECTED' );
$require( 'nvx_relay_payload_too_large' === $click_reject->get_error_code(), 'GOOGLE_CLICK_REJECTION_REASON_STABLE' );
$require( true === nvx_supabase_relay_validate_payload( 'lead_captured', $large_lead ), 'LEAD_CAPTURED_USES_SHARED_32768_LIMIT' );

$invalid = nvx_supabase_relay_validate_payload( 'google_click', '{invalid-json' );
$require( is_wp_error( $invalid ) && 'nvx_relay_payload_invalid' === $invalid->get_error_code(), 'INVALID_JSON_REJECTED' );

$small_preflight = nvx_supabase_relay_operations_preflight_google_click(
	false,
	array( 'body' => $small_click ),
	'https://example.test/google-click'
);
$require( false === $small_preflight, 'VALID_GOOGLE_CLICK_REACHES_NETWORK' );

$large_preflight = nvx_supabase_relay_operations_preflight_google_click(
	false,
	array( 'body' => $large_click ),
	'https://example.test/google-click'
);
$require( is_array( $large_preflight ), 'OVERSIZED_GOOGLE_CLICK_PREEMPTED' );
$require( 422 === (int) ( $large_preflight['response']['code'] ?? 0 ), 'OVERSIZED_GOOGLE_CLICK_TERMINAL_HTTP_422' );

$invalid_preflight = nvx_supabase_relay_operations_preflight_google_click(
	false,
	array( 'body' => '{invalid-json' ),
	'https://example.test/google-click'
);
$require( 422 === (int) ( $invalid_preflight['response']['code'] ?? 0 ), 'INVALID_JSON_TERMINAL_HTTP_422' );

$other_endpoint = nvx_supabase_relay_operations_preflight_google_click(
	false,
	array( 'body' => $large_click ),
	'https://example.test/other'
);
$require( false === $other_endpoint, 'UNRELATED_HTTP_REQUEST_UNTOUCHED' );

fwrite( STDOUT, "OUTBOX_PAYLOAD_LIMIT_RUNTIME=PASS google_click=8192 shared=32768 preflight=terminal_http_422\n" );

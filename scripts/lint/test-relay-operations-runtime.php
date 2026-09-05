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

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function sanitize_key( string $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $value ) ?? '' ); }
function absint( mixed $value ): int { return abs( (int) $value ); }
function add_action( mixed ...$args ): void { unset( $args ); }
function wp_remote_retrieve_response_code( mixed $response ): int { unset( $response ); return 0; }

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
$require( is_wp_error( $invalid ) && 'nvx_relay_payload_invalid' === $invalid->get_error_code(), 'INVALID_JSON_TERMINAL' );
$classified = nvx_supabase_relay_classify( $click_reject );
$require( false === $classified['retryable'], 'OVERSIZED_PAYLOAD_NOT_RETRYABLE' );
$require( 'HTTP_4XX' === $classified['outcome'], 'OVERSIZED_PAYLOAD_TERMINAL_CLASS' );

fwrite( STDOUT, "OUTBOX_PAYLOAD_LIMIT_RUNTIME=PASS google_click=8192 shared=32768\n" );

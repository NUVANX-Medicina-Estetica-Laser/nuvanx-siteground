<?php
/** Behavioral regression for Google attribution relay HMAC authentication. */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );

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

/** No-op filter registration for this isolated callback test. */
function add_filter( ...$args ): void {
	unset( $args );
}

/** WordPress-compatible JSON wrapper for the regression fixture. */
function wp_json_encode( $value ) {
	return json_encode( $value );
}

/** Canonical collector fixture URL. */
function nvx_attribution_collector_canonical_endpoint(): string {
	return 'https://ssvvuuysgxyqvmovrlvk.supabase.co/functions/v1/google-click-attribution';
}

$GLOBALS['nvx_test_google_relay_credential'] = 'fixture-hubspot-server-credential';

/** Existing server-only credential source used by the production module. */
function nvx_lead_captured_hubspot_token(): string {
	return (string) $GLOBALS['nvx_test_google_relay_credential'];
}

/** Minimal assertion helper. */
function nvx_test_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "ASSERTION FAILED: {$message}\n" );
		exit( 1 );
	}
}

require dirname( __DIR__, 2 ) . '/wp-content/themes/nuvanx-medical/inc/nvx-google-attribution-relay-auth.php';

$endpoint   = nvx_attribution_collector_canonical_endpoint();
$credential = nvx_lead_captured_hubspot_token();
$derived    = nvx_google_attribution_derive_hmac_key( $credential );
nvx_test_assert(
	'998f4b930ffd9666e625a38328b50f7b95f846712fb51ee9489b167fd3be07f7' === $derived,
	'domain-separated key must match the cross-language fixture'
);

$body = '{"nvx_lead_id":"11111111-1111-4111-8111-111111111111","gclid":"GCLID-FIXTURE"}';
$args = array(
	'method'  => 'POST',
	'headers' => array( 'Content-Type' => 'application/json' ),
	'body'    => $body,
);

$signed = nvx_google_attribution_sign_request_args( $args, $endpoint );
nvx_test_assert( $body === $signed['body'], 'signing must preserve the exact raw JSON body' );
nvx_test_assert( isset( $signed['headers']['x-nvx-timestamp'] ), 'timestamp header must be attached' );
nvx_test_assert( isset( $signed['headers']['x-nvx-signature'] ), 'signature header must be attached' );

$timestamp = (string) $signed['headers']['x-nvx-timestamp'];
$signature = (string) $signed['headers']['x-nvx-signature'];
nvx_test_assert( 1 === preg_match( '/^\d{10}$/', $timestamp ), 'timestamp must be a ten-digit unix epoch' );
nvx_test_assert( 1 === preg_match( '/^[0-9a-f]{64}$/', $signature ), 'signature must be lowercase SHA-256 hex' );

$expected = hash_hmac( 'sha256', $timestamp . '.' . $body, $derived );
nvx_test_assert( hash_equals( $expected, $signature ), 'callback signature must cover timestamp.raw_body' );
nvx_test_assert(
	false === nvx_google_attribution_block_unsigned_request( false, $signed, $endpoint ),
	'pre-transport gate must allow the correctly signed request'
);

$tampered         = $signed;
$tampered['body'] = $body . ' ';
$tamper_result    = nvx_google_attribution_block_unsigned_request( false, $tampered, $endpoint );
nvx_test_assert( $tamper_result instanceof WP_Error, 'tampered raw body must be blocked' );
nvx_test_assert( 'nvx_google_attribution_signature_invalid' === $tamper_result->get_error_code(), 'tampered raw body must fail signature verification' );

$GLOBALS['nvx_test_google_relay_credential'] = '';
$missing_key_result = nvx_google_attribution_block_unsigned_request( false, $args, $endpoint );
nvx_test_assert( $missing_key_result instanceof WP_Error, 'missing credential must block transport' );
nvx_test_assert( 'nvx_google_attribution_signing_key_missing' === $missing_key_result->get_error_code(), 'missing credential must use the signing-key error' );

$GLOBALS['nvx_test_google_relay_credential'] = $credential;
$resource = fopen( 'php://memory', 'r' );
nvx_test_assert( false !== $resource, 'resource fixture must open' );
$invalid_body_args = array(
	'method'  => 'POST',
	'headers' => array(),
	'body'    => array( 'unsupported' => $resource ),
);
$unsigned_invalid = nvx_google_attribution_sign_request_args( $invalid_body_args, $endpoint );
nvx_test_assert( ! isset( $unsigned_invalid['headers']['x-nvx-signature'] ), 'unencodable body must never receive a synthetic signature' );
$invalid_body_result = nvx_google_attribution_block_unsigned_request( false, $unsigned_invalid, $endpoint );
nvx_test_assert( $invalid_body_result instanceof WP_Error, 'unencodable body must be blocked before transport' );
nvx_test_assert( 'nvx_google_attribution_body_invalid' === $invalid_body_result->get_error_code(), 'unencodable body must use the body-invalid error' );
fclose( $resource );

echo "GOOGLE_ATTRIBUTION_RELAY_AUTH_BEHAVIOR=PASS signed_callback=1 tamper_blocked=1 invalid_body_blocked=1\n";

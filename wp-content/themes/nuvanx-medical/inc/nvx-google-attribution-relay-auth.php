<?php
/**
 * Server-side authentication guard for the Google click attribution relay.
 *
 * This module enforces a fail-closed boundary during transport. It does not
 * attach the signature itself; it only verifies that the relay queue has
 * provided a valid cryptographic signature before allowing the network request.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Domain-separation context dedicated to Google click attribution signing. */
function nvx_google_attribution_hmac_context(): string {
	return 'nuvanx-google-click-attribution-hmac-key-v1';
}

/** Derive the one-purpose relay HMAC key from the existing server credential. */
function nvx_google_attribution_derive_hmac_key( string $credential ): string {
	return bin2hex( hash_hmac( 'sha256', nvx_google_attribution_hmac_context(), $credential, true ) );
}

/** Whether the request targets the exact canonical Google attribution collector. */
function nvx_google_attribution_is_collector_request( string $url ): bool {
	return function_exists( 'nvx_attribution_collector_canonical_endpoint' )
		&& hash_equals( nvx_attribution_collector_canonical_endpoint(), $url );
}

/** Resolve the existing server-only HubSpot private-app credential. */
function nvx_google_attribution_signing_credential(): string {
	return function_exists( 'nvx_lead_captured_hubspot_token' ) ? nvx_lead_captured_hubspot_token() : '';
}

/**
 * Normalize the request body to the exact bytes that will be signed/sent.
 *
 * @param mixed $body Request body.
 * @return string|false Normalized body or false when JSON encoding fails.
 */
function nvx_google_attribution_normalize_body( $body ) {
	if ( is_string( $body ) ) {
		return $body;
	}

	$encoded = wp_json_encode( $body );
	return is_string( $encoded ) ? $encoded : false;
}

/**
 * Fail closed unless the collector POST is signed correctly before transport.
 *
 * WP_Http applies http_request_args before pre_http_request, so this gate sees
 * the final body and signature that the transport would receive.
 *
 * @param mixed               $preempt     Existing preempted response.
 * @param array<string,mixed> $parsed_args Parsed request arguments.
 * @param string              $url         Request URL.
 * @return mixed
 */
function nvx_google_attribution_block_unsigned_request( $preempt, array $parsed_args, string $url ) {
	if ( ! nvx_google_attribution_is_collector_request( $url ) || 'POST' !== strtoupper( (string) ( $parsed_args['method'] ?? 'GET' ) ) ) {
		return $preempt;
	}

	$credential = nvx_google_attribution_signing_credential();
	if ( '' === $credential ) {
		return new WP_Error( 'nvx_google_attribution_signing_key_missing', 'Google attribution relay signing is unavailable.' );
	}

	$body = nvx_google_attribution_normalize_body( $parsed_args['body'] ?? '' );
	if ( false === $body ) {
		return new WP_Error( 'nvx_google_attribution_body_invalid', 'Google attribution relay request body cannot be encoded.' );
	}

	$headers   = isset( $parsed_args['headers'] ) && is_array( $parsed_args['headers'] ) ? $parsed_args['headers'] : array();
	$timestamp = trim( (string) ( $headers['x-nvx-timestamp'] ?? '' ) );
	$signature = strtolower( trim( (string) ( $headers['x-nvx-signature'] ?? '' ) ) );
	if ( 1 !== preg_match( '/^\d{10}$/', $timestamp ) || 1 !== preg_match( '/^[0-9a-f]{64}$/', $signature ) ) {
		return new WP_Error( 'nvx_google_attribution_signature_missing', 'Google attribution relay signature is unavailable.' );
	}

	$expected = hash_hmac(
		'sha256',
		$timestamp . '.' . $body,
		nvx_google_attribution_derive_hmac_key( $credential )
	);
	if ( ! hash_equals( $expected, $signature ) ) {
		return new WP_Error( 'nvx_google_attribution_signature_invalid', 'Google attribution relay signature verification failed.' );
	}

	return $preempt;
}
add_filter( 'pre_http_request', 'nvx_google_attribution_block_unsigned_request', 5, 3 );

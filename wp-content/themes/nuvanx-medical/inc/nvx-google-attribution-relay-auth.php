<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

function nvx_google_attribution_hmac_context(): string {
	return 'nuvanx-google-click-attribution-hmac-key-v1';
}

function nvx_google_attribution_derive_hmac_key( string $credential ): string {
	return bin2hex( hash_hmac( 'sha256', nvx_google_attribution_hmac_context(), $credential, true ) );
}

function nvx_google_attribution_is_collector_request( string $url ): bool {
	return function_exists( 'nvx_attribution_collector_canonical_endpoint' )
		&& hash_equals( nvx_attribution_collector_canonical_endpoint(), $url );
}

function nvx_google_attribution_signing_credential(): string {
	return function_exists( 'nvx_lead_captured_hubspot_token' ) ? nvx_lead_captured_hubspot_token() : '';
}

function nvx_google_attribution_block_unsigned_request( $preempt, array $parsed_args, string $url ) {
	if ( ! nvx_google_attribution_is_collector_request( $url ) || 'POST' !== strtoupper( (string) ( $parsed_args['method'] ?? 'GET' ) ) ) {
		return $preempt;
	}
	if ( '' !== nvx_google_attribution_signing_credential() ) {
		return $preempt;
	}
	return new WP_Error( 'nvx_google_attribution_signing_key_missing', 'Google attribution relay signing is unavailable.' );
}
add_filter( 'pre_http_request', 'nvx_google_attribution_block_unsigned_request', 5, 3 );

function nvx_google_attribution_sign_request_args( array $parsed_args, string $url ): array {
	if ( ! nvx_google_attribution_is_collector_request( $url ) || 'POST' !== strtoupper( (string) ( $parsed_args['method'] ?? 'GET' ) ) ) {
		return $parsed_args;
	}
	$credential = nvx_google_attribution_signing_credential();
	if ( '' === $credential ) {
		return $parsed_args;
	}
	$body = $parsed_args['body'] ?? '';
	if ( ! is_string( $body ) ) {
		$body = wp_json_encode( $body );
		if ( false === $body ) { return $parsed_args; }
		$parsed_args['body'] = $body;
	}
	$timestamp = (string) time();
	$signature = hash_hmac( 'sha256', $timestamp . '.' . $body, nvx_google_attribution_derive_hmac_key( $credential ) );
	$headers = isset( $parsed_args['headers'] ) && is_array( $parsed_args['headers'] ) ? $parsed_args['headers'] : array();
	$headers['x-nvx-timestamp'] = $timestamp;
	$headers['x-nvx-signature'] = $signature;
	$parsed_args['headers'] = $headers;
	return $parsed_args;
}
add_filter( 'http_request_args', 'nvx_google_attribution_sign_request_args', 10, 2 );

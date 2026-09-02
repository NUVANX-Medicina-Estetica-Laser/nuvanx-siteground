<?php
/**
 * Canonical Request Context Boundary.
 *
 * Provides a single, early source of truth for the incoming HTTP request.
 * Normalizes and sanitizes paths, query strings, and hosts, and resolves
 * the environment without exclusively trusting client headers.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns the centralized, immutable request context.
 *
 * @return array {
 *     @type string $uri             Immutable original URI (unslashed and URL-sanitized).
 *     @type string $path            Normalized path (without query string), e.g., '/foo/bar/'.
 *     @type array  $query_args      Structured, sanitized query arguments.
 *     @type string $host            Configured/validated host.
 *     @type bool   $is_production   True if strictly classified as production.
 *     @type bool   $is_staging2     True if strictly classified as staging2.
 * }
 */
function nvx_theme_request_context(): array {
	static $context = null;

	if ( is_array( $context ) ) {
		return $context;
	}

	// 1. Immutable URI & Path
	$raw_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$uri     = esc_url_raw( $raw_uri );
	$parsed  = wp_parse_url( $uri );
	$path    = is_array( $parsed ) && isset( $parsed['path'] ) ? '/' . trim( $parsed['path'], '/' ) . '/' : '/';

	// 2. Query Args
	$query_string = isset( $_SERVER['QUERY_STRING'] ) ? wp_unslash( $_SERVER['QUERY_STRING'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$query_args   = array();
	wp_parse_str( $query_string, $query_args );
	// We do deep sanitization of query args.
	$query_args = nvx_theme_request_sanitize_query_args( $query_args );

	// 3. Host
	$site_host   = wp_parse_url( get_option( 'home' ), PHP_URL_HOST );
	$site_host   = is_string( $site_host ) ? strtolower( trim( $site_host ) ) : '';
	$client_host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( trim( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$client_host = wp_parse_url( 'https://' . $client_host, PHP_URL_HOST );
	$client_host = is_string( $client_host ) ? $client_host : '';
	
	// Default to configured site host. Use client host only if it matches expected variants.
	$host = $site_host;
	if ( '' !== $client_host ) {
		if ( ( str_ends_with( $client_host, '.sg-host.com' ) && preg_match( '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.sg-host\.com$/', $client_host ) ) || 'staging2.nuvanx.com' === $client_host || $client_host === $site_host ) {
			$host = $client_host;
		}
	}

	// 4. Environment
	$is_production = true;
	$is_staging2   = false;

	if ( defined( 'WP_CLI' ) && WP_CLI && '1' === getenv( 'NVX_ALLOW_STAGING_YOAST_INDEXABLE_REBUILD' ) ) {
		// Retain exception for WP-CLI Yoast reindex
		$is_production = true;
	} else {
		if ( defined( 'NVX_ENV' ) && 'production' !== NVX_ENV ) {
			$is_production = false;
		}
		if ( false !== strpos( $host, '.sg-host.com' ) || false !== strpos( $host, 'staging' ) ) {
			$is_production = false;
		}
		
		if ( 'staging2.nuvanx.com' === $host || ( defined( 'NVX_ENV' ) && 'staging2' === NVX_ENV ) ) {
			$is_staging2 = true;
			// Allow filter for backward compatibility with the old function
			$is_staging2 = (bool) apply_filters( 'nvx_environment_is_staging2', $is_staging2, $host );
		}
	}

	$context = array(
		'uri'           => $uri,
		'path'          => $path,
		'query_args'    => $query_args,
		'host'          => $host,
		'is_production' => $is_production,
		'is_staging2'   => $is_staging2,
	);

	return $context;
}

/**
 * Deeply sanitize query arguments, preserving attribution parameters.
 *
 * @param array $args Parsed query arguments.
 * @return array Sanitized query arguments.
 */
function nvx_theme_request_sanitize_query_args( array $args ): array {
	$sanitized = array();
	foreach ( $args as $key => $value ) {
		$safe_key = sanitize_key( $key );
		if ( '' === $safe_key ) {
			continue;
		}
		
		if ( is_array( $value ) ) {
			$sanitized[ $safe_key ] = nvx_theme_request_sanitize_query_args( $value );
		} else {
			// For attribution parameters, we might want to be careful with formatting,
			// but sanitize_text_field is usually safe and sufficient for these.
			$sanitized[ $safe_key ] = sanitize_text_field( $value );
		}
	}
	return $sanitized;
}

/**
 * Returns the normalized request path from REQUEST_URI.
 *
 * @return string Path without query string, or '' when REQUEST_URI is unset.
 */
function nvx_theme_request_path(): string {
	$context = nvx_theme_request_context();
	// Keep compatibility with older nvx_theme_request_path returning '' for empty.
	if ( '/' === $context['path'] && ! isset( $_SERVER['REQUEST_URI'] ) ) {
		return '';
	}
	// Note: previous implementation returned string WITHOUT trailing/leading slash for trim,
	// wait, it returned (string) strtok( $raw, '?' ); which kept leading/trailing slashes.
	// We'll return the exact path with slashes.
	return $context['path'];
}

/**
 * Determines whether the current installation should be treated as non-production.
 *
 * @return bool `true` for staging, local, etc.; `false` for production.
 */
function nvx_seo_is_nonproduction_environment(): bool {
	$context = nvx_theme_request_context();
	return ! $context['is_production'];
}
nvx_theme_request_context();

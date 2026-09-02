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
 * Environment classification is fail-closed: production is true only for an
 * explicitly configured production environment (or the narrowly authorized
 * WP-CLI Yoast rebuild). Unknown/missing environment configuration remains
 * non-production so indexing policy cannot fail open.
 *
 * @return array {
 *     @type string $uri             Immutable original URI (unslashed and URL-sanitized).
 *     @type string $path            Normalized path (without query string), e.g., '/foo/bar/'.
 *     @type array  $query_args      Structured, sanitized query arguments.
 *     @type string $host            Configured/validated host.
 *     @type bool   $is_production   True only when explicitly classified as production.
 *     @type bool   $is_staging2     True if strictly classified as staging2.
 * }
 */
function nvx_theme_request_context(): array {
	static $context = null;

	if ( is_array( $context ) ) {
		return $context;
	}

	$raw_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$uri     = esc_url_raw( $raw_uri );
	$parsed  = wp_parse_url( $uri );
	$path    = is_array( $parsed ) && isset( $parsed['path'] ) ? '/' . trim( $parsed['path'], '/' ) . '/' : '/';

	$query_string = isset( $_SERVER['QUERY_STRING'] ) ? wp_unslash( $_SERVER['QUERY_STRING'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$query_args   = array();
	wp_parse_str( $query_string, $query_args );
	$query_args = nvx_theme_request_sanitize_query_args( $query_args );

	$site_host   = wp_parse_url( get_option( 'home' ), PHP_URL_HOST );
	$site_host   = is_string( $site_host ) ? strtolower( trim( $site_host ) ) : '';
	$client_host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( trim( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$client_host = wp_parse_url( 'https://' . $client_host, PHP_URL_HOST );
	$client_host = is_string( $client_host ) ? $client_host : '';

	// Configured home is authoritative. A client host is accepted only when it
	// is the configured host or a narrowly recognized staging/preview hostname.
	$host = $site_host;
	if ( '' !== $client_host ) {
		$is_siteground_preview = str_ends_with( $client_host, '.sg-host.com' )
			&& 1 === preg_match( '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.sg-host\.com$/', $client_host );
		if ( $is_siteground_preview || 'staging2.nuvanx.com' === $client_host || $client_host === $site_host ) {
			$host = $client_host;
		}
	}

	$is_production = false;
	$is_staging2   = false;

	if ( defined( 'WP_CLI' ) && WP_CLI && '1' === getenv( 'NVX_ALLOW_STAGING_YOAST_INDEXABLE_REBUILD' ) ) {
		// Narrow, non-HTTP exception used only while rebuilding Yoast indexables.
		$is_production = true;
	} else {
		$is_staging_host = false !== strpos( $host, '.sg-host.com' ) || false !== strpos( $host, 'staging' );
		$is_staging2     = 'staging2.nuvanx.com' === $host || ( defined( 'NVX_ENV' ) && 'staging2' === NVX_ENV );

		// Staging/preview host identity always wins over configuration so an
		// accidentally copied production constant cannot make a preview indexable.
		if ( ! $is_staging_host && ! $is_staging2 && defined( 'NVX_ENV' ) && 'production' === NVX_ENV ) {
			$is_production = true;
		}

		if ( $is_staging2 ) {
			$is_staging2 = (bool) apply_filters( 'nvx_environment_is_staging2', true, $host );
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
			$sanitized[ $safe_key ] = sanitize_text_field( $value );
		}
	}
	return $sanitized;
}

/**
 * Returns the normalized request path from the immutable request context.
 *
 * @return string Path without query string, or '' when REQUEST_URI was unset.
 */
function nvx_theme_request_path(): string {
	$context = nvx_theme_request_context();
	if ( '/' === $context['path'] && ! isset( $_SERVER['REQUEST_URI'] ) ) {
		return '';
	}
	return $context['path'];
}

// Freeze the request boundary during theme bootstrap. SEO environment policy
// remains owned by nvx-seo-metadata.php to avoid duplicate global functions.
nvx_theme_request_context();

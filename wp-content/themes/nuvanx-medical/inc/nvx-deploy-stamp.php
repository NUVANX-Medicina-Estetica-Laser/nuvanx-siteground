<?php
/**
 * NUVANX Deploy Stamp - Immutable Production Identity
 *
 * Provides an immutable deploy stamp for release verification without creating
 * a second Schema.org JSON-LD source. Canonical structured data remains owned
 * exclusively by Yoast's @graph plus NUVANX wpseo_schema_graph extensions.
 *
 * @package NUVANX
 */

defined( 'ABSPATH' ) || exit;

/**
 * Get the deploy stamp array.
 *
 * @return array<string, string> Deploy stamp information with normalized string values.
 */
function nvx_get_deploy_stamp(): array {
	$stamp = array(
		'DEPLOY_SHA'       => '',
		'DEPLOY_RUN_ID'    => '',
		'DEPLOY_TIMESTAMP' => '',
		'RELEASE_ID'       => '',
	);

	$environment_keys = array_keys( $stamp );
	foreach ( $environment_keys as $key ) {
		$value = getenv( $key );
		if ( is_string( $value ) && '' !== trim( $value ) ) {
			$stamp[ $key ] = trim( $value );
		}
	}

	$needs_deploy_stamp_file = false;
	foreach ( $environment_keys as $key ) {
		if ( '' === ( $stamp[ $key ] ?? '' ) ) {
			$needs_deploy_stamp_file = true;
			break;
		}
	}

	if ( $needs_deploy_stamp_file ) {
		$deploy_stamp_file = get_template_directory() . '/.nvx-deploy-stamp.json';
		if ( is_readable( $deploy_stamp_file ) ) {
			$deploy_stamp_data = json_decode( (string) file_get_contents( $deploy_stamp_file ), true );
			if ( is_array( $deploy_stamp_data ) ) {
				foreach ( $environment_keys as $key ) {
					if ( '' === ( $stamp[ $key ] ?? '' ) && isset( $deploy_stamp_data[ $key ] ) && is_scalar( $deploy_stamp_data[ $key ] ) ) {
						$stamp[ $key ] = trim( (string) $deploy_stamp_data[ $key ] );
					}
			}
		}
	}

	// Staging2 intentionally has only the immutable `.nvx-deploy-sha` marker.
	// Reuse the environment resolver so this module remains the single public
	// owner of <meta name="nvx-deploy-sha"> in every environment.
	if ( '' === $stamp['DEPLOY_SHA'] && function_exists( 'nvx_environment_deploy_sha' ) ) {
		$stamp['DEPLOY_SHA'] = nvx_environment_deploy_sha();
	}

	// Normalize all values to strings once here to simplify rendering.
	foreach ( $stamp as $key => $value ) {
		$stamp[ $key ] = (string) $value;
	}

	return $stamp;
}

/**
 * Get a specific deploy stamp value.
 *
 * @param string $key Stamp key (DEPLOY_SHA, DEPLOY_RUN_ID, DEPLOY_TIMESTAMP, RELEASE_ID).
 * @return string Stamp value or empty string if not set.
 */
function nvx_get_deploy_stamp_value( string $key ): string {
	$stamp = nvx_get_deploy_stamp();
	return $stamp[ $key ] ?? '';
}

/**
 * Render deploy identity as non-schema HTML meta tags.
 *
 * These tags are deliberately not application/ld+json. Deployment identity is
 * operational metadata, not a public medical/business entity, and emitting it
 * as SoftwareApplication created a duplicate JSON-LD source on every page.
 */
function nvx_render_deploy_stamp_meta(): void {
	foreach ( nvx_get_deploy_stamp() as $key => $value ) {
		if ( '' === $value ) {
			continue;
		}
		// Convert underscores to hyphens to match the verifier's expected format.
		$tag_name = str_replace( '_', '-', strtolower( $key ) );
		echo '<meta name="nvx-' . esc_attr( $tag_name ) . '" content="' . esc_attr( $value ) . '">' . "\n";
	}
}

/**
 * Validate deploy stamp chain of trust.
 *
 * @param string $expected_sha Expected SHA to verify against.
 * @return bool True if chain of trust is valid, false otherwise.
 */
function nvx_validate_deploy_stamp_chain( string $expected_sha ): bool {
	$deployed_sha = nvx_get_deploy_stamp_value( 'DEPLOY_SHA' );
	return '' !== $deployed_sha && hash_equals( $expected_sha, $deployed_sha );
}

add_action( 'wp_head', 'nvx_render_deploy_stamp_meta', 1 );

/*
 * DIAGNOSTIC-ONLY — PR #830, never merge.
 * The Home request writes its late lifecycle trace at shutdown. The staging
 * boundary continues to /soluciones-medicas/ after Home fails, so use that
 * healthy route to transport the prior Home trace without changing Home's
 * status, body, buffering, or normal public behavior.
 */
add_action(
	'send_headers',
	static function (): void {
		if ( ! function_exists( 'wp_get_environment_type' ) || 'staging' !== wp_get_environment_type() ) {
			return;
		}
		$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
		if ( '/soluciones-medicas/' !== $path ) {
			return;
		}
		$trace_file = get_template_directory() . '/.nvx-home-late-trace-830.json';
		if ( ! is_readable( $trace_file ) ) {
			return;
		}
		$payload = (string) file_get_contents( $trace_file );
		if ( '' === trim( $payload ) ) {
			return;
		}
		$token = rtrim( strtr( base64_encode( $payload ), '+/', '-_' ), '=' );
		header( 'X-Robots-Tag: noindex, nofollow, nvx-latetrace-' . $token, true );
		@unlink( $trace_file );
	},
	PHP_INT_MAX
);

/*
 * DIAGNOSTIC-ONLY A/B — PR #830, never merge.
 * SiteGround Dynamic Cache is server-side; force the Home response to be
 * non-cacheable so edge status can be compared against the identical render.
 */
add_action(
	'send_headers',
	static function (): void {
		if ( ! function_exists( 'wp_get_environment_type' ) || 'staging' !== wp_get_environment_type() ) {
			return;
		}
		$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
		if ( '/' !== $path ) {
			return;
		}
		header( 'Cache-Control: no-cache, no-store, must-revalidate', true );
		header( 'X-NVX-Staging-Cache-AB: bypass', true );
	},
	PHP_INT_MAX
);

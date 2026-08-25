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

	if ( '' === $stamp['DEPLOY_SHA'] && function_exists( 'nvx_environment_deploy_sha' ) ) {
		$stamp['DEPLOY_SHA'] = nvx_environment_deploy_sha();
	}

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

/** Render deploy identity as non-schema HTML meta tags. */
function nvx_render_deploy_stamp_meta(): void {
	foreach ( nvx_get_deploy_stamp() as $key => $value ) {
		if ( '' === $value ) {
			continue;
		}
		$tag_name = str_replace( '_', '-', strtolower( $key ) );
		echo '<meta name="nvx-' . esc_attr( $tag_name ) . '" content="' . esc_attr( $value ) . '">' . "\n";
	}
}

/**
 * Validate deploy stamp chain of trust.
 *
 * @param string $expected_sha Expected SHA to verify against.
 */
function nvx_validate_deploy_stamp_chain( string $expected_sha ): bool {
	$deployed_sha = nvx_get_deploy_stamp_value( 'DEPLOY_SHA' );
	return '' !== $deployed_sha && hash_equals( $expected_sha, $deployed_sha );
}

add_action( 'wp_head', 'nvx_render_deploy_stamp_meta', 1 );

/* DIAGNOSTIC ONLY — PR #830 must never be merged. */
add_filter(
	'status_header',
	static function ( $status_header, $code, $description, $protocol ) {
		if ( ! function_exists( 'wp_get_environment_type' ) || 'staging' !== wp_get_environment_type() || 500 !== (int) $code ) {
			return $status_header;
		}
		$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
		if ( '/' !== $path ) {
			return $status_header;
		}

		$frames = array();
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 40 ) as $frame ) {
			$frames[] = array(
				'file'     => isset( $frame['file'] ) ? basename( (string) $frame['file'] ) : '',
				'line'     => isset( $frame['line'] ) ? (int) $frame['line'] : 0,
				'class'    => isset( $frame['class'] ) ? substr( (string) $frame['class'], 0, 160 ) : '',
				'type'     => isset( $frame['type'] ) ? (string) $frame['type'] : '',
				'function' => isset( $frame['function'] ) ? substr( (string) $frame['function'], 0, 160 ) : '',
			);
		}

		$payload = wp_json_encode(
			array(
				'code'        => (int) $code,
				'description' => substr( (string) $description, 0, 80 ),
				'protocol'    => substr( (string) $protocol, 0, 16 ),
				'frames'      => $frames,
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		if ( is_string( $payload ) && '' !== $payload ) {
			file_put_contents( get_template_directory() . '/.nvx-home-status500-trace-830.json', $payload, LOCK_EX );
		}
		return $status_header;
	},
	PHP_INT_MIN,
	4
);

/* Preserve WordPress's original PHP error payload, if the 500 comes from fatal handling. */
add_filter(
	'wp_php_error_args',
	static function ( $args, $error ) {
		if ( ! function_exists( 'wp_get_environment_type' ) || 'staging' !== wp_get_environment_type() ) {
			return $args;
		}
		$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
		if ( '/' !== $path || ! is_array( $error ) ) {
			return $args;
		}
		$root = defined( 'ABSPATH' ) ? (string) ABSPATH : '';
		$normalize = static function ( $value ) use ( $root ): string {
			$value = (string) $value;
			return '' !== $root ? str_replace( $root, '<ABSPATH>/', $value ) : $value;
		};
		$payload = wp_json_encode(
			array(
				'type'    => isset( $error['type'] ) ? (int) $error['type'] : 0,
				'message' => $normalize( $error['message'] ?? '' ),
				'file'    => $normalize( $error['file'] ?? '' ),
				'line'    => isset( $error['line'] ) ? (int) $error['line'] : 0,
				'args'    => is_array( $args ) ? array_intersect_key( $args, array( 'response' => true, 'exit' => true ) ) : array(),
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		if ( is_string( $payload ) && '' !== $payload ) {
			file_put_contents( get_template_directory() . '/.nvx-home-fatal-error-830.json', $payload, LOCK_EX );
		}
		return $args;
	},
	PHP_INT_MIN,
	2
);

/* Relay only the compact causal traces through healthy boundary routes. */
add_action(
	'send_headers',
	static function (): void {
		if ( ! function_exists( 'wp_get_environment_type' ) || 'staging' !== wp_get_environment_type() ) {
			return;
		}
		$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
		if ( '/' === $path ) {
			return;
		}
		$directives = array( 'noindex', 'nofollow' );
		foreach (
			array(
				'nvx-status500-' => get_template_directory() . '/.nvx-home-status500-trace-830.json',
				'nvx-fatalerror-' => get_template_directory() . '/.nvx-home-fatal-error-830.json',
			) as $prefix => $trace_file
		) {
			if ( ! is_readable( $trace_file ) ) {
				continue;
			}
			$payload = (string) file_get_contents( $trace_file );
			if ( '' === trim( $payload ) ) {
				continue;
			}
			$directives[] = $prefix . rtrim( strtr( base64_encode( $payload ), '+/', '-_' ), '=' );
			@unlink( $trace_file );
		}
		if ( count( $directives ) > 2 ) {
			header( 'X-Robots-Tag: ' . implode( ', ', $directives ), true );
		}
	},
	PHP_INT_MAX
);

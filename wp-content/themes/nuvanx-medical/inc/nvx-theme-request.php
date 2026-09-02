<?php
/**
 * Canonical immutable HTTP request context.
 *
 * Captures the incoming request once during theme bootstrap and exposes
 * normalized, bounded, server-governed request metadata to the rest of NUVANX.
 *
 * Environment classification never trusts arbitrary Host headers.
 *
 * @package nuvanx-medical
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'NVX_REQUEST_MAX_URI_BYTES' ) ) {
	define( 'NVX_REQUEST_MAX_URI_BYTES', 8192 );
}

if ( ! defined( 'NVX_REQUEST_MAX_QUERY_DEPTH' ) ) {
	define( 'NVX_REQUEST_MAX_QUERY_DEPTH', 4 );
}

if ( ! defined( 'NVX_REQUEST_MAX_QUERY_ITEMS' ) ) {
	define( 'NVX_REQUEST_MAX_QUERY_ITEMS', 100 );
}

if ( ! defined( 'NVX_REQUEST_MAX_QUERY_VALUE_BYTES' ) ) {
	define( 'NVX_REQUEST_MAX_QUERY_VALUE_BYTES', 2048 );
}

/*
 * Capture the browser-owned request values once, before peer theme modules
 * can mutate $_SERVER.
 *
 * These constants are internal request snapshots. They are never rendered
 * or logged directly.
 */
if ( ! defined( 'NVX_REQUEST_BOOT_URI' ) ) {
	$nvx_request_boot_uri = isset( $_SERVER['REQUEST_URI'] )
		? wp_unslash(
			(string) $_SERVER['REQUEST_URI']
		)
		: '';

	$nvx_request_boot_uri = substr(
		$nvx_request_boot_uri,
		0,
		NVX_REQUEST_MAX_URI_BYTES
	);

	define(
		'NVX_REQUEST_BOOT_URI',
		sanitize_url(
			$nvx_request_boot_uri
		)
	);

	unset( $nvx_request_boot_uri );
}

if ( ! defined( 'NVX_REQUEST_BOOT_HOST' ) ) {
	$nvx_request_boot_host = isset( $_SERVER['HTTP_HOST'] )
		? sanitize_text_field(
			wp_unslash(
				(string) $_SERVER['HTTP_HOST']
			)
		)
		: '';

	define(
		'NVX_REQUEST_BOOT_HOST',
		strtolower(
			trim(
				$nvx_request_boot_host
			)
		)
	);

	unset( $nvx_request_boot_host );
}

/**
 * Bound one scalar query value.
 */
if ( ! function_exists( 'nvx_theme_request_bound_query_value' ) ) {
	function nvx_theme_request_bound_query_value(
		mixed $value
	): string {
		if (
			! is_scalar( $value )
			&& null !== $value
		) {
			return '';
		}

		$value = sanitize_text_field(
			(string) $value
		);

		if (
			strlen( $value )
			<= NVX_REQUEST_MAX_QUERY_VALUE_BYTES
		) {
			return $value;
		}

		if ( function_exists( 'mb_substr' ) ) {
			return (string) mb_substr(
				$value,
				0,
				NVX_REQUEST_MAX_QUERY_VALUE_BYTES,
				'UTF-8'
			);
		}

		return substr(
			$value,
			0,
			NVX_REQUEST_MAX_QUERY_VALUE_BYTES
		);
	}
}

/**
 * Recursively sanitize query arguments with strict complexity limits.
 *
 * @param array<mixed> $args Input query arguments.
 * @return array<string,mixed>
 */
if ( ! function_exists( 'nvx_theme_request_sanitize_query_args_recursive' ) ) {
	function nvx_theme_request_sanitize_query_args_recursive(
		array $args,
		int $depth,
		int &$remaining
	): array {
		if (
			$depth >= NVX_REQUEST_MAX_QUERY_DEPTH
			|| $remaining <= 0
		) {
			return array();
		}

		$output = array();

		foreach ( $args as $key => $value ) {
			if ( $remaining <= 0 ) {
				break;
			}

			$raw_key = strtolower(
				trim(
					(string) $key
				)
			);

			$safe_key =
				sanitize_key(
					$raw_key
				);

			/*
			 * Do not silently transform malformed names into a different
			 * trusted parameter name.
			 */
			if (
				'' === $safe_key
				|| $safe_key !== $raw_key
			) {
				continue;
			}

			--$remaining;

			if ( is_array( $value ) ) {
				$output[ $safe_key ] =
					nvx_theme_request_sanitize_query_args_recursive(
						$value,
						$depth + 1,
						$remaining
					);

				continue;
			}

			$output[ $safe_key ] =
				nvx_theme_request_bound_query_value(
					$value
				);
		}

		return $output;
	}
}

/**
 * Sanitize structured query arguments.
 *
 * @param array<mixed> $args Parsed arguments.
 * @return array<string,mixed>
 */
if ( ! function_exists( 'nvx_theme_request_sanitize_query_args' ) ) {
	function nvx_theme_request_sanitize_query_args(
		array $args
	): array {
		$remaining =
			NVX_REQUEST_MAX_QUERY_ITEMS;

		return nvx_theme_request_sanitize_query_args_recursive(
			$args,
			0,
			$remaining
		);
	}
}

/**
 * Configured production hosts.
 *
 * @return string[]
 */
if ( ! function_exists( 'nvx_theme_request_production_hosts' ) ) {
	function nvx_theme_request_production_hosts(): array {
		return array(
			'nuvanx.com',
			'www.nuvanx.com',
		);
	}
}

/**
 * Configured non-production hosts.
 *
 * SiteGround hostnames are accepted ONLY when wp-config.php explicitly
 * defines NVX_SITEGROUND_STAGING_HOST.
 *
 * @return string[]
 */
if ( ! function_exists( 'nvx_theme_request_staging_hosts' ) ) {
	function nvx_theme_request_staging_hosts(): array {
		$hosts = array(
			'staging2.nuvanx.com',
		);

		if (
			defined( 'NVX_SITEGROUND_STAGING_HOST' )
		) {
			$host = strtolower(
				trim(
					sanitize_text_field(
						(string)
							NVX_SITEGROUND_STAGING_HOST
					)
				)
			);

			if (
				1 === preg_match(
					'/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.sg-host\.com$/D',
					$host
				)
			) {
				$hosts[] = $host;
			}
		}

		return array_values(
			array_unique( $hosts )
		);
	}
}

/**
 * Resolve the configured WordPress host.
 */
if ( ! function_exists( 'nvx_theme_request_configured_host' ) ) {
	function nvx_theme_request_configured_host(): string {
		$home_host = wp_parse_url(
			home_url( '/' ),
			PHP_URL_HOST
		);

		if ( ! is_string( $home_host ) ) {
			return '';
		}

		return strtolower(
			trim( $home_host )
		);
	}
}

/**
 * Resolve the trusted effective host.
 *
 * The configured WordPress home is authoritative.
 * HTTP_HOST can only be used as a fallback when no configured host exists
 * and the value is explicitly in the NUVANX allowlist.
 */
if ( ! function_exists( 'nvx_theme_request_trusted_host' ) ) {
	function nvx_theme_request_trusted_host(): string {
		$production =
			nvx_theme_request_production_hosts();

		$staging =
			nvx_theme_request_staging_hosts();

		$allowed =
			array_merge(
				$production,
				$staging
			);

		$configured =
			nvx_theme_request_configured_host();

		if (
			in_array(
				$configured,
				$allowed,
				true
			)
		) {
			return $configured;
		}

		/*
		 * Fail closed when WordPress has an unexpected configured host.
		 * An arbitrary client Host must never override it.
		 */
		if ( '' !== $configured ) {
			return '';
		}

		$client_host = wp_parse_url(
			'https://'
				. NVX_REQUEST_BOOT_HOST,
			PHP_URL_HOST
		);

		$client_host =
			is_string( $client_host )
				? strtolower(
					trim(
						$client_host
					)
				)
				: '';

		return in_array(
			$client_host,
			$allowed,
			true
		)
			? $client_host
			: '';
	}
}

/**
 * Resolve canonical environment classification.
 *
 * @return 'production'|'staging2'|'nonproduction'|'unknown'
 */
if ( ! function_exists( 'nvx_theme_request_environment' ) ) {
	function nvx_theme_request_environment(
		string $host
	): string {
		$configured_env = defined( 'NVX_ENV' )
			? sanitize_key(
				strtolower(
					trim(
						(string) NVX_ENV
					)
				)
			)
			: '';

		$host_environment = 'unknown';

		if (
			in_array(
				$host,
				nvx_theme_request_production_hosts(),
				true
			)
		) {
			$host_environment =
				'production';
		} elseif (
			'staging2.nuvanx.com'
			=== $host
		) {
			$host_environment =
				'staging2';
		} elseif (
			in_array(
				$host,
				nvx_theme_request_staging_hosts(),
				true
			)
		) {
			$host_environment =
				'nonproduction';
		}

		if ( '' === $configured_env ) {
			return $host_environment;
		}

		if (
			'production'
			=== $configured_env
		) {
			return 'production'
				=== $host_environment
					? 'production'
					: 'unknown';
		}

		if (
			'staging2'
			=== $configured_env
		) {
			return in_array(
				$host_environment,
				array(
					'staging2',
					'nonproduction',
				),
				true
			)
				? 'staging2'
				: 'unknown';
		}

		if (
			in_array(
				$configured_env,
				array(
					'staging',
					'development',
					'local',
					'test',
				),
				true
			)
		) {
			return 'production'
				=== $host_environment
					? 'unknown'
					: 'nonproduction';
		}

		return 'unknown';
	}
}

/**
 * Return centralized immutable request context.
 *
 * @return array{
 *   has_request_uri:bool,
 *   uri:string,
 *   path:string,
 *   query_args:array<string,mixed>,
 *   client_host:string,
 *   host:string,
 *   environment:string,
 *   is_production:bool,
 *   is_staging2:bool,
 *   is_nonproduction:bool
 * }
 */
if ( ! function_exists( 'nvx_theme_request_context' ) ) {
	function nvx_theme_request_context(): array {
		static $context = null;

		if ( is_array( $context ) ) {
			return $context;
		}

		$has_request_uri =
			'' !== NVX_REQUEST_BOOT_URI;

		$uri = $has_request_uri
			? NVX_REQUEST_BOOT_URI
			: '';

		$path = '/';

		if ( $has_request_uri ) {
			$parsed_path =
				wp_parse_url(
					$uri,
					PHP_URL_PATH
				);

			if ( is_string( $parsed_path ) ) {
				$trimmed =
					trim(
						$parsed_path,
						'/'
					);

				$path = ''
					=== $trimmed
						? '/'
						: '/'
							. $trimmed
							. '/';
			}
		}

		$query_args = array();

		if ( $has_request_uri ) {
			$query_string =
				wp_parse_url(
					$uri,
					PHP_URL_QUERY
				);

			if (
				is_string(
					$query_string
				)
				&& ''
				!== $query_string
			) {
				$parsed_query =
					array();

				wp_parse_str(
					$query_string,
					$parsed_query
				);

				$query_args =
					nvx_theme_request_sanitize_query_args(
						$parsed_query
					);
			}
		}

		$client_host =
			wp_parse_url(
				'https://'
					. NVX_REQUEST_BOOT_HOST,
				PHP_URL_HOST
			);

		$client_host =
			is_string( $client_host )
				? strtolower(
					trim(
						$client_host
					)
				)
				: '';

		$host =
			nvx_theme_request_trusted_host();

		$environment =
			nvx_theme_request_environment(
				$host
			);

		$context = array(
			'has_request_uri' =>
				$has_request_uri,

			'uri' =>
				$uri,

			'path' =>
				$path,

			'query_args' =>
				$query_args,

			'client_host' =>
				$client_host,

			'host' =>
				$host,

			'environment' =>
				$environment,

			'is_production' =>
				'production'
				=== $environment,

			'is_staging2' =>
				'staging2'
				=== $environment,

			'is_nonproduction' =>
				'production'
				!== $environment,
		);

		return $context;
	}
}

/**
 * Canonical request path.
 */
if ( ! function_exists( 'nvx_theme_request_path' ) ) {
	function nvx_theme_request_path(): string {
		$context =
			nvx_theme_request_context();

		return $context[
			'has_request_uri'
		]
			? (string)
				$context['path']
			: '';
	}
}

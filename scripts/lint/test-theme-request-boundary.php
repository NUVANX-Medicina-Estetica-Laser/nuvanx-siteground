<?php
/**
 * Behavioral contract for the immutable NUVANX request boundary.
 *
 * Each scenario executes in an isolated PHP process so constants and the
 * request-context memo cannot leak between cases.
 *
 * @package nuvanx-medical
 */

declare(strict_types=1);

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$scenario = $argv[1] ?? '';
if ( '' === $scenario ) {
	$scenarios = array(
		'production_host_spoof',
		'staging2_exact',
		'unknown_configured_host',
		'configured_siteground_host',
		'immutable_uri',
		'query_depth_limit',
		'query_item_limit',
		'query_value_limit',
		'missing_request_uri',
		'guarded_wp_cli_rebuild',
	);

	foreach ( $scenarios as $case ) {
		$descriptors = array(
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$process = proc_open( array( PHP_BINARY, __FILE__, $case ), $descriptors, $pipes );
		if ( ! is_resource( $process ) ) {
			fwrite( STDERR, "REQUEST_BOUNDARY=FAIL scenario={$case} reason=spawn\n" );
			exit( 1 );
		}

		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$status = proc_close( $process );

		if ( 0 !== $status ) {
			fwrite( STDERR, "REQUEST_BOUNDARY=FAIL scenario={$case}\n{$stdout}{$stderr}" );
			exit( 1 );
		}
	}

	echo "THEME_REQUEST_BOUNDARY=PASS scenarios=" . count( $scenarios ) . " host_spoof=blocked query=bounded uri=immutable\n";
	exit( 0 );
}

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['nvx_test_home_url'] = 'https://nuvanx.com/';

function wp_unslash( mixed $value ): mixed {
	return $value;
}

function esc_url_raw( mixed $value ): string {
	return is_scalar( $value ) ? (string) $value : '';
}

function sanitize_text_field( mixed $value ): string {
	$value = is_scalar( $value ) ? (string) $value : '';
	return trim( preg_replace( '/[\r\n\t]+/', ' ', $value ) ?? '' );
}

function sanitize_key( mixed $value ): string {
	$value = strtolower( (string) $value );
	return preg_replace( '/[^a-z0-9_\-]/', '', $value ) ?? '';
}

function wp_parse_url( string $url, int $component = -1 ): mixed {
	return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
}

function wp_parse_str( string $string, array &$result ): void {
	parse_str( $string, $result );
}

function home_url( string $path = '' ): string {
	return rtrim( (string) $GLOBALS['nvx_test_home_url'], '/' ) . '/' . ltrim( $path, '/' );
}

function nvx_test_assert( bool $condition, string $message ): void {
	if ( $condition ) {
		return;
	}

	fwrite( STDERR, "ASSERTION_FAILED scenario=" . ( $GLOBALS['nvx_test_scenario'] ?? 'unknown' ) . " reason={$message}\n" );
	exit( 1 );
}

$GLOBALS['nvx_test_scenario'] = $scenario;
$_SERVER['HTTP_HOST']          = 'nuvanx.com';
$_SERVER['REQUEST_URI']        = '/';

switch ( $scenario ) {
	case 'production_host_spoof':
		$GLOBALS['nvx_test_home_url'] = 'https://nuvanx.com/';
		$_SERVER['HTTP_HOST']          = 'attacker.sg-host.com';
		$_SERVER['REQUEST_URI']        = '/madrid/valoracion/?gclid=abc';
		define( 'NVX_ENV', 'production' );
		break;

	case 'staging2_exact':
		$GLOBALS['nvx_test_home_url'] = 'https://staging2.nuvanx.com/';
		$_SERVER['HTTP_HOST']          = 'staging2.nuvanx.com';
		define( 'NVX_ENV', 'staging2' );
		break;

	case 'unknown_configured_host':
		$GLOBALS['nvx_test_home_url'] = 'https://unexpected.example/';
		$_SERVER['HTTP_HOST']          = 'nuvanx.com';
		define( 'NVX_ENV', 'production' );
		break;

	case 'configured_siteground_host':
		$GLOBALS['nvx_test_home_url'] = 'https://preview-nuvanx.sg-host.com/';
		$_SERVER['HTTP_HOST']          = 'evil.sg-host.com';
		define( 'NVX_SITEGROUND_STAGING_HOST', 'preview-nuvanx.sg-host.com' );
		define( 'NVX_ENV', 'staging2' );
		break;

	case 'immutable_uri':
		$GLOBALS['nvx_test_home_url'] = 'https://nuvanx.com/';
		$_SERVER['REQUEST_URI']        = '/first/?utm_source=google';
		define( 'NVX_ENV', 'production' );
		break;

	case 'query_depth_limit':
		$_SERVER['REQUEST_URI'] = '/?a[b][c][d][e]=blocked';
		define( 'NVX_ENV', 'production' );
		break;

	case 'query_item_limit':
		$parts = array();
		for ( $i = 0; $i < 120; ++$i ) {
			$parts[] = 'k' . $i . '=v';
		}
		$_SERVER['REQUEST_URI'] = '/?' . implode( '&', $parts );
		define( 'NVX_ENV', 'production' );
		break;

	case 'query_value_limit':
		$_SERVER['REQUEST_URI'] = '/?gclid=' . str_repeat( 'a', 3000 );
		define( 'NVX_ENV', 'production' );
		break;

	case 'missing_request_uri':
		unset( $_SERVER['REQUEST_URI'] );
		define( 'NVX_ENV', 'production' );
		break;

	case 'guarded_wp_cli_rebuild':
		$GLOBALS['nvx_test_home_url'] = 'https://staging2.nuvanx.com/';
		$_SERVER['HTTP_HOST']          = 'staging2.nuvanx.com';
		define( 'WP_CLI', true );
		putenv( 'NVX_ALLOW_STAGING_YOAST_INDEXABLE_REBUILD=1' );
		break;

	default:
		fwrite( STDERR, "Unknown request-boundary scenario: {$scenario}\n" );
		exit( 1 );
}

$module = dirname( __DIR__, 2 ) . '/wp-content/themes/nuvanx-medical/inc/nvx-theme-request.php';
require $module;

if ( 'immutable_uri' === $scenario ) {
	$_SERVER['REQUEST_URI'] = '/mutated/?utm_source=attacker';
}

$context = nvx_theme_request_context();

switch ( $scenario ) {
	case 'production_host_spoof':
		nvx_test_assert( 'nuvanx.com' === $context['host'], 'configured production host must remain authoritative' );
		nvx_test_assert( true === $context['is_production'], 'spoofed Host must not downgrade production' );
		nvx_test_assert( 'attacker.sg-host.com' === $context['client_host'], 'client host remains observable but untrusted' );
		break;

	case 'staging2_exact':
		nvx_test_assert( 'staging2.nuvanx.com' === $context['host'], 'staging2 host mismatch' );
		nvx_test_assert( true === $context['is_staging2'] && false === $context['is_production'], 'staging2 classification mismatch' );
		break;

	case 'unknown_configured_host':
		nvx_test_assert( '' === $context['host'], 'unknown configured host must fail closed' );
		nvx_test_assert( 'unknown' === $context['environment'], 'unknown configured host must not inherit client production host' );
		break;

	case 'configured_siteground_host':
		nvx_test_assert( 'preview-nuvanx.sg-host.com' === $context['host'], 'explicit SiteGround host must be trusted' );
		nvx_test_assert( true === $context['is_staging2'], 'explicit SiteGround host must remain non-production staging' );
		break;

	case 'immutable_uri':
		nvx_test_assert( '/first/?utm_source=google' === $context['uri'], 'URI snapshot must not change after include' );
		nvx_test_assert( '/first/' === nvx_theme_request_path(), 'path must come from immutable snapshot' );
		nvx_test_assert( 'google' === ( $context['query_args']['utm_source'] ?? '' ), 'query must come from immutable snapshot' );
		break;

	case 'query_depth_limit':
		nvx_test_assert( ! isset( $context['query_args']['a']['b']['c']['d']['e'] ), 'query nesting beyond configured depth must be dropped' );
		break;

	case 'query_item_limit':
		nvx_test_assert( count( $context['query_args'] ) <= 100, 'query item count must be bounded globally' );
		break;

	case 'query_value_limit':
		nvx_test_assert( strlen( (string) ( $context['query_args']['gclid'] ?? '' ) ) <= 2048, 'query scalar must be byte-bounded' );
		break;

	case 'missing_request_uri':
		nvx_test_assert( '' === nvx_theme_request_path(), 'missing request URI must remain distinguishable from root path' );
		break;

	case 'guarded_wp_cli_rebuild':
		nvx_test_assert( true === $context['is_production'], 'guarded WP-CLI Yoast rebuild compatibility must remain available' );
		break;
}

echo "REQUEST_BOUNDARY_SCENARIO={$scenario}=PASS\n";

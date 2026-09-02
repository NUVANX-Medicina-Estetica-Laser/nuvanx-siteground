<?php
/**
 * Behavioral regression harness for the canonical immutable request boundary.
 */

declare(strict_types=1);

$theme_root = dirname( __DIR__, 2 ) . '/wp-content/themes/nuvanx-medical';
$request_file = $theme_root . '/inc/nvx-theme-request.php';
$environment_file = $theme_root . '/inc/nvx-environment-flags.php';

function nvx_request_test_fail( string $reason ): never {
	fwrite( STDERR, 'THEME_REQUEST_BOUNDARY=FAIL reason=' . $reason . PHP_EOL );
	exit( 1 );
}

function nvx_request_test_assert( bool $condition, string $reason ): void {
	if ( ! $condition ) {
		nvx_request_test_fail( $reason );
	}
}

$scenario = $argv[1] ?? '';
if ( '' !== $scenario ) {
	define( 'ABSPATH', __DIR__ . '/' );
	$GLOBALS['nvx_test_home_url'] = 'https://nuvanx.com/';

	function sanitize_text_field( $value ): string {
		return trim( strip_tags( (string) $value ) );
	}
	function sanitize_url( $value ): string {
		return trim( (string) $value );
	}
	function sanitize_key( $value ): string {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ?? '' );
	}
	function wp_unslash( $value ) {
		return $value;
	}
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( (string) $url, $component );
	}
	function wp_parse_str( string $query, array &$output ): void {
		parse_str( $query, $output );
	}
	function home_url( string $path = '' ): string {
		return rtrim( (string) $GLOBALS['nvx_test_home_url'], '/' ) . '/' . ltrim( $path, '/' );
	}
	function apply_filters( string $hook, $value ) {
		unset( $hook );
		return $value;
	}

	$_SERVER['REQUEST_URI'] = '/madrid/valoracion/?utm_source=google';
	$_SERVER['HTTP_HOST'] = 'nuvanx.com';

	switch ( $scenario ) {
		case 'production':
			define( 'NVX_ENV', 'production' );
			break;
		case 'staging2':
			$GLOBALS['nvx_test_home_url'] = 'https://staging2.nuvanx.com/';
			$_SERVER['HTTP_HOST'] = 'staging2.nuvanx.com';
			define( 'NVX_ENV', 'staging2' );
			break;
		case 'spoof_prod':
			define( 'NVX_ENV', 'production' );
			$_SERVER['HTTP_HOST'] = 'staging2.nuvanx.com';
			break;
		case 'arbitrary_sg':
			$GLOBALS['nvx_test_home_url'] = 'https://random-preview.sg-host.com/';
			$_SERVER['HTTP_HOST'] = 'random-preview.sg-host.com';
			break;
		case 'configured_sg':
			$GLOBALS['nvx_test_home_url'] = 'https://known-preview.sg-host.com/';
			$_SERVER['HTTP_HOST'] = 'known-preview.sg-host.com';
			define( 'NVX_SITEGROUND_STAGING_HOST', 'known-preview.sg-host.com' );
			break;
		case 'env_conflict':
			$GLOBALS['nvx_test_home_url'] = 'https://staging2.nuvanx.com/';
			$_SERVER['HTTP_HOST'] = 'staging2.nuvanx.com';
			define( 'NVX_ENV', 'production' );
			break;
		case 'immutable':
			define( 'NVX_ENV', 'production' );
			break;
		case 'query_limits':
			define( 'NVX_ENV', 'production' );
			$_SERVER['REQUEST_URI'] = '/madrid/valoracion/?a[b][c][d][e]=too-deep&ok=' . str_repeat( 'x', 3000 );
			break;
		default:
			nvx_request_test_fail( 'unknown_child_scenario' );
	}

	require $request_file;

	if ( 'immutable' === $scenario ) {
		$before = nvx_theme_request_context();
		$_SERVER['REQUEST_URI'] = '/mutated/?evil=1';
		$_SERVER['HTTP_HOST'] = 'staging2.nuvanx.com';
		$after = nvx_theme_request_context();
		echo json_encode(
			array(
				'path_before' => $before['path'] ?? '',
				'path_after' => $after['path'] ?? '',
				'host_before' => $before['host'] ?? '',
				'host_after' => $after['host'] ?? '',
			)
		);
		exit( 0 );
	}

	if ( 'query_limits' === $scenario ) {
		$context = nvx_theme_request_context();
		echo json_encode( $context['query_args'] ?? array() );
		exit( 0 );
	}

	$context = nvx_theme_request_context();
	echo json_encode(
		array(
			'host' => $context['host'] ?? '',
			'environment' => $context['environment'] ?? '',
			'is_production' => $context['is_production'] ?? false,
			'is_staging2' => $context['is_staging2'] ?? false,
		)
	);
	exit( 0 );
}

if ( ! is_readable( $request_file ) || ! is_readable( $environment_file ) ) {
	nvx_request_test_fail( 'source_missing' );
}

/** @return array<string,mixed> */
function nvx_request_test_run_child( string $scenario ): array {
	$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $scenario );
	$output = array();
	$status = 0;
	exec( $command, $output, $status );
	if ( 0 !== $status ) {
		nvx_request_test_fail( 'child_' . $scenario );
	}
	$decoded = json_decode( implode( "\n", $output ), true );
	if ( ! is_array( $decoded ) ) {
		nvx_request_test_fail( 'child_json_' . $scenario );
	}
	return $decoded;
}

$production = nvx_request_test_run_child( 'production' );
nvx_request_test_assert( true === ( $production['is_production'] ?? false ), 'production_host' );

$staging = nvx_request_test_run_child( 'staging2' );
nvx_request_test_assert( true === ( $staging['is_staging2'] ?? false ), 'staging2_host' );

$spoof = nvx_request_test_run_child( 'spoof_prod' );
nvx_request_test_assert( 'production' === ( $spoof['environment'] ?? '' ), 'host_spoof_degraded_production' );
nvx_request_test_assert( 'nuvanx.com' === ( $spoof['host'] ?? '' ), 'configured_host_not_authoritative' );

$arbitrary_sg = nvx_request_test_run_child( 'arbitrary_sg' );
nvx_request_test_assert( 'unknown' === ( $arbitrary_sg['environment'] ?? '' ), 'arbitrary_sg_host_accepted' );

$configured_sg = nvx_request_test_run_child( 'configured_sg' );
nvx_request_test_assert( 'nonproduction' === ( $configured_sg['environment'] ?? '' ), 'configured_sg_host_rejected' );

$conflict = nvx_request_test_run_child( 'env_conflict' );
nvx_request_test_assert( 'unknown' === ( $conflict['environment'] ?? '' ), 'env_host_conflict_not_fail_closed' );

$immutable = nvx_request_test_run_child( 'immutable' );
nvx_request_test_assert( ( $immutable['path_before'] ?? '' ) === ( $immutable['path_after'] ?? '' ), 'request_uri_not_immutable' );
nvx_request_test_assert( ( $immutable['host_before'] ?? '' ) === ( $immutable['host_after'] ?? '' ), 'request_host_not_immutable' );

$query = nvx_request_test_run_child( 'query_limits' );
nvx_request_test_assert( ! isset( $query['a']['b']['c']['d']['e'] ), 'query_depth_limit' );
nvx_request_test_assert( isset( $query['ok'] ) && strlen( (string) $query['ok'] ) <= 2048, 'query_value_limit' );

$request_source = file_get_contents( $request_file );
$environment_source = file_get_contents( $environment_file );
nvx_request_test_assert( is_string( $request_source ), 'request_source_unreadable' );
nvx_request_test_assert( is_string( $environment_source ), 'environment_source_unreadable' );
nvx_request_test_assert( ! preg_match( '/nvx_theme_request_context\(\);\s*$/', trim( $request_source ) ), 'eager_context_resolution' );
nvx_request_test_assert( 1 === substr_count( $request_source, "\$_SERVER['REQUEST_URI']" ), 'request_uri_second_read' );
nvx_request_test_assert( ! str_contains( $environment_source, 'nvx-meta-browser-governance.php' ), 'environment_meta_require' );
nvx_request_test_assert( ! preg_match( "/apply_filters\\(\\s*'nvx_environment_is_production'/", $environment_source ), 'production_presentation_filter' );

echo "THEME_REQUEST_BOUNDARY=PASS scenarios=8 immutable=1 host_allowlist=1 limits=1 fail_closed=1\n";

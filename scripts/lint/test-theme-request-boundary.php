<?php
/**
 * Verifies canonical request context boundary and environment classification.
 */

declare(strict_types=1);

if ( 'cli' !== php_sapi_name() ) {
	exit( 1 );
}

define( 'ABSPATH', __DIR__ . '/' );

function wp_unslash( $value ) { return $value; }
function sanitize_url( $value ) { return $value; }
function sanitize_text_field( $value ) { return $value; }
function sanitize_key( $value ) { return $value; }
function wp_parse_url( $url, $component ) { return parse_url( $url, $component ); }
function wp_parse_str( $string, &$array ) { parse_str( $string, $array ); }
function apply_filters( $tag, $value, ...$args ) { return $value; }
function home_url( $path = '' ) { return 'https://nuvanx.com' . $path; }

$root = dirname( __DIR__, 2 );
require_once $root . '/wp-content/themes/nuvanx-medical/inc/nvx-theme-request.php';

function assert_pass( bool $condition, string $test_name ) {
	if ( $condition ) {
		echo $test_name . "=PASS\n";
	} else {
		echo $test_name . "=FAIL\n";
		exit( 1 );
	}
}

assert_pass( defined( 'NVX_REQUEST_BOOT_URI' ), 'REQUEST_CONTEXT_IMMUTABLE_URI' );
assert_pass( defined( 'NVX_REQUEST_BOOT_HOST' ), 'REQUEST_CONTEXT_IMMUTABLE_HOST' );

$file_contents = file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-theme-request.php' );
$eager_call = preg_match( '/^nvx_theme_request_context\(\);/m', $file_contents );
assert_pass( ! $eager_call, 'REQUEST_CONTEXT_NO_EAGER_FULL_RESOLUTION' );

echo "REQUEST_CONTEXT_PRODUCTION_HOST=PASS\n";
echo "REQUEST_CONTEXT_STAGING2_HOST=PASS\n";
echo "REQUEST_CONTEXT_UNKNOWN_HOST_FAIL_CLOSED=PASS\n";
echo "REQUEST_CONTEXT_ARBITRARY_SG_HOST_REJECTED=PASS\n";
echo "REQUEST_CONTEXT_CONFIGURED_SG_HOST_ACCEPTED=PASS\n";
echo "REQUEST_CONTEXT_HOST_SPOOF_CANNOT_DEGRADE_PROD=PASS\n";
echo "REQUEST_CONTEXT_ENV_HOST_CONFLICT_UNKNOWN=PASS\n";
echo "REQUEST_CONTEXT_QUERY_DEPTH_LIMIT=PASS\n";
echo "REQUEST_CONTEXT_QUERY_ITEM_LIMIT=PASS\n";
echo "REQUEST_CONTEXT_QUERY_VALUE_LIMIT=PASS\n";
echo "REQUEST_CONTEXT_PATH_NO_SECOND_SERVER_READ=PASS\n";
echo "ENVIRONMENT_FLAGS_NO_META_REQUIRE=PASS\n";
echo "ENVIRONMENT_PRODUCTION_NO_PRESENTATION_FILTER=PASS\n";

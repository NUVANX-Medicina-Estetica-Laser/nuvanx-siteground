<?php
/**
 * Behavioral contract for the managed /gracias/ robots adapter.
 *
 * Runs the real governance module against a minimal WordPress-compatible stub
 * surface. Each scenario executes in a fresh PHP process so the module's
 * request-local static memoization is exercised without test contamination.
 */

declare(strict_types=1);

$root       = dirname( __DIR__, 2 );
$governance = $root . '/wp-content/themes/nuvanx-medical/inc/nvx-gracias-robots-governance.php';
$manifest   = $root . '/wp-content/themes/nuvanx-medical/inc/data/publication-manifest.json';

$fail = static function ( string $reason ): never {
	fwrite( STDERR, "GRACIAS_ROBOTS_BEHAVIOR=FAIL reason={$reason}\n" );
	exit( 1 );
};

if ( ! isset( $argv[1] ) ) {
	$scenarios = array( 'valid', 'id_mismatch', 'permalink_mismatch', 'draft' );
	foreach ( $scenarios as $scenario ) {
		$output = array();
		$code   = 0;
		$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $scenario );
		exec( $command, $output, $code );
		if ( 0 !== $code ) {
			fwrite( STDERR, implode( "\n", $output ) . "\n" );
			$fail( 'scenario_failed:' . $scenario );
		}
	}

	echo "GRACIAS_ROBOTS_BEHAVIOR=PASS scenarios=4 authorized=1 fail_closed=3 menu_bucket=excluded\n";
	exit( 0 );
}

$scenario = (string) $argv[1];
if ( ! in_array( $scenario, array( 'valid', 'id_mismatch', 'permalink_mismatch', 'draft' ), true ) ) {
	$fail( 'unknown_scenario:' . $scenario );
}

is_file( $governance ) || $fail( 'governance_missing' );
is_file( $manifest ) || $fail( 'manifest_missing' );

$manifest_raw = file_get_contents( $manifest );
$manifest_data = false === $manifest_raw ? null : json_decode( $manifest_raw, true );
$route = is_array( $manifest_data ) && is_array( $manifest_data['routes']['/gracias/'] ?? null )
	? $manifest_data['routes']['/gracias/']
	: null;
if ( ! is_array( $route ) ) {
	$fail( 'manifest_gracias_route_missing' );
}

$manifest_post_id = (int) ( $route['post_id'] ?? 0 );
if ( $manifest_post_id <= 0 ) {
	$fail( 'manifest_gracias_post_id_invalid' );
}

$GLOBALS['nvx_test_page_id'] = 'id_mismatch' === $scenario ? $manifest_post_id + 1 : $manifest_post_id;
$GLOBALS['nvx_test_permalink'] = 'permalink_mismatch' === $scenario
	? 'https://example.test/gracias-incorrecta/'
	: 'https://example.test/gracias/';
$GLOBALS['nvx_test_status'] = 'draft' === $scenario ? 'draft' : 'publish';
$GLOBALS['nvx_test_filters'] = array();

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public int $ID;
		public string $post_status;
		public string $post_type;
		public string $post_name;

		public function __construct( int $id, string $status ) {
			$this->ID          = $id;
			$this->post_status = $status;
			$this->post_type   = 'page';
			$this->post_name   = 'gracias';
		}
	}
}



function wp_parse_url( string $value, int $component = -1 ) {
	return parse_url( $value, $component );
}

function get_post( int $post_id ): ?WP_Post {
	return new WP_Post( $post_id, (string) $GLOBALS['nvx_test_status'] );
}

function get_permalink( int $post_id ): string {
	unset( $post_id );
	return (string) $GLOBALS['nvx_test_permalink'];
}

defined( 'OBJECT' ) || define( 'OBJECT', 'OBJECT' );
defined( 'NVX_HOOK_PRIO_INTERNAL_LINKS' ) || define( 'NVX_HOOK_PRIO_INTERNAL_LINKS', 10 );
defined( 'NVX_HOOK_PRIO_BUSINESS_RULES' ) || define( 'NVX_HOOK_PRIO_BUSINESS_RULES', 10 );
defined( 'NVX_HOOK_PRIO_TRUST_BADGES' ) || define( 'NVX_HOOK_PRIO_TRUST_BADGES', 10 );

function add_action( string $hook_name, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	return add_filter( $hook_name, $callback, $priority, $accepted_args );
}

function apply_filters( string $hook_name, $value ) {
	if ( ! isset( $GLOBALS['nvx_test_filters'] ) ) {
		return $value;
	}
	foreach ( $GLOBALS['nvx_test_filters'] as $filter ) {
		if ( $filter['hook'] === $hook_name && is_callable( $filter['callback'] ) ) {
			$value = call_user_func( $filter['callback'], $value );
		}
	}
	return $value;
}

function get_page_by_path( string $page_path, string $output = 'OBJECT', string $post_type = 'page' ) {
	if ( 'gracias' === $page_path ) {
		return new WP_Post( (int) $GLOBALS['nvx_test_page_id'], (string) $GLOBALS['nvx_test_status'] );
	}
	return null;
}

function add_filter( string $hook_name, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	$GLOBALS['nvx_test_filters'][] = array(
		'hook'          => $hook_name,
		'callback'      => $callback,
		'priority'      => $priority,
		'accepted_args' => $accepted_args,
	);
	return true;
}

defined( 'ABSPATH' ) || define( 'ABSPATH', $root . '/' );
require $root . '/wp-content/themes/nuvanx-medical/inc/nvx-page-hygiene.php';
require $governance;

$registered = array();
foreach ( $GLOBALS['nvx_test_filters'] as $filter ) {
	$registered[ $filter['hook'] ] = $filter;
}

isset( $registered['nvx_nofollow_page_ids'] ) || $fail( $scenario . ':nofollow_filter_missing' );
isset( $registered['nvx_noindex_page_ids'] ) || $fail( $scenario . ':noindex_filter_missing' );
! isset( $registered['nvx_noindex_but_navigable_page_ids'] ) || $fail( $scenario . ':navigable_bucket_forbidden' );
20 === (int) $registered['nvx_nofollow_page_ids']['priority'] || $fail( $scenario . ':nofollow_priority_invalid' );
20 === (int) $registered['nvx_noindex_page_ids']['priority'] || $fail( $scenario . ':noindex_priority_invalid' );
'nvx_gracias_robots_remove_nofollow' === $registered['nvx_nofollow_page_ids']['callback'] || $fail( $scenario . ':nofollow_callback_invalid' );
'nvx_gracias_robots_keep_noindex' === $registered['nvx_noindex_page_ids']['callback'] || $fail( $scenario . ':noindex_callback_invalid' );

$page_id = (int) $GLOBALS['nvx_test_page_id'];
$sentinel1 = $page_id + 1;
$sentinel2 = $page_id + 2;
$nofollow_input = array( $sentinel1, $page_id, $sentinel2 );
$noindex_input  = array( $sentinel1, $sentinel1 );
$nofollow_actual = nvx_gracias_robots_remove_nofollow( $nofollow_input );
$noindex_actual  = nvx_gracias_robots_keep_noindex( $noindex_input );

if ( 'valid' === $scenario ) {
	true === nvx_gracias_manifest_declares_noindex_follow() || $fail( 'valid:not_authorized' );
	array( $sentinel1, $sentinel2 ) === $nofollow_actual || $fail( 'valid:nofollow_not_removed' );
	array( $sentinel1, $page_id ) === $noindex_actual || $fail( 'valid:noindex_not_retained' );
} else {
	false === nvx_gracias_manifest_declares_noindex_follow() || $fail( $scenario . ':unexpected_authorization' );
	$nofollow_input === $nofollow_actual || $fail( $scenario . ':nofollow_fail_closed_broken' );
	$noindex_input === $noindex_actual || $fail( $scenario . ':noindex_fail_closed_broken' );
}

$navigable_bucket = nvx_noindex_but_navigable_page_ids();
in_array( $page_id, $navigable_bucket, true ) && $fail( $scenario . ':page_found_in_navigable_bucket' );

echo "GRACIAS_ROBOTS_SCENARIO=PASS scenario={$scenario}\n";

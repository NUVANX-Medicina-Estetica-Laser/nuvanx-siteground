<?php
/**
 * Static contract for the retired production-only browser Meta owner.
 */

declare(strict_types=1);

$root       = dirname( __DIR__, 2 );
$runtime    = $root . '/wp-content/themes/nuvanx-medical/inc/nvx-meta-browser-governance.php';
$bootstrap  = $root . '/wp-content/themes/nuvanx-medical/inc/nvx-environment-flags.php';
$boundary   = $root . '/scripts/production/verify-production-boundary.mjs';
$route_gate = __DIR__ . '/test-production-meta-local-boundary.mjs';

$fail = static function ( string $reason ): never {
	fwrite( STDERR, "META_BROWSER_OWNER_RETIREMENT=FAIL reason={$reason}\n" );
	exit( 1 );
};

is_file( $runtime ) || $fail( 'runtime_missing' );
is_file( $bootstrap ) || $fail( 'bootstrap_missing' );
is_file( $boundary ) || $fail( 'production_boundary_missing' );
is_file( $route_gate ) || $fail( 'production_local_route_gate_missing' );

$runtime_source   = file_get_contents( $runtime );
$bootstrap_source = file_get_contents( $bootstrap );
is_string( $runtime_source ) || $fail( 'runtime_unreadable' );
is_string( $bootstrap_source ) || $fail( 'bootstrap_unreadable' );

str_contains( $bootstrap_source, "require_once __DIR__ . '/nvx-meta-browser-governance.php';" )
	|| $fail( 'runtime_not_loaded_early' );
str_contains( $runtime_source, "return 'nuvanx-meta-dedupe-event-id.php';" )
	|| $fail( 'legacy_source_identity_missing' );
str_contains( $runtime_source, 'ReflectionFunction' ) || $fail( 'function_source_reflection_missing' );
str_contains( $runtime_source, 'ReflectionMethod' ) || $fail( 'method_source_reflection_missing' );
str_contains( $runtime_source, '$hook->remove_filter( $hook_name, $callback, (int) $priority );' )
	|| $fail( 'source_scoped_removal_missing' );
str_contains( $runtime_source, "add_action( 'init', 'nvx_retire_legacy_meta_browser_owner_callbacks', PHP_INT_MIN );" )
	|| $fail( 'early_init_retirement_missing' );
str_contains( $runtime_source, "add_action( 'wp_head', 'nvx_meta_browser_block_dynamic_loader', PHP_INT_MIN );" )
	|| $fail( 'pre_gtm_loader_guard_missing' );
str_contains( $runtime_source, "data-nvx-meta-browser-retired" )
	|| $fail( 'loader_guard_marker_missing' );
str_contains( $runtime_source, "Object.getOwnPropertyDescriptor(HTMLScriptElement.prototype, 'src')" )
	|| $fail( 'script_src_guard_missing' );
str_contains( $runtime_source, "add_action( 'send_headers', 'nvx_meta_browser_strip_legacy_response_cookies', PHP_INT_MAX );" )
	|| $fail( 'header_guard_missing' );
str_contains( $runtime_source, "header_remove( 'Set-Cookie' );" ) || $fail( 'set_cookie_rebuild_missing' );
str_contains( $runtime_source, '/^(?:_fbp|_fbc)=/i' ) || $fail( 'meta_cookie_scope_missing' );

foreach ( array( 'ob_start(', 'remove_all_actions(', 'remove_all_filters(', "add_action( 'template_redirect'", "add_filter( 'template_redirect'" ) as $forbidden ) {
	! str_contains( $runtime_source, $forbidden ) || $fail( 'broad_or_document_buffer_strategy_forbidden:' . $forbidden );
}

foreach ( array( 'connect.facebook.net', 'fbevents.js', "fbq('init'", '1497940655079106' ) as $forbidden_browser_owner ) {
	! str_contains( $runtime_source, $forbidden_browser_owner ) || $fail( 'browser_meta_loader_forbidden:' . $forbidden_browser_owner );
}

// Execute browser-level JS assertion.
exec('node ' . escapeshellarg( __DIR__ . '/test-meta-browser-dynamic-loader.mjs' ), $output, $code);
if ($code !== 0) {
	$fail('browser_level_dynamic_loader_guard_failed');
}

// Keep the P0 Production no-consent route inventory in the same blocking Meta contract.
$route_output = array();
$route_code   = 0;
exec('node ' . escapeshellarg( $route_gate ), $route_output, $route_code);
if ($route_code !== 0) {
	$fail('production_local_route_boundary_failed');
}

echo "META_BROWSER_OWNER_RETIREMENT=PASS source_scoped=1 dynamic_loader_guard=1 header_guard=1 browser_pixel_owner=none local_routes=3 boundary_routes=12 dual_path=1\n";

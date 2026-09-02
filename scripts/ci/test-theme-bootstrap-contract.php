<?php
/**
 * Canonical theme bootstrap dependency-order contract.
 *
 * This test is deliberately source-based. Loading the complete theme under a
 * partial WordPress mock can create false failures unrelated to dependency
 * ownership; the runtime contract is covered separately in Staging exact-SHA.
 */

declare(strict_types=1);

$functions = dirname( __DIR__, 2 ) . '/wp-content/themes/nuvanx-medical/functions.php';

if ( ! is_readable( $functions ) ) {
	fwrite( STDERR, "BOOTSTRAP_CONTRACT=FAIL reason=functions_missing\n" );
	exit( 1 );
}

$source = file_get_contents( $functions );
if ( ! is_string( $source ) || '' === $source ) {
	fwrite( STDERR, "BOOTSTRAP_CONTRACT=FAIL reason=functions_unreadable\n" );
	exit( 1 );
}

function nvx_bootstrap_contract_fail( string $reason ): never {
	fwrite( STDERR, 'BOOTSTRAP_CONTRACT=FAIL reason=' . $reason . PHP_EOL );
	exit( 1 );
}

if ( ! str_contains( $source, 'function nvx_theme_bootstrap(): void' ) ) {
	nvx_bootstrap_contract_fail( 'bootstrap_function_missing' );
}

if ( ! preg_match( "/add_action\\(\\s*'after_setup_theme',\\s*'nvx_theme_bootstrap',\\s*-1000\\s*\\)/", $source ) ) {
	nvx_bootstrap_contract_fail( 'after_setup_theme_hook_missing' );
}

$ordered = array(
	'inc/nvx-marketing-consent.php',
	'inc/nvx-ads-conversion-catalog.php',
	'inc/nvx-hubspot-secure-attribution.php',
	'inc/nvx-gtm-integration.php',
	'inc/nvx-attribution-integration.php',
	'inc/nvx-supabase-relay-queue.php',
	'inc/nvx-lead-captured-relay.php',
	'inc/nvx-google-attribution-relay-auth.php',
);

$previous = -1;
foreach ( $ordered as $module ) {
	$position = strpos( $source, "'{$module}'" );
	if ( false === $position ) {
		nvx_bootstrap_contract_fail( 'missing_' . basename( $module, '.php' ) );
	}
	if ( $position <= $previous ) {
		nvx_bootstrap_contract_fail( 'integration_order_' . basename( $module, '.php' ) );
	}
	$previous = $position;
}

$blog_system  = strpos( $source, "'inc/nvx-blog-system.php'" );
$blog_runtime = strpos( $source, "'inc/nvx-governed-blog-runtime.php'" );
if ( false === $blog_system || false === $blog_runtime || $blog_system >= $blog_runtime ) {
	nvx_bootstrap_contract_fail( 'blog_runtime_order' );
}

if ( 1 !== substr_count( $source, "'inc/nvx-structured-data.php'" ) ) {
	nvx_bootstrap_contract_fail( 'structured_data_owner_count' );
}

foreach (
	array(
		"'inc/nvx-schema-foundation.php'",
		"'inc/nvx-schema-faq.php'",
		"'inc/nvx-schema-treatments.php'",
		"'inc/nvx-schema-physicians.php'",
		"'inc/nvx-schema-graph.php'",
	) as $forbidden_direct_owner
) {
	if ( str_contains( $source, $forbidden_direct_owner ) ) {
		nvx_bootstrap_contract_fail( 'schema_submodule_double_owner' );
	}
}

echo "BOOTSTRAP_CONTRACT=PASS owner=functions lifecycle=after_setup_theme integration_order=canonical blog_order=canonical schema_owner=single\n";

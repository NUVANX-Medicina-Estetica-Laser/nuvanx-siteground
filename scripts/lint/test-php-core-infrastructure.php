<?php
/**
 * Block 9 regression: core infrastructure PHP.
 *
 * @package nuvanx-medical
 */

declare(strict_types=1);

function nvx_block9_assert( bool $condition, string $name ): void {
	if ( ! $condition ) {
		fwrite( STDERR, 'PHP_CORE_INFRASTRUCTURE=FAIL invariant=' . $name . PHP_EOL );
		exit( 1 );
	}
}

$root = dirname( __DIR__, 2 );
$retired = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-retired-strategy-redirects.php' );
$hygiene = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-page-hygiene.php' );
$request = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-theme-request.php' );
$bootstrap = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-theme-bootstrap.php' );

nvx_block9_assert( false === strpos( $retired, '$_SERVER' ), 'RETIRED_REDIRECTS_NO_LATE_SERVER_READS' );
nvx_block9_assert( false !== strpos( $retired, 'nvx_theme_request_context()' ), 'RETIRED_REDIRECTS_USE_IMMUTABLE_CONTEXT' );
nvx_block9_assert( false !== strpos( $request, "'query_args'" ), 'REQUEST_CONTEXT_OWNS_QUERY_ARGS' );

// Confirmed follow-up debt: page-hygiene still has several direct URI reads and
// will be migrated as one redirect/hygiene consolidation rather than piecemeal.
nvx_block9_assert( false !== strpos( $hygiene, '$_SERVER[\'REQUEST_URI\']' ), 'PAGE_HYGIENE_DIRECT_URI_DEBT_RECORDED' );

$core = array(
	'nvx-page-registry.php',
	'nvx-business-config.php',
	'nvx-clinical-governance.php',
	'nvx-environment-flags.php',
	'nvx-meta-browser-governance.php',
	'nvx-page-render-helpers.php',
	'nvx-authentic-page-photography.php',
	'nvx-public-media-runtime-governance.php',
	'nvx-document-governance.php',
	'nvx-native-style-governance.php',
	'nvx-page-hygiene.php',
	'nvx-security-headers.php',
	'nvx-retired-strategy-redirects.php',
	'nvx-brand-page-wrapper-governance.php',
	'nvx-integrations.php',
	'nvx-complianz-policy-routing.php',
	'performance/nuvanx-performance.php',
	'nvx-catalog-json.php',
);

$positions = array();
foreach ( $core as $module ) {
	$needle = "'inc/{$module}'";
	$offset = strpos( $bootstrap, $needle );
	nvx_block9_assert( false !== $offset, 'CORE_MANIFEST_' . $module );
	$positions[] = $offset;
}
$sorted = $positions;
sort( $sorted, SORT_NUMERIC );
nvx_block9_assert( $positions === $sorted, 'CORE_MANIFEST_ORDER_STABLE' );

echo 'PHP_CORE_INFRASTRUCTURE=PASS modules=' . count( $core ) . ' retired_redirects=immutable page_hygiene_debt=guarded' . PHP_EOL;

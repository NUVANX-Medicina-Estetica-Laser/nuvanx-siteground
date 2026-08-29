<?php
/**
 * Static ownership contract for canonical SEO text metadata.
 *
 * The website repository validates only website-owned SEO state. External
 * directories such as Doctoralia are validated from live/connected surfaces,
 * never from frozen observation snapshots committed to the theme.
 */

declare(strict_types=1);

$root       = dirname( __DIR__, 2 );
$data_dir   = $root . '/wp-content/themes/nuvanx-medical/inc/data';
$routes_raw = file_get_contents( $data_dir . '/routes.json' );
$seo_raw    = file_get_contents( $data_dir . '/seo-metadata.json' );
$central    = file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-seo-metadata.php' );

if ( false === $routes_raw || false === $seo_raw || false === $central ) {
	fwrite( STDERR, "SEO_CATALOG_OWNERSHIP_TEST=FAIL reason=unreadable_contract_source\n" );
	exit( 1 );
}

$routes = json_decode( $routes_raw, true );
$seo    = json_decode( $seo_raw, true );
if ( ! is_array( $routes ) || ! is_array( $seo ) ) {
	fwrite( STDERR, "SEO_CATALOG_OWNERSHIP_TEST=FAIL reason=invalid_json\n" );
	exit( 1 );
}

$failures = array();
foreach ( $routes as $route => $config ) {
	if ( ! is_array( $config ) || isset( $config['route_alias'] ) || empty( $config['seo_id'] ) ) {
		continue;
	}
	$seo_id = (string) $config['seo_id'];
	$record = $seo[ $seo_id ] ?? null;
	if (
		! is_array( $record )
		|| '' === trim( (string) ( $record['title'] ?? '' ) )
		|| '' === trim( (string) ( $record['description'] ?? '' ) )
	) {
		$failures[] = sprintf( 'missing_catalog_record route=%s seo_id=%s', $route, $seo_id );
	}
}

if ( false === strpos( $central, "add_filter( 'wpseo_title', 'nvx_seo_filter_title', 100 );" ) ) {
	$failures[] = 'canonical_title_owner_missing';
}
if ( false === strpos( $central, "add_filter( 'wpseo_metadesc', 'nvx_seo_filter_description', 100 );" ) ) {
	$failures[] = 'canonical_description_owner_missing';
}

$contact = $seo['contacto'] ?? null;
if (
	! is_array( $contact )
	|| 'Clínicas NUVANX Madrid: Contacto, Teléfonos y Sedes | Chamberí y Salamanca–Goya' !== ( $contact['title'] ?? null )
	|| 'Contacto NUVANX Madrid: direcciones, teléfonos, WhatsApp y horarios de las clínicas Chamberí (CS20144) y Salamanca–Goya (CS20073). Valoración médica presencial para medicina estética láser.' !== ( $contact['description'] ?? null )
) {
	$failures[] = 'contacto_catalog_parity_missing';
}

$contracts = array(
	'test-local-seo-ownership.php'          => 'local_seo_ownership_contract',
	'test-goya-nap-display-contract.php'   => 'goya_nap_display_contract',
	'test-gsc-search-analytics-contract.mjs'=> 'gsc_search_analytics_contract',
);
foreach ( $contracts as $filename => $label ) {
	$path = __DIR__ . '/' . $filename;
	if ( ! is_file( $path ) ) {
		$failures[] = $label . '_missing';
		continue;
	}
	$runtime = str_ends_with( $filename, '.mjs' ) ? 'node ' : 'php ';
	passthru( $runtime . escapeshellarg( $path ), $status );
	if ( 0 !== $status ) {
		$failures[] = $label . '_failed exit=' . $status;
	}
}

if ( array() !== $failures ) {
	fwrite( STDERR, "SEO_CATALOG_OWNERSHIP_TEST=FAIL\n" . implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'SEO_CATALOG_OWNERSHIP_TEST=PASS' . PHP_EOL;

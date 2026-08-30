<?php
/**
 * Lock the patient-cases holding route to the same publication policy as the
 * runtime hygiene safeguard. A future clinical release must deliberately change
 * both owners in the same reviewed change.
 */

$root          = dirname( __DIR__, 2 );
$manifest_path = $root . '/wp-content/themes/nuvanx-medical/inc/data/publication-manifest.json';
$hygiene_path  = $root . '/wp-content/themes/nuvanx-medical/inc/nvx-page-hygiene.php';
$template_path = $root . '/wp-content/themes/nuvanx-medical/page-casos-de-pacientes.php';

foreach ( array( $manifest_path, $hygiene_path, $template_path ) as $path ) {
	if ( ! is_file( $path ) || ! is_readable( $path ) ) {
		fwrite( STDERR, "CASES_PUBLICATION_POLICY=FAIL reason=unreadable_dependency\n" );
		exit( 1 );
	}
}

$manifest = json_decode( (string) file_get_contents( $manifest_path ), true );
$route    = is_array( $manifest ) ? ( $manifest['routes']['/casos-de-pacientes/'] ?? null ) : null;
if ( ! is_array( $route ) ) {
	fwrite( STDERR, "CASES_PUBLICATION_POLICY=FAIL reason=missing_manifest_route\n" );
	exit( 1 );
}

if ( 'publish' !== (string) ( $route['status'] ?? '' ) ) {
	fwrite( STDERR, "CASES_PUBLICATION_POLICY=FAIL reason=route_not_published\n" );
	exit( 1 );
}

if ( false !== ( $route['robots']['index'] ?? null ) || true !== ( $route['robots']['follow'] ?? null ) ) {
	fwrite( STDERR, "CASES_PUBLICATION_POLICY=FAIL reason=manifest_must_be_noindex_follow\n" );
	exit( 1 );
}

$hygiene  = (string) file_get_contents( $hygiene_path );
$template = (string) file_get_contents( $template_path );

$required_hygiene = array(
	"nvx_page_id_by_slug( 'casos-de-pacientes' )",
	'Casos de pacientes remains reachable only as an editorial holding route.',
	'$ids[] = $cases_id;',
);
foreach ( $required_hygiene as $marker ) {
	if ( false === strpos( $hygiene, $marker ) ) {
		fwrite( STDERR, "CASES_PUBLICATION_POLICY=FAIL reason=runtime_holding_safeguard_missing\n" );
		exit( 1 );
	}
}

if ( false === strpos( $template, 'responsible holding state' ) || false === strpos( $template, '_nvx_cases_publication_ready=1' ) ) {
	fwrite( STDERR, "CASES_PUBLICATION_POLICY=FAIL reason=holding_template_contract_missing\n" );
	exit( 1 );
}

fwrite( STDOUT, "CASES_PUBLICATION_POLICY=PASS status=publish robots=noindex,follow runtime_safeguard=active\n" );

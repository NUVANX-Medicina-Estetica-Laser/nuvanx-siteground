<?php
/**
 * Lock the patient-cases holding route to the same publication policy as the
 * executable runtime hygiene safeguard. A future clinical release must change
 * both owners deliberately in the same reviewed change.
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

$function_start = strpos( $hygiene, 'function nvx_noindex_page_ids()' );
if ( false === $function_start ) {
	fwrite( STDERR, "CASES_PUBLICATION_POLICY=FAIL reason=noindex_owner_missing\n" );
	exit( 1 );
}
$function_end = strpos( $hygiene, 'function nvx_noindex_but_navigable_page_ids()', $function_start );
if ( false === $function_end ) {
	fwrite( STDERR, "CASES_PUBLICATION_POLICY=FAIL reason=noindex_owner_boundary_missing\n" );
	exit( 1 );
}
$noindex_owner = substr( $hygiene, $function_start, $function_end - $function_start );

$required_executable = array(
	"$cases_id = nvx_page_id_by_slug( 'casos-de-pacientes' );",
	'if ( $cases_id > 0 ) {',
	'$ids[] = $cases_id;',
);
foreach ( $required_executable as $marker ) {
	if ( false === strpos( $noindex_owner, $marker ) ) {
		fwrite( STDERR, "CASES_PUBLICATION_POLICY=FAIL reason=runtime_noindex_safeguard_missing\n" );
		exit( 1 );
	}
}

$required_template = array(
	"nvx_catalog_json_load( 'patient-cases.json' )",
	'get_header();',
	'class="nvx-page nvx-brand-page nvx-cases-holding"',
	'get_footer();',
);
foreach ( $required_template as $marker ) {
	if ( false === strpos( $template, $marker ) ) {
		fwrite( STDERR, "CASES_PUBLICATION_POLICY=FAIL reason=holding_template_runtime_missing\n" );
		exit( 1 );
	}
}

fwrite( STDOUT, "CASES_PUBLICATION_POLICY=PASS status=publish robots=noindex,follow runtime_safeguard=active holding_template=active\n" );

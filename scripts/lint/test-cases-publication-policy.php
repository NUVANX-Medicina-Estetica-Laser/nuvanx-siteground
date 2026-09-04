<?php
/**
 * Lock the consented patient-cases route to its approved public publication policy.
 *
 * The repository owns documented clinical sequences whose publication consent
 * and editorial approval are explicit. This regression test prevents a future
 * deploy from silently returning the route to noindex/holding status.
 */

$root          = dirname( __DIR__, 2 );
$manifest_path = $root . '/wp-content/themes/nuvanx-medical/inc/data/publication-manifest.json';
$hygiene_path  = $root . '/wp-content/themes/nuvanx-medical/inc/nvx-page-hygiene.php';
$template_path = $root . '/wp-content/themes/nuvanx-medical/page-casos-de-pacientes.php';
$cases_path    = $root . '/wp-content/themes/nuvanx-medical/inc/data/patient-cases.json';

foreach ( array( $manifest_path, $hygiene_path, $template_path, $cases_path ) as $path ) {
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

if ( true !== ( $route['robots']['index'] ?? null ) || true !== ( $route['robots']['follow'] ?? null ) ) {
	fwrite( STDERR, "CASES_PUBLICATION_POLICY=FAIL reason=manifest_must_be_index_follow\n" );
	exit( 1 );
}

$cases_data = json_decode( (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/data/patient-cases.json' ), true );
if ( ! is_array( $cases_data ) ) {
	fwrite( STDERR, "CASES_PUBLICATION_POLICY=FAIL reason=invalid_cases_data\n" );
	exit( 1 );
}

$consent_states = $cases_data['consent_states'] ?? array();
$valid_states   = array_keys( $consent_states );
if ( ! in_array( 'approved', $valid_states, true ) || ! in_array( 'withdrawn', $valid_states, true ) || ! in_array( 'pending', $valid_states, true ) ) {
	fwrite( STDERR, "CASES_PUBLICATION_POLICY=FAIL reason=missing_canonical_consent_states\n" );
	exit( 1 );
}

$cases = $cases_data['cases'] ?? array();
foreach ( $cases as $case ) {
	$consent = $case['consent_status'] ?? '';
	if ( ! in_array( $consent, $valid_states, true ) ) {
		fwrite( STDERR, "CASES_PUBLICATION_POLICY=FAIL reason=invalid_consent_status case={$case['id']} consent={$consent}\n" );
		exit( 1 );
	}
}

$hygiene  = (string) file_get_contents( $hygiene_path );
$template = (string) file_get_contents( $template_path );
$cases    = json_decode( (string) file_get_contents( $cases_path ), true );

$function_start = strpos( $hygiene, 'function nvx_noindex_page_ids()' );
if ( false === $function_start ) {
	fwrite( STDERR, "CASES_PUBLICATION_POLICY=FAIL reason=noindex_owner_missing\n" );
	exit( 1 );
}
$function_end = strpos( $hygiene, 'function nvx_exclude_sensitive_pages_from_sitemap_ids', $function_start );
if ( false === $function_end ) {
	fwrite( STDERR, "CASES_PUBLICATION_POLICY=FAIL reason=noindex_owner_boundary_missing\n" );
	exit( 1 );
}
$noindex_owner = substr( $hygiene, $function_start, $function_end - $function_start );

$forbidden_noindex_markers = array(
	"nvx_page_id_by_slug( 'casos-de-pacientes' )",
	'$cases_id',
);
foreach ( $forbidden_noindex_markers as $marker ) {
	if ( false !== strpos( $noindex_owner, $marker ) ) {
		fwrite( STDERR, "CASES_PUBLICATION_POLICY=FAIL reason=runtime_cases_noindex_reintroduced\n" );
		exit( 1 );
	}
}

$required_template_markers = array(
	"nvx_catalog_json_load( 'patient-cases.json' )",
	'<?php foreach ( $cases_list as $case ) : ?>',
	'get_header();',
	'get_footer();',
);
foreach ( $required_template_markers as $marker ) {
	if ( false === strpos( $template, $marker ) ) {
		fwrite( STDERR, "CASES_PUBLICATION_POLICY=FAIL reason=public_cases_template_runtime_missing\n" );
		exit( 1 );
	}
}

// Formatting is not part of the publication contract. Match the executable
// assignment semantically so PHPCS/alignment changes cannot create a false red.
if ( 1 !== preg_match( '/\$cases_list\s*=\s*\$cases_data\[\'cases\'\]\s*\?\?\s*array\(\s*\)\s*;/', $template ) ) {
	fwrite( STDERR, "CASES_PUBLICATION_POLICY=FAIL reason=public_cases_template_runtime_missing\n" );
	exit( 1 );
}

$case_rows = is_array( $cases ) && isset( $cases['cases'] ) && is_array( $cases['cases'] ) ? $cases['cases'] : array();
if ( count( $case_rows ) < 1 ) {
	fwrite( STDERR, "CASES_PUBLICATION_POLICY=FAIL reason=no_repository_cases\n" );
	exit( 1 );
}

foreach ( $case_rows as $case ) {
	$consent = is_array( $case ) ? trim( (string) ( $case['consent_status'] ?? '' ) ) : '';
	if ( '' === $consent ) {
		fwrite( STDERR, "CASES_PUBLICATION_POLICY=FAIL reason=case_without_consent_status\n" );
		exit( 1 );
	}
}

fwrite( STDOUT, 'CASES_PUBLICATION_POLICY=PASS status=publish robots=index,follow runtime_safeguard=removed repository_cases=' . count( $case_rows ) . " consent_status=present\n" );

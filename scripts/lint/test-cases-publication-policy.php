<?php
/**
 * Lock the consented patient-cases route to its approved public publication policy.
 *
 * Case publication consent and photographic publication approval are separate
 * authorities. This contract validates runtime semantics without depending on
 * incidental whitespace or source formatting.
 */

declare(strict_types=1);

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

$cases_data = json_decode( (string) file_get_contents( $cases_path ), true );
if ( ! is_array( $cases_data ) ) {
	fwrite( STDERR, "CASES_PUBLICATION_POLICY=FAIL reason=invalid_cases_data\n" );
	exit( 1 );
}

$consent_states = is_array( $cases_data['consent_states'] ?? null ) ? $cases_data['consent_states'] : array();
$valid_consents = array_keys( $consent_states );
foreach ( array( 'approved', 'withdrawn', 'pending' ) as $required_consent ) {
	if ( ! in_array( $required_consent, $valid_consents, true ) ) {
		fwrite( STDERR, "CASES_PUBLICATION_POLICY=FAIL reason=missing_canonical_consent_states\n" );
		exit( 1 );
	}
}

$media_states = is_array( $cases_data['media_states'] ?? null ) ? $cases_data['media_states'] : array();
$valid_media  = array_keys( $media_states );
foreach ( array( 'approved', 'quarantined' ) as $required_media_state ) {
	if ( ! in_array( $required_media_state, $valid_media, true ) ) {
		fwrite( STDERR, "CASES_PUBLICATION_POLICY=FAIL reason=missing_canonical_media_states\n" );
		exit( 1 );
	}
}

$case_rows = is_array( $cases_data['cases'] ?? null ) ? $cases_data['cases'] : array();
if ( count( $case_rows ) < 1 ) {
	fwrite( STDERR, "CASES_PUBLICATION_POLICY=FAIL reason=no_repository_cases\n" );
	exit( 1 );
}

foreach ( $case_rows as $case ) {
	if ( ! is_array( $case ) ) {
		fwrite( STDERR, "CASES_PUBLICATION_POLICY=FAIL reason=invalid_case_row\n" );
		exit( 1 );
	}

	$id      = trim( (string) ( $case['id'] ?? '' ) );
	$consent = trim( (string) ( $case['consent_status'] ?? '' ) );
	$media   = trim( (string) ( $case['media_status'] ?? '' ) );
	$scope   = trim( (string) ( $case['media_scope'] ?? '' ) );
	$kind    = trim( (string) ( $case['media_kind'] ?? '' ) );

	if ( '' === $id || ! in_array( $consent, $valid_consents, true ) ) {
		fwrite( STDERR, "CASES_PUBLICATION_POLICY=FAIL reason=invalid_consent_status case={$id} consent={$consent}\n" );
		exit( 1 );
	}
	if ( 'clinical_case' !== $scope || 'before_after' !== $kind || ! in_array( $media, $valid_media, true ) ) {
		fwrite( STDERR, "CASES_PUBLICATION_POLICY=FAIL reason=invalid_media_authority case={$id} media={$media} scope={$scope} kind={$kind}\n" );
		exit( 1 );
	}
}

$hygiene  = (string) file_get_contents( $hygiene_path );
$template = (string) file_get_contents( $template_path );

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

foreach ( array( "nvx_page_id_by_slug( 'casos-de-pacientes' )", '$cases_id' ) as $marker ) {
	if ( false !== strpos( $noindex_owner, $marker ) ) {
		fwrite( STDERR, "CASES_PUBLICATION_POLICY=FAIL reason=runtime_cases_noindex_reintroduced\n" );
		exit( 1 );
	}
}

$required_template_literals = array(
	"nvx_catalog_json_load( 'patient-cases.json' )",
	'<?php foreach ( $cases_list as $case ) : ?>',
	"'clinical_case' === ( \$case['media_scope'] ?? '' )",
	"'before_after' === ( \$case['media_kind'] ?? '' )",
	"'approved' === ( \$case['media_status'] ?? '' )",
	"\$media_is_approved && ! empty( \$case['image_before'] ) && ! empty( \$case['image_after'] )",
	'get_header();',
	'get_footer();',
);
foreach ( $required_template_literals as $marker ) {
	if ( false === strpos( $template, $marker ) ) {
		fwrite( STDERR, "CASES_PUBLICATION_POLICY=FAIL reason=public_cases_template_runtime_missing marker={$marker}\n" );
		exit( 1 );
	}
}

if ( false !== strpos( $template, "\$case['image']" ) ) {
	fwrite( STDERR, "CASES_PUBLICATION_POLICY=FAIL reason=legacy_single_image_fallback_reintroduced\n" );
	exit( 1 );
}

if ( 1 !== preg_match( '/\$cases_list\s*=\s*\$cases_data\s*\[\s*[\'\"]cases[\'\"]\s*\]\s*\?\?\s*array\s*\(\s*\)\s*;/', $template ) ) {
	fwrite( STDERR, "CASES_PUBLICATION_POLICY=FAIL reason=cases_catalog_assignment_missing\n" );
	exit( 1 );
}

if ( 1 !== preg_match( '/\$media_is_approved\s*=\s*[\s\S]*?media_scope[\s\S]*?media_kind[\s\S]*?media_status[\s\S]*?;/', $template ) ) {
	fwrite( STDERR, "CASES_PUBLICATION_POLICY=FAIL reason=media_publication_gate_missing\n" );
	exit( 1 );
}

fwrite(
	STDOUT,
	'CASES_PUBLICATION_POLICY=PASS status=publish robots=index,follow runtime_safeguard=removed repository_cases=' . count( $case_rows ) . " consent_authority=explicit media_authority=explicit incomplete_pair_fallback=blocked\n"
);
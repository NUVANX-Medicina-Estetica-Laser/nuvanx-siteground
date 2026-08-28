<?php
/**
 * Blocking contract for Doctoralia external-profile reconciliation.
 *
 * The repository must distinguish canonical NUVANX service truth from observed
 * Doctoralia public/admin drift. External Doctoralia writes remain fail-closed
 * until synchronization ownership, Goya direction ownership and Chamberí admin
 * export are all resolved.
 */

declare(strict_types=1);

$root = dirname( __DIR__, 2 );
$fail = static function ( string $message ): void {
	fwrite( STDERR, 'DOCTORALIA_PUBLIC_PARITY_TEST=FAIL ' . $message . PHP_EOL );
	exit( 1 );
};

$data_path    = $root . '/wp-content/themes/nuvanx-medical/inc/data/doctoralia-profiles.json';
$services_path = $root . '/wp-content/themes/nuvanx-medical/inc/data/treatment-hub-schema.json';
$schema_path  = $root . '/wp-content/themes/nuvanx-medical/inc/nvx-schema-foundation.php';

$data_raw     = file_get_contents( $data_path );
$services_raw = file_get_contents( $services_path );
$schema_raw   = file_get_contents( $schema_path );

if ( false === $data_raw || false === $services_raw || false === $schema_raw ) {
	$fail( 'unreadable Doctoralia parity contract source' );
}

$data     = json_decode( $data_raw, true );
$services = json_decode( $services_raw, true );
if ( ! is_array( $data ) || ! is_array( $services ) ) {
	$fail( 'invalid governed Doctoralia/service JSON' );
}

if ( 'external_public_parity_open' !== ( $data['status'] ?? null ) ) {
	$fail( 'external Doctoralia parity must remain open until public surfaces reconcile' );
}

$canonical_keys = array();
foreach ( $services as $service ) {
	if ( ! is_array( $service ) || empty( $service['key'] ) ) {
		$fail( 'treatment-hub-schema contains an invalid service row' );
	}
	$canonical_keys[] = (string) $service['key'];
}
$projection_keys = $data['target_projection']['service_keys'] ?? null;
if ( ! is_array( $projection_keys ) || $canonical_keys !== array_values( $projection_keys ) ) {
	$fail( 'Doctoralia target projection drifted from treatment-hub-schema SSOT' );
}

$policy = $data['mutation_policy'] ?? null;
if ( ! is_array( $policy ) || false !== ( $policy['doctoralia_write_allowed'] ?? null ) ) {
	$fail( 'Doctoralia writes must remain blocked while ownership is unresolved' );
}

$required_before_write = array(
	'goya_synchronization_owner_confirmed',
	'goya_canonical_direction_confirmed',
	'chamberi_admin_export_complete',
	'website_chamberi_goya_exact_parity_diff_complete',
);
if ( $required_before_write !== ( $policy['required_before_write'] ?? null ) ) {
	$fail( 'Doctoralia write preconditions changed without governance update' );
}

$chamberi = $data['clinics']['chamberi'] ?? null;
$goya     = $data['clinics']['goya'] ?? null;
if ( ! is_array( $chamberi ) || ! is_array( $goya ) ) {
	$fail( 'both Doctoralia clinic records are required' );
}
if ( '47595' !== ( $chamberi['facility_id'] ?? null ) || 'pending' !== ( $chamberi['admin_export_status'] ?? null ) ) {
	$fail( 'Chamberí admin export/facility state is not the observed checkpoint' );
}
if ( '54924' !== ( $goya['facility_id'] ?? null ) ) {
	$fail( 'Goya facility identity drift' );
}
if ( 'unverified' !== ( $goya['synchronization_owner_status'] ?? null )
	|| 'unverified' !== ( $goya['canonical_direction_status'] ?? null ) ) {
	$fail( 'Goya ownership cannot be promoted before authenticated sync evidence' );
}

$directions = $goya['directions'] ?? null;
if ( ! is_array( $directions )
	|| 16 !== ( $directions['53333']['editable_service_rows'] ?? null )
	|| 7 !== ( $directions['49168']['editable_service_rows'] ?? null )
	|| 'more_complete_candidate' !== ( $directions['53333']['relationship'] ?? null )
	|| 'exact_first_seven_row_subset_of_53333' !== ( $directions['49168']['relationship'] ?? null ) ) {
	$fail( 'Goya direction reconciliation evidence drift' );
}
if ( 'candidate_unconfirmed' !== ( $directions['53333']['canonical_status'] ?? null )
	|| 'unconfirmed_do_not_mutate' !== ( $directions['49168']['canonical_status'] ?? null ) ) {
	$fail( 'a Goya direction was promoted without ownership proof' );
}

$public = $goya['public_primary_profile'] ?? null;
if ( ! is_array( $public ) || 'drift_live' !== ( $public['status'] ?? null ) ) {
	$fail( 'primary Goya public profile must remain classified as live drift' );
}
$legacy_services = $public['legacy_services_observed'] ?? array();
foreach ( array( 'Coolsculpting', 'Tratamiento con dermapen', 'HIFU (Facial)', 'HIFU (Corporal)' ) as $legacy ) {
	if ( ! in_array( $legacy, $legacy_services, true ) ) {
		$fail( 'observed legacy public service disappeared from governed checkpoint: ' . $legacy );
	}
}
if ( 'inconsistent_with_primary_profile' !== ( $goya['public_secondary_surfaces']['status'] ?? null ) ) {
	$fail( 'Doctoralia secondary public surfaces must remain marked inconsistent' );
}

$legal = $goya['legal_healthcare_responsible'] ?? null;
if ( ! is_array( $legal )
	|| 'unverified' !== ( $legal['status'] ?? null )
	|| false !== ( $legal['mutation_allowed'] ?? null ) ) {
	$fail( 'CS20073 healthcare-responsible role must remain fail-closed' );
}
if ( 'observed_admin_not_official_register' !== ( $goya['admin_legal_surface']['classification'] ?? null )
	|| 'observed_public_not_official_register' !== ( $public['responsable_classification'] ?? null ) ) {
	$fail( 'Doctoralia admin/public responsible-person observations were promoted beyond evidence' );
}

$goya_url = (string) ( $goya['public_url'] ?? '' );
if ( '' === $goya_url || ! str_contains( $schema_raw, "'https://www.doctoralia.es/clinicas/nuvanx-medicina-estetica-laser-sede-goya'" ) ) {
	$fail( 'Goya MedicalClinic sameAs lost the canonical public Doctoralia profile' );
}
if ( str_contains( $schema_raw, 'yolanda piñero' ) || str_contains( $schema_raw, 'Javier Rivera Tejeda' ) ) {
	$fail( 'unverified Doctoralia responsible-person data leaked into website Schema' );
}

foreach ( $data['target_projection']['legacy_not_canonical'] ?? array() as $legacy ) {
	$legacy_lower = strtolower( (string) $legacy );
	foreach ( $services as $service ) {
		$name_lower = strtolower( (string) ( $service['name'] ?? '' ) );
		if ( '' !== $name_lower && $name_lower === $legacy_lower ) {
			$fail( 'legacy Doctoralia service was promoted into canonical treatment SSOT: ' . $legacy );
		}
	}
}

echo 'DOCTORALIA_PUBLIC_PARITY_TEST=PASS status=external_public_parity_open goya_facility=54924 directions=2 chamberi_export=pending writes=blocked legal_role=unverified' . PHP_EOL;

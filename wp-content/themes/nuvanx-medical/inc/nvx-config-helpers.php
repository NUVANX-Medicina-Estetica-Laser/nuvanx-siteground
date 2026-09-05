<?php
/**
 * Canonical helpers for clinic contact and medical-staff identity data.
 *
 * @package nuvanx-medical
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/nvx-catalog-json.php';

/** Normalize a telephone value to international digits only. */
function nvx_phone_digits( string $phone ): string {
	return preg_replace( '/\D+/', '', $phone ) ?? '';
}

/** Build one canonical wa.me URL from any supported phone representation. */
function nvx_whatsapp_url_from_phone( string $phone ): string {
	$number = nvx_phone_digits( $phone );
	return '' !== $number ? 'https://wa.me/' . $number : '';
}

/**
 * Get WhatsApp number for a specific clinic or the primary clinic.
 *
 * @param string $clinic Clinic identifier ('primary', 'chamberi', 'goya').
 */
function nvx_whatsapp_number( string $clinic = 'primary' ): string {
	if ( ! function_exists( 'nvx_get_clinics_config' ) ) {
		return '';
	}

	$primary_key = 'chamberi';
	$key         = 'primary' === $clinic ? $primary_key : $clinic;
	$clinics     = nvx_get_clinics_config();

	if ( ! isset( $clinics[ $key ]['phone_href'] ) ) {
		$key = $primary_key;
	}

	$phone = isset( $clinics[ $key ]['phone_href'] ) ? (string) $clinics[ $key ]['phone_href'] : '';
	return nvx_phone_digits( $phone );
}

/** Get full WhatsApp URL for a specific clinic. */
function nvx_whatsapp_url( string $clinic = 'primary' ): string {
	return nvx_whatsapp_url_from_phone( nvx_whatsapp_number( $clinic ) );
}

/**
 * Load the canonical medical-staff registry.
 *
 * Page copy remains in page-specific catalogs. This registry owns clinician
 * identity, colegiado and stable public-profile/media references only.
 *
 * @return array<string,array<string,mixed>>
 */
function nvx_medical_staff_registry(): array {
	static $staff = null;
	if ( is_array( $staff ) ) {
		return $staff;
	}

	$data = nvx_catalog_json_load( 'medical-staff.json' );
	if (
		! empty( $data['_error'] )
		|| 1 !== (int) ( $data['schema'] ?? 0 )
		|| ! is_array( $data['staff'] ?? null )
	) {
		$staff = array();
		return $staff;
	}

	$staff = array();
	foreach ( $data['staff'] as $id => $doctor ) {
		if ( ! is_string( $id ) || ! is_array( $doctor ) ) {
			continue;
		}
		$name      = trim( (string) ( $doctor['name'] ?? '' ) );
		$colegiado = preg_replace( '/\D+/', '', (string) ( $doctor['colegiado'] ?? '' ) ) ?? '';
		if ( '' === $name || '' === $colegiado ) {
			continue;
		}

		$record = array(
			'name'      => $name,
			'colegiado' => $colegiado,
		);
		$doctoralia_url = trim( (string) ( $doctor['doctoralia_url'] ?? '' ) );
		if ( '' !== $doctoralia_url && wp_http_validate_url( $doctoralia_url ) ) {
			$record['doctoralia_url'] = $doctoralia_url;
		}
		$attachment_id = (int) ( $doctor['profile_media_attachment_id'] ?? 0 );
		if ( $attachment_id > 0 ) {
			$record['profile_media_attachment_id'] = $attachment_id;
		}
		$staff[ $id ] = $record;
	}

	return $staff;
}

/** Get a complete clinician record from the canonical registry. */
function nvx_medical_staff_record( string $doctor_id ): array {
	$staff = nvx_medical_staff_registry();
	return isset( $staff[ $doctor_id ] ) && is_array( $staff[ $doctor_id ] ) ? $staff[ $doctor_id ] : array();
}

/** Get a clinician's colegiado number from the canonical registry. */
function nvx_medical_colegiado( string $doctor_id ): string {
	$doctor = nvx_medical_staff_record( $doctor_id );
	return isset( $doctor['colegiado'] ) ? (string) $doctor['colegiado'] : '';
}

/** Get a clinician's public name from the canonical registry. */
function nvx_medical_staff_name( string $doctor_id ): string {
	$doctor = nvx_medical_staff_record( $doctor_id );
	return isset( $doctor['name'] ) ? (string) $doctor['name'] : '';
}

/** Get a clinician's Doctoralia profile URL when governed. */
function nvx_medical_staff_doctoralia_url( string $doctor_id ): string {
	$doctor = nvx_medical_staff_record( $doctor_id );
	return isset( $doctor['doctoralia_url'] ) ? (string) $doctor['doctoralia_url'] : '';
}

/** Get a clinician's governed profile-media attachment ID. */
function nvx_medical_staff_profile_media_attachment_id( string $doctor_id ): int {
	$doctor = nvx_medical_staff_record( $doctor_id );
	return isset( $doctor['profile_media_attachment_id'] ) ? (int) $doctor['profile_media_attachment_id'] : 0;
}

/** Load the canonical registry for governed clinic and partner assets. */
function nvx_clinic_asset_registry(): array {
	static $registry = null;
	if ( is_array( $registry ) ) {
		return $registry;
	}

	$data     = nvx_catalog_json_load( 'clinic-asset-registry.json' );
	$registry = empty( $data['_error'] )
		&& 'nuvanx-clinic-asset-registry/v3' === ( $data['schema'] ?? '' )
		? $data
		: array();
	return $registry;
}

/** Get the governed uploads paths for a clinic landing gallery. */
function nvx_clinic_landing_gallery_registry( string $clinic_key ): array {
	$registry = nvx_clinic_asset_registry();
	$gallery  = $registry['approved_editorial_overrides']['clinic_landing_galleries'][ $clinic_key ] ?? array();
	return is_array( $gallery ) ? $gallery : array();
}

// Public medical identity constants are defined once from the canonical registry.
if ( ! defined( 'NVX_DIRECTOR_COLEGIADO' ) ) {
	define( 'NVX_DIRECTOR_COLEGIADO', nvx_medical_colegiado( 'director' ) );
}
if ( ! defined( 'NVX_IVON_COLEGIADO' ) ) {
	define( 'NVX_IVON_COLEGIADO', nvx_medical_colegiado( 'ivon' ) );
}
if ( ! defined( 'NVX_FABIO_COLEGIADO' ) ) {
	define( 'NVX_FABIO_COLEGIADO', nvx_medical_colegiado( 'fabio' ) );
}
if ( ! defined( 'NVX_CRISTINA_COLEGIADO' ) ) {
	define( 'NVX_CRISTINA_COLEGIADO', nvx_medical_colegiado( 'cristina' ) );
}

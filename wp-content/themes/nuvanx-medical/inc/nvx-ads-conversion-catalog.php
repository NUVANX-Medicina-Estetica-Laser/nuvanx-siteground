<?php
/**
 * Google Ads click conversion governed mirror.
 *
 * Google Ads is the external authority. The repository stores only the
 * browser-facing send_to mirror plus explicit ownership/verification metadata.
 * Form measurement remains HubSpot -> GA4 generate_lead -> Ads import.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Validate the browser-facing Google Ads send_to format. */
function nvx_ads_is_send_to( string $value ): bool {
	return 1 === preg_match( '/^AW-[0-9]{8,12}\/[A-Za-z0-9_-]+$/', $value );
}

/**
 * Resolve the phone/WhatsApp Google Ads click conversion.
 *
 * @return string Empty when the governed mirror is missing or invalid.
 */
function nvx_ads_phone_whatsapp_send_to(): string {
	if ( ! function_exists( 'nvx_catalog_json_load' ) ) {
		return '';
	}

	$catalog = nvx_catalog_json_load( 'ads-conversion-catalog.json' );
	if ( 2 !== (int) ( $catalog['schema'] ?? 0 ) ) {
		return '';
	}

	$google = isset( $catalog['google_ads'] ) && is_array( $catalog['google_ads'] )
		? $catalog['google_ads']
		: array();
	$phone  = isset( $google['phone_whatsapp'] ) && is_array( $google['phone_whatsapp'] )
		? $google['phone_whatsapp']
		: array();

	if ( 'google_ads' !== (string) ( $google['authority'] ?? '' ) || '' === trim( (string) ( $google['owner'] ?? '' ) ) ) {
		return '';
	}

	$value = trim( (string) ( $phone['send_to'] ?? '' ) );
	return nvx_ads_is_send_to( $value ) ? $value : '';
}

/**
 * Browser-facing Ads click conversions.
 *
 * @return array{phone_whatsapp_send_to:string}
 */
function nvx_ads_conversion_client_context(): array {
	return array(
		'phone_whatsapp_send_to' => nvx_ads_phone_whatsapp_send_to(),
	);
}

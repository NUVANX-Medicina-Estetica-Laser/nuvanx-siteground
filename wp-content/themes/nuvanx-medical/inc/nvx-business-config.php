<?php
/**
 * NUVANX business configuration loader and clinic render helpers.
 *
 * Business contact, clinic identity, NAP, registrations, hours, coordinates
 * and route paths are owned by inc/data/clinics.json. This module contains
 * validation and rendering logic only.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Load and validate the canonical business registry.
 *
 * @return array<string,mixed>
 */
function nvx_get_business_config(): array {
	static $config = null;
	if ( is_array( $config ) ) {
		return $config;
	}

	$file = __DIR__ . '/data/clinics.json';
	if ( ! is_readable( $file ) ) {
		$config = array();
		return $config;
	}

	$json = file_get_contents( $file );
	$data = false !== $json ? json_decode( $json, true ) : null;
	if ( ! is_array( $data ) || 1 !== (int) ( $data['schema'] ?? 0 ) || ! is_array( $data['clinics'] ?? null ) ) {
		$config = array();
		return $config;
	}

	$email = trim( (string) ( $data['contact_email'] ?? '' ) );
	if ( '' === $email || ! is_email( $email ) ) {
		$config = array();
		return $config;
	}

	$config = $data;
	return $config;
}

/** Get the canonical public business contact email. */
function nvx_business_contact_email(): string {
	$config = nvx_get_business_config();
	return isset( $config['contact_email'] ) ? (string) $config['contact_email'] : '';
}

/**
 * Provides the canonical configuration for each clinic.
 *
 * @return array<string,array<string,mixed>>
 */
function nvx_get_clinics_config(): array {
	static $clinics = null;
	if ( is_array( $clinics ) ) {
		return $clinics;
	}

	$data = nvx_get_business_config();
	if ( ! is_array( $data['clinics'] ?? null ) ) {
		$clinics = array();
		return $clinics;
	}

	$required = array( 'name', 'reg', 'address', 'postal_code', 'locality', 'phone', 'phone_href', 'hours', 'opening_hours', 'latitude', 'longitude', 'landing_path', 'short_name' );
	$clinics  = array();
	foreach ( $data['clinics'] as $key => $clinic ) {
		if ( ! is_string( $key ) || ! is_array( $clinic ) ) {
			continue;
		}
		$valid = true;
		foreach ( $required as $field ) {
			if ( ! array_key_exists( $field, $clinic ) || ( is_string( $clinic[ $field ] ) && '' === trim( $clinic[ $field ] ) ) ) {
				$valid = false;
				break;
			}
		}
		if ( ! $valid || ! is_array( $clinic['opening_hours'] ) ) {
			continue;
		}

		$clinic['whatsapp_href'] = nvx_whatsapp_url_from_phone( (string) $clinic['phone_href'] );
		$clinics[ $key ]         = $clinic;
	}

	return $clinics;
}

/** Normalize a public route for exact clinic landing comparisons. */
function nvx_clinic_normalize_landing_path( string $path ): string {
	$without_query = strtok( $path, '?' );
	$path          = false === $without_query ? $path : $without_query;
	$normalized    = '/' . trim( $path, '/' ) . '/';

	return ( '/' === $normalized || '//' === $normalized ) ? '/' : $normalized;
}

/**
 * Resolve exactly one clinic key from clinics.json landing_path.
 *
 * Deliberately does not use slug substrings or nested-prefix matching: clinic
 * identity/NAP must never be inferred from a partial path.
 */
function nvx_clinic_key_from_landing_path( string $path ): ?string {
	$path = nvx_clinic_normalize_landing_path( $path );
	if ( '/' === $path ) {
		return null;
	}

	foreach ( nvx_get_clinics_config() as $key => $clinic ) {
		if ( ! is_string( $key ) || ! is_array( $clinic ) ) {
			continue;
		}

		$landing_path = nvx_clinic_normalize_landing_path( (string) ( $clinic['landing_path'] ?? '' ) );
		if ( '/' !== $landing_path && $path === $landing_path ) {
			return $key;
		}
	}

	return null;
}

/** Resolve the current clinic landing strictly from the immutable request path. */
function nvx_current_clinic_landing_key(): ?string {
	if ( ! function_exists( 'nvx_theme_request_path' ) ) {
		return null;
	}

	return nvx_clinic_key_from_landing_path( (string) nvx_theme_request_path() );
}

/**
 * Prevent the Sede Local template from rendering a guessed clinic identity.
 *
 * The clinics hub keeps its managed owner. An individual clinic template is
 * allowed only when the immutable request path exactly matches a landing_path
 * in clinics.json. Any unrelated/misassigned page falls back to page.php rather
 * than leaking Chamberí or Goya NAP.
 *
 * @param mixed $template Selected WordPress template path.
 * @return mixed
 */
function nvx_clinic_template_fail_closed( $template ) {
	$template = is_string( $template ) ? $template : '';
	if ( '' === $template || 'page-sede.php' !== basename( $template ) ) {
		return $template;
	}

	if ( function_exists( 'nvxIsClinicsHub' ) && nvxIsClinicsHub() ) {
		return $template;
	}

	$clinic_key = nvx_current_clinic_landing_key();
	if ( null !== $clinic_key ) {
		if ( function_exists( 'set_query_var' ) ) {
			set_query_var( 'nvx_clinic_key', $clinic_key );
		}
		return $template;
	}

	if ( function_exists( 'get_theme_file_path' ) ) {
		$page_template = (string) get_theme_file_path( '/page.php' );
		if ( '' !== $page_template ) {
			return $page_template;
		}
	}

	return $template;
}
add_filter( 'template_include', 'nvx_clinic_template_fail_closed', 99 );

/** Build a Google Maps embed URL from canonical clinic data. */
function nvx_clinic_map_embed_url( array $clinic ): string {
	$address = sprintf(
		'%s, %s, %s',
		(string) ( $clinic['address'] ?? '' ),
		(string) ( $clinic['postal_code'] ?? '' ),
		(string) ( $clinic['locality'] ?? '' )
	);
	return 'https://www.google.com/maps?q=' . rawurlencode( $address ) . '&output=embed';
}

/** Render one clinic map card from the canonical clinic configuration. */
function nvx_contact_map_card_markup( array $clinic ): string {
	$address = sprintf(
		'%s, %s, %s',
		(string) ( $clinic['address'] ?? '' ),
		(string) ( $clinic['postal_code'] ?? '' ),
		(string) ( $clinic['locality'] ?? '' )
	);
	$src   = nvx_clinic_map_embed_url( $clinic );
	$title = sprintf( esc_html__( 'Mapa %s', 'nuvanx-medical' ), (string) ( $clinic['name'] ?? 'NUVANX' ) );

	$html  = '<article class="nvx-contact-clinic nvx-location-map-card">';
	$html .= '<h3 class="nvx-contact-clinic__name">' . esc_html( (string) ( $clinic['name'] ?? '' ) ) . '</h3>';
	$html .= '<p class="nvx-contact-clinic__addr">' . esc_html( $address ) . '</p>';
	$html .= '<p class="nvx-contact-clinic__reg nvx-reg-copy">' . esc_html__( 'Registro sanitario', 'nuvanx-medical' ) . ': ' . esc_html( (string) ( $clinic['reg'] ?? '' ) ) . '</p>';
	$html .= '<div class="nvx-location-map-card__embed">';
	$html .= nvx_lazy_map_embed_markup( $src, $title, 'nvx-map-embed--contact' );
	$html .= '</div></article>';

	return $html;
}

/** Render the canonical Chamberí and Salamanca–Goya maps. */
function nvx_contact_maps_markup(): string {
	$clinics = nvx_get_clinics_config();
	$cards   = '';
	foreach ( array( 'chamberi', 'goya' ) as $key ) {
		if ( isset( $clinics[ $key ] ) && is_array( $clinics[ $key ] ) ) {
			$cards .= nvx_contact_map_card_markup( $clinics[ $key ] );
		}
	}
	if ( '' === $cards ) {
		return '';
	}

	return '<section class="nvx-brand-section nvx-contacto-maps" id="nvx-contacto-maps" aria-labelledby="nvx-contacto-maps-title"><div class="nvx-container"><p class="nvx-brand-kicker">' . esc_html__( 'Cómo llegar', 'nuvanx-medical' ) . '</p><h2 class="nvx-heading" id="nvx-contacto-maps-title">' . esc_html__( 'Nuestras ubicaciones en Madrid', 'nuvanx-medical' ) . '</h2><div class="nvx-contact-clinics">' . $cards . '</div></div></section>';
}

/** Append the maps once on the canonical Contacto page. */
function nvx_contact_append_maps( string $content ): string {
	if ( is_admin() || ! function_exists( 'nvx_is_contacto_page_request' ) || ! nvx_is_contacto_page_request() ) {
		return $content;
	}
	if ( false !== strpos( $content, 'nvx-contacto-maps' ) ) {
		return $content;
	}

	return $content . nvx_contact_maps_markup();
}
add_filter( 'the_content', 'nvx_contact_append_maps', NVX_HOOK_PRIO_CONTACT_MAPS );

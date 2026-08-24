<?php
/**
 * NUVANX Business Configuration
 *
 * Central source of truth for clinics data, phones, registration numbers and locations.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides the canonical configuration for each clinic.
 *
 * @return array<string, array{name: string, reg: string, address: string, postal_code: string, locality: string, phone: string, phone_href: string, whatsapp_href: string, hours: string, opening_hours: array<int, array{days: array<int, string>, opens: string, closes: string}>, days: string, latitude: float, longitude: float}>
 */
function nvx_get_clinics_config(): array {
	$clinics = array(
		'chamberi' => array(
			'name'          => 'Centro Clínico NUVANX Chamberí',
			'reg'           => 'CS20144',
			'address'       => 'Calle de Fernández de la Hoz, 4, Bajo Derecha',
			'postal_code'   => '28010',
			'locality'      => 'Madrid',
			'phone'         => '669 319 836',
			'phone_href'    => '+34669319836',
			'hours'         => 'lunes a sábado, 10:00–20:00',
			'opening_hours' => array(
				array(
					'days'   => array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' ),
					'opens'  => '10:00',
					'closes' => '20:00',
				),
			),
			'days'          => 'Martes y jueves',
			'latitude'      => 40.431204,
			'longitude'     => -3.693425,
		),
		'goya'     => array(
			'name'          => 'Centro Clínico NUVANX Salamanca–Goya',
			'reg'           => 'CS20073',
			'address'       => 'Calle de Fernán González, 26',
			'postal_code'   => '28009',
			'locality'      => 'Madrid',
			'phone'         => '647 505 107',
			'phone_href'    => '+34647505107',
			'hours'         => 'lunes a sábado, 11:00–20:00',
			'opening_hours' => array(
				array(
					'days'   => array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' ),
					'opens'  => '11:00',
					'closes' => '20:00',
				),
			),
			'days'          => 'Miércoles',
			'latitude'      => 40.423912,
			'longitude'     => -3.675648,
		),
	);

	foreach ( $clinics as &$clinic ) {
		$clinic['whatsapp_href'] = nvx_whatsapp_url_from_phone( (string) $clinic['phone_href'] );
	}
	unset( $clinic );

	return $clinics;
}

/** Render one clinic map card from the canonical clinic configuration. */
function nvx_contact_map_card_markup( array $clinic ): string {
	$address = sprintf(
		'%s, %s, %s',
		(string) ( $clinic['address'] ?? '' ),
		(string) ( $clinic['postal_code'] ?? '' ),
		(string) ( $clinic['locality'] ?? 'Madrid' )
	);
	$src   = 'https://www.google.com/maps?q=' . rawurlencode( $address ) . '&output=embed';
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
	// Guard against duplicate maps by checking for the section id
	// Note: This only sees content at priority 20. If MU producer injects outside the_content
	// or at a later priority, both sections would be emitted with duplicate DOM id.
	if ( false !== strpos( $content, 'nvx-contacto-maps' ) ) {
		return $content;
	}

	return $content . nvx_contact_maps_markup();
}
add_filter( 'the_content', 'nvx_contact_append_maps', NVX_HOOK_PRIO_CONTACT_MAPS );

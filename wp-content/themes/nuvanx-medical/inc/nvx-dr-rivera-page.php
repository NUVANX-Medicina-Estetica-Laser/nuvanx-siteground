<?php
/**
 * Dr. Rivera Tejeda — E-E-A-T Medical Authority Page.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detect Dr. Rivera page.
 */
function nvx_content_is_dr_rivera_page( string $content ): bool {
	if ( false !== strpos( $content, 'nvx-dr-rivera-editorial' ) ) {
		return false; // Already processed.
	}

	if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return false;
	}

	if ( ! is_singular( 'page' ) && ! is_page() ) {
		return false;
	}

	$path = function_exists( 'nvx_schema_current_path' )
		? nvx_schema_current_path( (int) get_queried_object_id() )
		: '';

	if (
		is_string( $path )
		&& function_exists( 'nvx_schema_path_matches' )
		&& nvx_schema_path_matches( $path, '/dr-javier-rivera-tejeda/' )
	) {
		return true;
	}

	return (bool) preg_match(
		'/aria-label=["\']Dr. Javier Rivera Tejeda["\']|id=["\']nvx-dr-rivera-h1["\']|class=["\'][^"\']*nvx-dr-rivera-hero/iu',
		$content
	);
}

/**
 * Replaces matching page content with Dr. Rivera's authority-page markup.
 *
 * @param string $content The original page content.
 * @return string The generated authority-page markup, or the original content when the page does not match.
 */
function nvx_content_dr_rivera_hijack( string $content ): string {
	if ( ! nvx_content_is_dr_rivera_page( $content ) ) {
		return $content;
	}

	$valuation_url = function_exists( 'nvx_cta_valoracion_url' ) ? nvx_cta_valoracion_url() : home_url( '/madrid/valoracion/' );

	require_once __DIR__ . '/nvx-catalog-json.php';
	$data = nvx_catalog_json_resolved( 'dr-rivera-page.json' );

	$director_name    = function_exists( 'nvx_medical_staff_name' ) ? nvx_medical_staff_name( 'director' ) : '';
	$director_license = function_exists( 'nvx_medical_colegiado' ) ? nvx_medical_colegiado( 'director' ) : '';
	$fallback_name    = '' !== $director_name ? $director_name : __( 'Director médico NUVANX', 'nuvanx-medical' );
	$fallback_lead    = '' !== $director_license
		? sprintf(
			/* translators: %s: medical license number */
			__( 'Nº Colegiado ICOMEM: %s · Especialista en Medicina Estética Láser e Ingeniería Tisular', 'nuvanx-medical' ),
			$director_license
		)
		: __( 'Especialista en Medicina Estética Láser e Ingeniería Tisular', 'nuvanx-medical' );

	// E-E-A-T Avatar and Manifest
	$avatar = esc_url( home_url( $data['avatar'] ?? '/wp-content/themes/nuvanx-medical/assets/images/dr-rivera-avatar.jpg' ) );

	$html = '<div class="nvx-dr-rivera-editorial">';

	$html .= '<div class="nvx-dr-rivera-header">';

	$avatar_path = ABSPATH . ltrim( wp_parse_url( $avatar, PHP_URL_PATH ), '/' );
	if ( is_readable( $avatar_path ) ) {
		$html .= '<img src="' . $avatar . '" alt="' . esc_attr( $data['h1'] ?? $fallback_name ) . '" class="nvx-dr-rivera-avatar" width="160" height="160" loading="lazy" decoding="async">';
	}
	$html .= '<p class="nvx-brand-kicker nvx-dr-rivera-kicker">' . esc_html( $data['kicker'] ?? __( 'Dirección Médica NUVANX', 'nuvanx-medical' ) ) . '</p>';
	$html .= '<h1 class="nvx-brand-hero__title" id="nvx-dr-rivera-h1">' . esc_html( $data['h1'] ?? $fallback_name ) . '</h1>';
	$html .= '<p class="nvx-brand-hero__lead nvx-dr-rivera-lead">' . esc_html( $data['lead'] ?? $fallback_lead ) . '</p>';
	$html .= '</div>';

	// Manifiesto Clínico
	if ( ! empty( $data['manifesto'] ) ) {
		$html .= '<blockquote class="nvx-blockquote">';
		$html .= '<p>' . esc_html( $data['manifesto'] ) . '</p>';
		$html .= '<footer><cite>' . esc_html( $fallback_name ) . '</cite></footer>';
		$html .= '</blockquote>';
	}

	if ( ! empty( $data['intro'] ) ) {
		$html .= '<div class="nvx-dr-rivera-body">';
		$html .= '<p>' . esc_html( $data['intro'] ) . '</p>';
		$html .= '</div>';
	}

	if ( ! empty( $data['procedures'] ) && is_array( $data['procedures'] ) ) {
		$html .= '<h2 class="nvx-dr-rivera-list-title">' . esc_html( $data['procedures_title'] ?? __( 'Procedimientos de Alta Complejidad Ejecutados Personalmente:', 'nuvanx-medical' ) ) . '</h2>';
		$html .= '<ul class="nvx-dr-rivera-list">';
		foreach ( $data['procedures'] as $proc ) {
			$title = isset( $proc['title'] ) ? (string) $proc['title'] : '';
			$body  = isset( $proc['body'] ) ? (string) $proc['body'] : '';
			if ( '' === $title && '' === $body ) {
				continue;
			}
			$html .= '<li>';
			if ( '' !== $title ) {
				$html .= '<strong>' . esc_html( $title ) . '</strong> ';
			}
			if ( '' !== $body ) {
				$html .= esc_html( $body );
			}
			$html .= '</li>';
		}
		$html .= '</ul>';
	}

	// CTA
	if ( function_exists( 'nvx_cta_pair_markup' ) ) {
		$html .= '<div class="nvx-dr-rivera-cta">';
		$html .= nvx_cta_pair_markup( 'nvx-dr-rivera-hero-ctas nvx-home-hero-ctas' );
		$html .= '</div>';
	} else {
		$html .= '<p class="nvx-dr-rivera-cta"><a href="' . esc_url( $valuation_url ) . '" class="nvx-brand-btn">' . esc_html__( 'Iniciar mi valoración médica', 'nuvanx-medical' ) . '</a></p>';
	}

	$html .= '</div>';

	return $html;
}
add_filter( 'the_content', 'nvx_content_dr_rivera_hijack', NVX_HOOK_PRIO_DR_RIVERA );

<?php
/**
 * Medicina Estética Láser hub — fully theme-owned.
 *
 * Route: /medicina-estetica-laser/ (slug-gated). CMS body is ignored.
 * Wire-frame: unified .nvx-brand-hero → approach → platforms → FAQ.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether the current request is the laser medicine hub page.
 */
function nvx_laser_is_hub_request(): bool {
	if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return false;
	}
	if ( ! is_page() ) {
		return false;
	}

	$slug = (string) get_post_field( 'post_name', get_queried_object_id() );
	if ( 'medicina-estetica-laser' === $slug ) {
		return true;
	}

	$path = function_exists( 'nvx_schema_current_path' )
		? nvx_schema_current_path( (int) get_queried_object_id() )
		: '';

	return is_string( $path )
		&& function_exists( 'nvx_schema_path_matches' )
		&& nvx_schema_path_matches( $path, '/medicina-estetica-laser/' );
}



/**
 * Resolve a public page URL by path, with home_url fallback.
 *
 * @param string $path Relative path without domain.
 */
function nvx_laser_page_url( string $path ): string {
	$path = trim( $path, '/' );
	$page = get_page_by_path( $path );

	if ( $page instanceof WP_Post && 'publish' === $page->post_status ) {
		$url = get_permalink( $page );
		if ( is_string( $url ) && '' !== $url ) {
			return $url;
		}
	}

	return home_url( '/' . $path . '/' );
}

/**
 * Linear premium icons — stroke 1.5px, 32×32 box.
 *
 * @param string $name Icon key.
 */
function nvx_laser_icon( string $name ): string {
	$icons = array(
		'spectrum' => '<svg class="nvx-laser-icon" viewBox="0 0 32 32" width="32" height="32" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><circle cx="16" cy="16" r="4" stroke="currentColor" stroke-width="1.5"/><path d="M16 4v5M16 23v5M4 16h5M23 16h5M7.5 7.5l3.5 3.5M21 21l3.5 3.5M24.5 7.5 21 11M11 21l-3.5 3.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
		'dose'     => '<svg class="nvx-laser-icon" viewBox="0 0 32 32" width="32" height="32" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M6 22 16 6l10 16" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M10 22h12M12 26h8M14 30h4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
		'nature'   => '<svg class="nvx-laser-icon" viewBox="0 0 32 32" width="32" height="32" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M16 28V14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M16 24c-6 0-10-3.5-10-8.5 6 0 10 3.5 10 8.5Z" stroke="currentColor" stroke-width="1.5"/><path d="M16 21c6 0 10-3.5 10-8.5-6 0-10 3.5-10 8.5Z" stroke="currentColor" stroke-width="1.5"/><path d="M11 10c3-3 6-4.5 9-4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
		'fiber'    => '<svg class="nvx-laser-icon" viewBox="0 0 32 32" width="32" height="32" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M6 24 16 6l4 3-10 18H6v-3Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M14 10l4 3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
		'rf'       => '<svg class="nvx-laser-icon" viewBox="0 0 32 32" width="32" height="32" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M8 22c3-7 5-10 8-10s5 3 8 10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M10 14c2-1.5 4-2.5 6-2.5s4 1 6 2.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><circle cx="16" cy="20" r="2" stroke="currentColor" stroke-width="1.5"/></svg>',
		'co2'      => '<svg class="nvx-laser-icon" viewBox="0 0 32 32" width="32" height="32" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect x="6" y="6" width="20" height="20" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M11 16h10M16 11v10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><circle cx="11" cy="11" r="1.25" stroke="currentColor" stroke-width="1.5"/><circle cx="21" cy="11" r="1.25" stroke="currentColor" stroke-width="1.5"/><circle cx="11" cy="21" r="1.25" stroke="currentColor" stroke-width="1.5"/><circle cx="21" cy="21" r="1.25" stroke="currentColor" stroke-width="1.5"/></svg>',
	);

	return $icons[ $name ] ?? $icons['spectrum'];
}

/**
 * Hero dual CTA: valoración + sedes.
 */
function nvx_laser_hero_ctas_markup(): string {
	$valoracion = function_exists( 'nvx_cta_valoracion_url' )
		? nvx_cta_valoracion_url()
		: home_url( '/madrid/valoracion/' );
	$clinicas   = home_url( '/clinicas-de-medicina-estetica-nuvanx/' );

	$html  = '<div class="nvx-brand-actions">';
	$html .= sprintf(
		'<a class="nvx-brand-btn nvx-brand-btn--primary" href="%1$s">%2$s</a>',
		esc_url( $valoracion ),
		esc_html__( 'Valoración gratuita — sin compromiso', 'nuvanx-medical' )
	);
	$html .= sprintf(
		'<a class="nvx-brand-btn nvx-brand-btn--secondary" href="%1$s">%2$s</a>',
		esc_url( $clinicas ),
		esc_html__( 'Ver clínicas en Madrid', 'nuvanx-medical' )
	);
	$html .= '</div>';

	return $html;
}

/**
 * Unified brand hero — same shell as Endolift® / medicina-estética (still media, no video).
 */
function nvx_laser_hero_markup(): string {
	$colegiado = function_exists( 'nvx_medical_colegiado' ) ? nvx_medical_colegiado( 'director' ) : '';
	$clinics = function_exists( 'nvx_get_clinics_config' ) ? nvx_get_clinics_config() : array();
	$chamberi_reg = (string) ( $clinics['chamberi']['reg'] ?? '' );
	$goya_reg = (string) ( $clinics['goya']['reg'] ?? '' );
	$chamberi_name = (string) ( $clinics['chamberi']['short_name'] ?? '' );
	$goya_name = (string) ( $clinics['goya']['short_name'] ?? '' );
	$clinic_meta = $chamberi_name . ' (' . $chamberi_reg . ') · ' . $goya_name . ' (' . $goya_reg . ') · Indicación médica personalizada';

	// Prefer featured image so this hub matches photo brand-heroes on peer routes.
	$media = '';
	if ( function_exists( 'nvx_hero_featured_media_figure' ) ) {
		$media = nvx_hero_featured_media_figure();
	}

	$html  = '<section class="nvx-brand-hero" aria-labelledby="nvx-laser-h1">';
	$html .= '<div class="nvx-brand-hero__inner">';
	$html .= '<div class="nvx-brand-hero__copy">';
	$html .= '<p class="nvx-brand-kicker">' . esc_html__( 'NUVANX · Tecnología médica de precisión', 'nuvanx-medical' ) . '</p>';
	$html .= '<h1 class="nvx-brand-hero__title" id="nvx-laser-h1">' . esc_html__( 'Medicina Estética Láser Avanzada en Madrid', 'nuvanx-medical' ) . '</h1>';
	$html .= '<p class="nvx-brand-hero__lead">' . esc_html__( 'Plataformas de energía selectiva calibradas con rigor clínico para redefinir el contorno, restaurar la firmeza dermoepidérmica y renovar la textura de la piel sin cirugía.', 'nuvanx-medical' ) . '</p>';
	$html .= '<p class="nvx-brand-hero__description">' . esc_html(
		sprintf(
			/* translators: %s: medical license number */
			__( 'Bajo la dirección médica del Dr. José Javier Rivera Tejeda (Nº Colegiado ICOMEM %s), diseñamos protocolos que combinan la biofísica de la luz y la estimulación celular profunda para lograr resultados estables y elegantes.', 'nuvanx-medical' ),
			$colegiado
		)
	) . '</p>';
	$html .= nvx_laser_hero_ctas_markup();
	$html .= '<p class="nvx-brand-meta nvx-reg-copy">' . esc_html( $clinic_meta ) . '</p>';
	$html .= '</div>';
	$html .= $media;
	$html .= '</div></section>';

	return $html;
}

/**
 * Canonical static data for the laser medicine editorial hub.
 *
 * @return array<string, array<mixed>>
 */
function nvx_laser_editorial_catalog(): array {
	static $catalog = null;

	if ( is_array( $catalog ) ) {
		return $catalog;
	}

	require_once __DIR__ . '/nvx-catalog-json.php';
	$catalog = nvx_catalog_json_resolved(
		'laser-medicine-page.json',
		null,
		array(),
		array(
			'@nvx-laser-url' => static function ( $path ) {
				return nvx_laser_page_url( is_string( $path ) ? $path : '' );
			},
		),
		'laser-medicine-page'
	);

	return $catalog;
}

/**
 * Full editorial body from theme JSON catalog.
 */
function nvx_laser_editorial_body_markup(): string {
	// Sections are direct children of .nvx-brand-page (same as Endolift® / med hubs).
	$html = '';

	$html .= '<section class="nvx-brand-section nvx-laser-focus" aria-labelledby="nvx-laser-focus-title">';
	$html .= '<div class="nvx-brand-section__inner">';
	$html .= '<p class="nvx-brand-kicker">' . esc_html__( 'El enfoque', 'nuvanx-medical' ) . '</p>';
	$html .= '<h2 id="nvx-laser-focus-title" class="nvx-brand-title">' . esc_html__( 'La diferencia entre tecnología e indicación médica', 'nuvanx-medical' ) . '</h2>';
	$html .= '<div class="nvx-laser-focus-grid">';

	$pillars    = nvx_laser_editorial_catalog()['pillars'] ?? array();
	$pillar_idx = 0;
	foreach ( $pillars as $pillar ) {
		if ( ! is_array( $pillar ) ) {
			continue;
		}
		$pid   = 'nvx-laser-pillar-' . $pillar_idx;
		$html .= '<article class="nvx-laser-pillar" aria-labelledby="' . esc_attr( $pid ) . '">';
		$html .= nvx_laser_icon( isset( $pillar['icon'] ) ? (string) $pillar['icon'] : 'spectrum' );
		$html .= '<h3 id="' . esc_attr( $pid ) . '" class="nvx-laser-pillar__title">' . esc_html( (string) ( $pillar['title'] ?? '' ) ) . '</h3>';
		$html .= '<p class="nvx-brand-lead">' . esc_html( (string) ( $pillar['body'] ?? '' ) ) . '</p>';
		$html .= '</article>';
		++$pillar_idx;
	}

	$html .= '</div></div></section>';

	$html .= '<section class="nvx-brand-section nvx-laser-platforms" id="plataformas" aria-labelledby="nvx-laser-platforms-title">';
	$html .= '<div class="nvx-brand-section__inner">';
	$html .= '<p class="nvx-brand-kicker">' . esc_html__( 'Nuestras plataformas clínicas', 'nuvanx-medical' ) . '</p>';
	$html .= '<h2 id="nvx-laser-platforms-title" class="nvx-brand-title">' . esc_html__( 'Tecnologías médicas de precisión', 'nuvanx-medical' ) . '</h2>';
	$html .= '<div class="nvx-laser-platform-list">';

	$platforms = nvx_laser_editorial_catalog()['platforms'] ?? array();
	$plat_idx  = 0;
	foreach ( $platforms as $platform ) {
		if ( ! is_array( $platform ) ) {
			continue;
		}
		$url        = isset( $platform['url'] ) && is_string( $platform['url'] ) ? $platform['url'] : '#';
		$plat_title = (string) ( $platform['title'] ?? '' );
		$pid        = 'nvx-laser-platform-' . $plat_idx;

		$html .= '<article class="nvx-laser-platform" aria-labelledby="' . esc_attr( $pid ) . '">';
		$html .= '<div class="nvx-laser-platform__main">';
		$html .= '<div class="nvx-laser-platform__head">';
		$html .= nvx_laser_icon( isset( $platform['icon'] ) ? (string) $platform['icon'] : 'spectrum' );
		$html .= '<p class="nvx-laser-platform__n">' . esc_html( (string) ( $platform['n'] ?? '' ) ) . '</p>';
		$html .= '</div>';
		$html .= '<h3 id="' . esc_attr( $pid ) . '" class="nvx-laser-platform__title">' . esc_html( $plat_title ) . '</h3>';
		$html .= '<p class="nvx-brand-lead">' . esc_html( (string) ( $platform['body'] ?? '' ) ) . '</p>';
		$html .= '<p class="nvx-laser-platform__link-wrap"><a class="nvx-brand-inline-link" href="' . esc_url( $url ) . '" aria-label="' . esc_attr( sprintf( __( 'Ver protocolo clínico: %s', 'nuvanx-medical' ), $plat_title ) ) . '">' . esc_html__( 'Ver protocolo clínico', 'nuvanx-medical' ) . '</a></p>';
		$html .= '</div>';
		$html .= '<aside class="nvx-laser-platform__meta" aria-label="' . esc_attr( sprintf( __( 'Indicación y recuperación: %s', 'nuvanx-medical' ), $plat_title ) ) . '">';
		$html .= '<p class="nvx-laser-meta-label">' . esc_html__( 'Objetivo clínico', 'nuvanx-medical' ) . '</p>';
		$html .= '<p class="nvx-brand-lead">' . esc_html( (string) ( $platform['goal'] ?? '' ) ) . '</p>';
		$html .= '<p class="nvx-laser-meta-label nvx-laser-meta-label--spaced">' . esc_html__( 'Recuperación', 'nuvanx-medical' ) . '</p>';
		$html .= '<p class="nvx-brand-lead">' . esc_html( (string) ( $platform['recover'] ?? '' ) ) . '</p>';
		$html .= '</aside></article>';
		++$plat_idx;
	}

	$html .= '</div></div></section>';

	$html .= '<section class="nvx-brand-section nvx-laser-faq" aria-labelledby="nvx-laser-faq-title">';
	$html .= '<div class="nvx-brand-section__inner">';
	$html .= '<p class="nvx-brand-kicker">' . esc_html__( 'Preguntas clínicas', 'nuvanx-medical' ) . '</p>';
	$html .= '<h2 id="nvx-laser-faq-title" class="nvx-brand-title">' . esc_html__( 'Rigor biológico sobre medicina estética láser', 'nuvanx-medical' ) . '</h2>';
	$html .= '<div class="nvx-faq nvx-laser-faq-list">';

	$html .= '<details class="nvx-brand-faq-item" open>';
	$html .= '<summary><span>' . esc_html__( '¿Cómo funciona la fototermólisis selectiva y cómo evita el láser dañar la superficie de la piel?', 'nuvanx-medical' ) . '</span></summary>';
	$html .= '<div class="nvx-brand-faq-content">';
	$html .= '<p>' . esc_html__( 'El principio fundamental de la medicina estética láser en NUVANX es la fototermólisis selectiva. Consiste en la entrega de una longitud de onda de luz específica orientada a calentar un cromóforo diana (como la melanina en las manchas o el agua en las células de la dermis) sin dañar los tejidos circundantes. Para lograrlo, el ancho de pulso del láser debe ser estrictamente menor o igual al tiempo de relajación térmica del objetivo de tratamiento. El tiempo de relajación térmica (τᵣ) se define mediante la siguiente relación física:', 'nuvanx-medical' ) . '</p>';
	$html .= '<figure class="nvx-laser-formula">';
	$html .= '<p class="nvx-laser-formula__eq" aria-hidden="true"><span class="nvx-laser-formula__tau">τ<sub>r</sub></span> = <span class="nvx-laser-formula__frac"><span class="nvx-laser-formula__num">d<sup>2</sup></span><span class="nvx-laser-formula__den">4α</span></span></p>';
	$html .= '<figcaption class="nvx-laser-formula__cap">' . esc_html__( 'Donde d representa el diámetro de la estructura celular objetivo (como un haz de colágeno o un vaso capilar) y α corresponde a la difusividad térmica del tejido. Al programar pulsos de energía extremadamente rápidos por debajo de este límite, el calor se confina en la diana biológica y se disipa antes de propagarse a las capas epidérmicas superficiales, reduciendo el riesgo de quemaduras y optimizando la seguridad del paciente.', 'nuvanx-medical' ) . '</figcaption>';
	$html .= '</figure></div></details>';

	$faqs = nvx_laser_editorial_catalog()['faqs'] ?? array();
	foreach ( $faqs as $faq ) {
		if ( ! is_array( $faq ) || empty( $faq['q'] ) || empty( $faq['a'] ) ) {
			continue;
		}
		$html .= '<details class="nvx-brand-faq-item">';
		$html .= '<summary><span>' . esc_html( (string) $faq['q'] ) . '</span></summary>';
		$html .= '<div class="nvx-brand-faq-content"><p>' . esc_html( (string) $faq['a'] ) . '</p></div>';
		$html .= '</details>';
	}

	$html .= '</div></div></section>';

	return $html;
}

/**
 * Full theme-owned laser hub page markup.
 */
function nvx_laser_hub_page_markup(): string {
	// Use standard wrapper like soluciones-medicas for consistent margins
	$standard_wrapper = '<div class="entry-content nvx-page__content">';
	return $standard_wrapper
		. nvx_laser_hero_markup()
		. nvx_laser_editorial_body_markup()
		. '</div>';
}

/**
 * Replace the laser hub route with theme-owned markup. CMS body is ignored.
 *
 * @param string $content Existing post content (unused on hub).
 */
add_filter(
	'nvx_page_owner',
	function ( $owner ) {
		if ( ! empty( $owner ) ) {
			return $owner;
		}
		if ( function_exists( 'nvx_laser_is_hub_request' ) && nvx_laser_is_hub_request() ) {
			return 'nvx_laser_medicine_page';
		}
		return $owner;
	}
);

function nvx_content_restructure_laser_medicine_page( string $content ): string {
	$owner = function_exists( 'nvx_get_page_owner' ) ? nvx_get_page_owner() : null;
	if ( $owner !== 'nvx_laser_medicine_page' ) {
		return $content;
	}

	return nvx_laser_hub_page_markup();
}
add_filter( 'the_content', 'nvx_content_restructure_laser_medicine_page', NVX_HOOK_PRIO_LASER_MEDICINE );

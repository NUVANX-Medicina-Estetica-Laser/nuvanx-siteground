<?php
/**
 * Clinics hub: public-facing markup, phone formatting, and content_filter.
 *
 * DOM manipulation utilities are in nvx-clinics-dom-helpers.php (loaded first).
 *
 * @package NUVANX
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/nvx-clinics-dom-helpers.php';

/**
 * Whether hub post_content is theme-owned (marker or empty of visible text).
 */
function nvx_clinics_hub_is_managed_content( string $content ): bool {
	if ( false !== strpos( $content, 'NUVANX_GITHUB_MANAGED:clinics-hub' ) ) {
		return true;
	}

	$text = trim( wp_strip_all_tags( $content ) );
	return '' === $text;
}

/**
 * Format E.164 phone for display (ES mobile without +34, spaced).
 */
function nvx_clinics_hub_phone_display( string $e164 ): string {
	$digits = preg_replace( '/^\+34/', '', $e164 );
	$digits = is_string( $digits ) ? preg_replace( '/\D+/', '', $digits ) : '';
	if ( ! is_string( $digits ) || '' === $digits ) {
		return $e164;
	}

	return trim( chunk_split( $digits, 3, ' ' ) );
}

/**
 * Approved equipment imagery for the educational clinics-hub section only.
 *
 * These assets identify technology in general and are intentionally not used
 * as proof that a specific device is installed at either physical sede, nor in
 * GBP or the individual clinic landing galleries.
 *
 * @return array<int,array{uploads_path:string,title:string,alt:string,description:string}>
 */
function nvx_clinics_hub_equipment_catalog(): array {
	return array(
		array(
			'uploads_path' => '2026/08/endolift-lasemar-1500-eufoton.webp',
			'title'        => __( 'Endolift LaseMAR 1500 · Eufoton', 'nuvanx-medical' ),
			'alt'          => __( 'Equipo Endolift LaseMAR 1500 de Eufoton', 'nuvanx-medical' ),
			'description'  => __( 'Plataforma láser identificada como Endolift LaseMAR 1500 de Eufoton. La indicación y el plan de tratamiento se valoran de forma individual en consulta médica.', 'nuvanx-medical' ),
		),
		array(
			'uploads_path' => '2026/08/BTL-Exion-Mobile-Version-1024x956-1.png',
			'title'        => __( 'BTL EXION®', 'nuvanx-medical' ),
			'alt'          => __( 'Plataforma BTL EXION', 'nuvanx-medical' ),
			'description'  => __( 'Plataforma BTL EXION®. La tecnología aplicable se decide tras la valoración médica y según el objetivo clínico de cada persona.', 'nuvanx-medical' ),
		),
		array(
			'uploads_path' => '2026/08/Endolift-ISO9001-Laser.webp',
			'title'        => __( 'Endolift®', 'nuvanx-medical' ),
			'alt'          => __( 'Sistema láser Endolift', 'nuvanx-medical' ),
			'description'  => __( 'Sistema láser identificado como Endolift®. Su uso se determina únicamente después de una evaluación médica presencial.', 'nuvanx-medical' ),
		),
		array(
			'uploads_path' => '2026/08/SmartLipo-for-Laserlipolysis-DEKA-1.png',
			'title'        => __( 'SmartLipo® · DEKA', 'nuvanx-medical' ),
			'alt'          => __( 'Equipo SmartLipo de DEKA para láser lipólisis', 'nuvanx-medical' ),
			'description'  => __( 'Equipo SmartLipo® de DEKA, identificado como plataforma de láser para lipólisis. La candidatura se define de forma individual por el equipo médico.', 'nuvanx-medical' ),
		),
		array(
			'uploads_path' => '2026/08/ipl-exilite-luz-pulsada.webp',
			'title'        => __( 'IPL EXILITE™', 'nuvanx-medical' ),
			'alt'          => __( 'Equipo IPL EXILITE de luz pulsada', 'nuvanx-medical' ),
			'description'  => __( 'Equipo IPL EXILITE™, sistema de luz pulsada. La valoración médica determina si esta tecnología resulta adecuada en cada caso.', 'nuvanx-medical' ),
		),
		array(
			'uploads_path' => '2026/08/Emfusion-btl-lentigo-aranitas-vasculares-punto-de-rubi-marcas-de-acne.png',
			'title'        => __( 'EMFUSION™ · BTL', 'nuvanx-medical' ),
			'alt'          => __( 'Plataforma EMFUSION de BTL', 'nuvanx-medical' ),
			'description'  => __( 'Plataforma EMFUSION™ de BTL. La selección de tecnología forma parte de la valoración médica y del plan individualizado.', 'nuvanx-medical' ),
		),
		array(
			'uploads_path' => '2026/08/SMARTXIDE-DOT_EQUIPO-TOUCH-DEKA-LASER-CO2-FRACCIONAL.png',
			'title'        => __( 'SmartXide DOT® · DEKA', 'nuvanx-medical' ),
			'alt'          => __( 'Equipo SmartXide DOT de DEKA, láser CO2 fraccionado', 'nuvanx-medical' ),
			'description'  => __( 'Plataforma SmartXide DOT® de DEKA, identificada como láser CO₂ fraccionado. La indicación se establece tras una evaluación médica presencial.', 'nuvanx-medical' ),
		),
	);
}

/**
 * Render one readable local uploads asset. Missing or unreadable media is not
 * emitted, so the acceptance gate can fail rather than silently substituting a
 * third-party, cross-origin or location-specific image.
 *
 * @param array{uploads_path:string,title:string,alt:string,description:string} $equipment Equipment entry.
 */
function nvx_clinics_hub_equipment_image_markup( array $equipment ): string {
	$uploads_path = ltrim( (string) ( $equipment['uploads_path'] ?? '' ), '/' );
	if ( '' === $uploads_path || str_contains( $uploads_path, '../' ) ) {
		return '';
	}

	$uploads     = wp_get_upload_dir();
	$source_path = trailingslashit( (string) $uploads['basedir'] ) . $uploads_path;
	$image_size  = is_readable( $source_path ) ? wp_getimagesize( $source_path ) : false;
	if ( ! is_array( $image_size ) || empty( $image_size[0] ) || empty( $image_size[1] ) ) {
		return '';
	}

	$url = trailingslashit( (string) $uploads['baseurl'] ) . $uploads_path;
	return sprintf(
		'<img class="nvx-media nvx-clinics-equipment__image" src="%1$s" width="%2$d" height="%3$d" alt="%4$s" loading="lazy" decoding="async">',
		esc_url( $url ),
		(int) $image_size[0],
		(int) $image_size[1],
		esc_attr( (string) $equipment['alt'] )
	);
}

/** Render the narrow, approved educational equipment section for the hub. */
function nvx_clinics_hub_equipment_section_markup(): string {
	$catalog = nvx_clinics_hub_equipment_catalog();
	$cards   = array();

	foreach ( $catalog as $equipment ) {
		$image = nvx_clinics_hub_equipment_image_markup( $equipment );
		if ( '' === $image ) {
			// Never conceal a missing governed asset with a shortened equipment grid.
			return nvx_clinics_hub_equipment_unavailable_markup();
		}
		$card  = '<article class="nvx-brand-card nvx-clinics-equipment__card">';
		$card .= '<figure class="nvx-brand-card__media nvx-clinics-equipment__media">' . $image . '</figure>';
		$card .= '<h3 class="nvx-brand-card__title">' . esc_html( (string) $equipment['title'] ) . '</h3>';
		$card .= '<p class="nvx-brand-card__body">' . esc_html( (string) $equipment['description'] ) . '</p>';
		$card .= '</article>';
		$cards[] = $card;
	}

	if ( 7 !== count( $catalog ) || 7 !== count( $cards ) ) {
		return nvx_clinics_hub_equipment_unavailable_markup();
	}

	$html  = '<section class="nvx-brand-section nvx-clinics-equipment" data-nvx-approved-equipment-section="clinic-hub-v1" aria-labelledby="nvx-clinics-equipment-title">';
	$html .= '<div class="nvx-brand-section__inner">';
	$html .= '<p class="nvx-brand-kicker">' . esc_html__( 'Tecnología clínica', 'nuvanx-medical' ) . '</p>';
	$html .= '<h2 id="nvx-clinics-equipment-title" class="nvx-brand-title">' . esc_html__( 'Equipos con los que trabajamos', 'nuvanx-medical' ) . '</h2>';
	$html .= '<p class="nvx-brand-lead">' . esc_html__( 'Esta selección identifica plataformas tecnológicas utilizadas en nuestra práctica. No asigna un equipo concreto a una sede; la disponibilidad y la indicación se confirman siempre durante la valoración médica.', 'nuvanx-medical' ) . '</p>';
	$html .= '<div class="nvx-brand-grid nvx-brand-grid--4 nvx-clinics-equipment__grid">' . implode( '', $cards ) . '</div>';
	$html .= '</div></section>';
	return $html;
}

/** Render a visible state when the governed seven-equipment contract is incomplete. */
function nvx_clinics_hub_equipment_unavailable_markup(): string {
	$html  = '<section class="nvx-brand-section nvx-clinics-equipment" data-nvx-approved-equipment-section="incomplete" aria-labelledby="nvx-clinics-equipment-title">';
	$html .= '<div class="nvx-brand-section__inner">';
	$html .= '<h2 id="nvx-clinics-equipment-title" class="nvx-brand-title">' . esc_html__( 'Información de equipos temporalmente no disponible', 'nuvanx-medical' ) . '</h2>';
	$html .= '<p class="nvx-brand-lead">' . esc_html__( 'Estamos verificando los recursos de esta sección. La selección completa se publicará de nuevo cuando estén disponibles las siete fichas aprobadas.', 'nuvanx-medical' ) . '</p>';
	$html .= '</div></section>';
	return $html;
}

/**
 * Builds the canonical NUVANX clinics hub markup with clinic details, contact links, directions, and valuation calls to action.
 *
 * @return string The complete rendered clinics hub HTML.
 */
function nvx_clinics_hub_page_markup(): string {
	// Global flag to prevent duplicate hero media injection from nvx_ensure_hero_featured_media
	global $nvx_page_shell_has_hero;
	$nvx_page_shell_has_hero = true;

	$clinics  = function_exists( 'nvx_schema_clinics' ) ? nvx_schema_clinics() : array();
	$registry = function_exists( 'nvx_schema_page_registry' ) ? nvx_schema_page_registry() : array();
	$config   = function_exists( 'nvx_get_clinics_config' ) ? nvx_get_clinics_config() : array();

	$chamberi_path = isset( $registry['clinics']['chamberi']['path'] )
		? (string) $registry['clinics']['chamberi']['path']
		: (string) ( $config['chamberi']['landing_path'] ?? '' );
	$goya_path     = isset( $registry['clinics']['goya']['path'] )
		? (string) $registry['clinics']['goya']['path']
		: (string) ( $config['goya']['landing_path'] ?? '' );

	$chamberi_phone = ! empty( $clinics['chamberi']['telephone'] ) ? (string) $clinics['chamberi']['telephone'] : '';
	$goya_phone     = ! empty( $clinics['goya']['telephone'] ) ? (string) $clinics['goya']['telephone'] : '';
	$chamberi_maps  = ! empty( $clinics['chamberi']['hasMap'] ) ? (string) $clinics['chamberi']['hasMap'] : nvxClinicsMapUrl( 'chamberi' );
	$goya_maps      = ! empty( $clinics['goya']['hasMap'] ) ? (string) $clinics['goya']['hasMap'] : nvxClinicsMapUrl( 'goya' );
	$chamberi_url   = home_url( $chamberi_path );
	$goya_url       = home_url( $goya_path );
	$valoracion     = home_url( '/madrid/valoracion/' );

	$chamberi_tel_disp = ! empty( $config['chamberi']['phone'] )
		? (string) $config['chamberi']['phone']
		: nvx_clinics_hub_phone_display( $chamberi_phone );
	$goya_tel_disp     = ! empty( $config['goya']['phone'] )
		? (string) $config['goya']['phone']
		: nvx_clinics_hub_phone_display( $goya_phone );

	$chamberi_wa = ! empty( $config['chamberi']['whatsapp_href'] ) ? (string) $config['chamberi']['whatsapp_href'] : 'https://wa.me/' . preg_replace( '/\D/', '', $chamberi_phone );
	$goya_wa     = ! empty( $config['goya']['whatsapp_href'] ) ? (string) $config['goya']['whatsapp_href'] : 'https://wa.me/' . preg_replace( '/\D/', '', $goya_phone );

	$chamberi_hours = ! empty( $config['chamberi']['hours'] ) ? (string) $config['chamberi']['hours'] : __( 'lunes a sábado, 10:00–20:00', 'nuvanx-medical' );
	$goya_hours     = ! empty( $config['goya']['hours'] ) ? (string) $config['goya']['hours'] : __( 'lunes a sábado, 11:00–20:00', 'nuvanx-medical' );

	$chamberi_addr = ! empty( $config['chamberi']['address'] ) ? sprintf( '%s, %s %s', $config['chamberi']['address'], $config['chamberi']['postal_code'], $config['chamberi']['locality'] ) : '';
	$goya_addr     = ! empty( $config['goya']['address'] ) ? sprintf( '%s, %s %s', $config['goya']['address'], $config['goya']['postal_code'], $config['goya']['locality'] ) : '';

	$html  = '<div class="nvx-brand-page nvx-clinics-hub-page">';
	$html .= '<section class="nvx-brand-hero" aria-labelledby="nvx-clinics-hub-h1">';
	$html .= '<div class="nvx-brand-hero__inner"><div class="nvx-brand-hero__copy">';
	$html .= '<p class="nvx-brand-kicker">' . esc_html__( 'Clínicas NUVANX · Madrid', 'nuvanx-medical' ) . '</p>';
	$html .= '<h1 id="nvx-clinics-hub-h1" class="nvx-brand-hero__title">' . esc_html__( 'Clínicas NUVANX Medicina Estética Láser en Madrid', 'nuvanx-medical' ) . '</h1>';
	$html .= '<p class="nvx-brand-hero__lead">' . esc_html__( 'Dos centros sanitarios autorizados, una sola dirección médica. Chamberí y Salamanca–Goya con el mismo criterio clínico, protocolos láser y valoración presencial antes de cualquier tratamiento.', 'nuvanx-medical' ) . '</p>';
	$html .= '<div class="nvx-brand-actions nvx-clinics-hub-actions">';
	$html .= '<a class="nvx-brand-btn nvx-brand-btn--primary" href="' . esc_url( $valoracion ) . '">' . esc_html__( 'Valoración gratuita — sin compromiso', 'nuvanx-medical' ) . '</a>';
	$html .= '</div>';
	$html .= '<p class="nvx-brand-meta nvx-reg-copy">' . esc_html( sprintf( __( '%1$s %2$s · %3$s %4$s · Medicina basada en evidencia', 'nuvanx-medical' ),
		(string) ( $config['chamberi']['short_name'] ?? '' ),
		(string) ( $config['chamberi']['reg'] ?? '' ),
		(string) ( $config['goya']['short_name'] ?? '' ),
		(string) ( $config['goya']['reg'] ?? '' )
	) ) . '</p>';
	$html .= '</div></div></section>';

	$html .= '<nav class="nvx-brand-section nvx-clinics-nav" aria-label="' . esc_attr__( 'Sedes NUVANX', 'nuvanx-medical' ) . '">';
	$html .= '<div class="nvx-brand-section__inner nvx-cta-pair">';
	$html .= '<a class="nvx-brand-btn nvx-brand-btn--secondary" href="#clinica-chamberi">' . esc_html__( 'Chamberí', 'nuvanx-medical' ) . '</a>';
	$html .= '<a class="nvx-brand-btn nvx-brand-btn--secondary" href="#clinica-goya">' . esc_html__( 'Salamanca–Goya', 'nuvanx-medical' ) . '</a>';
	$html .= '</div></nav>';

	// Chamberí.
	$html .= '<section id="clinica-chamberi" class="nvx-brand-section" aria-labelledby="nvx-clinic-chamberi-title">';
	$html .= '<div class="nvx-brand-section__inner">';
	$html .= '<p class="nvx-brand-kicker">' . esc_html( sprintf( __( 'Registro sanitario %s', 'nuvanx-medical' ), (string) ( $config['chamberi']['reg'] ?? '' ) ) ) . '</p>';
	$html .= '<h2 id="nvx-clinic-chamberi-title" class="nvx-brand-title">' . esc_html__( 'Centro Clínico NUVANX Chamberí', 'nuvanx-medical' ) . '</h2>';
	$html .= '<p class="nvx-brand-lead">' . esc_html__( 'A dos minutos de la Plaza de Olavide. Valoración, Endolift®, láser CO₂ y seguimiento en un centro autorizado por la Comunidad de Madrid.', 'nuvanx-medical' ) . '</p>';
	$html .= '<ul class="nvx-brand-list">';
	$html .= '<li><svg class="nvx-icon" aria-hidden="true"><use href="#icon-location"></use></svg> ' . esc_html( $chamberi_addr ) . '</li>';
	$html .= '<li><svg class="nvx-icon" aria-hidden="true"><use href="#icon-phone"></use></svg> <a class="nvx-brand-inline-link" href="' . esc_url( 'tel:' . $chamberi_phone ) . '">' . esc_html( $chamberi_tel_disp ) . '</a> · <a class="nvx-brand-inline-link" href="' . esc_url( $chamberi_wa ) . '" rel="noopener noreferrer" target="_blank">WhatsApp</a></li>';
	$html .= '<li>' . esc_html__( 'Horario:', 'nuvanx-medical' ) . ' ' . esc_html( $chamberi_hours ) . '</li>';
	$html .= '<li>' . esc_html__( 'El Dr. Rivera atiende en Chamberí los martes y jueves.', 'nuvanx-medical' ) . '</li>';
	$html .= '</ul>';
	$html .= '<div class="nvx-brand-actions">';
	$html .= '<a class="nvx-brand-btn nvx-brand-btn--primary" href="' . esc_url( $chamberi_url ) . '">' . esc_html__( 'Ficha de la sede Chamberí', 'nuvanx-medical' ) . '</a>';
	$html .= '<a class="nvx-brand-btn nvx-brand-btn--primary" href="' . esc_url( $chamberi_maps ) . '" rel="noopener noreferrer" target="_blank">' . esc_html__( 'Cómo llegar', 'nuvanx-medical' ) . '</a>';
	$html .= '</div></div></section>';

	// Goya.
	$html .= '<section id="clinica-goya" class="nvx-brand-section" aria-labelledby="nvx-clinic-goya-title">';
	$html .= '<div class="nvx-brand-section__inner">';
	$html .= '<p class="nvx-brand-kicker">' . esc_html( sprintf( __( 'Registro sanitario %s', 'nuvanx-medical' ), (string) ( $config['goya']['reg'] ?? '' ) ) ) . '</p>';
	$html .= '<h2 id="nvx-clinic-goya-title" class="nvx-brand-title">' . esc_html__( 'Centro Clínico NUVANX Salamanca–Goya', 'nuvanx-medical' ) . '</h2>';
	$html .= '<p class="nvx-brand-lead">' . esc_html__( 'En el Barrio de Salamanca. Misma dirección médica y protocolos que Chamberí, con atención y valoración en sede propia.', 'nuvanx-medical' ) . '</p>';
	$html .= '<ul class="nvx-brand-list">';
	$html .= '<li><svg class="nvx-icon" aria-hidden="true"><use href="#icon-location"></use></svg> ' . esc_html( $goya_addr ) . '</li>';
	$html .= '<li><svg class="nvx-icon" aria-hidden="true"><use href="#icon-phone"></use></svg> <a class="nvx-brand-inline-link" href="' . esc_url( 'tel:' . $goya_phone ) . '">' . esc_html( $goya_tel_disp ) . '</a> · <a class="nvx-brand-inline-link" href="' . esc_url( $goya_wa ) . '" rel="noopener noreferrer" target="_blank">WhatsApp</a></li>';
	$html .= '<li>' . esc_html__( 'Horario:', 'nuvanx-medical' ) . ' ' . esc_html( $goya_hours ) . '</li>';
	$html .= '<li>' . esc_html__( 'El Dr. Rivera atiende en Salamanca–Goya los miércoles.', 'nuvanx-medical' ) . '</li>';
	$html .= '</ul>';
	$html .= '<div class="nvx-brand-actions">';
	$html .= '<a class="nvx-brand-btn nvx-brand-btn--primary" href="' . esc_url( $goya_url ) . '">' . esc_html__( 'Ficha de la sede Goya', 'nuvanx-medical' ) . '</a>';
	$html .= '<a class="nvx-brand-btn nvx-brand-btn--primary" href="' . esc_url( $goya_maps ) . '" rel="noopener noreferrer" target="_blank">' . esc_html__( 'Cómo llegar', 'nuvanx-medical' ) . '</a>';
	$html .= '</div></div></section>';

	// Educational equipment section, intentionally separate from the two sede blocks.
	$html .= '<!-- NVX_APPROVED_EQUIPMENT_SECTION:clinic-hub-v1 -->';
	$html .= '<!-- Equipment cards are appended after generic vendor-image hygiene. -->';

	// Closing CTA with clinic codes for GEO/AI reinforcement.
	$html .= '<section class="nvx-brand-section" aria-labelledby="nvx-clinics-closure-title">';
	$html .= '<div class="nvx-brand-section__inner">';
	$html .= '<h2 id="nvx-clinics-closure-title" class="nvx-brand-title">' . esc_html__( 'Valoración en nuestras sedes de Madrid', 'nuvanx-medical' ) . '</h2>';
	$html .= '<p class="nvx-brand-lead">' . esc_html( sprintf( __( '%1$s (%2$s) y %3$s (%4$s). Un único criterio médico, dos centros sanitarios autorizados.', 'nuvanx-medical' ),
		(string) ( $config['chamberi']['short_name'] ?? '' ),
		(string) ( $config['chamberi']['reg'] ?? '' ),
		(string) ( $config['goya']['short_name'] ?? '' ),
		(string) ( $config['goya']['reg'] ?? '' )
	) ) . '</p>';
	$html .= '<div class="nvx-brand-actions">';
	$html .= '<a class="nvx-brand-btn nvx-brand-btn--primary" href="' . esc_url( $valoracion ) . '">' . esc_html__( 'Valoración gratuita — sin compromiso', 'nuvanx-medical' ) . '</a>';
	$html .= '</div></div></section>';

	$html .= '<section class="nvx-brand-section" aria-labelledby="nvx-clinics-hub-cta-title">';
	$html .= '<div class="nvx-brand-section__inner">';
	$html .= '<p class="nvx-brand-kicker">' . esc_html__( 'Siguiente paso', 'nuvanx-medical' ) . '</p>';
	$html .= '<h2 id="nvx-clinics-hub-cta-title" class="nvx-brand-title">' . esc_html__( 'Valoración médica en la sede que elijas', 'nuvanx-medical' ) . '</h2>';
	$html .= '<p class="nvx-brand-lead">' . esc_html__( 'La indicación y el presupuesto se definen en consulta presencial. Elige sede al solicitar la valoración o llama directamente a tu centro.', 'nuvanx-medical' ) . '</p>';
	$html .= '<div class="nvx-brand-actions">';
	$html .= '<a class="nvx-brand-btn nvx-brand-btn--primary" href="' . esc_url( $valoracion ) . '">' . esc_html__( 'Solicitar valoración', 'nuvanx-medical' ) . '</a>';
	$html .= '<a class="nvx-brand-btn nvx-brand-btn--secondary" href="' . esc_url( 'tel:' . $chamberi_phone ) . '">' . esc_html( sprintf( __( 'Chamberí · %s', 'nuvanx-medical' ), $chamberi_tel_disp ) ) . '</a>';
	$html .= '<a class="nvx-brand-btn nvx-brand-btn--secondary" href="' . esc_url( 'tel:' . $goya_phone ) . '">' . esc_html( sprintf( __( 'Goya · %s', 'nuvanx-medical' ), $goya_tel_disp ) ) . '</a>';
	$html .= '</div></div></section>';

	$html .= '</div>';

	return $html;
}

/**
 * Replace theme-owned hub marker/empty body with the canonical clinics page.
 */
function nvx_clinics_hub_render_managed( string $content ): string {
	if ( is_admin() || ! nvxIsClinicsHub() ) {
		return $content;
	}

	return nvx_clinics_hub_page_markup();
}
add_filter( 'the_content', 'nvx_clinics_hub_render_managed', NVX_HOOK_PRIO_CLINICS_HUB );

/**
 * Enhances residual CMS clinics markup (legacy path) with layout pipeline.
 *
 * @param string $content The HTML content to enhance.
 * @return string The enhanced HTML content, or the original content when enhancement is unavailable.
 */
function nvxClinicsHubEnhance( string $content ): string {
	if ( is_admin() || ( ! nvxIsClinicsHub() && ! nvxIsSedeTemplate() ) || '' === trim( $content ) ) {
		return $content;
	}
	// Managed hub is fully theme-owned — do not run the CMS residual pipeline.
	if ( nvxIsClinicsHub() && nvx_clinics_hub_is_managed_content( $content ) ) {
		return $content;
	}
	// After managed render the marker is gone; skip DOM work on our own markup.
	if ( nvxIsClinicsHub() && false !== strpos( $content, 'nvx-clinics-hub-page' ) ) {
		return $content;
	}

	$previous = libxml_use_internal_errors( true );
	$dom      = new DOMDocument( '1.0', 'UTF-8' );
	$wrapper  = '<div id="nvx-clinics-document">' . $content . '</div>';
	$loaded   = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $wrapper, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
	libxml_clear_errors();
	libxml_use_internal_errors( $previous );

	if ( ! $loaded ) {
		return $content;
	}

	$xpath       = new DOMXPath( $dom );
	$pipeline    = nvxClinicsRunLayoutPipeline( $xpath );
	$layout_root = $pipeline['layout_root'];
	$hoisted     = $pipeline['hoisted'];

	$clinics = array(
		'chamberi' => array(
			'id'    => 'clinica-chamberi',
			'label' => 'Chamberí',
			'match' => '/chamber[ií]/iu',
		),
		'goya'     => array(
			'id'    => 'clinica-goya',
			'label' => 'Salamanca–Goya',
			'match' => '/(?:salamanca|goya)/iu',
		),
	);

	$blocks = nvxClinicsIdentifyLocationBlocks( $xpath, $clinics );
	nvxClinicsProcessMapActions( $dom, $xpath, $blocks );
	nvxClinicsNormalizeCtaHierarchy( $dom, $xpath );
	nvxClinicsInsertNavElement( $dom, $xpath, $clinics, $blocks, $hoisted, $layout_root );

	$root = $dom->getElementById( 'nvx-clinics-document' );
	if ( ! $root ) {
		return $content;
	}

	$output = '';
	foreach ( $root->childNodes as $child ) {
		$output .= $dom->saveHTML( $child );
	}
	return $output ?: $content;
}
add_filter( 'the_content', 'nvxClinicsHubEnhance', NVX_HOOK_PRIO_CLINICS_ENHANCE );

/**
 * Append the explicitly approved equipment cards after global vendor-image
 * hygiene. This is a route-scoped exception; GBP and individual sede landing
 * restrictions remain unchanged.
 */
function nvx_clinics_hub_append_approved_equipment( string $content ): string {
	if ( is_admin() || ! nvxIsClinicsHub() || false === strpos( $content, 'NVX_APPROVED_EQUIPMENT_SECTION:clinic-hub-v1' ) ) {
		return $content;
	}

	$section = nvx_clinics_hub_equipment_section_markup();
	if ( '' === $section ) {
		return str_replace( '<!-- NVX_APPROVED_EQUIPMENT_SECTION:clinic-hub-v1 -->', '', $content );
	}

	return str_replace( '<!-- NVX_APPROVED_EQUIPMENT_SECTION:clinic-hub-v1 -->', $section, $content );
}
add_filter( 'the_content', 'nvx_clinics_hub_append_approved_equipment', NVX_HOOK_PRIO_CLINICS_APPROVED_EQUIPMENT );

/**
 * Register clinics hub as page owner to prevent shell hero duplication.
 *
 * When the shell evaluates $has_managed_editorial in nvx-page-shell.php,
 * this filter ensures clinics hub pages are recognized as managed,
 * preventing the shell from rendering its own hero in addition to
 * the renderer's hero.
 */
add_filter(
	'nvx_page_owner',
	function ( $owner ) {
		if ( ! empty( $owner ) || is_admin() ) {
			return $owner;
		}
		if ( function_exists( 'nvxIsClinicsHub' ) && nvxIsClinicsHub() ) {
			return 'nvx_clinics_hub';
		}
		return $owner;
	},
	10
);

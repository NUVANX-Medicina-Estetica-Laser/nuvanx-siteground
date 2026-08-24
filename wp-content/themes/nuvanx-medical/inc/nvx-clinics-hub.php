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
		: '/medicina-estetica-chamberi/';
	$goya_path     = isset( $registry['clinics']['goya']['path'] )
		? (string) $registry['clinics']['goya']['path']
		: '/clinicas-de-medicina-estetica-nuvanx/medicina-estetica-goya-barrio-salamanca/';

	$chamberi_phone = ! empty( $clinics['chamberi']['telephone'] ) ? (string) $clinics['chamberi']['telephone'] : '+34669319836';
	$goya_phone     = ! empty( $clinics['goya']['telephone'] ) ? (string) $clinics['goya']['telephone'] : '+34647505107';
	$chamberi_maps  = ! empty( $clinics['chamberi']['hasMap'] ) ? (string) $clinics['chamberi']['hasMap'] : nvxClinicsMapUrl( 'chamberi' );
	$goya_maps      = ! empty( $clinics['goya']['hasMap'] ) ? (string) $clinics['goya']['hasMap'] : nvxClinicsMapUrl( 'goya' );
	$chamberi_url   = home_url( $chamberi_path );
	$goya_url       = home_url( $goya_path );
	$valoracion     = home_url( '/madrid/valoracion/' );

	$chamberi_tel_disp = nvx_clinics_hub_phone_display( $chamberi_phone );
	$goya_tel_disp     = nvx_clinics_hub_phone_display( $goya_phone );

	$chamberi_wa = ! empty( $config['chamberi']['whatsapp_href'] ) ? (string) $config['chamberi']['whatsapp_href'] : 'https://wa.me/' . preg_replace( '/\D/', '', $chamberi_phone );
	$goya_wa     = ! empty( $config['goya']['whatsapp_href'] ) ? (string) $config['goya']['whatsapp_href'] : 'https://wa.me/' . preg_replace( '/\D/', '', $goya_phone );

	$chamberi_hours = ! empty( $config['chamberi']['hours'] ) ? (string) $config['chamberi']['hours'] : __( 'lunes a viernes, 12:00–20:00; sábados, 10:00–18:00', 'nuvanx-medical' );
	$goya_hours     = ! empty( $config['goya']['hours'] ) ? (string) $config['goya']['hours'] : __( 'lunes a viernes, 11:00–20:00', 'nuvanx-medical' );

	$chamberi_addr = ! empty( $config['chamberi']['address'] ) ? sprintf( '%s, %s %s', $config['chamberi']['address'], $config['chamberi']['postal_code'], $config['chamberi']['locality'] ) : __( 'Calle de Fernández de la Hoz, 4, Bajo Derecha, 28010 Madrid', 'nuvanx-medical' );
	$goya_addr     = ! empty( $config['goya']['address'] ) ? sprintf( '%s, %s %s', $config['goya']['address'], $config['goya']['postal_code'], $config['goya']['locality'] ) : __( 'Calle de Fernán González, 26, 28009 Madrid', 'nuvanx-medical' );

	$html  = '<div class="nvx-brand-page nvx-clinics-hub-page">';
	$html .= '<section class="nvx-brand-hero" aria-labelledby="nvx-clinics-hub-h1">';
	$html .= '<div class="nvx-brand-hero__inner"><div class="nvx-brand-hero__copy">';
	$html .= '<p class="nvx-brand-kicker">' . esc_html__( 'Clínicas NUVANX · Madrid', 'nuvanx-medical' ) . '</p>';
	$html .= '<h1 id="nvx-clinics-hub-h1" class="nvx-brand-hero__title">' . esc_html__( 'Clínicas NUVANX Medicina Estética Láser en Madrid', 'nuvanx-medical' ) . '</h1>';
	$html .= '<p class="nvx-brand-hero__lead">' . esc_html__( 'Dos centros sanitarios autorizados, una sola dirección médica. Chamberí y Salamanca–Goya con el mismo criterio clínico, protocolos láser y valoración presencial antes de cualquier tratamiento.', 'nuvanx-medical' ) . '</p>';
	$html .= '<div class="nvx-brand-actions nvx-clinics-hub-actions">';
	$html .= '<a class="nvx-brand-btn nvx-brand-btn--primary" href="' . esc_url( $valoracion ) . '">' . esc_html__( 'Solicitar valoración médica', 'nuvanx-medical' ) . '</a>';
	$html .= '</div>';
	$html .= '<p class="nvx-brand-meta nvx-reg-copy">' . esc_html__( 'Chamberí CS20144 · Salamanca–Goya CS20073 · Medicina basada en evidencia', 'nuvanx-medical' ) . '</p>';
	$html .= '</div></div></section>';

	$html .= '<nav class="nvx-brand-section nvx-clinics-nav" aria-label="' . esc_attr__( 'Sedes NUVANX', 'nuvanx-medical' ) . '">';
	$html .= '<div class="nvx-brand-section__inner nvx-cta-pair">';
	$html .= '<a class="nvx-brand-btn nvx-brand-btn--secondary" href="#clinica-chamberi">' . esc_html__( 'Chamberí', 'nuvanx-medical' ) . '</a>';
	$html .= '<a class="nvx-brand-btn nvx-brand-btn--secondary" href="#clinica-goya">' . esc_html__( 'Salamanca–Goya', 'nuvanx-medical' ) . '</a>';
	$html .= '</div></nav>';

	// Chamberí.
	$html .= '<section id="clinica-chamberi" class="nvx-brand-section" aria-labelledby="nvx-clinic-chamberi-title">';
	$html .= '<div class="nvx-brand-section__inner">';
	$html .= '<p class="nvx-brand-kicker">' . esc_html__( 'Registro sanitario CS20144', 'nuvanx-medical' ) . '</p>';
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
	$html .= '<p class="nvx-brand-kicker">' . esc_html__( 'Registro sanitario CS20073', 'nuvanx-medical' ) . '</p>';
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

	// Closing CTA with clinic codes for GEO/AI reinforcement.
	$html .= '<section class="nvx-brand-section" aria-labelledby="nvx-clinics-closure-title">';
	$html .= '<div class="nvx-brand-section__inner">';
	$html .= '<h2 id="nvx-clinics-closure-title" class="nvx-brand-title">' . esc_html__( 'Valoración en nuestras sedes de Madrid', 'nuvanx-medical' ) . '</h2>';
	$html .= '<p class="nvx-brand-lead">' . esc_html__( 'Chamberí (CS20144) y Salamanca–Goya (CS20073). Un único criterio médico, dos centros sanitarios autorizados.', 'nuvanx-medical' ) . '</p>';
	$html .= '<div class="nvx-brand-actions">';
	$html .= '<a class="nvx-brand-btn nvx-brand-btn--primary" href="' . esc_url( $valoracion ) . '">' . esc_html__( 'Solicitar valoración médica', 'nuvanx-medical' ) . '</a>';
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

	$html .= nvx_semantic_graph_clinics();
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

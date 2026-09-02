<?php
/**
 * Protocolo de novias — editorial photography.
 *
 * CMS page (/protocolo-novias-madrid/) has no dedicated template.
 * Injects a magazine-style studio of theme-hosted WebP plates after the
 * philosophy block. Marker class: nvx-bridal-studio.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/** Whether the current request is the bridal protocol page. */
function nvx_is_bridal_protocol_page( string $content = '' ): bool {
	if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return false;
	}

	if ( is_page( 'protocolo-novias-madrid' ) ) {
		return true;
	}

	return false !== strpos( $content, 'id="nvx-bridal-h1"' )
		|| false !== strpos( $content, "id='nvx-bridal-h1'" );
}

/**
 * Figure markup for a bridal upload, rewritten to theme WebP srcset.
 */
function nvx_bridal_figure( string $filename, string $alt, string $caption = '', string $extra_class = '', string $sizes = '(max-width: 1100px) calc(100vw - 48px), 1100px' ): string {
	$src   = content_url( 'uploads/2026/08/' . ltrim( $filename, '/' ) );
	$attrs = 'class="nvx-bridal-studio__img" loading="lazy" decoding="async" sizes="' . esc_attr( $sizes ) . '"';
	$img   = function_exists( 'nvx_responsive_img_markup' )
		? nvx_responsive_img_markup( $src, $alt, $attrs )
		: '<img src="' . esc_url( $src ) . '" alt="' . esc_attr( $alt ) . '" ' . $attrs . '>';

	$class = trim( 'nvx-bridal-studio__plate ' . $extra_class );
	$html  = '<figure class="' . esc_attr( $class ) . '">' . $img;
	if ( '' !== $caption ) {
		$html .= '<figcaption>' . esc_html( $caption ) . '</figcaption>';
	}
	$html .= '</figure>';

	return $html;
}

/** Editorial studio: mood collage, clinic box, papada strip, staggered pair. */
function nvx_bridal_gallery_markup(): string {
	$html  = '<section class="nvx-brand-section nvx-bridal-studio" aria-labelledby="nvx-bridal-studio-title">';
	$html .= '<div class="nvx-bridal-studio__intro nvx-brand-section__inner">';
	$html .= '<p class="nvx-brand-kicker">' . esc_html__( 'El vestido y el plan', 'nuvanx-medical' ) . '</p>';
	$html .= '<h2 id="nvx-bridal-studio-title" class="nvx-brand-title">' . esc_html__( 'Lo que se consulta antes del evento', 'nuvanx-medical' ) . '</h2>';
	$html .= '<p class="nvx-body nvx-body--measure">' . esc_html__( 'Mangas, espalda, papada, brazos o piernas: el vestido marca el calendario. La valoración decide qué zona tiene indicación y qué conviene no tratar. Las imágenes ilustran preocupaciones frecuentes; no constituyen un resultado garantizado.', 'nuvanx-medical' ) . '</p>';
	$html .= '</div>';

	$html .= '<div class="nvx-bridal-studio__spread nvx-brand-section__inner">';
	$html .= '<div class="nvx-bridal-studio__copy">';
	$html .= '<p class="nvx-brand-kicker">' . esc_html__( 'Antes de elegir tecnología', 'nuvanx-medical' ) . '</p>';
	$html .= '<h3 class="nvx-brand-subtitle">' . esc_html__( 'El vestido marca el calendario, no la indicación', 'nuvanx-medical' ) . '</h3>';
	$html .= '<p class="nvx-body">' . esc_html__( 'Mangas, espalda o cintura llegan a consulta como una preocupación concreta. El plan se ordena por el margen temporal, la recuperación aceptable y la anatomía. No se indica un procedimiento porque el vestido lo pida: se indica si hay un motivo clínico y tiempo suficiente para revisarlo.', 'nuvanx-medical' ) . '</p>';
	$html .= '</div></div>';

	$html .= '<div class="nvx-bridal-studio__spread nvx-brand-section__inner">';
	$html .= nvx_bridal_figure(
		'Box-Clinica-Novias.png',
		__( 'Box de consulta en clínica NUVANX, con camilla y luz de exploración', 'nuvanx-medical' ),
		__( 'Box de consulta', 'nuvanx-medical' ),
		'nvx-bridal-studio__plate--consult',
		'(max-width: 680px) calc(100vw - 48px), 520px'
	);
	$html .= '<div class="nvx-bridal-studio__copy">';
	$html .= '<p class="nvx-brand-kicker">' . esc_html__( 'El espacio', 'nuvanx-medical' ) . '</p>';
	$html .= '<h3 class="nvx-brand-subtitle">' . esc_html__( 'Consulta en box clínico', 'nuvanx-medical' ) . '</h3>';
	$html .= '<p class="nvx-body">' . esc_html__( 'La primera visita documenta objetivos, fecha del evento y límites. El presupuesto y la secuencia se entregan por escrito; cualquier cambio posterior requiere una nueva indicación.', 'nuvanx-medical' ) . '</p>';
	$html .= '</div></div>';

	$html .= nvx_bridal_figure(
		'Papada-novias.png',
		__( 'Papada y contorno cervical en tres momentos de un plan de novia', 'nuvanx-medical' ),
		__( 'Papada y contorno cervical — la indicación se confirma en valoración', 'nuvanx-medical' ),
		'nvx-bridal-studio__plate--papada nvx-brand-section__inner'
	);

	$html .= '<div class="nvx-bridal-studio__pair nvx-brand-section__inner">';
	$html .= nvx_bridal_figure(
		'Brazos-novias.png',
		__( 'Novia de frente con vestido de manga corta, zona de brazos', 'nuvanx-medical' ),
		__( 'Brazos', 'nuvanx-medical' ),
		'nvx-bridal-studio__plate--brazos',
		'(max-width: 680px) calc(100vw - 48px), 520px'
	);
	$html .= nvx_bridal_figure(
		'Espalda-novias.png',
		__( 'Espalda y escote de un vestido de novia sin tirantes', 'nuvanx-medical' ),
		__( 'Espalda', 'nuvanx-medical' ),
		'nvx-bridal-studio__plate--espalda',
		'(max-width: 680px) calc(100vw - 48px), 520px'
	);
	$html .= '</div></section>';

	return $html;
}

/**
 * Strip a previously injected bridal gallery so the studio can replace it.
 */
function nvx_bridal_strip_legacy_gallery( string $content ): string {
	$updated = preg_replace(
		'/<section\b[^>]*\b(?:nvx-bridal-gallery|nvx-bridal-studio)\b[^>]*>[\s\S]*?<\/section>/iu',
		'',
		$content,
		1
	);

	return is_string( $updated ) ? $updated : $content;
}

/**
 * Insert the bridal studio after the philosophy section, or after the opening hero.
 */
function nvx_bridal_inject_media( string $content ): string {
	if ( '' === $content || ! nvx_is_bridal_protocol_page( $content ) ) {
		return $content;
	}

	if ( false !== strpos( $content, 'nvx-bridal-studio__spread' ) ) {
		return $content;
	}

	$content = nvx_bridal_strip_legacy_gallery( $content );
	$gallery = nvx_bridal_gallery_markup();
	$anchors = array(
		'nvx-philosophy-title',
		'nvx-bridal-h1',
	);

	foreach ( $anchors as $anchor_id ) {
		$pattern = '/<section\b[^>]*(?:aria-labelledby=["\']' . preg_quote( $anchor_id, '/' ) . '["\']|id=["\']' . preg_quote( $anchor_id, '/' ) . '["\'])/iu';
		if ( ! preg_match( $pattern, $content, $match, PREG_OFFSET_CAPTURE ) ) {
			continue;
		}

		$open = (int) $match[0][1];
		$el   = function_exists( 'nvx_extract_balanced_element' )
			? nvx_extract_balanced_element( $content, $open, 'section' )
			: null;

		if ( ! is_string( $el ) || '' === $el ) {
			continue;
		}

		return substr( $content, 0, $open + strlen( $el ) ) . $gallery . substr( $content, $open + strlen( $el ) );
	}

	return $content . $gallery;
}
add_filter( 'the_content', 'nvx_bridal_inject_media', NVX_HOOK_PRIO_BRIDAL_MEDIA );

<?php
/**
 * Content pipeline: values/method section injection, content filters and enrichments.
 *
 * CTA primitive components are in nvx-cta-components.php (loaded first).
 *
 * @package NUVANX
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/nvx-cta-components.php';

/**
 * Strip page-level closing conversion bands so only the site footer CTA remains.
 *
 * Patterns are intentionally narrow:
 * - known page-module closing class tokens
 * - prior soft CTAs only when they carry conversion copy/chrome
 * - in-content duplicates of the site closing banner (id / footer-cta hook)
 *
 * @param string $content HTML.
 * @return string
 */
function nvx_content_strip_page_closing_ctas( string $content ): string {
	// Only prevent in-content duplicates of the site-wide footer CTA band.
	$patterns = array(
		'/<section\b[^>]*\bid=["\']nvx-site-closing-cta["\'][^>]*>[\s\S]{0,4000}?<\/section>/iu',
		// id may sit on the open tag (common CMS export) or nested in the body.
		'/<section\b(?=[^>]*\bnvx-cta-banner\b)(?=[^>]*\bid=["\']nvx-footer-cta["\'])[^>]*>[\s\S]*?<\/section>/iu',
		'/<section\b(?=[^>]*\bnvx-cta-banner\b)[^>]*>[\s\S]{0,4000}?\bid=["\']nvx-footer-cta["\'][\s\S]{0,2000}?<\/section>/iu',
	);

	foreach ( $patterns as $pattern ) {
		$content = preg_replace( $pattern, '', $content ) ?? $content;
	}

	return $content;
}

/**
 * Minimal stroke icons (currentColor).
 *
 * @param string $name Icon key.
 */
function nvx_content_icon_svg( string $name ): string {
	$icons = array(
		'shield'    => '<svg viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M24 6 10 12v10c0 10.5 5.8 16.8 14 20 8.2-3.2 14-9.5 14-20V12L24 6Z" stroke="currentColor" stroke-width="1.6"/><path d="M24 16v14M18 23h12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>',
		'laser'     => '<svg viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><circle cx="24" cy="24" r="5" stroke="currentColor" stroke-width="1.6"/><path d="M24 6v8M24 34v8M6 24h8M34 24h8M11 11l5.5 5.5M31.5 31.5 37 37M37 11l-5.5 5.5M16.5 31.5 11 37" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>',
		'nature'    => '<svg viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M24 40V22" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M24 34c-8 0-14-5-14-12 8 0 14 5 14 12Z" stroke="currentColor" stroke-width="1.6"/><path d="M24 30c8 0 14-5 14-12-8 0-14 5-14 12Z" stroke="currentColor" stroke-width="1.6"/><path d="M16 14c4-4 8-6 12-6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>',
		'scan'      => '<svg viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M10 16V10h6M32 10h6v6M38 32v6h-6M16 38H10v-6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><circle cx="24" cy="24" r="7" stroke="currentColor" stroke-width="1.6"/><path d="M8 24h32" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>',
		'precision' => '<svg viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M8 30 24 8l16 22" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M14 30h20" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M18 36h12M21 42h6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>',
		'follow'    => '<svg viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M8 32c6-10 10-14 16-14s10 4 16 14" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M12 20c3-2 6-3 12-3s9 1 12 3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><circle cx="24" cy="28" r="3" stroke="currentColor" stroke-width="1.6"/></svg>',
	);

	return $icons[ $name ] ?? $icons['shield'];
}

/**
 * Premium action banner immediately after the clinical values columns.
 * Uses design-system CTAs (pill radius) — not square marketing blocks.
 */
function nvx_home_action_banner_markup(): string {
	$valoracion = nvx_cta_valoracion_url();
	$whatsapp   = nvx_cta_whatsapp_url();

	$modal_contract = function_exists( 'nvx_cta_valoracion_modal_contract' )
		? nvx_cta_valoracion_modal_contract()
		: array( 'class' => '', 'attrs' => '' );

	// Stable structural id + data attribute for safe strip/replace (no broad markup regex).
	$html  = '<div id="nvx-post-values-action-banner" class="nvx-home-action-banner-shell" data-nvx-action-banner="post-values">';
	$html .= '<section class="nvx-home-action-banner" aria-labelledby="nvx-home-action-banner-title">';
	$html .= '<div class="nvx-home-action-banner__copy">';
	$html .= '<p class="nvx-brand-kicker nvx-home-action-banner__kicker">' . esc_html__( 'Valoración médica', 'nuvanx-medical' ) . '</p>';
	$html .= '<h2 id="nvx-home-action-banner-title" class="nvx-home-action-banner__title">' . esc_html__( '15–30 minutos para saber si existe indicación', 'nuvanx-medical' ) . '</h2>';
	$html .= '<p class="nvx-home-action-banner__text">' . wp_kses(
		__( 'El Dr. Rivera evalúa piel, anatomía y antecedentes en la primera visita. Explicamos las opciones disponibles y documentamos el presupuesto antes de cualquier decisión. Presencial en <strong>Chamberí</strong> o <strong>Salamanca–Goya</strong>.', 'nuvanx-medical' ),
		array( 'strong' => array() )
	) . '</p>';
	$html .= '</div>';
	$html .= '<div class="nvx-home-action-banner__actions">';
	// Pill CTAs from the design system (radius 999px) — never square blocks.
	$html .= sprintf(
		'<a class="nvx-brand-btn nvx-brand-btn--light nvx-home-action-banner__cta%1$s" href="%2$s"%3$s>%4$s</a>',
		$modal_contract['class'],
		esc_url( $valoracion ),
		$modal_contract['attrs'],
		esc_html__( 'Solicitar valoración médica', 'nuvanx-medical' )
	);
	$html .= sprintf(
		'<a class="nvx-brand-btn nvx-brand-btn--secondary-on-dark nvx-home-action-banner__cta" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
		esc_url( $whatsapp ),
		esc_html__( 'Contactar por WhatsApp', 'nuvanx-medical' )
	);
	$html .= '</div></section></div>';

	return $html;
}

/**
 * Clinical values pillars (structured presentation of intro/criterio blocks).
 * Conversion CTAs move to the post-values action banner — clean UI, no inline links.
 */
function nvx_values_section_markup(): string {
	$items = array(
		array(
			'icon'  => 'shield',
			'title' => '1. Diagnóstico antes de tecnología',
			'body'  => 'Cada protocolo comienza con una valoración médica de 15 a 30 minutos: calidad de piel, historial, objetivos y contraindicaciones. Solo se indica un tratamiento cuando existe una razón clínica para hacerlo.',
		),
		array(
			'icon'  => 'laser',
			'title' => '2. Equipamiento médico certificado',
			'body'  => 'Trabajamos con plataformas médicas con marcado CE como DEKA Motus AZ+, Láser CO₂ fraccionado y EXION® BTL. La tecnología y sus parámetros se seleccionan según la anatomía y el objetivo de cada paciente.',
		),
		array(
			'icon'  => 'nature',
			'title' => '3. Sin cambiar la expresión ni añadir volumen donde no hay indicación',
			'body'  => 'El objetivo es mejorar firmeza, textura y definición respetando la expresión y la identidad del rostro. Antes de tratar, explicamos qué puede mejorar, qué límites existen y qué recuperación requiere cada protocolo.',
		),
	);

	$html  = '<section class="nvx-brand-section nvx-brand-section--tight nvx-values-section" aria-label="Por qué NUVANX">';
	$html .= '<div class="nvx-shell nvx-brand-section__inner">';
	$html .= '<p class="nvx-brand-kicker">' . esc_html__( 'Por qué NUVANX', 'nuvanx-medical' ) . '</p>';
	$html .= '<h2 class="nvx-brand-title">' . esc_html__( 'Medicina estética donde el diagnóstico decide la tecnología', 'nuvanx-medical' ) . '</h2>';
	$html .= '<div class="nvx-values">';

	foreach ( $items as $item ) {
		$html .= '<article class="nvx-value">';
		$html .= '<div class="nvx-value__icon" aria-hidden="true">' . nvx_content_icon_svg( $item['icon'] ) . '</div>';
		$html .= '<h3 class="nvx-value__title">' . esc_html( $item['title'] ) . '</h3>';
		$html .= '<p class="nvx-value__body">' . esc_html( $item['body'] ) . '</p>';
		$html .= '</article>';
	}

	$html .= '</div>';
	// No CTAs inside the pillars — conversion lives in the action banner below.
	$html .= '</div></section>';
	$html .= nvx_home_action_banner_markup();

	return $html;
}

/**
 * Method as three icon columns (distinct from numbered treatment grids).
 */
function nvx_method_section_markup(): string {
	$items = array(
		array(
			'icon'  => 'scan',
			'title' => 'Evaluación individual',
			'body'  => 'Revisamos piel, anatomía, historial, objetivos y contraindicaciones antes de proponer un procedimiento.',
		),
		array(
			'icon'  => 'precision',
			'title' => 'Indicación y parámetros',
			'body'  => 'Definimos tecnología, energía, profundidad y número de sesiones según el caso, no mediante configuraciones estándar.',
		),
		array(
			'icon'  => 'follow',
			'title' => 'Control de evolución',
			'body'  => 'Programamos seguimiento según el tratamiento para valorar respuesta, recuperación y necesidad de ajustes.',
		),
	);

	$html  = '<section class="nvx-brand-section nvx-method-section" aria-label="Cómo trabajamos NUVANX">';
	$html .= '<div class="nvx-shell nvx-brand-section__inner">';
	$html .= '<p class="nvx-brand-kicker">' . esc_html__( 'Cómo trabajamos', 'nuvanx-medical' ) . '</p>';
	$html .= '<h2 class="nvx-brand-title">' . esc_html__( 'Un protocolo médico en tres decisiones', 'nuvanx-medical' ) . '</h2>';
	$html .= '<p class="nvx-brand-body nvx-method-lead">' . esc_html__( 'La evaluación, la indicación y el seguimiento forman un único proceso clínico.', 'nuvanx-medical' ) . '</p>';
	$html .= '<div class="nvx-method-columns">';

	foreach ( $items as $item ) {
		$html .= '<article class="nvx-method-col">';
		$html .= '<div class="nvx-method-col__icon" aria-hidden="true">' . nvx_content_icon_svg( $item['icon'] ) . '</div>';
		$html .= '<h3 class="nvx-method-col__title">' . esc_html( $item['title'] ) . '</h3>';
		$html .= '<p class="nvx-method-col__body">' . esc_html( $item['body'] ) . '</p>';
		$html .= '</article>';
	}

	$html .= '</div>';
	$html .= nvx_cta_pair_markup( 'nvx-method__cta' );
	$html .= '</div></section>';

	return $html;
}

/**
 * Replace intro/editorial prose blocks with structured values.
 * Matches structural patterns, not page slugs.
 */
function nvx_content_replace_values_sections( string $content ): string {
	// Already transformed: keep pillars, ensure post-values action banner exists.
	if ( false !== strpos( $content, 'nvx-values-section' ) || false !== strpos( $content, 'class="nvx-values"' ) ) {
		return nvx_content_ensure_post_values_action_banner( $content );
	}

	$replacement = nvx_values_section_markup();
	// Strip residual home editorial intro blocks (nvx-home-editorial class retired from markup).
	$updated = preg_replace(
		'/<section\b[^>]*class="[^"]*nvx-home-editorial[^"]*"[^>]*>[\s\S]*?<\/section>/i',
		$replacement,
		$content,
		1,
		$count
	);
	if ( is_string( $updated ) && $count > 0 ) {
		$content = $updated;
	}

	return nvx_content_ensure_post_values_action_banner( $content );
}

/**
 * Safe preg_replace: never wipe content when the regex engine fails (returns null).
 *
 * @param string   $pattern  Pattern.
 * @param string   $replace  Replacement.
 * @param string   $subject  Subject HTML.
 * @param int      $limit    Limit (-1 = all).
 * @param int|null $count    Optional match count out-param.
 */
function nvx_content_preg_replace_keep( string $pattern, string $replace, string $subject, int $limit = -1, ?int &$count = null ): string {
	$result = preg_replace( $pattern, $replace, $subject, $limit, $count );
	return is_string( $result ) ? $result : $subject;
}

/**
 * Pattern: canonical post-values banner shell (id + data attribute + section child).
 */
function nvx_content_post_values_banner_pattern_with_id(): string {
	return '/<div\b[^>]*\bid=["\']nvx-post-values-action-banner["\'][^>]*\bdata-nvx-action-banner=["\']post-values["\'][^>]*>\s*<section\b[^>]*\bclass=["\'][^"\']*\bnvx-home-action-banner\b[^"\']*["\'][^>]*>[\s\S]*?<\/section>\s*<\/div>/iu';
}

/**
 * Pattern: post-values banner shell without the canonical id (data attribute only).
 */
function nvx_content_post_values_banner_pattern_data_only(): string {
	return '/<div\b[^>]*\bdata-nvx-action-banner=["\']post-values["\'][^>]*>\s*<section\b[^>]*\bclass=["\'][^"\']*\bnvx-home-action-banner\b[^"\']*["\'][^>]*>[\s\S]*?<\/section>\s*<\/div>/iu';
}

/**
 * Pattern: values dual-CTA pair only (not other .nvx-cta-pair blocks).
 */
function nvx_content_values_cta_pair_pattern(): string {
	return '/\s*<div class="nvx-cta-pair nvx-values__cta"[^>]*>[\s\S]*?<\/div>/iu';
}

/**
 * Pattern: values section open…close (structural class only).
 */
function nvx_content_values_section_pattern(): string {
	return '/(<section\b[^>]*\bclass=["\'][^"\']*\bnvx-values-section\b[^"\']*["\'][^>]*>[\s\S]*?<\/section>)/iu';
}

/**
 * Remove the canonical post-values action banner only (stable id + data attribute).
 */
function nvx_content_strip_post_values_action_banner( string $content ): string {
	$content = nvx_content_preg_replace_keep( nvx_content_post_values_banner_pattern_with_id(), '', $content );
	return nvx_content_preg_replace_keep( nvx_content_post_values_banner_pattern_data_only(), '', $content );
}

/**
 * Whether the canonical post-values banner markup is already present.
 */
function nvx_content_has_post_values_action_banner( string $content ): bool {
	return false !== strpos( $content, 'id="nvx-post-values-action-banner"' )
		|| false !== strpos( $content, "id='nvx-post-values-action-banner'" )
		|| false !== strpos( $content, 'data-nvx-action-banner="post-values"' )
		|| false !== strpos( $content, "data-nvx-action-banner='post-values'" );
}

/**
 * Insert / refresh premium action banner right after the values section.
 * Patterns are named helpers scoped to known ids/classes only.
 */
function nvx_content_ensure_post_values_action_banner( string $content ): string {
	// Remove dual CTA under values pillars before re-inserting the current banner.
	$content = nvx_content_preg_replace_keep( nvx_content_values_cta_pair_pattern(), '', $content, 1 );

	// Refresh: drop previous canonical banner then re-insert current markup.
	$content = nvx_content_strip_post_values_action_banner( $content );

	// If strip failed partially and marker remains, do not insert a second copy.
	if ( nvx_content_has_post_values_action_banner( $content ) ) {
		return $content;
	}

	$banner  = nvx_home_action_banner_markup();
	$count   = 0;
	$updated = nvx_content_preg_replace_keep(
		nvx_content_values_section_pattern(),
		'$1' . $banner,
		$content,
		1,
		$count
	);
	if ( $count > 0 ) {
		return $updated;
	}

	// Fallback: values grid close inside its parent section (still structural).
	$count   = 0;
	$updated = nvx_content_preg_replace_keep(
		'/(<div class="nvx-values">[\s\S]*?<\/div>\s*<\/div>\s*<\/section>)/iu',
		'$1' . $banner,
		$content,
		1,
		$count
	);

	return $count > 0 ? $updated : $content;
}

/**
 * Keep only the first canonical method section; remove further copies.
 */
function nvx_content_strip_extra_method_sections( string $content ): string {
	$seen    = 0;
	$updated = preg_replace_callback(
		'/<section\b[^>]*\bclass=["\'][^"\']*\bnvx-method-section\b[^"\']*["\'][^>]*>[\s\S]*?<\/section>/iu',
		static function ( array $m ) use ( &$seen ): string {
			$seen++;
			return ( 1 === $seen ) ? $m[0] : '';
		},
		$content
	);

	return is_string( $updated ) ? $updated : $content;
}

/**
 * Replace numbered method lists with icon columns — at most one block on the page.
 */
function nvx_content_replace_method_sections( string $content ): string {
	if ( false !== strpos( $content, 'nvx-method-section' ) || false !== strpos( $content, 'nvx-method-columns' ) ) {
		return nvx_content_strip_extra_method_sections( $content );
	}

	// Insert canonical method block when CMS still has an unlabeled "Cómo trabajamos" section.
	$pattern = '/<section\b(?![^>]*\bnvx-method-section\b)[^>]*aria-label="[^"]*Cómo trabajamos[^"]*"[^>]*>[\s\S]*?<\/section>/iu';
	$count   = 0;
	$updated = preg_replace( $pattern, nvx_method_section_markup(), $content, 1, $count );
	if ( is_string( $updated ) && $count > 0 ) {
		return nvx_content_strip_extra_method_sections( $updated );
	}

	return $content;
}

/**
 * Treatment card blurbs sitewide — clinical only (no prices on home cards).
 */
function nvx_content_enrich_treatment_cards( string $content ): string {
	// Never inject tariffs into cards (home or elsewhere). Prices live on treatment pages.
	$endolift_new = 'Tensado del óvalo, mandíbula y papada con microfibra láser subdérmica tras valoración. Indicado en flacidez leve–moderada y grasa submentoniana seleccionada.';

	$exion_new = 'Plataforma con aplicadores Fractional RF, Face y Body. La elección y el número de sesiones dependen del diagnóstico; no sustituye rellenos ni valoración médica.';

	// Any brand-card titled Endolift® Facial…
	$content = preg_replace(
		'/(<h3 class="nvx-brand-card__title">\s*Endolift® Facial[\s\S]*?<\/h3>\s*<p class="nvx-brand-card__body">)([\s\S]*?)(<\/p>)/u',
		'$1' . esc_html( $endolift_new ) . '$3',
		$content
	);

	// Any brand-card titled EXION®…
	$content = preg_replace(
		'/(<h3 class="nvx-brand-card__title">\s*EXION®[\s\S]*?<\/h3>\s*<p class="nvx-brand-card__body">)([\s\S]*?)(<\/p>)/u',
		'$1' . esc_html( $exion_new ) . '$3',
		$content
	);

	return is_string( $content ) ? $content : '';
}

/**
 * Director E-E-A-T wherever the Rivera card / leadership copy appears.
 */
function nvx_content_enhance_director_blocks( string $content ): string {
	$colegiado = NVX_DIRECTOR_COLEGIADO;
	$role      = sprintf(
		/* translators: %s: medical license number */
		__( 'Director Médico · Colegiado Nº %s', 'nuvanx-medical' ),
		$colegiado
	);
	$body = __( 'Especialista en Endolift®, láser CO₂ y medicina estética facial. La valoración, la indicación y el seguimiento se realizan con criterio médico. Martes y jueves: Chamberí. Miércoles: Salamanca–Goya.', 'nuvanx-medical' );

	$content = preg_replace(
		'/(class="nvx-brand-card__kicker">\s*Dr\.\s*José Javier Rivera Tejeda\s*<\/p>\s*<h3 class="nvx-brand-card__title">)([\s\S]*?)(<\/h3>\s*<p class="nvx-brand-card__body">)([\s\S]*?)(<\/p>)/u',
		'$1' . esc_html( $role ) . '$3' . esc_html( $body ) . '$5',
		$content
	);

	// Alternate: title holds the name.
	$content = preg_replace(
		'/(class="nvx-brand-card__title">\s*Dr\.\s*José Javier Rivera Tejeda\s*)(Director Médico[^<]*)?(<\/h3>\s*<p class="nvx-brand-card__body">)([\s\S]*?)(<\/p>)/u',
		'$1' . esc_html( $role ) . '$3' . esc_html( $body ) . '$5',
		$content
	);

	$lead = sprintf(
		/* translators: %s: medical license number */
		__( 'La dirección médica de NUVANX corresponde al Dr. José Javier Rivera Tejeda (Colegiado ICOMEM Nº %s). El equipo clínico realiza valoración, indicación y seguimiento en ambas sedes con un protocolo individual.', 'nuvanx-medical' ),
		$colegiado
	);

	$content = preg_replace(
		'/(Nuestro equipo médico, liderado por el Dr\.\s*José Javier Rivera Tejeda)([^<]*)(<\/p>)/u',
		esc_html( $lead ) . '$3',
		$content
	);

	return is_string( $content ) ? $content : '';
}

/**
 * Remove branded-comparison FAQs until their evidence and legal review
 * are completed. Product pages should answer patient questions, not attack
 * alternatives by name.
 */
function nvx_content_rewrite_morpheus_faq( string $content ): string {
	$updated = preg_replace_callback(
		'/<details\b[^>]*>[\s\S]*?<\/details>/iu',
		static function ( array $matches ): string {
			return preg_match( '/\bMorpheus8\b/iu', $matches[0] ) ? '' : $matches[0];
		},
		$content
	);

	return is_string( $updated ) ? $updated : $content;
}

/**
 * Whether current request is the EXION® BTL hub page.
 */
function nvx_content_is_exion_hub(): bool {
	if ( function_exists( 'nvx_schema_path_matches' ) && function_exists( 'nvx_schema_current_path' ) ) {
		$path = nvx_schema_current_path( (int) get_queried_object_id() );
		if ( nvx_schema_path_matches( $path, '/exion-btl/' ) ) {
			return true;
		}
	}
	if ( is_page() ) {
		$slug = get_post_field( 'post_name', get_queried_object_id() );
		return is_string( $slug ) && 'exion-btl' === $slug;
	}
	return false;
}

/**
 * EXION® hub investment transparency — no invented retail PVP (tariff sheet not yet locked).
 */
function nvx_exion_investment_markup(): string {
	$html  = '<section class="nvx-brand-section nvx-exion-investment" id="inversion-exion" aria-labelledby="nvx-exion-investment-title" data-nvx-block="exion-investment">';
	$html .= '<div class="nvx-shell nvx-brand-section__inner">';
	$html .= '<p class="nvx-brand-kicker">' . esc_html__( 'Inversión', 'nuvanx-medical' ) . '</p>';
	$html .= '<h2 id="nvx-exion-investment-title" class="nvx-brand-title">' . esc_html__( 'Precio de EXION® BTL en NUVANX', 'nuvanx-medical' ) . '</h2>';
	$html .= '<p class="nvx-brand-lead">' . esc_html__(
		'El PVP de EXION® no se publica como tarifa fija online porque depende del aplicador (Face, Body o Fractional RF), de la zona, del número de sesiones y de si se combina con otros protocolos. El presupuesto se documenta por escrito tras la valoración médica.',
		'nuvanx-medical'
	) . '</p>';
	$html .= '<ul class="nvx-brand-list nvx-exion-investment__factors">';
	$html .= '<li>' . esc_html__( 'Aplicador y profundidad del protocolo (Face / Body / Fractional RF).', 'nuvanx-medical' ) . '</li>';
	$html .= '<li>' . esc_html__( 'Zona o superficie tratada y objetivo clínico (textura, firmeza, contorno).', 'nuvanx-medical' ) . '</li>';
	$html .= '<li>' . esc_html__( 'Plan de sesiones habitual (a menudo 2–4) y posibles combinaciones médicas.', 'nuvanx-medical' ) . '</li>';
	$html .= '</ul>';
	$html .= '<p class="nvx-brand-lead">' . esc_html__(
		'Si buscas “EXION® BTL precio Madrid”, la respuesta honesta es: sin exploración no hay cifra fiable. En la consulta cerramos indicación, plan y PVP con IVA incluido antes de cualquier decisión.',
		'nuvanx-medical'
	) . '</p>';
	$html .= '<p class="nvx-exion-investment__cta">' . nvx_cta_pair_markup( 'nvx-exion-investment__actions' ) . '</p>';
	$html .= '</div></section>';
	return $html;
}

/**
 * Inject / refresh EXION® investment block on the hub page.
 */
function nvx_content_ensure_exion_investment( string $content ): string {
	if ( ! nvx_content_is_exion_hub() ) {
		return $content;
	}

	$block = nvx_exion_investment_markup();

	if ( false !== strpos( $content, 'id="inversion-exion"' ) || false !== strpos( $content, "id='inversion-exion'" ) ) {
		$updated = preg_replace(
			'/<section\b[^>]*\bid=["\']inversion-exion["\'][^>]*>[\s\S]*?<\/section>/iu',
			$block,
			$content,
			1
		);
		return is_string( $updated ) ? $updated : $content;
	}

	// After first FAQ accordion or before last CTA cluster; fallback append.
	$count   = 0;
	$updated = preg_replace(
		'/(<section\b[^>]*\b(?:nvx-brand-faq|nvx-faq|nvx-home-faq)[^>]*>)/iu',
		$block . '$1',
		$content,
		1,
		$count
	);
	if ( is_string( $updated ) && $count > 0 ) {
		return $updated;
	}

	return $content . $block;
}
add_filter( 'the_content', 'nvx_content_ensure_exion_investment', NVX_HOOK_PRIO_EXION_INVESTMENT );

/**
 * Unify conversion CTAs globally in post content.
 */
function nvx_content_unify_ctas( string $content ): string {
	$primary_label  = 'Iniciar mi valoración médica';
	$whatsapp_label = 'Contactar por WhatsApp';
	$valoracion_url = nvx_cta_valoracion_url();
	$whatsapp_url   = nvx_cta_whatsapp_url();

	// Paired hero / brand action clusters: primary + secondary.
	// Since we are upgrading to a new HTML structure (hero-cta-group with a <button>),
	// we completely replace the inner contents of these wrappers.
	$content = preg_replace_callback(
		'/<div\s+class="([^"]*(?:nvx-home-hero-ctas|nvx-brand-actions|nvx-page__cta|nvx-cta-pair)[^"]*)">[\s\S]*?<\/div>/u',
		static function ( array $m ): string {
			// Do not recursively nest if it already has nvx-cta-cluster.
			if ( strpos( $m[1], 'nvx-cta-cluster' ) !== false ) {
				return $m[0];
			}
			return nvx_cta_pair_markup( $m[1] );
		},
		$content
	);

	// Label normalization for remaining anchors.
	$label_map = array(
		'Solicitar valoración médica personalizada' => $primary_label,
		'Solicitar valoración médica'               => $primary_label,
		'Solicitar valoración médica gratuita'      => $primary_label,
		'Solicitar consulta médica personalizada'   => $primary_label,
		'Solicitar consulta médica'                 => $primary_label,
		'Solicitar consulta'                        => $primary_label,
		'Solicitar información'                     => $primary_label,
		'Agenda tu Valoración médica personalizada' => $primary_label,
		'Pedir cita'                                => $primary_label,
		'Reservar cita'                             => $primary_label,
		'Valoración gratuita'                       => $primary_label,
		'Cita online'                               => $primary_label,
		'Enviar'                                    => $primary_label,
		'RESERVAR CITA'                             => $primary_label,
		'Explorar tratamientos exclusivos'          => $whatsapp_label,
	);

	foreach ( $label_map as $from => $to ) {
		$content = str_ireplace( '>' . $from . '<', '>' . $to . '<', $content );
	}

	// Primary conversion anchors → valoración URL (preserve classes).
	$content = preg_replace_callback(
		'/<a\b([^>]*)>(\s*Iniciar mi valoración médica\s*)<\/a>/iu',
		static function ( array $m ) use ( $valoracion_url ): string {
			$attrs = $m[1];
			$attrs = preg_replace( '/\s*href=["\'][^"\']*["\']/i', '', $attrs ) ?? $attrs;
			return '<a' . $attrs . ' href="' . esc_url( $valoracion_url ) . '">' . $m[2] . '</a>';
		},
		$content
	);

	// WhatsApp anchors → wa.me (preserve classes).
	$content = preg_replace_callback(
		'/<a\b([^>]*)>(\s*Contactar por WhatsApp\s*)<\/a>/iu',
		static function ( array $m ) use ( $whatsapp_url ): string {
			$attrs = $m[1];
			$attrs = preg_replace( '/\s*href=["\'][^"\']*["\']/i', '', $attrs ) ?? $attrs;
			$attrs = preg_replace( '/\s*target=["\'][^"\']*["\']/i', '', $attrs ) ?? $attrs;
			$attrs = preg_replace( '/\s*rel=["\'][^"\']*["\']/i', '', $attrs ) ?? $attrs;
			return '<a' . $attrs . ' href="' . esc_url( $whatsapp_url ) . '" target="_blank" rel="noopener noreferrer">' . $m[2] . '</a>';
		},
		$content
	);

	// Invitation free-text blocks → dual CTA pair.
	$content = preg_replace(
		'/<div class="nvx-home-invitation">[\s\S]*?<\/div>/u',
		nvx_cta_pair_markup( 'nvx-home-invitation' ),
		$content
	);

	// Final band CTAs.
	$content = preg_replace(
		'/(class="[^"]*nvx-home-cta-final-band[^"]*"[\s\S]*?<a[^>]*href=")[^"]*("[^>]*>)([^<]*)(<\/a>)/u',
		'$1' . esc_url( $valoracion_url ) . '$2' . esc_html( $primary_label ) . '$4',
		$content
	);

	return is_string( $content ) ? $content : '';
}

/**
 * Strip inline style attributes from hero containers so cascade wins without
 * the important flag (CSS Gate).
 */
function nvx_content_strip_hero_inline_styles( string $content ): string {
	// Opening tags for hero stages / copy that may carry inline layout residue.
	$hero_bits  = 'nvx-brand-hero|nvx-editorial-hero|nvx-page-hero|nvx-hero|nvx-home-hero-stage|nvx-ipl-hero';
	$copy_bits  = 'nvx-brand-hero__copy|nvx-hero__copy|nvx-page-hero__copy|nvx-editorial-hero__copy|nvx-ipl-hero__copy';
	$inner_bits = 'nvx-brand-hero__inner|nvx-hero__inner|nvx-page-hero__inner';
	$pattern    = '/(<(?:section|div)\b[^>]*\bclass="[^"]*\b(?:' . $hero_bits . '|' . $copy_bits . '|' . $inner_bits . ')\b[^"]*"[^>]*)\s+style="[^"]*"/iu';
	$updated    = preg_replace( $pattern, '$1', $content );
	return is_string( $updated ) ? $updated : $content;
}

/**
 * Convert residual CMS IPL hero shell to the single interior brand hero.
 *
 * EXILITE and related CMS pages still store nvx-ipl-* markup with no theme CSS.
 */
function nvx_content_convert_ipl_hero_to_brand( string $content ): string {
	if ( false === stripos( $content, 'nvx-ipl-hero' ) ) {
		return $content;
	}

	$updated = preg_replace_callback(
		'/<section\b([^>]*\bclass=(["\'])([^"\']*\bnvx-ipl-hero\b[^"\']*)\2[^>]*)>([\s\S]*?)<\/section>/iu',
		static function ( array $m ): string {
			$attrs = $m[1];
			$inner = $m[4];

			// Promote IPL BEM tokens inside the hero to brand-hero tokens.
			$map = array(
				'nvx-ipl-hero__copy'  => 'nvx-brand-hero__copy',
				'nvx-ipl-hero__media' => 'nvx-brand-hero__media',
				'nvx-ipl-kicker'      => 'nvx-brand-kicker',
				'nvx-ipl-h1'          => 'nvx-brand-hero__title',
				'nvx-ipl-lead'        => 'nvx-brand-hero__lead',
				'nvx-ipl-meta'        => 'nvx-brand-meta',
				'nvx-ipl-actions'     => 'nvx-cta-cluster',
			);
			foreach ( $map as $from => $to ) {
				$inner = preg_replace( '/\b' . preg_quote( $from, '/' ) . '\b/u', $to, $inner ) ?? $inner;
			}

			// Drop figure body-role classes that break full-bleed cover.
			$inner = preg_replace( '/\s*nvx-content-figure\s*/u', ' ', $inner ) ?? $inner;

			// Preserve non-class attributes (aria-label, etc.).
			$attrs = preg_replace( '/\s*class=(["\'])[^"\']*\1/iu', '', $attrs ) ?? $attrs;
			$attrs = trim( $attrs );

			$open = '<section class="nvx-brand-hero"';
			if ( '' !== $attrs ) {
				$open .= ' ' . $attrs;
			}
			$open .= '>';

			// Ensure absolute stage inner wrapper when missing.
			if ( false === stripos( $inner, 'nvx-brand-hero__inner' ) ) {
				$inner = '<div class="nvx-brand-hero__inner">' . $inner . '</div>';
			}

			return $open . $inner . '</section>';
		},
		$content,
		1
	);

	return is_string( $updated ) ? $updated : $content;
}

/**
 * Map residual IPL body class tokens onto brand section primitives.
 * Keeps CMS editorial body readable once the hero shell is unified.
 */
function nvx_content_map_ipl_body_to_brand( string $content ): string {
	if ( false === stripos( $content, 'nvx-ipl-' ) ) {
		return $content;
	}

	$map = array(
		'nvx-ipl-editorial'     => 'nvx-brand-page',
		'nvx-ipl-section--soft' => 'nvx-brand-section',
		'nvx-ipl-section--ink'  => 'nvx-brand-section',
		'nvx-ipl-section'       => 'nvx-brand-section',
		'nvx-ipl-shell'         => 'nvx-brand-section__inner',
		'nvx-ipl-intro'         => 'nvx-brand-intro',
		'nvx-ipl-mechanism'     => 'nvx-brand-mechanism',
		'nvx-ipl-faq'           => 'nvx-brand-faq',
		'nvx-ipl-process'       => 'nvx-brand-process',
		'nvx-ipl-facts'         => 'nvx-brand-facts',
		'nvx-ipl-grid'          => 'nvx-brand-grid nvx-brand-grid--3',
		'nvx-ipl-item'          => 'nvx-brand-card',
		'nvx-ipl-index'         => 'nvx-brand-card__kicker',
		'nvx-ipl-kicker'        => 'nvx-brand-kicker',
		'nvx-ipl-h2'            => 'nvx-brand-title',
		'nvx-ipl-h3'            => 'nvx-brand-card__title',
		'nvx-ipl-copy'          => 'nvx-brand-copy',
		'nvx-ipl-lead'          => 'nvx-brand-hero__lead',
		'nvx-ipl-meta'          => 'nvx-brand-meta',
		'nvx-ipl-actions'       => 'nvx-cta-cluster',
	);

	foreach ( $map as $from => $to ) {
		$content = preg_replace( '/\b' . preg_quote( $from, '/' ) . '\b/u', $to, $content ) ?? $content;
	}

	return $content;
}

/**
 * Collapse every interior hero shell to pure .nvx-brand-hero.
 * Strips page modifiers (--laser/--medical/--btl/…) and legacy skin classes.
 */
function nvx_content_normalize_interior_hero_shells( string $content ): string {
	if ( '' === trim( $content ) ) {
		return $content;
	}

	$content = nvx_content_convert_ipl_hero_to_brand( $content );
	$content = nvx_content_map_ipl_body_to_brand( $content );

	// Normalize class attributes on sections/divs that carry a hero stage.
	$updated = preg_replace_callback(
		'/<((?:section|div))\b([^>]*\bclass=(["\'])([^"\']*)\3[^>]*)>/iu',
		static function ( array $m ): string {
			$tag   = $m[1];
			$attrs = $m[2];
			$quote = $m[3];
			$class = $m[4];

			$is_hero_stage = (bool) preg_match(
				'/\b(?:nvx-brand-hero|nvx-editorial-hero|nvx-page-hero|nvx-ipl-hero)\b/u',
				$class
			);
			$is_hero_copy  = (bool) preg_match(
				'/\b(?:nvx-brand-hero__copy|nvx-editorial-hero__copy|nvx-page-hero__copy|nvx-hero__copy|nvx-ipl-hero__copy)\b/u',
				$class
			);
			$is_hero_media = (bool) preg_match(
				'/\b(?:nvx-brand-hero__media|nvx-page-hero__media|nvx-ipl-hero__media)\b/u',
				$class
			);
			$is_hero_inner = (bool) preg_match(
				'/\b(?:nvx-brand-hero__inner|nvx-page-hero__inner|nvx-hero__inner)\b/u',
				$class
			);

			if ( ! $is_hero_stage && ! $is_hero_copy && ! $is_hero_media && ! $is_hero_inner ) {
				return $m[0];
			}

			$tokens = preg_split( '/\s+/u', trim( $class ) ) ?: array();
			$keep   = array();

			foreach ( $tokens as $token ) {
				if ( '' === $token ) {
					continue;
				}
				// Drop BEM modifiers on the brand hero, except the authorized media opt-in and surface-ink.
				if ( preg_match( '/^nvx-brand-hero--/u', $token ) ) {
					if ( 'nvx-brand-hero--has-media' !== $token && 'nvx-brand-hero--surface-ink' !== $token ) {
						continue;
					}
				}
				// Drop page-specific hero skins and copy modifiers.
				if ( preg_match( '/^nvx-(?:Endolift®|endolaser|co2|aes|equipo|nosotros|ipl|btl|exilite|laser)(?:-hero(?:-copy)?|--copy-only)?$/u', $token ) ) {
					continue;
				}
				if ( preg_match( '/-hero(?:-copy)?$/u', $token ) && 'nvx-brand-hero' !== $token && ! preg_match( '/^nvx-brand-hero__/u', $token ) ) {
					// e.g. nvx-exion-hero, marker-hero leftovers.
					if ( preg_match( '/^nvx-/u', $token ) ) {
						continue;
					}
				}
				// Canonicalize alternate stage names.
				if ( in_array( $token, array( 'nvx-editorial-hero', 'nvx-page-hero', 'nvx-ipl-hero' ), true ) ) {
					$token = 'nvx-brand-hero';
				}
				if ( in_array( $token, array( 'nvx-editorial-hero__copy', 'nvx-page-hero__copy', 'nvx-hero__copy', 'nvx-ipl-hero__copy' ), true ) ) {
					$token = 'nvx-brand-hero__copy';
				}
				if ( in_array( $token, array( 'nvx-page-hero__media', 'nvx-ipl-hero__media' ), true ) ) {
					$token = 'nvx-brand-hero__media';
				}
				if ( in_array( $token, array( 'nvx-page-hero__inner', 'nvx-hero__inner' ), true ) ) {
					$token = 'nvx-brand-hero__inner';
				}
				// Skin copy suffixes like nvx-endolift-hero-copy already dropped above.
				if ( preg_match( '/^nvx-(?:Endolift®|aes|equipo|co2|endolaser)-hero-copy$/u', $token ) ) {
					continue;
				}
				$keep[] = $token;
			}

			// Ensure stage token exists when we rewrote a legacy stage.
			if ( $is_hero_stage && ! in_array( 'nvx-brand-hero', $keep, true ) ) {
				array_unshift( $keep, 'nvx-brand-hero' );
			}
			if ( $is_hero_copy && ! in_array( 'nvx-brand-hero__copy', $keep, true ) ) {
				array_unshift( $keep, 'nvx-brand-hero__copy' );
			}
			if ( $is_hero_media && ! in_array( 'nvx-brand-hero__media', $keep, true ) ) {
				array_unshift( $keep, 'nvx-brand-hero__media' );
			}
			if ( $is_hero_inner && ! in_array( 'nvx-brand-hero__inner', $keep, true ) ) {
				array_unshift( $keep, 'nvx-brand-hero__inner' );
			}

			$keep      = array_values( array_unique( $keep ) );
			$new_class = implode( ' ', $keep );
			$attrs     = preg_replace(
				'/\bclass=(["\'])[^"\']*\1/u',
				'class=' . $quote . esc_attr( $new_class ) . $quote,
				$attrs,
				1
			);

			return '<' . $tag . ( is_string( $attrs ) ? $attrs : $m[2] ) . '>';
		},
		$content
	);

	return is_string( $updated ) ? $updated : $content;
}

/**
 * Append a CSS class token to an HTML attribute string.
 */
function nvx_html_attrs_add_class( string $attrs, string $class_token ): string {
	if ( preg_match( '/\bclass=(["\'])([^"\']*)\1/i', $attrs, $cm ) ) {
		if ( false !== strpos( $cm[2], $class_token ) ) {
			return $attrs;
		}
		$updated = preg_replace(
			'/\bclass=(["\'])/',
			'class=$1' . $class_token . ' ',
			$attrs,
			1
		);
		return is_string( $updated ) ? $updated : $attrs;
	}

	return $attrs . ' class="' . esc_attr( $class_token ) . '"';
}

/**
 * Normalize a doctor/portrait img tag (no body crop role).
 *
 * @param array<int,string> $im preg_replace_callback matches.
 */
function nvx_content_normalize_doctor_img_tag( array $im ): string {
	$a = $im[1];
	if ( preg_match( '/nvx-logo|nvx-media--hero/i', $a ) ) {
		return '<img' . $a . '>';
	}
	$a = preg_replace( '/\s+style=["\'][^"\']*["\']/i', '', $a ) ?? $a;
	$a = preg_replace( '/\s*nvx-media--body\s*/i', ' ', $a ) ?? $a;
	$a = nvx_html_attrs_add_class( $a, 'nvx-media' );
	$a = nvx_html_attrs_add_class( $a, 'nvx-media--doctor' );
	if ( function_exists( 'nvx_content_enhance_img_tag_attrs' ) ) {
		$a = nvx_content_enhance_img_tag_attrs( $a );
	}
	return '<img' . $a . '>';
}

/**
 * Normalize a body-content img tag.
 *
 * @param array<int,string> $m preg_replace_callback matches.
 */
function nvx_content_normalize_body_img_tag( array $m ): string {
	$attrs = $m[1];
	if ( preg_match( '/nvx-logo|nvx-home-hero|nvx-media--hero|nvx-media--doctor/i', $attrs ) ) {
		return '<img' . $attrs . '>';
	}

	$attrs = preg_replace( '/\s+style=["\'][^"\']*["\']/i', '', $attrs ) ?? $attrs;
	// Strip accidental body role if ever re-processed on a hero path.
	$attrs = preg_replace( '/\s*nvx-media--body\s*/i', ' ', $attrs ) ?? $attrs;
	$attrs = nvx_html_attrs_add_class( $attrs, 'nvx-media' );
	$attrs = nvx_html_attrs_add_class( $attrs, 'nvx-media--body' );
	if ( function_exists( 'nvx_content_enhance_img_tag_attrs' ) ) {
		$attrs = nvx_content_enhance_img_tag_attrs( $attrs );
	}

	return '<img' . $attrs . '>';
}

/**
 * Tag body figures that are not portraits/formula stages.
 */
function nvx_content_tag_body_figures( string $content ): string {
	$skip_figure = 'nvx-content-figure|nvx-endolift-formula|nvx-laser-formula|nvx-aes-formula|nvx-equipo-portrait|nvx-brand-card__media|nvx-brand-card__media--portrait';

	$updated = preg_replace_callback(
		'/<figure\b([^>]*)>/iu',
		static function ( array $m ) use ( $skip_figure ): string {
			$attrs = $m[1];
			if ( preg_match( '/' . $skip_figure . '/i', $attrs ) ) {
				return '<figure' . $attrs . '>';
			}
			return '<figure' . nvx_html_attrs_add_class( $attrs, 'nvx-content-figure' ) . '>';
		},
		$content
	);

	return is_string( $updated ) ? $updated : $content;
}

/**
 * Protect team/card portrait frames and normalize their doctor media role.
 *
 * @param array<string,string> $team_slots Slot map filled by reference.
 */
function nvx_content_protect_team_media( string $content, array &$team_slots ): string {
	$protected = preg_replace_callback(
		'/<figure\b([^>]*\bclass=["\'][^"\']*\b(?:nvx-brand-card__media|nvx-equipo-portrait)\b[^"\']*["\'][^>]*)>([\s\S]*?)<\/figure>/iu',
		static function ( array $m ) use ( &$team_slots ): string {
			$attrs = $m[1];
			// Only card media gets the portrait media class; authority figures keep nvx-equipo-portrait.
			if ( false !== stripos( $attrs, 'nvx-brand-card__media' ) ) {
				$attrs = nvx_html_attrs_add_class( $attrs, 'nvx-brand-card__media--portrait' );
			}
			$inner              = $m[2];
			$inner              = preg_replace( '/\bnvx-media--body\b/i', 'nvx-media--doctor', $inner ) ?? $inner;
			$inner              = preg_replace_callback(
				'/<img\b([^>]*)>/iu',
				'nvx_content_normalize_doctor_img_tag',
				$inner
			);
			$key                = '<!--NVX_TEAM_MEDIA_' . count( $team_slots ) . '-->';
			$team_slots[ $key ] = '<figure' . $attrs . '>' . ( is_string( $inner ) ? $inner : $m[2] ) . '</figure>';
			return $key;
		},
		$content
	);

	return is_string( $protected ) ? $protected : $content;
}

/**
 * Protect hero media blocks so imgs inside never get nvx-media--body.
 *
 * @param array<string,string> $hero_slots Slot map filled by reference.
 */
function nvx_content_protect_hero_media( string $content, array &$hero_slots ): string {
	$protected = preg_replace_callback(
		'/<((?:figure|div))\b([^>]*\bclass=["\'][^"\']*\bnvx-(?:brand|editorial|page)?-?hero__media\b[^"\']*["\'][^>]*)>([\s\S]*?)<\/\1>/iu',
		static function ( array $m ) use ( &$hero_slots ): string {
			$key                = '<!--NVX_HERO_MEDIA_' . count( $hero_slots ) . '-->';
			$hero_slots[ $key ] = $m[0];
			return $key;
		},
		$content
	);

	return is_string( $protected ) ? $protected : $content;
}

/**
 * Normalize body figures/images so every page shares the same media rules.
 * Heroes are left untouched (full-bleed stage) — extracted before body tagging.
 */
function nvx_content_normalize_body_media( string $content ): string {
	$hero_slots = array();
	$content    = nvx_content_protect_hero_media( $content, $hero_slots );
	$content    = nvx_content_tag_body_figures( $content );

	$team_slots = array();
	$content    = nvx_content_protect_team_media( $content, $team_slots );

	$updated = preg_replace_callback(
		'/<img\b([^>]*)>/iu',
		'nvx_content_normalize_body_img_tag',
		$content
	);
	$content = is_string( $updated ) ? $updated : $content;

	// Restore protected media untouched.
	if ( ! empty( $team_slots ) ) {
		$content = str_replace( array_keys( $team_slots ), array_values( $team_slots ), $content );
	}
	// Restore hero media untouched (no body classes, full-bleed cover intact).
	if ( ! empty( $hero_slots ) ) {
		$content = str_replace( array_keys( $hero_slots ), array_values( $hero_slots ), $content );
	}

	return $content;
}

/**
 * Remove body façade block when the page hero already shows the clinic photo.
 *
 * @param string $content HTML.
 * @return string
 */
function nvx_content_strip_duplicate_fachada( string $content ): string {
	if ( ! preg_match( '/nvx-(?:brand|page|editorial)-hero__media/i', $content ) ) {
		return $content;
	}

	$updated = preg_replace(
		'/\s*<section\b[^>]*\bnvx-brand-section--fachada\b[^>]*>[\s\S]*?<\/section>/iu',
		'',
		$content,
		1
	);

	return is_string( $updated ) ? $updated : $content;
}

function nvx_content_presentation_enhance( string $content ): string {
	if ( is_admin() || '' === trim( $content ) ) {
		return $content;
	}

	// Feeds / REST: keep raw.
	if ( is_feed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return $content;
	}

	$content = nvx_content_strip_hero_inline_styles( $content );
	$content = nvx_content_normalize_interior_hero_shells( $content );
	$content = nvx_content_strip_duplicate_fachada( $content );
	$content = nvx_content_normalize_body_media( $content );
	// Values/method CMS residual transforms still apply on non-home routes that
	// pass post_content through the_content. Front page is theme-owned (shell).
	$content = nvx_content_replace_values_sections( $content );
	$content = nvx_content_replace_method_sections( $content );
	$content = nvx_content_enrich_treatment_cards( $content );
	$content = nvx_content_enhance_director_blocks( $content );
	$content = nvx_content_rewrite_morpheus_faq( $content );
	$content = nvx_content_unify_ctas( $content );
	// Closing CTA strip runs once at priority 99 (after page modules at ~19 rebuild content).

	return $content;
}
add_filter( 'the_content', 'nvx_content_presentation_enhance', NVX_HOOK_PRIO_PRESENTATION_ENHANCE );

/**
 * Single late strip of page-local closing CTAs after modules rebuild the_content.
 * Only footer.php nvx-cta-banner remains as the site-wide closing band.
 */
function nvx_content_strip_page_closing_ctas_late( string $content ): string {
	if ( is_admin() || is_feed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return $content;
	}
	return nvx_content_strip_page_closing_ctas( $content );
}
add_filter( 'the_content', 'nvx_content_strip_page_closing_ctas_late', NVX_HOOK_PRIO_STRIP_PAGE_CTAS );


/**
 * Global Before/After Teaser markup (sitewide).
 */
function nvx_before_after_teaser_markup(): string {
	$cases_page_id = 2645;
	if ( function_exists( 'nvx_noindex_page_ids' ) && in_array( $cases_page_id, nvx_noindex_page_ids(), true ) ) {
		return '';
	}

	$url = get_permalink( $cases_page_id );
	if ( ! is_string( $url ) || '' === $url ) {
		return '';
	}
	$html  = '<section class="nvx-ba-teaser" aria-label="' . esc_attr__( 'Resultados clínicos', 'nuvanx-medical' ) . '">';
	$html .= '<div class="nvx-ba-teaser__inner">';
	$html .= '<div class="nvx-ba-teaser__copy">';
	$html .= '<p class="nvx-ba-teaser__kicker">' . esc_html__( 'Evidencia clínica', 'nuvanx-medical' ) . '</p>';
	$html .= '<h2 class="nvx-ba-teaser__title">' . esc_html__( 'Resultados reales, sin filtros', 'nuvanx-medical' ) . '</h2>';
	$html .= '<p class="nvx-ba-teaser__body">' . esc_html__( 'Explora nuestra galería de casos clínicos documentados. Evolución real de pacientes NUVANX sometidos a protocolos láser y médico-estéticos.', 'nuvanx-medical' ) . '</p>';
	$html .= '</div>';
	$html .= '<div class="nvx-ba-teaser__cta">';
	$html .= sprintf(
		'<a href="%1$s" class="nvx-brand-btn nvx-btn--light">%2$s</a>',
		esc_url( $url ),
		esc_html__( 'Ver galería de resultados', 'nuvanx-medical' )
	);
	$html .= '</div></div></section>';
	return $html;
}

/**
 * Global Treatment Process markup (generic).
 */
function nvx_treatment_process_markup(): string {
	$html  = '<section class="nvx-treatment-process" aria-label="' . esc_attr__( 'Proceso clínico', 'nuvanx-medical' ) . '">';
	$html .= '<div class="nvx-treatment-process__inner">';
	$html .= '<p class="nvx-treatment-process__kicker">' . esc_html__( 'El procedimiento', 'nuvanx-medical' ) . '</p>';
	$html .= '<h2 class="nvx-treatment-process__title">' . esc_html__( 'Cómo funciona tu tratamiento', 'nuvanx-medical' ) . '</h2>';
	$html .= '<blockquote class="nvx-equipo-blockquote"><p>' . esc_html__( 'El día más importante de tu protocolo no es el de la sesión. Es el seguimiento.', 'nuvanx-medical' ) . '</p></blockquote>';
	$html .= '<ol class="nvx-treatment-process__steps">';
	$steps = array(
		array( 'Valoración médica', 'Evaluación presencial de tu anatomía y calidad cutánea para confirmar la indicación exacta del tratamiento.' ),
		array( 'Procedimiento', 'Intervención ambulatoria de alta precisión, respetando tiempos biológicos y maximizando el confort.' ),
		array( 'Seguimiento', 'Pautas de cuidado domiciliario y revisión en consulta para documentar y asegurar la correcta evolución.' ),
	);
	foreach ( $steps as $step ) {
		$html .= '<li class="nvx-treatment-process__step">';
		$html .= '<h3 class="nvx-treatment-process__step-title">' . esc_html( $step[0] ) . '</h3>';
		$html .= '<p class="nvx-treatment-process__step-body">' . esc_html( $step[1] ) . '</p>';
		$html .= '</li>';
	}
	$html .= '</ol></div></section>';
	return $html;
}

/**
 * Generic FAQ markup for pages missing them.
 */
function nvx_generic_faq_markup(): string {
	$html  = '<section class="nvx-brand-section nvx-faq-section" aria-labelledby="nvx-generic-faq-title">';
	$html .= '<div class="nvx-shell nvx-brand-section__inner">';
	$html .= '<h2 class="nvx-brand-title" id="nvx-generic-faq-title">' . esc_html__( 'Preguntas Frecuentes', 'nuvanx-medical' ) . '</h2>';
	$html .= '<div class="nvx-faq nvx-generic-faq-list">';

	$faqs = array(
		array( '¿Duele el procedimiento?', 'La percepción varía según el umbral personal y la zona. Según el protocolo pueden usarse anestesia local, frío o cremas tópicas para mejorar el confort; la experiencia se valora de forma individual en consulta.' ),
		array( '¿Cuánta recuperación necesito?', 'Depende del tratamiento, la intensidad del protocolo y tu respuesta individual. Algunos procedimientos permiten retomar la actividad habitual con rapidez; otros (por ejemplo, láser ablativo) implican eritema, descamación o varios días de curación. La pauta exacta se define en la valoración y el consentimiento.' ),
		array( '¿Cuándo se notan los resultados?', 'Depende del mecanismo del tratamiento. Algunos cambios se aprecian en los primeros días; cuando el objetivo es estimular colágeno, la evolución se valora a lo largo de semanas. No hay un calendario único para todos los protocolos.' ),
		array( '¿Es para mí?', 'La candidatura clínica solo puede determinarse de forma responsable mediante una valoración médica presencial. Estudiamos tu historial, la viabilidad de los tejidos y tus objetivos para trazar el plan adecuado.' ),
	);

	foreach ( $faqs as $i => $faq ) {
		$open  = ( 0 === $i ) ? ' open' : '';
		$html .= '<details class="nvx-brand-faq-item"' . $open . '>';
		$html .= '<summary><span>' . esc_html( $faq[0] ) . '</span><span class="nvx-brand-faq-icon"></span></summary>';
		$html .= '<div class="nvx-brand-faq-item__body"><p>' . esc_html( $faq[1] ) . '</p></div>';
		$html .= '</details>';
	}

	$html .= '</div></div></section>';
	return $html;
}

/**
 * Whether the current request is a real treatment detail/hub page.
 *
 * Must not match equipo/nosotros: those reuse Endolift® editorial layout classes
 * (nvx-endolift-editorial, nvx-endolift-hero) but are not treatments.
 */
function nvx_content_is_treatment_injection_target( string $content ): bool {
	// Explicit non-treatment shells that share layout classes with treatments.
	if (
		preg_match(
			'/nvx-equipo-editorial|nvx-equipo-hero|nvx-brand-page--nosotros|nvx-brand-page--equipo|id=["\']nvx-nosotros-h1["\']|id=["\']nvx-equipo-h1["\']|aria-label=["\']Equipo médico NUVANX["\']|aria-label=["\']Sobre Nosotros NUVANX["\']/iu',
			$content
		)
	) {
		return false;
	}

	// Canonical treatment routes from the schema page registry.
	if ( function_exists( 'nvx_schema_resolve_treatment_key' ) ) {
		$key = nvx_schema_resolve_treatment_key( (int) get_queried_object_id() );
		if ( null !== $key && '' !== (string) $key ) {
			return true;
		}
	}

	// Explicit treatment identifiers only (no bare nvx-endolift-editorial / nvx-brand-page--*).
	$treatment_markers = array(
		'nvx-endolaser-editorial',
		'nvx-endolaser-hero',
		'nvx-co2-editorial',
		'nvx-co2-hero',
		'nvx-btl-editorial',
		'nvx-aesthetic-editorial',
		'nvx-laser-hub-page',
		'nvx-brand-page--laser-hub',
		'nvx-laser-editorial',
		'nvx-brand-page--medicina-estetica',
		'nvx-brand-page--exion',
		'id="nvx-endolift-h1"',
		"id='nvx-endolift-h1'",
		'id="nvx-endolaser-h1"',
		"id='nvx-endolaser-h1'",
		'id="nvx-co2-h1"',
		"id='nvx-co2-h1'",
		'id="nvx-laser-h1"',
		"id='nvx-laser-h1'",
		'id="nvx-med-h1"',
		"id='nvx-med-h1'",
		'aria-label="Endolift® facial NUVANX"',
		"aria-label='Endolift® facial NUVANX'",
		'aria-label="Medicina estética láser NUVANX"',
		"aria-label='Medicina estética láser NUVANX'",
	);

	foreach ( $treatment_markers as $marker ) {
		if ( false !== stripos( $content, $marker ) ) {
			return true;
		}
	}

	// Endolift® facial detail: hero + editorial without equipo prefix.
	if (
		false !== strpos( $content, 'nvx-endolift-hero' )
		&& false === strpos( $content, 'nvx-equipo-' )
		&& (
			false !== strpos( $content, 'nvx-endolift-process' )
			|| false !== strpos( $content, 'nvx-brand-section' )
			|| false !== strpos( $content, 'Endolift®' )
		)
	) {
		return true;
	}

	return false;
}

/**
 * Whether the current content is a CO₂ page that must not receive generic FAQ.
 */
function nvx_content_is_co2_treatment_page( string $content ): bool {
	if (
		false !== strpos( $content, 'nvx-co2-editorial' )
		|| false !== strpos( $content, 'nvx-co2-hero' )
		|| false !== strpos( $content, 'nvx-co2-downtime' )
	) {
		return true;
	}

	return function_exists( 'nvx_schema_resolve_treatment_key' )
		&& 'laser_co2' === nvx_schema_resolve_treatment_key( (int) get_queried_object_id() );
}

/**
 * Build optional shared treatment section injections for a page.
 */
function nvx_content_build_treatment_section_injections( string $content ): string {
	$injections = '';

	// 1. Before/After teaser (promotional gallery link — no numeric claims).
	if ( false === strpos( $content, 'nvx-ba-teaser' ) ) {
		$injections .= nvx_before_after_teaser_markup();
	}

	// 2. Trust badges intentionally omitted until claims-register approved figures exist.

	// 3. How It Works / Process — skip when the page already documents process/downtime.
	$has_process = preg_match(
		'/nvx-method-section|nvx-endolift-process|nvx-co2-downtime|nvx-treatment-process__steps|nvx-treatment-process|Procedimiento, sesiones y cuidados/iu',
		$content
	);
	if ( ! $has_process ) {
		$injections .= nvx_treatment_process_markup();
	}

	// 4. FAQ — only if the page has none. Skip CO₂: recovery is protocol-specific and
	// already described on-page (do not inject generic “immediate return” answers).
	$has_faq = preg_match( '/nvx-brand-faq-item|nvx-faq|nvx-generic-faq-list/iu', $content );
	if ( ! $has_faq && ! nvx_content_is_co2_treatment_page( $content ) ) {
		$injections .= nvx_generic_faq_markup();
	}

	return $injections;
}

/**
 * Append injections before a trailing wrapper close, else at end of content.
 */
function nvx_content_append_injections( string $content, string $injections ): string {
	if ( '' === $injections ) {
		return $content;
	}

	if ( preg_match( '/<\/div>\s*$/i', $content ) ) {
		$replaced = preg_replace( '/(<\/div>\s*)$/i', $injections . '$1', $content );
		return is_string( $replaced ) ? $replaced : $content . $injections;
	}

	return $content . $injections;
}

/**
 * Auto-inject shared treatment sections into real treatment pages that lack them.
 */
function nvx_content_inject_global_treatment_sections( string $content ): string {
	if ( is_admin() || is_feed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return $content;
	}
	if ( ! is_page() ) {
		return $content;
	}

	// Skip Protocolos Signature page (ID 3369) - content is managed by nvx-signature-phase-pages.php
	if ( 3369 === get_queried_object_id() ) {
		return $content;
	}

	if ( ! nvx_content_is_treatment_injection_target( $content ) ) {
		return $content;
	}

	return nvx_content_append_injections(
		$content,
		nvx_content_build_treatment_section_injections( $content )
	);
}
add_filter( 'the_content', 'nvx_content_inject_global_treatment_sections', NVX_HOOK_PRIO_GLOBAL_TREATMENT );

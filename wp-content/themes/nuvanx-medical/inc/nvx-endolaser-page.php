<?php
/**
 * Endoláser corporal page — laserlipólisis + retracción cutánea.
 *
 * Wire-frame: Hero → Mecanismo dual → Zonas → Exclusión → Planificación → CTA.
 * Does not repeat Endolift® facial encyclopedia (formula 1470 / papada focus).
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/nvx-page-render-helpers.php';

/**
 * Singular context for Endoláser rewrite.
 */
function nvx_endolaser_is_singular_context(): bool {
	if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return false;
	}

	return is_singular( 'page' ) || is_page();
}

/**
 * Detect Endoláser corporal detail page only (never home / hub / other treatments).
 */
function nvx_content_is_endolaser_page( string $content ): bool {
	// Already rewritten once this request.
	if ( false !== strpos( $content, 'nvx-endolaser-editorial' ) ) {
		return false;
	}

	if ( ! nvx_endolaser_is_singular_context() ) {
		return false;
	}

	// Never hijack front page or non-page views (home mentions Endoláser in protocols).
	if ( is_front_page() || is_home() ) {
		return false;
	}

	// Authoritative: canonical path of the treatment page.
	$path = function_exists( 'nvx_schema_current_path' )
		? nvx_schema_current_path( (int) get_queried_object_id() )
		: '';

	if ( is_string( $path ) && false !== strpos( $path, 'endolaser-corporal' ) ) {
		return true;
	}

	// Structural markers only if CMS already used our classes (not free-text "Endoláser" on other pages).
	return (bool) preg_match(
		'/aria-label=["\']Endoláser corporal NUVANX["\']|id=["\']nvx-endolaser-h1["\']|class=["\'][^"\']*nvx-endolaser-hero/iu',
		$content
	);
}

/**
 * Builds the Endoláser hero copy markup from the page catalog data.
 *
 * @return string The rendered hero copy HTML.
 */
function nvx_endolaser_hero_copy_markup(): string {
	require_once __DIR__ . '/nvx-catalog-json.php';
	$data = nvx_catalog_json_resolved( 'endolaser-page.json' )['hero'] ?? array();

	$html  = '<div class="nvx-brand-hero__copy">';
	$html .= '<p class="nvx-brand-kicker">' . esc_html( $data['kicker'] ?? '' ) . '</p>';
	$html .= '<h1 class="nvx-brand-hero__title" id="nvx-endolaser-h1">' . esc_html( $data['h1'] ?? '' ) . '</h1>';

	// E-E-A-T Medical Authority Byline
	$html .= '<div class="nvx-medical-byline">';
	$html .= '<div class="nvx-medical-byline__text">';
	$html .= '<strong>' . esc_html( $data['byline_author'] ?? '' ) . '</strong><br>';
	$html .= '<span class="nvx-medical-byline__title">' . esc_html( $data['byline_title'] ?? '' ) . '</span>';
	$html .= '</div></div>';
	$html .= '<p class="nvx-brand-hero__lead">' . esc_html( $data['lead'] ?? '' ) . '</p>';
	$html .= '<p class="nvx-brand-hero__description">' . esc_html( $data['description'] ?? '' ) . '</p>';

	if ( function_exists( 'nvx_cta_pair_markup' ) ) {
		$html .= nvx_cta_pair_markup( 'nvx-brand-actions' );
	} else {
		$html .= '<div class="nvx-brand-actions"><a class="nvx-brand-btn nvx-brand-btn--primary" href="' . esc_url( home_url( '/madrid/valoracion/' ) ) . '">' . esc_html__( 'Reservar valoración médica', 'nuvanx-medical' ) . '</a></div>';
	}

	$html .= '<p class="nvx-brand-meta">' . esc_html( $data['meta'] ?? '' ) . '</p>';
	$html .= '</div>';

	return $html;
}

/**
 * Generates the Endoláser editorial body markup, including treatment mechanisms, zones, candidacy guidance, and planning information.
 *
 * @return string The escaped HTML markup for the editorial body.
 */
/** Render mechanism section for Endolaser page. */
function nvx_endolaser_mechanism_section( array $data ): string {
	$html  = nvx_page_brand_section_open_markup( 'nvx-endolaser-mechanism', 'nvx-endolaser-mech-title' );
	$html .= nvx_page_brand_section_heading_markup( esc_html( $data['mechanism']['kicker'] ?? '' ), 'nvx-endolaser-mech-title', esc_html( $data['mechanism']['title'] ?? '' ) );
	foreach ( $data['mechanism']['body'] ?? array() as $paragraph ) {
		$html .= '<p class="nvx-body nvx-body--measure">' . esc_html( $paragraph ) . '</p>';
	}
	$html   .= '<div class="nvx-endolift-effects">';
	$eff_idx = 0;
	foreach ( $data['mechanism']['effects'] ?? array() as $effect ) {
		$eid   = 'nvx-endolaser-effect-' . $eff_idx;
		$html .= '<article class="nvx-endolift-effect" aria-labelledby="' . esc_attr( $eid ) . '"><h3 id="' . esc_attr( $eid ) . '" class="nvx-endolift-effect__title">' . esc_html( $effect['title'] ?? '' ) . '</h3>';
		$html .= '<p class="nvx-body">' . esc_html( $effect['body'] ?? '' ) . '</p></article>';
		++$eff_idx;
	}
	$html .= '</div></div></section>';
	return $html;
}

/** Render zones section for Endolaser page. */
function nvx_endolaser_zones_section( array $data ): string {
	$html  = nvx_page_brand_section_open_markup( 'nvx-feature-zones', 'nvx-feature-zones-title' );
	$html .= nvx_page_brand_section_heading_markup( esc_html( $data['zones']['kicker'] ?? '' ), 'nvx-feature-zones-title', esc_html( $data['zones']['title'] ?? '' ) );
	$html .= '<p class="nvx-body nvx-body--measure">' . esc_html( $data['zones']['body'] ?? '' ) . '</p>';
	$html .= '<ul class="nvx-feature-zone-list">';
	foreach ( $data['zones']['items'] ?? array() as $zone ) {
		$html .= '<li class="nvx-feature-zone">';
		$html .= '<h3 class="nvx-feature-zone__title">' . esc_html( $zone['title'] ?? '' ) . '</h3>';
		$html .= '<p class="nvx-body">' . esc_html( $zone['body'] ?? '' ) . '</p>';
		$html .= '</li>';
	}
	$html .= '</ul></div></section>';
	return $html;
}

/** Render downtime section for Endolaser page. */
function nvx_endolaser_downtime_section( array $data ): string {
	if ( empty( $data['downtime']['phases'] ) || ! is_array( $data['downtime']['phases'] ) ) {
		return '';
	}
	$html  = nvx_page_brand_section_open_markup( 'nvx-endolaser-downtime', 'nvx-endolaser-down-title' );
	$html .= nvx_page_brand_section_heading_markup( esc_html( $data['downtime']['kicker'] ?? '' ), 'nvx-endolaser-down-title', esc_html( $data['downtime']['title'] ?? '' ) );
	$html .= '<div class="nvx-endolift-timeline">';
	foreach ( $data['downtime']['phases'] as $phase ) {
		$html .= '<div class="nvx-endolift-phase"><span class="nvx-endolift-phase__num">' . esc_html( $phase['n'] ?? '' ) . '</span>';
		$html .= '<h3 class="nvx-endolift-phase__title">' . esc_html( $phase['title'] ?? '' ) . '</h3>';
		$html .= '<p class="nvx-body">' . esc_html( $phase['body'] ?? '' ) . '</p></div>';
	}
	$html .= '</div>';
	if ( ! empty( $data['downtime']['note'] ) ) {
		$html .= '<p class="nvx-body nvx-body--measure"><em>' . esc_html( $data['downtime']['note'] ) . '</em></p>';
	}
	$html .= '</div></section>';
	return $html;
}

function nvx_endolaser_editorial_body_markup(): string {
	require_once __DIR__ . '/nvx-catalog-json.php';
	$data = nvx_catalog_json_resolved( 'endolaser-page.json' );

	$html  = '<div class="nvx-endolaser-editorial nvx-brand-editorial">';
	$html .= nvx_endolaser_mechanism_section( $data );
	$html .= nvx_endolaser_zones_section( $data );

	// C. Exclusión.
	$html .= nvx_page_brand_section_open_markup( 'nvx-endolaser-exclusion', 'nvx-endolaser-excl-title', 'nvx-endolift-diagnosis__grid' );
	$html .= '<div class="nvx-endolift-diagnosis__copy">';
	$html .= nvx_page_brand_section_heading_markup( esc_html( $data['exclusion']['kicker'] ?? '' ), 'nvx-endolaser-excl-title', esc_html( $data['exclusion']['title'] ?? '' ) );
	foreach ( $data['exclusion']['body'] ?? array() as $paragraph ) {
		$html .= '<p class="nvx-body">' . esc_html( $paragraph ) . '</p>';
	}
	$html .= '</div>';
	$html .= '<aside class="nvx-endolift-diagnosis__panel" aria-label="' . esc_attr__( 'Resumen de candidatura', 'nuvanx-medical' ) . '">';
	$html .= '<p class="nvx-endolift-panel-label">' . esc_html( $data['exclusion']['panel_title'] ?? '' ) . '</p>';
	$html .= '<ul class="nvx-endolift-panel-list">';
	foreach ( $data['exclusion']['panel_items'] ?? array() as $item ) {
		$html .= '<li><strong>' . esc_html( $item['title'] ?? '' ) . '</strong> — ' . esc_html( $item['body'] ?? '' ) . '</li>';
	}
	$html .= '</ul></aside></div></section>';

	// D. Planificación / inversión.
	$html .= nvx_page_brand_section_open_markup( 'nvx-endolaser-planning', 'nvx-endolaser-plan-title', '', array( 'id' => 'planificacion-endolaser' ) );
	$html .= nvx_page_brand_section_heading_markup( esc_html( $data['planning']['kicker'] ?? '' ), 'nvx-endolaser-plan-title', esc_html( $data['planning']['title'] ?? '' ) );
	$html .= '<p class="nvx-body nvx-body--measure">' . esc_html( $data['planning']['body'] ?? '' ) . '</p>';
	$html .= '<ul class="nvx-endolift-price-includes">';
	foreach ( $data['planning']['items'] ?? array() as $item ) {
		$html .= '<li>' . esc_html( $item ) . '</li>';
	}
	$html .= '</ul>';
	$html .= '<p class="nvx-body nvx-body--measure"><em>' . esc_html( $data['planning']['note'] ?? '' ) . '</em></p>';
	$html .= '</div></section>';

	$html .= nvx_endolaser_downtime_section( $data );



	// E. FAQ — same Q/A as FAQPage schema (nvx_schema_faq_catalog endolaser_corporal).
	$faqs = array();
	if ( function_exists( 'nvx_schema_faq_catalog' ) ) {
		$catalog = nvx_schema_faq_catalog();
		if ( ! empty( $catalog['endolaser_corporal'] ) ) {
			$faqs = $catalog['endolaser_corporal'];
		}
	}
	if ( empty( $faqs ) && ! empty( $data['faq']['items'] ) && is_array( $data['faq']['items'] ) ) {
		$faqs = $data['faq']['items'];
	}

	if ( ! empty( $faqs ) ) {
		$html .= nvx_page_brand_section_open_markup( 'nvx-endolaser-faq', 'nvx-endolaser-faq-title' );
		$html .= nvx_page_brand_section_heading_markup( esc_html( $data['faq']['kicker'] ?? 'Base de conocimiento' ), 'nvx-endolaser-faq-title', esc_html( $data['faq']['title'] ?? 'Preguntas clínicas frecuentes' ) );
		$html .= '<div class="nvx-faq nvx-endolaser-faq-list">';
		foreach ( $faqs as $faq ) {
			if ( ! empty( $faq['q'] ) && ! empty( $faq['a'] ) ) {
				$html .= '<details class="nvx-brand-faq-item">';
				$html .= '<summary><span>' . esc_html( $faq['q'] ) . '</span></summary>';
				$html .= '<div class="nvx-brand-faq-content"><p>' . esc_html( $faq['a'] ) . '</p></div>';
				$html .= '</details>';
			}
		}
		$html .= '</div></div></section>';
	}

	// Closing valoración CTA: site-wide nvx-cta-banner in footer.php (not page-local).

	$html .= '</div>';

	return $html;
}


/**
 * Rebuild Endoláser page content.
 */
add_filter(
	'nvx_page_owner',
	function ( $owner ) {
		if ( ! empty( $owner ) ) {
			return $owner;
		}
		global $post;
		$content = $post ? $post->post_content : '';
		if ( function_exists( 'nvx_content_is_endolaser_page' ) && nvx_content_is_endolaser_page( $content ) ) {
			return 'nvx_endolaser_page';
		}
		return $owner;
	}
);

function nvx_content_restructure_endolaser_page( string $content ): string {
	$owner = function_exists( 'nvx_get_page_owner' ) ? nvx_get_page_owner() : null;
	if ( $owner !== 'nvx_endolaser_page' ) {
		return $content;
	}

	$media = nvx_page_extract_brand_hero_media( $content );

	$hero  = '<section class="nvx-brand-hero" aria-labelledby="nvx-endolaser-h1">';
	$hero .= '<div class="nvx-brand-hero__inner">';
	$hero .= nvx_endolaser_hero_copy_markup();
	$hero .= $media;
	$hero .= '</div></section>';

	$body = nvx_endolaser_editorial_body_markup();

	// Use standard wrapper like soluciones-medicas for consistent margins
	$standard_wrapper = '<div class="entry-content nvx-page__content nvx-prose">';
	return $standard_wrapper . $hero . $body . '</div>';
}
add_filter( 'the_content', 'nvx_content_restructure_endolaser_page', NVX_HOOK_PRIO_ENDOLASER );

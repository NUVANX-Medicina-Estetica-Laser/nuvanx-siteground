<?php
/**
 * EXION® BTL treatment page — editorial high-authority structure.
 *
 * Wire-frame: Hero → Qué es → Indicaciones → vs tratamientos superficiales → Biofísica → Proceso → Postoperatorio → Tarifas → FAQ → CTA.
 * Pattern-based (EXION® markers), not page-ID gated.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether the current main query is a singular page suitable for rewrite.
 */
function nvx_exion_is_singular_context(): bool {
	if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return false;
	}

	return is_singular( 'page' ) || is_page();
}

/**
 * Detect EXION® BTL treatment content before rewrite.
 * Anchors primarily on stable structural markers (aria-label / ids / brand classes).
 */
function nvx_content_is_exion_page( string $content ): bool {
	if ( false !== strpos( $content, 'nvx-exion-editorial' ) ) {
		return false;
	}

	if ( ! nvx_exion_is_singular_context() || is_front_page() || is_home() ) {
		return false;
	}

	$path = function_exists( 'nvx_schema_current_path' )
		? nvx_schema_current_path( (int) get_queried_object_id() )
		: '';

	$is_exion = false;
	// Only claim the EXION® BTL hub page (/exion-btl/), not the applicator-specific pages
	// which are owned by nvx-btl-detail-pages.php (exion-face, exion-body, exion-fractional).
	if ( is_string( $path ) && '/exion-btl/' === $path ) {
		$is_exion = true;
	} elseif ( preg_match(
		'/aria-label=["\']EXION® BTL NUVANX["\']|id=["\']nvx-exion-h1["\']|class=["\'][^"\']*nvx-exion-hero/iu',
		$content
	) ) {
		$is_exion = true;
	} elseif ( preg_match(
		'/nvx-brand-hero[\s\S]{0,1200}EXION®[\s\S]{0,400}(Face|Body|Fractional)/iu',
		$content
	) ) {
		$is_exion = true;
	}

	return $is_exion;
}

/**
 * Linear process icons — Champagne Bronce stroke only (1.5px).
 *
 * @param string $name Icon key: assess|anesthesia|procedure|recover.
 */
function nvx_exion_process_icon( string $name ): string {
	$icons = array(
		'assess'     => '<svg class="nvx-exion-step__icon" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><circle cx="22" cy="22" r="10" stroke="currentColor" stroke-width="1.5"/><path d="M30 30 40 40" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M18 22h8M22 18v8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
		'anesthesia' => '<svg class="nvx-exion-step__icon" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M18 8h12v8l4 6v18H14V22l4-6V8Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M18 16h12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
		'procedure'  => '<svg class="nvx-exion-step__icon" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M10 34 28 8l10 6-18 26H10v-6Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M24 14l10 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
		'recover'    => '<svg class="nvx-exion-step__icon" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M12 28c4-10 8-14 12-14s8 4 12 14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M16 18c3-2 5-3 8-3s5 1 8 3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><circle cx="24" cy="30" r="3" stroke="currentColor" stroke-width="1.5"/></svg>',
	);

	return $icons[ $name ] ?? $icons['assess'];
}

/**
 * Builds the EXION® hero copy with medical authority details.
 *
 * @return string The rendered hero copy markup.
 */
function nvx_exion_hero_copy_markup(): string {
	require_once __DIR__ . '/nvx-catalog-json.php';
	$data = nvx_catalog_json_resolved( 'exion-page.json' )['hero'] ?? array();

	$colegiado = function_exists( 'nvx_medical_colegiado' ) ? nvx_medical_colegiado( 'director' ) : '';

	$html  = '<div class="nvx-brand-hero__copy">';
	$html .= '<p class="nvx-brand-kicker">' . esc_html( $data['kicker'] ?? '' ) . '</p>';
	$html .= '<h1 class="nvx-brand-hero__title" id="nvx-exion-h1">' . esc_html( $data['h1'] ?? '' ) . '</h1>';

	// E-E-A-T Medical Authority Byline
	$html .= '<div class="nvx-medical-byline">';
	$html .= '<div class="nvx-medical-byline__text">';
	$html .= '<strong>' . esc_html( $data['byline_author'] ?? '' ) . '</strong><br>';
	$html .= '<span class="nvx-medical-byline__title">' . esc_html( $data['byline_title'] ?? '' ) . '</span>';
	$html .= '</div></div>';
	$html .= '<p class="nvx-brand-hero__lead">' . esc_html( $data['lead'] ?? '' ) . '</p>';
	$html .= '<p class="nvx-brand-hero__description">' . esc_html(
		sprintf(
			/* translators: %s: medical license number */
			$data['description'] ?? '',
			$colegiado
		)
	) . '</p>';

	if ( function_exists( 'nvx_cta_pair_markup' ) ) {
		$html .= nvx_cta_pair_markup( 'nvx-brand-actions' );
	} else {
		$html .= '<div class="nvx-brand-actions"><a class="nvx-brand-btn nvx-brand-btn--primary" href="' . esc_url( home_url( '/madrid/valoracion/' ) ) . '">' . esc_html__( 'Valoración gratuita — sin compromiso', 'nuvanx-medical' ) . '</a></div>';
	}

	$html .= '<p class="nvx-brand-meta">' . esc_html( $data['meta'] ?? '' ) . '</p>';
	$html .= '</div>';

	return $html;
}

/**
 * Builds the EXION® editorial body markup.
 *
 * @return string The rendered editorial body HTML.
 */
function nvx_exion_editorial_body_markup(): string {
	require_once __DIR__ . '/nvx-catalog-json.php';
	$data = nvx_catalog_json_resolved( 'exion-page.json' );

	$colegiado    = function_exists( 'nvx_medical_colegiado' ) ? nvx_medical_colegiado( 'director' ) : '';
	$review_label = defined( 'NVX_EXION_REVIEW_LABEL' ) ? NVX_EXION_REVIEW_LABEL : 'julio 2026';
	$equipo_url   = home_url( '/equipo-medico/' );

	$html = '<div class="nvx-exion-editorial">';

	// Clinical review byline — E-E-A-T
	$html .= '<p class="nvx-exion-reviewed">';
	$html .= esc_html(
		sprintf(
			/* translators: 1: medical license number, 2: review month label */
			$data['review']['text'] ?? '',
			$colegiado,
			$review_label
		)
	);
	$html .= ' <a class="nvx-brand-inline-link" href="' . esc_url( $equipo_url ) . '">' . esc_html( $data['review']['link'] ?? '' ) . '</a>';
	$html .= '</p>';

	$html .= nvx_render_generic_brand_treatment_page_body( $data, 'nvx-exion', 'nvx_exion_process_icon' );

	// The canonical comparison-table presentation is currently owned by the
	// shared Endolift selectors in nvx-patterns-editorial.css. Reuse that owner
	// instead of restoring EXION-specific inline CSS removed by #929.
	$html = str_replace(
		array(
			'class="nvx-exion-compare-wrap"',
			'class="nvx-exion-compare-table"',
		),
		array(
			'class="nvx-exion-compare-wrap nvx-endolift-compare-wrap"',
			'class="nvx-exion-compare-table nvx-endolift-compare-table"',
		),
		$html
	);

	$html .= '</div>';

	return $html;
}

/**
 * Rebuild EXION® page: authority hero + diagnosis + technology + process + postop + FAQ + CTA.
 */
add_filter(
	'nvx_page_owner',
	function ( $owner ) {
		if ( ! empty( $owner ) ) {
			return $owner;
		}
		global $post;
		$content = $post ? $post->post_content : '';
		if ( function_exists( 'nvx_content_is_exion_page' ) && nvx_content_is_exion_page( $content ) ) {
			return 'nvx_exion_page';
		}
		return $owner;
	}
);

function nvx_content_restructure_exion_page( string $content ): string {
	$owner = function_exists( 'nvx_get_page_owner' ) ? nvx_get_page_owner() : null;
	if ( $owner !== 'nvx_exion_page' ) {
		return $content;
	}

	$media = function_exists( 'nvx_page_extract_brand_hero_media' ) ? nvx_page_extract_brand_hero_media( $content ) : '';

	$hero  = '<section class="nvx-brand-hero" aria-labelledby="nvx-exion-h1">';
	$hero .= '<div class="nvx-brand-hero__inner">';
	$hero .= nvx_exion_hero_copy_markup();
	$hero .= $media;
	$hero .= '</div></section>';

	$body = nvx_exion_editorial_body_markup();

	$standard_wrapper = '<div class="entry-content nvx-page__content">';
	return $standard_wrapper . $hero . $body . '</div>';
}
add_filter( 'the_content', 'nvx_content_restructure_exion_page', NVX_HOOK_PRIO_ENDOLIFT );

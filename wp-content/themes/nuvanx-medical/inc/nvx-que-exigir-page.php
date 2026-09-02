<?php
/**
 * Qué exigir antes de operarte — SEO Capture & Authority Page.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detect Qué exigir page.
 */
function nvx_content_is_que_exigir_page( string $content ): bool {
	if ( false !== strpos( $content, 'nvx-que-exigir-editorial' ) ) {
		return false;
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
		&& nvx_schema_path_matches( $path, '/que-exigir-antes-de-operarte/' )
	) {
		return true;
	}

	return (bool) preg_match(
		'/aria-label=["\']Qué exigir antes de operarte["\']|id=["\']nvx-que-exigir-h1["\']|class=["\'][^"\']*nvx-que-exigir-hero/iu',
		$content
	);
}

/**
 * Replace content with Qué exigir authority page.
 */
function nvx_content_que_exigir_hijack( string $content ): string {
	if ( ! nvx_content_is_que_exigir_page( $content ) ) {
		return $content;
	}

	require_once __DIR__ . '/nvx-catalog-json.php';
	$data = nvx_catalog_json_resolved( 'que-exigir-page.json' );

	$valuation_url = function_exists( 'nvx_cta_valoracion_url' ) ? nvx_cta_valoracion_url() : home_url( '/madrid/valoracion/' );

	$html = '<div class="nvx-que-exigir-editorial">';

	// Hero
	$html .= '<h1 class="nvx-brand-hero__title" id="nvx-que-exigir-h1">' . esc_html( $data['hero']['h1'] ?? '' ) . '</h1>';

	// E-E-A-T Byline
	$html .= '<div class="nvx-medical-byline nvx-medical-byline--border">';
	$html .= '<div class="nvx-medical-byline__text">';
	$html .= '<strong>' . esc_html( $data['byline']['author'] ?? '' ) . '</strong><br>';
	$html .= '<span class="nvx-medical-byline__title">' . esc_html( $data['byline']['title'] ?? '' ) . '</span>';
	$html .= '</div></div>';

	$html .= '<div class="nvx-que-exigir-body">';

	// Intro
	$html .= '<p><strong>' . esc_html( $data['intro']['bold'] ?? '' ) . '</strong></p>';
	$html .= '<p>' . esc_html( $data['intro']['text'] ?? '' ) . '</p>';

	// Sections
	foreach ( (array) ( $data['sections'] ?? array() ) as $sec ) {
		$html .= '<h2 class="nvx-que-exigir-h2">' . esc_html( $sec['title'] ?? '' ) . '</h2>';
		$html .= '<p>' . esc_html( $sec['body'] ?? '' ) . '</p>';
	}

	$html .= '<hr class="nvx-que-exigir-hr">';

	// CTA Block
	$html .= '<div class="nvx-que-exigir-cta-box">';
	$html .= '<h3 class="nvx-que-exigir-cta-title">' . esc_html( $data['cta']['title'] ?? '' ) . '</h3>';
	$html .= '<p class="nvx-que-exigir-cta-text">' . esc_html( $data['cta']['text'] ?? '' ) . '</p>';
	if ( function_exists( 'nvx_cta_pair_markup' ) ) {
		$html .= nvx_cta_pair_markup( 'nvx-que-exigir-hero-ctas nvx-home-hero-ctas' );
	} else {
		$html .= '<a href="' . esc_url( $valuation_url ) . '" class="nvx-brand-btn">' . esc_html( $data['cta']['fallback_btn'] ?? '' ) . '</a>';
	}
	$html .= '</div>';

	$html .= '</div>';

	return $html;
}
add_filter( 'the_content', 'nvx_content_que_exigir_hijack', NVX_HOOK_PRIO_QUE_EXIGIR );

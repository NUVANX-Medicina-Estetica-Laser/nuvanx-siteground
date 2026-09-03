<?php
/**
 * Profhilo page — editorial high-authority structure.
 *
 * Wire-frame: Hero → Qué es → Indicaciones → vs rellenos → Biofísica → Proceso → Postoperatorio → Tarifas → FAQ → CTA.
 * Pattern-based (Profhilo markers), not page-ID gated.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Singular context for Profhilo rewrite.
 */
function nvx_profhilo_is_singular_context(): bool {
	if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return false;
	}

	return is_singular( 'page' ) || is_page();
}

/**
 * Detect Profhilo detail page only (not home/hub cards).
 */
function nvx_content_is_profhilo_page( string $content ): bool {
	if ( false !== strpos( $content, 'nvx-profhilo-editorial' ) ) {
		return false;
	}

	if ( ! nvx_profhilo_is_singular_context() ) {
		return false;
	}

	if ( is_front_page() || is_home() ) {
		return false;
	}

	$path = function_exists( 'nvx_schema_current_path' )
		? nvx_schema_current_path( (int) get_queried_object_id() )
		: '';

	if ( is_string( $path ) && false !== strpos( $path, 'profhilo' ) ) {
		return true;
	}

	return (bool) preg_match(
		'/aria-label=["\']Profhilo NUVANX["\']|id=["\']nvx-profhilo-h1["\']|class=["\'][^"\']*nvx-profhilo-hero/iu',
		$content
	);
}

/**
 * Linear process icons — Champagne Bronce stroke only (1.5px).
 *
 * @param string $name Icon key: assess|anesthesia|procedure|recover.
 */
function nvx_profhilo_process_icon( string $name ): string {
	$icons = array(
		'assess'     => '<svg class="nvx-profhilo-step__icon" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><circle cx="22" cy="22" r="10" stroke="currentColor" stroke-width="1.5"/><path d="M30 30 40 40" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M18 22h8M22 18v8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
		'anesthesia' => '<svg class="nvx-profhilo-step__icon" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M18 8h12v8l4 6v18H14V22l4-6V8Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M18 16h12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
		'procedure'  => '<svg class="nvx-profhilo-step__icon" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M10 34 28 8l10 6-18 26H10v-6Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M24 14l10 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
		'recover'    => '<svg class="nvx-profhilo-step__icon" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M12 28c4-10 8-14 12-14s8 4 12 14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M16 18c3-2 5-3 8-3s5 1 8 3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><circle cx="24" cy="30" r="3" stroke="currentColor" stroke-width="1.5"/></svg>',
	);

	return $icons[ $name ] ?? $icons['assess'];
}

/**
 * Builds the Profhilo hero copy markup.
 *
 * Medical review provenance is deliberately not emitted here. The single
 * approval-gated owner is nvx-medical-review.php, which injects a byline only
 * when the page has a complete approved reviewer record.
 *
 * @return string The escaped hero copy HTML.
 */
function nvx_profhilo_hero_copy_markup(): string {
	require_once __DIR__ . '/nvx-catalog-json.php';
	$data = nvx_catalog_json_resolved( 'profhilo-page.json' )['hero'] ?? array();

	$colegiado = function_exists( 'nvx_medical_colegiado' ) ? nvx_medical_colegiado( 'director' ) : '';

	$html  = '<div class="nvx-brand-hero__copy">';
	$html .= '<p class="nvx-brand-kicker">' . esc_html( $data['kicker'] ?? '' ) . '</p>';
	$html .= '<h1 class="nvx-brand-hero__title" id="nvx-profhilo-h1">' . esc_html( $data['h1'] ?? '' ) . '</h1>';

	$post_id       = (int) get_queried_object_id();
	$review_status = ( $post_id > 0 && function_exists( 'get_post_meta' ) )
		? (string) get_post_meta( $post_id, '_nvx_medical_review_status', true )
		: '';
	if ( 'approved' === $review_status ) {
		$html .= '<div class="nvx-medical-byline" data-nvx-medical-review="approved">';
		$html .= '<div class="nvx-medical-byline__text">';
		$html .= '<strong>' . esc_html__( 'Dr. José Javier Rivera Tejeda', 'nuvanx-medical' ) . '</strong><br>';
		$html .= '<span class="nvx-medical-byline__title">' . esc_html__( 'Director Médico · Colegiado ICOMEM Nº ', 'nuvanx-medical' ) . esc_html( $colegiado ) . '</span>';
		$html .= '</div></div>';
	}
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
		$html .= '<div class="nvx-brand-actions"><a class="nvx-brand-btn nvx-brand-btn--primary" href="' . esc_url( home_url( '/madrid/valoracion/' ) ) . '">' . esc_html__( 'Reservar valoración médica', 'nuvanx-medical' ) . '</a></div>';
	}

	$html .= '<p class="nvx-brand-meta">' . esc_html( $data['meta'] ?? '' ) . '</p>';
	$html .= '</div>';

	return $html;
}

/**
 * Builds the Profhilo editorial body markup.
 *
 * Review dates/bylines are not rendered from editorial JSON. Provenance is
 * owned exclusively by the approval-gated medical-review module.
 *
 * @return string The generated editorial HTML.
 */
function nvx_profhilo_editorial_body_markup(): string {
	require_once __DIR__ . '/nvx-catalog-json.php';
	$data = nvx_catalog_json_resolved( 'profhilo-page.json' );

	$html  = '<div class="nvx-profhilo-editorial">';
	$html .= nvx_render_generic_brand_treatment_page_body( $data, 'nvx-profhilo', 'nvx_profhilo_process_icon' );
	$html .= '</div>';

	return $html;
}

/**
 * Rebuild Profhilo page.
 */
add_filter(
	'nvx_page_owner',
	function ( $owner ) {
		if ( ! empty( $owner ) ) {
			return $owner;
		}
		global $post;
		$content = $post ? $post->post_content : '';
		if ( function_exists( 'nvx_content_is_profhilo_page' ) && nvx_content_is_profhilo_page( $content ) ) {
			return 'nvx_profhilo_page';
		}
		return $owner;
	}
);

/**
 * Rebuilds owned Profhilo page content with its branded hero and editorial layout.
 *
 * @param string $content The existing page content, including any extracted hero media.
 * @return string The branded Profhilo page content, or the original content when the page is not owned by the Profhilo module.
 */
function nvx_content_restructure_profhilo_page( string $content ): string {
	$owner = function_exists( 'nvx_get_page_owner' ) ? nvx_get_page_owner() : null;
	if ( $owner !== 'nvx_profhilo_page' ) {
		return $content;
	}

	$media = nvx_page_extract_brand_hero_media( $content );

	$hero  = '<section class="nvx-brand-hero" aria-labelledby="nvx-profhilo-h1">';
	$hero .= '<div class="nvx-brand-hero__inner">';
	$hero .= nvx_profhilo_hero_copy_markup();
	$hero .= $media;
	$hero .= '</div></section>';

	$body = nvx_profhilo_editorial_body_markup();

	// Use standard wrapper like soluciones-medicas for consistent margins
	$standard_wrapper = '<div class="entry-content nvx-page__content">';
	return $standard_wrapper . $hero . $body . '</div>';
}
add_filter( 'the_content', 'nvx_content_restructure_profhilo_page', NVX_HOOK_PRIO_PROFIHILO_MODULE );

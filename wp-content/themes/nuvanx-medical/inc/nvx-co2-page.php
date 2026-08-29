<?php
/**
 * Láser CO₂ fraccionado page — editorial high-authority structure.
 *
 * Wire-frame: Hero → Qué es → Indicaciones → vs peelings → Biofísica → Proceso → Postoperatorio → Tarifas → FAQ → CTA.
 * Pattern-based (CO2 markers), not page-ID gated.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/nvx-page-render-helpers.php';

/**
 * Authorized CO₂ facial photo. Always use the original 760×510 file as src.
 */
function nvx_co2_authorized_hero_media_markup(): string {
	$attachment_id = 2086;
	$file          = get_attached_file( $attachment_id );
	$url           = wp_get_attachment_url( $attachment_id );
	if ( ! is_string( $file ) || ! is_readable( $file ) || ! is_string( $url ) || '' === $url ) {
		return '';
	}

	$srcset = array( esc_url( $url ) . ' 760w' );
	$medium = preg_replace( '/nvx-co2-hero-760\.webp$/i', 'nvx-co2-hero-760-300x201.webp', $url );
	if ( is_string( $medium ) && $medium !== $url && function_exists( 'nvx_local_upload_url_exists' ) && nvx_local_upload_url_exists( $medium ) ) {
		$srcset[] = esc_url( $medium ) . ' 300w';
	}

	$img  = '<img class="nvx-media nvx-media--hero" src="' . esc_url( $url ) . '"';
	$img .= ' width="760" height="510"';
	$img .= ' alt="' . esc_attr__( 'NUVANX — Láser CO₂ fraccionado — tratamiento facial', 'nuvanx-medical' ) . '"';
	$img .= ' srcset="' . esc_attr( implode( ', ', $srcset ) ) . '"';
	$img .= ' sizes="(min-width: 48rem) 50vw, 100vw"';
	$img .= ' loading="eager" decoding="async" fetchpriority="high">';

	return '<figure class="nvx-brand-hero__media nvx-brand-hero__media--authorized">' . $img . '</figure>';
}

/**
 * Singular context for CO₂ rewrite.
 */
function nvx_co2_is_singular_context(): bool {
	if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return false;
	}

	return is_singular( 'page' ) || is_page();
}

/**
 * Detect Láser CO₂ fraccionado detail page only (not home/hub cards).
 */
function nvx_content_is_co2_page( string $content ): bool {
	if ( false !== strpos( $content, 'nvx-co2-editorial' ) ) {
		return false;
	}

	if ( ! nvx_co2_is_singular_context() ) {
		return false;
	}

	if ( is_front_page() || is_home() ) {
		return false;
	}

	$path = function_exists( 'nvx_schema_current_path' )
		? nvx_schema_current_path( (int) get_queried_object_id() )
		: '';

	if ( is_string( $path ) && false !== strpos( $path, 'laser-co2-fraccionado' ) ) {
		return true;
	}

	return (bool) preg_match(
		'/aria-label=["\']Láser CO₂ NUVANX["\']|id=["\']nvx-co2-h1["\']|class=["\'][^"\']*nvx-co2-hero/iu',
		$content
	);
}

/**
 * Linear process icons — Champagne Bronce stroke only (1.5px).
 *
 * @param string $name Icon key: assess|anesthesia|procedure|recover.
 */
function nvx_co2_process_icon( string $name ): string {
	$icons = array(
		'assess'     => '<svg class="nvx-co2-step__icon" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><circle cx="22" cy="22" r="10" stroke="currentColor" stroke-width="1.5"/><path d="M30 30 40 40" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M18 22h8M22 18v8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
		'anesthesia' => '<svg class="nvx-co2-step__icon" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M18 8h12v8l4 6v18H14V22l4-6V8Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M18 16h12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
		'procedure'  => '<svg class="nvx-co2-step__icon" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M10 34 28 8l10 6-18 26H10v-6Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M24 14l10 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
		'recover'    => '<svg class="nvx-co2-step__icon" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M12 28c4-10 8-14 12-14s8 4 12 14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M16 18c3-2 5-3 8-3s5 1 8 3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><circle cx="24" cy="30" r="3" stroke="currentColor" stroke-width="1.5"/></svg>',
	);

	return $icons[ $name ] ?? $icons['assess'];
}

/**
 * Builds the CO₂ laser treatment hero copy markup.
 *
 * @return string The escaped hero copy HTML.
 */
function nvx_co2_hero_copy_markup(): string {
	require_once __DIR__ . '/nvx-catalog-json.php';
	$data = nvx_catalog_json_resolved( 'laser-co2-page.json' )['hero'] ?? array();

	$colegiado = function_exists( 'nvx_medical_colegiado' ) ? nvx_medical_colegiado( 'director' ) : '';

	$byline_html  = '<div class="nvx-medical-byline">';
	$byline_html .= '<div class="nvx-medical-byline__text">';
	$byline_html .= '<strong>' . esc_html( $data['byline_author'] ?? '' ) . '</strong><br>';
	$byline_html .= '<span class="nvx-medical-byline__title">' . esc_html( $data['byline_title'] ?? '' ) . '</span>';
	$byline_html .= '</div></div>';

	return nvx_brand_hero_copy_markup(
		array(
			'kicker'             => (string) ( $data['kicker'] ?? '' ),
			'h1_id'              => 'nvx-co2-h1',
			'h1'                 => (string) ( $data['h1'] ?? '' ),
			'byline_html'        => $byline_html,
			'lead'               => (string) ( $data['lead'] ?? '' ),
			'description_html'   => esc_html(
				sprintf(
					/* translators: %s: medical license number */
					$data['description'] ?? '',
					$colegiado
				)
			),
			'cta_fallback_label' => __( 'Valoración gratuita — sin compromiso', 'nuvanx-medical' ),
			'meta'               => (string) ( $data['meta'] ?? '' ),
		)
	);
}

/**
 * Builds the CO₂ laser treatment editorial body markup.
 *
 * @return string The generated editorial HTML.
 */
function nvx_co2_editorial_body_markup(): string {
	require_once __DIR__ . '/nvx-catalog-json.php';
	$data = nvx_catalog_json_resolved( 'laser-co2-page.json' );

	$colegiado    = function_exists( 'nvx_medical_colegiado' ) ? nvx_medical_colegiado( 'director' ) : '';
	$review_label = defined( 'NVX_CO2_REVIEW_LABEL' ) ? NVX_CO2_REVIEW_LABEL : 'julio 2026';
	$equipo_url   = home_url( '/equipo-medico/' );

	$html = '<div class="nvx-co2-editorial">';

	// Clinical review byline — E-E-A-T
	$html .= '<p class="nvx-co2-reviewed">';
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

	$html .= nvx_render_generic_brand_treatment_page_body( $data, 'nvx-co2', 'nvx_co2_process_icon' );
	$html .= '</div>';

	return $html;
}

/**
 * Rebuild CO₂ page.
 */
add_filter(
	'nvx_page_owner',
	function ( $owner ) {
		if ( ! empty( $owner ) ) {
			return $owner; }
		global $post;
		$content = $post ? $post->post_content : '';
		if ( function_exists( 'nvx_content_is_co2_page' ) && nvx_content_is_co2_page( $content ) ) {
			return 'nvx_co2_page';
		}
		return $owner;
	}
);

/**
 * Rebuilds owned CO₂ page content with its branded hero and editorial layout.
 *
 * @param string $content The existing page content, including any extracted hero media.
 * @return string The branded CO₂ page content, or the original content when the page is not owned by the CO₂ module.
 */
function nvx_content_restructure_co2_page( string $content ): string {
	$owner = function_exists( 'nvx_get_page_owner' ) ? nvx_get_page_owner() : null;
	if ( $owner !== 'nvx_co2_page' ) {
		return $content;
	}

	// Always emit the authorized original (760×510). wp_get_attachment_image +
	// the responsive rewriter otherwise promote nvx-co2-hero-760-150x150.webp.
	$media = nvx_co2_authorized_hero_media_markup();
	if ( '' === $media ) {
		$media          = nvx_page_extract_brand_hero_media( $content );
		$reject_collage = ( false !== strpos( $media, 'laser-co2-fraccionado-madrid-textura' )
			|| false !== strpos( $media, 'wp-image-3074' )
			|| false !== strpos( $media, 'Deka.webp' )
			|| false !== strpos( $media, 'laser-medico-nuvanx-madrid' ) );
		if ( $reject_collage ) {
			$media = '';
		}
	}

	if ( '' !== $media && false !== strpos( $media, 'nvx-co2-hero-760' ) && false === strpos( $media, 'nvx-brand-hero__media--authorized' ) ) {
		$media = preg_replace(
			'/\bclass="nvx-brand-hero__media"/',
			'class="nvx-brand-hero__media nvx-brand-hero__media--authorized"',
			$media,
			1
		) ?? $media;
	}

	$hero_class = '' !== $media ? 'nvx-brand-hero nvx-brand-hero--has-media' : 'nvx-brand-hero';
	$hero       = '<section class="' . esc_attr( $hero_class ) . '" aria-labelledby="nvx-co2-h1">';
	$hero      .= '<div class="nvx-brand-hero__inner">';
	$hero      .= nvx_co2_hero_copy_markup();
	$hero      .= $media;
	$hero      .= '</div></section>';

	$body = nvx_co2_editorial_body_markup();

	// Use standard wrapper like soluciones-medicas for consistent margins
	$standard_wrapper = '<div class="entry-content nvx-page__content">';
	return $standard_wrapper . $hero . $body . '</div>';
}
add_filter( 'the_content', 'nvx_content_restructure_co2_page', NVX_HOOK_PRIO_CO2_MODULE );

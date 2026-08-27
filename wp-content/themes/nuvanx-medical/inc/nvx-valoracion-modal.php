<?php
declare(strict_types=1);
/**
 * Site-wide valoración form modal.
 *
 * Opens from opted-in CTAs outside /contacto/, the full valoración landing and
 * post-conversion pages. Contacto remains a form-free NAP and routing page.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Determines whether the valuation modal is enabled for the current request.
 *
 * @return bool True if the modal is enabled for the request, false otherwise.
 */
function nvx_valoracion_modal_enabled(): bool {
	if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_feed() ) {
		return false;
	}

	// Contacto is contractually form-free: direct links route to the full landing.
	if (
		is_page( 'contacto' )
		|| ( function_exists( 'nvx_is_contacto_page_request' ) && nvx_is_contacto_page_request() )
	) {
		return false;
	}

	// Already on the form landing: keep full-page UX.
	if ( function_exists( 'nvx_theme_is_valoracion_form_page' ) && nvx_theme_is_valoracion_form_page() ) {
		return false;
	}
	if ( function_exists( 'nvx_theme_is_valoracion_landing' ) && nvx_theme_is_valoracion_landing() ) {
		return false;
	}
	if ( function_exists( 'nvx_theme_is_thank_you_page' ) && nvx_theme_is_thank_you_page() ) {
		return false;
	}

	return (bool) apply_filters( 'nvx_valoracion_modal_enabled', true );
}

/**
 * HubSpot portal / form / region for the modal.
 *
 * @return array{portal_id:string,form_id:string,region:string,script_url:string}
 */
function nvx_valoracion_modal_hubspot_config(): array {
	$portal = defined( 'NVX_VALORACION_HS_FRAME_PORTAL_ID' ) ? (string) NVX_VALORACION_HS_FRAME_PORTAL_ID : '147416356';
	$form   = defined( 'NVX_VALORACION_HS_FRAME_FORM_ID' ) ? (string) NVX_VALORACION_HS_FRAME_FORM_ID : '5042522a-0bc5-4381-ac3e-5aee8649b69c';
	$region = defined( 'NVX_VALORACION_HS_FRAME_REGION' ) ? (string) NVX_VALORACION_HS_FRAME_REGION : 'eu1';

	return array(
		'portal_id'  => $portal,
		'form_id'    => $form,
		'region'     => $region,
		'script_url' => 'https://js-eu1.hsforms.net/forms/embed/' . $portal . '.js',
	);
}

/**
 * Add the modal/button presentation layer to the same inline critical bundle as
 * the canonical components CSS. Public requests intentionally suppress local
 * theme stylesheet links, so a conventional wp_enqueue_style() would be dropped.
 */
function nvx_valoracion_modal_enqueue_presentation_styles(): void {
	if ( ! nvx_valoracion_modal_enabled() ) {
		return;
	}

	$path = get_template_directory() . '/assets/css/nvx-conversion-surfaces.css';
	if ( ! is_readable( $path ) ) {
		return;
	}

	$css = file_get_contents( $path );
	if ( ! is_string( $css ) || '' === trim( $css ) ) {
		return;
	}

	if ( ! wp_style_is( 'nvx-components', 'registered' ) ) {
		wp_register_style( 'nvx-components', false, array(), NVX_THEME_VERSION );
	}
	wp_enqueue_style( 'nvx-components' );
	wp_add_inline_style( 'nvx-components', $css );
}
add_action( 'wp_enqueue_scripts', 'nvx_valoracion_modal_enqueue_presentation_styles', 35 );

/**
 * Modal dialog markup.
 */
function nvx_valoracion_modal_markup(): string {
	$cfg = nvx_valoracion_modal_hubspot_config();

	$privacy = esc_url( home_url( '/politica-privacidad/' ) );
	$page    = function_exists( 'nvx_cta_valoracion_url' )
		? nvx_cta_valoracion_url()
		: home_url( '/madrid/valoracion/' );

	$html  = '<dialog id="nvx-valoracion-modal" class="nvx-valoracion-modal" aria-labelledby="nvx-valoracion-modal-title" aria-modal="true">';
	$html .= '<div class="nvx-valoracion-modal__backdrop" data-nvx-valoracion-modal-close tabindex="-1"></div>';
	$html .= '<div class="nvx-valoracion-modal__panel" role="document">';
	$html .= '<button type="button" class="nvx-valoracion-modal__close" data-nvx-valoracion-modal-close aria-label="' . esc_attr__( 'Cerrar formulario', 'nuvanx-medical' ) . '">&times;</button>';
	$html .= '<p class="nvx-eyebrow nvx-valoracion-modal__kicker">' . esc_html__( 'Valoración médica', 'nuvanx-medical' ) . '</p>';
	$html .= '<h2 id="nvx-valoracion-modal-title" class="nvx-valoracion-modal__title">' . esc_html__( 'Solicita una valoración médica', 'nuvanx-medical' ) . '</h2>';
	$html .= '<p class="nvx-valoracion-modal__lead">' . esc_html__( 'Normalmente, un miembro del equipo te contactará durante el siguiente día laborable para confirmar la fecha de valoración.', 'nuvanx-medical' ) . '</p>';
	$html .= '<div id="nvx-valoracion-modal-form" class="nvx-valoracion-modal__form nvx-hubspot-form-section" data-nvx-valoracion-modal-form>';
	// Presentation host only: runtime governance inserts the canonical .hs-form-frame with identity.
	// Repeating data-form-id/data-portal-id here would cause duplicate HubSpot embed initialization.
	$html .= '<div class="hs-form-frame"></div>';
	if ( function_exists( 'nvx_valoracion_direct_form_markup' ) ) {
		$html .= nvx_valoracion_direct_form_markup();
	}
	$html .= '</div>';
	$html .= '<p class="nvx-valoracion-modal__legal">' . sprintf(
		/* translators: %s: Enlace a la política de privacidad */
		esc_html__( 'Al enviar aceptas la %s.', 'nuvanx-medical' ),
		'<a class="nvx-text-link" href="' . esc_url( $privacy ) . '">' . esc_html__( 'Política de privacidad', 'nuvanx-medical' ) . '</a>'
	) . '</p>';
	$html .= '<p class="nvx-valoracion-modal__fallback"><a class="nvx-text-link" href="' . esc_url( $page ) . '">' . esc_html__( 'Abrir página de valoración completa', 'nuvanx-medical' ) . '</a></p>';
	$html .= '</div></dialog>';

	return $html;
}

/**
 * Boot config for nvx-main.js (must run before the main handle).
 *
 * Historically only window.nvxRuntimeGovernance was emitted (after nvx-main), so
 * initValoracionModal always saw cfg as undefined and never bound open handlers.
 */
function nvx_valoracion_modal_enqueue_boot_config(): void {
	if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_feed() ) {
		return;
	}

	if ( ! wp_script_is( 'nvx-main', 'enqueued' ) && ! wp_script_is( 'nvx-main', 'registered' ) ) {
		return;
	}

	$page_url = function_exists( 'nvx_cta_valoracion_url' )
		? nvx_cta_valoracion_url()
		: home_url( '/madrid/valoracion/' );

	$cfg = nvx_valoracion_modal_hubspot_config();

	$config = array(
		'enabled' => nvx_valoracion_modal_enabled(),
		'pageUrl' => $page_url,
		'modalId' => 'nvx-valoracion-modal',
		'hubspotPortalId' => $cfg['portal_id'],
		'hubspotFormId' => $cfg['form_id'],
		'hubspotRegion' => $cfg['region'],
	);

	$encoded = wp_json_encode( $config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	if ( ! is_string( $encoded ) ) {
		$encoded = '{"enabled":false}';
	}

	wp_add_inline_script(
		'nvx-main',
		'window.nvxValoracionModal=' . $encoded . ';',
		'before'
	);
}
add_action( 'wp_enqueue_scripts', 'nvx_valoracion_modal_enqueue_boot_config', 30 );

/**
 * Print modal shell in footer on eligible public pages.
 */
function nvx_valoracion_modal_render(): void {
	if ( ! nvx_valoracion_modal_enabled() ) {
		return;
	}

	// Modal HTML is built with esc_attr / esc_html / esc_url / wp_kses only.
	echo nvx_valoracion_modal_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in nvx_valoracion_modal_markup().
}
// Before wp_print_footer_scripts (20) so nvx-main can bind to #nvx-valoracion-modal.
add_action( 'wp_footer', 'nvx_valoracion_modal_render', 5 );

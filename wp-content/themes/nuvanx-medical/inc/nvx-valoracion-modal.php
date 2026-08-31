<?php
declare(strict_types=1);
/**
 * Site-wide valoración form modal.
 *
 * Opens from opted-in CTAs outside /contacto/, the full valoración landing and
 * post-conversion pages. Contacto remains a form-free NAP and routing page.
 * The visible form is first-party; HubSpot form transport remains server-side.
 * Only the public portal ID is exposed to the browser for consented analytics.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Determines whether the valuation modal is enabled for the current request. */
function nvx_valoracion_modal_enabled(): bool {
	if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_feed() ) {
		return false;
	}

	if (
		is_page( 'contacto' )
		|| ( function_exists( 'nvx_is_contacto_page_request' ) && nvx_is_contacto_page_request() )
	) {
		return false;
	}

	if ( function_exists( 'nvx_theme_is_valoracion_form_page' ) && nvx_theme_is_valoracion_form_page() ) {
		return false;
	}
	if ( function_exists( 'nvx_theme_is_valoracion_landing' ) && nvx_theme_is_valoracion_landing() ) {
		return false;
	}
	if ( function_exists( 'nvx_theme_is_thank_you_page' ) && nvx_theme_is_thank_you_page() ) {
		return false;
	}

	if (
		! function_exists( 'nvx_hubspot_secure_identity_configured' )
		|| ! nvx_hubspot_secure_identity_configured()
		|| ! function_exists( 'nvx_valoracion_direct_form_markup' )
	) {
		return false;
	}

	return (bool) apply_filters( 'nvx_valoracion_modal_enabled', true );
}

/** Modal dialog markup. */
function nvx_valoracion_modal_markup(): string {
	if ( ! nvx_valoracion_modal_enabled() ) {
		return '';
	}

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
	$html .= '<div id="nvx-valoracion-modal-form" class="nvx-valoracion-modal__form" data-nvx-valoracion-modal-form data-nvx-first-party-owner="1">';
	$html .= nvx_valoracion_direct_form_markup();
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

/** Boot config for nvx-main.js and consented global HubSpot analytics. */
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
	$portal_id = function_exists( 'nvx_hubspot_secure_portal_id' )
		? nvx_hubspot_secure_portal_id()
		: '';

	$config = array(
		'enabled'         => nvx_valoracion_modal_enabled(),
		'pageUrl'         => $page_url,
		'modalId'         => 'nvx-valoracion-modal',
		'hubspotPortalId' => $portal_id,
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

/** Print modal shell in footer on eligible public pages. */
function nvx_valoracion_modal_render(): void {
	if ( ! nvx_valoracion_modal_enabled() ) {
		return;
	}

	echo nvx_valoracion_modal_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in nvx_valoracion_modal_markup().
}
add_action( 'wp_footer', 'nvx_valoracion_modal_render', 5 );

<?php
/**
 * CTA components: valoración URL, WhatsApp markup, CTA pair, and closing CTA block.
 *
 * Extracted from nvx-content-presentation.php.
 *
 * @package NUVANX
 */

defined( 'ABSPATH' ) || exit;

// Backwards compatibility: define constants if not already set (fallback to config)
if ( ! defined( 'NVX_DIRECTOR_COLEGIADO' ) ) {
	define( 'NVX_DIRECTOR_COLEGIADO', nvx_medical_colegiado( 'director' ) ?: '282864786' );
}
if ( ! defined( 'NVX_IVON_COLEGIADO' ) ) {
	define( 'NVX_IVON_COLEGIADO', nvx_medical_colegiado( 'ivon' ) ?: '284621525' );
}
if ( ! defined( 'NVX_FABIO_COLEGIADO' ) ) {
	define( 'NVX_FABIO_COLEGIADO', nvx_medical_colegiado( 'fabio' ) ?: '282877543' );
}

/**
 * @return string
 */
function nvx_cta_valoracion_url(): string {
	return home_url( '/madrid/valoracion/' );
}

/**
 * @return string
 */
function nvx_cta_whatsapp_url(): string {
	return nvx_whatsapp_url( 'primary' );
}


/**
 * Secondary WhatsApp CTA.
 */
function nvx_cta_whatsapp_markup( string $class = 'nvx-brand-btn nvx-brand-btn--secondary' ): string {
	return sprintf(
		'<a class="%1$s" href="%2$s" target="_blank" rel="noopener noreferrer">%3$s</a>',
		esc_attr( $class ),
		esc_url( nvx_cta_whatsapp_url() ),
		esc_html__( 'Contactar por WhatsApp', 'nuvanx-medical' )
	);
}

/**
 * Dual CTA cluster.
 */
function nvx_cta_pair_markup( string $extra_class = '' ): string {
	$class      = trim( 'nvx-cta-cluster ' . $extra_class );
	$valoracion = nvx_cta_valoracion_url();

	// Already on the valoración form page: primary CTA targets the form anchor.
	if ( function_exists( 'nvx_theme_is_valoracion_form_page' ) && nvx_theme_is_valoracion_form_page() ) {
		$valoracion = trailingslashit( get_permalink() ) . '#nvx-hubspot-form';
	}

	return '<div class="' . esc_attr( $class ) . '">
		<a href="' . esc_url( $valoracion ) . '" class="nvx-brand-btn nvx-brand-btn--primary nvx-open-valoracion-modal" data-nvx-valoracion-modal="1" aria-haspopup="dialog" data-gtag="click-reserve">
			<span>Solicitar valoración médica</span>
		</a>
		<a href="' . esc_url( nvx_cta_whatsapp_url() ) . '" class="nvx-brand-btn nvx-brand-btn--secondary" target="_blank" rel="noopener noreferrer" data-gtag="click-whatsapp">
			<svg class="icon-whatsapp" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51a12.8 12.8 0 0 0-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413z"/></svg>
			Contactar por WhatsApp
		</a>
	</div>';
}

/**
 * Canonical site-wide closing conversion band (pre-footer).
 * One markup, one copy, used by footer.php on every non-conversion page.
 */
function nvx_site_closing_cta_markup(): string {
	$valoracion = nvx_cta_valoracion_url();
	$whatsapp   = nvx_cta_whatsapp_url();

	// Already on the valoración form page: primary CTA targets the form anchor.
	if ( function_exists( 'nvx_theme_is_valoracion_form_page' ) && nvx_theme_is_valoracion_form_page() ) {
		$valoracion = trailingslashit( get_permalink() ) . '#nvx-hubspot-form';
	}

	$html  = '<section class="nvx-cta-banner" id="nvx-site-closing-cta" aria-label="' . esc_attr__( 'Solicitar valoración médica', 'nuvanx-medical' ) . '">';
	$html .= '<div class="nvx-cta-banner__inner">';
	$html .= '<div>';
	$html .= '<p class="nvx-cta-banner__kicker">Tratamos personas, no imágenes aisladas</p>';
	$html .= '<h2 class="nvx-cta-banner__title">Cada protocolo comienza con una valoración médica individual. Si no está indicado para ti, te lo diremos con claridad.</h2>';
	$html .= '<p class="nvx-cta-banner__sub">Presupuesto y plan documentado por escrito tras la valoración médica &bull; Tiempos de recuperación informados según el protocolo</p>';
	$html .= '</div>';

	$html .= '<div class="nvx-cta-pair nvx-cta-banner__actions">';
	$html .= sprintf(
		'<a class="nvx-brand-btn nvx-btn--light nvx-open-valoracion-modal" id="nvx-footer-cta" href="%1$s" data-nvx-valoracion-modal="1" aria-haspopup="dialog">%2$s</a>',
		esc_url( $valoracion ),
		esc_html__( 'Iniciar mi valoración médica', 'nuvanx-medical' )
	);
	$html .= sprintf(
		'<a class="nvx-brand-btn nvx-btn--secondary-on-dark" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
		esc_url( $whatsapp ),
		esc_html__( 'Contactar por WhatsApp', 'nuvanx-medical' )
	);
	$html .= '</div></div></section>';

	return $html;
}


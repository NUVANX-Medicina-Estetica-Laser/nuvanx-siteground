<?php
/**
 * Canonical theme-owned valuation landing.
 *
 * The CMS stores a stable route marker only. This module renders the complete
 * hierarchy and the single first-party conversion form, so staging and
 * production do not depend on historical database HTML or browser form embeds.
 *
 * SEO title/description are owned exclusively by nvx-seo-metadata.php.
 * HubSpot transport is server-side through nvx-hubspot-secure-attribution.php.
 *
 * @package nuvanx-medical
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonical clinical explanation for the managed valuation landing.
 *
 * A virtual orientation can start the process, while physical examination is
 * still required whenever it is clinically necessary before treatment.
 */
function nvx_valoracion_managed_intro_markup(): string {
	$html  = '<section class="nvx-brand-section nvx-valoracion-intro" id="nvx-valoracion-intro" aria-labelledby="nvx-valoracion-intro-title">';
	$html .= '<div class="nvx-container">';
	$html .= '<p class="nvx-brand-kicker">' . esc_html__( 'Primer paso', 'nuvanx-medical' ) . '</p>';
	$html .= '<h2 id="nvx-valoracion-intro-title" class="nvx-heading">' . esc_html__( 'Una consulta médica para orientar tu caso', 'nuvanx-medical' ) . '</h2>';
	$html .= '<p class="nvx-body nvx-body--measure">' . esc_html__( 'Puedes iniciar el proceso con una orientación virtual o reservar directamente una valoración presencial en Chamberí o Salamanca–Goya. La cita suele durar entre 15 y 30 minutos. Durante ese tiempo revisamos el motivo de consulta, las opciones de tratamiento, los tiempos de recuperación y el presupuesto individualizado. Cuando la exploración física sea necesaria para confirmar la indicación, se completa de forma presencial antes de tratar.', 'nuvanx-medical' ) . '</p>';
	$html .= '<p class="nvx-body nvx-body--measure">' . esc_html__( 'Al finalizar tendrás una orientación clara sobre los siguientes pasos. El equipo, bajo la dirección del Dr. Rivera Tejeda, sigue tres criterios:', 'nuvanx-medical' ) . '</p>';
	$html .= '<ol class="nvx-treatment-process__steps nvx-valoracion-steps">';

	$steps = function_exists( 'nvx_valoracion_process_steps' ) ? nvx_valoracion_process_steps() : array();
	foreach ( $steps as $step ) {
		$html .= '<li class="nvx-treatment-process__step">';
		$html .= '<h3 class="nvx-treatment-process__step-title">' . esc_html( $step['title'] ?? '' ) . '</h3>';
		$html .= '<p class="nvx-body">' . esc_html( $step['body'] ?? '' ) . '</p>';
		$html .= '</li>';
	}
	$html .= '</ol>';

	if ( function_exists( 'nvx_contact_privacy_disclaimer_markup' ) ) {
		$html .= nvx_contact_privacy_disclaimer_markup();
	}
	$html .= '</div></section>';

	$html .= '<section class="nvx-brand-section nvx-valoracion-locations" aria-labelledby="nvx-valoracion-loc-title">';
	$html .= '<div class="nvx-container">';
	$html .= '<p class="nvx-brand-kicker">' . esc_html__( 'Sedes', 'nuvanx-medical' ) . '</p>';
	$html .= '<h2 id="nvx-valoracion-loc-title" class="nvx-heading">' . esc_html__( 'Ubicaciones autorizadas por Sanidad', 'nuvanx-medical' ) . '</h2>';
	if ( function_exists( 'nvx_contact_clinics_markup' ) ) {
		$html .= nvx_contact_clinics_markup();
	}
	$html .= '</div></section>';

	$wa_url = function_exists( 'nvx_cta_whatsapp_url' ) ? nvx_cta_whatsapp_url() : 'https://wa.me/34689317399';
	$html  .= '<section class="nvx-home-closure" aria-labelledby="nvx-valoracion-closure-title">';
	$html  .= '<div class="nvx-container">';
	$html  .= '<h2 id="nvx-valoracion-closure-title" class="nvx-home-closure__title">' . esc_html__( '¿Dudas sobre tu caso o la indicación?', 'nuvanx-medical' ) . '</h2>';
	$html  .= '<p class="nvx-home-closure__desc">' . esc_html__( 'Nuestro equipo médico revisará tus consultas previas sin compromiso comercial.', 'nuvanx-medical' ) . '</p>';
	$html  .= '<div class="nvx-home-closure__actions">';
	$html  .= '<a href="#nvx-hubspot-form" class="nvx-brand-btn nvx-btn--primary">' . esc_html__( 'Solicitar valoración médica', 'nuvanx-medical' ) . '</a>';
	$html  .= '<a href="' . esc_url( $wa_url ) . '" class="nvx-brand-btn nvx-btn--secondary-on-dark" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Contactar por WhatsApp', 'nuvanx-medical' ) . '</a>';
	$html  .= '</div></div></section>';

	return $html;
}

/**
 * Render the single canonical first-party conversion owner.
 *
 * Fail closed when the authenticated HubSpot transport is unavailable. The
 * browser never owns HubSpot account/form identity on this route.
 */
function nvx_valoracion_managed_form_markup(): string {
	if (
		! function_exists( 'nvx_hubspot_secure_identity_configured' )
		|| ! nvx_hubspot_secure_identity_configured()
		|| ! function_exists( 'nvx_valoracion_direct_form_markup' )
	) {
		return '<!-- NVX_VALORACION_FORM_UNAVAILABLE secure_identity_not_configured -->';
	}

	return '<div id="nvx-valoracion-first-party-form" class="nvx-valoracion-first-party-form" data-nvx-first-party-owner="1">'
		. nvx_valoracion_direct_form_markup()
		. '</div>';
}

/**
 * Build the canonical valuation page before form-order filters.
 */
function nvx_valoracion_managed_page_markup(): string {
	// Hero image-free by design: block the featured-image injection performed by
	// nvx_ensure_hero_featured_media (the_content prio 12). The managed renderer
	// runs earlier (prio 10), so setting the flag here short-circuits that filter.
	global $nvx_page_shell_has_hero;
	$nvx_page_shell_has_hero = true;

	$doctoralia_url = 'https://www.doctoralia.es/clinicas/nuvanx-medicina-estetica-laser';
	$wa_url         = function_exists( 'nvx_cta_whatsapp_url' ) ? nvx_cta_whatsapp_url() : 'https://wa.me/34689317399';

	$html = '<div class="nvx-brand-page nvx-valoracion-page" id="nvx-valoracion-main" aria-labelledby="nvx-valoracion-h1">';

	// Conversion-first page header: site header/menu -> concise page heading -> form.
	$html .= '<section class="nvx-brand-hero nvx-brand-hero--surface-ink nvx-valoracion-hero" aria-labelledby="nvx-valoracion-h1">';
	$html .= '<div class="nvx-brand-hero__inner">';
	$html .= '<div class="nvx-brand-hero__copy">';
	$html .= '<p class="nvx-brand-kicker">' . esc_html__( 'VALORACIÓN MÉDICA · MADRID', 'nuvanx-medical' ) . '</p>';
	$html .= '<h1 id="nvx-valoracion-h1" class="nvx-brand-hero__title">' . esc_html__( 'Valoración médica estética en Madrid', 'nuvanx-medical' ) . '</h1>';
	$html .= function_exists( 'nvx_clinical_authority_byline_markup' )
		? nvx_clinical_authority_byline_markup()
		: '';
	$html .= '<p class="nvx-brand-hero__lead" id="nvx-valoracion-lead">' . esc_html__( 'Valoración médica en Madrid de 15 a 30 minutos: diagnóstico, indicación y presupuesto documentado antes de tratar. Sin Anestesia General. Recuperación en 48h como reincorporación habitual al trabajo o a la vida social, según el protocolo indicado; el edema y el ejercicio de impacto pueden requerir más días. Sin obligación de procedimiento.', 'nuvanx-medical' ) . '</p>';
	$html .= '<ul class="nvx-valoracion-hero__proof" aria-label="' . esc_attr__( 'Condiciones clínicas del protocolo', 'nuvanx-medical' ) . '">';
	$html .= '<li>' . esc_html__( 'Sin Anestesia General', 'nuvanx-medical' ) . '</li>';
	$html .= '<li>' . esc_html__( 'Recuperación en 48h (reincorporación habitual, según protocolo)', 'nuvanx-medical' ) . '</li>';
	$html .= '</ul>';
	$html .= '</div></div></section>';

	// The form is deliberately the first content block after the heading.
	$html .= '<section class="nvx-brand-section nvx-hubspot-form-section nvx-form-stage" id="nvx-hubspot-form" aria-labelledby="nvx-valoracion-form-title">';
	$html .= '<div class="nvx-brand-section__inner">';
	$html .= '<p class="nvx-brand-kicker">' . esc_html__( 'SOLICITUD DE VALORACIÓN', 'nuvanx-medical' ) . '</p>';
	$html .= '<h2 id="nvx-valoracion-form-title" class="nvx-brand-title">' . esc_html__( 'Cuéntanos qué quieres valorar', 'nuvanx-medical' ) . '</h2>';
	$html .= '<p class="nvx-brand-body">' . esc_html__( 'Completa tus datos e indica la zona o tratamiento de interés. El equipo de NUVANX te contactará para coordinar una orientación virtual o una valoración presencial. La cita suele durar entre 15 y 30 minutos y permite revisar tu caso, las opciones de tratamiento y el presupuesto individualizado.', 'nuvanx-medical' ) . '</p>';
	$html .= '<div class="nvx-valoracion-direct-contact">';
	$html .= '<p class="nvx-valoracion-direct-contact__label">' . esc_html__( '¿Prefieres coordinar directamente o tienes alguna duda?', 'nuvanx-medical' ) . '</p>';
	$html .= '<div class="nvx-valoracion-direct-contact__actions">';
	$html .= '<a href="' . esc_url( $wa_url ) . '" class="nvx-brand-btn nvx-btn--secondary" target="_blank" rel="noopener noreferrer">' . esc_html__( 'WhatsApp directo', 'nuvanx-medical' ) . '</a>';
	$html .= '<a href="tel:+34689317399" class="nvx-brand-btn nvx-btn--secondary">' . esc_html__( 'Llamar: 689 31 73 99', 'nuvanx-medical' ) . '</a>';
	$html .= '</div></div>';
	$html .= '<div class="nvx-form nvx-hs-native-section" aria-label="' . esc_attr__( 'Formulario de valoración médica NUVANX', 'nuvanx-medical' ) . '">';
	$html .= '<div class="nvx-hs-native-box">';
	$html .= nvx_valoracion_managed_form_markup();
	$html .= '<p class="nvx-copy nvx-form-note">' . esc_html__( 'La información enviada se utiliza para gestionar tu solicitud. La indicación final depende de valoración médica y los resultados pueden variar según cada paciente.', 'nuvanx-medical' ) . '</p>';
	$html .= '<p class="nvx-copy nvx-form-note nvx-doctoralia-proof">' . esc_html__( 'Más de 100 opiniones verificadas en Doctoralia.', 'nuvanx-medical' ) . ' <a class="nvx-brand-inline-link" href="' . esc_url( $doctoralia_url ) . '" target="_blank" rel="noopener noreferrer external">' . esc_html__( 'Consultar opiniones verificadas', 'nuvanx-medical' ) . '</a></p>';
	$html .= '</div></div></div></section>';

	// Keep clinical explanation and locations after the conversion block.
	$html .= nvx_valoracion_managed_intro_markup();
	$html .= '</div>';

	return $html;
}

/**
 * Replace any prior body or CMS marker with the canonical managed landing.
 *
 * @param string $content Original page content.
 */
function nvx_render_managed_valoracion_page( $content ): string {
	$content = is_string( $content ) ? $content : '';
	if ( is_admin() || ! function_exists( 'nvx_is_valoracion_page_request' ) || ! nvx_is_valoracion_page_request() ) {
		return $content;
	}

	return nvx_valoracion_managed_page_markup();
}
add_filter( 'the_content', 'nvx_render_managed_valoracion_page', NVX_HOOK_PRIO_VALORACION_MANAGED );

/**
 * Register valoración page as page owner to prevent shell hero duplication.
 *
 * When the shell evaluates $has_managed_editorial in nvx-page-shell.php,
 * this filter ensures valoración pages are recognized as managed,
 * preventing the shell from rendering its own hero in addition to
 * the renderer's hero.
 */
add_filter(
	'nvx_page_owner',
	function ( $owner ) {
		if ( ! empty( $owner ) || is_admin() ) {
			return $owner;
		}
		if ( function_exists( 'nvx_is_valoracion_page_request' ) && nvx_is_valoracion_page_request() ) {
			return 'nvx_valoracion_managed';
		}
		return $owner;
	},
	10
);

/**
 * Page-level MedicalWebPage + ReserveAction for /madrid/valoracion/.
 *
 * Organization already exposes ReserveAction. This pass types the landing
 * itself so the H1 and initial answer are speakable. Canonical medical review
 * provenance is applied later by nvx-medical-review.php.
 *
 * @param mixed $graph Yoast schema graph.
 * @return mixed
 */
function nvx_valoracion_schema_graph( $graph ) {
	if ( ! is_array( $graph ) || is_admin() || is_feed() ) {
		return $graph;
	}
	if ( ! function_exists( 'nvx_is_valoracion_page_request' ) || ! nvx_is_valoracion_page_request() ) {
		return $graph;
	}

	$url         = home_url( '/madrid/valoracion/' );
	$description = __( 'Valoración médica en Madrid de 15 a 30 minutos: diagnóstico, indicación y presupuesto documentado antes de tratar. Sin Anestesia General. Recuperación en 48h como reincorporación habitual, según el protocolo indicado. Sin obligación de procedimiento.', 'nuvanx-medical' );
	$action      = array(
		'@type'  => 'ReserveAction',
		'name'   => __( 'Solicitar valoración médica', 'nuvanx-medical' ),
		'target' => array(
			'@type'          => 'EntryPoint',
			'urlTemplate'    => $url . '#nvx-hubspot-form',
			'inLanguage'     => 'es',
			'actionPlatform' => array(
				'https://schema.org/DesktopWebPlatform',
				'https://schema.org/MobileWebPlatform',
			),
		),
		'result' => array(
			'@type' => 'Reservation',
			'name'  => __( 'Cita de valoración médica', 'nuvanx-medical' ),
		),
	);

	foreach ( $graph as $index => $node ) {
		if ( ! is_array( $node ) || ! isset( $node['@type'] ) ) {
			continue;
		}
		$types = is_array( $node['@type'] ) ? $node['@type'] : array( $node['@type'] );
		if ( ! in_array( 'WebPage', $types, true ) && ! in_array( 'MedicalWebPage', $types, true ) ) {
			continue;
		}

		if ( function_exists( 'nvx_schema_add_type' ) ) {
			$graph[ $index ]['@type'] = nvx_schema_add_type( $node['@type'], 'MedicalWebPage' );
		} else {
			$types[]                  = 'MedicalWebPage';
			$graph[ $index ]['@type'] = array_values( array_unique( $types ) );
		}

		$graph[ $index ]['name']            = __( 'Valoración médica estética en Madrid', 'nuvanx-medical' );
		$graph[ $index ]['description']     = $description;
		$graph[ $index ]['url']             = $url;
		$graph[ $index ]['inLanguage']      = 'es-ES';
		$graph[ $index ]['speakable']       = array(
			'@type'       => 'SpeakableSpecification',
			'cssSelector' => array( '#nvx-valoracion-h1', '#nvx-valoracion-lead' ),
		);
		$graph[ $index ]['potentialAction'] = $action;
		$graph[ $index ]['mainEntity']      = $action;
		break;
	}

	return $graph;
}
add_filter( 'wpseo_schema_graph', 'nvx_valoracion_schema_graph', 53 );

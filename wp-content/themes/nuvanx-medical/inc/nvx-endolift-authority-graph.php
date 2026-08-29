<?php
/**
 * Endolift® contextual authority graph.
 *
 * Connects the technology owner to the diagnosis/decision hub, pricing owner
 * and canonical local-clinic entities without creating a second conversion CTA.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether the current request is the canonical Endolift® facial technology page.
 */
function nvx_endolift_authority_graph_is_target(): bool {
	if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ! is_page() ) {
		return false;
	}

	$path = function_exists( 'nvx_schema_current_path' )
		? nvx_schema_current_path( (int) get_queried_object_id() )
		: '/' . trim( (string) get_post_field( 'post_name', get_queried_object_id() ), '/' ) . '/';

	return '/endolift-facial-papada-mandibula/' === $path;
}

/**
 * Render the missing contextual relationships for the Endolift® owner page.
 */
function nvx_endolift_authority_graph_markup(): string {
	$problem_url = home_url( '/papada-definicion-mandibular-madrid/' );
	$pricing_url = home_url( '/inversion-medicina-estetica/' );
	$clinics     = function_exists( 'nvx_get_clinics_config' ) ? nvx_get_clinics_config() : array();

	$chamberi_path = isset( $clinics['chamberi']['landing_path'] ) ? (string) $clinics['chamberi']['landing_path'] : '';
	$goya_path     = isset( $clinics['goya']['landing_path'] ) ? (string) $clinics['goya']['landing_path'] : '';
	$clinics_hub   = home_url( '/clinicas-de-medicina-estetica-nuvanx/' );
	$chamberi_url  = '' !== $chamberi_path ? home_url( $chamberi_path ) : $clinics_hub;
	$goya_url      = '' !== $goya_path ? home_url( $goya_path ) : $clinics_hub;

	$html  = '<section class="nvx-brand-section nvx-endolift-authority-graph" aria-labelledby="nvx-endolift-context-title" data-nvx-endolift-authority-graph="1">';
	$html .= '<div class="nvx-brand-section__inner">';
	$html .= '<p class="nvx-brand-kicker">' . esc_html__( 'Decisión clínica', 'nuvanx-medical' ) . '</p>';
	$html .= '<h2 id="nvx-endolift-context-title" class="nvx-brand-title">' . esc_html__( 'Endolift® dentro de un diagnóstico, no como técnica aislada', 'nuvanx-medical' ) . '</h2>';
	$html .= '<p class="nvx-body nvx-body--measure">';
	$html .= esc_html__( 'Si la consulta parte de papada, cuello o pérdida de definición mandibular, primero conviene separar grasa localizada, laxitud y soporte estructural. Consulta cómo se plantea el ', 'nuvanx-medical' );
	$html .= '<a class="nvx-brand-inline-link" href="' . esc_url( $problem_url ) . '">' . esc_html__( 'diagnóstico de papada y definición mandibular', 'nuvanx-medical' ) . '</a>';
	$html .= esc_html__( ' antes de decidir si Endolift® es una opción proporcionada.', 'nuvanx-medical' );
	$html .= '</p>';
	$html .= '<p class="nvx-body nvx-body--measure">';
	$html .= esc_html__( 'Para situar tarifas y qué determina el presupuesto, revisa el ', 'nuvanx-medical' );
	$html .= '<a class="nvx-brand-inline-link" href="' . esc_url( $pricing_url ) . '">' . esc_html__( 'marco de inversión en medicina estética', 'nuvanx-medical' ) . '</a>';
	$html .= esc_html__( '. La cifra final se confirma después de la exploración médica.', 'nuvanx-medical' );
	$html .= '</p>';
	$html .= '<p class="nvx-body nvx-body--measure">';
	$html .= esc_html__( 'La valoración presencial puede realizarse en ', 'nuvanx-medical' );
	$html .= '<a class="nvx-brand-inline-link" href="' . esc_url( $chamberi_url ) . '">' . esc_html__( 'NUVANX Chamberí', 'nuvanx-medical' ) . '</a>';
	$html .= esc_html__( ' o en ', 'nuvanx-medical' );
	$html .= '<a class="nvx-brand-inline-link" href="' . esc_url( $goya_url ) . '">' . esc_html__( 'NUVANX Salamanca–Goya', 'nuvanx-medical' ) . '</a>';
	$html .= esc_html__( '; cada sede mantiene su propia entidad local y datos clínicos.', 'nuvanx-medical' );
	$html .= '</p>';
	$html .= '</div></section>';

	return $html;
}

/**
 * Place the contextual graph immediately before the FAQ. The existing page CTA
 * remains the primary conversion target and this block introduces no new button.
 */
function nvx_endolift_authority_graph_inject( string $content ): string {
	if ( ! nvx_endolift_authority_graph_is_target() || false !== strpos( $content, 'data-nvx-endolift-authority-graph=' ) ) {
		return $content;
	}

	$needle = '<section class="nvx-brand-section nvx-endolift-faq"';
	$offset = strpos( $content, $needle );
	if ( false === $offset ) {
		return $content;
	}

	return substr( $content, 0, $offset ) . nvx_endolift_authority_graph_markup() . substr( $content, $offset );
}
add_filter( 'the_content', 'nvx_endolift_authority_graph_inject', NVX_HOOK_PRIO_ENDOLIFT_AUTHORITY_GRAPH );

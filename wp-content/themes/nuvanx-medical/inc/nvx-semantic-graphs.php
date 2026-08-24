<?php
/**
 * Centralized semantic internal linking graphs.
 * Distributes contextual authority from informational/foundational nodes to Money Pages.
 *
 * @package NUVANX
 */

defined( 'ABSPATH' ) || exit;

/**
 * Returns a generic list of foundation links (clinics, doctors, valuation, pricing, cases).
 */
function nvx_get_foundation_links_markup(): string {
	$links = array(
		'/madrid/valoracion/' => 'Valoración Médica Madrid',
		'/clinicas-de-medicina-estetica-nuvanx/medicina-estetica-chamberi/' => 'Clínica Medicina Estética Chamberí',
		'/clinicas-de-medicina-estetica-nuvanx/medicina-estetica-goya-barrio-salamanca/' => 'Clínica Medicina Estética Goya',
		'/equipo-medico/' => 'Dr. Rivera Tejeda y Equipo',
		'/precios-medicina-estetica-madrid/' => 'Precios de Tratamientos',
		'/casos-clinicos/' => 'Casos Reales Antes y Después',
	);

	$html = '<ul class="nvx-semantic-list">';
	foreach ( $links as $path => $label ) {
		$html .= '<li><a href="' . esc_url( home_url( $path ) ) . '">' . esc_html( $label ) . '</a></li>';
	}
	$html .= '</ul>';
	return $html;
}

/**
 * Returns the Endolift specific semantic graph.
 */
function nvx_semantic_graph_endolift(): string {
	$html  = '<nav class="nvx-semantic-graph nvx-semantic-graph--endolift" aria-label="' . esc_attr__( 'Explora más sobre Endolift y tratamientos asociados', 'nuvanx-medical' ) . '">';
	$html .= '<h3 class="nvx-semantic-graph__title">' . esc_html__( 'Tratamientos y Enlaces Relacionados con Endolift®', 'nuvanx-medical' ) . '</h3>';
	$html .= '<ul class="nvx-semantic-list">';
	$html .= '<li><a href="' . esc_url( home_url( '/endolift-facial-papada-mandibula/' ) ) . '">' . esc_html__( 'Endolift para Papada y Mandíbula', 'nuvanx-medical' ) . '</a></li>';
	$html .= '<li><a href="' . esc_url( home_url( '/smartlipo-laserlipolisis-endolift/' ) ) . '">' . esc_html__( 'Smartlipo vs Endolift: Ciencia del Láser', 'nuvanx-medical' ) . '</a></li>';
	$html .= '<li><a href="' . esc_url( home_url( '/casos-clinicos/#endolift' ) ) . '">' . esc_html__( 'Casos Clínicos de Endolift', 'nuvanx-medical' ) . '</a></li>';
	$html .= '</ul>';
	$html .= '<h4 class="nvx-semantic-graph__subtitle">' . esc_html__( 'Nodos Clínicos', 'nuvanx-medical' ) . '</h4>';
	$html .= nvx_get_foundation_links_markup();
	$html .= '</nav>';
	return $html;
}

/**
 * Returns the CO2 specific semantic graph.
 */
function nvx_semantic_graph_co2(): string {
	$html  = '<nav class="nvx-semantic-graph nvx-semantic-graph--co2" aria-label="' . esc_attr__( 'Explora más sobre Láser CO2', 'nuvanx-medical' ) . '">';
	$html .= '<h3 class="nvx-semantic-graph__title">' . esc_html__( 'Láser CO₂ Fraccionado y Tratamientos de Piel', 'nuvanx-medical' ) . '</h3>';
	$html .= '<ul class="nvx-semantic-list">';
	$html .= '<li><a href="' . esc_url( home_url( '/laser-co2-fraccionado-madrid-textura-cicatrices-poro/' ) ) . '">' . esc_html__( 'Láser CO2 Fraccionado: Información Completa', 'nuvanx-medical' ) . '</a></li>';
	$html .= '<li><a href="' . esc_url( home_url( '/journal/' ) ) . '">' . esc_html__( 'Journal: Recuperación y Cuidados Post-Láser', 'nuvanx-medical' ) . '</a></li>';
	$html .= '</ul>';
	$html .= '<h4 class="nvx-semantic-graph__subtitle">' . esc_html__( 'Nodos Clínicos', 'nuvanx-medical' ) . '</h4>';
	$html .= nvx_get_foundation_links_markup();
	$html .= '</nav>';
	return $html;
}

/**
 * Returns the Endoláser specific semantic graph.
 */
function nvx_semantic_graph_endolaser(): string {
	$html  = '<nav class="nvx-semantic-graph nvx-semantic-graph--endolaser" aria-label="' . esc_attr__( 'Explora más sobre Endoláser Corporal', 'nuvanx-medical' ) . '">';
	$html .= '<h3 class="nvx-semantic-graph__title">' . esc_html__( 'Endoláser Corporal y Remodelación', 'nuvanx-medical' ) . '</h3>';
	$html .= '<ul class="nvx-semantic-list">';
	$html .= '<li><a href="' . esc_url( home_url( '/smartlipo-laserlipolisis-endolift/' ) ) . '">' . esc_html__( 'Laserlipólisis y Retracción Corporal', 'nuvanx-medical' ) . '</a></li>';
	$html .= '</ul>';
	$html .= '<h4 class="nvx-semantic-graph__subtitle">' . esc_html__( 'Nodos Clínicos', 'nuvanx-medical' ) . '</h4>';
	$html .= nvx_get_foundation_links_markup();
	$html .= '</nav>';
	return $html;
}

/**
 * Returns the Sedes -> Treatments semantic graph.
 */
function nvx_semantic_graph_clinics(): string {
	$html  = '<nav class="nvx-semantic-graph nvx-semantic-graph--clinics" aria-label="' . esc_attr__( 'Tratamientos en Clínicas NUVANX', 'nuvanx-medical' ) . '">';
	$html .= '<h3 class="nvx-semantic-graph__title">' . esc_html__( 'Principales Tratamientos Disponibles en nuestras Sedes', 'nuvanx-medical' ) . '</h3>';
	$html .= '<ul class="nvx-semantic-list">';
	$html .= '<li><a href="' . esc_url( home_url( '/endolift-facial-papada-mandibula/' ) ) . '">' . esc_html__( 'Endolift Facial y Papada', 'nuvanx-medical' ) . '</a></li>';
	$html .= '<li><a href="' . esc_url( home_url( '/laser-co2-fraccionado-madrid-textura-cicatrices-poro/' ) ) . '">' . esc_html__( 'Láser CO₂ Fraccionado', 'nuvanx-medical' ) . '</a></li>';
	$html .= '<li><a href="' . esc_url( home_url( '/endolaser-corporal-grasa-localizada/' ) ) . '">' . esc_html__( 'Endoláser Corporal', 'nuvanx-medical' ) . '</a></li>';
	$html .= '<li><a href="' . esc_url( home_url( '/exion-face/' ) ) . '">' . esc_html__( 'Rejuvenecimiento con EXION Face', 'nuvanx-medical' ) . '</a></li>';
	$html .= '</ul>';
	$html .= '</nav>';
	return $html;
}

/**
 * Returns the Valuation semantic graph.
 */
function nvx_semantic_graph_valoracion(): string {
	$html  = '<nav class="nvx-semantic-graph nvx-semantic-graph--valoracion" aria-label="' . esc_attr__( 'Conoce más sobre NUVANX', 'nuvanx-medical' ) . '">';
	$html .= '<h3 class="nvx-semantic-graph__title">' . esc_html__( 'Antes de tu Valoración', 'nuvanx-medical' ) . '</h3>';
	$html .= '<ul class="nvx-semantic-list">';
	$html .= '<li><a href="' . esc_url( home_url( '/equipo-medico/' ) ) . '">' . esc_html__( 'Conoce al Dr. Rivera Tejeda', 'nuvanx-medical' ) . '</a></li>';
	$html .= '<li><a href="' . esc_url( home_url( '/precios-medicina-estetica-madrid/' ) ) . '">' . esc_html__( 'Filosofía de Precios en NUVANX', 'nuvanx-medical' ) . '</a></li>';
	$html .= '<li><a href="' . esc_url( home_url( '/casos-clinicos/' ) ) . '">' . esc_html__( 'Revisa nuestros Casos Clínicos', 'nuvanx-medical' ) . '</a></li>';
	$html .= '</ul>';
	$html .= '</nav>';
	return $html;
}

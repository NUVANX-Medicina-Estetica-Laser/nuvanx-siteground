<?php
/**
 * Canonical Page Registry — Single Source of Truth for Route, Owner, Renderer, Surface, Template.
 *
 * Eliminates distributed filter resolution and bootstrap load-order dependencies.
 *
 * @package nuvanx-medical
 * @version 1.0.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns the canonical Page Registry matrix:
 * path => [ 'owner' => ..., 'renderer' => ..., 'surface' => ..., 'template' => ... ]
 *
 * @return array<string, array{owner: string, renderer: string, surface: string, template: string}>
 */
function nvx_get_canonical_page_registry(): array {
	static $registry = null;
	if ( null !== $registry ) {
		return $registry;
	}

	$registry = array(
		'/' => array(
			'owner'    => 'nvx_home_page',
			'renderer' => 'front-page.php',
			'surface'  => 'surface-warm',
			'template' => 'front-page.php',
		),
		'/nosotros/' => array(
			'owner'    => 'nvx_nosotros_page',
			'renderer' => 'nvx_content_restructure_nosotros_page',
			'surface'  => 'surface-warm',
			'template' => 'page.php',
		),
		'/sobre-nosotros/' => array(
			'owner'    => 'nvx_nosotros_page',
			'renderer' => 'nvx_content_restructure_nosotros_page',
			'surface'  => 'surface-warm',
			'template' => 'page.php',
		),
		'/equipo-medico/' => array(
			'owner'    => 'nvx_equipo_page',
			'renderer' => 'nvx_content_restructure_equipo_page',
			'surface'  => 'surface-warm',
			'template' => 'page.php',
		),
		'/clinicas-de-medicina-estetica-nuvanx/' => array(
			'owner'    => 'nvx_clinics_hub',
			'renderer' => 'nvx_content_restructure_clinics_hub_page',
			'surface'  => 'surface-warm',
			'template' => 'page.php',
		),
		'/madrid/valoracion/' => array(
			'owner'    => 'nvx_valoracion_managed_page',
			'renderer' => 'nvx_render_managed_valoracion_page',
			'surface'  => 'surface-warm',
			'template' => 'templates/page-landing-valoracion.php',
		),
		'/soluciones-medicas/' => array(
			'owner'    => 'nvx_soluciones_medicas_page',
			'renderer' => 'templates/page-soluciones-medicas.php',
			'surface'  => 'surface-warm',
			'template' => 'templates/page-soluciones-medicas.php',
		),
		'/contacto/' => array(
			'owner'    => 'nvx_contacto_page',
			'renderer' => 'templates/page-contacto.php',
			'surface'  => 'surface-warm',
			'template' => 'templates/page-contacto.php',
		),
		'/casos-de-pacientes/' => array(
			'owner'    => 'nvx_casos_pacientes_page',
			'renderer' => 'page-casos-de-pacientes.php',
			'surface'  => 'surface-warm',
			'template' => 'page-casos-de-pacientes.php',
		),
		'/medicina-estetica/' => array(
			'owner'    => 'nvx_aesthetic_medicine_page',
			'renderer' => 'nvx_aesthetic_medicine_page_content',
			'surface'  => 'surface-warm',
			'template' => 'page.php',
		),
		'/medicina-estetica-laser/' => array(
			'owner'    => 'nvx_laser_medicine_page',
			'renderer' => 'nvx_laser_medicine_page_content',
			'surface'  => 'surface-warm',
			'template' => 'page.php',
		),
		'/endolift-facial-papada-mandibula/' => array(
			'owner'    => 'nvx_endolift_page',
			'renderer' => 'nvx_endolift_page_content',
			'surface'  => 'surface-warm',
			'template' => 'page.php',
		),
		'/endolaser-corporal-grasa-localizada/' => array(
			'owner'    => 'nvx_endolaser_page',
			'renderer' => 'nvx_endolaser_page_content',
			'surface'  => 'surface-warm',
			'template' => 'page.php',
		),
		'/laser-co2-fraccionado-madrid-textura-cicatrices-poro/' => array(
			'owner'    => 'nvx_co2_page',
			'renderer' => 'nvx_co2_page_content',
			'surface'  => 'surface-warm',
			'template' => 'page.php',
		),
		'/profhilo-madrid/' => array(
			'owner'    => 'nvx_profhilo_page',
			'renderer' => 'nvx_profhilo_page_content',
			'surface'  => 'surface-warm',
			'template' => 'page.php',
		),
		'/exion-btl/' => array(
			'owner'    => 'nvx_exion_page',
			'renderer' => 'nvx_exion_page_content',
			'surface'  => 'surface-warm',
			'template' => 'page.php',
		),
		'/exion-face/' => array(
			'owner'    => 'nvx_btl_detail_page',
			'renderer' => 'nvx_btl_detail_page_content',
			'surface'  => 'surface-warm',
			'template' => 'page.php',
		),
		'/exion-body/' => array(
			'owner'    => 'nvx_btl_detail_page',
			'renderer' => 'nvx_btl_detail_page_content',
			'surface'  => 'surface-warm',
			'template' => 'page.php',
		),
		'/exion-fractional/' => array(
			'owner'    => 'nvx_btl_detail_page',
			'renderer' => 'nvx_btl_detail_page_content',
			'surface'  => 'surface-warm',
			'template' => 'page.php',
		),
		'/emfusion/' => array(
			'owner'    => 'nvx_btl_detail_page',
			'renderer' => 'nvx_btl_detail_page_content',
			'surface'  => 'surface-warm',
			'template' => 'page.php',
		),
		'/btl-exilite-ipl-madrid/' => array(
			'owner'    => 'nvx_btl_detail_page',
			'renderer' => 'nvx_btl_detail_page_content',
			'surface'  => 'surface-warm',
			'template' => 'page.php',
		),
		'/por-que-nuvanx/' => array(
			'owner'    => 'nvx_strategy_pages',
			'renderer' => 'nvx_strategy_page_content_filter',
			'surface'  => 'surface-warm',
			'template' => 'page.php',
		),
		'/inversion-medicina-estetica/' => array(
			'owner'    => 'nvx_strategy_pages',
			'renderer' => 'nvx_strategy_page_content_filter',
			'surface'  => 'surface-warm',
			'template' => 'page.php',
		),
		'/protocolos-signature/' => array(
			'owner'    => 'nvx_signature_hub_page',
			'renderer' => 'nvx_signature_phase_inject_markup',
			'surface'  => 'surface-warm',
			'template' => 'page.php',
		),
		'/papada-definicion-mandibular-madrid/' => array(
			'owner'    => 'nvx_signature_phase_pages',
			'renderer' => 'nvx_signature_phase_inject_markup',
			'surface'  => 'surface-warm',
			'template' => 'page.php',
		),
		'/calidad-piel-firmeza-luminosidad-madrid/' => array(
			'owner'    => 'nvx_signature_phase_pages',
			'renderer' => 'nvx_signature_phase_inject_markup',
			'surface'  => 'surface-warm',
			'template' => 'page.php',
		),
		'/cicatrices-acne-poros-textura-madrid/' => array(
			'owner'    => 'nvx_signature_phase_pages',
			'renderer' => 'nvx_signature_phase_inject_markup',
			'surface'  => 'surface-warm',
			'template' => 'page.php',
		),
		'/manchas-rojeces-fotorejuvenecimiento-ipl-madrid/' => array(
			'owner'    => 'nvx_signature_phase_pages',
			'renderer' => 'nvx_signature_phase_inject_markup',
			'surface'  => 'surface-warm',
			'template' => 'page.php',
		),
		'/grasa-localizada-abdomen-flancos-madrid/' => array(
			'owner'    => 'nvx_signature_phase_pages',
			'renderer' => 'nvx_signature_phase_inject_markup',
			'surface'  => 'surface-warm',
			'template' => 'page.php',
		),
		'/flacidez-grasa-localizada-brazos-madrid/' => array(
			'owner'    => 'nvx_signature_phase_pages',
			'renderer' => 'nvx_signature_phase_inject_markup',
			'surface'  => 'surface-warm',
			'template' => 'page.php',
		),
		'/grasa-espalda-zona-sujetador-madrid/' => array(
			'owner'    => 'nvx_signature_phase_pages',
			'renderer' => 'nvx_signature_phase_inject_markup',
			'surface'  => 'surface-warm',
			'template' => 'page.php',
		),
		'/flacidez-muslos-internos-subgluteo-madrid/' => array(
			'owner'    => 'nvx_signature_phase_pages',
			'renderer' => 'nvx_signature_phase_inject_markup',
			'surface'  => 'surface-warm',
			'template' => 'page.php',
		),
		'/tratamiento-rodillas-grasa-flacidez-madrid/' => array(
			'owner'    => 'nvx_signature_phase_pages',
			'renderer' => 'nvx_signature_phase_inject_markup',
			'surface'  => 'surface-warm',
			'template' => 'page.php',
		),
		'/contorno-corporal-masculino-madrid/' => array(
			'owner'    => 'nvx_signature_phase_pages',
			'renderer' => 'nvx_signature_phase_inject_markup',
			'surface'  => 'surface-warm',
			'template' => 'page.php',
		),
		'/labios-acido-hialuronico-madrid/' => array(
			'owner'    => 'nvx_aesthetic_treatment_pages',
			'renderer' => 'nvx_aesthetic_treatment_page_content',
			'surface'  => 'surface-warm',
			'template' => 'page.php',
		),
		'/rinomodelacion-sin-cirugia-madrid/' => array(
			'owner'    => 'nvx_aesthetic_treatment_pages',
			'renderer' => 'nvx_aesthetic_treatment_page_content',
			'surface'  => 'surface-warm',
			'template' => 'page.php',
		),
		'/ojeras-surco-lagrimal-madrid/' => array(
			'owner'    => 'nvx_aesthetic_treatment_pages',
			'renderer' => 'nvx_aesthetic_treatment_page_content',
			'surface'  => 'surface-warm',
			'template' => 'page.php',
		),
		'/bioestimuladores-colageno-madrid/' => array(
			'owner'    => 'nvx_aesthetic_treatment_pages',
			'renderer' => 'nvx_aesthetic_treatment_page_content',
			'surface'  => 'surface-warm',
			'template' => 'page.php',
		),
		'/neuromoduladores-faciales-madrid/' => array(
			'owner'    => 'nvx_aesthetic_treatment_pages',
			'renderer' => 'nvx_aesthetic_treatment_page_content',
			'surface'  => 'surface-warm',
			'template' => 'page.php',
		),
		'/acido-hialuronico-relleno-madrid/' => array(
			'owner'    => 'nvx_aesthetic_treatment_pages',
			'renderer' => 'nvx_aesthetic_treatment_page_content',
			'surface'  => 'surface-warm',
			'template' => 'page.php',
		),
	);

	$clinics = function_exists( 'nvx_get_clinics_config' ) ? nvx_get_clinics_config() : array();
	foreach (
		array(
			'chamberi' => 'nvx_sede_chamberi',
			'goya'     => 'nvx_sede_goya',
		) as $clinic_key => $owner
	) {
		$landing_path = trim( (string) ( $clinics[ $clinic_key ]['landing_path'] ?? '' ) );
		if ( '' === $landing_path ) {
			continue;
		}
		$landing_path = '/' . trim( $landing_path, '/' ) . '/';
		$registry[ $landing_path ] = array(
			'owner'    => $owner,
			'renderer' => 'templates/page-sede.php',
			'surface'  => 'surface-warm',
			'template' => 'templates/page-sede.php',
		);
	}

	return $registry;
}

/**
 * Resolve canonical registry entry by path or queried post ID.
 *
 * @param int|null $post_id Post ID if available.
 * @return array{owner: string, renderer: string, surface: string, template: string}|null
 */
function nvx_resolve_canonical_page_entry( ?int $post_id = null ): ?array {
	$registry = nvx_get_canonical_page_registry();
	$target_id = $post_id ?? (int) get_queried_object_id();

	// Check current request URI path via schema helper
	if ( function_exists( 'nvx_schema_current_path' ) ) {
		$path = nvx_schema_current_path( $target_id );
		if ( isset( $registry[ $path ] ) ) {
			return $registry[ $path ];
		}
	}

	// Check queried post slug
	if ( $target_id > 0 ) {
		$slug = (string) get_post_field( 'post_name', $target_id );
		if ( '' !== $slug ) {
			$slug_path = '/' . trim( $slug, '/' ) . '/';
			if ( isset( $registry[ $slug_path ] ) ) {
				return $registry[ $slug_path ];
			}
		}
	}

	// Normalize $_SERVER['REQUEST_URI'] as fallback
	if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
		$raw_path = (string) wp_parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH );
		$norm_path = '/' . trim( $raw_path, '/' ) . '/';
		if ( '/' === $norm_path || '//' === $norm_path ) {
			$norm_path = '/';
		}
		if ( isset( $registry[ $norm_path ] ) ) {
			return $registry[ $norm_path ];
		}
	}

	return null;
}

/**
 * Get canonical page owner, independent of hook registration order.
 *
 * @param int|null $post_id Post ID if available.
 * @return string|null
 */
function nvx_get_canonical_page_owner( ?int $post_id = null ): ?string {
	$entry = nvx_resolve_canonical_page_entry( $post_id );
	return $entry['owner'] ?? null;
}

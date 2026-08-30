<?php
/**
 * Graph assembly: Organisation and Article enrichment, node attachment,
 * Yoast schema graph filter, FAQ emission gate, and @id deduplication.
 *
 * Extracted from nvx-structured-data.php.
 *
 * @package NUVANX
 */

defined( 'ABSPATH' ) || exit;

function nvx_schema_enrich_organization( array &$graph, int $index, array $all_clinics, array $physicians ): void {
	$cfg          = function_exists( 'nvx_get_clinics_config' ) ? nvx_get_clinics_config() : array();
	$chamberi_tel = (string) ( $cfg['chamberi']['phone_href'] ?? '' );
	$goya_tel     = (string) ( $cfg['goya']['phone_href'] ?? '' );

	$graph[ $index ]['@type']                  = nvx_schema_add_type( $graph[ $index ]['@type'], 'MedicalOrganization' );
	$graph[ $index ]['name']                   = 'NUVANX Medicina Estética Láser';
	$graph[ $index ]['alternateName']          = array( 'NUVANX', 'NUVANX Madrid', 'NUVANX Medicina Estética Láser Madrid' );
	$graph[ $index ]['url']                    = home_url( '/' );
	$graph[ $index ]['description']            = 'Centro médico de medicina estética láser y well-aging en Madrid (Chamberí y Goya · Barrio Salamanca). Protocolos Endolift®, endoláser, Láser CO₂ y EXION® BTL con dirección médica y criterio científico (geriatría preventiva / longevidad).';
	$graph[ $index ]['email']                  = NVX_CONTACT_EMAIL;
	$graph[ $index ]['telephone']              = $chamberi_tel;
	$graph[ $index ]['isAcceptingNewPatients'] = true;
	$graph[ $index ]['address']                = array( $all_clinics['chamberi']['address'], $all_clinics['goya']['address'] );
	$graph[ $index ]['contactPoint']           = array(
		array(
			'@type'             => 'ContactPoint',
			'contactType'       => 'Citas — Chamberí',
			'telephone'         => $chamberi_tel,
			'areaServed'        => 'ES',
			'availableLanguage' => array( 'es', 'en' ),
		),
		array(
			'@type'             => 'ContactPoint',
			'contactType'       => 'Citas — Goya · Barrio Salamanca',
			'telephone'         => $goya_tel,
			'areaServed'        => 'ES',
			'availableLanguage' => array( 'es', 'en' ),
		),
	);
	$graph[ $index ]['knowsAbout']             = array(
		'Medicina estética',
		'Medicina estética láser',
		NVX_SD_ENDOLIFT_FACIAL,
		'Marcación mandibular con láser',
		NVX_SD_ENDOLASER_CORPORAL,
		NVX_SD_LASER_CO2_FRACCIONADO,
		'EXION® BTL',
		'BTL EXILITE™ IPL',
		NVX_SD_MEDICINA_REGENERATIVA,
		'Well-aging',
		'Geriatría preventiva',
		'Longevidad',
	);
	$graph[ $index ]['potentialAction']        = array(
		'@type'  => 'ReserveAction',
		'name'   => 'Reserva de valoración diagnóstica',
		'target' => array(
			'@type'          => 'EntryPoint',
			'urlTemplate'    => home_url( '/madrid/valoracion/' ),
			'inLanguage'     => 'es',
			'actionPlatform' => array(
				'https://schema.org/DesktopWebPlatform',
				'https://schema.org/MobileWebPlatform',
			),
		),
		'result' => array(
			'@type' => 'Reservation',
			'name'  => 'Cita médica presencial',
		),
	);

	if ( ! empty( $physicians ) ) {
		$employee_refs = array();
		foreach ( $physicians as $person ) {
			$employee_refs[] = array( '@id' => $person['@id'] );
		}
		$graph[ $index ]['employee'] = $employee_refs;
	}

	$existing_same_as = isset( $graph[ $index ]['sameAs'] ) ? (array) $graph[ $index ]['sameAs'] : array();
	// Note: Doctoralia links belong to individual clinic nodes, not corporate Organization
	// Each MedicalClinic maintains its own sameAs for location-specific external identity
	// Facebook (corporate page) is an Organization-level identity and belongs here.
	$org_social = array(
		'https://www.facebook.com/profile.php?id=61593612745090',
		'https://www.instagram.com/nuvanx/',
	);
	$graph[ $index ]['sameAs'] = array_values( array_unique( array_filter( array_merge( $existing_same_as, $org_social ) ) ) );
}

/**
 * Clinic branch keys to attach for the current page.
 *
 * Home and equipo get both branches; other pages use path/meta resolution.
 *
 * @param int $page_id Current page ID.
 * @return string[]
 */
function nvx_schema_clinic_keys_for_page( int $page_id ): array {
	if ( is_front_page() || is_singular( 'post' ) ) {
		return array( 'chamberi', 'goya' );
	}

	$path = nvx_schema_current_path( $page_id );
	if ( nvx_schema_path_matches( $path, NVX_SD_PATH_EQUIPO_MEDICO ) ) {
		return array( 'chamberi', 'goya' );
	}

	return nvx_schema_resolve_clinic_keys( $page_id );
}

/**
 * Enriches Article / BlogPosting nodes in Yoast Schema graph with E-E-A-T authorship and MedicalOrganization publisher.
 */
function nvx_schema_enrich_article( array &$graph, int $post_id, string $org_id, ?array $physician ): void {
	if ( ! is_singular( 'post' ) ) {
		return;
	}

	$author_id = ( null !== $physician && ! empty( $physician['@id'] ) )
		? $physician['@id']
		: home_url( '/equipo-medico/#physician-rivera-tejeda' );

	foreach ( $graph as $index => $node ) {
		$types = isset( $node['@type'] ) ? (array) $node['@type'] : array();
		if ( in_array( 'Article', $types, true ) || in_array( 'BlogPosting', $types, true ) || in_array( 'NewsArticle', $types, true ) ) {
			$graph[ $index ]['publisher']  = array( '@id' => $org_id );
			$graph[ $index ]['author']     = array( '@id' => $author_id );
			$graph[ $index ]['inLanguage'] = 'es';
		}
	}
}

/**
 * Attaches clinic sub-organizations and offer catalog to the Yoast schema graph.
 *
 * subOrganization / department refs only include clinics actually appended to
 * the graph (no dangling @id when a single branch page is rendered).
 */
function nvx_schema_attach_clinics_graph( array &$graph, int $page_id, array $organization, array $all_clinics, array $physicians ): void {
	if ( null === $organization['index'] ) {
		return;
	}

	$clinic_keys = nvx_schema_clinic_keys_for_page( $page_id );
	if ( empty( $clinic_keys ) ) {
		return;
	}

	if ( is_front_page() ) {
		$catalog = nvx_schema_offer_catalog( $organization['id'] );
		$graph[ $organization['index'] ]['hasOfferCatalog'] = array( '@id' => $catalog['@id'] );
		$graph[] = $catalog;
	}

	$clinic_employees = array();
	foreach ( $physicians as $person ) {
		if ( ! empty( $person['@id'] ) ) {
			$clinic_employees[] = array( '@id' => $person['@id'] );
		}
	}

	$clinic_refs = array();
	foreach ( $clinic_keys as $key ) {
		if ( empty( $all_clinics[ $key ] ) ) {
			continue;
		}
		$clinic                       = $all_clinics[ $key ];
		$clinic['parentOrganization'] = array( '@id' => $organization['id'] );
		if ( ! empty( $clinic_employees ) ) {
			$clinic['employee'] = $clinic_employees;
		}
		$clinic_refs[] = array( '@id' => $clinic['@id'] );
		$graph[]       = $clinic;
	}

	if ( ! empty( $clinic_refs ) ) {
		$graph[ $organization['index'] ]['subOrganization'] = $clinic_refs;
	}
}

/**
 * Attaches publication nodes for team members if on equipo page.
 */
function nvx_schema_attach_publications( array &$graph, int $page_id, array $physicians ): void {
	if ( ! nvx_schema_path_matches( nvx_schema_current_path( $page_id ), NVX_SD_PATH_EQUIPO_MEDICO ) ) {
		return;
	}
	foreach ( $physicians as $person ) {
		if ( empty( $person['@id'] ) ) {
			continue;
		}
		if ( false !== strpos( $person['name'] ?? '', 'Ivon' ) ) {
			foreach ( nvx_schema_ivon_publications( $person['@id'] ) as $work ) {
				$graph[] = $work;
			}
		}
		if ( false !== strpos( $person['name'] ?? '', 'Fabio' ) ) {
			foreach ( nvx_schema_fabio_publications( $person['@id'] ) as $work ) {
				$graph[] = $work;
			}
		}
	}
}

/**
 * Link WebPage.mainEntity to an entity @id when the page URL matches.
 *
 * This is the single graph-linking implementation shared by both schema passes.
 *
 * @param array  $graph     Schema graph.
 * @param string $pageUrl   Canonical page URL.
 * @param string $entityId  Entity node @id.
 * @return array Updated schema graph.
 */
function nvx_schema_link_webpage_main_entity( array $graph, string $pageUrl, string $entityId ): array {
	if ( '' === $pageUrl || '' === $entityId ) {
		return $graph;
	}

	$target = trailingslashit( $pageUrl );
	foreach ( $graph as $index => $piece ) {
		$types = isset( $piece['@type'] ) ? (array) $piece['@type'] : array();
		$url   = isset( $piece['url'] ) ? trailingslashit( (string) $piece['url'] ) : '';
		if ( in_array( 'WebPage', $types, true ) && $url === $target ) {
			$graph[ $index ]['mainEntity'] = array( '@id' => $entityId );
			break;
		}
	}

	return $graph;
}

/**
 * Attaches treatment and FAQ nodes to schema graph when applicable.
 */
function nvx_schema_attach_treatment_and_faq( array &$graph, int $page_id, string $org_id, ?array $physician ): void {
	if ( is_singular( 'post' ) ) {
		nvx_schema_enrich_article( $graph, $page_id, $org_id, $physician );
		return;
	}

	$treatment = nvx_schema_treatment_node( $page_id, $org_id );
	if ( null !== $treatment ) {
		$graph[] = $treatment;
		if ( ! empty( $treatment['@id'] ) && ! empty( $treatment['url'] ) ) {
			$graph = nvx_schema_link_webpage_main_entity( $graph, (string) $treatment['url'], (string) $treatment['@id'] );
		}
	}

	$faq = nvx_schema_faq_node( $page_id );
	if ( null !== $faq ) {
		$graph[] = $faq;
	}
}


/**
 * Emits BreadcrumbList schema based on routes.json or dynamic post hierarchy.
 */
function nvx_schema_breadcrumb_node( $page_id ) {
	$path = nvx_schema_current_path( $page_id );
	if ( function_exists( 'nvx_catalog_json_resolved' ) ) {
		$routes = nvx_catalog_json_resolved( 'routes.json' );
		if ( ! empty( $routes[ $path ]['breadcrumb'] ) && is_array( $routes[ $path ]['breadcrumb'] ) ) {
			$items    = array();
			$position = 1;
			foreach ( $routes[ $path ]['breadcrumb'] as $b ) {
				if ( ! empty( $b['name'] ) && ! empty( $b['url'] ) ) {
					$items[] = array(
						'@type'    => 'ListItem',
						'position' => $position++,
						'name'     => $b['name'],
						'item'     => home_url( $b['url'] ),
					);
				}
			}
			if ( ! empty( $items ) ) {
				return array(
					'@type'           => 'BreadcrumbList',
					'@id'             => home_url( $path . '#breadcrumb' ),
					'itemListElement' => $items,
				);
			}
		}
	}

	// Dynamic breadcrumbs for single blog posts: Inicio > Blog > Título
	$target_id = $page_id > 0 ? (int) $page_id : (int) get_queried_object_id();
	if ( $target_id > 0 && ( is_single( $target_id ) || 'post' === get_post_type( $target_id ) ) ) {
		$title = get_the_title( $target_id );
		if ( is_string( $title ) && '' !== trim( $title ) ) {
			return array(
				'@type'           => 'BreadcrumbList',
				'@id'             => home_url( $path . '#breadcrumb' ),
				'itemListElement' => array(
					array(
						'@type'    => 'ListItem',
						'position' => 1,
						'name'     => 'Inicio',
						'item'     => home_url( '/' ),
					),
					array(
						'@type'    => 'ListItem',
						'position' => 2,
						'name'     => 'Blog',
						'item'     => home_url( '/blog/' ),
					),
					array(
						'@type'    => 'ListItem',
						'position' => 3,
						'name'     => trim( wp_strip_all_tags( $title ) ),
						'item'     => home_url( $path ),
					),
				),
			);
		}
	}

	return null;
}

/**
 * Emits VideoObject schema for the homepage hero video.
 */
function nvx_schema_video_object_node() {
	if ( ! is_front_page() ) {
		return null;
	}
	return array(
		'@type'        => 'VideoObject',
		'@id'          => home_url( '/#video' ),
		'name'         => 'NUVANX Medicina Estética Láser - Presentación',
		'description'  => 'Conoce NUVANX Medicina Estética Láser en Madrid. Tratamientos médicos con criterio, tecnología avanzada y resultados naturales.',
		'thumbnailUrl' => home_url( '/wp-content/themes/nuvanx-medical/assets/images/responsive/nvx-home-hero-poster-1920-1080.webp' ),
		'uploadDate'   => '2023-01-01T00:00:00Z',
		'contentUrl'   => home_url( '/wp-content/themes/nuvanx-medical/assets/video/nvx-home-hero-1080p.mp4' ),
	);
}

/**
 * Emits HowTo schema for treatment pages.
 */
function nvx_schema_howto_node( $page_id ) {
	// A simple heuristic for now: emit a standard HowTo for clinical assessment if it's a treatment page
	if ( nvx_schema_resolve_treatment_key( $page_id ) ) {
		$path = nvx_schema_current_path( $page_id );
		return array(
			'@type'       => 'HowTo',
			'@id'         => home_url( $path . '#howto' ),
			'name'        => 'Proceso de Valoración y Tratamiento',
			'description' => 'Pasos desde el diagnóstico hasta el tratamiento en NUVANX.',
			'step'        => array(
				array(
					'@type' => 'HowToStep',
					'name'  => 'Diagnóstico Clínico',
					'text'  => 'Evaluación médica integral, ecografía cutánea y diagnóstico diferencial para determinar la viabilidad.',
				),
				array(
					'@type' => 'HowToStep',
					'name'  => 'Planificación del Protocolo',
					'text'  => 'Definición de sesiones, parámetros y combinación tecnológica según el estado anatómico.',
				),
				array(
					'@type' => 'HowToStep',
					'name'  => 'Ejecución y Seguimiento',
					'text'  => 'Realización del procedimiento médico y pautas de recuperación guiadas por el equipo clínico.',
				),
			),
		);
	}
	return null;
}

/**
 * Add NUVANX medical locations and page entities to Yoast's canonical graph.
 *
 * @param array $graph Yoast Schema graph.
 * @return array
 */
function nvx_extend_yoast_schema_graph( $graph ) {
	if ( is_admin() || is_feed() || ( ! is_singular( 'page' ) && ! is_front_page() && ! is_singular( 'post' ) ) ) {
		return $graph;
	}

	$organization = nvx_schema_find_organization( $graph );
	$all_clinics  = nvx_schema_clinics();
	$page_id      = (int) get_queried_object_id();

	if ( null === $organization['index'] ) {
		$graph[]               = array(
			'@type' => array( 'Organization', 'MedicalOrganization' ),
			'@id'   => $organization['id'],
			'url'   => home_url( '/' ),
		);
		$organization['index'] = array_key_last( $graph );
	}

	// Add WebSite node for homepage only if it doesn't already exist
	// This prevents duplicate WebSite nodes when Yoast already emits one
	if ( is_front_page() ) {
		$website_id = home_url( '/#website' );
		$website_exists = false;

		foreach ( $graph as $node ) {
			if ( is_array( $node ) && isset( $node['@id'] ) && $node['@id'] === $website_id ) {
				$website_exists = true;
				break;
			}
		}

		if ( ! $website_exists ) {
			$graph[] = array(
				'@type'       => 'WebSite',
				'@id'         => $website_id,
				'url'         => home_url( '/' ),
				'name'        => 'NUVANX Medicina Estética Láser Madrid',
				'description' => 'Medicina estética láser en Madrid: Endolift®, EXION®, BTL, láser CO₂. Valoración presencial en Chamberí y Salamanca–Goya. Protocolos médicos basados en evidencia.',
				'publisher'   => array( '@id' => $organization['id'] ),
			);
		}
	}

	$physicians = nvx_schema_build_physicians( $page_id, $organization['id'] );
	$physician  = ! empty( $physicians ) ? $physicians[0] : null;

	if ( null !== $organization['index'] ) {
		nvx_schema_enrich_organization( $graph, $organization['index'], $all_clinics, $physicians );
	}

	nvx_schema_attach_clinics_graph( $graph, $page_id, $organization, $all_clinics, $physicians );

	foreach ( $physicians as $person ) {
		$graph[] = $person;
	}

	nvx_schema_attach_publications( $graph, $page_id, $physicians );
	nvx_schema_attach_treatment_and_faq( $graph, $page_id, $organization['id'], $physician );

	$breadcrumb = nvx_schema_breadcrumb_node( $page_id );
	if ( $breadcrumb ) {
		$graph[] = $breadcrumb;
	}

	$video = nvx_schema_video_object_node();
	if ( $video ) {
		$graph[] = $video;
	}

	$howto = nvx_schema_howto_node( $page_id );
	if ( $howto ) {
		$graph[] = $howto;
	}

	return $graph;
}
add_filter( 'wpseo_schema_graph', 'nvx_extend_yoast_schema_graph', 51 );

/**
 * Gate filter to enforce that FAQPage structured data is never emitted on
 * pages without visible FAQs. Purges orphan FAQPage nodes, removes 'FAQPage'
 * from composite @type arrays, unsets orphan Question entities, and drops
 * invalid FAQPage nodes with empty mainEntity.
 *
 * @param array $graph Yoast Schema graph.
 * @return array Sanitized Schema graph.
 */
function nvx_schema_gate_faq_emission( $graph ) {
	if ( ! is_array( $graph ) || is_admin() || is_feed() ) {
		return $graph;
	}

	$has_visible_faq = nvx_schema_page_has_visible_faq();

	foreach ( $graph as $index => $node ) {
		if ( ! is_array( $node ) || ! isset( $node['@type'] ) ) {
			continue;
		}

		$types       = is_array( $node['@type'] ) ? $node['@type'] : array( $node['@type'] );
		$is_faq_node = in_array( 'FAQPage', $types, true );

		if ( ! $is_faq_node ) {
			continue;
		}

		if ( ! $has_visible_faq ) {
			// Page has NO visible FAQ: purge FAQPage node or remove type.
			if ( count( $types ) === 1 ) {
				unset( $graph[ $index ] );
			} else {
				$remaining = array_values( array_diff( $types, array( 'FAQPage' ) ) );
				$graph[ $index ]['@type'] = count( $remaining ) === 1 ? $remaining[0] : $remaining;
				if ( isset( $graph[ $index ]['mainEntity'] ) && is_array( $graph[ $index ]['mainEntity'] ) ) {
					$first_entity = reset( $graph[ $index ]['mainEntity'] );
					if ( is_array( $first_entity ) && ( $first_entity['@type'] ?? '' ) === 'Question' ) {
						unset( $graph[ $index ]['mainEntity'] );
					}
				}
			}
			continue;
		}

		// Page HAS visible FAQ: ensure mainEntity contains valid non-empty questions.
		$has_valid_entities = false;
		if ( ! empty( $node['mainEntity'] ) && is_array( $node['mainEntity'] ) ) {
			foreach ( $node['mainEntity'] as $entity ) {
				if ( is_array( $entity ) && ( $entity['@type'] ?? '' ) === 'Question' && ! empty( $entity['name'] ) ) {
					$has_valid_entities = true;
					break;
				}
			}
		}

		if ( ! $has_valid_entities ) {
			if ( count( $types ) === 1 ) {
				unset( $graph[ $index ] );
			} else {
				$remaining = array_values( array_diff( $types, array( 'FAQPage' ) ) );
				$graph[ $index ]['@type'] = count( $remaining ) === 1 ? $remaining[0] : $remaining;
				unset( $graph[ $index ]['mainEntity'] );
			}
		}
	}

	return array_values( $graph );
}
add_filter( 'wpseo_schema_graph', 'nvx_schema_gate_faq_emission', 70 );

/**
 * Deduplicate Schema.org @id entries across the graph.
 *
 * @param array $graph Yoast Schema graph.
 * @return array
 */
function nvx_schema_deduplicate_ids( $graph ) {
	if ( ! is_array( $graph ) ) {
		return $graph;
	}

	$seen = array();
	foreach ( $graph as $key => $node ) {
		if ( isset( $node['@id'] ) && is_string( $node['@id'] ) ) {
			$id = $node['@id'];
			if ( isset( $seen[ $id ] ) ) {
				unset( $graph[ $key ] );
			} else {
				$seen[ $id ] = true;
			}
		}
	}

	return array_values( $graph );
}
add_filter( 'wpseo_schema_graph', 'nvx_schema_deduplicate_ids', 71 );

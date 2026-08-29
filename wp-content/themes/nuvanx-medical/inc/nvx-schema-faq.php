<?php
/**
 * FAQPage schema node builders: catalog loaders, visibility checks,
 * and node assembly.
 *
 * Extracted from nvx-structured-data.php without changing runtime behavior.
 *
 * @package NUVANX
 */

defined( 'ABSPATH' ) || exit;

/**
 * FAQ copy keyed by treatment registry keys.
 * Must mirror visible page FAQs (HTML + FAQPage). Answers transactional questions
 * that generative engines cite (precio, duración, recuperación, límites).
 *
 * Parse a single page JSON file for FAQ items.
 *
 * @param string $file JSON file relative path
 * @return array<string, array<int, array{q:string,a:string}>>
 */
function nvx_schema_faq_load_single_page( string $file ): array {
	$json  = nvx_catalog_json_resolved( $file );
	$items = array();
	if ( ! empty( $json['faq']['items'] ) && is_array( $json['faq']['items'] ) ) {
		foreach ( $json['faq']['items'] as $item ) {
			if ( ! empty( $item['q'] ) && ! empty( $item['a'] ) ) {
				$items[] = array(
					'q' => (string) $item['q'],
					'a' => (string) $item['a'],
				);
			}
		}
	}
	return $items;
}

/** Parse a mapped catalog JSON file for FAQ items. */
function nvx_schema_faq_load_map_catalog( string $file ): array {
	return nvx_schema_faq_load_map_catalog_impl( $file, null );
}

/** Parse a mapped catalog JSON file for FAQ items with claim resolver. */
function nvx_schema_faq_load_map_catalog_with_resolver( string $file, callable $resolver ): array {
	return nvx_schema_faq_load_map_catalog_impl( $file, $resolver );
}

/** Load FAQ items from Signature Phase catalog JSON. */
function nvx_schema_faq_load_signature_phase(): array {
	$json = nvx_catalog_json_resolved( 'nvx-signature-phase-catalog.json' );
	$catalog = array();
	if ( ! is_array( $json ) ) {
		return $catalog;
	}
	foreach ( $json as $key => $entry ) {
		if ( ! is_array( $entry ) || empty( $entry['faq'] ) || ! is_array( $entry['faq'] ) ) {
			continue;
		}
		$items = array();
		foreach ( $entry['faq'] as $item ) {
			if ( ! empty( $item['q'] ) && ! empty( $item['a'] ) ) {
				$items[] = array(
					'q' => (string) $item['q'],
					'a' => (string) $item['a'],
				);
			}
		}
		if ( ! empty( $items ) ) {
			$catalog[ $key ] = $items;
		}
	}

	// Add aliases for routes.json schema_id values that differ from catalog keys.
	// This ensures nvx_schema_faq_node() can look up FAQs when the treatment
	// registry resolves a schema_id like 'double_chin' instead of 'profile-definition'.
	$schema_id_map = array(
		'double_chin'      => 'profile-definition',
		'acne_scars'       => 'surface-renewal',
		'pigmentation'     => 'tone-correction',
		'local_fat_abdomen'=> 'abdomen-flancos',
		'postpartum'       => 'post-maternity',
	);
	foreach ( $schema_id_map as $schema_id => $catalog_key ) {
		if ( ! empty( $catalog[ $catalog_key ] ) ) {
			$catalog[ $schema_id ] = $catalog[ $catalog_key ];
		}
	}

	return $catalog;
}


/** Implementation for FAQ catalog loading from mapped JSON files. */
function nvx_schema_faq_load_map_catalog_impl( string $file, ?callable $resolver ): array {
	if ( null === $resolver ) {
		$json = nvx_catalog_json_resolved( $file );
	} else {
		$json = nvx_catalog_json_resolved( $file, $resolver, array(), array(), basename( $file, '.json' ) );
	}
	$catalog = array();
	if ( ! is_array( $json ) ) {
		return $catalog;
	}
	foreach ( $json as $key => $entry ) {
		if ( ! is_array( $entry ) || empty( $entry['faqs'] ) || ! is_array( $entry['faqs'] ) ) {
			continue;
		}
		$items = array();
		foreach ( $entry['faqs'] as $item ) {
			if ( ! empty( $item['q'] ) && ! empty( $item['a'] ) ) {
				$items[] = array(
					'q' => (string) $item['q'],
					'a' => (string) $item['a'],
				);
			}
		}
		if ( ! empty( $items ) ) {
			$catalog[ $key ] = $items;
			// Only add explicit 'key' alias if present in JSON
			if ( ! empty( $entry['key'] ) ) {
				$catalog[ $entry['key'] ] = $items;
			}
		}
	}
	return $catalog;
}

function nvx_schema_faq_catalog() {
	static $catalogs = array();
	$locale          = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
	if ( isset( $catalogs[ $locale ] ) ) {
		return $catalogs[ $locale ];
	}

	$catalog = array();
	if ( function_exists( 'nvx_catalog_json_resolved' ) ) {
		$catalog['endolift_facial']    = nvx_schema_faq_load_single_page( 'endolift-page.json' );
		$catalog['endolaser_corporal'] = nvx_schema_faq_load_single_page( 'endolaser-page.json' );
		// Use claim resolver for BTL detail pages to ensure claim keys are resolved
		if ( function_exists( 'nvx_btl_claim' ) ) {
			$catalog = array_merge( $catalog, nvx_schema_faq_load_map_catalog_with_resolver( 'btl-detail-pages.json', 'nvx_btl_claim' ) );
		} else {
			$catalog = array_merge( $catalog, nvx_schema_faq_load_map_catalog( 'btl-detail-pages.json' ) );
		}
		$aesthetic_faqs = nvx_schema_faq_load_map_catalog( 'aesthetic-treatment-pages.json' );
		$alias_map      = array(
			'rhinomodeling_ha' => 'rinomodelacion',
			'tear_trough_ha'   => 'dark_circles_ha',
			'biostimulators'   => 'collagen_bio',
			'neuromodulators'  => 'neuromodulador',
		);
		foreach ( $alias_map as $json_key => $schema_key ) {
			if ( ! empty( $aesthetic_faqs[ $json_key ] ) ) {
				$aesthetic_faqs[ $schema_key ] = $aesthetic_faqs[ $json_key ];
			}
		}
		$catalog = array_merge( $catalog, $aesthetic_faqs );
		// Load Signature Phase FAQs
		$signature_faqs = nvx_schema_faq_load_signature_phase();
		$catalog = array_merge( $catalog, $signature_faqs );
	}

	// Replace hardcoded Endolift® prices with dynamic tariff constants in FAQ answers
	if ( ! empty( $catalog['endolift_facial'] ) && function_exists( 'nvx_endolift_price_from_eur' ) && function_exists( 'nvx_endolift_price_papada_eur' ) ) {
		$from   = function_exists( 'nvx_format_price_eur' ) ? nvx_format_price_eur( nvx_endolift_price_from_eur() ) : number_format_i18n( nvx_endolift_price_from_eur(), 2 );
		$papada = function_exists( 'nvx_format_price_eur' ) ? nvx_format_price_eur( nvx_endolift_price_papada_eur() ) : number_format_i18n( nvx_endolift_price_papada_eur(), 2 );
		foreach ( $catalog['endolift_facial'] as &$faq ) {
			// Three format guards: comma-decimal (es_ES locale), dot-decimal (neutral/en),
			// and short form without cents — all silently fail if the FAQ text uses a different format.
			// Deuda conocida: el mecanismo es frágil ante edición manual. Issue SCHEMA-001.
			$faq['a'] = str_replace( '798 €', $from . ' €', $faq['a'] );
			$faq['a'] = str_replace( '798,60 €', $from . ' €', $faq['a'] );
			$faq['a'] = str_replace( '798.60 €', $from . ' €', $faq['a'] );
			$faq['a'] = str_replace( '1.064,80 €', $papada . ' €', $faq['a'] );
			$faq['a'] = str_replace( '1064.80 €', $papada . ' €', $faq['a'] );
		}
		unset( $faq );
	}


	if ( empty( $catalog['endolift_facial'] ) ) {
		$from                       = nvx_format_price_eur( nvx_endolift_price_from_eur() );
		$papada                     = nvx_format_price_eur( nvx_endolift_price_papada_eur() );
		$catalog['endolift_facial'] = array(
			array(
				'q' => '¿Cuánto cuesta el Endolift® facial en NUVANX Madrid?',
				'a' => 'PVP con IVA incluido desde ' . $from . ' € (ojeras). Papada y marcación mandibular: ' . $papada . ' € cada una. Full Face y combos en la tabla de tarifas de la página. El presupuesto se cierra tras valoración anatómica presencial.',
			),
			array(
				'q' => '¿Endolift® es para cualquier papada o flacidez?',
				'a' => 'No. Indicado en flacidez leve–moderada y grasa submentoniana seleccionada. La ptosis severa con exceso cutáneo se deriva a cirugía plástica; no se fuerza el láser.',
			),
			array(
				'q' => '¿Cuál es la durabilidad real de los resultados del Endolift®?',
				'a' => 'Al inducir colágeno profundo, no se comporta como un relleno temporal. La firmeza suele sostenerse entre 18 meses y 3 años según envejecimiento, sol, tabaquismo y genética. El seguimiento personaliza expectativas.',
			),
			array(
				'q' => '¿El Endolift® sustituye al ácido hialurónico?',
				'a' => 'No. Planos complementarios: Endolift® tensa piel y tejido conectivo y puede reducir grasa; rellenos o inductores aportan soporte volumétrico. Criterio NUVANX: tensar primero y rellenar después solo si está indicado.',
			),
			array(
				'q' => '¿Es doloroso?',
				'a' => 'Un poco de calor y algo de presión, nada más — usamos anestesia local precisamente para que no duela. Si te preocupa el dolor, dínoslo en la consulta: se puede ajustar.',
			),
		);
	}

	if ( empty( $catalog['endolaser_corporal'] ) ) {
		$catalog['endolaser_corporal'] = array(
			array(
				'q' => '¿Cuántas sesiones de Endoláser corporal se necesitan?',
				'a' => 'El procedimiento se realiza en 1 sesión única. Los resultados se observan progresivamente a partir de las 3 semanas, con efecto máximo a los 4-6 meses según la zona tratada y respuesta individual.',
			),
			array(
				'q' => '¿Es necesaria prenda de compresión?',
				'a' => 'Sí, se utiliza faja compresiva o malla elastodrenante durante 1-2 semanas post-tratamiento para optimizar la retracción tisular y el drenaje linfático.',
			),
		);
	}

	// Post-Maternity hub FAQs (PHP-based, not in JSON)
	if ( empty( $catalog['post-maternity'] ) ) {
		$clinics = function_exists( 'nvx_get_clinics_config' ) ? nvx_get_clinics_config() : array();
		$chamberi_reg = (string) ( $clinics['chamberi']['reg'] ?? '' );
		$goya_reg = (string) ( $clinics['goya']['reg'] ?? '' );
		$chamberi_name = (string) ( $clinics['chamberi']['short_name'] ?? '' );
		$goya_name = (string) ( $clinics['goya']['short_name'] ?? '' );
		$faq_where = 'Valoración en ' . $chamberi_name . ' (' . $chamberi_reg . ') y ' . $goya_name . ' (' . $goya_reg . '), con plan documentado si procede.';

		$catalog['post-maternity'] = array(
			array(
				'q' => '¿Puedo tratarme en lactancia?',
				'a' => 'Solo tras valoración individual. En muchos casos se espera o se limita el plan; no hay calendario mágico "a los X meses" igual para todas.',
			),
			array(
				'q' => '¿Corrige la diástasis de rectos?',
				'a' => 'La diástasis se evalúa antes de indicar. Un protocolo de contorno no sustituye la reparación quirúrgica cuando esta es la vía adecuada.',
			),
			array(
				'q' => '¿Es una abdominoplastia sin cirugía?',
				'a' => 'No. No se promete el resultado de una cirugía de contorno. El objetivo es mejorar grasa y/o calidad tisular si hay indicación y el momento es seguro.',
			),
			array(
				'q' => '¿Cuándo tiene sentido valorar?',
				'a' => 'Cuando hay queja localizada (abdomen, flancos, calidad de piel), expectativas realistas y condiciones clínicas que permitan un plan seguro.',
			),
			array(
				'q' => '¿Dónde?',
				'a' => $faq_where,
			),
		);
	}
	// Alias for routes.json schema_id 'postpartum' → same FAQs as 'post-maternity'.
	if ( ! empty( $catalog['post-maternity'] ) && empty( $catalog['postpartum'] ) ) {
		$catalog['postpartum'] = $catalog['post-maternity'];
	}

	$catalogs[ $locale ] = $catalog;
	return $catalog;
}

/**
 * Determine whether a page renders visible FAQ content to the user.
 *
 * Google Search Essentials and Schema.org guidelines mandate that FAQPage
 * structured data must mirror visible on-page FAQs. Emitting FAQPage on pages
 * without visible FAQ content (such as the front page or non-treatment pages)
 * constitutes structured data spam.
 *
 * @param int|null $page_id Optional page ID. Defaults to current queried object ID.
 * @return bool True if the page has visible FAQ content, false otherwise.
 */
function nvx_schema_page_has_visible_faq( ?int $page_id = null ): bool {
	if ( is_front_page() ) {
		return false;
	}

	$target_id = null !== $page_id ? $page_id : (int) get_queried_object_id();

	$key = nvx_schema_resolve_treatment_key( $target_id );
	if ( null === $key && function_exists( 'nvx_btl_detail_current_key' ) ) {
		$key = nvx_btl_detail_current_key( '' );
	}
	if ( null === $key && function_exists( 'nvx_signature_phase_current_faq_key' ) ) {
		$key = nvx_signature_phase_current_faq_key();
	}

	if ( null === $key ) {
		return false;
	}

	$catalog = nvx_schema_faq_catalog();
	return ! empty( $catalog[ $key ] ) && is_array( $catalog[ $key ] );
}

/**
 * Return an FAQPage node that exactly mirrors visible page content.
 *
 * Emits null on any page without visible FAQ content (including the front page).
 *
 * @param int $page_id Current page ID.
 * @return array|null
 */
function nvx_schema_faq_node( $page_id ) {
	if ( ! nvx_schema_page_has_visible_faq( $page_id ) ) {
		return null;
	}

	$key = nvx_schema_resolve_treatment_key( $page_id );
	if ( null === $key && function_exists( 'nvx_btl_detail_current_key' ) ) {
		$key = nvx_btl_detail_current_key( '' );
	}
	if ( null === $key && function_exists( 'nvx_signature_phase_current_faq_key' ) ) {
		$key = nvx_signature_phase_current_faq_key();
	}

	if ( null === $key ) {
		return null;
	}

	$catalog = nvx_schema_faq_catalog();
	if ( empty( $catalog[ $key ] ) ) {
		return null;
	}

	$questions = $catalog[ $key ];
	$faq_id    = get_permalink( $page_id ) . '#faq';
	$faq_url   = get_permalink( $page_id );

	$entities = array();

	foreach ( $questions as $q ) {
		if ( ! empty( $q['q'] ) && ! empty( $q['a'] ) ) {
			$entities[] = array(
				'@type'          => 'Question',
				'name'           => $q['q'],
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $q['a'],
				),
			);
		}
	}

	if ( empty( $entities ) ) {
		return null;
	}

	return array(
		'@type'      => 'FAQPage',
		'@id'        => $faq_id,
		'url'        => $faq_url,
		'mainEntity' => $entities,
	);
}

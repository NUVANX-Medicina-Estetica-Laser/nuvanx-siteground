<?php
/**
 * Yoast graph extensions for the four canonical facial treatment pages.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Replace a graph node by @id or append it. */
function nvx_aesthetic_schema_upsert_node( array $graph, array $node ): array {
	$id = isset( $node['@id'] ) ? (string) $node['@id'] : '';
	if ( '' !== $id ) {
		foreach ( $graph as $index => $piece ) {
			if ( isset( $piece['@id'] ) && $id === (string) $piece['@id'] ) {
				$graph[ $index ] = $node;
				return $graph;
			}
		}
	}
	$graph[] = $node;
	return $graph;
}

/**
 * Map string names to Schema.org typed nodes.
 *
 * @param array<int,string> $names Display names.
 * @return array<int,array{@type:string,name:string}>
 */
function nvx_aesthetic_schema_named_nodes( array $names, string $type ): array {
	$nodes = array();
	foreach ( $names as $name ) {
		$nodes[] = array(
			'@type' => $type,
			'name'  => $name,
		);
	}
	return $nodes;
}

/**
 * Extract explicit EUR amounts from a public price string.
 *
 * Only numbers directly associated with € / EUR are accepted. This prevents
 * session counts, durations or dosage numbers in the same copy from becoming
 * an accidental schema.org Offer price.
 *
 * Spanish thousands/decimal notation is normalized (1.064,80 € -> 1064.80).
 *
 * @return float[]
 */
function nvx_aesthetic_schema_euro_amounts( string $price_text ): array {
	if ( '' === trim( $price_text ) ) {
		return array();
	}

	$pattern = '/(?<![\d.,])(\d+(?:\.\d{3})*(?:,\d{1,2})?|\d+(?:,\d{1,2})?)\s*(?:€|EUR)/iu';
	if ( ! preg_match_all( $pattern, $price_text, $matches ) || empty( $matches[1] ) ) {
		return array();
	}

	$prices = array();
	foreach ( $matches[1] as $match ) {
		$raw = (string) $match;
		if ( false !== strpos( $raw, ',' ) ) {
			$normalized = str_replace( '.', '', $raw );
			$normalized = str_replace( ',', '.', $normalized );
		} elseif ( 1 === preg_match( '/^\d{1,3}(?:\.\d{3})+$/D', $raw ) ) {
			$normalized = str_replace( '.', '', $raw );
		} else {
			$normalized = $raw;
		}

		$amount = (float) $normalized;
		if ( $amount > 0 ) {
			$prices[] = $amount;
		}
	}

	return $prices;
}

/**
 * Build the MedicalProcedure/Service node for a facial treatment page.
 *
 * @param array<string,mixed> $schema Schema catalog entry.
 * @param array<string,mixed> $entry  Treatment catalog entry.
 */
function nvx_aesthetic_schema_procedure_node(
	array $schema,
	array $entry,
	string $permalink,
	string $organization_id
): array {
	$node = array(
		'@type'             => array( 'MedicalProcedure', 'Service' ),
		'@id'               => $permalink . '#medical-procedure',
		'name'              => $schema['name'],
		'alternateName'     => $schema['alternateName'],
		'url'               => $permalink,
		'mainEntityOfPage'  => array( '@id' => $permalink ),
		'provider'          => array( '@id' => $organization_id ),
		'description'       => $entry['description'],
		'bodyLocation'      => $schema['bodyLocation'],
		'procedureType'     => $schema['procedureType'],
		'preparation'       => $schema['preparation'],
		'howPerformed'      => $schema['howPerformed'],
		'followup'          => $schema['followup'],
		'indication'        => nvx_aesthetic_schema_named_nodes( (array) $schema['indications'], 'MedicalIndication' ),
		'relevantCondition' => nvx_aesthetic_schema_named_nodes( (array) $schema['conditions'], 'MedicalCondition' ),
		'areaServed'        => array( 'Madrid', 'Chamberí', 'Barrio de Salamanca', 'Goya' ),
	);

	// Note: reviewedBy removed - belongs to WebPage only, not MedicalProcedure/Service
	// reviewedBy is now managed solely by nvx-medical-review.php for WebPage nodes
	// Note: performer property removed - belongs to Event, not MedicalProcedure/Service
	// MedicalProcedure uses provider relationship instead

	if ( ! empty( $entry['price_range'] ) ) {
		$price_text = (string) $entry['price_range'];
		$prices     = nvx_aesthetic_schema_euro_amounts( $price_text );

		if ( array() !== $prices ) {
			$numeric_price = min( $prices );
			$high_price    = max( $prices );

			$offer = array(
				'@type'         => 'Offer',
				'price'         => function_exists( 'nvx_schema_price_string' ) ? nvx_schema_price_string( $numeric_price ) : (string) $numeric_price,
				'priceCurrency' => 'EUR',
				'availability'  => 'https://schema.org/InStock',
				'description'   => $price_text,
			);

			if ( $high_price > $numeric_price ) {
				$offer['priceSpecification'] = array(
					'@type'         => 'PriceSpecification',
					'price'         => function_exists( 'nvx_schema_price_string' ) ? nvx_schema_price_string( $numeric_price ) : (string) $numeric_price,
					'priceCurrency' => 'EUR',
					'minPrice'      => function_exists( 'nvx_schema_price_string' ) ? nvx_schema_price_string( $numeric_price ) : (string) $numeric_price,
					'maxPrice'      => function_exists( 'nvx_schema_price_string' ) ? nvx_schema_price_string( $high_price ) : (string) $high_price,
				);
			} elseif ( false !== stripos( $price_text, 'desde' ) ) {
				$offer['priceSpecification'] = array(
					'@type'         => 'PriceSpecification',
					'price'         => function_exists( 'nvx_schema_price_string' ) ? nvx_schema_price_string( $numeric_price ) : (string) $numeric_price,
					'priceCurrency' => 'EUR',
					'minPrice'      => function_exists( 'nvx_schema_price_string' ) ? nvx_schema_price_string( $numeric_price ) : (string) $numeric_price,
				);
			}

			$node['offers'] = $offer;
		}
	}

	return $node;
}

/**
 * Point the matching WebPage node at the MedicalProcedure entity.
 *
 * @param array<int,array<string,mixed>> $graph Yoast graph.
 * @return array<int,array<string,mixed>>
 */
function nvx_aesthetic_schema_link_webpage_main_entity( array $graph, string $permalink, string $procedure_id ): array {
	foreach ( $graph as $index => $piece ) {
		$types = isset( $piece['@type'] ) ? (array) $piece['@type'] : array();
		if ( ! in_array( 'WebPage', $types, true ) || ! isset( $piece['url'] ) ) {
			continue;
		}
		if ( trailingslashit( $piece['url'] ) !== trailingslashit( $permalink ) ) {
			continue;
		}
		$graph[ $index ]['mainEntity'] = array( '@id' => $procedure_id );
		break;
	}
	return $graph;
}

/** Add MedicalProcedure/Service and FAQPage to the existing Yoast block. */
function nvx_aesthetic_treatment_extend_yoast_graph( $graph, $context = null ) {
	unset( $context );
	if ( ! is_array( $graph ) || ! function_exists( 'nvx_aesthetic_treatment_current_key' ) ) {
		return $graph;
	}

	$key = nvx_aesthetic_treatment_current_key();
	if ( null === $key ) {
		return $graph;
	}

	$catalog        = nvx_aesthetic_treatment_catalog();
	$schema_catalog = nvx_aesthetic_treatment_schema_catalog();
	if ( empty( $catalog[ $key ] ) || empty( $schema_catalog[ $key ] ) ) {
		return $graph;
	}

	$permalink       = get_permalink( get_queried_object_id() );
	$organization    = function_exists( 'nvx_schema_find_organization' )
		? nvx_schema_find_organization( $graph )
		: array( 'id' => function_exists( 'nvx_schema_organization_id' ) ? nvx_schema_organization_id() : home_url( '/#organization' ) );
	$organization_id = ! empty( $organization['id'] ) ? $organization['id'] : ( function_exists( 'nvx_schema_organization_id' ) ? nvx_schema_organization_id() : home_url( '/#organization' ) );
	$procedure       = nvx_aesthetic_schema_procedure_node(
		$schema_catalog[ $key ],
		$catalog[ $key ],
		$permalink,
		$organization_id
	);
	$graph           = nvx_aesthetic_schema_upsert_node( $graph, $procedure );

	$graph = nvx_aesthetic_schema_link_webpage_main_entity( $graph, $permalink, $procedure['@id'] );

	return array_values( $graph );
}
add_filter( 'wpseo_schema_graph', 'nvx_aesthetic_treatment_extend_yoast_graph', 55, 2 );

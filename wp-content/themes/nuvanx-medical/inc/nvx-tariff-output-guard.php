<?php
/**
 * Final public tariff-output guard.
 *
 * Canonical catalog hydration owns price truth. This late guard prevents any
 * legacy renderer or schema helper from reintroducing numeric fallback prices
 * when tariff-catalog.json is unavailable or incomplete.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Canonical tariff requirements for public price-bearing surfaces. */
function nvx_tariff_public_requirements(): array {
	return array(
		array( 'Endolift®', 'ojeras' ),
		array( 'Endolift®', 'papada' ),
		array( 'Endolift®', 'marcacion_mandibular' ),
		array( 'Endolift®', 'cuello' ),
		array( 'Endolift®', 'rodillas' ),
		array( 'Endolift®', 'brazos' ),
		array( 'Endolift®', 'muslos_internos' ),
		array( 'Endolift®', 'flancos' ),
		array( 'Endolift®', 'abdomen' ),
		array( 'endolift_combo', 'papada_cuello' ),
		array( 'endolift_combo', 'full_face' ),
		array( 'laser_co2', 'facial' ),
		array( 'laser_co2', 'corporal' ),
		array( 'exion', 'exion_face_sesion' ),
		array( 'exion', 'exion_body_sesion' ),
		array( 'exion', 'exion_fractional_cara' ),
		array( 'labios_ha', 'hidratacion' ),
		array( 'rinomodelacion_ha', 'rinomodelacion' ),
		array( 'ojeras_ha', 'surco_lagrimal' ),
		array( 'bioestimuladores', 'polynucleotides_cara' ),
	);
}

/** Whether canonical tariff truth is complete enough for public price output. */
function nvx_tariff_public_truth_is_complete(): bool {
	if ( ! function_exists( 'nvx_catalog_json_load' ) || ! function_exists( 'nvx_catalog_tariffs_complete' ) ) {
		return false;
	}

	$tariffs = nvx_catalog_json_load( 'tariff-catalog.json' );
	return nvx_catalog_tariffs_complete( $tariffs, nvx_tariff_public_requirements() );
}

/** Neutral copy shared by fail-closed output surfaces. */
function nvx_tariff_public_neutral_copy(): string {
	if ( function_exists( 'nvx_catalog_price_unavailable_copy' ) ) {
		return nvx_catalog_price_unavailable_copy();
	}

	return __( 'Presupuesto individualizado tras valoración médica. Consulta la tarifa vigente con el equipo antes de confirmar el tratamiento.', 'nuvanx-medical' );
}

/**
 * Sanitize Endolift public HTML after legacy renderer execution.
 *
 * The catalog renderer already fails closed. This final fence only changes
 * content if another later/parallel renderer has reintroduced a numeric price.
 */
function nvx_tariff_sanitize_endolift_content( string $content ): string {
	$neutral = esc_html( nvx_tariff_public_neutral_copy() );

	$content = (string) preg_replace_callback(
		'#(<section\b[^>]*class="[^"]*\bnvx-endolift-investment\b[^"]*"[^>]*>[\s\S]*?<p\b[^>]*class="[^"]*\bnvx-body\b[^"]*"[^>]*>)[\s\S]*?(</p>)#iu',
		static function ( array $matches ) use ( $neutral ): string {
			return $matches[1] . $neutral . $matches[2];
		},
		$content,
		1
	);

	$content = (string) preg_replace_callback(
		'#(<section\b[^>]*class="[^"]*\bnvx-endolift-faq\b[^"]*"[^>]*>[\s\S]*?</section>)#iu',
		static function ( array $matches ) use ( $neutral ): string {
			return (string) preg_replace_callback(
				'#<p\b([^>]*)>([\s\S]*?)</p>#iu',
				static function ( array $paragraph ) use ( $neutral ): string {
					$text = html_entity_decode( wp_strip_all_tags( $paragraph[2] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
					if ( false === strpos( $text, '€' ) ) {
						return $paragraph[0];
					}
					return '<p' . $paragraph[1] . '>' . $neutral . '</p>';
				},
				$matches[1]
			);
		},
		$content,
		1
	);

	return $content;
}

/** Final visible-content fail-closed fence. */
function nvx_tariff_guard_public_content( $content ) {
	if ( ! is_string( $content ) || nvx_tariff_public_truth_is_complete() ) {
		return $content;
	}

	return nvx_tariff_sanitize_endolift_content( $content );
}
add_filter( 'the_content', 'nvx_tariff_guard_public_content', NVX_HOOK_PRIO_TARIFF_OUTPUT_GUARD );

/** Whether a schema type list contains a type. */
function nvx_tariff_schema_has_type( array $node, string $type ): bool {
	$types = isset( $node['@type'] ) ? (array) $node['@type'] : array();
	return in_array( $type, $types, true );
}

/** Remove price-bearing schema values while preserving clinical description. */
function nvx_tariff_sanitize_schema_node( array $node ): array {
	unset(
		$node['price'],
		$node['priceCurrency'],
		$node['lowPrice'],
		$node['highPrice'],
		$node['minPrice'],
		$node['maxPrice']
	);

	if ( isset( $node['description'] ) && is_string( $node['description'] ) ) {
		$description = $node['description'];
		if ( nvx_tariff_schema_has_type( $node, 'Offer' ) && ( false !== strpos( $description, '€' ) || false !== stripos( $description, 'tarifa' ) ) ) {
			unset( $node['description'] );
		} elseif ( false !== strpos( $description, '€' ) || false !== stripos( $description, 'PVP' ) ) {
			$description = (string) preg_replace( '/\s+PVP\b[\s\S]*$/u', '', $description );
			$description = (string) preg_replace( '/\s+Tarifa(?:s)?\s+de\s+referencia\b[\s\S]*$/iu', '', $description );
			$node['description'] = trim( $description );
		}
	}

	if ( isset( $node['acceptedAnswer'] ) && is_array( $node['acceptedAnswer'] ) ) {
		$answer = $node['acceptedAnswer'];
		if ( isset( $answer['text'] ) && is_string( $answer['text'] ) && false !== strpos( $answer['text'], '€' ) ) {
			$answer['text'] = nvx_tariff_public_neutral_copy();
		}
		$node['acceptedAnswer'] = $answer;
	}

	// Recurse into the nested array itself, not only its children. This is
	// required for associative schema objects such as MedicalProcedure.offers
	// and priceSpecification, while also traversing numeric lists on PHP 8.0.
	foreach ( $node as $key => $value ) {
		if ( is_array( $value ) ) {
			$node[ $key ] = nvx_tariff_sanitize_schema_node( $value );
		}
	}

	return $node;
}

/** Pure schema sanitizer used by runtime and blocking tests. */
function nvx_tariff_sanitize_schema_graph( array $graph ): array {
	return nvx_tariff_sanitize_schema_node( $graph );
}

/** Final schema fail-closed fence after all price-producing owners. */
function nvx_tariff_guard_schema_graph( $graph ) {
	if ( ! is_array( $graph ) || nvx_tariff_public_truth_is_complete() ) {
		return $graph;
	}

	return nvx_tariff_sanitize_schema_graph( $graph );
}
add_filter( 'wpseo_schema_graph', 'nvx_tariff_guard_schema_graph', 900 );

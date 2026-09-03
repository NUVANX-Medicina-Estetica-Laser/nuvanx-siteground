<?php
/**
 * Nested schema tariff fail-closed regression contract.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

function add_filter( ...$args ): bool {
	unset( $args );
	return true;
}

function __( string $text, string $domain = '' ): string {
	unset( $domain );
	return $text;
}

function esc_html( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
}

function wp_strip_all_tags( string $text ): string {
	return strip_tags( $text );
}

function nvx_tariff_nested_assert( bool $condition, string $name ): void {
	if ( ! $condition ) {
		fwrite( STDERR, 'TARIFF_SCHEMA_NESTED=FAIL invariant=' . $name . PHP_EOL );
		exit( 1 );
	}
}

$root = dirname( __DIR__, 2 );
require_once $root . '/wp-content/themes/nuvanx-medical/inc/nvx-constants.php';
require_once $root . '/wp-content/themes/nuvanx-medical/inc/nvx-tariff-output-guard.php';

$graph = array(
	array(
		'@type'       => array( 'MedicalProcedure', 'Service' ),
		'name'        => 'Tratamiento de prueba',
		'description' => 'Descripción clínica conservada.',
		'offers'      => array(
			'@type'              => 'Offer',
			'price'              => '798.60',
			'priceCurrency'      => 'EUR',
			'description'        => 'Tarifa de referencia desde 798,60 €.',
			'priceSpecification' => array(
				'@type'         => 'UnitPriceSpecification',
				'price'         => '798.60',
				'priceCurrency' => 'EUR',
				'minPrice'      => '700.00',
				'maxPrice'      => '900.00',
			),
		),
		'itemListElement' => array(
			array(
				'@type'         => 'Offer',
				'price'         => '350.00',
				'priceCurrency' => 'EUR',
			),
			array(
				'@type'      => 'AggregateOffer',
				'lowPrice'   => '350.00',
				'highPrice'  => '450.00',
				'offerCount' => 2,
			),
		),
	),
);

$clean = nvx_tariff_sanitize_schema_graph( $graph );
$json  = wp_json_encode( $clean, JSON_UNESCAPED_UNICODE );

nvx_tariff_nested_assert( is_string( $json ), 'GRAPH_ENCODES' );
nvx_tariff_nested_assert( false === strpos( $json, '798.60' ), 'ASSOCIATIVE_OFFER_PRICE_REMOVED' );
nvx_tariff_nested_assert( false === strpos( $json, '700.00' ), 'PRICE_SPEC_MIN_REMOVED' );
nvx_tariff_nested_assert( false === strpos( $json, '900.00' ), 'PRICE_SPEC_MAX_REMOVED' );
nvx_tariff_nested_assert( false === strpos( $json, '350.00' ), 'LIST_OFFER_LOW_PRICE_REMOVED' );
nvx_tariff_nested_assert( false === strpos( $json, '450.00' ), 'LIST_OFFER_HIGH_PRICE_REMOVED' );
nvx_tariff_nested_assert( false === strpos( $json, 'priceCurrency' ), 'ALL_PRICE_CURRENCY_REMOVED' );
nvx_tariff_nested_assert( false === strpos( $json, '€' ), 'PRICE_BEARING_OFFER_DESCRIPTION_REMOVED' );
nvx_tariff_nested_assert( 'Descripción clínica conservada.' === ( $clean[0]['description'] ?? '' ), 'CLINICAL_DESCRIPTION_PRESERVED' );
nvx_tariff_nested_assert( 2 === ( $clean[0]['itemListElement'][1]['offerCount'] ?? 0 ), 'NON_PRICE_AGGREGATE_METADATA_PRESERVED' );

echo 'TARIFF_SCHEMA_NESTED=PASS associative_offer=sanitized price_specification=sanitized lists=sanitized clinical_copy=preserved' . PHP_EOL;

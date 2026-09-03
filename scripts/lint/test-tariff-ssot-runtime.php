<?php
/**
 * Blocking tariff SSOT contract.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

function __( string $text, string $domain = '' ): string { unset( $domain ); return $text; }
function home_url( string $path = '' ): string { return 'https://nuvanx.test' . $path; }
function add_action( ...$args ): bool { unset( $args ); return true; }
function add_filter( ...$args ): bool { unset( $args ); return true; }
function remove_filter( ...$args ): bool { unset( $args ); return true; }
function determine_locale(): string { return 'es_ES'; }
function esc_html( string $text ): string { return htmlspecialchars( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ); }
function wp_strip_all_tags( string $text ): string { return strip_tags( $text ); }

function nvx_tariff_ssot_assert( bool $condition, string $name ): void {
	if ( ! $condition ) {
		fwrite( STDERR, 'TARIFF_SSOT_RUNTIME=FAIL invariant=' . $name . PHP_EOL );
		exit( 1 );
	}
}

$root              = dirname( __DIR__, 2 );
$catalog_source    = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-catalog-json.php' );
$guard_source      = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-tariff-output-guard.php' );
$structured_source = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-structured-data.php' );
$constants_source  = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-constants.php' );

require_once $root . '/wp-content/themes/nuvanx-medical/inc/nvx-constants.php';
require_once $root . '/wp-content/themes/nuvanx-medical/inc/nvx-catalog-json.php';
require_once $root . '/wp-content/themes/nuvanx-medical/inc/nvx-tariff-output-guard.php';

$tariffs = nvx_catalog_json_load( 'tariff-catalog.json' );
nvx_tariff_ssot_assert( empty( $tariffs['_error'] ), 'CANONICAL_TARIFF_LOADS' );
nvx_tariff_ssot_assert( 290.0 === nvx_catalog_get_tariff_pvp( $tariffs, 'labios_ha', 'hidratacion' ), 'LIPS_CANONICAL_KEY' );
nvx_tariff_ssot_assert( null === nvx_catalog_get_tariff_pvp( $tariffs, 'labios_ha', 'perfilado_hidratacion' ), 'LEGACY_COMPOSITE_KEY_ABSENT' );
nvx_tariff_ssot_assert( 348.0 === nvx_catalog_get_tariff_pvp( $tariffs, 'bioestimuladores', 'polynucleotides_cara' ), 'BIO_CANONICAL_KEY' );
nvx_tariff_ssot_assert( null !== nvx_catalog_get_tariff_pvp( $tariffs, 'Endolift®', 'marcacion_mandibular' ), 'MANDIBULAR_HAS_OWN_TARIFF_KEY' );
nvx_tariff_ssot_assert( null !== nvx_catalog_get_tariff_pvp( $tariffs, 'Endolift®', 'muslos_internos' ), 'INNER_THIGH_HAS_OWN_TARIFF_KEY' );

$neutral_copy = nvx_catalog_price_unavailable_copy();
$catalog      = nvx_catalog_json_resolved( 'aesthetic-medicine-page.json' );
$by_n         = array();
foreach ( $catalog['treatments'] ?? array() as $treatment ) {
	if ( is_array( $treatment ) && isset( $treatment['n'] ) ) {
		$by_n[ (string) $treatment['n'] ] = $treatment;
	}
}

nvx_tariff_ssot_assert( isset( $by_n['01'], $by_n['04'], $by_n['05'] ), 'AESTHETIC_IDENTITIES_PRESENT' );
nvx_tariff_ssot_assert( 'Desde 290 €' === ( $by_n['01']['price'] ?? '' ), 'LIPS_HYDRATED_FROM_CANONICAL_TARIFF' );
nvx_tariff_ssot_assert( 'Desde 348 € (según principio activo y zona)' === ( $by_n['05']['price'] ?? '' ), 'BIO_HYDRATED_ON_TREATMENT_05' );
nvx_tariff_ssot_assert( $neutral_copy === ( $by_n['04']['price'] ?? '' ), 'NON_CANONICAL_CARD_FAILS_CLOSED' );

$shuffled = array(
	'treatments' => array(
		array( 'n' => '05', 'price' => 'legacy 999 €' ),
		array( 'n' => '01', 'price' => 'legacy 999 €' ),
		array( 'n' => '04', 'price' => 'legacy 999 €' ),
		array( 'n' => '03', 'price' => 'legacy 999 €' ),
		array( 'n' => '02', 'price' => 'legacy 999 €' ),
	),
);
$shuffled = nvx_catalog_apply_tariff_truth( $shuffled, 'aesthetic-medicine-page.json' );
$shuffled_by_n = array();
foreach ( $shuffled['treatments'] as $treatment ) {
	$shuffled_by_n[ $treatment['n'] ] = $treatment['price'];
}
nvx_tariff_ssot_assert( 'Desde 348 € (según principio activo y zona)' === $shuffled_by_n['05'], 'BIO_REORDER_SAFE' );
nvx_tariff_ssot_assert( $neutral_copy === $shuffled_by_n['04'], 'NON_CANONICAL_CARD_REORDER_FAIL_CLOSED' );

$endolift_fixture = array(
	'investment' => array( 'body' => 'legacy 999 €' ),
	'faq'        => array(
		'items' => array(
			array( 'q' => '¿Duele?', 'a' => 'Respuesta clínica sin precio.' ),
			array( 'q' => '¿Cuánto cuesta el Endolift® facial en NUVANX Madrid?', 'a' => 'legacy 999 €' ),
		),
	),
);
$endolift_hydrated = nvx_catalog_apply_tariff_truth( $endolift_fixture, 'endolift-page.json' );
nvx_tariff_ssot_assert( 'Respuesta clínica sin precio.' === $endolift_hydrated['faq']['items'][0]['a'], 'FAQ_NON_PRICE_ANSWER_UNTOUCHED' );
nvx_tariff_ssot_assert( false === strpos( $endolift_hydrated['faq']['items'][1]['a'], '999' ), 'FAQ_PRICING_IDENTITY_HYDRATED' );
nvx_tariff_ssot_assert( false !== strpos( $endolift_hydrated['faq']['items'][1]['a'], 'Papada:' ), 'FAQ_PRICING_IDENTITY_CANONICAL_COPY' );

$endolift_reordered = $endolift_fixture;
$endolift_reordered['faq']['items'] = array_reverse( $endolift_reordered['faq']['items'] );
$endolift_reordered = nvx_catalog_apply_tariff_truth( $endolift_reordered, 'endolift-page.json' );
nvx_tariff_ssot_assert( false === strpos( $endolift_reordered['faq']['items'][0]['a'], '999' ), 'FAQ_REORDER_SAFE' );
nvx_tariff_ssot_assert( 'Respuesta clínica sin precio.' === $endolift_reordered['faq']['items'][1]['a'], 'FAQ_REORDER_NON_PRICE_UNTOUCHED' );

$duplicate_pricing = array(
	'investment' => array( 'body' => 'legacy 999 €' ),
	'faq'        => array(
		'items' => array(
			array( 'q' => '¿Cuánto cuesta el Endolift®?', 'a' => 'legacy 999 €' ),
			array( 'q' => 'Precio del Endolift®', 'a' => 'legacy 888 €' ),
		),
	),
);
$duplicate_pricing = nvx_catalog_apply_tariff_truth( $duplicate_pricing, 'endolift-page.json' );
nvx_tariff_ssot_assert( $neutral_copy === $duplicate_pricing['investment']['body'], 'FAQ_DUPLICATE_BLOCK_FAILS_CLOSED' );
nvx_tariff_ssot_assert( $neutral_copy === $duplicate_pricing['faq']['items'][0]['a'], 'FAQ_DUPLICATE_FIRST_NEUTRAL' );
nvx_tariff_ssot_assert( $neutral_copy === $duplicate_pricing['faq']['items'][1]['a'], 'FAQ_DUPLICATE_SECOND_NEUTRAL' );

$malformed_cases = array(
	array(
		'name'    => 'ENDOLIFT_MALFORMED_FAQ',
		'file'    => 'endolift-page.json',
		'block'   => 'investment',
		'catalog' => array( 'investment' => array( 'body' => 'Desde 999 €' ), 'faq' => 'malformed' ),
	),
	array(
		'name'    => 'EXION_MALFORMED_FAQ',
		'file'    => 'exion-page.json',
		'block'   => 'investment',
		'catalog' => array( 'investment' => array( 'body' => 'Desde 999 €' ), 'faq' => array( 'items' => 'malformed' ) ),
	),
	array(
		'name'    => 'ENDOLASER_MALFORMED_FAQ',
		'file'    => 'endolaser-page.json',
		'block'   => 'planning',
		'catalog' => array( 'planning' => array( 'body' => 'Desde 999 €' ), 'faq' => array( 'items' => array() ) ),
	),
	array(
		'name'    => 'CO2_MALFORMED_FAQ',
		'file'    => 'laser-co2-page.json',
		'block'   => 'investment',
		'catalog' => array( 'investment' => array( 'body' => 'Desde 999 €' ), 'faq' => array( 'items' => array( array( 'q' => 'Precio sin respuesta' ) ) ) ),
	),
);
foreach ( $malformed_cases as $case ) {
	$result = nvx_catalog_apply_tariff_truth( $case['catalog'], $case['file'] );
	nvx_tariff_ssot_assert( isset( $result[ $case['block'] ] ) && is_array( $result[ $case['block'] ] ), $case['name'] . '_BLOCK_PRESERVED' );
	nvx_tariff_ssot_assert( $neutral_copy === ( $result[ $case['block'] ]['body'] ?? '' ), $case['name'] . '_PRICE_NEUTRALIZED' );
	nvx_tariff_ssot_assert( false === strpos( (string) ( $result[ $case['block'] ]['body'] ?? '' ), '999' ), $case['name'] . '_NO_STALE_NUMERIC_PRICE' );
}

$legacy_html = '<section class="nvx-brand-section nvx-endolift-investment"><div><h2>Presupuesto</h2><p class="nvx-body nvx-body--measure">Tarifa 999 €</p><ul><li>Incluye control</li></ul></div></section>'
	. '<section class="nvx-brand-section nvx-endolift-faq"><div><details><p>Desde 999 €</p></details><details><p>Respuesta clínica</p></details></div></section>';
$guarded_html = nvx_tariff_sanitize_endolift_content( $legacy_html );
nvx_tariff_ssot_assert( false === strpos( $guarded_html, '999 €' ), 'HTML_OUTAGE_REMOVES_RECONSTRUCTED_PRICE' );
nvx_tariff_ssot_assert( false !== strpos( $guarded_html, 'Respuesta clínica' ), 'HTML_OUTAGE_PRESERVES_NON_PRICE_FAQ' );

$legacy_graph = array(
	array(
		'@type'       => array( 'MedicalProcedure', 'Service' ),
		'description' => 'Descripción clínica conservada. PVP papada desde 1.064,80 €; tarifa facial desde 798,60 €.',
	),
	array(
		'@type'         => 'Offer',
		'price'         => '798.60',
		'priceCurrency' => 'EUR',
		'description'   => 'Tarifa de referencia desde 798,60 € (presupuesto tras valoración).',
	),
	array(
		'@type'          => 'Question',
		'acceptedAnswer' => array( '@type' => 'Answer', 'text' => 'Desde 798,60 €.' ),
	),
);
$guarded_graph = nvx_tariff_sanitize_schema_graph( $legacy_graph );
$guarded_json  = json_encode( $guarded_graph, JSON_UNESCAPED_UNICODE );
nvx_tariff_ssot_assert( is_string( $guarded_json ) && false === strpos( $guarded_json, '798.60' ), 'SCHEMA_OUTAGE_REMOVES_PRICE_PROPERTY' );
nvx_tariff_ssot_assert( is_string( $guarded_json ) && false === strpos( $guarded_json, '€' ), 'SCHEMA_OUTAGE_REMOVES_NUMERIC_PRICE_PROSE' );
nvx_tariff_ssot_assert( 'Descripción clínica conservada.' === $guarded_graph[0]['description'], 'SCHEMA_OUTAGE_PRESERVES_CLINICAL_DESCRIPTION' );
nvx_tariff_ssot_assert( $neutral_copy === $guarded_graph[2]['acceptedAnswer']['text'], 'SCHEMA_OUTAGE_NEUTRALIZES_FAQ_PRICE' );

nvx_tariff_ssot_assert( false === strpos( $catalog_source, "'perfilado_hidratacion'" ), 'LEGACY_TARIFF_LOOKUP_REMOVED' );
nvx_tariff_ssot_assert( false === strpos( $catalog_source, "['treatments'][3]" ), 'POSITIONAL_BIO_OWNER_REMOVED' );
nvx_tariff_ssot_assert( false === strpos( $catalog_source, 'price_faq_index' ), 'FAQ_POSITIONAL_OWNER_REMOVED' );
nvx_tariff_ssot_assert( false !== strpos( $catalog_source, 'nvx_catalog_faq_index_by_id' ), 'FAQ_STABLE_IDENTITY_RESOLVER_PRESENT' );
nvx_tariff_ssot_assert( false !== strpos( $catalog_source, "'marcacion_mandibular'" ), 'MANDIBULAR_CANONICAL_LOOKUP_PRESENT' );
nvx_tariff_ssot_assert( false !== strpos( $catalog_source, "'muslos_internos'" ), 'INNER_THIGH_CANONICAL_LOOKUP_PRESENT' );
nvx_tariff_ssot_assert( false !== strpos( $catalog_source, 'nvx_catalog_price_destination_valid' ), 'NESTED_DESTINATION_GUARD_PRESENT' );
nvx_tariff_ssot_assert( false !== strpos( $guard_source, "add_filter( 'the_content', 'nvx_tariff_guard_public_content', NVX_HOOK_PRIO_TARIFF_OUTPUT_GUARD )" ), 'VISIBLE_OUTPUT_FINAL_FENCE_REGISTERED' );
nvx_tariff_ssot_assert( false !== strpos( $constants_source, 'NVX_HOOK_PRIO_TARIFF_OUTPUT_GUARD = 221' ), 'VISIBLE_OUTPUT_PRIORITY_REGISTERED' );
nvx_tariff_ssot_assert( false !== strpos( $guard_source, "add_filter( 'wpseo_schema_graph', 'nvx_tariff_guard_schema_graph', 900 )" ), 'SCHEMA_OUTPUT_FINAL_FENCE_REGISTERED' );
nvx_tariff_ssot_assert( false !== strpos( $structured_source, "require_once __DIR__ . '/nvx-tariff-output-guard.php';" ), 'SCHEMA_BOOTSTRAP_LOADS_TARIFF_FENCE' );
nvx_tariff_ssot_assert( false !== strpos( $catalog_source, "error_log( 'NUVANX catalog: ' . \$message )" ), 'PRODUCTION_INTEGRITY_LOGGING_PRESENT' );

echo 'TARIFF_SSOT_RUNTIME=PASS source=tariff-catalog faq=semantic-identity reorder=pass duplicate=fail-closed anatomy=independent output=html+schema-fenced aesthetic=reorder-safe hook=registered' . PHP_EOL;

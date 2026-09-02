<?php
/**
 * Blocking tariff SSOT contract.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'WP_DEBUG' ) ) {
	define( 'WP_DEBUG', false );
}

function __( string $text, string $domain = '' ): string { unset( $domain ); return $text; }
function home_url( string $path = '' ): string { return 'https://nuvanx.test' . $path; }
function add_action( ...$args ): bool { unset( $args ); return true; }
function remove_filter( ...$args ): bool { unset( $args ); return true; }
function determine_locale(): string { return 'es_ES'; }

function nvx_tariff_ssot_assert( bool $condition, string $name ): void {
	if ( ! $condition ) {
		fwrite( STDERR, 'TARIFF_SSOT_RUNTIME=FAIL invariant=' . $name . PHP_EOL );
		exit( 1 );
	}
}

$root   = dirname( __DIR__, 2 );
$source = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-catalog-json.php' );
require_once $root . '/wp-content/themes/nuvanx-medical/inc/nvx-catalog-json.php';

$tariffs = nvx_catalog_json_load( 'tariff-catalog.json' );
nvx_tariff_ssot_assert( empty( $tariffs['_error'] ), 'CANONICAL_TARIFF_LOADS' );
nvx_tariff_ssot_assert( 290.0 === nvx_catalog_get_tariff_pvp( $tariffs, 'labios_ha', 'hidratacion' ), 'LIPS_CANONICAL_KEY' );
nvx_tariff_ssot_assert( null === nvx_catalog_get_tariff_pvp( $tariffs, 'labios_ha', 'perfilado_hidratacion' ), 'LEGACY_COMPOSITE_KEY_ABSENT' );
nvx_tariff_ssot_assert( 348.0 === nvx_catalog_get_tariff_pvp( $tariffs, 'bioestimuladores', 'polynucleotides_cara' ), 'BIO_CANONICAL_KEY' );

$catalog = nvx_catalog_json_resolved( 'aesthetic-medicine-page.json' );
$by_n    = array();
foreach ( $catalog['treatments'] ?? array() as $treatment ) {
	if ( is_array( $treatment ) && isset( $treatment['n'] ) ) {
		$by_n[ (string) $treatment['n'] ] = $treatment;
	}
}

nvx_tariff_ssot_assert( isset( $by_n['01'], $by_n['04'], $by_n['05'] ), 'AESTHETIC_IDENTITIES_PRESENT' );
nvx_tariff_ssot_assert( 'Desde 290 €' === ( $by_n['01']['price'] ?? '' ), 'LIPS_HYDRATED_FROM_CANONICAL_TARIFF' );
nvx_tariff_ssot_assert( 'Desde 348 € (según principio activo y zona)' === ( $by_n['05']['price'] ?? '' ), 'BIO_HYDRATED_ON_TREATMENT_05' );
nvx_tariff_ssot_assert( 'Presupuesto según zona tras valoración' === ( $by_n['04']['price'] ?? '' ), 'FACIAL_HA_CARD_NOT_OVERWRITTEN_BY_BIO' );

// The mapping must survive catalog reordering because identity, not array index, owns hydration.
$shuffled = array(
	'treatments' => array(
		array( 'n' => '05', 'price' => 'legacy 999 €' ),
		array( 'n' => '01', 'price' => 'legacy 999 €' ),
		array( 'n' => '04', 'price' => 'Presupuesto según zona tras valoración' ),
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
nvx_tariff_ssot_assert( 'Presupuesto según zona tras valoración' === $shuffled_by_n['04'], 'NON_TARIFF_CARD_REORDER_SAFE' );

// When canonical tariffs cannot be trusted, old numeric editorial prices are neutralized.
$unsafe = array(
	'treatments' => array(
		array( 'n' => '01', 'price' => 'Desde 999 €' ),
		array( 'n' => '02', 'price' => 'Desde 999 €' ),
		array( 'n' => '03', 'price' => 'Desde 999 €' ),
		array( 'n' => '04', 'price' => 'Presupuesto según zona tras valoración' ),
		array( 'n' => '05', 'price' => 'Desde 999 €' ),
	),
);
$neutral = nvx_catalog_suppress_price_copy( $unsafe, 'aesthetic-medicine-page.json', nvx_catalog_governance_config() );
foreach ( array( '01', '02', '03', '05' ) as $number ) {
	$index = nvx_catalog_aesthetic_treatment_index( $neutral, $number );
	nvx_tariff_ssot_assert( null !== $index, 'NEUTRAL_IDENTITY_' . $number );
	nvx_tariff_ssot_assert( false === strpos( (string) $neutral['treatments'][ $index ]['price'], '999' ), 'FAIL_CLOSED_REMOVES_EDITORIAL_PRICE_' . $number );
}

nvx_tariff_ssot_assert( false === strpos( $source, "'perfilado_hidratacion'" ), 'LEGACY_TARIFF_LOOKUP_REMOVED' );
nvx_tariff_ssot_assert( false === strpos( $source, "['treatments'][3]" ), 'POSITIONAL_BIO_OWNER_REMOVED' );
nvx_tariff_ssot_assert( false !== strpos( $source, 'nvx_catalog_suppress_price_copy' ), 'FAIL_CLOSED_PRICE_GUARD_PRESENT' );

echo 'TARIFF_SSOT_RUNTIME=PASS source=tariff-catalog lips=hidratacion bio=treatment-05 positional_owner=removed fail_closed=1 reorder_safe=1' . PHP_EOL;

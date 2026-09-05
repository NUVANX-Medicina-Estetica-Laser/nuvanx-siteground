<?php
/**
 * Contract: FAQPage schema coverage across medical treatment pages.
 *
 * @package nuvanx-medical
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );

function get_locale(): string {
	return 'es_ES';
}
function determine_locale(): string {
	return 'es_ES';
}
function home_url( string $path = '' ): string {
	return 'https://nuvanx.com' . $path;
}
function get_permalink( int $post_id ): string {
	return 'https://nuvanx.com/treatment-' . $post_id . '/';
}
function is_front_page(): bool {
	return false;
}
function get_queried_object_id(): int {
	return 2017; // mock
}
function __( string $text, string $domain = 'default' ): string {
	return $text;
}
function add_action( ...$args ): bool { unset( $args ); return true; }
function add_filter( ...$args ): bool { unset( $args ); return true; }
function esc_html( string $text = '' ): string { return $text; }
function esc_attr( string $text = '' ): string { return $text; }
function esc_url( string $url = '' ): string { return $url; }

$root = dirname( __DIR__, 2 );
require_once $root . '/wp-content/themes/nuvanx-medical/inc/nvx-catalog-json.php';
require_once $root . '/wp-content/themes/nuvanx-medical/inc/nvx-schema-faq.php';

$catalog = nvx_schema_faq_catalog();

$required_keys = array(
	'endolift_facial',
	'endolaser_corporal',
	'laser_co2',
	'co2',
	'exion_btl',
	'profhilo',
	'lips_ha',
	'rhinomodeling_ha',
	'rinomodelacion',
	'facial_ha',
	'acido_hialuronico',
);

foreach ( $required_keys as $key ) {
	if ( empty( $catalog[ $key ] ) || ! is_array( $catalog[ $key ] ) ) {
		fwrite( STDERR, "TREATMENT_FAQPAGE_CONTRACT=FAIL missing catalog key: {$key}\n" );
		exit( 1 );
	}
	$first = $catalog[ $key ][0] ?? null;
	if ( empty( $first['q'] ) || empty( $first['a'] ) ) {
		fwrite( STDERR, "TREATMENT_FAQPAGE_CONTRACT=FAIL empty Q/A for key: {$key}\n" );
		exit( 1 );
	}
}

// Assert specific high-intent transactional questions exist.
$endolift_faqs = json_encode( $catalog['endolift_facial'], JSON_UNESCAPED_UNICODE );
if ( ! str_contains( $endolift_faqs, 'cuánto cuesta' ) && ! str_contains( $endolift_faqs, 'Cuánto cuesta' ) ) {
	fwrite( STDERR, "TREATMENT_FAQPAGE_CONTRACT=FAIL Endolift missing pricing question\n" );
	exit( 1 );
}

// Expected FAQ prices must be derived from the canonical tariff SSOT, never
// duplicated as fixture literals. This keeps the contract valid after a
// governed tariff update and prevents tests from becoming a second price owner.
$tariffs     = nvx_catalog_json_load( 'tariff-catalog.json' );
$ojeras_pvp  = nvx_catalog_get_tariff_pvp( $tariffs, 'Endolift®', 'ojeras' );
$papada_pvp  = nvx_catalog_get_tariff_pvp( $tariffs, 'Endolift®', 'papada' );
if ( null === $ojeras_pvp || null === $papada_pvp ) {
	fwrite( STDERR, "TREATMENT_FAQPAGE_CONTRACT=FAIL canonical Endolift tariff missing\n" );
	exit( 1 );
}
$ojeras_label = number_format( $ojeras_pvp, 2, ',', '.' ) . ' €';
$papada_label = number_format( $papada_pvp, 2, ',', '.' ) . ' €';
if ( ! str_contains( $endolift_faqs, $ojeras_label ) || ! str_contains( $endolift_faqs, $papada_label ) ) {
	fwrite( STDERR, "TREATMENT_FAQPAGE_CONTRACT=FAIL Endolift pricing FAQ not synchronized with tariff catalog\n" );
	exit( 1 );
}

$co2_faqs = json_encode( $catalog['laser_co2'], JSON_UNESCAPED_UNICODE );
if ( ! str_contains( $co2_faqs, 'cuántas sesiones' ) && ! str_contains( $co2_faqs, 'Cuántas sesiones' ) ) {
	fwrite( STDERR, "TREATMENT_FAQPAGE_CONTRACT=FAIL CO2 missing sessions question\n" );
	exit( 1 );
}

$aesthetic_pages = nvx_catalog_json_resolved( 'aesthetic-treatment-pages.json' );
$routes          = nvx_catalog_json_resolved( 'routes.json' );
if ( ! is_array( $aesthetic_pages ) || ! is_array( $routes ) ) {
	fwrite( STDERR, "TREATMENT_FAQPAGE_CONTRACT=FAIL missing aesthetic or routes catalog\n" );
	exit( 1 );
}
foreach ( $aesthetic_pages as $json_key => $entry ) {
	if ( ! is_string( $json_key ) || ! is_array( $entry ) || empty( $entry['faqs'] ) || ! is_array( $entry['faqs'] ) ) {
		continue;
	}
	if ( empty( $catalog[ $json_key ] ) || ! is_array( $catalog[ $json_key ] ) ) {
		fwrite( STDERR, "TREATMENT_FAQPAGE_CONTRACT=FAIL missing aesthetic JSON key: {$json_key}\n" );
		exit( 1 );
	}
	$slug = trim( (string) ( $entry['slug'] ?? '' ), '/' );
	if ( '' === $slug ) {
		fwrite( STDERR, "TREATMENT_FAQPAGE_CONTRACT=FAIL aesthetic JSON key '{$json_key}' with FAQs has empty or missing slug\n" );
		exit( 1 );
	}
	$path      = '/' . $slug . '/';
	$schema_id = (string) ( $routes[ $path ]['schema_id'] ?? '' );
	if ( '' === $schema_id ) {
		fwrite( STDERR, "TREATMENT_FAQPAGE_CONTRACT=FAIL missing routes schema_id for {$path}\n" );
		exit( 1 );
	}
	if ( empty( $catalog[ $schema_id ] ) || ! is_array( $catalog[ $schema_id ] ) ) {
		fwrite( STDERR, "TREATMENT_FAQPAGE_CONTRACT=FAIL missing routes schema_id alias: {$json_key} -> {$schema_id}\n" );
		exit( 1 );
	}
}

// Assert that nvx_schema_faq_node resolves correctly for acido_hialuronico.
function nvx_schema_resolve_treatment_key( $page_id ) {
	if ( 3602 === $page_id ) return 'acido_hialuronico';
	return null;
}
$node = nvx_schema_faq_node( 3602 );
if ( empty( $node ) || 'FAQPage' !== $node['@type'] ) {
	fwrite( STDERR, "TREATMENT_FAQPAGE_CONTRACT=FAIL nvx_schema_faq_node failed to emit FAQPage for acido_hialuronico\n" );
	exit( 1 );
}
echo "TREATMENT_FAQPAGE_CONTRACT=PASS verified_keys=" . count( $required_keys ) . " tariff_expectations=ssot-derived\n";

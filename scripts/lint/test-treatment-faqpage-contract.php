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
function nvx_format_price_eur( float $price ): string {
	return number_format( $price, 2, ',', '.' );
}
function nvx_endolift_price_from_eur(): float {
	return 798.60;
}
function add_action( ...$args ): bool { unset( $args ); return true; }
function add_filter( ...$args ): bool { unset( $args ); return true; }
function esc_html( string $text = '' ): string { return $text; }
function esc_attr( string $text = '' ): string { return $text; }
function esc_url( string $url = '' ): string { return $url; }

require_once dirname( __DIR__, 2 ) . '/wp-content/themes/nuvanx-medical/inc/nvx-catalog-json.php';
require_once dirname( __DIR__, 2 ) . '/wp-content/themes/nuvanx-medical/inc/nvx-schema-faq.php';

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

// Assert specific high-intent transactional questions exist
$endolift_faqs = json_encode( $catalog['endolift_facial'], JSON_UNESCAPED_UNICODE );
if ( ! str_contains( $endolift_faqs, 'cuánto cuesta' ) && ! str_contains( $endolift_faqs, 'Cuánto cuesta' ) ) {
	fwrite( STDERR, "TREATMENT_FAQPAGE_CONTRACT=FAIL Endolift missing pricing question\n" );
	exit( 1 );
}

if ( ! str_contains( $endolift_faqs, '798,60 €' ) || ! str_contains( $endolift_faqs, '1.064,80 €' ) ) {
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
		fwrite( STDERR, "TREATMENT_FAQPAGE_CONTRACT=FAIL FAQ-bearing aesthetic entry {$json_key} is missing a slug\n" );
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

echo "TREATMENT_FAQPAGE_CONTRACT=PASS verified_keys=" . count( $required_keys ) . "\n";

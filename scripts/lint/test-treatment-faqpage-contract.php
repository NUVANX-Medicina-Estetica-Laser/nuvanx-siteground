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
function nvx_catalog_json_resolved( string $file ): array {
	$path = dirname( __DIR__, 2 ) . '/wp-content/themes/nuvanx-medical/inc/data/' . $file;
	if ( ! file_exists( $path ) ) {
		return array();
	}
	$decoded = json_decode( (string) file_get_contents( $path ), true );
	return is_array( $decoded ) ? $decoded : array();
}
function nvx_format_price_eur( float $price ): string {
	return number_format( $price, 2, ',', '.' );
}
function nvx_endolift_price_from_eur(): float {
	return 798.60;
}
function nvx_endolift_price_papada_eur(): float {
	return 1064.80;
}

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

$co2_faqs = json_encode( $catalog['laser_co2'], JSON_UNESCAPED_UNICODE );
if ( ! str_contains( $co2_faqs, 'cuántas sesiones' ) && ! str_contains( $co2_faqs, 'Cuántas sesiones' ) ) {
	fwrite( STDERR, "TREATMENT_FAQPAGE_CONTRACT=FAIL CO2 missing sessions question\n" );
	exit( 1 );
}

echo "TREATMENT_FAQPAGE_CONTRACT=PASS verified_keys=" . count( $required_keys ) . "\n";

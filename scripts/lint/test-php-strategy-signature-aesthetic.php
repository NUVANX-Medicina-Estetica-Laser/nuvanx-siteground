<?php
/**
 * Block 5 regression: Strategy + Signature + Bridal + Aesthetic PHP.
 *
 * @package nuvanx-medical
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );

function add_filter( ...$args ): bool { unset( $args ); return true; }
function home_url( string $path = '' ): string { return 'https://nuvanx.test' . $path; }
function trailingslashit( string $value ): string { return rtrim( $value, '/' ) . '/'; }
function nvx_schema_price_string( float $value ): string { return number_format( $value, 2, '.', '' ); }

function nvx_block5_assert( bool $condition, string $name ): void {
	if ( ! $condition ) {
		fwrite( STDERR, 'PHP_STRATEGY_SIGNATURE_AESTHETIC=FAIL invariant=' . $name . PHP_EOL );
		exit( 1 );
	}
}

$root = dirname( __DIR__, 2 );
require_once $root . '/wp-content/themes/nuvanx-medical/inc/nvx-aesthetic-treatment-schema.php';

// Currency parser must ignore session counts, durations and other non-price numbers.
$amounts = nvx_aesthetic_schema_euro_amounts( '1 sesión · desde 330 € · revisión a los 15 días' );
nvx_block5_assert( array( 330.0 ) === $amounts, 'NON_PRICE_NUMBERS_IGNORED' );

$amounts = nvx_aesthetic_schema_euro_amounts( 'Hidratación: desde 290 €. Perfilado: desde 498 EUR.' );
nvx_block5_assert( array( 290.0, 498.0 ) === $amounts, 'MULTIPLE_EUR_AMOUNTS_PARSED' );

$amounts = nvx_aesthetic_schema_euro_amounts( 'Tarifa desde 1.064,80 €' );
nvx_block5_assert( 1 === count( $amounts ) && abs( $amounts[0] - 1064.80 ) < 0.001, 'SPANISH_THOUSANDS_DECIMALS' );

$amounts = nvx_aesthetic_schema_euro_amounts( '3 sesiones recomendadas; presupuesto tras valoración.' );
nvx_block5_assert( array() === $amounts, 'NO_CURRENCY_NO_OFFER_AMOUNT' );

$schema = array(
	'name'          => 'Test procedure',
	'alternateName' => array( 'Test' ),
	'bodyLocation'  => 'Face',
	'procedureType' => 'https://schema.org/PercutaneousProcedure',
	'preparation'   => 'Assessment',
	'howPerformed'  => 'Procedure',
	'followup'      => 'Follow-up',
	'indications'   => array( 'Indication' ),
	'conditions'    => array( 'Condition' ),
);
$entry = array(
	'description' => 'Test description',
	'price_range' => '1 sesión · desde 330 € · revisión a los 15 días',
);
$node = nvx_aesthetic_schema_procedure_node(
	$schema,
	$entry,
	'https://nuvanx.test/test/',
	'https://nuvanx.test/#organization'
);
nvx_block5_assert( '330.00' === ( $node['offers']['price'] ?? '' ), 'OFFER_PRICE_IS_CURRENCY_AMOUNT' );
nvx_block5_assert( '330.00' === ( $node['offers']['priceSpecification']['minPrice'] ?? '' ), 'FROM_PRICE_MINIMUM' );

// Validate the real Signature source records contain the fields the renderer indexes.
$signature_path = $root . '/wp-content/themes/nuvanx-medical/inc/data/nvx-signature-phase-catalog.json';
$signature_json = json_decode( (string) file_get_contents( $signature_path ), true );
nvx_block5_assert( is_array( $signature_json ) && array() !== $signature_json, 'SIGNATURE_JSON_AVAILABLE' );
$required = array( 'slug', 'title', 'kicker', 'lead', 'intro', 'assessment', 'technology', 'limits', 'seo_title', 'seo_desc', 'protocol' );
foreach ( $signature_json as $key => $record ) {
	nvx_block5_assert( is_array( $record ), 'SIGNATURE_RECORD_ARRAY_' . (string) $key );
	nvx_block5_assert( array() === array_diff( $required, array_keys( $record ) ), 'SIGNATURE_REQUIRED_FIELDS_' . (string) $key );
}

$signature_source = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-signature-catalog.php' );
nvx_block5_assert(
	false !== strpos( $signature_source, 'nvx_catalog_filter_records(' ),
	'SIGNATURE_RUNTIME_FILTERS_INCOMPLETE_RECORDS'
);

// Inventory guards for final ownership consolidation: canonical root bootstrap
// must order the shared owners before these editorial consumers.
$bootstrap = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-theme-bootstrap.php' );
$catalog_owner = strpos( $bootstrap, "'inc/nvx-catalog-json.php'" );
$aesthetic     = strpos( $bootstrap, "'inc/nvx-aesthetic-treatment-pages.php'" );
$page_helpers  = strpos( $bootstrap, "'inc/nvx-page-render-helpers.php'" );
$bridal        = strpos( $bootstrap, "'inc/nvx-bridal-page.php'" );
$signature_cat = strpos( $bootstrap, "'inc/nvx-signature-catalog.php'" );
$signature_page = strpos( $bootstrap, "'inc/nvx-signature-phase-pages.php'" );
nvx_block5_assert( false !== $catalog_owner && false !== $aesthetic && $catalog_owner < $aesthetic, 'CATALOG_OWNER_PRECEDES_AESTHETIC' );
nvx_block5_assert( false !== $page_helpers && false !== $bridal && $page_helpers < $bridal, 'PAGE_HELPERS_PRECEDE_BRIDAL' );
nvx_block5_assert( false !== $signature_cat && false !== $signature_page && $signature_cat < $signature_page, 'SIGNATURE_CATALOG_PRECEDES_RENDERER' );

echo 'PHP_STRATEGY_SIGNATURE_AESTHETIC=PASS offers=currency_bound signature=validated bootstrap_order=verified' . PHP_EOL;

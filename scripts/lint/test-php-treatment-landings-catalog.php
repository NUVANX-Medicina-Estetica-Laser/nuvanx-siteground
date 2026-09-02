<?php
/**
 * Block 7 regression: treatment catalog + laser / BTL landing PHP.
 *
 * @package nuvanx-medical
 */

declare(strict_types=1);

function nvx_block7_assert( bool $condition, string $name ): void {
	if ( ! $condition ) {
		fwrite( STDERR, 'PHP_TREATMENT_LANDINGS_CATALOG=FAIL invariant=' . $name . PHP_EOL );
		exit( 1 );
	}
}

$root = dirname( __DIR__, 2 );
$profhilo = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-profhilo-page.php' );
$medical  = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-medical-review.php' );

// Profhilo must not own a second visible medical-review contract.
nvx_block7_assert( false === strpos( $profhilo, 'nvx-profhilo-reviewed' ), 'NO_PROFHILO_SECONDARY_REVIEW_BLOCK' );
nvx_block7_assert( false === strpos( $profhilo, "\$data['byline_author']" ), 'NO_PROFHILO_EDITORIAL_BYLINE_OWNER' );
nvx_block7_assert( false === strpos( $profhilo, "\$data['byline_title']" ), 'NO_PROFHILO_EDITORIAL_REVIEW_DATE_OWNER' );
nvx_block7_assert( false !== strpos( $medical, 'data-nvx-medical-review="approved"' ), 'CANONICAL_APPROVAL_GATED_REVIEW_OWNER_PRESENT' );

// Inventory every landing in this block and ensure the canonical helper owner
// precedes them in the root bootstrap. Lateral requires are recorded for final consolidation.
$bootstrap = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-theme-bootstrap.php' );
$helpers   = strpos( $bootstrap, "'inc/nvx-page-render-helpers.php'" );
$landings  = array(
	'nvx-solutions-page.php',
	'nvx-endolift-page.php',
	'nvx-exion-page.php',
	'nvx-profhilo-page.php',
	'nvx-endolaser-page.php',
	'nvx-co2-page.php',
	'nvx-btl-detail-pages.php',
);
nvx_block7_assert( false !== $helpers, 'PAGE_HELPER_OWNER_PRESENT' );
foreach ( $landings as $file ) {
	$offset = strpos( $bootstrap, "'inc/{$file}'" );
	nvx_block7_assert( false !== $offset && $helpers < $offset, 'PAGE_HELPERS_PRECEDE_' . $file );
}

// The current tariff contract mismatch is deliberately visible to the final
// consolidation: never silently change the catalog shape without updating the hydrator.
$tariffs = json_decode(
	(string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/data/tariff-catalog.json' ),
	true
);
nvx_block7_assert( is_array( $tariffs ), 'TARIFF_JSON_VALID' );
nvx_block7_assert( isset( $tariffs['labios_ha']['hidratacion'] ), 'LIPS_HYDRATION_CANONICAL_KEY_PRESENT' );
nvx_block7_assert( ! isset( $tariffs['labios_ha']['perfilado_hidratacion'] ), 'LEGACY_LIPS_COMPOSITE_KEY_ABSENT' );

$aesthetic_catalog = json_decode(
	(string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/data/aesthetic-medicine-page.json' ),
	true
);
nvx_block7_assert( is_array( $aesthetic_catalog ) && isset( $aesthetic_catalog['treatments'][4] ), 'AESTHETIC_TREATMENT_INDEX_4_EXISTS' );
nvx_block7_assert( false !== strpos( (string) $aesthetic_catalog['treatments'][4]['title'], 'Bioestimulación' ), 'BIOSTIMULATION_IS_INDEX_4' );

echo 'PHP_TREATMENT_LANDINGS_CATALOG=PASS profhilo_review=single_owner landing_inventory=covered tariff_drift=guarded' . PHP_EOL;

<?php
/**
 * Contract: GBP website-side photos, local copy and T+7 review policy.
 *
 * @package nuvanx-medical
 */

declare(strict_types=1);

$root = dirname( __DIR__, 2 );
$fail = static function ( string $message ): void {
	fwrite( STDERR, 'GBP_LOCAL_CONTRACT=FAIL ' . $message . PHP_EOL );
	exit( 1 );
};

$catalog_path = $root . '/wp-content/themes/nuvanx-medical/inc/data/gbp-profiles.json';
$module_path  = $root . '/wp-content/themes/nuvanx-medical/inc/nvx-gbp-local.php';
$catalog      = json_decode( (string) file_get_contents( $catalog_path ), true );
$module       = (string) file_get_contents( $module_path );

if ( ! is_array( $catalog ) ) {
	$fail( 'gbp-profiles.json is invalid' );
}

if ( ( $catalog['primary_category'] ?? '' ) !== 'Clínica de medicina estética' ) {
	$fail( 'primary category must be Clínica de medicina estética' );
}

$asset_registry_path = $root . '/wp-content/themes/nuvanx-medical/inc/data/clinic-asset-registry.json';
$asset_registry      = json_decode( (string) file_get_contents( $asset_registry_path ), true );
$governed_galleries  = $asset_registry['approved_editorial_overrides']['clinic_landing_galleries'] ?? array();

foreach ( array( 'chamberi', 'goya' ) as $clinic ) {
	$expected_count = count( $governed_galleries[ $clinic ] ?? array() );
	if ( $expected_count < 1 ) {
		$fail( $clinic . ' must have at least 1 registered gallery photo in clinic-asset-registry.json' );
	}
	$description = (string) ( $catalog['clinics'][ $clinic ]['description'] ?? '' );
	foreach ( (array) ( $catalog['clinics'][ $clinic ]['neighborhood_keywords'] ?? array() ) as $keyword ) {
		if ( false === strpos( $description, (string) $keyword ) ) {
			$fail( $clinic . ' description missing keyword ' . $keyword );
		}
	}
}

if ( 3 !== (int) ( $catalog['review_policy']['delay_days'] ?? 0 ) ) {
	$fail( 'review delay must be 3 days' );
}

if ( empty( $catalog['review_policy']['incentives_forbidden'] ) || empty( $catalog['review_policy']['star_coaching_forbidden'] ) ) {
	$fail( 'incentives and star coaching must be forbidden' );
}

$email_fn = (string) preg_match( '/function nvx_gbp_review_email_body[\s\S]+?^}/m', $module, $email_match )
	? $email_match[0]
	: $module;
foreach ( array( 'regalo', 'descuento', '5 estrellas', 'a cambio' ) as $banned ) {
	if ( false !== stripos( $email_fn, $banned ) ) {
		$fail( 'review email contains incentive language: ' . $banned );
	}
}

if ( ! str_contains( $module, 'NVX_GBP_DELAY_DAYS  = 3' )
	|| ! str_contains( $module, 'nvx_gbp_send_due_review_requests' )
	|| ! str_contains( $module, 'writereview' ) ) {
	$fail( 'T+3 review sender or GBP review URL helper is missing' );
}

echo 'GBP_LOCAL_CONTRACT=PASS delay_days=3' . PHP_EOL;

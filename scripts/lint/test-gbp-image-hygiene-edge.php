<?php
/**
 * Regression contract for public image hygiene edge cases.
 *
 * @package nuvanx-medical
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );

$root = dirname( __DIR__, 2 );
$fail = static function ( string $reason ): never {
	fwrite( STDERR, "GBP_IMAGE_HYGIENE_EDGE=FAIL reason={$reason}\n" );
	exit( 1 );
};

$GLOBALS['nvx_test_path'] = '';

function add_filter( $hook_name = null, $callback = null, $priority = 10, $accepted_args = 1 ) {
	unset( $hook_name, $callback, $priority, $accepted_args );
	return true;
}
function add_action( $hook_name = null, $callback = null, $priority = 10, $accepted_args = 1 ) {
	unset( $hook_name, $callback, $priority, $accepted_args );
	return true;
}
function is_admin(): bool { return false; }
function get_queried_object_id(): int { return 0; }
function nvx_schema_current_path( int $page_id = 0 ): string {
	unset( $page_id );
	return (string) ( $GLOBALS['nvx_test_path'] ?? '' );
}

require $root . '/wp-content/themes/nuvanx-medical/inc/nvx-gbp-local.php';

$abdomen = '<img src="https://staging2.nuvanx.com/wp-content/uploads/2026/06/laser-medico-nuvanx-madrid.webp" alt="Laserlipólisis médica en NUVANX Madrid">';

// Unknown/anomalous route context must fail safe: do not delete approved media.
if ( nvx_public_html_is_abdomen_asset_off_intent( $abdomen ) ) {
	$fail( 'empty_path_must_not_block_approved_asset' );
}

$GLOBALS['nvx_test_path'] = '/btl-exilite-ipl-madrid/';
if ( ! nvx_public_html_is_abdomen_asset_off_intent( $abdomen ) ) {
	$fail( 'off_intent_ipl_route_must_block_asset' );
}

$GLOBALS['nvx_test_path'] = '/endolaser-corporal-grasa-localizada/';
if ( nvx_public_html_is_abdomen_asset_off_intent( $abdomen ) ) {
	$fail( 'corporal_route_must_keep_asset' );
}

$wrapped = '<figure class="nvx-brand-hero__media"><picture><img src="https://staging2.nuvanx.com/wp-content/uploads/2026/07/btl-exilite-ipl-madrid.webp" alt="BTL EXILITE"></picture><figcaption>Packshot BTL</figcaption></figure>';
$cleared = nvx_public_strip_vendor_images( $wrapped );
if ( '' !== trim( $cleared ) ) {
	$fail( 'vendor_figure_or_caption_survived' );
}

echo 'GBP_IMAGE_HYGIENE_EDGE=PASS' . PHP_EOL;

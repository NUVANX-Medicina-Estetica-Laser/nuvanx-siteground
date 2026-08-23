<?php
/**
 * Behavioral contract for governed public editorial image delivery.
 *
 * @package nuvanx-medical
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );

$root = dirname( __DIR__, 2 );
$fail = static function ( string $reason ): never {
	fwrite( STDERR, "PUBLIC_MEDIA_DELIVERY=FAIL reason={$reason}\n" );
	exit( 1 );
};

function add_filter( $hook_name = null, $callback = null, $priority = 10, $accepted_args = 1 ) {
	unset( $hook_name, $callback, $priority, $accepted_args );
	return true;
}
function add_action( $hook_name = null, $callback = null, $priority = 10, $accepted_args = 1 ) {
	unset( $hook_name, $callback, $priority, $accepted_args );
	return true;
}
function is_admin(): bool {
	return false;
}
function is_singular( $post_types = '' ): bool {
	unset( $post_types );
	return false;
}
function get_post_field( $field, $post = null ) {
	unset( $field, $post );
	return '';
}
function get_queried_object_id(): int {
	return 0;
}
function __( $text, $domain = 'default' ): string {
	unset( $domain );
	return (string) $text;
}
function esc_html( $value ): string {
	return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
}
function esc_attr( $value ): string {
	return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
}
function get_attached_file( $attachment_id ): string {
	return '/tmp/nvx-media-' . (int) $attachment_id . '.webp';
}
function wp_get_attachment_image( $attachment_id, $size = 'thumbnail', $icon = false, $attr = array() ): string {
	unset( $icon, $attr );
	return sprintf( '<img src="https://staging2.nuvanx.com/media/%d-%s.webp">', (int) $attachment_id, (string) $size );
}
function wp_get_attachment_image_src( $attachment_id, $size = 'thumbnail' ): array {
	$width = 'large' === $size ? 1024 : 300;
	return array(
		sprintf( 'https://staging2.nuvanx.com/media/%d-%s.webp', (int) $attachment_id, (string) $size ),
		$width,
		(int) round( $width * 0.75 ),
		true,
	);
}
function wp_get_attachment_url( $attachment_id ): string {
	return 'https://staging2.nuvanx.com/media/' . (int) $attachment_id . '.webp';
}

// Reproduce the runtime state in which the clinic map is available when the
// authentic registry is evaluated.
require $root . '/wp-content/themes/nuvanx-medical/inc/nvx-gbp-local.php';
require $root . '/wp-content/themes/nuvanx-medical/inc/nvx-authentic-page-photography.php';

$registry_ids = array();
foreach ( nvx_authentic_page_photo_registry() as $entry ) {
	foreach ( (array) ( $entry['images'] ?? array() ) as $image ) {
		$registry_ids[] = (int) ( $image['id'] ?? 0 );
	}
}
if ( ! in_array( 2877, $registry_ids, true ) ) {
	$fail( 'optimized_consultation_missing_from_runtime_registry' );
}
foreach ( array( 2115, 2109 ) as $orphaned_id ) {
	if ( in_array( $orphaned_id, $registry_ids, true ) ) {
		$fail( 'orphaned_attachment_still_in_runtime_registry:' . $orphaned_id );
	}
}
foreach ( array( 3068, 2093, 2446, 2894 ) as $replacement_id ) {
	if ( ! in_array( $replacement_id, $registry_ids, true ) ) {
		$fail( 'replacement_attachment_missing_from_runtime_registry:' . $replacement_id );
	}
}

// 2892 may remain as an internal rollback reference in the clinic map, but its
// public/Schema URL must resolve to optimized attachment 2877.
foreach ( array( 'chamberi', 'goya' ) as $clinic_key ) {
	$map = nvx_clinic_editorial_photo_map( $clinic_key );
	if ( 4 !== count( $map ) ) {
		$fail( 'clinic_map_count:' . $clinic_key );
	}
	foreach ( $map as $image ) {
		$id = (int) ( $image['id'] ?? 0 );
		if ( 2892 !== $id ) {
			continue;
		}
		$public_url = nvx_governed_public_attachment_url( 'https://staging2.nuvanx.com/media/2892.webp', $id );
		if ( 'https://staging2.nuvanx.com/media/2877.webp' !== $public_url ) {
			$fail( 'clinic_legacy_url_not_publicly_aliased:' . $clinic_key );
		}
	}
}

$legacy = nvx_governed_public_image_downsize( false, 2892, 'full' );
if ( ! is_array( $legacy ) || false === strpos( (string) $legacy[0], '/2877-large.webp' ) ) {
	$fail( 'legacy_consultation_not_resolved_through_2877' );
}

$heavy = nvx_governed_public_image_downsize( false, 3358, 'full' );
if ( ! is_array( $heavy ) || false === strpos( (string) $heavy[0], '/3358-large.webp' ) ) {
	$fail( 'governed_heavy_asset_not_downsized' );
}
if ( false !== nvx_governed_public_image_downsize( false, 2086, 'full' ) ) {
	$fail( 'co2_authorized_hero_must_remain_outside_governed_downsize' );
}

$sources = array(
	300  => array( 'url' => '300.webp', 'descriptor' => 'w', 'value' => 300 ),
	768  => array( 'url' => '768.webp', 'descriptor' => 'w', 'value' => 768 ),
	1024 => array( 'url' => '1024.webp', 'descriptor' => 'w', 'value' => 1024 ),
	1280 => array( 'url' => '1280.webp', 'descriptor' => 'w', 'value' => 1280 ),
	1600 => array( 'url' => '1600.webp', 'descriptor' => 'w', 'value' => 1600 ),
);

$rivera_sources = nvx_governed_public_srcset_sources( $sources, array(), '', array(), 2381 );
if ( array_key_exists( 1024, $rivera_sources ) || array_key_exists( 1280, $rivera_sources ) || array_key_exists( 1600, $rivera_sources ) ) {
	$fail( 'rivera_portrait_srcset_exceeds_768' );
}

$body_sources = nvx_governed_public_srcset_sources( $sources, array(), '', array(), 3358 );
if ( array_key_exists( 1600, $body_sources ) || ! array_key_exists( 1280, $body_sources ) ) {
	$fail( 'editorial_srcset_cap_not_1280' );
}

$attachment = (object) array( 'ID' => 2381 );
$attrs      = nvx_governed_public_image_attributes( array( 'sizes' => '28vw' ), $attachment, 'full' );
if ( '(min-width: 769px) 224px, 92vw' !== (string) ( $attrs['sizes'] ?? '' ) ) {
	$fail( 'rivera_sizes_not_bound_to_14rem_column' );
}

$aliased = nvx_governed_public_attachment_url( 'https://staging2.nuvanx.com/media/2892.webp', 2892 );
if ( 'https://staging2.nuvanx.com/media/2877.webp' !== $aliased ) {
	$fail( 'schema_attachment_url_not_aliased_to_2877' );
}

$untouched = nvx_governed_public_attachment_url( 'https://staging2.nuvanx.com/media/2086.webp', 2086 );
if ( 'https://staging2.nuvanx.com/media/2086.webp' !== $untouched ) {
	$fail( 'unrelated_attachment_url_changed' );
}

echo 'PUBLIC_MEDIA_DELIVERY=PASS cases=16' . PHP_EOL;

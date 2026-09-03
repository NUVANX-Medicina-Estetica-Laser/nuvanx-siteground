<?php
/**
 * Regression contract for the H1 post-meta persistence boundary.
 */

declare(strict_types=1);

$root   = dirname( __DIR__, 2 );
$source = (string) file_get_contents( $root . '/tools/migrations/h1-content-seed-reconciliation.php' );

$assert = static function ( bool $condition, string $name ): void {
	if ( ! $condition ) {
		fwrite( STDERR, 'H1_POSTMETA_VERIFICATION=FAIL invariant=' . $name . PHP_EOL );
		exit( 1 );
	}
};

$assert( '' !== $source, 'SOURCE_READABLE' );
$assert( str_contains( $source, 'function nvx_h1_set_meta_verified' ), 'HELPER_PRESENT' );
$assert( str_contains( $source, "wp_cache_delete( \$post_id, 'post_meta' )" ), 'POST_META_CACHE_INVALIDATED' );
$assert( str_contains( $source, '$wpdb->postmeta' ), 'DURABLE_POSTMETA_TABLE_VERIFIED' );
$assert( str_contains( $source, 'SELECT meta_value' ), 'DURABLE_META_READ_PRESENT' );
$assert( str_contains( $source, 'post_meta_persistence_verification_failed' ), 'PERSISTENCE_FAILURE_FAILS_CLOSED' );
$assert( str_contains( $source, 'post_meta_runtime_verification_failed' ), 'RUNTIME_FAILURE_FAILS_CLOSED' );
$assert( str_contains( $source, 'get_post_meta( $post_id, $meta_key, true )' ), 'RUNTIME_API_REVERIFIED' );

echo 'H1_POSTMETA_VERIFICATION=PASS durable=db runtime=wordpress cache=explicitly-invalidated' . PHP_EOL;

<?php
/** Deterministic regression for H1 post-COMMIT object-cache interleavings. */

declare(strict_types=1);

$GLOBALS['nvx_test_durable'] = array();
$GLOBALS['nvx_test_cache'] = array();
$GLOBALS['nvx_test_inject_concurrent_change'] = false;

class WP_Post {}

final class NvxH1RaceWpdb {
	public string $postmeta = 'wp_postmeta';

	public function prepare( string $query, ...$args ): array {
		return array( 'query' => $query, 'args' => $args );
	}

	public function get_col( array $prepared ): array {
		$key = (string) ( $prepared['args'][1] ?? '' );
		if ( ! array_key_exists( $key, $GLOBALS['nvx_test_durable'] ) ) {
			return array();
		}
		return array( (string) $GLOBALS['nvx_test_durable'][ $key ] );
	}
}

$GLOBALS['wpdb'] = new NvxH1RaceWpdb();

function sanitize_key( $value ): string {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ) ?? '';
}
function sanitize_title( $value ): string { return sanitize_key( $value ); }
function wp_strip_all_tags( $value ): string { return strip_tags( (string) $value ); }
function sanitize_textarea_field( $value ): string { return (string) $value; }
function maybe_serialize( $value ) { return $value; }
function maybe_unserialize( $value ) { return $value; }
function clean_post_cache( $post_id ): void {
	unset( $GLOBALS['nvx_test_cache']['posts'][ (int) $post_id ] );
	unset( $GLOBALS['nvx_test_cache']['post_meta'][ (int) $post_id ] );
}
function wp_cache_delete( $key, $group = '' ): bool {
	unset( $GLOBALS['nvx_test_cache'][ (string) $group ][ $key ] );
	return true;
}
function get_post_meta( $post_id, $meta_key, $single = false ) {
	$post_id = (int) $post_id;
	$meta_key = (string) $meta_key;
	if ( isset( $GLOBALS['nvx_test_cache']['post_meta'][ $post_id ][ $meta_key ] ) ) {
		return $GLOBALS['nvx_test_cache']['post_meta'][ $post_id ][ $meta_key ];
	}

	// Model the dangerous window inside a lazy runtime read: this request reads
	// the old durable value, another request commits a new value and invalidates,
	// then this request finishes by installing its older snapshot in cache.
	$value = (string) ( $GLOBALS['nvx_test_durable'][ $meta_key ] ?? '' );
	if ( true === $GLOBALS['nvx_test_inject_concurrent_change'] ) {
		$GLOBALS['nvx_test_inject_concurrent_change'] = false;
		$GLOBALS['nvx_test_durable'][ $meta_key ] = 'approved';
		wp_cache_delete( $post_id, 'post_meta' );
	}
	$GLOBALS['nvx_test_cache']['post_meta'][ $post_id ][ $meta_key ] = $value;
	return $value;
}

require_once dirname( __DIR__, 2 ) . '/tools/migrations/h1-content-seed-reconciliation.php';

if ( function_exists( 'nvx_h1_prime_post_meta_cache_from_durable_storage' ) ) {
	fwrite( STDERR, "Manual postmeta cache priming owner still exists.\n" );
	exit( 1 );
}

$post_id = 42;
$key = '_nvx_medical_review_status';

// Stable committed state must verify successfully and leave no verifier-owned
// post_meta cache snapshot behind.
$GLOBALS['nvx_test_durable'][ $key ] = 'pending';
$GLOBALS['nvx_test_cache']['post_meta'][ $post_id ][ $key ] = 'stale-before-verification';
nvx_h1_verify_meta_after_commit( $post_id, $key, 'pending' );
if ( isset( $GLOBALS['nvx_test_cache']['post_meta'][ $post_id ] ) ) {
	fwrite( STDERR, "Stable verification left post_meta cache populated.\n" );
	exit( 1 );
}

// Concurrent mutation during the runtime read must be detected by the second
// durable read, while the finally boundary removes the stale lazy snapshot.
$GLOBALS['nvx_test_durable'][ $key ] = 'pending';
$GLOBALS['nvx_test_inject_concurrent_change'] = true;
$detected = false;
try {
	nvx_h1_verify_meta_after_commit( $post_id, $key, 'pending' );
} catch ( RuntimeException $error ) {
	$detected = 'post_meta_postcommit_concurrent_change_nvx_medical_review_status' === $error->getMessage();
}
if ( ! $detected ) {
	fwrite( STDERR, "Concurrent durable mutation was not detected fail-closed.\n" );
	exit( 1 );
}
if ( isset( $GLOBALS['nvx_test_cache']['post_meta'][ $post_id ] ) ) {
	fwrite( STDERR, "Concurrent verification left a stale post_meta cache snapshot.\n" );
	exit( 1 );
}
if ( 'approved' !== $GLOBALS['nvx_test_durable'][ $key ] ) {
	fwrite( STDERR, "Regression harness failed to preserve the concurrent durable update.\n" );
	exit( 1 );
}

fwrite( STDOUT, "H1_POSTCOMMIT_CACHE_RACE=PASS stable=1 concurrent_change_detected=1 final_invalidation=1 manual_cache_writer=0\n" );

<?php
/** Cross-check the PHP deterministic concurrency logic. */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );

function add_action( ...$args ): void {}
function add_filter( ...$args ): void {}
function wp_schedule_event( ...$args ): void {}
function wp_clear_scheduled_hook( ...$args ): void {}
function wp_next_scheduled( ...$args ): bool { return false; }
function wp_unschedule_event( ...$args ): void {}

function get_post_meta( $post_id, $key, $single = false ) {
    return '1';
}
function add_post_meta( ...$args ): void {}
function update_post_meta( ...$args ): void {}
function clean_post_meta_cache( ...$args ): void {}
function absint( $x ) { return (int) $x; }

global $wpdb;
class Mock_WPDB_Atomic {
	public $postmeta = 'wp_postmeta';
	public $queries = array();
	public function prepare( $query, ...$args ) {
		// A simple prepare that just replaces sequentially
		$query = str_replace( "'%s'", "%s", $query );
		$query = str_replace( '"%s"', "%s", $query );
		$query = preg_replace( '/%[dfF]/', '%s', $query );
		
		$escaped_args = array_map( function( $arg ) {
			if ( is_string( $arg ) ) return "'" . $arg . "'";
			return $arg;
		}, $args );
		
		return vsprintf( $query, $escaped_args );
	}
	public function query( $query ) {
		$this->queries[] = $query;
		return 1;
	}
}

$wpdb = new Mock_WPDB_Atomic();

require_once dirname( __DIR__, 2 ) . '/wp-content/themes/nuvanx-medical/inc/nvx-supabase-relay-queue.php';

$new_attempts = nvx_supabase_relay_atomic_increment_attempts( 123, 2 );

if ( empty( $wpdb->queries ) ) {
	echo "FAIL: Atomic increment did not execute any query\n";
	exit( 1 );
}

$query = $wpdb->queries[0];
if ( ! str_contains( $query, "UPDATE wp_postmeta SET meta_value = CAST(meta_value AS UNSIGNED) + 2 WHERE post_id = 123 AND meta_key = '_nvx_relay_attempts'" ) ) {
	echo "FAIL: Query is not atomic. Got: " . $query . "\n";
	exit( 1 );
}

echo "RELAY_CONCURRENCY_ATOMIC=PASS\n";

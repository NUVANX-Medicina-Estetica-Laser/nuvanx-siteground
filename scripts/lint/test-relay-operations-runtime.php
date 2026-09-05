<?php
/** Deterministic unit contract for relay endpoint payload ceilings and operations. */

declare(strict_types=1);

const ABSPATH = __DIR__ . '/';
const DAY_IN_SECONDS = 86400;
const HOUR_IN_SECONDS = 3600;
const NVX_SUPABASE_RELAY_QUEUE_MAX_BODY_BYTES = 32768;

final class WP_Error {
	public function __construct( private string $code, private string $message = '' ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
}
class WP_Post {
	public int $ID = 1;
	public string $post_type = 'nvx_relay_outbox';
	public string $post_status = 'draft';
	public string $post_content = '';
	public string $post_date_gmt = '';
}

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function sanitize_key( string $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $value ) ?? '' ); }
function sanitize_url( string $value ): string { return trim( $value ); }
function absint( mixed $value ): int { return abs( (int) $value ); }
function sanitize_text_field( mixed $value ): string { return trim( (string) $value ); }
function wp_slash( mixed $value ): string { return addslashes( (string) $value ); }
function wp_parse_url( string $url, int $component = -1 ): mixed { return parse_url( $url, $component ); }
function wp_remote_retrieve_response_code( mixed $response ): int {
	return ( is_array( $response ) && isset( $response['response']['code'] ) ) ? (int) $response['response']['code'] : 0;
}

$mock_actions = array();
$mock_filters = array();

function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
	global $mock_actions;
	$mock_actions[ $hook ][] = array(
		'callback'      => $callback,
		'priority'      => $priority,
		'accepted_args' => $accepted_args,
	);
	usort(
		$mock_actions[ $hook ],
		static fn( array $a, array $b ): int => $a['priority'] <=> $b['priority']
	);
}

function remove_action( string $hook, mixed $callback, int $priority = 10 ): bool {
	global $mock_actions;
	if ( ! isset( $mock_actions[ $hook ] ) ) {
		return false;
	}
	$mock_actions[ $hook ] = array_values(
		array_filter(
			$mock_actions[ $hook ],
			static fn( array $entry ): bool => ! ( $entry['callback'] === $callback && $entry['priority'] === $priority )
		)
	);
	return true;
}

function has_action( string $hook, mixed $callback = false ): bool|int {
	global $mock_actions;
	if ( empty( $mock_actions[ $hook ] ) ) {
		return false;
	}
	if ( false === $callback ) {
		return true;
	}
	foreach ( $mock_actions[ $hook ] as $entry ) {
		if ( $entry['callback'] === $callback ) {
			return $entry['priority'];
		}
	}
	return false;
}

function do_action( string $hook, mixed ...$args ): void {
	global $mock_actions;
	if ( empty( $mock_actions[ $hook ] ) ) {
		return;
	}
	foreach ( $mock_actions[ $hook ] as $entry ) {
		$callback   = $entry['callback'];
		$entry_args = array_slice( $args, 0, $entry['accepted_args'] );
		$callback( ...$entry_args );
	}
}

function wp_transition_post_status( string $new_status, string $old_status, mixed $post ): void {
	do_action( 'transition_post_status', $new_status, $old_status, $post );
}

function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
	global $mock_filters;
	$mock_filters[ $hook ][] = array(
		'callback'      => $callback,
		'priority'      => $priority,
		'accepted_args' => $accepted_args,
	);
}

function clean_post_cache( int $post_id ): void {}
function wp_cache_delete( mixed $key, string $group = '' ): bool { return true; }
function wp_cache_set( mixed $key, mixed $value, string $group = '', int $expire = 0 ): bool { return true; }
function register_post_type( mixed ...$args ): void {}
function wp_next_scheduled( mixed ...$args ): mixed { return false; }
function wp_schedule_event( mixed ...$args ): bool { return true; }
function wp_clear_scheduled_hook( mixed ...$args ): int { return 1; }

function wp_json_encode( mixed $value ): string|false { return json_encode( $value ); }
function nvx_supabase_relay_queue_endpoints(): array {
	return array(
		'google_click'  => 'https://example.test/google-click',
		'lead_captured' => 'https://example.test/lead-captured',
	);
}

$mock_options = array();

function get_option( string $key, mixed $default = false ): mixed {
	global $mock_options;
	return $mock_options[ $key ] ?? $default;
}

function update_option( string $key, mixed $value, mixed $autoload = null ): bool {
	global $mock_options;
	$mock_options[ $key ] = (string) $value;
	return true;
}

function add_option( string $key, mixed $value, string $deprecated = '', mixed $autoload = 'yes' ): bool {
	global $mock_options;
	if ( array_key_exists( $key, $mock_options ) ) {
		return false;
	}
	$mock_options[ $key ] = (string) $value;
	return true;
}

function delete_option( string $key ): bool {
	global $mock_options;
	unset( $mock_options[ $key ] );
	return true;
}

$mock_posts         = array();
$mock_deleted_posts = array();
$mock_relay_time    = 1700000000;

function nvx_supabase_relay_time(): int {
	global $mock_relay_time;
	return $mock_relay_time;
}

function get_post_meta( int $post_id, string $key = '', bool $single = false ): mixed {
	global $mock_posts;
	if ( '' === $key ) {
		return $mock_posts[ $post_id ]['meta'] ?? array();
	}
	$val = $mock_posts[ $post_id ]['meta'][ $key ] ?? '';
	return $single ? $val : ( '' !== $val ? array( $val ) : array() );
}

function update_post_meta( int $post_id, string $key, mixed $value ): bool {
	global $mock_posts;
	if ( ! isset( $mock_posts[ $post_id ] ) ) {
		$mock_posts[ $post_id ] = array(
			'post_type'   => 'nvx_relay_outbox',
			'post_status' => 'draft',
			'meta'        => array(),
		);
	}
	$mock_posts[ $post_id ]['meta'][ $key ] = (string) $value;
	return true;
}

function wp_delete_post( int $post_id, bool $force = false ): bool {
	global $mock_posts, $mock_deleted_posts;
	unset( $mock_posts[ $post_id ] );
	$mock_deleted_posts[] = $post_id;
	return true;
}

function get_posts( array $args = array() ): array {
	global $mock_posts;
	$results    = array();
	$status     = $args['post_status'] ?? 'publish';
	$post_type  = $args['post_type'] ?? 'post';
	$meta_query = $args['meta_query'] ?? array();

	foreach ( $mock_posts as $id => $data ) {
		if ( isset( $data['post_type'] ) && $data['post_type'] !== $post_type ) {
			continue;
		}
		if ( isset( $data['post_status'] ) && $data['post_status'] !== $status ) {
			continue;
		}
		$match = true;
		foreach ( $meta_query as $mq ) {
			$k       = $mq['key'] ?? '';
			$compare = $mq['compare'] ?? '=';
			$val     = $data['meta'][ $k ] ?? null;
			if ( 'NOT EXISTS' === $compare ) {
				if ( null !== $val && '' !== $val ) {
					$match = false;
					break;
				}
			} elseif ( '<=' === $compare ) {
				if ( null === $val || '' === $val ) {
					$match = false;
					break;
				}
				$target_val = $mq['value'] ?? 0;
				if ( (int) $val > (int) $target_val ) {
					$match = false;
					break;
				}
			}
		}
		if ( $match ) {
			$results[] = ( 'ids' === ( $args['fields'] ?? '' ) ) ? $id : (object) array( 'ID' => $id );
		}
	}
	$limit = $args['posts_per_page'] ?? -1;
	if ( $limit > 0 && count( $results ) > $limit ) {
		$results = array_slice( $results, 0, $limit );
	}
	return $results;
}

$mock_stale_cache_posts = array();

function get_post( mixed $post = null, string $output = 'OBJECT', string $filter = 'raw' ): ?WP_Post {
	global $mock_posts, $mock_stale_cache_posts;
	$post_id = $post instanceof WP_Post ? $post->ID : absint( $post );
	if ( $post_id < 1 || ! isset( $mock_posts[ $post_id ] ) ) {
		return null;
	}
	$p                = new WP_Post();
	$p->ID            = $post_id;
	$p->post_type     = $mock_posts[ $post_id ]['post_type'] ?? 'nvx_relay_outbox';
	$p->post_status   = $mock_stale_cache_posts[ $post_id ] ?? ( $mock_posts[ $post_id ]['post_status'] ?? 'draft' );
	$p->post_content  = $mock_posts[ $post_id ]['post_content'] ?? '';
	$p->post_date_gmt = $mock_posts[ $post_id ]['post_date_gmt'] ?? '';
	return $p;
}

function add_post_meta( int $post_id, string $key, mixed $value, bool $unique = false ): bool {
	global $mock_posts;
	if ( $unique && isset( $mock_posts[ $post_id ]['meta'][ $key ] ) ) {
		return false;
	}
	return update_post_meta( $post_id, $key, $value );
}

function wp_update_post( array $postarr = array(), bool $wp_error = false ): int|WP_Error {
	global $mock_posts;
	$id = (int) ( $postarr['ID'] ?? 0 );
	if ( $id < 1 || ! isset( $mock_posts[ $id ] ) ) {
		return $wp_error ? new WP_Error( 'invalid_post', 'Post not found.' ) : 0;
	}
	if ( isset( $postarr['post_status'] ) ) {
		$old_status                        = $mock_posts[ $id ]['post_status'] ?? '';
		$mock_posts[ $id ]['post_status'] = (string) $postarr['post_status'];
		$post                              = get_post( $id );
		if ( function_exists( 'wp_transition_post_status' ) ) {
			wp_transition_post_status( (string) $postarr['post_status'], $old_status, $post );
		}
	}
	return $id;
}

class MockWpdbDirectCas {
	public string $posts   = 'wp_posts';
	public string $options = 'wp_options';

	public function prepare( string $query, ...$args ): string {
		foreach ( $args as $arg ) {
			if ( is_int( $arg ) || ctype_digit( (string) $arg ) ) {
				if ( preg_match( '/%[ds]/', $query, $match, PREG_OFFSET_CAPTURE ) ) {
					$placeholder = $match[0][0];
					$offset      = $match[0][1];
					$val         = '%d' === $placeholder ? (string) (int) $arg : "'" . addslashes( (string) $arg ) . "'";
					$query       = substr_replace( $query, $val, $offset, 2 );
				}
			} else {
				if ( preg_match( '/%s/', $query, $match, PREG_OFFSET_CAPTURE ) ) {
					$offset = $match[0][1];
					$val    = "'" . addslashes( (string) $arg ) . "'";
					$query  = substr_replace( $query, $val, $offset, 2 );
				}
			}
		}
		return $query;
	}

	public function query( string $query ): int {
		global $mock_posts, $mock_options;
		// CAS on posts: UPDATE wp_posts SET post_status = %s WHERE ID = %d AND post_type = %s AND post_status = %s
		if ( preg_match( "/UPDATE wp_posts SET post_status = '([^']+)' WHERE ID = '?(\d+)'? AND post_type = '([^']+)' AND post_status = '([^']+)'/", $query, $m ) ) {
			$new_status      = $m[1];
			$post_id         = (int) $m[2];
			$expected_type   = $m[3];
			$expected_status = $m[4];

			if (
				isset( $mock_posts[ $post_id ] )
				&& ( $mock_posts[ $post_id ]['post_type'] ?? '' ) === $expected_type
				&& ( $mock_posts[ $post_id ]['post_status'] ?? '' ) === $expected_status
			) {
				$mock_posts[ $post_id ]['post_status'] = $new_status;
				return 1;
			}
			return 0;
		}

		// CAS on options: UPDATE wp_options SET option_value = %s WHERE option_name = %s AND option_value = %s
		if ( preg_match( "/UPDATE wp_options SET option_value = '([^']*)' WHERE option_name = '([^']+)' AND option_value = '([^']*)'/", $query, $m ) ) {
			$new_val  = $m[1];
			$key      = $m[2];
			$expected = $m[3];

			if ( ( $mock_options[ $key ] ?? '' ) === $expected ) {
				$mock_options[ $key ] = $new_val;
				return 1;
			}
			return 0;
		}

		return 0;
	}
}

global $wpdb;
$wpdb = new MockWpdbDirectCas();

require_once dirname( __DIR__, 2 ) . '/wp-content/themes/nuvanx-medical/inc/nvx-supabase-relay-operations.php';
require_once dirname( __DIR__, 2 ) . '/wp-content/themes/nuvanx-medical/inc/nvx-supabase-relay-queue.php';

$require = static function ( bool $condition, string $label ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL {$label}\n" );
		exit( 1 );
	}
	fwrite( STDOUT, "PASS {$label}\n" );
};

$small_click = json_encode( array( 'payload' => str_repeat( 'a', 8000 ) ), JSON_THROW_ON_ERROR );
$large_click = json_encode( array( 'payload' => str_repeat( 'a', 9000 ) ), JSON_THROW_ON_ERROR );
$large_lead  = json_encode( array( 'payload' => str_repeat( 'a', 9000 ) ), JSON_THROW_ON_ERROR );

$require( true === nvx_supabase_relay_validate_payload( 'google_click', $small_click ), 'GOOGLE_CLICK_UNDER_8192_ACCEPTED' );
$click_reject = nvx_supabase_relay_validate_payload( 'google_click', $large_click );
$require( is_wp_error( $click_reject ), 'GOOGLE_CLICK_OVER_8192_REJECTED' );
$require( 'nvx_relay_payload_too_large' === $click_reject->get_error_code(), 'GOOGLE_CLICK_REJECTION_REASON_STABLE' );
$require( true === nvx_supabase_relay_validate_payload( 'lead_captured', $large_lead ), 'LEAD_CAPTURED_USES_SHARED_32768_LIMIT' );

$invalid = nvx_supabase_relay_validate_payload( 'google_click', '{invalid-json' );
$require( is_wp_error( $invalid ) && 'nvx_relay_payload_invalid' === $invalid->get_error_code(), 'INVALID_JSON_REJECTED' );

$small_preflight = nvx_supabase_relay_operations_preflight_google_click(
	false,
	array(
		'method' => 'POST',
		'body'   => $small_click,
	),
	'https://example.test/google-click'
);
$require( false === $small_preflight, 'VALID_GOOGLE_CLICK_REACHES_NETWORK' );

$large_preflight = nvx_supabase_relay_operations_preflight_google_click(
	false,
	array(
		'method' => 'POST',
		'body'   => $large_click,
	),
	'https://example.test/google-click'
);
$require( is_array( $large_preflight ), 'OVERSIZED_GOOGLE_CLICK_PREEMPTED' );
$require( 422 === (int) ( $large_preflight['response']['code'] ?? 0 ), 'OVERSIZED_GOOGLE_CLICK_TERMINAL_HTTP_422' );

$invalid_preflight = nvx_supabase_relay_operations_preflight_google_click(
	false,
	array(
		'method' => 'POST',
		'body'   => '{invalid-json',
	),
	'https://example.test/google-click'
);
$require( 422 === (int) ( $invalid_preflight['response']['code'] ?? 0 ), 'INVALID_JSON_TERMINAL_HTTP_422' );

$other_endpoint = nvx_supabase_relay_operations_preflight_google_click(
	false,
	array(
		'method' => 'POST',
		'body'   => $large_click,
	),
	'https://example.test/other'
);
$require( false === $other_endpoint, 'UNRELATED_HTTP_REQUEST_UNTOUCHED' );

// Method bypass assertions (GET / HEAD bypass preflight even if body is empty or malformed)
$get_preflight = nvx_supabase_relay_operations_preflight_google_click(
	false,
	array(
		'method' => 'GET',
		'body'   => '',
	),
	'https://example.test/google-click'
);
$require( false === $get_preflight, 'GET_GOOGLE_CLICK_BYPASSES_PREFLIGHT' );

$head_preflight = nvx_supabase_relay_operations_preflight_google_click(
	false,
	array(
		'method' => 'HEAD',
	),
	'https://example.test/google-click'
);
$require( false === $head_preflight, 'HEAD_GOOGLE_CLICK_BYPASSES_PREFLIGHT' );

// Test dead transition stamping
$test_post            = new WP_Post();
$test_post->ID        = 10;
$test_post->post_type = 'nvx_relay_outbox';

// Valid dedupe and endpoint
$mock_posts[10] = array(
	'post_type'   => 'nvx_relay_outbox',
	'post_status' => 'pending',
	'meta'        => array(
		'_nvx_relay_endpoint'   => 'google_click',
		'_nvx_relay_dedupe_key' => str_repeat( 'a', 64 ),
	),
);
nvx_supabase_relay_operations_stamp_dead_transition( 'draft', 'pending', $test_post );
$require( (string) $mock_relay_time === ( $mock_posts[10]['meta']['_nvx_relay_dead_at'] ?? '' ), 'VALID_DEAD_TRANSITION_TIMESTAMPED' );

// Invalid dedupe key (must still be timestamped)
$test_post_corrupt            = new WP_Post();
$test_post_corrupt->ID        = 11;
$test_post_corrupt->post_type = 'nvx_relay_outbox';
$mock_posts[11] = array(
	'post_type'   => 'nvx_relay_outbox',
	'post_status' => 'pending',
	'meta'        => array(
		'_nvx_relay_endpoint'   => 'google_click',
		'_nvx_relay_dedupe_key' => 'corrupt-dedupe-key',
	),
);
nvx_supabase_relay_operations_stamp_dead_transition( 'draft', 'pending', $test_post_corrupt );
$require( (string) $mock_relay_time === ( $mock_posts[11]['meta']['_nvx_relay_dead_at'] ?? '' ), 'CORRUPT_DEDUPE_DEAD_TRANSITION_TIMESTAMPED' );

// Missing endpoint and dedupe (must still be timestamped)
$test_post_empty            = new WP_Post();
$test_post_empty->ID        = 12;
$test_post_empty->post_type = 'nvx_relay_outbox';
$mock_posts[12] = array(
	'post_type'   => 'nvx_relay_outbox',
	'post_status' => 'pending',
	'meta'        => array(),
);
nvx_supabase_relay_operations_stamp_dead_transition( 'draft', 'pending', $test_post_empty );
$require( (string) $mock_relay_time === ( $mock_posts[12]['meta']['_nvx_relay_dead_at'] ?? '' ), 'EMPTY_METADATA_DEAD_TRANSITION_TIMESTAMPED' );

// Wrong post type (must not be timestamped)
$test_post_page            = new WP_Post();
$test_post_page->ID        = 13;
$test_post_page->post_type = 'page';
$mock_posts[13] = array(
	'post_type'   => 'page',
	'post_status' => 'pending',
	'meta'        => array(),
);
nvx_supabase_relay_operations_stamp_dead_transition( 'draft', 'pending', $test_post_page );
$require( ! isset( $mock_posts[13]['meta']['_nvx_relay_dead_at'] ), 'UNRELATED_POST_TYPE_DEAD_TRANSITION_IGNORED' );

// Non-draft transition (must not be timestamped)
$test_post_pub            = new WP_Post();
$test_post_pub->ID        = 14;
$test_post_pub->post_type = 'nvx_relay_outbox';
$mock_posts[14] = array(
	'post_type'   => 'nvx_relay_outbox',
	'post_status' => 'pending',
	'meta'        => array(),
);
nvx_supabase_relay_operations_stamp_dead_transition( 'publish', 'pending', $test_post_pub );
$require( ! isset( $mock_posts[14]['meta']['_nvx_relay_dead_at'] ), 'NON_DRAFT_TRANSITION_IGNORED' );

// =========================================================================
// Runtime tests: direct-SQL CAS ($wpdb) and recovery quarantine dead stamping
// =========================================================================

// Test 1: Incomplete BUILDING row recovery
// Expired building row with incomplete metadata must be transitioned to draft
// via direct-SQL CAS ($wpdb) and immediately timestamped with _nvx_relay_dead_at
// via wp_transition_post_status -> operations stamp hook without manual calls.
$mock_posts[201] = array(
	'post_type'     => 'nvx_relay_outbox',
	'post_status'   => 'nvx_relay_building',
	'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $mock_relay_time - 120 ),
	'post_content'  => '',
	'meta'          => array(
		'_nvx_relay_endpoint'      => 'google_click',
		'_nvx_relay_publish_claim' => ( $mock_relay_time - 120 ) . '|claim201',
	),
);

$res_incomplete = nvx_supabase_relay_queue_recover_building_item( 201 );
$require( false === $res_incomplete, 'INCOMPLETE_BUILDING_RECOVERY_RETURNS_FALSE' );
$require( 'draft' === $mock_posts[201]['post_status'], 'INCOMPLETE_BUILDING_TRANSITIONS_TO_DRAFT' );
$require( (string) $mock_relay_time === ( $mock_posts[201]['meta']['_nvx_relay_dead_at'] ?? '' ), 'INCOMPLETE_BUILDING_DIRECT_SQL_DEAD_AT_TIMESTAMPED' );

// Test 2: Superseded BUILDING row recovery
// Expired building row with complete metadata superseded by a competitor post
// holding the claim option must transition to draft via direct-SQL CAS ($wpdb)
// and immediately receive _nvx_relay_dead_at timestamp via the action hook.
$body202       = '{"gclid":"test-gclid-superseded","source":"google"}';
$endpoint202   = 'google_click';
$origin202     = 'https://nuvanx.es';
$dedupe_key202 = nvx_supabase_relay_dedupe_key( $endpoint202, $body202, $origin202 );
$claim_key202  = nvx_supabase_relay_queue_claim_key( $dedupe_key202 );

// Active competitor item 203 holds the claim option and is valid pending
$mock_options[ $claim_key202 ] = '203';
$mock_posts[203] = array(
	'post_type'     => 'nvx_relay_outbox',
	'post_status'   => 'pending',
	'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $mock_relay_time - 60 ),
	'post_content'  => $body202,
	'meta'          => array(
		'_nvx_relay_endpoint'   => $endpoint202,
		'_nvx_relay_origin'     => $origin202,
		'_nvx_relay_dedupe_key' => $dedupe_key202,
		'_nvx_relay_ready'      => '1',
		'_nvx_relay_attempts'   => '1',
	),
);

// Expired building item 202 is superseded by item 203
$mock_posts[202] = array(
	'post_type'     => 'nvx_relay_outbox',
	'post_status'   => 'nvx_relay_building',
	'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $mock_relay_time - 120 ),
	'post_content'  => $body202,
	'meta'          => array(
		'_nvx_relay_endpoint'      => $endpoint202,
		'_nvx_relay_origin'        => $origin202,
		'_nvx_relay_dedupe_key'    => $dedupe_key202,
		'_nvx_relay_publish_claim' => ( $mock_relay_time - 120 ) . '|claim202',
		'_nvx_relay_next_attempt'  => (string) $mock_relay_time,
		'_nvx_relay_attempts'      => '1',
		'_nvx_relay_ready'         => '1',
	),
);

$res_superseded = nvx_supabase_relay_queue_recover_building_item( 202 );
$require( false === $res_superseded, 'SUPERSEDED_BUILDING_RECOVERY_RETURNS_FALSE' );
$require( 'draft' === $mock_posts[202]['post_status'], 'SUPERSEDED_BUILDING_TRANSITIONS_TO_DRAFT' );
$require( (string) $mock_relay_time === ( $mock_posts[202]['meta']['_nvx_relay_dead_at'] ?? '' ), 'SUPERSEDED_BUILDING_DIRECT_SQL_DEAD_AT_TIMESTAMPED' );

// Test 3: CAS failure does not dispatch transition_post_status or set dead_at
$mock_posts[204] = array(
	'post_type'     => 'nvx_relay_outbox',
	'post_status'   => 'pending',
	'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $mock_relay_time - 120 ),
	'post_content'  => '',
	'meta'          => array(
		'_nvx_relay_endpoint' => 'google_click',
	),
);
$cas_failed = nvx_supabase_relay_queue_compare_and_swap_status( 204, 'nvx_relay_building', 'draft' );
$require( false === $cas_failed, 'CAS_MISMATCH_RETURNS_FALSE' );
$require( 'pending' === $mock_posts[204]['post_status'], 'CAS_MISMATCH_LEAVES_STATUS_UNMODIFIED' );
$require( ! isset( $mock_posts[204]['meta']['_nvx_relay_dead_at'] ), 'CAS_MISMATCH_DOES_NOT_TIMESTAMP_DEAD_AT' );

// Test 4: Concurrent persistent-cache fill returning stale post status
// Direct-SQL CAS succeeds in DB, but get_post() observes stale pre-CAS status
// from a racing cache refill. The post snapshot must still dispatch transition_post_status,
// stamp _nvx_relay_dead_at, and return true aligned with the committed database mutation.
$mock_posts[205] = array(
	'post_type'     => 'nvx_relay_outbox',
	'post_status'   => 'nvx_relay_building',
	'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $mock_relay_time - 120 ),
	'post_content'  => '',
	'meta'          => array(
		'_nvx_relay_endpoint' => 'google_click',
	),
);

// Simulate persistent object cache returning stale status 'nvx_relay_building' after SQL update
$mock_stale_cache_posts[205] = 'nvx_relay_building';

$cas_stale_cache_res = nvx_supabase_relay_queue_compare_and_swap_status( 205, 'nvx_relay_building', 'draft' );
$require( true === $cas_stale_cache_res, 'STALE_CACHE_CAS_RETURNS_TRUE_ALIGNED_WITH_DB' );
$require( 'draft' === $mock_posts[205]['post_status'], 'STALE_CACHE_CAS_DB_STATUS_UPDATED' );
$require( (string) $mock_relay_time === ( $mock_posts[205]['meta']['_nvx_relay_dead_at'] ?? '' ), 'STALE_CACHE_CAS_DEAD_AT_TIMESTAMPED' );

unset( $mock_stale_cache_posts[205] );

// Test cleanup retention & untimestamped backfill
$mock_posts         = array();
$mock_deleted_posts = array();

// Post 101: expired dead letter (older than 30 days)
$mock_posts[101] = array(
	'post_type'   => 'nvx_relay_outbox',
	'post_status' => 'draft',
	'meta'        => array(
		'_nvx_relay_endpoint' => 'google_click',
		'_nvx_relay_dead_at'  => (string) ( $mock_relay_time - ( 31 * DAY_IN_SECONDS ) ),
	),
);

// Post 102: recent dead letter (5 days old)
$mock_posts[102] = array(
	'post_type'   => 'nvx_relay_outbox',
	'post_status' => 'draft',
	'meta'        => array(
		'_nvx_relay_endpoint' => 'google_click',
		'_nvx_relay_dead_at'  => (string) ( $mock_relay_time - ( 5 * DAY_IN_SECONDS ) ),
	),
);

// Post 103: orphaned dead letter without dead_at timestamp
$mock_posts[103] = array(
	'post_type'   => 'nvx_relay_outbox',
	'post_status' => 'draft',
	'meta'        => array(
		'_nvx_relay_endpoint' => 'google_click',
	),
);

nvx_supabase_relay_operations_cleanup_dead_letters();

$require( in_array( 101, $mock_deleted_posts, true ), 'EXPIRED_DEAD_LETTER_DELETED' );
$require( ! in_array( 102, $mock_deleted_posts, true ), 'UNEXPIRED_DEAD_LETTER_PRESERVED' );
$require( ! in_array( 103, $mock_deleted_posts, true ), 'ORPHAN_DEAD_LETTER_PRESERVED_UNTIL_RETENTION' );
$require( (string) $mock_relay_time === ( $mock_posts[103]['meta']['_nvx_relay_dead_at'] ?? '' ), 'ORPHAN_DEAD_LETTER_BACKFILLED_WITH_TIMESTAMP' );

// Fast-forward time past retention for post 103
$mock_relay_time += ( 31 * DAY_IN_SECONDS );
nvx_supabase_relay_operations_cleanup_dead_letters();
$require( in_array( 103, $mock_deleted_posts, true ), 'BACKFILLED_DEAD_LETTER_DELETED_AFTER_RETENTION' );

fwrite( STDOUT, "OUTBOX_PAYLOAD_LIMIT_RUNTIME=PASS google_click=8192 shared=32768 preflight=terminal_http_422\n" );

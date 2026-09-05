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
}

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function sanitize_key( string $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $value ) ?? '' ); }
function sanitize_url( string $value ): string { return trim( $value ); }
function absint( mixed $value ): int { return abs( (int) $value ); }
function add_action( mixed ...$args ): void { unset( $args ); }
function add_filter( mixed ...$args ): void { unset( $args ); }
function wp_json_encode( mixed $value ): string|false { return json_encode( $value ); }
function nvx_supabase_relay_queue_endpoints(): array { return array( 'google_click' => 'https://example.test/google-click' ); }

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

require_once dirname( __DIR__, 2 ) . '/wp-content/themes/nuvanx-medical/inc/nvx-supabase-relay-operations.php';

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

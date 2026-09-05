<?php
/**
 * Operational policy owner for the Supabase relay outbox.
 *
 * The queue module remains the sole owner of publication, fencing, dispatch,
 * transport classification and retry orchestration. This module owns only
 * cross-cutting operational policy: endpoint payload ceilings, bounded
 * telemetry, non-blocking shutdown scheduling and dead-letter retention.
 *
 * @package nuvanx-medical
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'NVX_SUPABASE_RELAY_QUEUE_CPT' ) ) {
	define( 'NVX_SUPABASE_RELAY_QUEUE_CPT', 'nvx_relay_outbox' );
}
if ( ! defined( 'NVX_SUPABASE_RELAY_QUEUE_FAST_CRON' ) ) {
	define( 'NVX_SUPABASE_RELAY_QUEUE_FAST_CRON', 'nvx_supabase_relay_queue_fast_drain' );
}
if ( ! defined( 'NVX_SUPABASE_RELAY_QUEUE_CLEANUP_CRON' ) ) {
	define( 'NVX_SUPABASE_RELAY_QUEUE_CLEANUP_CRON', 'nvx_supabase_relay_queue_cleanup_dead_letters' );
}
if ( ! defined( 'NVX_SUPABASE_RELAY_GOOGLE_CLICK_MAX_BODY_BYTES' ) ) {
	define( 'NVX_SUPABASE_RELAY_GOOGLE_CLICK_MAX_BODY_BYTES', 8192 );
}
if ( ! defined( 'NVX_SUPABASE_RELAY_DEAD_RETENTION_SECONDS' ) ) {
	define( 'NVX_SUPABASE_RELAY_DEAD_RETENTION_SECONDS', 30 * DAY_IN_SECONDS );
}
if ( ! defined( 'NVX_SUPABASE_RELAY_DEAD_CLEANUP_BATCH' ) ) {
	define( 'NVX_SUPABASE_RELAY_DEAD_CLEANUP_BATCH', 100 );
}

/** Emit bounded operational telemetry without payloads, identities or tokens. */
function nvx_supabase_relay_operational_log( string $event, string $endpoint = 'system', int $value = 0 ): void {
	$event    = sanitize_key( $event );
	$endpoint = sanitize_key( $endpoint );
	$allowed  = array(
		'payload_rejected',
		'stale_building_recovered',
		'stale_building_quarantined',
		'fast_drain_scheduled',
		'dead_timestamped',
		'dead_cleanup_deleted',
	);
	if ( ! in_array( $event, $allowed, true ) || '' === $endpoint ) {
		return;
	}

	nvx_observability_log(
		'supabase_relay_ops',
		$event,
		array(
			'endpoint' => $endpoint,
			'value'    => $value > 0 ? absint( $value ) : null,
		)
	);
}

/** Validate one relay payload against the generic and endpoint-specific ceilings. */
function nvx_supabase_relay_validate_payload( string $endpoint, string $body ): bool|WP_Error {
	$endpoint      = sanitize_key( $endpoint );
	$generic_limit = defined( 'NVX_SUPABASE_RELAY_QUEUE_MAX_BODY_BYTES' )
		? (int) NVX_SUPABASE_RELAY_QUEUE_MAX_BODY_BYTES
		: 32768;
	$limit         = 'google_click' === $endpoint
		? min( $generic_limit, (int) NVX_SUPABASE_RELAY_GOOGLE_CLICK_MAX_BODY_BYTES )
		: $generic_limit;

	if ( '' === $body ) {
		return new WP_Error( 'nvx_relay_payload_invalid', 'Relay payload is empty.' );
	}
	if ( strlen( $body ) > $limit ) {
		return new WP_Error( 'nvx_relay_payload_too_large', 'Relay payload exceeds the endpoint ceiling.' );
	}
	json_decode( $body, true );
	if ( JSON_ERROR_NONE !== json_last_error() ) {
		return new WP_Error( 'nvx_relay_payload_invalid', 'Relay payload is not valid JSON.' );
	}
	return true;
}

/**
 * Return a terminal synthetic HTTP response for deterministic Google payload defects.
 *
 * The canonical queue remains the only classifier. Its existing HTTP 4xx branch is
 * non-retryable, so this boundary prevents both immediate network I/O and requeueing
 * without replacing nvx_supabase_relay_classify(), dispatch() or queue_send().
 *
 * @param mixed               $preempt Preemptive HTTP value.
 * @param array<string,mixed> $parsed_args HTTP request arguments.
 * @return mixed
 */
function nvx_supabase_relay_operations_preflight_google_click( mixed $preempt, array $parsed_args, string $url ): mixed {
	if ( false !== $preempt || ! function_exists( 'nvx_supabase_relay_queue_endpoints' ) ) {
		return $preempt;
	}
	$endpoints = nvx_supabase_relay_queue_endpoints();
	$target    = isset( $endpoints['google_click'] ) ? sanitize_url( (string) $endpoints['google_click'] ) : '';
	if ( '' === $target || $target !== sanitize_url( $url ) ) {
		return $preempt;
	}
	$method = isset( $parsed_args['method'] ) ? strtoupper( (string) $parsed_args['method'] ) : 'POST';
	if ( 'POST' !== $method ) {
		return $preempt;
	}

	$body = $parsed_args['body'] ?? '';
	if ( is_array( $body ) ) {
		$encoded = wp_json_encode( $body );
		$body    = is_string( $encoded ) ? $encoded : '';
	} elseif ( ! is_string( $body ) ) {
		$body = '';
	}
	$validation = nvx_supabase_relay_validate_payload( 'google_click', $body );
	if ( ! is_wp_error( $validation ) ) {
		return $preempt;
	}

	nvx_supabase_relay_operational_log( 'payload_rejected', 'google_click' );
	return array(
		'headers'  => array(),
		'body'     => '',
		'response' => array(
			'code'    => 422,
			'message' => 'Unprocessable Entity',
		),
		'cookies'  => array(),
		'filename' => null,
	);
}
add_filter( 'pre_http_request', 'nvx_supabase_relay_operations_preflight_google_click', 5, 3 );

/** Observe expired BUILDING transitions without changing publication ownership. */
function nvx_supabase_relay_operations_observe_status_transition( string $new_status, string $old_status, WP_Post $post ): void {
	if ( NVX_SUPABASE_RELAY_QUEUE_CPT !== $post->post_type || 'nvx_relay_building' !== $old_status || $new_status === $old_status ) {
		return;
	}
	$claim = (string) get_post_meta( absint( $post->ID ), '_nvx_relay_publish_claim', true );
	if ( '' === $claim || ! function_exists( 'nvx_supabase_relay_queue_publish_claim_live' ) || nvx_supabase_relay_queue_publish_claim_live( $claim ) ) {
		return;
	}
	$endpoint = sanitize_key( (string) get_post_meta( absint( $post->ID ), '_nvx_relay_endpoint', true ) );
	if ( '' === $endpoint ) {
		$endpoint = 'system';
	}
	$event = 'nvx_relay_prepared' === $new_status ? 'stale_building_recovered' : 'stale_building_quarantined';
	nvx_supabase_relay_operational_log( $event, $endpoint );
}
add_action( 'transition_post_status', 'nvx_supabase_relay_operations_observe_status_transition', 10, 3 );

/** Timestamp the actual transition into the queue's terminal draft state. */
function nvx_supabase_relay_operations_stamp_dead_transition( string $new_status, string $old_status, WP_Post $post ): void {
	if ( NVX_SUPABASE_RELAY_QUEUE_CPT !== $post->post_type || 'draft' !== $new_status || 'draft' === $old_status ) {
		return;
	}
	$endpoint = sanitize_key( (string) get_post_meta( absint( $post->ID ), '_nvx_relay_endpoint', true ) );
	if ( '' === $endpoint ) {
		$endpoint = 'system';
	}
	$time = function_exists( 'nvx_supabase_relay_time' ) ? nvx_supabase_relay_time() : time();
	update_post_meta( absint( $post->ID ), '_nvx_relay_dead_at', (string) $time );
	nvx_supabase_relay_operational_log( 'dead_timestamped', $endpoint );
}
add_action( 'transition_post_status', 'nvx_supabase_relay_operations_stamp_dead_transition', 20, 3 );

/** Cron performs the network drain; request shutdown never does. */
function nvx_supabase_relay_operations_fast_drain(): void {
	if ( function_exists( 'nvx_supabase_relay_queue_drain' ) ) {
		nvx_supabase_relay_queue_drain( 1 );
	}
}
add_action( NVX_SUPABASE_RELAY_QUEUE_FAST_CRON, 'nvx_supabase_relay_operations_fast_drain' );

/** Schedule one fast drain after a request created queue work. */
function nvx_supabase_relay_operations_shutdown_schedule(): void {
	if ( empty( $GLOBALS['nvx_supabase_relay_queue_dirty'] ) || wp_next_scheduled( NVX_SUPABASE_RELAY_QUEUE_FAST_CRON ) ) {
		return;
	}
	if ( true === wp_schedule_single_event( time() + 5, NVX_SUPABASE_RELAY_QUEUE_FAST_CRON ) ) {
		nvx_supabase_relay_operational_log( 'fast_drain_scheduled' );
	}
}

/** Replace the queue's legacy blocking shutdown callback after all modules load. */
function nvx_supabase_relay_operations_rewire_shutdown(): void {
	remove_action( 'shutdown', 'nvx_supabase_relay_queue_shutdown_drain' );
	if ( false === has_action( 'shutdown', 'nvx_supabase_relay_operations_shutdown_schedule' ) ) {
		add_action( 'shutdown', 'nvx_supabase_relay_operations_shutdown_schedule', 10 );
	}
}
add_action( 'init', 'nvx_supabase_relay_operations_rewire_shutdown', -1000 );

/** Schedule dead-letter retention once per day. */
function nvx_supabase_relay_operations_schedule_cleanup(): void {
	if ( wp_next_scheduled( NVX_SUPABASE_RELAY_QUEUE_CLEANUP_CRON ) ) {
		return;
	}
	wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', NVX_SUPABASE_RELAY_QUEUE_CLEANUP_CRON );
}
add_action( 'init', 'nvx_supabase_relay_operations_schedule_cleanup', 21 );

/** Delete only terminal rows whose terminal timestamp exceeded retention. */
function nvx_supabase_relay_operations_cleanup_dead_letters(): void {
	$now    = function_exists( 'nvx_supabase_relay_time' ) ? nvx_supabase_relay_time() : time();
	$cutoff = $now - (int) NVX_SUPABASE_RELAY_DEAD_RETENTION_SECONDS;
	$ids    = get_posts(
		array(
			'post_type'              => NVX_SUPABASE_RELAY_QUEUE_CPT,
			'post_status'            => 'draft',
			'posts_per_page'         => (int) NVX_SUPABASE_RELAY_DEAD_CLEANUP_BATCH,
			'fields'                 => 'ids',
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'meta_query'             => array(
				array(
					'key'     => '_nvx_relay_dead_at',
					'value'   => (string) $cutoff,
					'compare' => '<=',
					'type'    => 'NUMERIC',
				),
			),
		)
	);

	$deleted = 0;
	foreach ( $ids as $candidate_id ) {
		$post_id    = absint( $candidate_id );
		$dedupe_key = (string) get_post_meta( $post_id, '_nvx_relay_dedupe_key', true );
		if ( 1 === preg_match( '/\A[a-f0-9]{64}\z/', $dedupe_key ) && function_exists( 'nvx_supabase_relay_queue_claim_key' ) ) {
			$claim_key = nvx_supabase_relay_queue_claim_key( $dedupe_key );
			$current   = function_exists( 'nvx_supabase_relay_queue_fresh_option' )
				? nvx_supabase_relay_queue_fresh_option( $claim_key )
				: (string) get_option( $claim_key, '' );
			$claim_is_candidate = (string) $post_id === $current;
			$claim_is_expired   = '' !== $current
				&& ! ctype_digit( $current )
				&& function_exists( 'nvx_supabase_relay_queue_publish_claim_live' )
				&& ! nvx_supabase_relay_queue_publish_claim_live( $current );
			if ( ( $claim_is_candidate || $claim_is_expired ) && function_exists( 'nvx_supabase_relay_queue_release_claim' ) ) {
				nvx_supabase_relay_queue_release_claim( $dedupe_key, $current );
			}
		}
		if ( false !== wp_delete_post( $post_id, true ) ) {
			++$deleted;
		}
	}
	if ( $deleted > 0 ) {
		nvx_supabase_relay_operational_log( 'dead_cleanup_deleted', 'system', $deleted );
	}

	$untimestamped = get_posts(
		array(
			'post_type'              => NVX_SUPABASE_RELAY_QUEUE_CPT,
			'post_status'            => 'draft',
			'posts_per_page'         => (int) NVX_SUPABASE_RELAY_DEAD_CLEANUP_BATCH,
			'fields'                 => 'ids',
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'meta_query'             => array(
				array(
					'key'     => '_nvx_relay_dead_at',
					'compare' => 'NOT EXISTS',
				),
			),
		)
	);
	foreach ( $untimestamped as $untimestamped_id ) {
		$post_id  = absint( $untimestamped_id );
		$endpoint = sanitize_key( (string) get_post_meta( $post_id, '_nvx_relay_endpoint', true ) );
		if ( '' === $endpoint ) {
			$endpoint = 'system';
		}
		update_post_meta( $post_id, '_nvx_relay_dead_at', (string) $now );
		nvx_supabase_relay_operational_log( 'dead_timestamped', $endpoint );
	}
}
add_action( NVX_SUPABASE_RELAY_QUEUE_CLEANUP_CRON, 'nvx_supabase_relay_operations_cleanup_dead_letters' );

/** Remove every operational schedule when the theme is switched. */
function nvx_supabase_relay_operations_unschedule(): void {
	wp_clear_scheduled_hook( NVX_SUPABASE_RELAY_QUEUE_FAST_CRON );
	wp_clear_scheduled_hook( NVX_SUPABASE_RELAY_QUEUE_CLEANUP_CRON );
}
add_action( 'switch_theme', 'nvx_supabase_relay_operations_unschedule' );
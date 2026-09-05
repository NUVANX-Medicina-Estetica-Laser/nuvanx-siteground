<?php
/**
 * Operational policy owner for the Supabase relay outbox.
 *
 * The queue module owns durable publication, fencing and at-least-once delivery.
 * This module owns operational policy that must not be mixed into that protocol:
 * endpoint payload ceilings, bounded telemetry, shutdown scheduling and
 * dead-letter retention.
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

if ( ! defined( 'NVX_SUPABASE_RELAY_QUEUE_CRON' ) ) {
	define( 'NVX_SUPABASE_RELAY_QUEUE_CRON', 'nvx_supabase_relay_queue_drain' );
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

/** Emit bounded operational telemetry without payloads, identifiers or PII. */
function nvx_supabase_relay_operational_log( string $event, string $endpoint = 'system', int $value = 0 ): void {
	$event    = sanitize_key( $event );
	$endpoint = sanitize_key( $endpoint );
	$allowed  = array(
		'dedupe_reuse',
		'lease_lost',
		'stale_building_recovered',
		'stale_building_quarantined',
		'retry_state_conflict',
		'payload_rejected',
		'fast_drain_scheduled',
		'dead_cleanup_deleted',
	);
	if ( ! in_array( $event, $allowed, true ) || '' === $endpoint ) {
		return;
	}
	$line = 'NVX_RELAY_OP event=' . $event . ' endpoint=' . $endpoint;
	if ( $value > 0 ) {
		$line .= ' value=' . absint( $value );
	}
	error_log( $line );
}

/** Validate one relay payload against generic and endpoint-specific ceilings. */
function nvx_supabase_relay_validate_payload( string $endpoint, string $body ): true|WP_Error {
	$endpoint = sanitize_key( $endpoint );
	$generic_limit = defined( 'NVX_SUPABASE_RELAY_QUEUE_MAX_BODY_BYTES' )
		? (int) NVX_SUPABASE_RELAY_QUEUE_MAX_BODY_BYTES
		: 32768;
	$limit = 'google_click' === $endpoint
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

/** Classify deterministic payload defects as terminal rather than retryable transport failures. */
if ( ! function_exists( 'nvx_supabase_relay_classify' ) ) {
	function nvx_supabase_relay_classify( mixed $response ): array {
		if ( is_wp_error( $response ) ) {
			$code = sanitize_key( (string) $response->get_error_code() );
			if ( in_array( $code, array( 'nvx_relay_payload_too_large', 'nvx_relay_payload_invalid' ), true ) ) {
				return array( 'outcome' => 'HTTP_4XX', 'status' => 0, 'retryable' => false, 'reason' => $code );
			}
			return array( 'outcome' => 'TRANSPORT', 'status' => 0, 'retryable' => true, 'reason' => $code );
		}
		$status = absint( wp_remote_retrieve_response_code( $response ) );
		if ( $status >= 200 && $status < 300 ) {
			return array( 'outcome' => 'SUCCESS', 'status' => $status, 'retryable' => false, 'reason' => '' );
		}
		if ( 429 === $status ) {
			return array( 'outcome' => 'HTTP_429', 'status' => $status, 'retryable' => true, 'reason' => 'rate_limited' );
		}
		if ( $status >= 500 ) {
			return array( 'outcome' => 'HTTP_5XX', 'status' => $status, 'retryable' => true, 'reason' => 'http_5xx' );
		}
		if ( $status >= 400 ) {
			return array( 'outcome' => 'HTTP_4XX', 'status' => $status, 'retryable' => false, 'reason' => 'http_4xx' );
		}
		return array( 'outcome' => 'TRANSPORT', 'status' => $status, 'retryable' => true, 'reason' => 'empty_status' );
	}
}

/** Send one persisted payload with endpoint policy enforced before network I/O. */
if ( ! function_exists( 'nvx_supabase_relay_queue_send' ) ) {
	function nvx_supabase_relay_queue_send( string $endpoint, string $body, string $origin = '', bool $force_bootstrap = false ): array|WP_Error {
		$endpoint   = sanitize_key( $endpoint );
		$validation = nvx_supabase_relay_validate_payload( $endpoint, $body );
		if ( is_wp_error( $validation ) ) {
			nvx_supabase_relay_operational_log( 'payload_rejected', $endpoint );
			return $validation;
		}
		$endpoints = nvx_supabase_relay_queue_endpoints();
		$url       = isset( $endpoints[ $endpoint ] ) ? sanitize_url( (string) $endpoints[ $endpoint ] ) : '';
		if ( '' === $url ) {
			return new WP_Error( 'nvx_relay_endpoint_missing', 'Relay endpoint is unavailable.' );
		}
		$token     = nvx_supabase_relay_google_click_token();
		$bootstrap = nvx_supabase_relay_ensure_runtime_bootstrap( $token, $force_bootstrap );
		if ( is_wp_error( $bootstrap ) ) {
			return $bootstrap;
		}
		if ( 'lead_captured' === $endpoint ) {
			if ( ! function_exists( 'nvx_lead_captured_post_signed' ) ) {
				return new WP_Error( 'nvx_lead_capture_transport_missing', 'Lead capture signed transport is unavailable.' );
			}
			return nvx_lead_captured_post_signed( $body, $token );
		}
		if ( 'google_click' === $endpoint ) {
			$origin = nvx_supabase_relay_sanitize_origin( $origin );
			if ( '' === $origin ) {
				return new WP_Error( 'nvx_relay_origin_missing', 'Google Click relay origin is unavailable.' );
			}
			return nvx_supabase_relay_google_click_post_signed( $url, $body, $origin, $token );
		}
		return new WP_Error( 'nvx_relay_endpoint_unsupported', 'Unsupported relay endpoint.' );
	}
}

/** Dispatch synchronously and never persist deterministic invalid payloads. */
if ( ! function_exists( 'nvx_supabase_relay_dispatch' ) ) {
	function nvx_supabase_relay_dispatch( string $endpoint, string $body, array $headers = array() ): array {
		$endpoint   = sanitize_key( $endpoint );
		$validation = nvx_supabase_relay_validate_payload( $endpoint, $body );
		if ( is_wp_error( $validation ) ) {
			$reason = sanitize_key( (string) $validation->get_error_code() );
			nvx_supabase_relay_operational_log( 'payload_rejected', $endpoint );
			nvx_supabase_relay_log( $endpoint, 'HTTP_4XX', 0, $reason );
			return array( 'outcome' => 'HTTP_4XX', 'status' => 0, 'queued' => 0 );
		}

		$origin = isset( $headers['Origin'] ) ? nvx_supabase_relay_sanitize_origin( (string) $headers['Origin'] ) : '';
		try {
			$response = nvx_supabase_relay_queue_send( $endpoint, $body, $origin );
		} catch ( Throwable $error ) {
			unset( $error );
			$response = new WP_Error( 'nvx_relay_unexpected_transport', 'Relay transport failed unexpectedly.' );
		}
		$class             = nvx_supabase_relay_classify( $response );
		$delivery_attempts = 1;
		if ( 401 === $class['status'] ) {
			try {
				$retry_response = nvx_supabase_relay_queue_send( $endpoint, $body, $origin, true );
			} catch ( Throwable $retry_error ) {
				unset( $retry_error );
				$retry_response = new WP_Error( 'nvx_relay_unexpected_transport', 'Relay transport failed unexpectedly.' );
			}
			$class             = nvx_supabase_relay_classify( $retry_response );
			$delivery_attempts = ( is_wp_error( $retry_response ) && 'nvx_runtime_bootstrap_unavailable' === $retry_response->get_error_code() ) ? 1 : 2;
		}
		nvx_supabase_relay_log( $endpoint, $class['outcome'], $class['status'], $class['reason'] );
		$queued = 0;
		if ( $class['retryable'] ) {
			$queued = nvx_supabase_relay_queue_enqueue( $endpoint, $body, array( 'Origin' => $origin ), $delivery_attempts );
		}
		return array( 'outcome' => $class['outcome'], 'status' => $class['status'], 'queued' => $queued );
	}
}

/** Record dedupe reuse and retry-state conflicts without exposing queue identity. */
if ( ! function_exists( 'nvx_supabase_relay_queue_record_existing_attempt' ) ) {
	function nvx_supabase_relay_queue_record_existing_attempt( int $existing, string $endpoint, int $attempts ): int {
		nvx_supabase_relay_operational_log( 'dedupe_reuse', $endpoint );
		$new_attempts = nvx_supabase_relay_queue_atomic_add_attempts( $existing, $attempts );
		if ( null === $new_attempts ) {
			nvx_supabase_relay_operational_log( 'retry_state_conflict', $endpoint );
			nvx_supabase_relay_log( $endpoint, 'QUEUED', 0, 'attempt_state_write_failed' );
			return $existing;
		}
		if ( $new_attempts >= (int) NVX_SUPABASE_RELAY_QUEUE_MAX_TRIES ) {
			nvx_supabase_relay_queue_mark_dead( $existing, $endpoint, 0, 'max_retries_exceeded' );
		} else {
			$next_attempt = time() + nvx_supabase_relay_queue_backoff_seconds( $new_attempts );
			if ( ! nvx_supabase_relay_queue_set_next_attempt_monotonic( $existing, $next_attempt ) ) {
				nvx_supabase_relay_operational_log( 'retry_state_conflict', $endpoint );
				nvx_supabase_relay_log( $endpoint, 'QUEUED', 0, 'next_attempt_write_failed' );
				return $existing;
			}
		}
		return $existing;
	}
}

/** Report drain lease loss at the ownership check without logging the token. */
if ( ! function_exists( 'nvx_supabase_relay_queue_lock_owned' ) ) {
	function nvx_supabase_relay_queue_lock_owned( string $token ): bool {
		$key     = 'nvx_supabase_relay_drain_lock_v1';
		$current = nvx_supabase_relay_queue_fresh_option( $key );
		$parts   = explode( '|', $current, 2 );
		$expiry  = isset( $parts[0] ) ? absint( $parts[0] ) : 0;
		$owner   = isset( $parts[1] ) ? (string) $parts[1] : '';
		$owned   = $owner === $token && $expiry > nvx_supabase_relay_time();
		if ( ! $owned ) {
			nvx_supabase_relay_operational_log( 'lease_lost' );
		}
		return $owned;
	}
}

/** Preserve a terminal timestamp so dead letters have an explicit retention owner. */
if ( ! function_exists( 'nvx_supabase_relay_queue_mark_dead' ) ) {
	function nvx_supabase_relay_queue_mark_dead( int $post_id, string $endpoint, int $status, string $reason ): void {
		if ( nvx_supabase_relay_queue_complete_terminal_state( $post_id, $endpoint, $status, $reason, false ) ) {
			update_post_meta( absint( $post_id ), '_nvx_relay_dead_at', (string) nvx_supabase_relay_time() );
		}
	}
}

/** Observe expired BUILDING transitions without changing the publication protocol. */
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

/** Fast drain owner: cron performs the I/O; request shutdown only schedules it. */
function nvx_supabase_relay_queue_fast_drain(): void {
	if ( function_exists( 'nvx_supabase_relay_queue_drain' ) ) {
		nvx_supabase_relay_queue_drain( 1 );
	}
}
add_action( NVX_SUPABASE_RELAY_QUEUE_FAST_CRON, 'nvx_supabase_relay_queue_fast_drain' );

/** Opportunistic request shutdown schedules a bounded drain instead of blocking on network I/O. */
if ( ! function_exists( 'nvx_supabase_relay_queue_shutdown_drain' ) ) {
	function nvx_supabase_relay_queue_shutdown_drain(): void {
		if ( empty( $GLOBALS['nvx_supabase_relay_queue_dirty'] ) || wp_next_scheduled( NVX_SUPABASE_RELAY_QUEUE_FAST_CRON ) ) {
			return;
		}
		if ( true === wp_schedule_single_event( time() + 5, NVX_SUPABASE_RELAY_QUEUE_FAST_CRON ) ) {
			nvx_supabase_relay_operational_log( 'fast_drain_scheduled' );
		}
	}
}

/** Schedule dead-letter retention once per day. */
function nvx_supabase_relay_operations_schedule_cleanup(): void {
	if ( wp_next_scheduled( NVX_SUPABASE_RELAY_QUEUE_CLEANUP_CRON ) ) {
		return;
	}
	wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', NVX_SUPABASE_RELAY_QUEUE_CLEANUP_CRON );
}
add_action( 'init', 'nvx_supabase_relay_operations_schedule_cleanup', 21 );

/** Delete only bounded, terminal draft outbox rows older than the retention window. */
function nvx_supabase_relay_queue_cleanup_dead_letters(): void {
	$cutoff = nvx_supabase_relay_time() - (int) NVX_SUPABASE_RELAY_DEAD_RETENTION_SECONDS;
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
			'date_query'             => array(
				array(
					'column'    => 'post_date_gmt',
					'before'    => gmdate( 'Y-m-d H:i:s', $cutoff ),
					'inclusive' => true,
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
			$claim_is_expired   = '' !== $current && ! ctype_digit( $current ) && function_exists( 'nvx_supabase_relay_queue_publish_claim_live' ) && ! nvx_supabase_relay_queue_publish_claim_live( $current );
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
}
add_action( NVX_SUPABASE_RELAY_QUEUE_CLEANUP_CRON, 'nvx_supabase_relay_queue_cleanup_dead_letters' );

/** Remove every operational schedule when the theme is switched. */
function nvx_supabase_relay_operations_unschedule(): void {
	wp_clear_scheduled_hook( NVX_SUPABASE_RELAY_QUEUE_FAST_CRON );
	wp_clear_scheduled_hook( NVX_SUPABASE_RELAY_QUEUE_CLEANUP_CRON );
}
add_action( 'switch_theme', 'nvx_supabase_relay_operations_unschedule' );

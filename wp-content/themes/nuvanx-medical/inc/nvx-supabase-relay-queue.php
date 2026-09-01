<?php
/**
 * Persistent at-least-once outbox for canonical Supabase relays.
 *
 * HubSpot remains authoritative for the patient response. A collector failure
 * never changes an already accepted HubSpot submission; it is classified and
 * retried from this private outbox.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const NVX_SUPABASE_RELAY_QUEUE_CPT       = 'nvx_relay_outbox';
const NVX_SUPABASE_RELAY_QUEUE_CRON      = 'nvx_supabase_relay_queue_drain';
const NVX_SUPABASE_RELAY_QUEUE_MAX_TRIES = 8;
const NVX_SUPABASE_RELAY_QUEUE_BATCH     = 10;
const NVX_GOOGLE_CLICK_HMAC_CONTEXT      = 'nuvanx-google-click-attribution-hmac-key-v1';

/**
 * Allowed relay endpoints that may be queued.
 *
 * @return array<string,string>
 */
function nvx_supabase_relay_queue_endpoints(): array {
	return array(
		'google_click'  => function_exists( 'nvx_attribution_collector_canonical_endpoint' )
			? nvx_attribution_collector_canonical_endpoint()
			: '',
		'lead_captured' => function_exists( 'nvx_lead_captured_endpoint' )
			? nvx_lead_captured_endpoint()
			: '',
	);
}

/** Register a private, non-queryable outbox post type. */
function nvx_supabase_relay_queue_register_cpt(): void {
	register_post_type(
		NVX_SUPABASE_RELAY_QUEUE_CPT,
		array(
			'labels'              => array(
				'name'          => 'Supabase relay outbox',
				'singular_name' => 'Supabase relay item',
			),
			'public'              => false,
			'publicly_queryable'  => false,
			'show_ui'             => false,
			'show_in_menu'        => false,
			'show_in_rest'        => false,
			'exclude_from_search' => true,
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'capability_type'     => 'post',
			'supports'            => array( 'title' ),
		)
	);
}
add_action( 'init', 'nvx_supabase_relay_queue_register_cpt' );

/**
 * Add a five-minute schedule for outbox drainage.
 *
 * @param array<string,array<string,mixed>> $schedules Existing cron schedules.
 * @return array<string,array<string,mixed>>
 */
function nvx_supabase_relay_queue_cron_schedules( array $schedules ): array {
	if ( ! isset( $schedules['nvx_relay_outbox_5min'] ) ) {
		$schedules['nvx_relay_outbox_5min'] = array(
			'interval' => 300,
			'display'  => 'Every five minutes (NUVANX relay outbox)',
		);
	}
	return $schedules;
}
add_filter( 'cron_schedules', 'nvx_supabase_relay_queue_cron_schedules' );

function nvx_supabase_relay_queue_schedule_cron(): void {
	if ( ! wp_next_scheduled( NVX_SUPABASE_RELAY_QUEUE_CRON ) ) {
		wp_schedule_event( time() + 60, 'nvx_relay_outbox_5min', NVX_SUPABASE_RELAY_QUEUE_CRON );
	}
}
add_action( 'init', 'nvx_supabase_relay_queue_schedule_cron' );

function nvx_supabase_relay_queue_unschedule_cron(): void {
	$timestamp = wp_next_scheduled( NVX_SUPABASE_RELAY_QUEUE_CRON );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, NVX_SUPABASE_RELAY_QUEUE_CRON );
	}
}
add_action( 'switch_theme', 'nvx_supabase_relay_queue_unschedule_cron' );

/**
 * Emit bounded, non-PII relay telemetry.
 *
 * Outcomes are the classification vocabulary in AGENTS.md §2:
 * SUCCESS, HTTP_4xx, HTTP_429, HTTP_5xx, TRANSPORT, QUEUED, DRAINED, DEAD.
 */
function nvx_supabase_relay_log( string $endpoint, string $outcome, int $status = 0, string $reason = '' ): void {
	$endpoint = preg_replace( '/[^a-z0-9_]/', '', strtolower( $endpoint ) );
	$outcome  = strtoupper( $outcome );
	$allowed  = array( 'SUCCESS', 'HTTP_4XX', 'HTTP_429', 'HTTP_5XX', 'TRANSPORT', 'QUEUED', 'DRAINED', 'DEAD' );
	if ( '' === $endpoint || ! in_array( $outcome, $allowed, true ) ) {
		return;
	}
	$line = 'NVX_SUPABASE_RELAY=' . $outcome . ' endpoint=' . $endpoint;
	if ( $status > 0 ) {
		$line .= ' status=' . $status;
	}
	if ( '' !== $reason ) {
		$safe_reason = preg_replace( '/[^a-z0-9_]/', '', strtolower( $reason ) );
		if ( is_string( $safe_reason ) && '' !== $safe_reason ) {
			$line .= ' reason=' . $safe_reason;
		}
	}
	error_log( $line );
}

/**
 * Classify a WordPress HTTP response for a Supabase relay.
 *
 * @param mixed $response wp_remote_* result.
 * @return array{outcome:string,status:int,retryable:bool,reason:string}
 */
function nvx_supabase_relay_classify( $response ): array {
	if ( is_wp_error( $response ) ) {
		return array(
			'outcome'   => 'TRANSPORT',
			'status'    => 0,
			'retryable' => true,
			'reason'    => sanitize_key( (string) $response->get_error_code() ),
		);
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	if ( $status >= 200 && $status < 300 ) {
		return array(
			'outcome'   => 'SUCCESS',
			'status'    => $status,
			'retryable' => false,
			'reason'    => '',
		);
	}
	if ( 429 === $status ) {
		return array(
			'outcome'   => 'HTTP_429',
			'status'    => $status,
			'retryable' => true,
			'reason'    => 'rate_limited',
		);
	}
	if ( $status >= 500 ) {
		return array(
			'outcome'   => 'HTTP_5XX',
			'status'    => $status,
			'retryable' => true,
			'reason'    => 'http_5xx',
		);
	}
	if ( $status >= 400 ) {
		return array(
			'outcome'   => 'HTTP_4XX',
			'status'    => $status,
			'retryable' => false,
			'reason'    => 'http_4xx',
		);
	}

	return array(
		'outcome'   => 'TRANSPORT',
		'status'    => $status,
		'retryable' => true,
		'reason'    => 'empty_status',
	);
}

/**
 * Backoff seconds for the next drain attempt.
 *
 * @param int $attempt 1-based attempt count after the failure being recorded.
 */
function nvx_supabase_relay_queue_backoff_seconds( int $attempt ): int {
	$schedule = array( 30, 60, 120, 300, 900, 1800, 3600, 21600 );
	$index    = max( 0, min( $attempt - 1, count( $schedule ) - 1 ) );
	return $schedule[ $index ];
}

/** Resolve the existing server-only HubSpot token used as the Google-click signing root. */
function nvx_supabase_relay_google_click_token(): string {
	if ( function_exists( 'nvx_lead_captured_hubspot_token' ) ) {
		return trim( (string) nvx_lead_captured_hubspot_token() );
	}
	if ( defined( 'NVX_HUBSPOT_ACCESS_TOKEN' ) ) {
		return trim( (string) NVX_HUBSPOT_ACCESS_TOKEN );
	}
	return '';
}

/** Derive the exact HMAC key expected by google-click-attribution. */
function nvx_supabase_relay_google_click_hmac_key( string $token ): string {
	return hash_hmac( 'sha256', NVX_GOOGLE_CLICK_HMAC_CONTEXT, $token );
}

/** POST a Google-click payload with timestamp + HMAC authentication attached at send time. */
function nvx_supabase_relay_google_click_post_signed( string $url, string $body, string $origin, string $token ) {
	$timestamp = (string) time();
	$hmac_key  = nvx_supabase_relay_google_click_hmac_key( $token );
	$signature = hash_hmac( 'sha256', $timestamp . '.' . $body, $hmac_key );
	$headers   = array(
		'Content-Type'    => 'application/json',
		'x-nvx-timestamp' => $timestamp,
		'x-nvx-signature' => $signature,
	);
	if ( '' !== $origin ) {
		$headers['Origin'] = $origin;
	}

	return wp_remote_post(
		$url,
		array(
			'timeout'     => 3,
			'redirection' => 0,
			'blocking'    => true,
			'headers'     => $headers,
			'body'        => $body,
		)
	);
}

/**
 * Enqueue a signed-at-send-time collector payload.
 *
 * @param array<string,string> $headers Extra headers to persist (Origin only).
 */
function nvx_supabase_relay_queue_enqueue( string $endpoint, string $body, array $headers = array() ): int {
	$endpoints = nvx_supabase_relay_queue_endpoints();
	if ( ! isset( $endpoints[ $endpoint ] ) || '' === $endpoints[ $endpoint ] || '' === $body ) {
		return 0;
	}
	if ( strlen( $body ) > 32768 ) {
		nvx_supabase_relay_log( $endpoint, 'DEAD', 0, 'payload_too_large' );
		return 0;
	}

	$origin = isset( $headers['Origin'] ) ? esc_url_raw( (string) $headers['Origin'] ) : '';
	$post_id = wp_insert_post(
		array(
			'post_type'    => NVX_SUPABASE_RELAY_QUEUE_CPT,
			'post_status'  => 'pending',
			'post_title'   => $endpoint . ' ' . gmdate( 'Y-m-d H:i:s' ),
			'post_content' => $body,
		),
		true
	);
	if ( is_wp_error( $post_id ) || $post_id < 1 ) {
		nvx_supabase_relay_log( $endpoint, 'DEAD', 0, 'enqueue_failed' );
		return 0;
	}

	update_post_meta( $post_id, '_nvx_relay_endpoint', $endpoint );
	update_post_meta( $post_id, '_nvx_relay_attempts', '0' );
	update_post_meta( $post_id, '_nvx_relay_next_attempt', (string) time() );
	if ( '' !== $origin ) {
		update_post_meta( $post_id, '_nvx_relay_origin', $origin );
	}

	$GLOBALS['nvx_supabase_relay_queue_dirty'] = true;
	nvx_supabase_relay_log( $endpoint, 'QUEUED' );
	return (int) $post_id;
}

/**
 * POST one queued payload. HMAC/bootstrap headers are attached at send time.
 *
 * @return mixed WordPress HTTP response.
 */
function nvx_supabase_relay_queue_send( string $endpoint, string $body, string $origin = '' ) {
	$endpoints = nvx_supabase_relay_queue_endpoints();
	$url       = $endpoints[ $endpoint ] ?? '';
	if ( '' === $url || '' === $body ) {
		return new WP_Error( 'nvx_relay_endpoint_missing', 'Relay endpoint is unavailable.' );
	}

	if ( 'lead_captured' === $endpoint && function_exists( 'nvx_lead_captured_hubspot_token' ) && function_exists( 'nvx_lead_captured_post_signed' ) ) {
		$token = nvx_lead_captured_hubspot_token();
		if ( '' === $token ) {
			return new WP_Error( 'nvx_relay_signing_key_missing', 'Lead-captured signing is unavailable.' );
		}
		return nvx_lead_captured_post_signed( $body, $token );
	}

	if ( 'google_click' === $endpoint ) {
		$token = nvx_supabase_relay_google_click_token();
		if ( '' === $token ) {
			return new WP_Error( 'nvx_relay_signing_key_missing', 'Google-click signing is unavailable.' );
		}
		return nvx_supabase_relay_google_click_post_signed( $url, $body, $origin, $token );
	}

	$headers = array(
		'Content-Type' => 'application/json',
	);
	if ( '' !== $origin ) {
		$headers['Origin'] = $origin;
	}

	return wp_remote_post(
		$url,
		array(
			'timeout'     => 3,
			'redirection' => 0,
			'blocking'    => true,
			'headers'     => $headers,
			'body'        => $body,
		)
	);
}

/**
 * Dispatch now; enqueue only retryable failures. Never throws.
 *
 * @param array<string,string> $headers Extra headers (Origin).
 * @return array{outcome:string,status:int,queued:int}
 */
function nvx_supabase_relay_dispatch( string $endpoint, string $body, array $headers = array() ): array {
	$response = nvx_supabase_relay_queue_send( $endpoint, $body, isset( $headers['Origin'] ) ? (string) $headers['Origin'] : '' );
	$class    = nvx_supabase_relay_classify( $response );
	nvx_supabase_relay_log( $endpoint, $class['outcome'], $class['status'], $class['reason'] );

	$queued = 0;
	if ( $class['retryable'] ) {
		$queued = nvx_supabase_relay_queue_enqueue( $endpoint, $body, $headers );
	}

	return array(
		'outcome' => $class['outcome'],
		'status'  => $class['status'],
		'queued'  => $queued,
	);
}

/**
 * Drain due outbox items.
 *
 * @param int $limit Maximum items to attempt.
 */
function nvx_supabase_relay_queue_drain( int $limit = NVX_SUPABASE_RELAY_QUEUE_BATCH ): void {
	$limit = max( 1, min( $limit, NVX_SUPABASE_RELAY_QUEUE_BATCH ) );
	$query = new WP_Query(
		array(
			'post_type'              => NVX_SUPABASE_RELAY_QUEUE_CPT,
			'post_status'            => 'pending',
			'posts_per_page'         => $limit,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => false,
			'meta_query'             => array(
				array(
					'key'     => '_nvx_relay_next_attempt',
					'value'   => (string) time(),
					'compare' => '<=',
					'type'    => 'NUMERIC',
				),
			),
		)
	);

	foreach ( $query->posts as $post ) {
		$post_id  = (int) $post->ID;
		$endpoint = (string) get_post_meta( $post_id, '_nvx_relay_endpoint', true );
		$origin   = (string) get_post_meta( $post_id, '_nvx_relay_origin', true );
		$body     = (string) $post->post_content;
		$attempts = (int) get_post_meta( $post_id, '_nvx_relay_attempts', true );

		if ( 'lead_captured' === $endpoint && function_exists( 'nvx_lead_captured_bootstrap_runtime' ) && function_exists( 'nvx_lead_captured_hubspot_token' ) ) {
			$token = nvx_lead_captured_hubspot_token();
			if ( '' !== $token ) {
				nvx_lead_captured_bootstrap_runtime( $token );
			}
		}

		$response = nvx_supabase_relay_queue_send( $endpoint, $body, $origin );
		$class    = nvx_supabase_relay_classify( $response );

		if ( 'SUCCESS' === $class['outcome'] ) {
			wp_delete_post( $post_id, true );
			nvx_supabase_relay_log( $endpoint, 'DRAINED', $class['status'] );
			continue;
		}

		$attempts++;
		if ( ! $class['retryable'] || $attempts >= NVX_SUPABASE_RELAY_QUEUE_MAX_TRIES ) {
			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => 'draft',
				)
			);
			nvx_supabase_relay_log( $endpoint, 'DEAD', $class['status'], $class['reason'] );
			continue;
		}

		update_post_meta( $post_id, '_nvx_relay_attempts', (string) $attempts );
		update_post_meta( $post_id, '_nvx_relay_next_attempt', (string) ( time() + nvx_supabase_relay_queue_backoff_seconds( $attempts ) ) );
		nvx_supabase_relay_log( $endpoint, $class['outcome'], $class['status'], 'retry_scheduled' );
	}
}
add_action( NVX_SUPABASE_RELAY_QUEUE_CRON, 'nvx_supabase_relay_queue_drain' );

/** Drain at most three dirty-queue items after the current request. */
function nvx_supabase_relay_queue_shutdown_drain(): void {
	if ( empty( $GLOBALS['nvx_supabase_relay_queue_dirty'] ) ) {
		return;
	}
	nvx_supabase_relay_queue_drain( 3 );
}
add_action( 'shutdown', 'nvx_supabase_relay_queue_shutdown_drain' );

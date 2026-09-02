<?php
/**
 * Persistent at-least-once outbox for canonical Supabase relays.
 *
 * HubSpot remains authoritative for the patient response. A collector failure
 * never changes an already accepted HubSpot submission.
 *
 * Payloads are persisted unsigned. Authentication signatures and replay
 * timestamps are generated only at send time.
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

if ( ! defined( 'NVX_SUPABASE_RELAY_QUEUE_MAX_TRIES' ) ) {
	define( 'NVX_SUPABASE_RELAY_QUEUE_MAX_TRIES', 8 );
}

if ( ! defined( 'NVX_SUPABASE_RELAY_QUEUE_BATCH' ) ) {
	define( 'NVX_SUPABASE_RELAY_QUEUE_BATCH', 10 );
}

if ( ! defined( 'NVX_SUPABASE_RELAY_QUEUE_MAX_BODY_BYTES' ) ) {
	define( 'NVX_SUPABASE_RELAY_QUEUE_MAX_BODY_BYTES', 32768 );
}

if ( ! defined( 'NVX_SUPABASE_RELAY_QUEUE_LOCK_TTL' ) ) {
	define( 'NVX_SUPABASE_RELAY_QUEUE_LOCK_TTL', 180 );
}

/**
 * Safely derive lock lease duration based on batch size and worst-case transport timeouts.
 *
 * Each item in the batch can consume up to 5s delivery, 5s forced bootstrap, and 5s retry delivery (15s).
 * A safe safety buffer is added to ensure the lease outlives worst-case execution.
 *
 * @param int $batch_size Maximum items in batch.
 * @return int TTL in seconds.
 */
if ( ! function_exists( 'nvx_supabase_relay_queue_lock_ttl' ) ) {
	function nvx_supabase_relay_queue_lock_ttl(
		int $batch_size = NVX_SUPABASE_RELAY_QUEUE_BATCH
	): int {
		$worst_case_per_item = 15;
		$derived             = ( max( 1, $batch_size ) * $worst_case_per_item ) + 30;

		return max(
			(int) NVX_SUPABASE_RELAY_QUEUE_LOCK_TTL,
			$derived
		);
	}
}

/**
 * Current Unix timestamp with mock override support for deterministic concurrency tests.
 *
 * @return int Timestamp.
 */
if ( ! function_exists( 'nvx_supabase_relay_time' ) ) {
	function nvx_supabase_relay_time(): int {
		return isset( $GLOBALS['nvx_mock_time'] ) ? (int) $GLOBALS['nvx_mock_time'] : time();
	}
}

if ( ! defined( 'NVX_GOOGLE_CLICK_HMAC_CONTEXT' ) ) {
	define(
		'NVX_GOOGLE_CLICK_HMAC_CONTEXT',
		'nuvanx-google-click-attribution-hmac-key-v1'
	);
}

/**
 * Allowed relay endpoints.
 *
 * @return array<string,string>
 */
if ( ! function_exists( 'nvx_supabase_relay_queue_endpoints' ) ) {
	function nvx_supabase_relay_queue_endpoints(): array {
		return array(
			'google_click' => function_exists(
				'nvx_attribution_collector_canonical_endpoint'
			)
				? (string) nvx_attribution_collector_canonical_endpoint()
				: '',

			'lead_captured' => function_exists(
				'nvx_lead_captured_endpoint'
			)
				? (string) nvx_lead_captured_endpoint()
				: '',
		);
	}
}

/**
 * Register private outbox CPT.
 */
if ( ! function_exists( 'nvx_supabase_relay_queue_register_cpt' ) ) {
	function nvx_supabase_relay_queue_register_cpt(): void {
		register_post_type(
			NVX_SUPABASE_RELAY_QUEUE_CPT,
			array(
				'labels' => array(
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
}

add_action(
	'init',
	'nvx_supabase_relay_queue_register_cpt',
	5
);

/**
 * Add five-minute drainage schedule.
 *
 * @param array<string,array<string,mixed>> $schedules Existing schedules.
 * @return array<string,array<string,mixed>>
 */
if ( ! function_exists( 'nvx_supabase_relay_queue_cron_schedules' ) ) {
	function nvx_supabase_relay_queue_cron_schedules(
		array $schedules
	): array {
		if ( isset( $schedules['nvx_relay_outbox_5min'] ) ) {
			return $schedules;
		}

		$schedules['nvx_relay_outbox_5min'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => 'Every five minutes (NUVANX relay outbox)',
		);

		return $schedules;
	}
}

add_filter(
	'cron_schedules',
	'nvx_supabase_relay_queue_cron_schedules'
);

/**
 * Ensure the recurring drain exists once.
 */
if ( ! function_exists( 'nvx_supabase_relay_queue_schedule_cron' ) ) {
	function nvx_supabase_relay_queue_schedule_cron(): void {
		if ( wp_next_scheduled( NVX_SUPABASE_RELAY_QUEUE_CRON ) ) {
			return;
		}

		wp_schedule_event(
			time() + MINUTE_IN_SECONDS,
			'nvx_relay_outbox_5min',
			NVX_SUPABASE_RELAY_QUEUE_CRON
		);
	}
}

add_action(
	'init',
	'nvx_supabase_relay_queue_schedule_cron',
	20
);

/**
 * Remove all scheduled outbox drains when the theme is switched.
 */
if ( ! function_exists( 'nvx_supabase_relay_queue_unschedule_cron' ) ) {
	function nvx_supabase_relay_queue_unschedule_cron(): void {
		wp_clear_scheduled_hook(
			NVX_SUPABASE_RELAY_QUEUE_CRON
		);
	}
}

add_action(
	'switch_theme',
	'nvx_supabase_relay_queue_unschedule_cron'
);

/**
 * Emit bounded non-PII telemetry.
 */
if ( ! function_exists( 'nvx_supabase_relay_log' ) ) {
	function nvx_supabase_relay_log(
		string $endpoint,
		string $outcome,
		int $status = 0,
		string $reason = ''
	): void {
		$endpoint = sanitize_key( $endpoint );
		$outcome  = strtoupper( $outcome );

		$allowed = array(
			'SUCCESS',
			'HTTP_4XX',
			'HTTP_429',
			'HTTP_5XX',
			'TRANSPORT',
			'QUEUED',
			'DRAINED',
			'DEAD',
		);

		if (
			''
			=== $endpoint
			|| ! in_array( $outcome, $allowed, true )
		) {
			return;
		}

		$line = 'NVX_SUPABASE_RELAY='
			. $outcome
			. ' endpoint='
			. $endpoint;

		if ( $status > 0 ) {
			$line .= ' status=' . absint( $status );
		}

		if ( '' !== $reason ) {
			$safe_reason = sanitize_key( $reason );

			if ( '' !== $safe_reason ) {
				$line .= ' reason=' . $safe_reason;
			}
		}

		error_log( $line );
	}
}

/**
 * Classify one HTTP result.
 *
 * @param mixed $response wp_remote_* result.
 * @return array{outcome:string,status:int,retryable:bool,reason:string}
 */
if ( ! function_exists( 'nvx_supabase_relay_classify' ) ) {
	function nvx_supabase_relay_classify(
		mixed $response
	): array {
		if ( is_wp_error( $response ) ) {
			return array(
				'outcome'   => 'TRANSPORT',
				'status'    => 0,
				'retryable' => true,
				'reason'    => sanitize_key(
					(string) $response->get_error_code()
				),
			);
		}

		$status = absint(
			wp_remote_retrieve_response_code(
				$response
			)
		);

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
}

/**
 * Backoff for a failed delivery attempt.
 *
 * $attempt is the total number of delivery attempts already made.
 */
if ( ! function_exists( 'nvx_supabase_relay_queue_backoff_seconds' ) ) {
	function nvx_supabase_relay_queue_backoff_seconds(
		int $attempt
	): int {
		$schedule = array(
			30,
			60,
			120,
			300,
			900,
			1800,
			3600,
			21600,
		);

		$index = max(
			0,
			min(
				$attempt - 1,
				count( $schedule ) - 1
			)
		);

		return (int) $schedule[ $index ];
	}
}

/**
 * Validate persisted relay JSON.
 */
if ( ! function_exists( 'nvx_supabase_relay_valid_body' ) ) {
	function nvx_supabase_relay_valid_body(
		string $body
	): bool {
		if (
			''
			=== $body
			|| strlen( $body )
			> (int) NVX_SUPABASE_RELAY_QUEUE_MAX_BODY_BYTES
		) {
			return false;
		}

		json_decode( $body, true );

		return JSON_ERROR_NONE === json_last_error();
	}
}

/**
 * Sanitize an Origin persisted for Google Click.
 */
if ( ! function_exists( 'nvx_supabase_relay_sanitize_origin' ) ) {
	function nvx_supabase_relay_sanitize_origin(
		string $origin
	): string {
		if ( '' === trim( $origin ) ) {
			return '';
		}

		$origin = sanitize_url( $origin );

		if ( '' === $origin ) {
			return '';
		}

		$scheme = strtolower(
			(string) wp_parse_url(
				$origin,
				PHP_URL_SCHEME
			)
		);

		$host = strtolower(
			(string) wp_parse_url(
				$origin,
				PHP_URL_HOST
			)
		);

		if ( 'https' !== $scheme || '' === $host ) {
			return '';
		}

		if (
			function_exists(
				'nvx_attribution_collector_allowed_hosts'
			)
			&& ! in_array(
				$host,
				nvx_attribution_collector_allowed_hosts(),
				true
			)
		) {
			return '';
		}

		return 'https://' . $host;
	}
}

/**
 * Stable queue dedupe identifier.
 */
if ( ! function_exists( 'nvx_supabase_relay_dedupe_key' ) ) {
	function nvx_supabase_relay_dedupe_key(
		string $endpoint,
		string $body,
		string $origin = ''
	): string {
		return hash(
			'sha256',
			$endpoint
			. '|'
			. $origin
			. '|'
			. $body
		);
	}
}

/**
 * Locate an already-pending identical queue item.
 */
if ( ! function_exists( 'nvx_supabase_relay_existing_item' ) ) {
	function nvx_supabase_relay_existing_item(
		string $dedupe_key
	): int {
		$ids = get_posts(
			array(
				'post_type'              => NVX_SUPABASE_RELAY_QUEUE_CPT,
				'post_status'            => 'pending',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					array(
						'key'     => '_nvx_relay_dedupe_key',
						'value'   => $dedupe_key,
						'compare' => '=',
					),
				),
			)
		);

		if ( empty( $ids ) ) {
			return 0;
		}

		return absint( $ids[0] );
	}
}

/**
 * Resolve server-only signing root.
 */
if ( ! function_exists( 'nvx_supabase_relay_google_click_token' ) ) {
	function nvx_supabase_relay_google_click_token(): string {
		if ( function_exists( 'nvx_lead_captured_hubspot_token' ) ) {
			return trim(
				(string) nvx_lead_captured_hubspot_token()
			);
		}

		if ( defined( 'NVX_HUBSPOT_ACCESS_TOKEN' ) ) {
			return trim(
				(string) NVX_HUBSPOT_ACCESS_TOKEN
			);
		}

		return '';
	}
}

/**
 * Google Click domain-separated HMAC key.
 */
if ( ! function_exists( 'nvx_supabase_relay_google_click_hmac_key' ) ) {
	function nvx_supabase_relay_google_click_hmac_key(
		string $token
	): string {
		return hash_hmac(
			'sha256',
			NVX_GOOGLE_CLICK_HMAC_CONTEXT,
			$token
		);
	}
}

/**
 * Ensure Supabase runtime signing state exists.
 *
 * Bootstrap failures are represented as WP_Error so the queue classifies them
 * as retryable transport state instead of turning a temporary 401 into DEAD.
 *
 * @return true|WP_Error
 */
if ( ! function_exists( 'nvx_supabase_relay_ensure_runtime_bootstrap' ) ) {
	function nvx_supabase_relay_ensure_runtime_bootstrap(
		string $token,
		bool $force = false
	): true|WP_Error {
		if ( '' === $token ) {
			return new WP_Error(
				'nvx_relay_signing_key_missing',
				'Relay signing key is unavailable.'
			);
		}

		if (
			! function_exists(
				'nvx_lead_captured_bootstrap_runtime'
			)
		) {
			return new WP_Error(
				'nvx_runtime_bootstrap_owner_missing',
				'Runtime bootstrap owner is unavailable.'
			);
		}

		if (
			! nvx_lead_captured_bootstrap_runtime(
				$token,
				$force
			)
		) {
			return new WP_Error(
				'nvx_runtime_bootstrap_unavailable',
				'Runtime bootstrap is temporarily unavailable.'
			);
		}

		return true;
	}
}

/**
 * POST Google Click with fresh timestamp/signature.
 *
 * @return array<string,mixed>|WP_Error
 */
if ( ! function_exists( 'nvx_supabase_relay_google_click_post_signed' ) ) {
	function nvx_supabase_relay_google_click_post_signed(
		string $url,
		string $body,
		string $origin,
		string $token
	): array|WP_Error {
		$timestamp = (string) time();

		$hmac_key = nvx_supabase_relay_google_click_hmac_key(
			$token
		);

		$signature = hash_hmac(
			'sha256',
			$timestamp . '.' . $body,
			$hmac_key
		);

		return wp_remote_post(
			$url,
			array(
				'method'             => 'POST',
				'timeout'            => 5,
				'redirection'        => 0,
				'blocking'           => true,
				'reject_unsafe_urls' => true,
				'headers'            => array(
					'Accept'          => 'application/json',
					'Content-Type'    => 'application/json',
					'Origin'          => $origin,
					'x-nvx-timestamp' => $timestamp,
					'x-nvx-signature' => $signature,
				),
				'body'               => $body,
			)
		);
	}
}

/**
 * Enqueue a retryable payload.
 *
 * $attempts represents attempts already performed, including the failed
 * synchronous attempt that caused this enqueue.
 *
 * @param array<string,string> $headers Persistable transport context.
 */

/**
 * Release dedupe reservation lock.
 */
if ( ! function_exists( 'nvx_supabase_relay_queue_unlock_dedupe' ) ) {
	function nvx_supabase_relay_queue_unlock_dedupe(
		string $key,
		string $token
	): void {
		global $wpdb;
		if ( isset( $wpdb ) && method_exists( $wpdb, 'query' ) && method_exists( $wpdb, 'prepare' ) && method_exists( $wpdb, 'esc_like' ) ) {
			$table = isset( $wpdb->options ) ? $wpdb->options : 'wp_options';
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE option_name = %s AND option_value LIKE %s",
					$key,
					'%|' . $wpdb->esc_like( $token )
				)
			);
			if ( function_exists( 'wp_cache_delete' ) ) {
				wp_cache_delete( $key, 'options' );
			}
			return;
		}

		$current = (string) get_option( $key, '' );
		if ( str_ends_with( $current, '|' . $token ) ) {
			delete_option( $key );
		}
	}
}

/**
 * Atomic dedupe reservation primitive.
 */
if ( ! function_exists( 'nvx_supabase_relay_queue_dedupe_reservation' ) ) {
	function nvx_supabase_relay_queue_dedupe_reservation(
		string $dedupe_key
	): array {
		$key   = 'nvx_relay_dedupe_res_' . $dedupe_key;
		$token = function_exists( 'wp_generate_uuid4' )
			? wp_generate_uuid4()
			: bin2hex( random_bytes( 16 ) );
		$lease = 60; // 60 seconds

		$has_reservation = false;
		while ( ! $has_reservation ) {
			$expires = nvx_supabase_relay_time() + $lease;
			$value   = $expires . '|' . $token;

			if ( add_option( $key, $value, '', false ) ) {
				$has_reservation = true;
			} else {
				$current        = (string) get_option( $key, '' );
				$parts          = explode( '|', $current, 2 );
				$current_expiry = isset( $parts[0] ) ? absint( $parts[0] ) : 0;

				if ( $current_expiry > 0 && $current_expiry <= nvx_supabase_relay_time() ) {
					if ( nvx_supabase_relay_compare_and_swap_option( $key, $current, $value ) ) {
						$has_reservation = true;
					}
				}
			}
			if ( ! $has_reservation ) {
				usleep( 50000 );
			}
		}

		return array(
			'key'   => $key,
			'token' => $token,
		);
	}
}

/**
 * Atomic attempt accumulation primitive.
 */
if ( ! function_exists( 'nvx_supabase_relay_queue_atomic_add_attempts' ) ) {
	function nvx_supabase_relay_queue_atomic_add_attempts(
		int $post_id,
		int $add_attempts
	): int {
		$post_id   = absint( $post_id );
		$max_tries = (int) NVX_SUPABASE_RELAY_QUEUE_MAX_TRIES;

		while ( true ) {
			$current = absint( get_post_meta( $post_id, '_nvx_relay_attempts', true ) );
			$new_val = min( $max_tries, $current + $add_attempts );

			if ( $current === $new_val ) {
				return $new_val;
			}

			if ( update_post_meta( $post_id, '_nvx_relay_attempts', (string) $new_val, (string) $current ) ) {
				return $new_val;
			}
		}
	}
}

/**
 * Monotonic next attempt primitive.
 */
if ( ! function_exists( 'nvx_supabase_relay_queue_set_next_attempt_monotonic' ) ) {
	function nvx_supabase_relay_queue_set_next_attempt_monotonic(
		int $post_id,
		int $proposed_next_attempt
	): void {
		$post_id = absint( $post_id );
		while ( true ) {
			$current = absint( get_post_meta( $post_id, '_nvx_relay_next_attempt', true ) );
			if ( $proposed_next_attempt <= $current ) {
				return;
			}
			if ( update_post_meta( $post_id, '_nvx_relay_next_attempt', (string) $proposed_next_attempt, (string) $current ) ) {
				return;
			}
		}
	}
}

/**
 * Re-validate item is due right before I/O.
 */
if ( ! function_exists( 'nvx_supabase_relay_queue_item_due' ) ) {
	function nvx_supabase_relay_queue_item_due(
		int $post_id,
		string $lock,
		int $lease_ttl
	): bool {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'pending' !== $post->post_status ) {
			return false;
		}

		$next_attempt = absint( get_post_meta( $post_id, '_nvx_relay_next_attempt', true ) );
		if ( $next_attempt > nvx_supabase_relay_time() ) {
			return false;
		}

		if ( ! nvx_supabase_relay_queue_renew_lock( $lock, $lease_ttl ) ) {
			return false;
		}

		return true;
	}
}

/**
 * Post-I/O fencing primitive.
 */
if ( ! function_exists( 'nvx_supabase_relay_queue_lock_owned' ) ) {
	function nvx_supabase_relay_queue_lock_owned(
		string $token
	): bool {
		$key     = 'nvx_supabase_relay_drain_lock_v1';
		$current = (string) get_option( $key, '' );
		$parts   = explode( '|', $current, 2 );

		$current_expiry = isset( $parts[0] ) ? absint( $parts[0] ) : 0;
		$current_token  = isset( $parts[1] ) ? $parts[1] : '';

		if ( $current_token !== $token ) {
			return false;
		}

		if ( $current_expiry <= nvx_supabase_relay_time() ) {
			return false;
		}

		return true;
	}
}

if ( ! function_exists( 'nvx_supabase_relay_queue_enqueue' ) ) {
	function nvx_supabase_relay_queue_enqueue(
		string $endpoint,
		string $body,
		array $headers = array(),
		int $attempts = 1
	): int {
		$endpoint  = sanitize_key( $endpoint );
		$endpoints = nvx_supabase_relay_queue_endpoints();

		if (
			! isset( $endpoints[ $endpoint ] )
			|| ''
			=== $endpoints[ $endpoint ]
			|| ! nvx_supabase_relay_valid_body( $body )
		) {
			nvx_supabase_relay_log(
				$endpoint,
				'DEAD',
				0,
				'invalid_enqueue'
			);

			return 0;
		}

		$origin = isset( $headers['Origin'] )
			? nvx_supabase_relay_sanitize_origin(
				(string) $headers['Origin']
			)
			: '';

		if (
			'google_click'
			=== $endpoint
			&& ''
			=== $origin
		) {
			nvx_supabase_relay_log(
				$endpoint,
				'DEAD',
				0,
				'origin_invalid'
			);

			return 0;
		}

		$dedupe_key = nvx_supabase_relay_dedupe_key(
			$endpoint,
			$body,
			$origin
		);

		$reservation = nvx_supabase_relay_queue_dedupe_reservation( $dedupe_key );

		try {
			$existing = nvx_supabase_relay_existing_item(
				$dedupe_key
			);

			if ( $existing > 0 ) {
				$new_attempts = nvx_supabase_relay_queue_atomic_add_attempts(
					$existing,
					$attempts
				);

				if ( $new_attempts >= (int) NVX_SUPABASE_RELAY_QUEUE_MAX_TRIES ) {
					nvx_supabase_relay_queue_mark_dead(
						$existing,
						$endpoint,
						0,
						'max_retries_exceeded'
					);
				} else {
					$next_attempt = time()
						+ nvx_supabase_relay_queue_backoff_seconds(
							$new_attempts
						);
					nvx_supabase_relay_queue_set_next_attempt_monotonic(
						$existing,
						$next_attempt
					);
				}

				return $existing;
			}

			$attempts = max(
				1,
				min(
					$attempts,
					(int) NVX_SUPABASE_RELAY_QUEUE_MAX_TRIES
				)
			);

			$next_attempt = time()
				+ nvx_supabase_relay_queue_backoff_seconds(
					$attempts
				);

			$post_id = wp_insert_post(
				array(
					'post_type'    => NVX_SUPABASE_RELAY_QUEUE_CPT,
					'post_status'  => 'draft',
					'post_title'   => sanitize_text_field(
						$endpoint
						. ' '
						. gmdate( 'Y-m-d H:i:s' )
					),
					/*
					 * wp_insert_post() expects slashed content.
					 * Without wp_slash(), valid JSON escape sequences may change.
					 */
					'post_content' => wp_slash( $body ),
				),
				true
			);

			if (
				is_wp_error( $post_id )
				|| absint( $post_id ) < 1
			) {
				nvx_supabase_relay_log(
					$endpoint,
					'DEAD',
					0,
					'enqueue_failed'
				);

				return 0;
			}

			$post_id = absint( $post_id );

			$meta_ok = true;

			$meta_ok = add_post_meta(
				$post_id,
				'_nvx_relay_endpoint',
				$endpoint,
				true
			) && $meta_ok;

			$meta_ok = add_post_meta(
				$post_id,
				'_nvx_relay_attempts',
				(string) $attempts,
				true
			) && $meta_ok;

			$meta_ok = add_post_meta(
				$post_id,
				'_nvx_relay_next_attempt',
				(string) $next_attempt,
				true
			) && $meta_ok;

			$meta_ok = add_post_meta(
				$post_id,
				'_nvx_relay_dedupe_key',
				$dedupe_key,
				true
			) && $meta_ok;

			if ( '' !== $origin ) {
				$meta_ok = add_post_meta(
					$post_id,
					'_nvx_relay_origin',
					$origin,
					true
				) && $meta_ok;
			}

			if ( ! $meta_ok ) {
				wp_delete_post( $post_id, true );

				nvx_supabase_relay_log(
					$endpoint,
					'DEAD',
					0,
					'enqueue_metadata_failed'
				);

				return 0;
			}

			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => 'pending',
				)
			);
		} finally {
			nvx_supabase_relay_queue_unlock_dedupe(
				$reservation['key'],
				$reservation['token']
			);
		}

		$GLOBALS['nvx_supabase_relay_queue_dirty'] = true;

		nvx_supabase_relay_log(
			$endpoint,
			'QUEUED'
		);

		return $post_id;
	}
}

/**
 * Send one persisted payload.
 *
 * No unsigned generic transport exists. Unknown endpoints fail closed.
 *
 * @return array<string,mixed>|WP_Error
 */
if ( ! function_exists( 'nvx_supabase_relay_queue_send' ) ) {
	function nvx_supabase_relay_queue_send(
		string $endpoint,
		string $body,
		string $origin = '',
		bool $force_bootstrap = false
	): array|WP_Error {
		$endpoint = sanitize_key( $endpoint );

		$endpoints = nvx_supabase_relay_queue_endpoints();

		$url = isset( $endpoints[ $endpoint ] )
			? sanitize_url(
				(string) $endpoints[ $endpoint ]
			)
			: '';

		if (
			''
			=== $url
			|| ! nvx_supabase_relay_valid_body( $body )
		) {
			return new WP_Error(
				'nvx_relay_endpoint_missing',
				'Relay endpoint or body is unavailable.'
			);
		}

		$token = nvx_supabase_relay_google_click_token();

		$bootstrap = nvx_supabase_relay_ensure_runtime_bootstrap(
			$token,
			$force_bootstrap
		);

		if ( is_wp_error( $bootstrap ) ) {
			return $bootstrap;
		}

		if ( 'lead_captured' === $endpoint ) {
			if (
				! function_exists(
					'nvx_lead_captured_post_signed'
				)
			) {
				return new WP_Error(
					'nvx_lead_capture_transport_missing',
					'Lead capture signed transport is unavailable.'
				);
			}

			return nvx_lead_captured_post_signed(
				$body,
				$token
			);
		}

		if ( 'google_click' === $endpoint ) {
			$origin = nvx_supabase_relay_sanitize_origin(
				$origin
			);

			if ( '' === $origin ) {
				return new WP_Error(
					'nvx_relay_origin_missing',
					'Google Click relay origin is unavailable.'
				);
			}

			return nvx_supabase_relay_google_click_post_signed(
				$url,
				$body,
				$origin,
				$token
			);
		}

		return new WP_Error(
			'nvx_relay_endpoint_unsupported',
			'Unsupported relay endpoint.'
		);
	}
}

/**
 * Dispatch synchronously and queue only retryable failure.
 *
 * @param array<string,string> $headers Extra transport context.
 * @return array{outcome:string,status:int,queued:int}
 */
if ( ! function_exists( 'nvx_supabase_relay_dispatch' ) ) {
	function nvx_supabase_relay_dispatch(
		string $endpoint,
		string $body,
		array $headers = array()
	): array {
		$endpoint = sanitize_key( $endpoint );

		$origin = isset( $headers['Origin'] )
			? nvx_supabase_relay_sanitize_origin(
				(string) $headers['Origin']
			)
			: '';

		try {
			$response = nvx_supabase_relay_queue_send(
				$endpoint,
				$body,
				$origin
			);
		} catch ( Throwable $error ) {
			unset( $error );

			$response = new WP_Error(
				'nvx_relay_unexpected_transport',
				'Relay transport failed unexpectedly.'
			);
		}

		$class             = nvx_supabase_relay_classify(
			$response
		);
		$delivery_attempts = 1;

		if ( 401 === $class['status'] ) {
			try {
				$retry_response = nvx_supabase_relay_queue_send(
					$endpoint,
					$body,
					$origin,
					true
				);
			} catch ( Throwable $retry_error ) {
				unset( $retry_error );

				$retry_response = new WP_Error(
					'nvx_relay_unexpected_transport',
					'Relay transport failed unexpectedly.'
				);
			}

			$class             = nvx_supabase_relay_classify(
				$retry_response
			);
			$delivery_attempts = ( is_wp_error( $retry_response ) && 'nvx_runtime_bootstrap_unavailable' === $retry_response->get_error_code() )
				? 1
				: 2;
		}

		nvx_supabase_relay_log(
			$endpoint,
			$class['outcome'],
			$class['status'],
			$class['reason']
		);

		$queued = 0;

		if ( $class['retryable'] ) {
			$queued = nvx_supabase_relay_queue_enqueue(
				$endpoint,
				$body,
				array(
					'Origin' => $origin,
				),
				$delivery_attempts
			);
		}

		return array(
			'outcome' => $class['outcome'],
			'status'  => $class['status'],
			'queued'  => $queued,
		);
	}
}

/**
 * Atomically replace an option value only if it matches expected value.
 *
 * Prevents concurrent contenders from overwriting or deleting newly acquired locks.
 *
 * @param string $key Option name.
 * @param string $expected Expected current option value.
 * @param string $new_value New option value to set.
 * @return bool True if updated, false if value differed (lost CAS).
 */
if ( ! function_exists( 'nvx_supabase_relay_compare_and_swap_option' ) ) {
	function nvx_supabase_relay_compare_and_swap_option(
		string $key,
		string $expected,
		string $new_value
	): bool {
		global $wpdb;

		if ( isset( $wpdb ) && method_exists( $wpdb, 'query' ) && method_exists( $wpdb, 'prepare' ) ) {
			$table = isset( $wpdb->options ) ? $wpdb->options : 'wp_options';

			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET option_value = %s WHERE option_name = %s AND option_value = %s",
					$new_value,
					$key,
					$expected
				)
			);

			if ( $updated > 0 ) {
				if ( function_exists( 'wp_cache_set' ) ) {
					wp_cache_set( $key, $new_value, 'options' );
				}
				return true;
			}

			return false;
		}

		if ( isset( $GLOBALS['nvx_mock_options'] ) && is_array( $GLOBALS['nvx_mock_options'] ) ) {
			if ( ( $GLOBALS['nvx_mock_options'][ $key ] ?? '' ) === $expected ) {
				$GLOBALS['nvx_mock_options'][ $key ] = $new_value;
				return true;
			}
			return false;
		}

		$current = (string) get_option( $key, '' );
		if ( $current === $expected ) {
			return update_option( $key, $new_value );
		}

		return false;
	}
}

/**
 * Acquire global drain lock.
 *
 * add_option() uses the unique option name as the cross-request exclusion
 * mechanism. The lock expires so a fatal process cannot block the queue forever.
 * Expired lock takeover uses compare-and-swap to guarantee mutual exclusion.
 *
 * @return string Lock token or empty string.
 */
if ( ! function_exists( 'nvx_supabase_relay_queue_lock' ) ) {
	function nvx_supabase_relay_queue_lock(
		int $ttl = 0
	): string {
		$key   = 'nvx_supabase_relay_drain_lock_v1';
		$token = function_exists( 'wp_generate_uuid4' )
			? wp_generate_uuid4()
			: bin2hex( random_bytes( 16 ) );

		$lease = $ttl > 0
			? $ttl
			: ( function_exists( 'nvx_supabase_relay_queue_lock_ttl' )
				? nvx_supabase_relay_queue_lock_ttl()
				: (int) NVX_SUPABASE_RELAY_QUEUE_LOCK_TTL );

		$expires = nvx_supabase_relay_time() + $lease;

		$value = $expires . '|' . $token;

		if (
			add_option(
				$key,
				$value,
				'',
				false
			)
		) {
			return $token;
		}

		$current = (string) get_option(
			$key,
			''
		);

		$parts = explode(
			'|',
			$current,
			2
		);

		$current_expiry = isset( $parts[0] )
			? absint( $parts[0] )
			: 0;

		if (
			$current_expiry > 0
			&& $current_expiry <= nvx_supabase_relay_time()
		) {
			if (
				nvx_supabase_relay_compare_and_swap_option(
					$key,
					$current,
					$value
				)
			) {
				return $token;
			}
		}

		return '';
	}
}

/**
 * Atomically renew the drain lock for the active owner.
 *
 * @param string $token Lock ownership token.
 * @param int    $ttl   Renewal lease duration in seconds.
 * @return bool True if lock was renewed, false if lost or not owned.
 */
if ( ! function_exists( 'nvx_supabase_relay_queue_renew_lock' ) ) {
	function nvx_supabase_relay_queue_renew_lock(
		string $token,
		int $ttl = 0
	): bool {
		if ( '' === $token ) {
			return false;
		}

		$key = 'nvx_supabase_relay_drain_lock_v1';

		$current = (string) get_option(
			$key,
			''
		);

		$parts = explode(
			'|',
			$current,
			2
		);

		$current_expiry = isset( $parts[0] )
			? absint( $parts[0] )
			: 0;

		$current_token = isset( $parts[1] )
			? $parts[1]
			: '';

		if ( $current_token !== $token ) {
			return false;
		}

		if ( $current_expiry <= nvx_supabase_relay_time() ) {
			return false;
		}

		$lease = $ttl > 0
			? $ttl
			: ( function_exists( 'nvx_supabase_relay_queue_lock_ttl' )
				? nvx_supabase_relay_queue_lock_ttl()
				: (int) NVX_SUPABASE_RELAY_QUEUE_LOCK_TTL );

		$new_value = ( nvx_supabase_relay_time() + $lease ) . '|' . $token;

		return nvx_supabase_relay_compare_and_swap_option(
			$key,
			$current,
			$new_value
		);
	}
}

/**
 * Release the drain lock only when owned by this process.
 */
if ( ! function_exists( 'nvx_supabase_relay_queue_unlock' ) ) {
	function nvx_supabase_relay_queue_unlock(
		string $token
	): void {
		if ( '' === $token ) {
			return;
		}

		$key = 'nvx_supabase_relay_drain_lock_v1';

		global $wpdb;
		if ( isset( $wpdb ) && method_exists( $wpdb, 'query' ) && method_exists( $wpdb, 'prepare' ) && method_exists( $wpdb, 'esc_like' ) ) {
			$table = isset( $wpdb->options ) ? $wpdb->options : 'wp_options';
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE option_name = %s AND option_value LIKE %s",
					$key,
					'%|' . $wpdb->esc_like( $token )
				)
			);
			if ( function_exists( 'wp_cache_delete' ) ) {
				wp_cache_delete( $key, 'options' );
			}
			return;
		}

		$current = (string) get_option(
			$key,
			''
		);

		if (
			str_ends_with(
				$current,
				'|' . $token
			)
		) {
			delete_option( $key );
		}
	}
}

/**
 * Mark one queue item dead.
 */
if ( ! function_exists( 'nvx_supabase_relay_queue_mark_dead' ) ) {
	function nvx_supabase_relay_queue_mark_dead(
		int $post_id,
		string $endpoint,
		int $status,
		string $reason
	): void {
		wp_update_post(
			array(
				'ID'          => absint( $post_id ),
				'post_status' => 'draft',
			)
		);

		nvx_supabase_relay_log(
			$endpoint,
			'DEAD',
			$status,
			$reason
		);
	}
}

/**
 * Drain due outbox items.
 */
if ( ! function_exists( 'nvx_supabase_relay_queue_drain' ) ) {
	function nvx_supabase_relay_queue_drain(
		int $limit = NVX_SUPABASE_RELAY_QUEUE_BATCH
	): void {
		$limit = max(
			1,
			min(
				$limit,
				(int) NVX_SUPABASE_RELAY_QUEUE_BATCH
			)
		);

		$lease_ttl = function_exists( 'nvx_supabase_relay_queue_lock_ttl' )
			? nvx_supabase_relay_queue_lock_ttl( $limit )
			: (int) NVX_SUPABASE_RELAY_QUEUE_LOCK_TTL;

		$lock = nvx_supabase_relay_queue_lock( $lease_ttl );

		if ( '' === $lock ) {
			return;
		}

		try {
			$now = time();

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
							'value'   => (string) $now,
							'compare' => '<=',
							'type'    => 'NUMERIC',
						),
					),
				)
			);

			foreach ( $query->posts as $post ) {
				if ( ! $post instanceof WP_Post ) {
					continue;
				}

				$post_id = absint( $post->ID );

				if ( ! nvx_supabase_relay_queue_item_due( $post_id, $lock, $lease_ttl ) ) {
					continue;
				}

				$endpoint = sanitize_key(
					(string) get_post_meta(
						$post_id,
						'_nvx_relay_endpoint',
						true
					)
				);

				$origin = nvx_supabase_relay_sanitize_origin(
					(string) get_post_meta(
						$post_id,
						'_nvx_relay_origin',
						true
					)
				);

				$attempts = absint(
					get_post_meta(
						$post_id,
						'_nvx_relay_attempts',
						true
					)
				);

				$body = (string) $post->post_content;

				if (
					! nvx_supabase_relay_valid_body(
						$body
					)
				) {
					nvx_supabase_relay_queue_mark_dead(
						$post_id,
						$endpoint,
						0,
						'invalid_payload'
					);

					continue;
				}

				try {
					$response = nvx_supabase_relay_queue_send(
						$endpoint,
						$body,
						$origin
					);
				} catch ( Throwable $error ) {
					unset( $error );

					$response = new WP_Error(
						'nvx_relay_unexpected_transport',
						'Relay transport failed unexpectedly.'
					);
				}

				$class             = nvx_supabase_relay_classify(
					$response
				);
				$delivery_attempts = 1;

				if ( 401 === $class['status'] ) {
					try {
						$retry_response = nvx_supabase_relay_queue_send(
							$endpoint,
							$body,
							$origin,
							true
						);
					} catch ( Throwable $retry_error ) {
						unset( $retry_error );

						$retry_response = new WP_Error(
							'nvx_relay_unexpected_transport',
							'Relay transport failed unexpectedly.'
						);
					}

					$class             = nvx_supabase_relay_classify(
						$retry_response
					);
					$delivery_attempts = ( is_wp_error( $retry_response ) && 'nvx_runtime_bootstrap_unavailable' === $retry_response->get_error_code() )
						? 1
						: 2;
				}

				if ( ! nvx_supabase_relay_queue_lock_owned( $lock ) ) {
					break;
				}

				if ( 'SUCCESS' === $class['outcome'] ) {
					wp_delete_post(
						$post_id,
						true
					);

					nvx_supabase_relay_log(
						$endpoint,
						'DRAINED',
						$class['status']
					);

					continue;
				}

				$new_attempts = nvx_supabase_relay_queue_atomic_add_attempts(
					$post_id,
					$delivery_attempts
				);

				if (
					! $class['retryable']
					|| $new_attempts
					>= (int) NVX_SUPABASE_RELAY_QUEUE_MAX_TRIES
				) {
					nvx_supabase_relay_queue_mark_dead(
						$post_id,
						$endpoint,
						$class['status'],
						$class['reason']
					);

					continue;
				}

				$next_attempt = time()
					+ nvx_supabase_relay_queue_backoff_seconds(
						$new_attempts
					);

				nvx_supabase_relay_queue_set_next_attempt_monotonic(
					$post_id,
					$next_attempt
				);

				nvx_supabase_relay_log(
					$endpoint,
					$class['outcome'],
					$class['status'],
					'retry_scheduled'
				);
			}
		} finally {
			nvx_supabase_relay_queue_unlock(
				$lock
			);
		}
	}
}

add_action(
	NVX_SUPABASE_RELAY_QUEUE_CRON,
	'nvx_supabase_relay_queue_drain'
);

/**
 * Opportunistic drain after a request created queue work.
 *
 * Freshly enqueued items have next_attempt in the future, so shutdown no longer
 * creates an immediate retry. It only drains older work already due.
 */
if ( ! function_exists( 'nvx_supabase_relay_queue_shutdown_drain' ) ) {
	function nvx_supabase_relay_queue_shutdown_drain(): void {
		if (
			empty(
				$GLOBALS['nvx_supabase_relay_queue_dirty']
			)
		) {
			return;
		}

		nvx_supabase_relay_queue_drain( 3 );
	}
}

add_action(
	'shutdown',
	'nvx_supabase_relay_queue_shutdown_drain'
);

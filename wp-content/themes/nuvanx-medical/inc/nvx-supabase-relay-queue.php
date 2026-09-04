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

if ( ! defined( 'NVX_SUPABASE_RELAY_QUEUE_CAS_MAX_ATTEMPTS' ) ) {
	define( 'NVX_SUPABASE_RELAY_QUEUE_CAS_MAX_ATTEMPTS', 20 );
}

if ( ! defined( 'NVX_SUPABASE_RELAY_QUEUE_CLAIM_LEASE_SECONDS' ) ) {
	define( 'NVX_SUPABASE_RELAY_QUEUE_CLAIM_LEASE_SECONDS', 60 );
}

if ( ! defined( 'NVX_SUPABASE_RELAY_QUEUE_BUILDING_STATUS' ) ) {
	define( 'NVX_SUPABASE_RELAY_QUEUE_BUILDING_STATUS', 'nvx_relay_building' );
}

if ( ! defined( 'NVX_SUPABASE_RELAY_QUEUE_PREPARED_STATUS' ) ) {
	define( 'NVX_SUPABASE_RELAY_QUEUE_PREPARED_STATUS', 'nvx_relay_prepared' );
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

/** Register private outbox CPT. */
if ( ! function_exists( 'nvx_supabase_relay_queue_register_cpt' ) ) {
	function nvx_supabase_relay_queue_register_cpt(): void {
		register_post_status(
			NVX_SUPABASE_RELAY_QUEUE_BUILDING_STATUS,
			array(
				'label'                     => 'Building Supabase relay item',
				'public'                    => false,
				'internal'                  => true,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => false,
				'show_in_admin_status_list' => false,
			)
		);

		register_post_status(
			NVX_SUPABASE_RELAY_QUEUE_PREPARED_STATUS,
			array(
				'label'                     => 'Prepared Supabase relay item',
				'public'                    => false,
				'internal'                  => true,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => false,
				'show_in_admin_status_list' => false,
			)
		);

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

add_action( 'init', 'nvx_supabase_relay_queue_register_cpt', 5 );

/** Add five-minute drainage schedule. */
if ( ! function_exists( 'nvx_supabase_relay_queue_cron_schedules' ) ) {
	function nvx_supabase_relay_queue_cron_schedules( array $schedules ): array {
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
add_filter( 'cron_schedules', 'nvx_supabase_relay_queue_cron_schedules' );

/** Ensure the recurring drain exists once. */
if ( ! function_exists( 'nvx_supabase_relay_queue_schedule_cron' ) ) {
	function nvx_supabase_relay_queue_schedule_cron(): void {
		if ( wp_next_scheduled( NVX_SUPABASE_RELAY_QUEUE_CRON ) ) {
			return;
		}
		wp_schedule_event( time() + MINUTE_IN_SECONDS, 'nvx_relay_outbox_5min', NVX_SUPABASE_RELAY_QUEUE_CRON );
	}
}
add_action( 'init', 'nvx_supabase_relay_queue_schedule_cron', 20 );

/** Remove all scheduled outbox drains when the theme is switched. */
if ( ! function_exists( 'nvx_supabase_relay_queue_unschedule_cron' ) ) {
	function nvx_supabase_relay_queue_unschedule_cron(): void {
		wp_clear_scheduled_hook( NVX_SUPABASE_RELAY_QUEUE_CRON );
	}
}
add_action( 'switch_theme', 'nvx_supabase_relay_queue_unschedule_cron' );

/** Emit bounded non-PII telemetry. */
if ( ! function_exists( 'nvx_supabase_relay_log' ) ) {
	function nvx_supabase_relay_log( string $endpoint, string $outcome, int $status = 0, string $reason = '' ): void {
		$endpoint = sanitize_key( $endpoint );
		$outcome  = strtoupper( $outcome );
		$allowed  = array( 'SUCCESS', 'HTTP_4XX', 'HTTP_429', 'HTTP_5XX', 'TRANSPORT', 'QUEUED', 'DRAINED', 'DEAD' );
		if ( '' === $endpoint || ! in_array( $outcome, $allowed, true ) ) {
			return;
		}
		$line = 'NVX_SUPABASE_RELAY=' . $outcome . ' endpoint=' . $endpoint;
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

/** Classify one HTTP result. */
if ( ! function_exists( 'nvx_supabase_relay_classify' ) ) {
	function nvx_supabase_relay_classify( mixed $response ): array {
		if ( is_wp_error( $response ) ) {
			return array( 'outcome' => 'TRANSPORT', 'status' => 0, 'retryable' => true, 'reason' => sanitize_key( (string) $response->get_error_code() ) );
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

/** Backoff for a failed delivery attempt. */
if ( ! function_exists( 'nvx_supabase_relay_queue_backoff_seconds' ) ) {
	function nvx_supabase_relay_queue_backoff_seconds( int $attempt ): int {
		$schedule = array( 30, 60, 120, 300, 900, 1800, 3600, 21600 );
		$index    = max( 0, min( $attempt - 1, count( $schedule ) - 1 ) );
		return (int) $schedule[ $index ];
	}
}

/** Validate persisted relay JSON. */
if ( ! function_exists( 'nvx_supabase_relay_valid_body' ) ) {
	function nvx_supabase_relay_valid_body( string $body ): bool {
		if ( '' === $body || strlen( $body ) > (int) NVX_SUPABASE_RELAY_QUEUE_MAX_BODY_BYTES ) {
			return false;
		}
		json_decode( $body, true );
		return JSON_ERROR_NONE === json_last_error();
	}
}

/** Sanitize an Origin persisted for Google Click. */
if ( ! function_exists( 'nvx_supabase_relay_sanitize_origin' ) ) {
	function nvx_supabase_relay_sanitize_origin( string $origin ): string {
		if ( '' === trim( $origin ) ) {
			return '';
		}
		$origin = sanitize_url( $origin );
		if ( '' === $origin ) {
			return '';
		}
		$scheme = strtolower( (string) wp_parse_url( $origin, PHP_URL_SCHEME ) );
		$host   = strtolower( (string) wp_parse_url( $origin, PHP_URL_HOST ) );
		if ( 'https' !== $scheme || '' === $host ) {
			return '';
		}
		if ( function_exists( 'nvx_attribution_collector_allowed_hosts' ) && ! in_array( $host, nvx_attribution_collector_allowed_hosts(), true ) ) {
			return '';
		}
		return 'https://' . $host;
	}
}

/** Stable queue dedupe identifier. */
if ( ! function_exists( 'nvx_supabase_relay_dedupe_key' ) ) {
	function nvx_supabase_relay_dedupe_key( string $endpoint, string $body, string $origin = '' ): string {
		return hash( 'sha256', $endpoint . '|' . $origin . '|' . $body );
	}
}

/** Locate the first visible matching queue item. */
if ( ! function_exists( 'nvx_supabase_relay_existing_item' ) ) {
	function nvx_supabase_relay_existing_item( string $dedupe_key ): int {
		$ids = get_posts(
			array(
				'post_type'              => NVX_SUPABASE_RELAY_QUEUE_CPT,
				'post_status'            => array( 'pending', NVX_SUPABASE_RELAY_QUEUE_PREPARED_STATUS ),
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					array( 'key' => '_nvx_relay_dedupe_key', 'value' => $dedupe_key, 'compare' => '=' ),
				),
			)
		);
		foreach ( $ids as $candidate_id ) {
			$candidate_id = absint( $candidate_id );
			$post         = get_post( $candidate_id );
			if ( $post instanceof WP_Post && 'pending' === $post->post_status ) {
				return $candidate_id;
			}
			if ( $post instanceof WP_Post && NVX_SUPABASE_RELAY_QUEUE_PREPARED_STATUS === $post->post_status && nvx_supabase_relay_queue_item_ready( $candidate_id ) ) {
				return $candidate_id;
			}
		}
		return 0;
	}
}

/**
 * Retire non-canonical rows under the exact claim-row lock.
 *
 * In production the claim option is SELECT ... FOR UPDATE before enumeration,
 * so ownership cannot transfer between the per-candidate check and deletion.
 * If the caller already owns a transaction (terminal lifecycle), this function
 * reuses it and never commits the caller's transaction.
 *
 * @return bool True when cleanup completed under uninterrupted ownership.
 */
if ( ! function_exists( 'nvx_supabase_relay_queue_retire_duplicate_rows' ) ) {
	function nvx_supabase_relay_queue_retire_duplicate_rows( int $canonical_post_id, string $dedupe_key, string $expected_claim = '' ): bool {
		$canonical_post_id = absint( $canonical_post_id );
		$claim_key         = nvx_supabase_relay_queue_claim_key( $dedupe_key );
		global $wpdb;

		$has_database_fence = isset( $wpdb )
			&& method_exists( $wpdb, 'query' )
			&& method_exists( $wpdb, 'prepare' )
			&& method_exists( $wpdb, 'get_var' )
			&& isset( $wpdb->options );
		$started_transaction = false;

		if ( $has_database_fence ) {
			$options_table  = (string) $wpdb->options;
			$in_transaction = 1 === absint( $wpdb->get_var( 'SELECT @@in_transaction' ) );
			if ( ! $in_transaction ) {
				if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
					return false;
				}
				$started_transaction = true;
			}
			$locked_claim = (string) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT option_value FROM {$options_table} WHERE option_name = %s FOR UPDATE",
					$claim_key
				)
			);
			if ( '' === $expected_claim ) {
				$expected_claim = $locked_claim;
			}
			if ( '' === $expected_claim || $expected_claim !== $locked_claim ) {
				if ( $started_transaction ) {
					$wpdb->query( 'ROLLBACK' );
				}
				return false;
			}
		} else {
			if ( '' === $expected_claim ) {
				$expected_claim = nvx_supabase_relay_queue_fresh_option( $claim_key );
			}
			if ( '' === $expected_claim || $expected_claim !== nvx_supabase_relay_queue_fresh_option( $claim_key ) ) {
				return false;
			}
		}

		$ids = get_posts(
			array(
				'post_type'              => NVX_SUPABASE_RELAY_QUEUE_CPT,
				'post_status'            => array( 'pending', NVX_SUPABASE_RELAY_QUEUE_PREPARED_STATUS ),
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					array( 'key' => '_nvx_relay_dedupe_key', 'value' => $dedupe_key, 'compare' => '=' ),
				),
			)
		);
		foreach ( $ids as $candidate_id ) {
			$candidate_id = absint( $candidate_id );
			if ( $candidate_id < 1 || $candidate_id === $canonical_post_id ) {
				continue;
			}
			if ( ! $has_database_fence && $expected_claim !== nvx_supabase_relay_queue_fresh_option( $claim_key ) ) {
				return false;
			}
			if ( (string) $candidate_id === ( $has_database_fence ? $locked_claim : nvx_supabase_relay_queue_fresh_option( $claim_key ) ) ) {
				if ( $started_transaction ) {
					$wpdb->query( 'ROLLBACK' );
				}
				return false;
			}

			// Lifecycle fencing protocol: transition candidate to non-adoptable draft status before deletion.
			$cand_post    = get_post( $candidate_id );
			$orig_status  = $cand_post instanceof WP_Post ? (string) $cand_post->post_status : 'pending';
			$transitioned = wp_update_post(
				array(
					'ID'          => $candidate_id,
					'post_status' => 'draft',
				),
				true
			);
			if ( is_wp_error( $transitioned ) || absint( $transitioned ) !== $candidate_id ) {
				if ( $started_transaction ) {
					$wpdb->query( 'ROLLBACK' );
				}
				return false;
			}

			// Verify ownership did not transfer during or immediately after the per-candidate status transition.
			if ( ! $has_database_fence ) {
				$fresh = nvx_supabase_relay_queue_fresh_option( $claim_key );
				if ( $expected_claim !== $fresh || (string) $candidate_id === $fresh ) {
					wp_update_post(
						array(
							'ID'          => $candidate_id,
							'post_status' => $orig_status,
						),
						true
					);
					return false;
				}
			}

			if ( false === wp_delete_post( $candidate_id, true ) ) {
				if ( $started_transaction ) {
					$wpdb->query( 'ROLLBACK' );
				}
				return false;
			}
		}

		if ( $started_transaction ) {
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				$wpdb->query( 'ROLLBACK' );
				return false;
			}
			if ( function_exists( 'wp_cache_delete' ) ) {
				wp_cache_delete( $claim_key, 'options' );
			}
			return true;
		}
		return $has_database_fence || $expected_claim === nvx_supabase_relay_queue_fresh_option( $claim_key );
	}
}

/** Resolve server-only signing root. */
if ( ! function_exists( 'nvx_supabase_relay_google_click_token' ) ) {
	function nvx_supabase_relay_google_click_token(): string {
		if ( function_exists( 'nvx_lead_captured_hubspot_token' ) ) {
			return trim( (string) nvx_lead_captured_hubspot_token() );
		}
		if ( defined( 'NVX_HUBSPOT_ACCESS_TOKEN' ) ) {
			return trim( (string) NVX_HUBSPOT_ACCESS_TOKEN );
		}
		return '';
	}
}

/** Google Click domain-separated HMAC key. */
if ( ! function_exists( 'nvx_supabase_relay_google_click_hmac_key' ) ) {
	function nvx_supabase_relay_google_click_hmac_key( string $token ): string {
		return hash_hmac( 'sha256', NVX_GOOGLE_CLICK_HMAC_CONTEXT, $token );
	}
}

/** Ensure Supabase runtime signing state exists. */
if ( ! function_exists( 'nvx_supabase_relay_ensure_runtime_bootstrap' ) ) {
	function nvx_supabase_relay_ensure_runtime_bootstrap( string $token, bool $force = false ): true|WP_Error {
		if ( '' === $token ) {
			return new WP_Error( 'nvx_relay_signing_key_missing', 'Relay signing key is unavailable.' );
		}
		if ( ! function_exists( 'nvx_lead_captured_bootstrap_runtime' ) ) {
			return new WP_Error( 'nvx_runtime_bootstrap_owner_missing', 'Runtime bootstrap owner is unavailable.' );
		}
		if ( ! nvx_lead_captured_bootstrap_runtime( $token, $force ) ) {
			return new WP_Error( 'nvx_runtime_bootstrap_unavailable', 'Runtime bootstrap is temporarily unavailable.' );
		}
		return true;
	}
}

/** POST Google Click with fresh timestamp/signature. */
if ( ! function_exists( 'nvx_supabase_relay_google_click_post_signed' ) ) {
	function nvx_supabase_relay_google_click_post_signed( string $url, string $body, string $origin, string $token ): array|WP_Error {
		$timestamp = (string) time();
		$hmac_key  = nvx_supabase_relay_google_click_hmac_key( $token );
		$signature = hash_hmac( 'sha256', $timestamp . '.' . $body, $hmac_key );
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
				'body' => $body,
			)
		);
	}
}

/** Force a fresh non-autoloaded option read. */
if ( ! function_exists( 'nvx_supabase_relay_queue_fresh_option' ) ) {
	function nvx_supabase_relay_queue_fresh_option( string $key ): string {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $key, 'options' );
		}
		return (string) get_option( $key, '' );
	}
}

/** Canonical option-key for a dedupe identity claim. */
if ( ! function_exists( 'nvx_supabase_relay_queue_claim_key' ) ) {
	function nvx_supabase_relay_queue_claim_key( string $dedupe_key ): string {
		return 'nvx_relay_claim_' . $dedupe_key;
	}
}

/** Atomically replace an option value only if it matches expected value. */
if ( ! function_exists( 'nvx_supabase_relay_compare_and_swap_option' ) ) {
	function nvx_supabase_relay_compare_and_swap_option( string $key, string $expected, string $new_value ): bool {
		global $wpdb;
		if ( isset( $wpdb ) && method_exists( $wpdb, 'query' ) && method_exists( $wpdb, 'prepare' ) ) {
			$table   = isset( $wpdb->options ) ? $wpdb->options : 'wp_options';
			$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET option_value = %s WHERE option_name = %s AND option_value = %s", $new_value, $key, $expected ) );
			if ( $updated > 0 ) {
				if ( function_exists( 'wp_cache_set' ) ) {
					wp_cache_set( $key, $new_value, 'options' );
				}
				return true;
			}
			return false;
		}
		if ( isset( $GLOBALS['nvx_mock_options'] ) && is_array( $GLOBALS['nvx_mock_options'] ) ) {
			if ( isset( $GLOBALS['nvx_mock_option_cas_conflict_values'][ $key ] ) ) {
				$GLOBALS['nvx_mock_options'][ $key ] = (string) $GLOBALS['nvx_mock_option_cas_conflict_values'][ $key ];
				unset( $GLOBALS['nvx_mock_option_cas_conflict_values'][ $key ] );
				return false;
			}
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

/** Atomically transition one queue row only from the expected status. */
if ( ! function_exists( 'nvx_supabase_relay_queue_compare_and_swap_status' ) ) {
	function nvx_supabase_relay_queue_compare_and_swap_status( int $post_id, string $expected_status, string $new_status ): bool {
		$post_id = absint( $post_id );
		if ( $post_id < 1 || '' === $expected_status || '' === $new_status || $expected_status === $new_status ) {
			return false;
		}

		global $wpdb;
		if (
			isset( $wpdb )
			&& method_exists( $wpdb, 'query' )
			&& method_exists( $wpdb, 'prepare' )
			&& isset( $wpdb->posts )
		) {
			$posts_table = (string) $wpdb->posts;
			$updated     = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$posts_table} SET post_status = %s WHERE ID = %d AND post_type = %s AND post_status = %s",
					$new_status,
					$post_id,
					NVX_SUPABASE_RELAY_QUEUE_CPT,
					$expected_status
				)
			);
			if ( 1 !== $updated ) {
				return false;
			}
			if ( function_exists( 'clean_post_cache' ) ) {
				clean_post_cache( $post_id );
			}
			if ( function_exists( 'wp_cache_delete' ) ) {
				wp_cache_delete( $post_id, 'posts' );
			}
			$post = get_post( $post_id );
			return $post instanceof WP_Post && $new_status === (string) $post->post_status;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || $expected_status !== (string) $post->post_status ) {
			return false;
		}
		$updated = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => $new_status,
			),
			true
		);
		if ( is_wp_error( $updated ) || absint( $updated ) !== $post_id ) {
			return false;
		}
		$post = get_post( $post_id );
		return $post instanceof WP_Post && $new_status === (string) $post->post_status;
	}
}

/** Release a claim conditionally only if it still has the expected value. */
if ( ! function_exists( 'nvx_supabase_relay_queue_release_claim' ) ) {
	function nvx_supabase_relay_queue_release_claim( string $dedupe_key, string $expected_value ): bool {
		if ( '' === $dedupe_key || '' === $expected_value ) {
			return false;
		}
		$claim_key = nvx_supabase_relay_queue_claim_key( $dedupe_key );
		global $wpdb;
		if ( isset( $wpdb ) && method_exists( $wpdb, 'query' ) && method_exists( $wpdb, 'prepare' ) ) {
			$table   = isset( $wpdb->options ) ? $wpdb->options : 'wp_options';
			$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE option_name = %s AND option_value = %s", $claim_key, $expected_value ) );
			if ( $deleted > 0 && function_exists( 'wp_cache_delete' ) ) {
				wp_cache_delete( $claim_key, 'options' );
			}
			return $deleted > 0;
		}
		if ( isset( $GLOBALS['nvx_mock_options'] ) && is_array( $GLOBALS['nvx_mock_options'] ) ) {
			if ( ( $GLOBALS['nvx_mock_options'][ $claim_key ] ?? '' ) === $expected_value ) {
				unset( $GLOBALS['nvx_mock_options'][ $claim_key ] );
				if ( isset( $GLOBALS['nvx_mock_deleted_options'] ) && is_array( $GLOBALS['nvx_mock_deleted_options'] ) ) {
					$GLOBALS['nvx_mock_deleted_options'][] = $claim_key;
				}
				return true;
			}
			return false;
		}
		$current = (string) get_option( $claim_key, '' );
		if ( $current === $expected_value ) {
			return delete_option( $claim_key );
		}
		return false;
	}
}

/** Whether the explicit publication readiness marker is durable. */
if ( ! function_exists( 'nvx_supabase_relay_queue_item_ready' ) ) {
	function nvx_supabase_relay_queue_item_ready( int $post_id ): bool {
		return '1' === (string) get_post_meta( absint( $post_id ), '_nvx_relay_ready', true );
	}
}

/** Whether an in-flight generation token is still live. */
if ( ! function_exists( 'nvx_supabase_relay_queue_publish_claim_live' ) ) {
	function nvx_supabase_relay_queue_publish_claim_live( string $claim ): bool {
		$parts  = explode( '|', $claim, 2 );
		$expiry = isset( $parts[0] ) && is_numeric( $parts[0] ) ? (int) $parts[0] : 0;
		return $expiry > nvx_supabase_relay_time();
	}
}

/** Create a live terminal claim that existing publisher fencing already respects. */
if ( ! function_exists( 'nvx_supabase_relay_queue_terminal_claim_value' ) ) {
	function nvx_supabase_relay_queue_terminal_claim_value( int $post_id ): string {
		$post_id = absint( $post_id );
		if ( $post_id < 1 ) {
			return '';
		}
		$token = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : bin2hex( random_bytes( 16 ) );
		$lease = function_exists( 'nvx_supabase_relay_queue_lock_ttl' )
			? nvx_supabase_relay_queue_lock_ttl( 1 )
			: max( (int) NVX_SUPABASE_RELAY_QUEUE_LOCK_TTL, (int) NVX_SUPABASE_RELAY_QUEUE_CLAIM_LEASE_SECONDS );
		return ( nvx_supabase_relay_time() + $lease ) . '|terminal:' . $post_id . ':' . $token;
	}
}

/** Acquire a terminal fence by replacing the exact numeric publication owner. */
if ( ! function_exists( 'nvx_supabase_relay_queue_begin_terminal_lifecycle' ) ) {
	function nvx_supabase_relay_queue_begin_terminal_lifecycle( int $post_id, string $dedupe_key ): string {
		$post_id = absint( $post_id );
		if ( $post_id < 1 || 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $dedupe_key ) ) {
			return '';
		}
		$terminal_claim = nvx_supabase_relay_queue_terminal_claim_value( $post_id );
		if ( '' === $terminal_claim ) {
			return '';
		}
		$claim_key = nvx_supabase_relay_queue_claim_key( $dedupe_key );
		return nvx_supabase_relay_compare_and_swap_option( $claim_key, (string) $post_id, $terminal_claim )
			? $terminal_claim
			: '';
	}
}

/** Finish a fallback terminal lifecycle under the exact terminal claim. */
if ( ! function_exists( 'nvx_supabase_relay_queue_finish_terminal_lifecycle' ) ) {
	function nvx_supabase_relay_queue_finish_terminal_lifecycle( int $post_id, string $dedupe_key, string $terminal_claim ): bool {
		$post_id = absint( $post_id );
		if ( $post_id < 1 || '' === $terminal_claim || 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $dedupe_key ) ) {
			return false;
		}
		$claim_key = nvx_supabase_relay_queue_claim_key( $dedupe_key );
		if ( $terminal_claim !== nvx_supabase_relay_queue_fresh_option( $claim_key ) ) {
			return false;
		}
		if ( ! nvx_supabase_relay_queue_retire_duplicate_rows( $post_id, $dedupe_key, $terminal_claim ) ) {
			return false;
		}
		return nvx_supabase_relay_queue_release_claim( $dedupe_key, $terminal_claim );
	}
}

/**
 * Whether a prepared row is a structurally complete publication whose only
 * missing write is the readiness marker.
 *
 * Readiness is the final metadata write of a publication. A prepared row that
 * already carries a valid identity and durable due-visibility, and whose
 * in-flight publish claim is empty or expired, is an interrupted or legacy
 * publication that can be safely recovered instead of stranded or deleted.
 */
if ( ! function_exists( 'nvx_supabase_relay_queue_prepared_row_recoverable' ) ) {
	function nvx_supabase_relay_queue_prepared_row_recoverable( int $post_id, string $dedupe_key ): bool {
		$post_id = absint( $post_id );
		if ( $post_id < 1 || 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $dedupe_key ) ) {
			return false;
		}
		$stored_dedupe = (string) get_post_meta( $post_id, '_nvx_relay_dedupe_key', true );
		if ( '' === $stored_dedupe || ! hash_equals( $dedupe_key, $stored_dedupe ) ) {
			return false;
		}
		if ( '' === (string) get_post_meta( $post_id, '_nvx_relay_next_attempt', true ) ) {
			return false;
		}
		$publish_claim = (string) get_post_meta( $post_id, '_nvx_relay_publish_claim', true );
		return '' === $publish_claim || ! nvx_supabase_relay_queue_publish_claim_live( $publish_claim );
	}
}

/**
 * Decide whether a prepared row belongs to the exact publication generation.
 * Pending rows are already public queue rows and remain rollout-compatible.
 */
if ( ! function_exists( 'nvx_supabase_relay_queue_item_adoptable_for_claim' ) ) {
	function nvx_supabase_relay_queue_item_adoptable_for_claim( int $post_id, string $dedupe_key, string $claim_value ): bool {
		$post_id = absint( $post_id );
		$post    = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return false;
		}
		$stored_dedupe = (string) get_post_meta( $post_id, '_nvx_relay_dedupe_key', true );
		if ( '' === $stored_dedupe || ! hash_equals( $dedupe_key, $stored_dedupe ) ) {
			return false;
		}
		if ( 'pending' === $post->post_status ) {
			return true;
		}
		if ( NVX_SUPABASE_RELAY_QUEUE_PREPARED_STATUS !== $post->post_status || ! nvx_supabase_relay_queue_item_ready( $post_id ) ) {
			return false;
		}
		$publish_claim = (string) get_post_meta( $post_id, '_nvx_relay_publish_claim', true );
		return '' !== $publish_claim && '' !== $claim_value && hash_equals( $claim_value, $publish_claim );
	}
}

/** Verify whether a post is a valid pending item. */
if ( ! function_exists( 'nvx_supabase_relay_queue_is_valid_pending_item' ) ) {
	function nvx_supabase_relay_queue_is_valid_pending_item( int $post_id, string $dedupe_key ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}
		$post = get_post( $post_id );
		if ( ! $post || 'pending' !== ( $post->post_status ?? '' ) ) {
			return false;
		}
		$stored_dedupe = (string) get_post_meta( $post_id, '_nvx_relay_dedupe_key', true );
		return '' !== $stored_dedupe && hash_equals( $dedupe_key, $stored_dedupe );
	}
}

/** Verify whether a prepared row matches a dedupe identity. */
if ( ! function_exists( 'nvx_supabase_relay_queue_is_valid_prepared_item' ) ) {
	function nvx_supabase_relay_queue_is_valid_prepared_item( int $post_id, string $dedupe_key ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}
		$post = get_post( $post_id );
		if ( ! $post || NVX_SUPABASE_RELAY_QUEUE_PREPARED_STATUS !== ( $post->post_status ?? '' ) ) {
			return false;
		}
		$stored_dedupe = (string) get_post_meta( $post_id, '_nvx_relay_dedupe_key', true );
		return '' !== $stored_dedupe && hash_equals( $dedupe_key, $stored_dedupe );
	}
}

/** Convert an exactly fenced prepared row into a drainable pending row. */
if ( ! function_exists( 'nvx_supabase_relay_queue_finalize_publication' ) ) {
	function nvx_supabase_relay_queue_finalize_publication( int $post_id, string $dedupe_key ): bool {
		$post_id   = absint( $post_id );
		$claim_key = nvx_supabase_relay_queue_claim_key( $dedupe_key );
		$expected  = (string) $post_id;
		if ( $expected !== nvx_supabase_relay_queue_fresh_option( $claim_key ) ) {
			return false;
		}
		$publish_claim = (string) get_post_meta( $post_id, '_nvx_relay_publish_claim', true );
		if ( '' !== $publish_claim && ! nvx_supabase_relay_queue_item_ready( $post_id ) ) {
			$has_due_visibility = '' !== (string) get_post_meta( $post_id, '_nvx_relay_next_attempt', true );
			if ( ! $has_due_visibility ) {
				return false;
			}
			if ( ! add_post_meta( $post_id, '_nvx_relay_ready', '1', true ) && ! nvx_supabase_relay_queue_item_ready( $post_id ) ) {
				return false;
			}
		}
		if ( ! nvx_supabase_relay_queue_retire_duplicate_rows( $post_id, $dedupe_key, $expected ) ) {
			return false;
		}
		if ( nvx_supabase_relay_queue_is_valid_pending_item( $post_id, $dedupe_key ) ) {
			return true;
		}
		if ( ! nvx_supabase_relay_queue_is_valid_prepared_item( $post_id, $dedupe_key ) ) {
			return false;
		}
		$updated = wp_update_post( array( 'ID' => $post_id, 'post_status' => 'pending' ), true );
		if ( is_wp_error( $updated ) || absint( $updated ) !== $post_id ) {
			return false;
		}
		if ( function_exists( 'clean_post_cache' ) ) {
			clean_post_cache( $post_id );
		}
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $post_id, 'posts' );
			wp_cache_delete( $post_id, 'post_meta' );
		}
		return $expected === nvx_supabase_relay_queue_fresh_option( $claim_key ) && nvx_supabase_relay_queue_is_valid_pending_item( $post_id, $dedupe_key );
	}
}

/** Acquire or verify the durable publication fence for one item. */
if ( ! function_exists( 'nvx_supabase_relay_queue_acquire_publication_fence' ) ) {
	function nvx_supabase_relay_queue_acquire_publication_fence( int $post_id, string $dedupe_key ): bool {
		$post_id     = absint( $post_id );
		$is_pending  = nvx_supabase_relay_queue_is_valid_pending_item( $post_id, $dedupe_key );
		$is_prepared = nvx_supabase_relay_queue_is_valid_prepared_item( $post_id, $dedupe_key );
		if ( $post_id < 1 || '' === $dedupe_key || ( ! $is_pending && ! $is_prepared ) ) {
			return false;
		}
		$claim_key = nvx_supabase_relay_queue_claim_key( $dedupe_key );
		$expected  = (string) $post_id;
		$current   = nvx_supabase_relay_queue_fresh_option( $claim_key );
		if ( $expected === $current ) {
			return nvx_supabase_relay_queue_finalize_publication( $post_id, $dedupe_key );
		}
		if ( '' === $current ) {
			if ( $is_prepared && ! nvx_supabase_relay_queue_prepared_row_recoverable( $post_id, $dedupe_key ) ) {
				wp_delete_post( $post_id, true );
				return false;
			}
			if ( add_option( $claim_key, $expected, '', false ) ) {
				return nvx_supabase_relay_queue_finalize_publication( $post_id, $dedupe_key );
			}
			return $expected === nvx_supabase_relay_queue_fresh_option( $claim_key ) && nvx_supabase_relay_queue_finalize_publication( $post_id, $dedupe_key );
		}
		if ( ctype_digit( $current ) ) {
			$current_post_id = absint( $current );
			if ( nvx_supabase_relay_queue_is_valid_pending_item( $current_post_id, $dedupe_key ) || nvx_supabase_relay_queue_is_valid_prepared_item( $current_post_id, $dedupe_key ) ) {
				nvx_supabase_relay_queue_retire_duplicate_rows( $current_post_id, $dedupe_key, $current );
				return false;
			}
			$acquired = nvx_supabase_relay_compare_and_swap_option( $claim_key, $current, $expected );
			return $acquired && nvx_supabase_relay_queue_finalize_publication( $post_id, $dedupe_key );
		}
		if ( nvx_supabase_relay_queue_publish_claim_live( $current ) ) {
			return false;
		}
		if ( $is_prepared ) {
			$publish_claim = (string) get_post_meta( $post_id, '_nvx_relay_publish_claim', true );
			if (
				'' !== $publish_claim
				&& ! nvx_supabase_relay_queue_item_adoptable_for_claim( $post_id, $dedupe_key, $current )
				&& ! nvx_supabase_relay_queue_prepared_row_recoverable( $post_id, $dedupe_key )
			) {
				wp_delete_post( $post_id, true );
				return false;
			}
		}
		$acquired = nvx_supabase_relay_compare_and_swap_option( $claim_key, $current, $expected );
		return $acquired && nvx_supabase_relay_queue_finalize_publication( $post_id, $dedupe_key );
	}
}

/** Atomic attempt accumulation primitive. */
if ( ! function_exists( 'nvx_supabase_relay_queue_atomic_add_attempts' ) ) {
	function nvx_supabase_relay_queue_atomic_add_attempts( int $post_id, int $add_attempts ): ?int {
		$post_id      = absint( $post_id );
		$max_tries    = (int) NVX_SUPABASE_RELAY_QUEUE_MAX_TRIES;
		$max_attempts = max( 1, (int) NVX_SUPABASE_RELAY_QUEUE_CAS_MAX_ATTEMPTS );
		for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
			$current = absint( get_post_meta( $post_id, '_nvx_relay_attempts', true ) );
			$new_val = min( $max_tries, $current + $add_attempts );
			if ( $current === $new_val ) {
				return $new_val;
			}
			if ( update_post_meta( $post_id, '_nvx_relay_attempts', (string) $new_val, (string) $current ) ) {
				return $new_val;
			}
			if ( function_exists( 'wp_cache_delete' ) ) {
				wp_cache_delete( $post_id, 'post_meta' );
			}
		}
		return null;
	}
}

/** Monotonic next-attempt primitive. */
if ( ! function_exists( 'nvx_supabase_relay_queue_set_next_attempt_monotonic' ) ) {
	function nvx_supabase_relay_queue_set_next_attempt_monotonic( int $post_id, int $proposed_next_attempt ): bool {
		$post_id      = absint( $post_id );
		$max_attempts = max( 1, (int) NVX_SUPABASE_RELAY_QUEUE_CAS_MAX_ATTEMPTS );
		for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
			$current = absint( get_post_meta( $post_id, '_nvx_relay_next_attempt', true ) );
			if ( $proposed_next_attempt <= $current ) {
				return true;
			}
			if ( update_post_meta( $post_id, '_nvx_relay_next_attempt', (string) $proposed_next_attempt, (string) $current ) ) {
				return true;
			}
			if ( function_exists( 'wp_cache_delete' ) ) {
				wp_cache_delete( $post_id, 'post_meta' );
			}
		}
		return false;
	}
}

/** Whether a BUILDING row has exceeded its durable construction grace window. */
if ( ! function_exists( 'nvx_supabase_relay_queue_building_expired' ) ) {
	function nvx_supabase_relay_queue_building_expired( WP_Post $post ): bool {
		$created_gmt = trim( (string) ( $post->post_date_gmt ?? '' ) );
		if ( '' === $created_gmt || '0000-00-00 00:00:00' === $created_gmt ) {
			return false;
		}
		$created = strtotime( $created_gmt . ' UTC' );
		if ( false === $created || $created < 1 ) {
			return false;
		}
		$grace = max( 1, (int) NVX_SUPABASE_RELAY_QUEUE_CLAIM_LEASE_SECONDS );
		return ( $created + $grace ) <= nvx_supabase_relay_time();
	}
}

/** Whether a BUILDING row already contains a complete recoverable publication. */
if ( ! function_exists( 'nvx_supabase_relay_queue_building_complete' ) ) {
	function nvx_supabase_relay_queue_building_complete( int $post_id ): bool {
		$post_id = absint( $post_id );
		$post    = get_post( $post_id );
		if ( ! $post instanceof WP_Post || NVX_SUPABASE_RELAY_QUEUE_BUILDING_STATUS !== (string) $post->post_status ) {
			return false;
		}

		$endpoint = sanitize_key( (string) get_post_meta( $post_id, '_nvx_relay_endpoint', true ) );
		$origin   = nvx_supabase_relay_sanitize_origin( (string) get_post_meta( $post_id, '_nvx_relay_origin', true ) );
		$body     = (string) $post->post_content;
		$dedupe   = (string) get_post_meta( $post_id, '_nvx_relay_dedupe_key', true );
		$claim    = (string) get_post_meta( $post_id, '_nvx_relay_publish_claim', true );
		$due      = (string) get_post_meta( $post_id, '_nvx_relay_next_attempt', true );
		$attempts = absint( get_post_meta( $post_id, '_nvx_relay_attempts', true ) );
		$endpoints = nvx_supabase_relay_queue_endpoints();

		if ( ! isset( $endpoints[ $endpoint ] ) || '' === $endpoints[ $endpoint ] || ! nvx_supabase_relay_valid_body( $body ) ) {
			return false;
		}
		if ( 'google_click' === $endpoint && '' === $origin ) {
			return false;
		}
		if ( 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $dedupe ) || ! hash_equals( nvx_supabase_relay_dedupe_key( $endpoint, $body, $origin ), $dedupe ) ) {
			return false;
		}
		if ( 1 !== preg_match( '/\A[0-9]+\|.+\z/', $claim ) || '' === $due || 1 !== preg_match( '/\A[0-9]+\z/', $due ) || $attempts < 1 ) {
			return false;
		}
		return nvx_supabase_relay_queue_item_ready( $post_id );
	}
}

/** Recover one expired BUILDING row without racing the old or successor publisher. */
if ( ! function_exists( 'nvx_supabase_relay_queue_recover_building_item' ) ) {
	function nvx_supabase_relay_queue_recover_building_item( int $post_id ): bool {
		$post_id = absint( $post_id );
		$post    = get_post( $post_id );
		if ( ! $post instanceof WP_Post || NVX_SUPABASE_RELAY_QUEUE_BUILDING_STATUS !== (string) $post->post_status ) {
			return false;
		}

		$publish_claim = (string) get_post_meta( $post_id, '_nvx_relay_publish_claim', true );
		if ( '' !== $publish_claim && nvx_supabase_relay_queue_publish_claim_live( $publish_claim ) ) {
			return false;
		}
		if ( ! nvx_supabase_relay_queue_building_expired( $post ) ) {
			return false;
		}

		$endpoint   = sanitize_key( (string) get_post_meta( $post_id, '_nvx_relay_endpoint', true ) );
		$dedupe_key = (string) get_post_meta( $post_id, '_nvx_relay_dedupe_key', true );
		$dedupe_ok  = 1 === preg_match( '/\A[a-f0-9]{64}\z/', $dedupe_key );
		$complete   = $dedupe_ok && nvx_supabase_relay_queue_building_complete( $post_id );

		if ( $complete ) {
			$claim_key = nvx_supabase_relay_queue_claim_key( $dedupe_key );
			$current   = nvx_supabase_relay_queue_fresh_option( $claim_key );
			if ( '' !== $current && ! ctype_digit( $current ) && nvx_supabase_relay_queue_publish_claim_live( $current ) ) {
				return false;
			}
			if ( ctype_digit( $current ) ) {
				$current_post_id = absint( $current );
				if (
					$current_post_id !== $post_id
					&& ( nvx_supabase_relay_queue_is_valid_pending_item( $current_post_id, $dedupe_key ) || nvx_supabase_relay_queue_is_valid_prepared_item( $current_post_id, $dedupe_key ) )
				) {
					if ( nvx_supabase_relay_queue_compare_and_swap_status( $post_id, NVX_SUPABASE_RELAY_QUEUE_BUILDING_STATUS, 'draft' ) ) {
						nvx_supabase_relay_log( $endpoint, 'DEAD', 0, 'building_superseded' );
					}
					return false;
				}
			}

			if ( ! nvx_supabase_relay_queue_compare_and_swap_status( $post_id, NVX_SUPABASE_RELAY_QUEUE_BUILDING_STATUS, NVX_SUPABASE_RELAY_QUEUE_PREPARED_STATUS ) ) {
				return false;
			}
			return nvx_supabase_relay_queue_acquire_publication_fence( $post_id, $dedupe_key );
		}

		if ( ! nvx_supabase_relay_queue_compare_and_swap_status( $post_id, NVX_SUPABASE_RELAY_QUEUE_BUILDING_STATUS, 'draft' ) ) {
			return false;
		}
		if ( $dedupe_ok && '' !== $publish_claim ) {
			nvx_supabase_relay_queue_release_claim( $dedupe_key, $publish_claim );
		}
		nvx_supabase_relay_log( $endpoint, 'DEAD', 0, 'building_publication_incomplete' );
		return false;
	}
}

/** Discover a bounded oldest-first set of BUILDING rows and recover expired ones. */
if ( ! function_exists( 'nvx_supabase_relay_queue_recover_building_rows' ) ) {
	function nvx_supabase_relay_queue_recover_building_rows( int $limit ): void {
		$limit = max( 1, min( $limit, (int) NVX_SUPABASE_RELAY_QUEUE_BATCH ) );
		$query = new WP_Query(
			array(
				'post_type'              => NVX_SUPABASE_RELAY_QUEUE_CPT,
				'post_status'            => array( NVX_SUPABASE_RELAY_QUEUE_BUILDING_STATUS ),
				'posts_per_page'         => $limit,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
			)
		);
		foreach ( $query->posts as $post ) {
			if ( $post instanceof WP_Post ) {
				nvx_supabase_relay_queue_recover_building_item( absint( $post->ID ) );
			}
		}
	}
}

/** Recover a prepared publication that never received its final due marker. */
if ( ! function_exists( 'nvx_supabase_relay_queue_recover_prepared_without_due' ) ) {
	function nvx_supabase_relay_queue_recover_prepared_without_due( int $post_id ): bool {
		$post_id = absint( $post_id );
		$post    = get_post( $post_id );
		if ( ! $post instanceof WP_Post || NVX_SUPABASE_RELAY_QUEUE_PREPARED_STATUS !== $post->post_status ) {
			return false;
		}
		if ( '' !== (string) get_post_meta( $post_id, '_nvx_relay_next_attempt', true ) ) {
			return false;
		}

		$dedupe_key    = (string) get_post_meta( $post_id, '_nvx_relay_dedupe_key', true );
		$endpoint      = sanitize_key( (string) get_post_meta( $post_id, '_nvx_relay_endpoint', true ) );
		$publish_claim = (string) get_post_meta( $post_id, '_nvx_relay_publish_claim', true );

		if ( '' !== $publish_claim && nvx_supabase_relay_queue_publish_claim_live( $publish_claim ) ) {
			return false;
		}

		if ( 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $dedupe_key ) ) {
			$updated = wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ), true );
			if ( is_wp_error( $updated ) || absint( $updated ) !== $post_id ) {
				nvx_supabase_relay_log( $endpoint, 'TRANSPORT', 0, 'quarantine_transition_failed' );
				return false;
			}
			nvx_supabase_relay_log( $endpoint, 'DEAD', 0, 'invalid_dedupe_metadata' );
			return false;
		}

		if ( ! nvx_supabase_relay_queue_item_ready( $post_id ) ) {
			$updated = wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ), true );
			if ( is_wp_error( $updated ) || absint( $updated ) !== $post_id ) {
				nvx_supabase_relay_log( $endpoint, 'TRANSPORT', 0, 'quarantine_transition_failed' );
				return false;
			}
			nvx_supabase_relay_log( $endpoint, 'DEAD', 0, 'publication_incomplete' );
			return false;
		}

		$due = (string) nvx_supabase_relay_time();
		if ( ! add_post_meta( $post_id, '_nvx_relay_next_attempt', $due, true ) ) {
			$current_due = absint( get_post_meta( $post_id, '_nvx_relay_next_attempt', true ) );
			if ( $current_due < 1 && ! nvx_supabase_relay_queue_set_next_attempt_monotonic( $post_id, absint( $due ) ) ) {
				nvx_supabase_relay_log( $endpoint, 'TRANSPORT', 0, 'recovery_due_write_failed' );
				return false;
			}
		}

		if ( ! nvx_supabase_relay_queue_acquire_publication_fence( $post_id, $dedupe_key ) ) {
			return false;
		}
		return true;
	}
}

/** Discover prepared rows hidden from the normal due query and recover them. */
if ( ! function_exists( 'nvx_supabase_relay_queue_recover_invisible_prepared' ) ) {
	function nvx_supabase_relay_queue_recover_invisible_prepared( int $limit ): void {
		$limit = max( 1, min( $limit, (int) NVX_SUPABASE_RELAY_QUEUE_BATCH ) );
		$query = new WP_Query(
			array(
				'post_type'              => NVX_SUPABASE_RELAY_QUEUE_CPT,
				'post_status'            => array( NVX_SUPABASE_RELAY_QUEUE_PREPARED_STATUS ),
				'posts_per_page'         => $limit,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					array( 'key' => '_nvx_relay_next_attempt', 'compare' => 'NOT EXISTS' ),
				),
			)
		);
		foreach ( $query->posts as $post ) {
			if ( $post instanceof WP_Post ) {
				nvx_supabase_relay_queue_recover_prepared_without_due( absint( $post->ID ) );
			}
		}
	}
}

/** Re-validate item is due right before I/O. */
if ( ! function_exists( 'nvx_supabase_relay_queue_item_due' ) ) {
	function nvx_supabase_relay_queue_item_due( int $post_id, string $lock, int $lease_ttl ): bool {
		if ( function_exists( 'clean_post_cache' ) ) {
			clean_post_cache( $post_id );
		}
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $post_id, 'posts' );
			wp_cache_delete( $post_id, 'post_meta' );
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || ! in_array( $post->post_status, array( 'pending', NVX_SUPABASE_RELAY_QUEUE_PREPARED_STATUS ), true ) ) {
			return false;
		}
		$is_prepared   = NVX_SUPABASE_RELAY_QUEUE_PREPARED_STATUS === $post->post_status;
		$publish_claim = (string) get_post_meta( $post_id, '_nvx_relay_publish_claim', true );
		$dedupe_key    = (string) get_post_meta( $post_id, '_nvx_relay_dedupe_key', true );

		if ( $is_prepared && '' !== $publish_claim && nvx_supabase_relay_queue_publish_claim_live( $publish_claim ) ) {
			return false;
		}

		if ( $is_prepared && ! nvx_supabase_relay_queue_item_ready( $post_id ) ) {
			$dedupe_valid = 1 === preg_match( '/\A[a-f0-9]{64}\z/', $dedupe_key );
			$has_due_visibility = '' !== (string) get_post_meta( $post_id, '_nvx_relay_next_attempt', true );
			$claim_recoverable  = '' === $publish_claim || ! nvx_supabase_relay_queue_publish_claim_live( $publish_claim );
			if ( $dedupe_valid && $has_due_visibility && $claim_recoverable ) {
				$next_attempt = absint( get_post_meta( $post_id, '_nvx_relay_next_attempt', true ) );
				if ( $next_attempt > nvx_supabase_relay_time() ) {
					return false;
				}
				if ( ! nvx_supabase_relay_queue_acquire_publication_fence( $post_id, $dedupe_key ) ) {
					return false;
				}
				return nvx_supabase_relay_queue_renew_lock( $lock, $lease_ttl );
			}
			$endpoint = sanitize_key( (string) get_post_meta( $post_id, '_nvx_relay_endpoint', true ) );
			nvx_supabase_relay_queue_mark_dead( $post_id, $endpoint, 0, 'publication_incomplete' );
			return false;
		}
		if ( 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $dedupe_key ) ) {
			$endpoint = sanitize_key( (string) get_post_meta( $post_id, '_nvx_relay_endpoint', true ) );
			nvx_supabase_relay_queue_mark_dead( $post_id, $endpoint, 0, 'invalid_dedupe_metadata' );
			return false;
		}
		$next_attempt = absint( get_post_meta( $post_id, '_nvx_relay_next_attempt', true ) );
		if ( $next_attempt > nvx_supabase_relay_time() ) {
			return false;
		}
		if ( ! nvx_supabase_relay_queue_acquire_publication_fence( $post_id, $dedupe_key ) ) {
			return false;
		}
		if ( ! nvx_supabase_relay_queue_renew_lock( $lock, $lease_ttl ) ) {
			return false;
		}
		return true;
	}
}

/** Post-I/O fencing primitive. */
if ( ! function_exists( 'nvx_supabase_relay_queue_lock_owned' ) ) {
	function nvx_supabase_relay_queue_lock_owned( string $token ): bool {
		$key     = 'nvx_supabase_relay_drain_lock_v1';
		$current = nvx_supabase_relay_queue_fresh_option( $key );
		$parts   = explode( '|', $current, 2 );
		$current_expiry = isset( $parts[0] ) ? absint( $parts[0] ) : 0;
		$current_token  = isset( $parts[1] ) ? $parts[1] : '';
		return $current_token === $token && $current_expiry > nvx_supabase_relay_time();
	}
}

/** Record additional attempts and schedule retry for an existing item. */
if ( ! function_exists( 'nvx_supabase_relay_queue_record_existing_attempt' ) ) {
	function nvx_supabase_relay_queue_record_existing_attempt( int $existing, string $endpoint, int $attempts ): int {
		$new_attempts = nvx_supabase_relay_queue_atomic_add_attempts( $existing, $attempts );
		if ( null === $new_attempts ) {
			nvx_supabase_relay_log( $endpoint, 'QUEUED', 0, 'attempt_state_write_failed' );
			return $existing;
		}
		if ( $new_attempts >= (int) NVX_SUPABASE_RELAY_QUEUE_MAX_TRIES ) {
			nvx_supabase_relay_queue_mark_dead( $existing, $endpoint, 0, 'max_retries_exceeded' );
		} else {
			$next_attempt = time() + nvx_supabase_relay_queue_backoff_seconds( $new_attempts );
			if ( ! nvx_supabase_relay_queue_set_next_attempt_monotonic( $existing, $next_attempt ) ) {
				nvx_supabase_relay_log( $endpoint, 'QUEUED', 0, 'next_attempt_write_failed' );
				return $existing;
			}
		}
		return $existing;
	}
}

/** Persist an outbound payload into the CPT outbox queue. */
if ( ! function_exists( 'nvx_supabase_relay_queue_enqueue' ) ) {
	function nvx_supabase_relay_queue_enqueue( string $endpoint, string $body, array $headers = array(), int $attempts = 1 ): int {
		$endpoint  = sanitize_key( $endpoint );
		$endpoints = nvx_supabase_relay_queue_endpoints();
		if ( ! isset( $endpoints[ $endpoint ] ) || '' === $endpoints[ $endpoint ] || ! nvx_supabase_relay_valid_body( $body ) ) {
			nvx_supabase_relay_log( $endpoint, 'DEAD', 0, 'invalid_enqueue' );
			return 0;
		}
		$origin = isset( $headers['Origin'] ) ? nvx_supabase_relay_sanitize_origin( (string) $headers['Origin'] ) : '';
		if ( 'google_click' === $endpoint && '' === $origin ) {
			nvx_supabase_relay_log( $endpoint, 'DEAD', 0, 'origin_invalid' );
			return 0;
		}
		$dedupe_key = nvx_supabase_relay_dedupe_key( $endpoint, $body, $origin );
		$claim_key  = nvx_supabase_relay_queue_claim_key( $dedupe_key );
		$token      = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : bin2hex( random_bytes( 16 ) );
		$now             = nvx_supabase_relay_time();
		$lease_seconds   = (int) NVX_SUPABASE_RELAY_QUEUE_CLAIM_LEASE_SECONDS;
		$expiry          = $now + $lease_seconds;
		$in_flight_value = $expiry . '|' . $token;
		$we_own_claim    = false;

		if ( add_option( $claim_key, $in_flight_value, '', false ) ) {
			$we_own_claim = true;
		} else {
			$max_contention_loops = 3;
			for ( $i = 0; $i < $max_contention_loops; $i++ ) {
				$current = (string) get_option( $claim_key, '' );
				if ( '' !== $current && ctype_digit( $current ) ) {
					$existing_post_id = absint( $current );
					if ( nvx_supabase_relay_queue_is_valid_pending_item( $existing_post_id, $dedupe_key ) || nvx_supabase_relay_queue_is_valid_prepared_item( $existing_post_id, $dedupe_key ) ) {
						if ( nvx_supabase_relay_queue_acquire_publication_fence( $existing_post_id, $dedupe_key ) ) {
							return nvx_supabase_relay_queue_record_existing_attempt( $existing_post_id, $endpoint, $attempts );
						}
						return nvx_supabase_relay_queue_record_existing_attempt( $existing_post_id, $endpoint, $attempts );
					}
					if ( nvx_supabase_relay_compare_and_swap_option( $claim_key, $current, $in_flight_value ) ) {
						$we_own_claim = true;
						break;
					}
					continue;
				}
				$parts        = explode( '|', $current, 2 );
				$claim_expiry = isset( $parts[0] ) && is_numeric( $parts[0] ) ? (int) $parts[0] : 0;
				if ( $claim_expiry > nvx_supabase_relay_time() ) {
					usleep( 30000 );
					continue;
				}
				if ( nvx_supabase_relay_compare_and_swap_option( $claim_key, $current, $in_flight_value ) ) {
					$we_own_claim = true;
					break;
				}
			}
			if ( ! $we_own_claim ) {
				$final_claim = (string) get_option( $claim_key, '' );
				if ( '' !== $final_claim && ctype_digit( $final_claim ) ) {
					$existing_post_id = absint( $final_claim );
					if ( nvx_supabase_relay_queue_is_valid_pending_item( $existing_post_id, $dedupe_key ) || nvx_supabase_relay_queue_is_valid_prepared_item( $existing_post_id, $dedupe_key ) ) {
						if ( nvx_supabase_relay_queue_acquire_publication_fence( $existing_post_id, $dedupe_key ) ) {
							return nvx_supabase_relay_queue_record_existing_attempt( $existing_post_id, $endpoint, $attempts );
						}
						return nvx_supabase_relay_queue_record_existing_attempt( $existing_post_id, $endpoint, $attempts );
					}
				}
				$fallback = nvx_supabase_relay_existing_item( $dedupe_key );
				if ( $fallback > 0 && nvx_supabase_relay_queue_acquire_publication_fence( $fallback, $dedupe_key ) ) {
					return nvx_supabase_relay_queue_record_existing_attempt( $fallback, $endpoint, $attempts );
				}
				nvx_supabase_relay_log( $endpoint, 'TRANSPORT', 0, 'claim_contention_unresolved' );
				return 0;
			}
		}

		for ( $scan = 0; $scan < 20; $scan++ ) {
			$existing_pending = nvx_supabase_relay_existing_item( $dedupe_key );
			if ( $existing_pending < 1 ) {
				break;
			}
			$post = get_post( $existing_pending );
			$adoptable = $post instanceof WP_Post && (
				'pending' === $post->post_status
				|| nvx_supabase_relay_queue_item_adoptable_for_claim( $existing_pending, $dedupe_key, $in_flight_value )
			);
			if ( $adoptable ) {
				$adoption_bound = nvx_supabase_relay_compare_and_swap_option( $claim_key, $in_flight_value, (string) $existing_pending );
				if ( $adoption_bound && nvx_supabase_relay_queue_acquire_publication_fence( $existing_pending, $dedupe_key ) ) {
					return nvx_supabase_relay_queue_record_existing_attempt( $existing_pending, $endpoint, $attempts );
				}
				$current_claim = nvx_supabase_relay_queue_fresh_option( $claim_key );
				if ( ctype_digit( $current_claim ) ) {
					$current_post_id = absint( $current_claim );
					if ( nvx_supabase_relay_queue_is_valid_pending_item( $current_post_id, $dedupe_key ) || nvx_supabase_relay_queue_is_valid_prepared_item( $current_post_id, $dedupe_key ) ) {
						if ( nvx_supabase_relay_queue_acquire_publication_fence( $current_post_id, $dedupe_key ) ) {
							return nvx_supabase_relay_queue_record_existing_attempt( $current_post_id, $endpoint, $attempts );
						}
						return nvx_supabase_relay_queue_record_existing_attempt( $current_post_id, $endpoint, $attempts );
					}
				}
				nvx_supabase_relay_log( $endpoint, 'TRANSPORT', 0, 'adoption_claim_lost' );
				return 0;
			}
			if ( $post instanceof WP_Post && NVX_SUPABASE_RELAY_QUEUE_PREPARED_STATUS === $post->post_status && $in_flight_value === nvx_supabase_relay_queue_fresh_option( $claim_key ) ) {
				wp_delete_post( $existing_pending, true );
				continue;
			}
			break;
		}

		$attempts     = max( 1, min( $attempts, (int) NVX_SUPABASE_RELAY_QUEUE_MAX_TRIES ) );
		$next_attempt = time() + nvx_supabase_relay_queue_backoff_seconds( $attempts );
		$post_id      = wp_insert_post(
			array(
				'post_type'    => NVX_SUPABASE_RELAY_QUEUE_CPT,
				'post_status'  => NVX_SUPABASE_RELAY_QUEUE_BUILDING_STATUS,
				'post_title'   => sanitize_text_field( $endpoint . ' ' . gmdate( 'Y-m-d H:i:s' ) ),
				'post_content' => wp_slash( $body ),
			),
			true
		);
		if ( is_wp_error( $post_id ) || absint( $post_id ) < 1 ) {
			nvx_supabase_relay_queue_release_claim( $dedupe_key, $in_flight_value );
			nvx_supabase_relay_log( $endpoint, 'DEAD', 0, 'enqueue_failed' );
			return 0;
		}
		$post_id = absint( $post_id );
		$meta_ok = true;
		$meta_ok = add_post_meta( $post_id, '_nvx_relay_endpoint', $endpoint, true ) && $meta_ok;
		$meta_ok = add_post_meta( $post_id, '_nvx_relay_attempts', (string) $attempts, true ) && $meta_ok;
		if ( '' !== $origin ) {
			$meta_ok = add_post_meta( $post_id, '_nvx_relay_origin', $origin, true ) && $meta_ok;
		}
		$meta_ok = add_post_meta( $post_id, '_nvx_relay_publish_claim', $in_flight_value, true ) && $meta_ok;
		$meta_ok = add_post_meta( $post_id, '_nvx_relay_dedupe_key', $dedupe_key, true ) && $meta_ok;
		$meta_ok = add_post_meta( $post_id, '_nvx_relay_next_attempt', (string) $next_attempt, true ) && $meta_ok;
		$meta_ok = add_post_meta( $post_id, '_nvx_relay_ready', '1', true ) && $meta_ok;
		if ( ! $meta_ok ) {
			if ( nvx_supabase_relay_queue_compare_and_swap_status( $post_id, NVX_SUPABASE_RELAY_QUEUE_BUILDING_STATUS, 'draft' ) ) {
				wp_delete_post( $post_id, true );
			}
			nvx_supabase_relay_queue_release_claim( $dedupe_key, $in_flight_value );
			nvx_supabase_relay_log( $endpoint, 'DEAD', 0, 'enqueue_metadata_failed' );
			return 0;
		}

		if ( ! nvx_supabase_relay_queue_compare_and_swap_status( $post_id, NVX_SUPABASE_RELAY_QUEUE_BUILDING_STATUS, NVX_SUPABASE_RELAY_QUEUE_PREPARED_STATUS ) ) {
			nvx_supabase_relay_queue_release_claim( $dedupe_key, $in_flight_value );
			nvx_supabase_relay_log( $endpoint, 'TRANSPORT', 0, 'enqueue_prepare_transition_failed' );
			return 0;
		}

		$claim_bound = nvx_supabase_relay_compare_and_swap_option( $claim_key, $in_flight_value, (string) $post_id );
		if ( ! $claim_bound ) {
			$current_claim = nvx_supabase_relay_queue_fresh_option( $claim_key );
			if ( '' !== $current_claim && ctype_digit( $current_claim ) ) {
				$existing_post_id = absint( $current_claim );
				if ( nvx_supabase_relay_queue_is_valid_pending_item( $existing_post_id, $dedupe_key ) || nvx_supabase_relay_queue_is_valid_prepared_item( $existing_post_id, $dedupe_key ) ) {
					if ( $existing_post_id === $post_id ) {
						$GLOBALS['nvx_supabase_relay_queue_dirty'] = true;
						if ( ! nvx_supabase_relay_queue_finalize_publication( $post_id, $dedupe_key ) ) {
							nvx_supabase_relay_log( $endpoint, 'TRANSPORT', 0, 'successor_adopted_finalize_failed' );
							return 0;
						}
						nvx_supabase_relay_log( $endpoint, 'QUEUED', 0, 'successor_adopted_prepared_row' );
						return $post_id;
					}
					wp_delete_post( $post_id, true );
					nvx_supabase_relay_queue_acquire_publication_fence( $existing_post_id, $dedupe_key );
					return nvx_supabase_relay_queue_record_existing_attempt( $existing_post_id, $endpoint, $attempts );
				}
			}
			$fallback = nvx_supabase_relay_existing_item( $dedupe_key );
			if ( $fallback > 0 && $fallback !== $post_id && nvx_supabase_relay_queue_acquire_publication_fence( $fallback, $dedupe_key ) ) {
				wp_delete_post( $post_id, true );
				return nvx_supabase_relay_queue_record_existing_attempt( $fallback, $endpoint, $attempts );
			}
			nvx_supabase_relay_log( $endpoint, 'TRANSPORT', 0, 'claim_lost_during_publish' );
			return 0;
		}
		if ( ! nvx_supabase_relay_queue_finalize_publication( $post_id, $dedupe_key ) ) {
			$GLOBALS['nvx_supabase_relay_queue_dirty'] = true;
			nvx_supabase_relay_log( $endpoint, 'TRANSPORT', 0, 'publication_finalize_failed' );
			return 0;
		}
		$GLOBALS['nvx_supabase_relay_queue_dirty'] = true;
		nvx_supabase_relay_log( $endpoint, 'QUEUED' );
		return $post_id;
	}
}

/** Send one persisted payload. */
if ( ! function_exists( 'nvx_supabase_relay_queue_send' ) ) {
	function nvx_supabase_relay_queue_send( string $endpoint, string $body, string $origin = '', bool $force_bootstrap = false ): array|WP_Error {
		$endpoint  = sanitize_key( $endpoint );
		$endpoints = nvx_supabase_relay_queue_endpoints();
		$url       = isset( $endpoints[ $endpoint ] ) ? sanitize_url( (string) $endpoints[ $endpoint ] ) : '';
		if ( '' === $url || ! nvx_supabase_relay_valid_body( $body ) ) {
			return new WP_Error( 'nvx_relay_endpoint_missing', 'Relay endpoint or body is unavailable.' );
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

/** Dispatch synchronously and queue only retryable failure. */
if ( ! function_exists( 'nvx_supabase_relay_dispatch' ) ) {
	function nvx_supabase_relay_dispatch( string $endpoint, string $body, array $headers = array() ): array {
		$endpoint = sanitize_key( $endpoint );
		$origin   = isset( $headers['Origin'] ) ? nvx_supabase_relay_sanitize_origin( (string) $headers['Origin'] ) : '';
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

/** Acquire global drain lock. */
if ( ! function_exists( 'nvx_supabase_relay_queue_lock' ) ) {
	function nvx_supabase_relay_queue_lock( int $ttl = 0 ): string {
		$key   = 'nvx_supabase_relay_drain_lock_v1';
		$token = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : bin2hex( random_bytes( 16 ) );
		$lease = $ttl > 0 ? $ttl : ( function_exists( 'nvx_supabase_relay_queue_lock_ttl' ) ? nvx_supabase_relay_queue_lock_ttl() : (int) NVX_SUPABASE_RELAY_QUEUE_LOCK_TTL );
		$value = ( nvx_supabase_relay_time() + $lease ) . '|' . $token;
		if ( add_option( $key, $value, '', false ) ) {
			return $token;
		}
		$current        = (string) get_option( $key, '' );
		$parts          = explode( '|', $current, 2 );
		$current_expiry = isset( $parts[0] ) ? absint( $parts[0] ) : 0;
		if ( $current_expiry > 0 && $current_expiry <= nvx_supabase_relay_time() && nvx_supabase_relay_compare_and_swap_option( $key, $current, $value ) ) {
			return $token;
		}
		return '';
	}
}

/** Atomically renew the drain lock for the active owner. */
if ( ! function_exists( 'nvx_supabase_relay_queue_renew_lock' ) ) {
	function nvx_supabase_relay_queue_renew_lock( string $token, int $ttl = 0 ): bool {
		if ( '' === $token ) {
			return false;
		}
		$key            = 'nvx_supabase_relay_drain_lock_v1';
		$current        = (string) get_option( $key, '' );
		$parts          = explode( '|', $current, 2 );
		$current_expiry = isset( $parts[0] ) ? absint( $parts[0] ) : 0;
		$current_token  = isset( $parts[1] ) ? $parts[1] : '';
		if ( $current_token !== $token || $current_expiry <= nvx_supabase_relay_time() ) {
			return false;
		}
		$lease     = $ttl > 0 ? $ttl : ( function_exists( 'nvx_supabase_relay_queue_lock_ttl' ) ? nvx_supabase_relay_queue_lock_ttl() : (int) NVX_SUPABASE_RELAY_QUEUE_LOCK_TTL );
		$new_value = ( nvx_supabase_relay_time() + $lease ) . '|' . $token;
		return nvx_supabase_relay_compare_and_swap_option( $key, $current, $new_value );
	}
}

/** Release the drain lock only when owned by this process. */
if ( ! function_exists( 'nvx_supabase_relay_queue_unlock' ) ) {
	function nvx_supabase_relay_queue_unlock( string $token ): void {
		if ( '' === $token ) {
			return;
		}
		$key = 'nvx_supabase_relay_drain_lock_v1';
		global $wpdb;
		if ( isset( $wpdb ) && method_exists( $wpdb, 'query' ) && method_exists( $wpdb, 'prepare' ) && method_exists( $wpdb, 'esc_like' ) ) {
			$table = isset( $wpdb->options ) ? $wpdb->options : 'wp_options';
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE option_name = %s AND option_value LIKE %s", $key, '%|' . $wpdb->esc_like( $token ) ) );
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

/** Clean post and claim caches after a terminal transaction or rollback. */
if ( ! function_exists( 'nvx_supabase_relay_queue_clean_terminal_caches' ) ) {
	function nvx_supabase_relay_queue_clean_terminal_caches( int $post_id, string $claim_key ): void {
		if ( function_exists( 'clean_post_cache' ) ) {
			clean_post_cache( absint( $post_id ) );
		}
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( absint( $post_id ), 'posts' );
			wp_cache_delete( absint( $post_id ), 'post_meta' );
			wp_cache_delete( $claim_key, 'options' );
		}
	}
}

/** Complete SUCCESS or DEAD under one dedupe ownership boundary. */
if ( ! function_exists( 'nvx_supabase_relay_queue_complete_terminal_state' ) ) {
	function nvx_supabase_relay_queue_complete_terminal_state( int $post_id, string $endpoint, int $status, string $reason, bool $delete_post ): bool {
		$post_id    = absint( $post_id );
		$dedupe_key = (string) get_post_meta( $post_id, '_nvx_relay_dedupe_key', true );
		$outcome    = $delete_post ? 'DRAINED' : 'DEAD';
		if ( $post_id < 1 ) {
			return false;
		}
		if ( 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $dedupe_key ) ) {
			if ( $delete_post ) {
				wp_delete_post( $post_id, true );
			} else {
				$updated = wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ), true );
				if ( is_wp_error( $updated ) || absint( $updated ) !== $post_id ) {
					return false;
				}
			}
			nvx_supabase_relay_log( $endpoint, $outcome, $status, $reason );
			return true;
		}
		$claim_key = nvx_supabase_relay_queue_claim_key( $dedupe_key );
		$expected  = (string) $post_id;
		global $wpdb;
		$has_transaction_owner = isset( $wpdb ) && method_exists( $wpdb, 'query' ) && method_exists( $wpdb, 'prepare' ) && method_exists( $wpdb, 'get_var' ) && isset( $wpdb->options );
		if ( $has_transaction_owner ) {
			$options_table = (string) $wpdb->options;
			$started       = $wpdb->query( 'START TRANSACTION' );
			if ( false !== $started ) {
				try {
					$locked_claim = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$options_table} WHERE option_name = %s FOR UPDATE", $claim_key ) );
					if ( $expected !== $locked_claim ) {
						$wpdb->query( 'ROLLBACK' );
						nvx_supabase_relay_queue_clean_terminal_caches( $post_id, $claim_key );
						nvx_supabase_relay_log( $endpoint, 'TRANSPORT', $status, 'terminal_ownership_lost' );
						return false;
					}
					if ( ! nvx_supabase_relay_queue_retire_duplicate_rows( $post_id, $dedupe_key, $expected ) ) {
						$wpdb->query( 'ROLLBACK' );
						nvx_supabase_relay_queue_clean_terminal_caches( $post_id, $claim_key );
						nvx_supabase_relay_log( $endpoint, 'TRANSPORT', $status, 'terminal_cleanup_lost' );
						return false;
					}
					if ( $expected !== (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$options_table} WHERE option_name = %s FOR UPDATE", $claim_key ) ) ) {
						$wpdb->query( 'ROLLBACK' );
						nvx_supabase_relay_queue_clean_terminal_caches( $post_id, $claim_key );
						return false;
					}
					if ( $delete_post ) {
						$transitioned = false !== wp_delete_post( $post_id, true );
					} else {
						$updated      = wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ), true );
						$transitioned = ! is_wp_error( $updated ) && absint( $updated ) === $post_id;
					}
					if ( ! $transitioned ) {
						$wpdb->query( 'ROLLBACK' );
						nvx_supabase_relay_queue_clean_terminal_caches( $post_id, $claim_key );
						nvx_supabase_relay_log( $endpoint, 'TRANSPORT', $status, 'terminal_transition_failed' );
						return false;
					}
					if ( ! nvx_supabase_relay_queue_retire_duplicate_rows( $post_id, $dedupe_key, $expected ) ) {
						$wpdb->query( 'ROLLBACK' );
						nvx_supabase_relay_queue_clean_terminal_caches( $post_id, $claim_key );
						nvx_supabase_relay_log( $endpoint, 'TRANSPORT', $status, 'terminal_final_cleanup_lost' );
						return false;
					}
					$deleted_claim = $wpdb->query( $wpdb->prepare( "DELETE FROM {$options_table} WHERE option_name = %s AND option_value = %s", $claim_key, $expected ) );
					if ( 1 !== $deleted_claim || false === $wpdb->query( 'COMMIT' ) ) {
						$wpdb->query( 'ROLLBACK' );
						nvx_supabase_relay_queue_clean_terminal_caches( $post_id, $claim_key );
						nvx_supabase_relay_log( $endpoint, 'TRANSPORT', $status, 'terminal_commit_failed' );
						return false;
					}
					nvx_supabase_relay_queue_clean_terminal_caches( $post_id, $claim_key );
					nvx_supabase_relay_log( $endpoint, $outcome, $status, $reason );
					return true;
				} catch ( Throwable $error ) {
					unset( $error );
					$wpdb->query( 'ROLLBACK' );
					nvx_supabase_relay_queue_clean_terminal_caches( $post_id, $claim_key );
					nvx_supabase_relay_log( $endpoint, 'TRANSPORT', $status, 'terminal_transaction_exception' );
					return false;
				}
			}
		}
		$terminal_claim = nvx_supabase_relay_queue_begin_terminal_lifecycle( $post_id, $dedupe_key );
		if ( '' === $terminal_claim ) {
			nvx_supabase_relay_log( $endpoint, 'TRANSPORT', $status, 'terminal_fence_acquire_failed' );
			return false;
		}
		if ( ! nvx_supabase_relay_queue_retire_duplicate_rows( $post_id, $dedupe_key, $terminal_claim ) ) {
			nvx_supabase_relay_compare_and_swap_option( $claim_key, $terminal_claim, $expected );
			return false;
		}
		if ( $terminal_claim !== nvx_supabase_relay_queue_fresh_option( $claim_key ) ) {
			return false;
		}
		if ( $delete_post ) {
			$transitioned = false !== wp_delete_post( $post_id, true );
		} else {
			$updated      = wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ), true );
			$transitioned = ! is_wp_error( $updated ) && absint( $updated ) === $post_id;
		}
		if ( ! $transitioned ) {
			nvx_supabase_relay_compare_and_swap_option( $claim_key, $terminal_claim, $expected );
			return false;
		}
		if ( ! nvx_supabase_relay_queue_finish_terminal_lifecycle( $post_id, $dedupe_key, $terminal_claim ) ) {
			return false;
		}
		nvx_supabase_relay_log( $endpoint, $outcome, $status, $reason );
		return true;
	}
}

/** Mark one queue item dead. */
if ( ! function_exists( 'nvx_supabase_relay_queue_mark_dead' ) ) {
	function nvx_supabase_relay_queue_mark_dead( int $post_id, string $endpoint, int $status, string $reason ): void {
		nvx_supabase_relay_queue_complete_terminal_state( $post_id, $endpoint, $status, $reason, false );
	}
}

/** Drain due outbox items. */
if ( ! function_exists( 'nvx_supabase_relay_queue_drain' ) ) {
	function nvx_supabase_relay_queue_drain( int $limit = NVX_SUPABASE_RELAY_QUEUE_BATCH ): void {
		$limit     = max( 1, min( $limit, (int) NVX_SUPABASE_RELAY_QUEUE_BATCH ) );
		$lease_ttl = function_exists( 'nvx_supabase_relay_queue_lock_ttl' ) ? nvx_supabase_relay_queue_lock_ttl( $limit ) : (int) NVX_SUPABASE_RELAY_QUEUE_LOCK_TTL;
		$lock      = nvx_supabase_relay_queue_lock( $lease_ttl );
		if ( '' === $lock ) {
			return;
		}
		try {
			nvx_supabase_relay_queue_recover_building_rows( $limit );
			nvx_supabase_relay_queue_recover_invisible_prepared( $limit );
			$now   = time();
			$query = new WP_Query(
				array(
					'post_type'              => NVX_SUPABASE_RELAY_QUEUE_CPT,
					'post_status'            => array( 'pending', NVX_SUPABASE_RELAY_QUEUE_PREPARED_STATUS ),
					'posts_per_page'         => $limit,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'no_found_rows'          => true,
					'update_post_meta_cache' => true,
					'update_post_term_cache' => false,
					'meta_query'             => array(
						array( 'key' => '_nvx_relay_next_attempt', 'value' => (string) $now, 'compare' => '<=', 'type' => 'NUMERIC' ),
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
				$endpoint = sanitize_key( (string) get_post_meta( $post_id, '_nvx_relay_endpoint', true ) );
				$origin   = nvx_supabase_relay_sanitize_origin( (string) get_post_meta( $post_id, '_nvx_relay_origin', true ) );
				$body     = (string) $post->post_content;
				if ( ! nvx_supabase_relay_valid_body( $body ) ) {
					nvx_supabase_relay_queue_mark_dead( $post_id, $endpoint, 0, 'invalid_payload' );
					continue;
				}
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
				if ( ! nvx_supabase_relay_queue_lock_owned( $lock ) ) {
					break;
				}
				if ( 'SUCCESS' === $class['outcome'] ) {
					nvx_supabase_relay_queue_complete_terminal_state( $post_id, $endpoint, $class['status'], '', true );
					continue;
				}
				$new_attempts = nvx_supabase_relay_queue_atomic_add_attempts( $post_id, $delivery_attempts );
				if ( null === $new_attempts ) {
					nvx_supabase_relay_log( $endpoint, $class['outcome'], $class['status'], 'retry_state_write_failed' );
					break;
				}
				if ( ! $class['retryable'] || $new_attempts >= (int) NVX_SUPABASE_RELAY_QUEUE_MAX_TRIES ) {
					nvx_supabase_relay_queue_mark_dead( $post_id, $endpoint, $class['status'], $class['reason'] );
					continue;
				}
				$next_attempt = time() + nvx_supabase_relay_queue_backoff_seconds( $new_attempts );
				if ( ! nvx_supabase_relay_queue_set_next_attempt_monotonic( $post_id, $next_attempt ) ) {
					nvx_supabase_relay_log( $endpoint, $class['outcome'], $class['status'], 'next_attempt_write_failed' );
					break;
				}
				nvx_supabase_relay_log( $endpoint, $class['outcome'], $class['status'], 'retry_scheduled' );
			}
		} finally {
			nvx_supabase_relay_queue_unlock( $lock );
		}
	}
}

add_action( NVX_SUPABASE_RELAY_QUEUE_CRON, 'nvx_supabase_relay_queue_drain' );

/** Opportunistic drain after a request created queue work. */
if ( ! function_exists( 'nvx_supabase_relay_queue_shutdown_drain' ) ) {
	function nvx_supabase_relay_queue_shutdown_drain(): void {
		if ( empty( $GLOBALS['nvx_supabase_relay_queue_dirty'] ) ) {
			return;
		}
		nvx_supabase_relay_queue_drain( 3 );
	}
}
add_action( 'shutdown', 'nvx_supabase_relay_queue_shutdown_drain' );

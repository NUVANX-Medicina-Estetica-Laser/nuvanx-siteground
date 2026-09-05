<?php
/**
 * Operational policy edge for the canonical Supabase relay queue package.
 *
 * This file is loaded immediately before nvx-supabase-relay-queue.php. The
 * queue core keeps guarded fallback definitions for isolated test/bootstrap
 * compatibility, while the normal theme runtime uses these instrumented owners.
 * Publication, transport and terminal lifecycle remain in the queue core.
 *
 * @package nuvanx-medical
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Post-I/O fence ownership check with an explicit lease-loss signal.
 *
 * The queue core calls this primitive only after synchronous relay I/O and
 * before mutating retry/terminal state. Losing the lease is therefore a real
 * observable concurrency outcome rather than a silent break.
 */
if ( ! function_exists( 'nvx_supabase_relay_queue_lock_owned' ) ) {
	function nvx_supabase_relay_queue_lock_owned( string $token ): bool {
		$key            = 'nvx_supabase_relay_drain_lock_v1';
		$current        = nvx_supabase_relay_queue_fresh_option( $key );
		$parts          = explode( '|', $current, 2 );
		$current_expiry = isset( $parts[0] ) ? absint( $parts[0] ) : 0;
		$current_token  = isset( $parts[1] ) ? $parts[1] : '';
		$owned          = $current_token === $token && $current_expiry > nvx_supabase_relay_time();

		if ( ! $owned && function_exists( 'nvx_observability_log' ) ) {
			nvx_observability_log( 'supabase_relay_ops', 'drain_lease_lost' );
		}
		return $owned;
	}
}

/**
 * Reuse one canonical dedupe winner and expose the reuse/retry-state outcome.
 *
 * This is the same retry scheduling primitive as the queue fallback, with
 * bounded operational telemetry added at the authoritative reuse boundary.
 */
if ( ! function_exists( 'nvx_supabase_relay_queue_record_existing_attempt' ) ) {
	function nvx_supabase_relay_queue_record_existing_attempt( int $existing, string $endpoint, int $attempts ): int {
		$new_attempts = nvx_supabase_relay_queue_atomic_add_attempts( $existing, $attempts );
		if ( null === $new_attempts ) {
			nvx_supabase_relay_log( $endpoint, 'QUEUED', 0, 'attempt_state_write_failed' );
			if ( function_exists( 'nvx_observability_log' ) ) {
				nvx_observability_log(
					'supabase_relay_ops',
					'retry_state_conflict',
					array( 'endpoint' => sanitize_key( $endpoint ), 'phase' => 'attempts' )
				);
			}
			return $existing;
		}

		if ( function_exists( 'nvx_observability_log' ) ) {
			nvx_observability_log(
				'supabase_relay_ops',
				'dedupe_reused',
				array(
					'endpoint' => sanitize_key( $endpoint ),
					'attempts' => $new_attempts,
				)
			);
		}

		if ( $new_attempts >= (int) NVX_SUPABASE_RELAY_QUEUE_MAX_TRIES ) {
			nvx_supabase_relay_queue_mark_dead( $existing, $endpoint, 0, 'max_retries_exceeded' );
		} else {
			$next_attempt = time() + nvx_supabase_relay_queue_backoff_seconds( $new_attempts );
			if ( ! nvx_supabase_relay_queue_set_next_attempt_monotonic( $existing, $next_attempt ) ) {
				nvx_supabase_relay_log( $endpoint, 'QUEUED', 0, 'next_attempt_write_failed' );
				if ( function_exists( 'nvx_observability_log' ) ) {
					nvx_observability_log(
						'supabase_relay_ops',
						'retry_state_conflict',
						array( 'endpoint' => sanitize_key( $endpoint ), 'phase' => 'next_attempt' )
					);
				}
				return $existing;
			}
		}
		return $existing;
	}
}

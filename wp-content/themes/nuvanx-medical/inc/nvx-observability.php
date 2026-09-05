<?php
/**
 * Canonical structured runtime observability owner.
 *
 * Runtime modules emit bounded operational metadata only. Raw payloads,
 * request bodies, credentials, contact fields and free-form exception messages
 * are never accepted by this sink.
 *
 * @package nuvanx-medical
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Emit one bounded, no-PII operational event.
 *
 * @param array<string, int|float|bool|string|null> $context Structured metadata.
 */
function nvx_observability_log( string $domain, string $event, array $context = array() ): void {
	$domain = sanitize_key( $domain );
	$event  = sanitize_key( $event );
	if ( '' === $domain || '' === $event ) {
		return;
	}

	$allowed_keys = array(
		'attempts',
		'bytes',
		'code',
		'component',
		'endpoint',
		'field',
		'filename',
		'http_status',
		'key',
		'limit',
		'mode',
		'operation',
		'owner',
		'phase',
		'post_id',
		'provider',
		'reason',
		'route',
		'source',
		'status',
		'value',
	);
	$allowed      = array_fill_keys( $allowed_keys, true );
	$parts        = array(
		'NUVANX_OBSERVABILITY',
		'domain=' . $domain,
		'event=' . $event,
	);

	foreach ( $context as $key => $value ) {
		$safe_key = sanitize_key( (string) $key );
		if ( '' === $safe_key || ! isset( $allowed[ $safe_key ] ) || null === $value ) {
			continue;
		}

		if ( is_bool( $value ) ) {
			$safe_value = $value ? '1' : '0';
		} elseif ( is_int( $value ) || is_float( $value ) ) {
			$safe_value = (string) $value;
		} else {
			// String context is identifier-like only. This deliberately rejects
			// free-form text as a logging transport and strips URL/contact syntax.
			$safe_value = sanitize_key( substr( (string) $value, 0, 96 ) );
		}

		if ( '' !== $safe_value ) {
			$parts[] = $safe_key . '=' . $safe_value;
		}
	}

	$line = implode( ' ', $parts );
	if ( strlen( $line ) > 512 ) {
		$line = substr( $line, 0, 512 );
	}

	// Sole runtime error_log() sink for the canonical theme bootstrap.
	error_log( $line );
}

/** Canonical Supabase relay logger, defined before the queue compatibility fallback. */
if ( ! function_exists( 'nvx_supabase_relay_log' ) ) {
	function nvx_supabase_relay_log( string $endpoint, string $outcome, int $status = 0, string $reason = '' ): void {
		$endpoint = sanitize_key( $endpoint );
		$outcome  = strtoupper( sanitize_key( $outcome ) );
		$allowed  = array( 'SUCCESS', 'HTTP_4XX', 'HTTP_429', 'HTTP_5XX', 'TRANSPORT', 'QUEUED', 'DRAINED', 'DEAD' );
		if ( '' === $endpoint || ! in_array( $outcome, $allowed, true ) ) {
			return;
		}
		nvx_observability_log(
			'supabase_relay',
			strtolower( $outcome ),
			array(
				'endpoint'    => $endpoint,
				'http_status' => $status > 0 ? absint( $status ) : null,
				'reason'      => '' !== $reason ? sanitize_key( $reason ) : null,
			)
		);
	}
}

/** Canonical HubSpot secure bridge logger, defined before its compatibility fallback. */
if ( ! function_exists( 'nvx_hubspot_secure_log' ) ) {
	function nvx_hubspot_secure_log( string $outcome, string $reason = '', int $status = 0 ): void {
		$outcome = strtoupper( sanitize_key( $outcome ) );
		if ( ! in_array( $outcome, array( 'SUCCESS', 'FAILURE', 'TRANSPORT' ), true ) ) {
			return;
		}
		nvx_observability_log(
			'hubspot_secure',
			strtolower( $outcome ),
			array(
				'reason'      => '' !== $reason ? sanitize_key( $reason ) : null,
				'http_status' => $status > 0 ? absint( $status ) : null,
			)
		);
	}
}

/** Canonical Google-click attribution relay logger, defined before its fallback. */
if ( ! function_exists( 'nvx_attribution_log_direct_relay' ) ) {
	function nvx_attribution_log_direct_relay( string $outcome, int $status = 0, string $reason = '' ): void {
		$outcome = strtoupper( sanitize_key( $outcome ) );
		$allowed = array( 'SUCCESS', 'FAILURE', 'HTTP_4XX', 'HTTP_429', 'HTTP_5XX', 'TRANSPORT', 'QUEUED', 'DEAD' );
		if ( ! in_array( $outcome, $allowed, true ) ) {
			return;
		}
		nvx_observability_log(
			'attribution_relay',
			strtolower( $outcome ),
			array(
				'http_status' => $status > 0 ? absint( $status ) : null,
				'reason'      => '' !== $reason ? sanitize_key( $reason ) : null,
			)
		);
	}
}

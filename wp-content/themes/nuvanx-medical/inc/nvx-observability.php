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
		'consent',
		'endpoint',
		'field',
		'filename',
		'form_id',
		'http_status',
		'hutk_present',
		'key',
		'limit',
		'mode',
		'operation',
		'owner',
		'page_uri_hash',
		'phase',
		'post_id',
		'provider',
		'reason',
		'route',
		'source',
		'status',
		'test_id',
		'value',
	);
	$allowed = array_fill_keys( $allowed_keys, true );
	$parts   = array(
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

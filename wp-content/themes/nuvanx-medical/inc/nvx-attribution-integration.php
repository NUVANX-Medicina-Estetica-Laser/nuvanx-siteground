<?php
/**
 * Runtime wiring for Attribution Contract v2.
 *
 * - Applies the browser contract to the canonical HubSpot V4 form.
 * - Mirrors successful first-party form attribution to the Supabase collector.
 * - Keeps nvx_lead_id separate from submission_id and the reconciled lead FK.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Enqueue the canonical HubSpot V4 attribution synchronizer after the contract runtime. */
function nvx_attribution_enqueue_hubspot_sync(): void {
	if ( is_admin() ) {
		return;
	}

	wp_enqueue_script(
		'nvx-hubspot-attribution-sync',
		get_template_directory_uri() . '/assets/js/nvx-hubspot-attribution-sync.js',
		array( 'nvx-attribution-contract' ),
		nvx_asset_version( 'assets/js/nvx-hubspot-attribution-sync.js' ),
		array(
			'in_footer' => false,
			'strategy'  => 'defer',
		)
	);

	if ( function_exists( 'nvx_hubspot_secure_marketing_fields' ) ) {
		$marketing_fields = wp_json_encode( array_values( nvx_hubspot_secure_marketing_fields() ) );
		if ( false !== $marketing_fields ) {
			wp_add_inline_script(
				'nvx-hubspot-attribution-sync',
				'window.nvxAttributionMarketingFields=' . $marketing_fields . ';',
				'before'
			);
		}
	}
}
add_action( 'wp_enqueue_scripts', 'nvx_attribution_enqueue_hubspot_sync', 9 );

/** Validate a canonical UUID v4 explicitly, matching the collector contract. */
function nvx_attribution_is_uuid_v4( string $value ): bool {
	return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value );
}

/** Canonical collector URL; env/constant may only pin this exact value. */
function nvx_attribution_collector_canonical_endpoint(): string {
	return 'https://ssvvuuysgxyqvmovrlvk.supabase.co/functions/v1/google-click-attribution';
}

/**
 * Resolve the collector endpoint.
 *
 * A missing override uses the canonical URL. A configured override that does
 * not match the canonical pin fails closed so traffic never leaves the ledger.
 */
function nvx_attribution_collector_endpoint(): string {
	$canonical = nvx_attribution_collector_canonical_endpoint();
	$value     = defined( 'NVX_ATTRIBUTION_COLLECTOR_ENDPOINT' )
		? trim( (string) NVX_ATTRIBUTION_COLLECTOR_ENDPOINT )
		: trim( (string) ( getenv( 'NVX_ATTRIBUTION_COLLECTOR_ENDPOINT' ) ?: $canonical ) );

	return hash_equals( $canonical, $value ) ? $canonical : '';
}

/** Whether a hostname is safe to send as a collector Origin. */
function nvx_attribution_is_collector_host( string $host ): bool {
	return 1 === preg_match( '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/', $host );
}

/**
 * Collector Origin hosts: canonical first-party set plus optional extras.
 *
 * Extra hosts come from NVX_ATTRIBUTION_COLLECTOR_ALLOWED_HOSTS (constant or
 * env, comma/whitespace separated) so a preview domain needs no theme edit.
 *
 * @return string[]
 */
function nvx_attribution_collector_allowed_hosts(): array {
	$hosts = array(
		'nuvanx.com',
		'www.nuvanx.com',
		'staging2.nuvanx.com',
	);

	$extra = defined( 'NVX_ATTRIBUTION_COLLECTOR_ALLOWED_HOSTS' )
		? (string) NVX_ATTRIBUTION_COLLECTOR_ALLOWED_HOSTS
		: (string) ( getenv( 'NVX_ATTRIBUTION_COLLECTOR_ALLOWED_HOSTS' ) ?: '' );

	foreach ( preg_split( '/[\s,]+/', $extra, -1, PREG_SPLIT_NO_EMPTY ) as $candidate ) {
		$candidate = strtolower( trim( $candidate ) );
		if ( nvx_attribution_is_collector_host( $candidate ) ) {
			$hosts[] = $candidate;
		}
	}

	$hosts    = array_values( array_unique( $hosts ) );
	$filtered = apply_filters( 'nvx_attribution_collector_allowed_hosts', $hosts );
	if ( ! is_array( $filtered ) ) {
		return $hosts;
	}

	$safe = array();
	foreach ( $filtered as $candidate ) {
		$candidate = strtolower( trim( (string) $candidate ) );
		if ( nvx_attribution_is_collector_host( $candidate ) ) {
			$safe[] = $candidate;
		}
	}

	return array_values( array_unique( $safe ) );
}

/** Resolve a collector Origin accepted by the production Edge Function. */
function nvx_attribution_collector_origin(): string {
	$host = strtolower( (string) wp_parse_url( get_site_url(), PHP_URL_HOST ) );
	if ( ! in_array( $host, nvx_attribution_collector_allowed_hosts(), true ) ) {
		nvx_attribution_log_direct_relay( 'FAILURE', 0, 'origin_not_allowed' );
		return '';
	}
	return 'https://' . $host;
}

/**
 * Email hash for the Google click collector.
 *
 * Must stay SHA-256 of the lowercase email so PHP matches the browser relay
 * and the existing Edge Function join key. HMAC would break that contract.
 */
function nvx_attribution_email_hash( string $email ): string {
	$email = strtolower( trim( $email ) );
	return '' === $email ? '' : hash( 'sha256', $email );
}

/**
 * Convert HubSpot fields to a simple name => value map.
 *
 * @param array<string,mixed> $payload HubSpot Forms API payload.
 * @return array<string,string>
 */
function nvx_attribution_hubspot_field_map( array $payload ): array {
	$fields = isset( $payload['fields'] ) && is_array( $payload['fields'] ) ? $payload['fields'] : array();
	$output = array();
	foreach ( $fields as $field ) {
		if ( ! is_array( $field ) || ! isset( $field['name'] ) ) {
			continue;
		}
		$name = (string) $field['name'];
		if ( '' === $name ) {
			continue;
		}
		$output[ $name ] = trim( (string) ( $field['value'] ?? '' ) );
	}
	return $output;
}

/** Emit bounded, non-PII collector telemetry. */
function nvx_attribution_log_direct_relay( string $outcome, int $status = 0, string $reason = '' ): void {
	$outcome = strtoupper( $outcome );
	if ( ! in_array( $outcome, array( 'SUCCESS', 'FAILURE' ), true ) ) {
		return;
	}
	$line = 'NVX_ATTRIBUTION_DIRECT_RELAY=' . $outcome;
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
 * Relay attribution only after the secure bridge has produced a successful HubSpot response.
 *
 * The callback runs after nvx_hubspot_secure_pre_http_request() on the same
 * pre_http_request filter. Collector failure never changes the already accepted
 * HubSpot response.
 *
 * @param mixed               $preempt Existing preempted HTTP response.
 * @param array<string,mixed> $args    Original public HubSpot request args.
 * @param string              $url     Original public HubSpot request URL.
 * @return mixed
 */
function nvx_attribution_relay_direct_form_after_hubspot( $preempt, array $args, string $url ) {
	if ( ! function_exists( 'nvx_hubspot_secure_original_url' ) || nvx_hubspot_secure_original_url() !== $url ) {
		return $preempt;
	}
	if ( false === $preempt || is_wp_error( $preempt ) ) {
		return $preempt;
	}

	$hubspot_status = (int) wp_remote_retrieve_response_code( $preempt );
	if ( $hubspot_status < 200 || $hubspot_status >= 300 ) {
		return $preempt;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- direct-form handler validated the nonce before issuing HubSpot request.
	if ( empty( $_POST['nvx_valoracion_submit'] ) ) {
		return $preempt;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- same validated direct-form request.
	$marketing_consent = isset( $_POST['nvx_marketing_consent'] ) && '1' === sanitize_text_field( wp_unslash( (string) $_POST['nvx_marketing_consent'] ) );
	if ( ! $marketing_consent ) {
		return $preempt;
	}

	$body    = $args['body'] ?? '';
	$payload = is_string( $body ) ? json_decode( $body, true ) : (array) $body;
	if ( ! is_array( $payload ) ) {
		return $preempt;
	}
	$fields = nvx_attribution_hubspot_field_map( $payload );

	$lead_id = strtolower( (string) ( $fields['nvx_lead_id'] ?? '' ) );
	$email   = strtolower( trim( (string) ( $fields['email'] ?? '' ) ) );
	if ( ! nvx_attribution_is_uuid_v4( $lead_id ) || ! is_email( $email ) ) {
		return $preempt;
	}

	$gclid  = (string) ( $fields['nvx_google_click_id'] ?? '' );
	$gbraid = (string) ( $fields['nvx_google_braid'] ?? '' );
	$wbraid = (string) ( $fields['nvx_google_wbraid'] ?? '' );
	$gclsrc = (string) ( $fields['nvx_google_gclsrc'] ?? '' );
	if ( '' === $gclid && '' === $gbraid && '' === $wbraid ) {
		return $preempt;
	}

	$submission_id = function_exists( 'wp_generate_uuid4' ) ? strtolower( wp_generate_uuid4() ) : '';
	if ( ! nvx_attribution_is_uuid_v4( $submission_id ) ) {
		return $preempt;
	}

	if ( ! function_exists( 'nvx_hubspot_secure_form_id' ) ) {
		return $preempt;
	}
	$form_id = nvx_hubspot_secure_form_id();
	if ( '' === $form_id ) {
		return $preempt;
	}

	$context     = isset( $payload['context'] ) && is_array( $payload['context'] ) ? $payload['context'] : array();
	$landing_url = isset( $context['pageUri'] ) ? esc_url_raw( (string) $context['pageUri'] ) : home_url( '/madrid/valoracion/' );
	$origin      = nvx_attribution_collector_origin();
	if ( '' === $origin ) {
		return $preempt;
	}

	$collector_payload = array(
		'submission_id' => $submission_id,
		'nvx_lead_id'   => $lead_id,
		'email_hash'    => nvx_attribution_email_hash( $email ),
		'gclid'         => '' !== $gclid ? $gclid : null,
		'gbraid'        => '' !== $gbraid ? $gbraid : null,
		'wbraid'        => '' !== $wbraid ? $wbraid : null,
		'gclsrc'        => '' !== $gclsrc ? $gclsrc : null,
		'form_id'       => $form_id,
		'landing_url'   => $landing_url,
	);
	$collector_body = wp_json_encode( $collector_payload );
	if ( false === $collector_body ) {
		nvx_attribution_log_direct_relay( 'FAILURE' );
		return $preempt;
	}

	$endpoint = nvx_attribution_collector_endpoint();
	if ( '' === $endpoint ) {
		nvx_attribution_log_direct_relay( 'FAILURE' );
		return $preempt;
	}

	$response = wp_remote_post(
		$endpoint,
		array(
			'timeout'     => 0.5,
			'redirection' => 0,
			'blocking'    => false,
			'headers'     => array(
				'Content-Type' => 'application/json',
				'Origin'       => $origin,
			),
			'body'        => $collector_body,
		)
	);

	if ( is_wp_error( $response ) ) {
		nvx_attribution_log_direct_relay( 'FAILURE' );
	}

	return $preempt;
}
add_filter( 'pre_http_request', 'nvx_attribution_relay_direct_form_after_hubspot', 20, 3 );

<?php
/**
 * HubSpot Secure Attribution Bridge — Runtime Contract v2.
 *
 * Replaces the canonical public HubSpot form submission with one authenticated
 * server-to-server request while preserving the first-party lead flow:
 *
 * 1. Lead creation never depends on marketing consent.
 * 2. nvx_lead_id is first-party lineage and may cross the bridge after UUID v4 validation.
 * 3. Marketing attribution is carried only when explicit marketing consent exists.
 * 4. QA identity is always rebuilt server-side and cannot be enabled by the browser.
 * 5. Staging2 may send only deterministic server-owned QA submissions.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve the canonical HubSpot identity (portal ID and form ID) as a validated pair.
 *
 * Fails closed if a higher-priority identity source is configured but malformed,
 * preventing silent fallback to a different account or legacy identity.
 *
 * @return array{portal_id:string,form_id:string}
 */
function nvx_hubspot_secure_identity(): array {
	$sources = array(
		// 1. Canonical constants
		array(
			'portal' => defined( 'NVX_HUBSPOT_PORTAL_ID' ) ? (string) NVX_HUBSPOT_PORTAL_ID : null,
			'form'   => defined( 'NVX_HUBSPOT_VALORACION_FORM_ID' ) ? (string) NVX_HUBSPOT_VALORACION_FORM_ID : null,
		),
		// 2. Legacy constants
		array(
			'portal' => defined( 'NVX_VALORACION_HS_FRAME_PORTAL_ID' ) ? (string) NVX_VALORACION_HS_FRAME_PORTAL_ID : null,
			'form'   => defined( 'NVX_VALORACION_HS_FRAME_FORM_ID' ) ? (string) NVX_VALORACION_HS_FRAME_FORM_ID : null,
		),
		// 3. Canonical environment variables
		array(
			'portal' => false !== getenv( 'NVX_HUBSPOT_PORTAL_ID' ) ? (string) getenv( 'NVX_HUBSPOT_PORTAL_ID' ) : null,
			'form'   => false !== getenv( 'NVX_HUBSPOT_VALORACION_FORM_ID' ) ? (string) getenv( 'NVX_HUBSPOT_VALORACION_FORM_ID' ) : null,
		),
		// 4. Legacy environment variables
		array(
			'portal' => false !== getenv( 'NVX_VALORACION_HS_FRAME_PORTAL_ID' ) ? (string) getenv( 'NVX_VALORACION_HS_FRAME_PORTAL_ID' ) : null,
			'form'   => false !== getenv( 'NVX_VALORACION_HS_FRAME_FORM_ID' ) ? (string) getenv( 'NVX_VALORACION_HS_FRAME_FORM_ID' ) : null,
		),
	);

	foreach ( $sources as $source ) {
		$raw_portal = $source['portal'];
		$raw_form   = $source['form'];

		if ( null === $raw_portal && null === $raw_form ) {
			continue;
		}

		$portal = null !== $raw_portal ? trim( $raw_portal ) : '';
		$form   = null !== $raw_form ? strtolower( trim( $raw_form ) ) : '';

		$valid_portal = 1 === preg_match( '/^\d{1,20}$/', $portal );
		$valid_form   = 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $form );

		if ( $valid_portal && $valid_form ) {
			return array(
				'portal_id' => $portal,
				'form_id'   => $form,
			);
		}

		// Present but invalid/partial in this layer: fail closed immediately.
		return array(
			'portal_id' => '',
			'form_id'   => '',
		);
	}

	return array(
		'portal_id' => '',
		'form_id'   => '',
	);
}

/**
 * Resolve the canonical HubSpot portal id used by the first-party form.
 *
 * Account identity must be provisioned by the environment. Never fall back to
 * a production portal when configuration is missing or malformed.
 */
function nvx_hubspot_secure_portal_id(): string {
	$identity = nvx_hubspot_secure_identity();
	$portal   = $identity['portal_id'];
	if ( '' !== $portal ) {
		return $portal;
	}

	return '';
}

/**
 * Resolve the canonical HubSpot valoración form id.
 *
 * Form identity must be provisioned by the environment. Never fall back to a
 * production form when configuration is missing or malformed.
 */
function nvx_hubspot_secure_form_id(): string {
	$identity = nvx_hubspot_secure_identity();
	$form     = $identity['form_id'];
	if ( '' !== $form ) {
		return $form;
	}

	return '';
}

/** Whether the environment has a valid canonical HubSpot form identity. */
function nvx_hubspot_secure_identity_configured(): bool {
	return '' !== nvx_hubspot_secure_portal_id() && '' !== nvx_hubspot_secure_form_id();
}

/**
 * Return the exact public Forms API URL used by nvx_valoracion_forward_to_hubspot().
 */
function nvx_hubspot_secure_original_url(): string {
	$portal = nvx_hubspot_secure_portal_id();
	$form   = nvx_hubspot_secure_form_id();
	if ( '' === $portal || '' === $form ) {
		return '';
	}

	return 'https://api.hsforms.com/submissions/v3/integration/submit/'
		. rawurlencode( $portal ) . '/'
		. rawurlencode( $form );
}

/**
 * Return the authenticated HubSpot server-to-server submit URL.
 */
function nvx_hubspot_secure_submit_url(): string {
	$portal = nvx_hubspot_secure_portal_id();
	$form   = nvx_hubspot_secure_form_id();
	if ( '' === $portal || '' === $form ) {
		return '';
	}

	return 'https://api.hsforms.com/submissions/v3/integration/secure/submit/'
		. rawurlencode( $portal ) . '/'
		. rawurlencode( $form );
}

/**
 * Retrieve a bounded POST value from the current first-party request.
 */
function nvx_hubspot_secure_post_value( string $name, int $max_len = 4096 ): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- bridge runs inside the already-validated direct form request.
	$value = isset( $_POST[ $name ] ) ? (string) wp_unslash( $_POST[ $name ] ) : '';
	$value = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max_len ) : substr( $value, 0, $max_len );
	return trim( $value );
}

/**
 * Validate a canonical UUID v4 lineage value.
 */
function nvx_hubspot_secure_is_uuid_v4( string $value ): bool {
	return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value );
}

/**
 * Fields whose values are privileged because they control QA isolation.
 * Browser values for these fields are always discarded and rebuilt server-side.
 *
 * @return string[]
 */
function nvx_hubspot_secure_server_owned_fields(): array {
	return array(
		'nvx_is_test_lead',
		'nvx_test_run_id',
	);
}

/**
 * Marketing attribution fields removed when consent is absent.
 * nvx_lead_id is deliberately excluded: it is first-party lead lineage.
 *
 * @return string[]
 */
function nvx_hubspot_secure_marketing_fields(): array {
	return array(
		'nvx_first_source',
		'nvx_first_medium',
		'nvx_first_campaign_id',
		'nvx_first_referrer_domain',
		'nvx_first_landing_url',
		'nvx_first_timestamp',
		'nvx_first_channel',
		'nvx_conversion_channel',
		'nvx_conversion_source',
		'nvx_conversion_medium',
		'nvx_conversion_campaign_id',
		'nvx_conversion_landing_url',
		'nvx_conversion_timestamp',
		'nvx_google_click_id',
		'nvx_google_braid',
		'nvx_google_wbraid',
		'nvx_google_gclsrc',
		'nvx_utm_source',
		'nvx_utm_medium',
		'nvx_utm_campaign',
		'nvx_utm_content',
		'nvx_utm_term',
		'nvx_landing_url',
		'nvx_attribution_captured_at',
		'nvx_attribution_expires_at',
		'hs_google_click_id',
	);
}

/**
 * Normalize fields crossing the authenticated bridge.
 *
 * QA values are removed unconditionally. Marketing fields survive only when
 * consent is explicit. nvx_lead_id survives only when it is a valid UUID v4.
 *
 * @param array $fields            Raw HubSpot fields array.
 * @param bool  $marketing_consent Whether marketing attribution may be sent.
 * @return array
 */
function nvx_hubspot_secure_filter_fields( array $fields, bool $marketing_consent ): array {
	$server_owned = array_fill_keys( nvx_hubspot_secure_server_owned_fields(), true );
	$marketing    = array_fill_keys( nvx_hubspot_secure_marketing_fields(), true );
	$output       = array();

	foreach ( $fields as $field ) {
		if ( ! is_array( $field ) || ! isset( $field['name'] ) ) {
			continue;
		}

		$name = (string) $field['name'];
		if ( isset( $server_owned[ $name ] ) ) {
			continue;
		}
		if ( ! $marketing_consent && isset( $marketing[ $name ] ) ) {
			continue;
		}
		if ( 'nvx_lead_id' === $name ) {
			$value = strtolower( trim( (string) ( $field['value'] ?? '' ) ) );
			if ( ! nvx_hubspot_secure_is_uuid_v4( $value ) ) {
				continue;
			}
			$field['value'] = $value;
		}

		$output[] = $field;
	}

	return array_values( $output );
}

/**
 * Append deterministic server-owned QA identity.
 */
function nvx_hubspot_secure_append_qa( array $fields ): array {
	$qa = function_exists( 'nvx_attribution_qa_context' )
		? nvx_attribution_qa_context()
		: array(
			'is_test_lead' => false,
			'test_run_id'  => '',
		);

	$fields[] = array(
		'objectTypeId' => '0-1',
		'name'         => 'nvx_is_test_lead',
		'value'        => ! empty( $qa['is_test_lead'] ) ? 'true' : 'false',
	);
	$fields[] = array(
		'objectTypeId' => '0-1',
		'name'         => 'nvx_test_run_id',
		'value'        => (string) ( $qa['test_run_id'] ?? '' ),
	);

	return $fields;
}

/**
 * Whether a decoded payload is the narrow server-owned Staging2 QA case.
 */
function nvx_hubspot_secure_payload_is_staging_qa( array $payload ): bool {
	$host = strtolower( (string) wp_parse_url( get_site_url(), PHP_URL_HOST ) );
	if ( 'staging2.nuvanx.com' !== $host ) {
		return false;
	}

	$fields      = isset( $payload['fields'] ) && is_array( $payload['fields'] ) ? $payload['fields'] : array();
	$test_lead   = '';
	$test_run_id = '';
	foreach ( $fields as $field ) {
		if ( ! is_array( $field ) ) {
			continue;
		}
		if ( isset( $field['name'] ) && 'nvx_is_test_lead' === $field['name'] ) {
			$test_lead = (string) ( $field['value'] ?? '' );
		}
		if ( isset( $field['name'] ) && 'nvx_test_run_id' === $field['name'] ) {
			$test_run_id = (string) ( $field['value'] ?? '' );
		}
	}

	return 'true' === $test_lead && 0 === strpos( $test_run_id, 'staging2-' );
}

/**
 * Preempt the public HubSpot form submission with one authenticated POST.
 *
 * Lead transport is independent of advertising consent. If marketing consent
 * is absent, only attribution fields are removed; identity + request content
 * still reach HubSpot. On Staging2, only server-owned QA payloads may leave.
 */
function nvx_hubspot_secure_pre_http_request( $preempt, array $args, string $url ) {
	$original_url = nvx_hubspot_secure_original_url();
	if ( '' === $original_url ) {
		$public_submit_prefix = 'https://api.hsforms.com/submissions/v3/integration/submit/';
		if ( 0 === strpos( $url, $public_submit_prefix ) ) {
			return new WP_Error( 'nvx_missing_hubspot_identity', 'HubSpot portal/form identity is not configured.' );
		}
		return $preempt;
	}

	if ( $original_url !== $url ) {
		return $preempt;
	}

	$secure_url = nvx_hubspot_secure_submit_url();
	if ( '' === $secure_url ) {
		return new WP_Error( 'nvx_missing_hubspot_identity', 'HubSpot portal/form identity is not configured.' );
	}

	if ( ! defined( 'NVX_HUBSPOT_ACCESS_TOKEN' ) ) {
		return new WP_Error( 'nvx_missing_credential', 'NVX_HUBSPOT_ACCESS_TOKEN is not defined.' );
	}
	$token = (string) NVX_HUBSPOT_ACCESS_TOKEN;
	if ( '' === $token ) {
		return new WP_Error( 'nvx_missing_credential', 'NVX_HUBSPOT_ACCESS_TOKEN is empty.' );
	}

	$body    = isset( $args['body'] ) ? $args['body'] : '';
	$payload = is_string( $body ) ? json_decode( $body, true ) : (array) $body;
	if ( ! is_array( $payload ) ) {
		$payload = array();
	}

	$marketing_consent = '1' === nvx_hubspot_secure_post_value( 'nvx_marketing_consent', 1 );
	$fields            = isset( $payload['fields'] ) && is_array( $payload['fields'] ) ? $payload['fields'] : array();
	$fields            = nvx_hubspot_secure_filter_fields( $fields, $marketing_consent );
	$fields            = nvx_hubspot_secure_append_qa( $fields );
	$payload['fields'] = $fields;

	if ( function_exists( 'nvx_environment_is_staging2' ) && nvx_environment_is_staging2() && ! nvx_hubspot_secure_payload_is_staging_qa( $payload ) ) {
		return new WP_Error( 'nvx_staging_outbound_blocked', 'Staging2 outbound HubSpot traffic is restricted to server-owned QA submissions.' );
	}

	return wp_remote_post(
		$secure_url,
		array(
			'method'  => 'POST',
			'timeout' => 15,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $token,
			),
			'body'    => wp_json_encode( $payload ),
		)
	);
}
add_filter( 'pre_http_request', 'nvx_hubspot_secure_pre_http_request', 10, 3 );

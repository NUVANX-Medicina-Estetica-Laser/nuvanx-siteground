<?php
/**
 * HubSpot Secure Attribution Bridge — Runtime Contract v2.
 *
 * Replaces the canonical public HubSpot form submission with one authenticated
 * server-to-server request while preserving the first-party lead flow.
 *
 * @package nuvanx-medical
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'NVX_HUBSPOT_SECURE_MAX_BODY_BYTES' ) ) {
	define( 'NVX_HUBSPOT_SECURE_MAX_BODY_BYTES', 65536 );
}

/**
 * Emit bounded operational telemetry.
 *
 * Never log tokens, request bodies, response bodies or personal data.
 */
if ( ! function_exists( 'nvx_hubspot_secure_log' ) ) {
	function nvx_hubspot_secure_log(
		string $outcome,
		string $reason = '',
		int $status = 0
	): void {
		$allowed_outcomes = array(
			'SUCCESS',
			'FAILURE',
			'TRANSPORT',
		);

		$outcome = strtoupper( sanitize_key( $outcome ) );

		if ( ! in_array( $outcome, $allowed_outcomes, true ) || ! function_exists( 'nvx_observability_log' ) ) {
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

/**
 * Safely limit a UTF-8 string.
 */
if ( ! function_exists( 'nvx_hubspot_secure_limit_string' ) ) {
	function nvx_hubspot_secure_limit_string(
		string $value,
		int $max_length
	): string {
		$max_length = max( 1, $max_length );

		if ( function_exists( 'mb_substr' ) ) {
			return (string) mb_substr( $value, 0, $max_length, 'UTF-8' );
		}

		return substr( $value, 0, $max_length );
	}
}

/**
 * Resolve the canonical HubSpot identity as a validated pair.
 *
 * Higher-priority configuration that is present but invalid fails closed.
 *
 * @return array{portal_id:string,form_id:string}
 */
if ( ! function_exists( 'nvx_hubspot_secure_identity' ) ) {
	function nvx_hubspot_secure_identity(): array {
		static $resolved = null;

		if ( is_array( $resolved ) ) {
			return $resolved;
		}

		$sources = array(
			array(
				'portal' => defined( 'NVX_HUBSPOT_PORTAL_ID' )
					? (string) NVX_HUBSPOT_PORTAL_ID
					: null,
				'form'   => defined( 'NVX_HUBSPOT_VALORACION_FORM_ID' )
					? (string) NVX_HUBSPOT_VALORACION_FORM_ID
					: null,
			),
			array(
				'portal' => defined( 'NVX_VALORACION_HS_FRAME_PORTAL_ID' )
					? (string) NVX_VALORACION_HS_FRAME_PORTAL_ID
					: null,
				'form'   => defined( 'NVX_VALORACION_HS_FRAME_FORM_ID' )
					? (string) NVX_VALORACION_HS_FRAME_FORM_ID
					: null,
			),
			array(
				'portal' => false !== getenv( 'NVX_HUBSPOT_PORTAL_ID' )
					? (string) getenv( 'NVX_HUBSPOT_PORTAL_ID' )
					: null,
				'form'   => false !== getenv( 'NVX_HUBSPOT_VALORACION_FORM_ID' )
					? (string) getenv( 'NVX_HUBSPOT_VALORACION_FORM_ID' )
					: null,
			),
			array(
				'portal' => false !== getenv( 'NVX_VALORACION_HS_FRAME_PORTAL_ID' )
					? (string) getenv( 'NVX_VALORACION_HS_FRAME_PORTAL_ID' )
					: null,
				'form'   => false !== getenv( 'NVX_VALORACION_HS_FRAME_FORM_ID' )
					? (string) getenv( 'NVX_VALORACION_HS_FRAME_FORM_ID' )
					: null,
			),
		);

		foreach ( $sources as $source ) {
			$raw_portal = $source['portal'];
			$raw_form   = $source['form'];

			if ( null === $raw_portal && null === $raw_form ) {
				continue;
			}

			$portal = null !== $raw_portal
				? trim( sanitize_text_field( $raw_portal ) )
				: '';

			$form = null !== $raw_form
				? strtolower( trim( sanitize_text_field( $raw_form ) ) )
				: '';

			$valid_portal = 1 === preg_match( '/^\d{1,20}$/D', $portal );
			$valid_form   = 1 === preg_match(
				'/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',
				$form
			);

			if ( $valid_portal && $valid_form ) {
				$resolved = array(
					'portal_id' => $portal,
					'form_id'   => $form,
				);

				return $resolved;
			}

			nvx_hubspot_secure_log( 'FAILURE', 'invalid_identity' );

			$resolved = array(
				'portal_id' => '',
				'form_id'   => '',
			);

			return $resolved;
		}

		$resolved = array(
			'portal_id' => '',
			'form_id'   => '',
		);

		return $resolved;
	}
}

/** Resolve the canonical HubSpot portal ID. */
if ( ! function_exists( 'nvx_hubspot_secure_portal_id' ) ) {
	function nvx_hubspot_secure_portal_id(): string {
		return nvx_hubspot_secure_identity()['portal_id'];
	}
}

/** Resolve the canonical HubSpot valoración form ID. */
if ( ! function_exists( 'nvx_hubspot_secure_form_id' ) ) {
	function nvx_hubspot_secure_form_id(): string {
		return nvx_hubspot_secure_identity()['form_id'];
	}
}

/** Whether canonical HubSpot identity is configured. */
if ( ! function_exists( 'nvx_hubspot_secure_identity_configured' ) ) {
	function nvx_hubspot_secure_identity_configured(): bool {
		return '' !== nvx_hubspot_secure_portal_id()
			&& '' !== nvx_hubspot_secure_form_id();
	}
}

/** Public HubSpot Forms API URL intercepted by this bridge. */
if ( ! function_exists( 'nvx_hubspot_secure_original_url' ) ) {
	function nvx_hubspot_secure_original_url(): string {
		$portal = nvx_hubspot_secure_portal_id();
		$form   = nvx_hubspot_secure_form_id();

		if ( '' === $portal || '' === $form ) {
			return '';
		}

		return 'https://api.hsforms.com/submissions/v3/integration/submit/'
			. rawurlencode( $portal )
			. '/'
			. rawurlencode( $form );
	}
}

/** Authenticated HubSpot server-to-server submit URL. */
if ( ! function_exists( 'nvx_hubspot_secure_submit_url' ) ) {
	function nvx_hubspot_secure_submit_url(): string {
		$portal = nvx_hubspot_secure_portal_id();
		$form   = nvx_hubspot_secure_form_id();

		if ( '' === $portal || '' === $form ) {
			return '';
		}

		return 'https://api.hsforms.com/submissions/v3/integration/secure/submit/'
			. rawurlencode( $portal )
			. '/'
			. rawurlencode( $form );
	}
}

/** Validate canonical UUID v4 lineage. */
if ( ! function_exists( 'nvx_hubspot_secure_is_uuid_v4' ) ) {
	function nvx_hubspot_secure_is_uuid_v4( string $value ): bool {
		return 1 === preg_match(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD',
			$value
		);
	}
}

/** @return string[] */
if ( ! function_exists( 'nvx_hubspot_secure_server_owned_fields' ) ) {
	function nvx_hubspot_secure_server_owned_fields(): array {
		return array(
			'nvx_is_test_lead',
			'nvx_test_run_id',
		);
	}
}

/** @return string[] */
if ( ! function_exists( 'nvx_hubspot_secure_marketing_fields' ) ) {
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
}

/** @return string[] */
if ( ! function_exists( 'nvx_hubspot_secure_url_fields' ) ) {
	function nvx_hubspot_secure_url_fields(): array {
		return array(
			'nvx_first_landing_url',
			'nvx_conversion_landing_url',
			'nvx_landing_url',
		);
	}
}

/**
 * Normalize a single field crossing the privileged bridge.
 *
 * @param array<string,mixed> $field Raw HubSpot field.
 * @return array{objectTypeId:string,name:string,value:string}|null
 */
if ( ! function_exists( 'nvx_hubspot_secure_normalize_field' ) ) {
	function nvx_hubspot_secure_normalize_field( array $field ): ?array {
		$raw_name = isset( $field['name'] ) ? (string) $field['name'] : '';
		$name     = sanitize_key( $raw_name );

		if ( '' === $name || $name !== $raw_name ) {
			return null;
		}

		$raw_value = $field['value'] ?? '';
		if ( ! is_scalar( $raw_value ) && null !== $raw_value ) {
			return null;
		}

		$value = (string) $raw_value;
		if ( 'email' === $name ) {
			$value = sanitize_email( $value );
			if ( '' !== $value && ! is_email( $value ) ) {
				return null;
			}
			$value = nvx_hubspot_secure_limit_string( $value, 120 );
		} elseif ( 'message' === $name ) {
			$value = sanitize_textarea_field( $value );
			$value = nvx_hubspot_secure_limit_string( $value, 2000 );
		} elseif ( in_array( $name, nvx_hubspot_secure_url_fields(), true ) ) {
			$value = sanitize_url( $value );
			$value = nvx_hubspot_secure_limit_string( $value, 2048 );
		} else {
			$value = sanitize_text_field( $value );
			$value = nvx_hubspot_secure_limit_string( $value, 4096 );
		}

		$object_type = isset( $field['objectTypeId'] )
			? sanitize_text_field( (string) $field['objectTypeId'] )
			: '0-1';
		if ( '0-1' !== $object_type ) {
			return null;
		}

		return array(
			'objectTypeId' => '0-1',
			'name'         => $name,
			'value'        => $value,
		);
	}
}

/**
 * Normalize fields crossing the authenticated bridge.
 *
 * @param array<int,mixed> $fields Raw HubSpot fields.
 * @return array<int,array{objectTypeId:string,name:string,value:string}>
 */
if ( ! function_exists( 'nvx_hubspot_secure_filter_fields' ) ) {
	function nvx_hubspot_secure_filter_fields( array $fields, bool $marketing_consent ): array {
		$server_owned = array_fill_keys( nvx_hubspot_secure_server_owned_fields(), true );
		$marketing    = array_fill_keys( nvx_hubspot_secure_marketing_fields(), true );
		$output       = array();

		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || ! isset( $field['name'] ) ) {
				continue;
			}
			$name = sanitize_key( (string) $field['name'] );
			if ( '' === $name || isset( $server_owned[ $name ] ) || ( ! $marketing_consent && isset( $marketing[ $name ] ) ) ) {
				continue;
			}

			$normalized = nvx_hubspot_secure_normalize_field( $field );
			if ( null === $normalized ) {
				continue;
			}

			if ( 'nvx_lead_id' === $name ) {
				$value = strtolower( trim( $normalized['value'] ) );
				if ( ! nvx_hubspot_secure_is_uuid_v4( $value ) ) {
					continue;
				}
				$normalized['value'] = $value;
			}
			$output[] = $normalized;
		}
		return array_values( $output );
	}
}

/**
 * Append deterministic server-owned QA identity.
 *
 * @param array<int,array{objectTypeId:string,name:string,value:string}> $fields
 * @return array<int,array{objectTypeId:string,name:string,value:string}>
 */
if ( ! function_exists( 'nvx_hubspot_secure_append_qa' ) ) {
	function nvx_hubspot_secure_append_qa( array $fields ): array {
		$qa = function_exists( 'nvx_attribution_qa_context' )
			? nvx_attribution_qa_context()
			: array( 'is_test_lead' => false, 'test_run_id' => '' );

		$is_test_lead = isset( $qa['is_test_lead'] ) && true === (bool) $qa['is_test_lead'];
		$test_run_id  = isset( $qa['test_run_id'] ) ? sanitize_text_field( (string) $qa['test_run_id'] ) : '';
		$test_run_id  = nvx_hubspot_secure_limit_string( $test_run_id, 120 );

		if ( $is_test_lead && 1 !== preg_match( '/^staging2-[A-Za-z0-9._:-]{1,110}$/D', $test_run_id ) ) {
			$is_test_lead = false;
			$test_run_id  = '';
		}
		if ( ! $is_test_lead ) {
			$test_run_id = '';
		}

		$fields[] = array( 'objectTypeId' => '0-1', 'name' => 'nvx_is_test_lead', 'value' => $is_test_lead ? 'true' : 'false' );
		$fields[] = array( 'objectTypeId' => '0-1', 'name' => 'nvx_test_run_id', 'value' => $test_run_id );
		return $fields;
	}
}

/**
 * Determine whether payload is the narrowly authorized Staging2 QA case.
 *
 * @param array<string,mixed> $payload
 */
if ( ! function_exists( 'nvx_hubspot_secure_payload_is_staging_qa' ) ) {
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
			$name  = isset( $field['name'] ) ? sanitize_key( (string) $field['name'] ) : '';
			$value = isset( $field['value'] ) ? sanitize_text_field( (string) $field['value'] ) : '';
			if ( 'nvx_is_test_lead' === $name ) {
				$test_lead = $value;
			}
			if ( 'nvx_test_run_id' === $name ) {
				$test_run_id = $value;
			}
		}
		return 'true' === $test_lead && 1 === preg_match( '/^staging2-[A-Za-z0-9._:-]{1,110}$/D', $test_run_id );
	}
}

/** @param array<string,mixed> $headers */
if ( ! function_exists( 'nvx_hubspot_secure_request_header' ) ) {
	function nvx_hubspot_secure_request_header( array $headers, string $wanted ): string {
		foreach ( $headers as $name => $value ) {
			if ( 0 !== strcasecmp( (string) $name, $wanted ) ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$value = implode( ',', $value );
			}
			return trim( (string) $value );
		}
		return '';
	}
}

/** @param array<string,mixed> $args */
if ( ! function_exists( 'nvx_hubspot_secure_valid_original_request' ) ) {
	function nvx_hubspot_secure_valid_original_request( array $args ): bool {
		$method = strtoupper( sanitize_text_field( (string) ( $args['method'] ?? '' ) ) );
		if ( 'POST' !== $method ) {
			return false;
		}

		$headers      = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array();
		$content_type = strtolower( nvx_hubspot_secure_request_header( $headers, 'Content-Type' ) );
		if ( '' === $content_type || 0 !== strpos( $content_type, 'application/json' ) ) {
			return false;
		}

		$body = $args['body'] ?? '';
		return is_string( $body ) && strlen( $body ) <= (int) NVX_HUBSPOT_SECURE_MAX_BODY_BYTES;
	}
}

/**
 * Intercept the canonical public HubSpot request and replace it with one
 * authenticated server-to-server request.
 *
 * @param mixed $preempt Existing preempted response or false.
 * @param mixed $args    Parsed wp_remote_* arguments.
 * @param mixed $url     Requested URL.
 * @return mixed
 */
if ( ! function_exists( 'nvx_hubspot_secure_pre_http_request' ) ) {
	function nvx_hubspot_secure_pre_http_request( mixed $preempt, mixed $args, mixed $url ): mixed {
		$url          = is_string( $url ) ? $url : '';
		$original_url = nvx_hubspot_secure_original_url();

		if ( '' === $original_url ) {
			$public_submit_prefix = 'https://api.hsforms.com/submissions/v3/integration/submit/';
			if ( 0 === strpos( $url, $public_submit_prefix ) ) {
				nvx_hubspot_secure_log( 'FAILURE', 'identity_missing' );
				return new WP_Error( 'nvx_missing_hubspot_identity', 'HubSpot portal/form identity is not configured.' );
			}
			return $preempt;
		}

		if ( ! hash_equals( $original_url, $url ) || false !== $preempt ) {
			return $preempt;
		}

		$args = is_array( $args ) ? $args : array();
		if ( ! nvx_hubspot_secure_valid_original_request( $args ) ) {
			nvx_hubspot_secure_log( 'FAILURE', 'invalid_request_shape' );
			return new WP_Error( 'nvx_invalid_hubspot_request', 'HubSpot request transport contract is invalid.' );
		}

		$secure_url = nvx_hubspot_secure_submit_url();
		if ( '' === $secure_url ) {
			nvx_hubspot_secure_log( 'FAILURE', 'secure_url_missing' );
			return new WP_Error( 'nvx_missing_hubspot_identity', 'HubSpot portal/form identity is not configured.' );
		}

		if ( ! defined( 'NVX_HUBSPOT_ACCESS_TOKEN' ) ) {
			nvx_hubspot_secure_log( 'FAILURE', 'credential_missing' );
			return new WP_Error( 'nvx_missing_credential', 'HubSpot server credential is not configured.' );
		}
		$token = trim( (string) NVX_HUBSPOT_ACCESS_TOKEN );
		if ( '' === $token ) {
			nvx_hubspot_secure_log( 'FAILURE', 'credential_empty' );
			return new WP_Error( 'nvx_missing_credential', 'HubSpot server credential is not configured.' );
		}

		if ( ! function_exists( 'nvx_marketing_consent_granted' ) ) {
			nvx_hubspot_secure_log( 'FAILURE', 'consent_owner_missing' );
			return new WP_Error( 'nvx_missing_consent_owner', 'Marketing consent owner is unavailable.' );
		}

		$body    = (string) ( $args['body'] ?? '' );
		$payload = json_decode( $body, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $payload ) ) {
			nvx_hubspot_secure_log( 'FAILURE', 'invalid_json' );
			return new WP_Error( 'nvx_invalid_hubspot_payload', 'HubSpot request payload is invalid.' );
		}

		$marketing_consent = nvx_marketing_consent_granted();
		$fields            = isset( $payload['fields'] ) && is_array( $payload['fields'] ) ? $payload['fields'] : array();
		$fields            = nvx_hubspot_secure_filter_fields( $fields, $marketing_consent );
		$fields            = nvx_hubspot_secure_append_qa( $fields );
		$payload['fields'] = $fields;

		if ( function_exists( 'nvx_environment_is_staging2' ) && nvx_environment_is_staging2() && ! nvx_hubspot_secure_payload_is_staging_qa( $payload ) ) {
			nvx_hubspot_secure_log( 'FAILURE', 'staging_outbound_blocked' );
			return new WP_Error( 'nvx_staging_outbound_blocked', 'Staging2 outbound HubSpot traffic is restricted to server-owned QA submissions.' );
		}

		$encoded_payload = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $encoded_payload ) || '' === $encoded_payload || strlen( $encoded_payload ) > (int) NVX_HUBSPOT_SECURE_MAX_BODY_BYTES ) {
			nvx_hubspot_secure_log( 'FAILURE', 'payload_encode' );
			return new WP_Error( 'nvx_hubspot_payload_encode_failed', 'HubSpot request payload could not be encoded.' );
		}

		$response = wp_remote_post(
			$secure_url,
			array(
				'method'             => 'POST',
				'timeout'            => 15,
				'redirection'        => 0,
				'blocking'           => true,
				'reject_unsafe_urls' => true,
				'headers'            => array(
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $token,
				),
				'body' => $encoded_payload,
			)
		);

		if ( is_wp_error( $response ) ) {
			nvx_hubspot_secure_log( 'TRANSPORT', sanitize_key( (string) $response->get_error_code() ) );
			return $response;
		}

		$status = absint( wp_remote_retrieve_response_code( $response ) );
		if ( $status < 200 || $status >= 300 ) {
			nvx_hubspot_secure_log( 'FAILURE', 'hubspot_http', $status );
			return $response;
		}

		nvx_hubspot_secure_log( 'SUCCESS', '', $status );
		return $response;
	}
}

if (
	function_exists( 'has_filter' )
	&& false === has_filter( 'pre_http_request', 'nvx_hubspot_secure_pre_http_request' )
) {
	add_filter( 'pre_http_request', 'nvx_hubspot_secure_pre_http_request', 10, 3 );
}

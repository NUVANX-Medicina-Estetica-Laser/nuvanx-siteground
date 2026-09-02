<?php
/**
 * Canonical lead-captured relay.
 *
 * Observes successful authenticated HubSpot submissions and mirrors only
 * first-party lineage and consented attribution into Supabase.
 *
 * HubSpot remains authoritative for the patient response.
 *
 * @package nuvanx-medical
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'NVX_LEAD_CAPTURED_MAX_BODY_BYTES' ) ) {
	define( 'NVX_LEAD_CAPTURED_MAX_BODY_BYTES', 32768 );
}

if ( ! defined( 'NVX_LEAD_CAPTURED_HMAC_CONTEXT' ) ) {
	define(
		'NVX_LEAD_CAPTURED_HMAC_CONTEXT',
		'nuvanx-lead-capture-hmac-key-v1'
	);
}

/**
 * Bound a UTF-8 string.
 */
if ( ! function_exists( 'nvx_lead_captured_limit_string' ) ) {
	function nvx_lead_captured_limit_string(
		string $value,
		int $max_length
	): string {
		$max_length = max( 1, $max_length );

		if ( function_exists( 'mb_substr' ) ) {
			return (string) mb_substr(
				$value,
				0,
				$max_length,
				'UTF-8'
			);
		}

		return substr( $value, 0, $max_length );
	}
}

/**
 * Canonical Supabase lead-capture URL.
 */
if ( ! function_exists( 'nvx_lead_captured_endpoint' ) ) {
	function nvx_lead_captured_endpoint(): string {
		return 'https://ssvvuuysgxyqvmovrlvk.supabase.co/functions/v1/lead-captured';
	}
}

/**
 * Canonical runtime-bootstrap URL.
 */
if ( ! function_exists( 'nvx_lead_captured_bootstrap_endpoint' ) ) {
	function nvx_lead_captured_bootstrap_endpoint(): string {
		return 'https://ssvvuuysgxyqvmovrlvk.supabase.co/functions/v1/runtime-bootstrap';
	}
}

/**
 * Resolve server-only HubSpot token.
 */
if ( ! function_exists( 'nvx_lead_captured_hubspot_token' ) ) {
	function nvx_lead_captured_hubspot_token(): string {
		if ( ! defined( 'NVX_HUBSPOT_ACCESS_TOKEN' ) ) {
			return '';
		}

		return trim( (string) NVX_HUBSPOT_ACCESS_TOKEN );
	}
}

/**
 * Validate UUID v4.
 */
if ( ! function_exists( 'nvx_lead_captured_is_uuid_v4' ) ) {
	function nvx_lead_captured_is_uuid_v4( string $value ): bool {
		return 1 === preg_match(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD',
			$value
		);
	}
}

/**
 * Derive capture HMAC key.
 */
if ( ! function_exists( 'nvx_lead_captured_derive_hmac_key' ) ) {
	function nvx_lead_captured_derive_hmac_key( string $token ): string {
		$raw_key = hash_hmac(
			'sha256',
			NVX_LEAD_CAPTURED_HMAC_CONTEXT,
			$token,
			true
		);

		return bin2hex( $raw_key );
	}
}

/**
 * Bootstrap runtime credential.
 */
if ( ! function_exists( 'nvx_lead_captured_bootstrap_runtime' ) ) {
	function nvx_lead_captured_bootstrap_runtime(
		string $token,
		bool $force = false
	): bool {
		if ( '' === trim( $token ) ) {
			return false;
		}

		$transient = 'nvx_runtime_bootstrap_ok_v1';

		if (
			! $force
			&& '1' === (string) get_transient( $transient )
		) {
			return true;
		}

		if ( $force ) {
			delete_transient( $transient );
		}

		$response = wp_remote_post(
			nvx_lead_captured_bootstrap_endpoint(),
			array(
				'method'             => 'POST',
				'timeout'            => 5,
				'redirection'        => 0,
				'blocking'           => true,
				'reject_unsafe_urls' => true,
				'headers'            => array(
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'               => '{}',
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log(
				sprintf(
					'[NUVANX] runtime bootstrap transport failure; wp_error_code=%s.',
					sanitize_key(
						(string) $response->get_error_code()
					)
				)
			);

			return false;
		}

		$status = absint(
			wp_remote_retrieve_response_code( $response )
		);

		if ( $status < 200 || $status >= 300 ) {
			error_log(
				sprintf(
					'[NUVANX] runtime bootstrap HTTP failure; status=%d.',
					$status
				)
			);

			return false;
		}

		set_transient(
			$transient,
			'1',
			HOUR_IN_SECONDS
		);

		return true;
	}
}

/**
 * Convert HubSpot fields to bounded name => value map.
 *
 * @param array<string,mixed> $payload HubSpot payload.
 * @return array<string,string>
 */
if ( ! function_exists( 'nvx_lead_captured_field_map' ) ) {
	function nvx_lead_captured_field_map( array $payload ): array {
		$output = array();

		$fields = isset( $payload['fields'] )
			&& is_array( $payload['fields'] )
				? $payload['fields']
				: array();

		foreach ( $fields as $field ) {
			if (
				! is_array( $field )
				|| ! isset( $field['name'] )
			) {
				continue;
			}

			$raw_name = (string) $field['name'];
			$name     = sanitize_key( $raw_name );

			if ( '' === $name || $name !== $raw_name ) {
				continue;
			}

			$raw_value = $field['value'] ?? '';

			if (
				! is_scalar( $raw_value )
				&& null !== $raw_value
			) {
				continue;
			}

			$value = nvx_lead_captured_limit_string(
				sanitize_text_field( (string) $raw_value ),
				4096
			);

			$output[ $name ] = trim( $value );
		}

		return $output;
	}
}

/**
 * Verify that browser-owned POST/cookies belong to the validated direct form.
 */
if ( ! function_exists( 'nvx_lead_captured_is_validated_direct_form_request' ) ) {
	function nvx_lead_captured_is_validated_direct_form_request(): bool {
		$method = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper(
				sanitize_text_field(
					wp_unslash(
						(string) $_SERVER['REQUEST_METHOD']
					)
				)
			)
			: '';

		if ( 'POST' !== $method ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified below.
		$marker = isset( $_POST['nvx_valoracion_submit'] )
			? sanitize_text_field(
				wp_unslash(
					(string) $_POST['nvx_valoracion_submit']
				)
			)
			: '';

		if ( '1' !== $marker ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified below.
		$nonce = isset( $_POST['nvx_valoracion_nonce'] )
			? sanitize_text_field(
				wp_unslash(
					(string) $_POST['nvx_valoracion_nonce']
				)
			)
			: '';

		if ( '' === $nonce ) {
			return false;
		}

		return false !== wp_verify_nonce(
			$nonce,
			'nvx_valoracion_submit'
		);
	}
}

/**
 * Read consented Meta browser identity.
 *
 * @return array<string,string>
 */
if ( ! function_exists( 'nvx_lead_captured_meta_identity' ) ) {
	function nvx_lead_captured_meta_identity(
		bool $marketing_consent
	): array {
		if (
			! $marketing_consent
			|| ! nvx_lead_captured_is_validated_direct_form_request()
		) {
			return array();
		}

		$output = array();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- request verified above.
		$fbclid = isset( $_POST['fbclid'] )
			? sanitize_text_field(
				wp_unslash( (string) $_POST['fbclid'] )
			)
			: '';

		$fbclid = trim( $fbclid );

		if (
			'' !== $fbclid
			&& strlen( $fbclid ) <= 512
			&& 1 === preg_match(
				'/^[A-Za-z0-9._~:+-]+$/D',
				$fbclid
			)
		) {
			$output['fbclid'] = $fbclid;
		}

		foreach (
			array(
				'fbc' => '_fbc',
				'fbp' => '_fbp',
			)
			as $key => $cookie_name
		) {
			$value = '';

			if ( isset( $_COOKIE[ $cookie_name ] ) ) {
				$value = sanitize_text_field(
					wp_unslash(
						(string) $_COOKIE[ $cookie_name ]
					)
				);
			} elseif ( isset( $_POST[ $key ] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- request verified above.
				$value = sanitize_text_field(
					wp_unslash(
						(string) $_POST[ $key ]
					)
				);
			}

			$value = trim( $value );

			if (
				'' !== $value
				&& strlen( $value ) <= 512
				&& 1 === preg_match(
					'/^fb\.1\.\d{10,16}\.[A-Za-z0-9._~:+-]+$/D',
					$value
				)
			) {
				$output[ $key ] = $value;
			}
		}

		return $output;
	}
}

/**
 * Normalize downstream attribution.
 */
if ( ! function_exists( 'nvx_lead_captured_attribution_value' ) ) {
	function nvx_lead_captured_attribution_value(
		string $key,
		string $value
	): string {
		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		if ( 'landing_url' === $key ) {
			return nvx_lead_captured_limit_string(
				sanitize_url( $value ),
				1000
			);
		}

		$max_length = 'gclsrc' === $key ? 128 : 512;

		return nvx_lead_captured_limit_string(
			sanitize_text_field( $value ),
			$max_length
		);
	}
}

/**
 * Build consented attribution snapshot.
 *
 * @param array<string,string> $fields HubSpot field map.
 * @return array<string,string>
 */
if ( ! function_exists( 'nvx_lead_captured_attribution' ) ) {
	function nvx_lead_captured_attribution(
		array $fields,
		string $prefix
	): array {
		if (
			'nvx_first_' !== $prefix
			&& 'nvx_conversion_' !== $prefix
		) {
			return array();
		}

		$property_map = array(
			'source'      => $prefix . 'source',
			'medium'      => $prefix . 'medium',
			'campaign_id' => $prefix . 'campaign_id',
			'landing_url' => $prefix . 'landing_url',
			'timestamp'   => $prefix . 'timestamp',
			'channel'     => $prefix . 'channel',
		);

		if ( 'nvx_first_' === $prefix ) {
			$property_map['referrer_domain'] =
				'nvx_first_referrer_domain';
		}

		$output = array();

		foreach ( $property_map as $key => $property ) {
			$value = isset( $fields[ $property ] )
				? nvx_lead_captured_attribution_value(
					$key,
					(string) $fields[ $property ]
				)
				: '';

			if ( '' !== $value ) {
				$output[ $key ] = $value;
			}
		}

		if ( 'nvx_conversion_' === $prefix ) {
			$generic = array(
				'utm_source'   => 'nvx_utm_source',
				'utm_medium'   => 'nvx_utm_medium',
				'utm_campaign' => 'nvx_utm_campaign',
				'utm_content'  => 'nvx_utm_content',
				'utm_term'     => 'nvx_utm_term',
				'gclid'        => 'nvx_google_click_id',
				'gbraid'       => 'nvx_google_braid',
				'wbraid'       => 'nvx_google_wbraid',
				'gclsrc'       => 'nvx_google_gclsrc',
			);

			foreach ( $generic as $key => $property ) {
				$value = isset( $fields[ $property ] )
					? nvx_lead_captured_attribution_value(
						$key,
						(string) $fields[ $property ]
					)
					: '';

				if ( '' !== $value ) {
					$output[ $key ] = $value;
				}
			}
		}

		return $output;
	}
}

/**
 * Extract optional HubSpot IDs.
 *
 * @param mixed $response HTTP response.
 * @return array{contact_id:string,submission_id:string}
 */
if ( ! function_exists( 'nvx_lead_captured_hubspot_ids' ) ) {
	function nvx_lead_captured_hubspot_ids(
		mixed $response
	): array {
		$result = array(
			'contact_id'    => '',
			'submission_id' => '',
		);

		if ( is_wp_error( $response ) ) {
			return $result;
		}

		$decoded = json_decode(
			(string) wp_remote_retrieve_body( $response ),
			true
		);

		if (
			JSON_ERROR_NONE !== json_last_error()
			|| ! is_array( $decoded )
		) {
			return $result;
		}

		foreach ( array( 'contactId', 'contact_id' ) as $key ) {
			if (
				isset( $decoded[ $key ] )
				&& 1 === preg_match(
					'/^[1-9][0-9]{0,18}$/D',
					(string) $decoded[ $key ]
				)
			) {
				$result['contact_id'] =
					(string) $decoded[ $key ];

				break;
			}
		}

		foreach (
			array(
				'submissionId',
				'submission_id',
				'conversionId',
				'conversion_id',
			)
			as $key
		) {
			if ( ! isset( $decoded[ $key ] ) ) {
				continue;
			}

			$value = trim(
				sanitize_text_field(
					(string) $decoded[ $key ]
				)
			);

			if (
				1 === preg_match(
					'/^[A-Za-z0-9._:-]{1,180}$/D',
					$value
				)
			) {
				$result['submission_id'] = $value;
				break;
			}
		}

		return $result;
	}
}

/**
 * Signed transport used by the outbox.
 *
 * @return array<string,mixed>|WP_Error
 */
if ( ! function_exists( 'nvx_lead_captured_post_signed' ) ) {
	function nvx_lead_captured_post_signed(
		string $body,
		string $token
	): array|WP_Error {
		if (
			'' === trim( $token )
			|| '' === $body
			|| strlen( $body ) > NVX_LEAD_CAPTURED_MAX_BODY_BYTES
		) {
			return new WP_Error(
				'nvx_lead_capture_invalid_transport',
				'Lead capture signed transport is unavailable.'
			);
		}

		json_decode( $body, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error(
				'nvx_lead_capture_invalid_json',
				'Lead capture payload is invalid.'
			);
		}

		$timestamp = (string) time();

		$signature = hash_hmac(
			'sha256',
			$timestamp . '.' . $body,
			nvx_lead_captured_derive_hmac_key( $token )
		);

		return wp_remote_post(
			nvx_lead_captured_endpoint(),
			array(
				'method'             => 'POST',
				'timeout'            => 5,
				'redirection'        => 0,
				'blocking'           => true,
				'reject_unsafe_urls' => true,
				'headers'            => array(
					'Accept'          => 'application/json',
					'Content-Type'    => 'application/json',
					'x-nvx-timestamp' => $timestamp,
					'x-nvx-signature' => $signature,
				),
				'body'               => $body,
			)
		);
	}
}

/**
 * Build capture payload before any Supabase network dependency.
 *
 * @param mixed               $response Successful HubSpot response.
 * @param array<string,mixed> $parsed_args HubSpot request args.
 */
if ( ! function_exists( 'nvx_lead_captured_build_relay_body' ) ) {
	function nvx_lead_captured_build_relay_body(
		mixed $response,
		array $parsed_args
	): string {
		$raw_payload = $parsed_args['body'] ?? '';

		if ( ! is_string( $raw_payload ) ) {
			return '';
		}

		$payload = json_decode( $raw_payload, true );

		if (
			JSON_ERROR_NONE !== json_last_error()
			|| ! is_array( $payload )
		) {
			return '';
		}

		$fields = nvx_lead_captured_field_map( $payload );

		$lead_id = strtolower(
			trim(
				(string) ( $fields['nvx_lead_id'] ?? '' )
			)
		);

		if ( ! nvx_lead_captured_is_uuid_v4( $lead_id ) ) {
			error_log(
				'[NUVANX] lead-captured relay skipped: valid nvx_lead_id missing.'
			);
			return '';
		}

		if ( ! function_exists( 'nvx_hubspot_secure_form_id' ) ) {
			return '';
		}

		$form_id = strtolower(
			trim(
				(string) nvx_hubspot_secure_form_id()
			)
		);

		if ( '' === $form_id ) {
			return '';
		}

		$is_test = isset( $fields['nvx_is_test_lead'] )
			&& 'true' === strtolower(
				(string) $fields['nvx_is_test_lead']
			);

		$test_run_id = isset( $fields['nvx_test_run_id'] )
			? nvx_lead_captured_limit_string(
				sanitize_text_field(
					(string) $fields['nvx_test_run_id']
				),
				128
			)
			: '';

		if (
			$is_test
			&& 1 !== preg_match(
				'/^staging2-[A-Za-z0-9._:-]{1,110}$/D',
				$test_run_id
			)
		) {
			return '';
		}

		if ( ! $is_test && '' !== $test_run_id ) {
			return '';
		}

		$marketing_consent =
			function_exists( 'nvx_marketing_consent_granted' )
			&& true === (bool) nvx_marketing_consent_granted();

		$email = isset( $fields['email'] )
			? sanitize_email(
				(string) $fields['email']
			)
			: '';

		$email_hash = null;

		if ( '' !== $email && is_email( $email ) ) {
			$email_hash = hash(
				'sha256',
				strtolower( trim( $email ) )
			);
		}

		unset( $email );

		$ids = nvx_lead_captured_hubspot_ids( $response );

		$first_attribution = $marketing_consent
			? nvx_lead_captured_attribution(
				$fields,
				'nvx_first_'
			)
			: array();

		$conversion_attribution = $marketing_consent
			? nvx_lead_captured_attribution(
				$fields,
				'nvx_conversion_'
			)
			: array();

		if ( $marketing_consent ) {
			foreach (
				nvx_lead_captured_meta_identity( true )
				as $key => $value
			) {
				$conversion_attribution[ $key ] = $value;
			}
		}

		$relay_payload = array(
			'nvx_lead_id'           => $lead_id,
			'form_id'               => $form_id,
			'hubspot_contact_id'     =>
				'' !== $ids['contact_id']
					? $ids['contact_id']
					: null,
			'hubspot_submission_id'  =>
				'' !== $ids['submission_id']
					? $ids['submission_id']
					: null,
			'email_hash'             => $email_hash,
			'nvx_is_test_lead'       => $is_test,
			'nvx_test_run_id'        =>
				$is_test ? $test_run_id : null,
			'marketing_consent'      => $marketing_consent,
			'first_attribution'      => $first_attribution,
			'conversion_attribution' => $conversion_attribution,
		);

		$body = wp_json_encode(
			$relay_payload,
			JSON_UNESCAPED_SLASHES
		);

		if (
			! is_string( $body )
			|| ''
			=== $body
			|| strlen( $body ) > NVX_LEAD_CAPTURED_MAX_BODY_BYTES
		) {
			return '';
		}

		return $body;
	}
}

/**
 * Mirror successful HubSpot submission.
 *
 * @param mixed $response HTTP response.
 * @param mixed $parsed_args HTTP args.
 * @param mixed $url URL.
 * @return mixed
 */
if ( ! function_exists( 'nvx_lead_captured_on_http_response' ) ) {
	function nvx_lead_captured_on_http_response(
		mixed $response,
		mixed $parsed_args,
		mixed $url
	): mixed {
		$url = is_string( $url ) ? $url : '';

		if (
			hash_equals( nvx_lead_captured_endpoint(), $url )
			|| hash_equals(
				nvx_lead_captured_bootstrap_endpoint(),
				$url
			)
		) {
			return $response;
		}

		if (
			! function_exists( 'nvx_hubspot_secure_submit_url' )
		) {
			return $response;
		}

		$hubspot_url = (string) nvx_hubspot_secure_submit_url();

		if (
			'' === $hubspot_url
			|| ! hash_equals( $hubspot_url, $url )
		) {
			return $response;
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = absint(
			wp_remote_retrieve_response_code( $response )
		);

		if ( $status < 200 || $status >= 300 ) {
			return $response;
		}

		$parsed_args = is_array( $parsed_args )
			? $parsed_args
			: array();

		/*
		 * Critical ordering:
		 * Build the mirror BEFORE bootstrap or any downstream request.
		 */
		$relay_body = nvx_lead_captured_build_relay_body(
			$response,
			$parsed_args
		);

		if ( '' === $relay_body ) {
			return $response;
		}

		if ( ! function_exists( 'nvx_supabase_relay_dispatch' ) ) {
			error_log(
				'[NUVANX] lead-captured relay failure: persistent outbox owner unavailable.'
			);

			return $response;
		}

		try {
			nvx_supabase_relay_dispatch(
				'lead_captured',
				$relay_body
			);
		} catch ( Throwable $error ) {
			unset( $error );

			error_log(
				'[NUVANX] lead-captured relay failure: unexpected outbox exception.'
			);
		}

		return $response;
	}
}

if ( function_exists( 'add_filter' ) ) {
	add_filter(
		'http_response',
		'nvx_lead_captured_on_http_response',
		10,
		3
	);
}

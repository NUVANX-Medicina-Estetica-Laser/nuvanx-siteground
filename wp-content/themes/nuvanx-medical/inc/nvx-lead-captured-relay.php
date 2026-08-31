<?php
/**
 * Canonical lead-captured relay.
 *
 * Observes only successful authenticated HubSpot submissions and mirrors
 * first-party lineage to Supabase. HubSpot remains authoritative for the
 * patient response; relay failures never alter an accepted HubSpot submission.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Canonical Supabase capture ledger URL. */
function nvx_lead_captured_endpoint(): string {
	return 'https://ssvvuuysgxyqvmovrlvk.supabase.co/functions/v1/lead-captured';
}

/** Canonical one-purpose runtime bootstrap URL. */
function nvx_lead_captured_bootstrap_endpoint(): string {
	return 'https://ssvvuuysgxyqvmovrlvk.supabase.co/functions/v1/runtime-bootstrap';
}

/** Resolve the existing server-only HubSpot private-app token. */
function nvx_lead_captured_hubspot_token(): string {
	if ( ! defined( 'NVX_HUBSPOT_ACCESS_TOKEN' ) ) {
		return '';
	}
	return trim( (string) NVX_HUBSPOT_ACCESS_TOKEN );
}

/**
 * Derive a dedicated HMAC key from the HubSpot token for capture signing.
 *
 * @param string $token HubSpot access token.
 * @return string Derived HMAC key as lowercase hexadecimal.
 */
function nvx_lead_captured_derive_hmac_key( string $token ): string {
	$context = 'nuvanx-lead-capture-hmac-key-v1';
	$info    = hash_hmac( 'sha256', $context, $token, true );
	return bin2hex( $info );
}

/**
 * Bootstrap the already-validated HubSpot credential into Supabase Vault.
 *
 * The token is sent only as an Authorization header to the pinned bootstrap
 * endpoint. It is never written to WordPress storage, payloads or logs.
 */
function nvx_lead_captured_bootstrap_runtime( string $token, bool $force = false ): bool {
	$transient = 'nvx_runtime_bootstrap_ok_v1';
	if ( ! $force && '1' === (string) get_transient( $transient ) ) {
		return true;
	}
	if ( $force ) {
		delete_transient( $transient );
	}

	$response = wp_remote_post(
		nvx_lead_captured_bootstrap_endpoint(),
		array(
			'timeout'     => 5,
			'redirection' => 0,
			'blocking'    => true,
			'headers'     => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body'        => '{}',
		)
	);

	if ( is_wp_error( $response ) ) {
		error_log(
			sprintf(
				'[NUVANX] runtime bootstrap transport failure; wp_error_code=%s.',
				sanitize_key( (string) $response->get_error_code() )
			)
		);
		return false;
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	if ( $status < 200 || $status >= 300 ) {
		error_log( sprintf( '[NUVANX] runtime bootstrap HTTP failure; status=%d.', $status ) );
		return false;
	}

	set_transient( $transient, '1', HOUR_IN_SECONDS );
	return true;
}

/**
 * Convert HubSpot fields to a simple name => value map.
 *
 * @param array $payload HubSpot request payload.
 * @return array<string, string>
 */
function nvx_lead_captured_field_map( array $payload ): array {
	$mapped = array();
	$fields = isset( $payload['fields'] ) && is_array( $payload['fields'] ) ? $payload['fields'] : array();
	foreach ( $fields as $field ) {
		if ( ! is_array( $field ) || ! isset( $field['name'] ) ) {
			continue;
		}
		$name = (string) $field['name'];
		if ( '' === $name ) {
			continue;
		}
		$value           = isset( $field['value'] ) ? (string) $field['value'] : '';
		$mapped[ $name ] = trim( $value );
	}
	return $mapped;
}

/**
 * Read consented Meta browser identity from the original first-party request.
 *
 * Values are rejected, never truncated, when the complete identifier exceeds
 * 512 characters. `_fbc`/`_fbp` cookies are preferred over posted hidden
 * fields. FBP is never synthesized.
 *
 * @return array<string,string>
 */
function nvx_lead_captured_meta_identity( bool $marketing_consent ): array {
	if ( ! $marketing_consent ) {
		return array();
	}

	$out = array();
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- relay runs only inside the already-validated direct-form request.
	$fbclid = isset( $_POST['fbclid'] ) ? trim( (string) wp_unslash( $_POST['fbclid'] ) ) : '';
	if ( '' !== $fbclid && strlen( $fbclid ) <= 512 && 1 === preg_match( '/^[A-Za-z0-9._~:+-]+$/', $fbclid ) ) {
		$out['fbclid'] = $fbclid;
	}

	foreach ( array( 'fbc' => '_fbc', 'fbp' => '_fbp' ) as $key => $cookie_name ) {
		$value = '';
		if ( isset( $_COOKIE[ $cookie_name ] ) ) {
			$value = trim( (string) wp_unslash( $_COOKIE[ $cookie_name ] ) );
		} else {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- same validated request boundary as above.
			$value = isset( $_POST[ $key ] ) ? trim( (string) wp_unslash( $_POST[ $key ] ) ) : '';
		}
		if ( '' !== $value
			&& strlen( $value ) <= 512
			&& 1 === preg_match( '/^fb\.1\.\d{10,16}\.[A-Za-z0-9._~:+-]+$/', $value ) ) {
			$out[ $key ] = $value;
		}
	}

	return $out;
}

/**
 * Build a non-clinical attribution snapshot from already-consent-filtered fields.
 *
 * @param array<string, string> $fields HubSpot field map.
 * @param string                $prefix nvx_first_ or nvx_conversion_.
 * @return array<string, string>
 */
function nvx_lead_captured_attribution( array $fields, string $prefix ): array {
	$property_map = array(
		'source'      => $prefix . 'source',
		'medium'      => $prefix . 'medium',
		'campaign_id' => $prefix . 'campaign_id',
		'landing_url' => $prefix . 'landing_url',
		'timestamp'   => $prefix . 'timestamp',
		'channel'     => $prefix . 'channel',
	);
	if ( 'nvx_first_' === $prefix ) {
		$property_map['referrer_domain'] = 'nvx_first_referrer_domain';
	}

	$out = array();
	foreach ( $property_map as $key => $property ) {
		if ( isset( $fields[ $property ] ) && '' !== $fields[ $property ] ) {
			$out[ $key ] = $fields[ $property ];
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
			if ( isset( $fields[ $property ] ) && '' !== $fields[ $property ] ) {
				$out[ $key ] = $fields[ $property ];
			}
		}
	}

	return $out;
}

/**
 * Extract optional IDs returned by HubSpot without depending on their presence.
 * No response body fragment is logged because it may contain personal data.
 *
 * @param mixed $response WordPress HTTP response.
 * @return array{contact_id:string,submission_id:string}
 */
function nvx_lead_captured_hubspot_ids( $response ): array {
	$result = array( 'contact_id' => '', 'submission_id' => '' );
	if ( is_wp_error( $response ) ) {
		return $result;
	}
	$body    = (string) wp_remote_retrieve_body( $response );
	$decoded = json_decode( $body, true );
	if ( ! is_array( $decoded ) ) {
		$status     = (int) wp_remote_retrieve_response_code( $response );
		$json_error = function_exists( 'json_last_error' ) ? (int) json_last_error() : -1;
		error_log(
			sprintf(
				'[NUVANX] lead-captured relay: HubSpot response IDs unavailable; status=%d json_error=%d.',
				$status,
				$json_error
			)
		);
		return $result;
	}
	foreach ( array( 'contactId', 'contact_id' ) as $key ) {
		if ( isset( $decoded[ $key ] ) && preg_match( '/^[1-9][0-9]{0,18}$/', (string) $decoded[ $key ] ) ) {
			$result['contact_id'] = (string) $decoded[ $key ];
			break;
		}
	}
	foreach ( array( 'submissionId', 'submission_id', 'conversionId', 'conversion_id' ) as $key ) {
		if ( isset( $decoded[ $key ] ) ) {
			$value = trim( (string) $decoded[ $key ] );
			if ( '' !== $value && strlen( $value ) <= 180 ) {
				$result['submission_id'] = $value;
				break;
			}
		}
	}
	return $result;
}

/** Build one signed capture request. */
function nvx_lead_captured_post_signed( string $body, string $token ) {
	$timestamp = (string) time();
	$hmac_key  = nvx_lead_captured_derive_hmac_key( $token );
	$signature = hash_hmac( 'sha256', $timestamp . '.' . $body, $hmac_key );
	return wp_remote_post(
		nvx_lead_captured_endpoint(),
		array(
			'timeout'     => 5,
			'redirection' => 0,
			'blocking'    => true,
			'headers'     => array(
				'Content-Type'    => 'application/json',
				'x-nvx-timestamp' => $timestamp,
				'x-nvx-signature' => $signature,
			),
			'body'        => $body,
		)
	);
}

/** Log only bounded transport/status metadata for a failed capture. */
function nvx_lead_captured_log_relay_failure( $relay ): void {
	if ( is_wp_error( $relay ) ) {
		error_log(
			sprintf(
				'[NUVANX] lead-captured relay transport failure; wp_error_code=%s.',
				sanitize_key( (string) $relay->get_error_code() )
			)
		);
		return;
	}
	$status = (int) wp_remote_retrieve_response_code( $relay );
	if ( $status < 200 || $status >= 300 ) {
		error_log( sprintf( '[NUVANX] lead-captured relay HTTP failure; status=%d.', $status ) );
	}
}

/**
 * Mirror one successful secure HubSpot submission into the canonical capture ledger.
 *
 * @param mixed  $response HTTP response.
 * @param array  $parsed_args Parsed HTTP args.
 * @param string $url Requested URL.
 * @return mixed
 */
function nvx_lead_captured_on_http_response( $response, array $parsed_args, string $url ): mixed {
	if ( $url === nvx_lead_captured_endpoint() || $url === nvx_lead_captured_bootstrap_endpoint() ) {
		return $response;
	}
	if ( ! function_exists( 'nvx_hubspot_secure_submit_url' ) || nvx_hubspot_secure_submit_url() !== $url ) {
		return $response;
	}
	if ( is_wp_error( $response ) ) {
		return $response;
	}
	$status = (int) wp_remote_retrieve_response_code( $response );
	if ( $status < 200 || $status >= 300 ) {
		return $response;
	}

	$token = nvx_lead_captured_hubspot_token();
	if ( '' === $token ) {
		error_log( '[NUVANX] lead-captured relay skipped: existing HubSpot server credential missing.' );
		return $response;
	}
	if ( ! nvx_lead_captured_bootstrap_runtime( $token ) ) {
		return $response;
	}

	$raw_payload = isset( $parsed_args['body'] ) ? $parsed_args['body'] : '';
	$payload     = is_string( $raw_payload ) ? json_decode( $raw_payload, true ) : (array) $raw_payload;
	if ( ! is_array( $payload ) ) {
		error_log( '[NUVANX] lead-captured relay skipped: authenticated HubSpot request payload is not decodable JSON.' );
		return $response;
	}
	$fields  = nvx_lead_captured_field_map( $payload );
	$lead_id = isset( $fields['nvx_lead_id'] ) ? strtolower( $fields['nvx_lead_id'] ) : '';
	if ( ! function_exists( 'nvx_hubspot_secure_is_uuid_v4' ) || ! nvx_hubspot_secure_is_uuid_v4( $lead_id ) ) {
		error_log( '[NUVANX] lead-captured relay skipped: valid nvx_lead_id missing.' );
		return $response;
	}

	$is_test           = isset( $fields['nvx_is_test_lead'] ) && 'true' === strtolower( $fields['nvx_is_test_lead'] );
	$test_run_id       = isset( $fields['nvx_test_run_id'] ) ? $fields['nvx_test_run_id'] : '';
	$marketing_consent = function_exists( 'nvx_marketing_consent_granted' ) && nvx_marketing_consent_granted();
	$email             = isset( $fields['email'] ) ? strtolower( trim( $fields['email'] ) ) : '';
	$email_hash        = '' !== $email ? hash( 'sha256', $email ) : null;
	unset( $email );
	$ids = nvx_lead_captured_hubspot_ids( $response );

	$first_attribution      = $marketing_consent ? nvx_lead_captured_attribution( $fields, 'nvx_first_' ) : array();
	$conversion_attribution = $marketing_consent ? nvx_lead_captured_attribution( $fields, 'nvx_conversion_' ) : array();
	if ( $marketing_consent ) {
		foreach ( nvx_lead_captured_meta_identity( true ) as $key => $value ) {
			$conversion_attribution[ $key ] = $value;
		}
	}

	$relay_payload = array(
		'nvx_lead_id'           => $lead_id,
		'form_id'               => nvx_hubspot_secure_form_id(),
		'hubspot_contact_id'     => '' !== $ids['contact_id'] ? $ids['contact_id'] : null,
		'hubspot_submission_id'  => '' !== $ids['submission_id'] ? $ids['submission_id'] : null,
		'email_hash'             => $email_hash,
		'nvx_is_test_lead'       => $is_test,
		'nvx_test_run_id'        => '' !== $test_run_id ? $test_run_id : null,
		'marketing_consent'      => $marketing_consent,
		'first_attribution'      => $first_attribution,
		'conversion_attribution' => $conversion_attribution,
	);
	$relay_body = wp_json_encode( $relay_payload );
	if ( false === $relay_body ) {
		error_log( '[NUVANX] lead-captured relay skipped: canonical payload encoding failed.' );
		return $response;
	}

	$relay = nvx_lead_captured_post_signed( $relay_body, $token );
	if ( ! is_wp_error( $relay ) ) {
		$relay_status = (int) wp_remote_retrieve_response_code( $relay );
		if ( 401 === $relay_status || 503 === $relay_status ) {
			if ( nvx_lead_captured_bootstrap_runtime( $token, true ) ) {
				$relay = nvx_lead_captured_post_signed( $relay_body, $token );
			}
		}
	}

	if ( function_exists( 'nvx_supabase_relay_classify' ) ) {
		$class = nvx_supabase_relay_classify( $relay );
		nvx_supabase_relay_log( 'lead_captured', $class['outcome'], $class['status'], $class['reason'] );
		if ( $class['retryable'] && function_exists( 'nvx_supabase_relay_queue_enqueue' ) ) {
			nvx_supabase_relay_queue_enqueue( 'lead_captured', $relay_body );
		}
	} else {
		nvx_lead_captured_log_relay_failure( $relay );
	}

	return $response;
}
add_filter( 'http_response', 'nvx_lead_captured_on_http_response', 10, 3 );

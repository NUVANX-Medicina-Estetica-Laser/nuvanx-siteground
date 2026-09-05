<?php
/**
 * Runtime wiring for Attribution Contract v2.
 *
 * - Applies the browser attribution contract to HubSpot.
 * - Mirrors successful consented Google-click attribution to Supabase.
 * - Keeps nvx_lead_id and submission_id deterministic and distinct.
 *
 * @package nuvanx-medical
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'NVX_ATTRIBUTION_COLLECTOR_MAX_BODY_BYTES' ) ) {
	define( 'NVX_ATTRIBUTION_COLLECTOR_MAX_BODY_BYTES', 8192 );
}

/**
 * Load HubSpot attribution synchronizer.
 */
if ( ! function_exists( 'nvx_attribution_enqueue_hubspot_sync' ) ) {
	function nvx_attribution_enqueue_hubspot_sync(): void {
		if ( is_admin() ) {
			return;
		}

		wp_enqueue_script(
			'nvx-hubspot-attribution-sync',
			get_template_directory_uri()
				. '/assets/js/nvx-hubspot-attribution-sync.js',
			array( 'nvx-attribution-contract' ),
			nvx_asset_version(
				'assets/js/nvx-hubspot-attribution-sync.js'
			),
			array(
				'in_footer' => false,
				'strategy'  => 'defer',
			)
		);

		if (
			function_exists(
				'nvx_hubspot_secure_marketing_fields'
			)
		) {
			$marketing_fields = wp_json_encode(
				array_values(
					nvx_hubspot_secure_marketing_fields()
				)
			);

			if ( is_string( $marketing_fields ) ) {
				wp_add_inline_script(
					'nvx-hubspot-attribution-sync',
					'window.nvxAttributionMarketingFields='
						. $marketing_fields
						. ';',
					'before'
				);
			}
		}
	}
}

add_action(
	'wp_enqueue_scripts',
	'nvx_attribution_enqueue_hubspot_sync',
	9
);

/**
 * Validate UUID v4.
 */
if ( ! function_exists( 'nvx_attribution_is_uuid_v4' ) ) {
	function nvx_attribution_is_uuid_v4(
		string $value
	): bool {
		return 1 === preg_match(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD',
			$value
		);
	}
}

/**
 * Deterministic collector submission ID derived from nvx_lead_id.
 */
if ( ! function_exists( 'nvx_attribution_submission_id_from_lead' ) ) {
	function nvx_attribution_submission_id_from_lead(
		string $lead_id
	): string {
		$lead_id = strtolower( trim( $lead_id ) );

		if ( ! nvx_attribution_is_uuid_v4( $lead_id ) ) {
			return '';
		}

		$digest = hash(
			'sha256',
			'nuvanx-google-click-submission-id-v1|'
				. $lead_id,
			true
		);

		$digest[6] = chr(
			( ord( $digest[6] ) & 0x0f ) | 0x40
		);

		$digest[8] = chr(
			( ord( $digest[8] ) & 0x3f ) | 0x80
		);

		$hex = bin2hex(
			substr( $digest, 0, 16 )
		);

		return substr( $hex, 0, 8 )
			. '-'
			. substr( $hex, 8, 4 )
			. '-'
			. substr( $hex, 12, 4 )
			. '-'
			. substr( $hex, 16, 4 )
			. '-'
			. substr( $hex, 20, 12 );
	}
}

/**
 * Canonical collector endpoint.
 */
if ( ! function_exists( 'nvx_attribution_collector_canonical_endpoint' ) ) {
	function nvx_attribution_collector_canonical_endpoint(): string {
		return 'https://ssvvuuysgxyqvmovrlvk.supabase.co/functions/v1/google-click-attribution';
	}
}

/**
 * Resolve collector endpoint fail-closed.
 */
if ( ! function_exists( 'nvx_attribution_collector_endpoint' ) ) {
	function nvx_attribution_collector_endpoint(): string {
		$canonical =
			nvx_attribution_collector_canonical_endpoint();

		$value = defined(
			'NVX_ATTRIBUTION_COLLECTOR_ENDPOINT'
		)
			? trim(
				(string) NVX_ATTRIBUTION_COLLECTOR_ENDPOINT
			)
			: $canonical;

		return hash_equals(
			$canonical,
			$value
		)
			? $canonical
			: '';
	}
}

/**
 * Exact hosts supported by the current Edge Function.
 *
 * @return string[]
 */
if ( ! function_exists( 'nvx_attribution_collector_allowed_hosts' ) ) {
	function nvx_attribution_collector_allowed_hosts(): array {
		return array(
			'nuvanx.com',
			'www.nuvanx.com',
			'staging2.nuvanx.com',
		);
	}
}

/**
 * Resolve collector Origin.
 */
if ( ! function_exists( 'nvx_attribution_collector_origin' ) ) {
	function nvx_attribution_collector_origin(): string {
		$host = strtolower(
			(string) wp_parse_url(
				get_site_url(),
				PHP_URL_HOST
			)
		);

		if (
			! in_array(
				$host,
				nvx_attribution_collector_allowed_hosts(),
				true
			)
		) {
			nvx_attribution_log_direct_relay(
				'FAILURE',
				0,
				'origin_not_allowed'
			);

			return '';
		}

		return 'https://' . $host;
	}
}

/**
 * Normalize first-party landing URL exactly like downstream.
 */
if ( ! function_exists( 'nvx_attribution_clean_landing_url' ) ) {
	function nvx_attribution_clean_landing_url(
		string $value
	): string {
		$value = sanitize_url( $value );

		if ( '' === $value ) {
			return '';
		}

		$scheme = strtolower(
			(string) wp_parse_url(
				$value,
				PHP_URL_SCHEME
			)
		);

		$host = strtolower(
			(string) wp_parse_url(
				$value,
				PHP_URL_HOST
			)
		);

		if (
			'https' !== $scheme
			|| ! in_array(
				$host,
				nvx_attribution_collector_allowed_hosts(),
				true
			)
		) {
			return '';
		}

		$path = (string) wp_parse_url(
			$value,
			PHP_URL_PATH
		);

		if ( '' === $path ) {
			$path = '/';
		}

		$normalized = 'https://'
			. $host
			. $path;

		return substr(
			$normalized,
			0,
			1000
		);
	}
}

/**
 * SHA-256 email join key.
 */
if ( ! function_exists( 'nvx_attribution_email_hash' ) ) {
	function nvx_attribution_email_hash(
		string $email
	): string {
		$email = sanitize_email(
			strtolower(
				trim( $email )
			)
		);

		if (
			'' === $email
			|| ! is_email( $email )
		) {
			return '';
		}

		return hash( 'sha256', $email );
	}
}

/**
 * Normalize one Google click ID.
 */
if ( ! function_exists( 'nvx_attribution_clean_click_id' ) ) {
	function nvx_attribution_clean_click_id(
		mixed $value,
		int $max_length = 512
	): string {
		if (
			! is_scalar( $value )
			|| null === $value
		) {
			return '';
		}

		$value = trim(
			sanitize_text_field(
				(string) $value
			)
		);

		if (
			''
			=== $value
			|| strlen( $value ) > $max_length
		) {
			return '';
		}

		if (
			1 !== preg_match(
				'/^[A-Za-z0-9._~:+-]+$/D',
				$value
			)
		) {
			return '';
		}

		return $value;
	}
}

/**
 * Convert normalized HubSpot fields to a map.
 *
 * @param array<int,mixed> $fields HubSpot fields.
 * @return array<string,string>
 */
if ( ! function_exists( 'nvx_attribution_hubspot_field_map' ) ) {
	function nvx_attribution_hubspot_field_map(
		array $fields
	): array {
		$output = array();

		foreach ( $fields as $field ) {
			if (
				! is_array( $field )
				|| ! isset( $field['name'] )
			) {
				continue;
			}

			$raw_name = (string) $field['name'];
			$name     = sanitize_key( $raw_name );

			if (
				''
				=== $name
				|| $name !== $raw_name
			) {
				continue;
			}

			$raw_value = $field['value'] ?? '';

			if (
				! is_scalar( $raw_value )
				&& null !== $raw_value
			) {
				continue;
			}

			$output[ $name ] = substr(
				sanitize_text_field(
					(string) $raw_value
				),
				0,
				4096
			);
		}

		return $output;
	}
}

/**
 * Verify this is the validated first-party direct form.
 */
if ( ! function_exists( 'nvx_attribution_is_direct_form_request' ) ) {
	function nvx_attribution_is_direct_form_request(): bool {
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

		return ''
			!== $nonce
			&& false !== wp_verify_nonce(
				$nonce,
				'nvx_valoracion_submit'
			);
	}
}

/**
 * Non-PII collector telemetry.
 */
if ( ! function_exists( 'nvx_attribution_log_direct_relay' ) ) {
	function nvx_attribution_log_direct_relay(
		string $outcome,
		int $status = 0,
		string $reason = ''
	): void {
		$outcome = strtoupper(
			sanitize_key( $outcome )
		);

		$allowed = array(
			'SUCCESS',
			'FAILURE',
			'HTTP_4XX',
			'HTTP_429',
			'HTTP_5XX',
			'TRANSPORT',
			'QUEUED',
			'DEAD',
		);

		if ( ! in_array( $outcome, $allowed, true ) || ! function_exists( 'nvx_observability_log' ) ) {
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

/**
 * Relay Google-click attribution after HubSpot secure success.
 *
 * @param mixed $preempt Existing preempted response.
 * @param mixed $args Original HubSpot request args.
 * @param mixed $url Original HubSpot public URL.
 * @return mixed
 */
if ( ! function_exists( 'nvx_attribution_relay_direct_form_after_hubspot' ) ) {
	function nvx_attribution_relay_direct_form_after_hubspot(
		mixed $preempt,
		mixed $args,
		mixed $url
	): mixed {
		$url = is_string( $url ) ? $url : '';

		if (
			! function_exists(
				'nvx_hubspot_secure_original_url'
			)
			|| ! hash_equals(
				(string) nvx_hubspot_secure_original_url(),
				$url
			)
		) {
			return $preempt;
		}

		if (
			false === $preempt
			|| is_wp_error( $preempt )
		) {
			return $preempt;
		}

		$hubspot_status = absint(
			wp_remote_retrieve_response_code(
				$preempt
			)
		);

		if (
			$hubspot_status < 200
			|| $hubspot_status >= 300
		) {
			return $preempt;
		}

		if ( ! nvx_attribution_is_direct_form_request() ) {
			return $preempt;
		}

		/*
		 * Consent is exclusively server-owned.
		 * Never trust nvx_marketing_consent hidden input.
		 */
		$marketing_consent =
			function_exists(
				'nvx_marketing_consent_granted'
			)
			&& true
			=== (bool) nvx_marketing_consent_granted();

		if ( ! $marketing_consent ) {
			return $preempt;
		}

		$args = is_array( $args )
			? $args
			: array();

		$body = $args['body'] ?? '';

		if ( ! is_string( $body ) ) {
			return $preempt;
		}

		$payload = json_decode(
			$body,
			true
		);

		if (
			JSON_ERROR_NONE !== json_last_error()
			|| ! is_array( $payload )
		) {
			return $preempt;
		}

		/*
		 * Reuse the secure bridge normalization contract.
		 */
		if (
			! function_exists(
				'nvx_hubspot_secure_filter_fields'
			)
			|| ! function_exists(
				'nvx_hubspot_secure_append_qa'
			)
		) {
			return $preempt;
		}

		$raw_fields = isset( $payload['fields'] )
			&& is_array( $payload['fields'] )
				? $payload['fields']
				: array();

		$secure_fields =
			nvx_hubspot_secure_filter_fields(
				$raw_fields,
				true
			);

		$secure_fields =
			nvx_hubspot_secure_append_qa(
				$secure_fields
			);

		$fields =
			nvx_attribution_hubspot_field_map(
				$secure_fields
			);

		$lead_id = strtolower(
			trim(
				(string) (
					$fields['nvx_lead_id']
					?? ''
				)
			)
		);

		if ( ! nvx_attribution_is_uuid_v4( $lead_id ) ) {
			return $preempt;
		}

		$email = isset( $fields['email'] )
			? sanitize_email(
				(string) $fields['email']
			)
			: '';

		if ( '' === $email || ! is_email( $email ) ) {
			return $preempt;
		}

		$email_hash =
			nvx_attribution_email_hash( $email );

		unset( $email );

		if ( '' === $email_hash ) {
			return $preempt;
		}

		$gclid =
			nvx_attribution_clean_click_id(
				$fields['nvx_google_click_id']
					?? ''
			);

		$gbraid =
			nvx_attribution_clean_click_id(
				$fields['nvx_google_braid']
					?? ''
			);

		$wbraid =
			nvx_attribution_clean_click_id(
				$fields['nvx_google_wbraid']
					?? ''
			);

		$gclsrc =
			nvx_attribution_clean_click_id(
				$fields['nvx_google_gclsrc']
					?? '',
				128
			);

		if (
			''
			=== $gclid
			&& ''
			=== $gbraid
			&& ''
			=== $wbraid
		) {
			return $preempt;
		}

		/*
		 * No random fallback. Idempotency requires deterministic ID.
		 */
		$submission_id =
			nvx_attribution_submission_id_from_lead(
				$lead_id
			);

		if (
			! nvx_attribution_is_uuid_v4(
				$submission_id
			)
		) {
			return $preempt;
		}

		if (
			! function_exists(
				'nvx_hubspot_secure_form_id'
			)
		) {
			return $preempt;
		}

		$form_id = strtolower(
			trim(
				(string) nvx_hubspot_secure_form_id()
			)
		);

		if ( '' === $form_id ) {
			return $preempt;
		}

		$context = isset( $payload['context'] )
			&& is_array( $payload['context'] )
				? $payload['context']
				: array();

		$landing_url = isset(
			$context['pageUri']
		)
			? nvx_attribution_clean_landing_url(
				(string) $context['pageUri']
			)
			: '';

		if ( '' === $landing_url ) {
			$landing_url =
				nvx_attribution_clean_landing_url(
					home_url(
						'/madrid/valoracion/'
					)
				);
		}

		$origin =
			nvx_attribution_collector_origin();

		if ( '' === $origin ) {
			return $preempt;
		}

		$test_run_id = isset(
			$fields['nvx_test_run_id']
		)
			? sanitize_text_field(
				(string) $fields['nvx_test_run_id']
			)
			: '';

		/*
		 * Downstream accepts exact-sha QA identity; otherwise let the Edge
		 * Function derive staging2-origin from the server-owned Origin.
		 */
		if (
			''
			!== $test_run_id
			&& 1 !== preg_match(
				'/^staging2-sha-[A-Za-z0-9._:-]{4,80}$/D',
				$test_run_id
			)
		) {
			$test_run_id = '';
		}

		$collector_payload = array(
			'submission_id'   => $submission_id,
			'nvx_lead_id'     => $lead_id,
			'email_hash'      => $email_hash,
			'gclid'           =>
				'' !== $gclid ? $gclid : null,
			'gbraid'          =>
				'' !== $gbraid ? $gbraid : null,
			'wbraid'          =>
				'' !== $wbraid ? $wbraid : null,
			'gclsrc'          =>
				'' !== $gclsrc ? $gclsrc : null,
			'form_id'         => $form_id,
			'landing_url'     =>
				'' !== $landing_url
					? $landing_url
					: null,
			'nvx_test_run_id' =>
				'' !== $test_run_id
					? $test_run_id
					: null,
		);

		$collector_body = wp_json_encode(
			$collector_payload,
			JSON_UNESCAPED_SLASHES
		);

		if (
			! is_string( $collector_body )
			|| ''
			=== $collector_body
			|| strlen( $collector_body )
			> NVX_ATTRIBUTION_COLLECTOR_MAX_BODY_BYTES
		) {
			nvx_attribution_log_direct_relay(
				'FAILURE',
				0,
				'payload_encode'
			);

			return $preempt;
		}

		/*
		 * Preserve the endpoint configuration fail-closed contract even though
		 * the outbox owns the actual network transport.
		 */
		if ( '' === nvx_attribution_collector_endpoint() ) {
			nvx_attribution_log_direct_relay(
				'FAILURE',
				0,
				'endpoint_unavailable'
			);

			return $preempt;
		}

		if (
			! function_exists(
				'nvx_supabase_relay_dispatch'
			)
		) {
			nvx_attribution_log_direct_relay(
				'FAILURE',
				0,
				'outbox_unavailable'
			);

			return $preempt;
		}

		try {
			$result =
				nvx_supabase_relay_dispatch(
					'google_click',
					$collector_body,
					array(
						'Origin' => $origin,
					)
				);

			$outcome = isset( $result['outcome'] )
				? sanitize_key(
					(string) $result['outcome']
				)
				: 'FAILURE';

			$status = isset( $result['status'] )
				? absint( $result['status'] )
				: 0;

			nvx_attribution_log_direct_relay(
				$outcome,
				$status
			);
		} catch ( Throwable $error ) {
			unset( $error );

			nvx_attribution_log_direct_relay(
				'FAILURE',
				0,
				'outbox_exception'
			);
		}

		return $preempt;
	}
}

if ( function_exists( 'add_filter' ) ) {
	add_filter(
		'pre_http_request',
		'nvx_attribution_relay_direct_form_after_hubspot',
		20,
		3
	);
}

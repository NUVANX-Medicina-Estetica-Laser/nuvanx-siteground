<?php
/**
 * First-party valoración form.
 *
 * HubSpot's embed is a marketing iframe. Complianz leaves it blank until
 * cookie consent, which is the default state for paid mobile traffic.
 * This form is first-party HTML and posts through WordPress so a clean
 * visit can convert without accepting marketing cookies. Leads are forwarded
 * to the same HubSpot form via the server-side Forms API.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Markup for the consent-independent valoración form.
 */
function nvx_valoracion_direct_form_markup(): string {
	// Disabled on valoracion landing page to avoid legacy marker conflicts.
	// The landing page uses the canonical HubSpot native form.
	if ( function_exists( 'nvx_is_valoracion_page_request' ) && nvx_is_valoracion_page_request() ) {
		return '';
	}

	$privacy_url = esc_url( home_url( '/politica-privacidad/' ) );
	$action      = esc_url( home_url( '/madrid/valoracion/' ) );
	$nonce       = wp_nonce_field( 'nvx_valoracion_submit', 'nvx_valoracion_nonce', true, false );

	$error = isset( $_GET['valoracion'] ) && 'error' === sanitize_key( wp_unslash( (string) $_GET['valoracion'] ) );

	$html  = '<form class="nvx-valoracion-direct-form" method="post" action="' . $action . '" data-nvx-direct-form>';
	$html .= '<input type="hidden" name="nvx_valoracion_submit" value="1">';
	$html .= is_string( $nonce ) ? $nonce : '';
	$html .= '<div class="nvx-hp" aria-hidden="true"><label>' . esc_html__( 'Empresa', 'nuvanx-medical' ) . '<input type="text" name="nvx_company" tabindex="-1" autocomplete="off"></label></div>';

	if ( $error ) {
		$html .= '<p class="nvx-valoracion-direct-form__error" id="nvx-valoracion-error" role="alert" tabindex="-1">' . esc_html__( 'No se envió la solicitud. Revisa que hayas completado nombre, apellidos, teléfono, email, el motivo de consulta y la aceptación de privacidad.', 'nuvanx-medical' ) . '</p>';
	}

	$identity_fields = array(
		array(
			'id'           => 'firstname',
			'label'        => esc_html__( 'Nombre', 'nuvanx-medical' ),
			'autocomplete' => 'given-name',
			'maxlength'    => 80,
		),
		array(
			'id'           => 'lastname',
			'label'        => esc_html__( 'Apellidos', 'nuvanx-medical' ),
			'autocomplete' => 'family-name',
			'maxlength'    => 120,
		),
	);

	foreach ( $identity_fields as $field ) {
		$html .= '<p class="nvx-valoracion-direct-form__field">';
		$html .= '<label for="nvx-valoracion-' . $field['id'] . '">' . $field['label'] . '</label>';
		$html .= '<input class="hs-input" id="nvx-valoracion-' . $field['id'] . '" name="' . $field['id'] . '" type="text" autocomplete="' . $field['autocomplete'] . '" minlength="2" maxlength="' . $field['maxlength'] . '" required' . ( $error ? ' aria-invalid="true" aria-describedby="nvx-valoracion-error"' : '' ) . '>';
		$html .= '</p>';
	}

	$html .= '<p class="nvx-valoracion-direct-form__field">';
	$html .= '<label for="nvx-valoracion-phone">' . esc_html__( 'Teléfono', 'nuvanx-medical' ) . '</label>';
	$html .= '<input class="hs-input" id="nvx-valoracion-phone" name="phone" type="tel" autocomplete="tel" inputmode="tel" minlength="7" maxlength="20" required' . ( $error ? ' aria-invalid="true" aria-describedby="nvx-valoracion-error"' : '' ) . '>';
	$html .= '</p>';

	$html .= '<p class="nvx-valoracion-direct-form__field">';
	$html .= '<label for="nvx-valoracion-email">' . esc_html__( 'Email', 'nuvanx-medical' ) . '</label>';
	$html .= '<input class="hs-input" id="nvx-valoracion-email" name="email" type="email" autocomplete="email" maxlength="120" required' . ( $error ? ' aria-invalid="true" aria-describedby="nvx-valoracion-error"' : '' ) . '>';
	$html .= '</p>';

	$html .= '<p class="nvx-valoracion-direct-form__field">';
	$html .= '<label for="nvx-valoracion-message">' . esc_html__( 'Qué quieres valorar', 'nuvanx-medical' ) . '</label>';
	$html .= '<textarea class="hs-input" id="nvx-valoracion-message" name="message" rows="4" minlength="10" maxlength="2000" required' . ( $error ? ' aria-invalid="true" aria-describedby="nvx-valoracion-error"' : '' ) . '></textarea>';
	$html .= '</p>';

	$html .= '<p class="nvx-valoracion-direct-form__consent">';
	$html .= '<label for="nvx-valoracion-privacy">';
	$html .= '<input id="nvx-valoracion-privacy" name="privacy" type="checkbox" value="1" required' . ( $error ? ' aria-invalid="true" aria-describedby="nvx-valoracion-error"' : '' ) . '> ';
	$html .= sprintf(
		/* translators: %s: privacy policy link */
		esc_html__( 'Acepto la %s y el tratamiento de mis datos para gestionar esta solicitud.', 'nuvanx-medical' ),
		'<a class="nvx-text-link" href="' . $privacy_url . '">' . esc_html__( 'Política de privacidad', 'nuvanx-medical' ) . '</a>'
	);
	$html .= '</label></p>';

	// First-party lineage is always allowed; marketing attribution remains consent-gated.
	$html .= '<input type="hidden" name="nvx_lead_id" value="">';
	$html .= '<input type="hidden" name="nvx_marketing_consent" value="0">';

	foreach ( array( 'gclid', 'gbraid', 'wbraid', 'gclsrc', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term' ) as $param ) {
		$value = isset( $_GET[ $param ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $param ] ) ) : '';
		$html .= '<input type="hidden" name="' . esc_attr( $param ) . '" value="' . esc_attr( $value ) . '">';
	}

	$managed_hidden = array(
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
		'nvx_landing_url',
		'nvx_attribution_captured_at',
		'nvx_attribution_expires_at',
	);
	foreach ( $managed_hidden as $name ) {
		$html .= '<input type="hidden" name="' . esc_attr( $name ) . '" value="">';
	}

	// Defer synchronization until the deferred attribution runtime has executed.
	$html .= '<script>(function(){function setv(form,name,value){var el=form.querySelector("[name=\""+name+"\"]");if(el)el.value=value==null?"":String(value)}function sync(){try{var form=document.querySelector("[data-nvx-direct-form]");if(!form)return;var c=window.NUVANXAttributionContract;var consent=typeof window.wp_has_consent==="function"&&window.wp_has_consent("marketing")===true;setv(form,"nvx_marketing_consent",consent?"1":"0");if(c&&typeof c.getLeadId==="function")setv(form,"nvx_lead_id",c.getLeadId()||"");if(!consent||!c)return;var first=typeof c.getFirstTouch==="function"?(c.getFirstTouch()||{}):{};var conv=typeof c.getConversionTouch==="function"?(c.getConversionTouch()||first):first;setv(form,"utm_source",conv.source||"");setv(form,"utm_medium",conv.medium||"");setv(form,"utm_campaign",conv.campaign_id||"");setv(form,"utm_content",conv.utm_content||"");setv(form,"utm_term",conv.utm_term||"");setv(form,"gclid",conv.gclid||first.gclid||"");setv(form,"gbraid",conv.gbraid||first.gbraid||"");setv(form,"wbraid",conv.wbraid||first.wbraid||"");setv(form,"gclsrc",conv.gclsrc||first.gclsrc||"");setv(form,"nvx_first_source",first.source||"");setv(form,"nvx_first_medium",first.medium||"");setv(form,"nvx_first_campaign_id",first.campaign_id||"");setv(form,"nvx_first_referrer_domain",first.referrer_domain||"");setv(form,"nvx_first_landing_url",first.landing_url||"");setv(form,"nvx_first_timestamp",first.timestamp||"");setv(form,"nvx_first_channel",first.channel||"");setv(form,"nvx_conversion_channel",conv.channel||"");setv(form,"nvx_conversion_source",conv.source||"");setv(form,"nvx_conversion_medium",conv.medium||"");setv(form,"nvx_conversion_campaign_id",conv.campaign_id||"");setv(form,"nvx_conversion_landing_url",conv.landing_url||"");setv(form,"nvx_conversion_timestamp",conv.timestamp||"");setv(form,"nvx_landing_url",conv.landing_url||first.landing_url||"");setv(form,"nvx_attribution_captured_at",first.timestamp||"");if(first.expires_at){var exp=Number(first.expires_at);setv(form,"nvx_attribution_expires_at",Number.isFinite(exp)?new Date(exp).toISOString():first.expires_at)}}catch(e){}}if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",sync,{once:true});else sync();document.addEventListener("wp_listen_for_consent_change",sync);document.addEventListener("wp_consent_type_defined",sync)})();</script>';

	$html .= '<button type="submit" class="nvx-brand-btn nvx-btn--primary nvx-valoracion-direct-form__submit">' . esc_html__( 'Solicitar valoración médica', 'nuvanx-medical' ) . '</button>';
	$html .= '</form>';

	return $html;
}

/**
 * Character length for first-party name fields.
 */
function nvx_valoracion_name_length( string $value ): int {
	if ( function_exists( 'mb_strlen' ) ) {
		return (int) mb_strlen( $value, 'UTF-8' );
	}
	if ( function_exists( 'iconv_strlen' ) ) {
		$iconv_length = @iconv_strlen( $value, 'UTF-8' );
		if ( false !== $iconv_length ) {
			return (int) $iconv_length;
		}
	}
	$utf8_count = preg_match_all( '/./us', $value );
	return false === $utf8_count ? 0 : (int) $utf8_count;
}

/**
 * Validate a canonical UUID v4.
 */
function nvx_valoracion_is_uuid_v4( string $value ): bool {
	return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value );
}

/**
 * Resolve the logical lead lineage id for this submission.
 *
 * The browser contract owns the session-scoped UUID. A no-JS request receives
 * a fresh server UUID for that submission; no site-global transient is used.
 */
function nvx_valoracion_lead_id(): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- called only after the direct-form nonce is validated.
	$posted = isset( $_POST['nvx_lead_id'] ) ? strtolower( trim( sanitize_text_field( wp_unslash( (string) $_POST['nvx_lead_id'] ) ) ) : '';
	if ( '' !== $posted && nvx_valoracion_is_uuid_v4( $posted ) ) {
		return $posted;
	}

	return function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : '';
}

/**
 * Append a HubSpot field when a value exists.
 *
 * @param array<int,array{objectTypeId:string,name:string,value:string}> $fields Fields array.
 */
function nvx_valoracion_append_field( array &$fields, string $name, string $value ): void {
	if ( '' === $value ) {
		return;
	}
	$fields[] = array(
		'objectTypeId' => '0-1',
		'name'         => $name,
		'value'        => $value,
	);
}

/**
 * Whether this request has explicit marketing consent for attribution data.
 *
 * Derives consent from server-verifiable Complianz state, not browser POST value.
 * The POST field is only informational; actual consent authorization comes from
 * the server-side consent management system.
 */
function nvx_valoracion_has_marketing_consent(): bool {
	// Check Complianz consent state server-side
	if ( function_exists( 'cmplz_has_consent' ) ) {
		return cmplz_has_consent( 'marketing' ) === true;
	}

	// Fallback: check if consent cookie exists and is granted
	$consent_cookie = isset( $_COOKIE['cmplz_marketing'] ) ? sanitize_text_field( wp_unslash( (string) $_COOKIE['cmplz_marketing'] ) ) : '';
	return 'allow' === $consent_cookie;
}

/**
 * Emit a bounded operational event without personal data.
 */
function nvx_valoracion_log_outcome( string $outcome, string $reason = '', int $status = 0, array $qa_context = array() ): void {
	$allowed_outcomes = array( 'FAILURE', 'SUCCESS' );
	$allowed_reasons  = array( 'nonce', 'rate_limit', 'validation', 'hubspot_transport', 'hubspot_http' );
	$outcome          = strtoupper( $outcome );
	if ( ! in_array( $outcome, $allowed_outcomes, true ) ) {
		return;
	}
	$line = 'NVX_VALORACION_' . $outcome;
	if ( 'FAILURE' === $outcome && in_array( $reason, $allowed_reasons, true ) ) {
		$line .= ' reason=' . $reason;
	}
	if ( $status > 0 ) {
		$line .= ' status=' . (int) $status;
	}
	// Add QA context without personal data
	if ( ! empty( $qa_context ) ) {
		$line .= ' form_id=' . (string) ( $qa_context['form_id'] ?? 'unknown' );
		$line .= ' pageUri_hash=' . (string) ( $qa_context['pageUri_hash'] ?? 'unknown' );
		$line .= ' consent=' . (string) ( $qa_context['consent'] ?? 'unknown' );
		$line .= ' hutk_present=' . (string) ( $qa_context['hutk_present'] ?? 'unknown' );
		$line .= ' test_id=' . (string) ( $qa_context['test_id'] ?? 'unknown' );
	}
	error_log( $line );
}

/**
 * Build a single-use thank-you redirect for a successful first-party submission.
 *
 * The raw token is returned only in the redirect URL. WordPress stores only its
 * SHA-256 hash, which is consumed once on the thank-you request.
 */
function nvx_valoracion_direct_success_redirect_url(): string {
	try {
		$token = bin2hex( random_bytes( 32 ) );
		$hash  = hash( 'sha256', $token );
		if ( ! set_transient( 'nvx_success_' . $hash, 1, 10 * MINUTE_IN_SECONDS ) ) {
			return home_url( '/gracias/' );
		}
		return add_query_arg( 'nvx_success', $token, home_url( '/gracias/' ) );
	} catch ( Throwable $error ) {
		unset( $error );
		return home_url( '/gracias/' );
	}
}

/**
 * Consume the first-party success token before rendering the thank-you page.
 * Any request carrying the token is non-cacheable so a cached page can never
 * replay a conversion. Invalid or already-consumed tokens do not emit anything.
 */
function nvx_valoracion_prepare_direct_success(): void {
	$GLOBALS['nvx_valoracion_direct_success_ready'] = false;

	$is_thank_you = function_exists( 'nvx_theme_thank_you_page_slugs' )
		? is_page( nvx_theme_thank_you_page_slugs() )
		: is_page( 'gracias' );
	if ( ! $is_thank_you || ! isset( $_GET['nvx_success'] ) ) {
		return;
	}

	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	nocache_headers();

	$token = sanitize_text_field( wp_unslash( (string) $_GET['nvx_success'] ) );
	if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $token ) ) {
		return;
	}

	$key = 'nvx_success_' . hash( 'sha256', $token );
	if ( ! get_transient( $key ) ) {
		return;
	}

	delete_transient( $key );
	$GLOBALS['nvx_valoracion_direct_success_ready'] = true;
}
add_action( 'template_redirect', 'nvx_valoracion_prepare_direct_success', 1 );

/**
 * Emit the current canonical conversion contract after a server-side form win.
 *
 * This only queues a dataLayer event; Site Kit/GTM remains the Google tag owner
 * and Consent Mode remains responsible for network-level measurement behavior.
 */
function nvx_valoracion_emit_direct_success(): void {
	if ( empty( $GLOBALS['nvx_valoracion_direct_success_ready'] ) ) {
		return;
	}
	$GLOBALS['nvx_valoracion_direct_success_ready'] = false;

	$form_id = function_exists( 'nvx_hubspot_secure_form_id' )
		? nvx_hubspot_secure_form_id()
		: ( defined( 'NVX_VALORACION_HS_FRAME_FORM_ID' ) ? (string) NVX_VALORACION_HS_FRAME_FORM_ID : '5042522a-0bc5-4381-ac3e-5aee8649b69c' );
	$event = array(
		'event'             => 'nvx_conversion_signal',
		'nvx_event_name'    => 'generate_lead',
		'page_path'         => '/gracias/',
		'event_source'      => 'nuvanx_theme',
		'form_id'           => $form_id,
		'form_context'      => 'valoracion',
		'lead_source'       => 'first_party_form',
		'form_event_source' => 'server_redirect',
	);
	$payload = wp_json_encode( $event, JSON_UNESCAPED_SLASHES );
	if ( is_string( $payload ) ) {
		wp_print_inline_script_tag( 'window.dataLayer=window.dataLayer||[];window.dataLayer.push(' . $payload . ');' );
	}
}
add_action( 'wp_head', 'nvx_valoracion_emit_direct_success', 5 );

/**
 * Handle a first-party valoración POST and forward it to HubSpot.
 */
function nvx_valoracion_maybe_handle_direct_submit(): void {
	if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) || empty( $_POST['nvx_valoracion_submit'] ) ) {
		return;
	}

	$referer = wp_get_referer();
	$back    = is_string( $referer ) && '' !== $referer ? $referer : home_url( '/madrid/valoracion/' );
	$fail    = add_query_arg( 'valoracion', 'error', $back );

	if ( ! isset( $_POST['nvx_valoracion_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['nvx_valoracion_nonce'] ) ), 'nvx_valoracion_submit' ) ) {
		nvx_valoracion_log_outcome( 'FAILURE', 'nonce', 0, array() );
		wp_safe_redirect( $fail );
		exit;
	}

	$honeypot = isset( $_POST['nvx_company'] ) ? trim( (string) wp_unslash( $_POST['nvx_company'] ) ) : '';
	if ( '' !== $honeypot ) {
		wp_safe_redirect( $fail );
		exit;
	}

	// SEC-02: Trust only REMOTE_ADDR for rate-limiting identity to prevent IP spoofing
	// until a trusted SiteGround proxies list is versioned.
	$ip = $_SERVER['REMOTE_ADDR'] ?? '0';
	$ip = sanitize_text_field( wp_unslash( (string) $ip ) );
	$rate_key = 'nvx_val_rl_' . hash( 'sha256', $ip );
	$hits     = (int) get_transient( $rate_key );
	if ( $hits >= 5 ) {
		nvx_valoracion_log_outcome( 'FAILURE', 'rate_limit', 0, array() );
		wp_safe_redirect( $fail );
		exit;
	}
	set_transient( $rate_key, $hits + 1, HOUR_IN_SECONDS );

	$firstname = isset( $_POST['firstname'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['firstname'] ) ) : '';
	$lastname  = isset( $_POST['lastname'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['lastname'] ) ) : '';
	$phone     = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['phone'] ) ) : '';
	$email     = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( (string) $_POST['email'] ) ) : '';
	$message   = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['message'] ) ) : '';
	$privacy   = ! empty( $_POST['privacy'] );

	if ( ! $privacy || ! is_email( $email ) || nvx_valoracion_name_length( $firstname ) < 2 || nvx_valoracion_name_length( $lastname ) < 2 || strlen( $phone ) < 7 || nvx_valoracion_name_length( $message ) < 10 ) {
		nvx_valoracion_log_outcome( 'FAILURE', 'validation', 0, array() );
		wp_safe_redirect( $fail );
		exit;
	}

	$fields = array(
		array( 'objectTypeId' => '0-1', 'name' => 'firstname', 'value' => $firstname ),
		array( 'objectTypeId' => '0-1', 'name' => 'lastname', 'value' => $lastname ),
		array( 'objectTypeId' => '0-1', 'name' => 'email', 'value' => $email ),
		array( 'objectTypeId' => '0-1', 'name' => 'phone', 'value' => $phone ),
		array( 'objectTypeId' => '0-1', 'name' => 'message', 'value' => $message ),
	);

	// Lineage is operational first-party data and is independent of marketing consent.
	nvx_valoracion_append_field( $fields, 'nvx_lead_id', nvx_valoracion_lead_id() );

	$marketing_consent = nvx_valoracion_has_marketing_consent();
	if ( $marketing_consent ) {
		foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term' ) as $utm_param ) {
			$value = isset( $_POST[ $utm_param ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $utm_param ] ) ) : '';
			nvx_valoracion_append_field( $fields, 'nvx_' . $utm_param, $value );
		}

		$click_id_map = array(
			'gclid'  => 'nvx_google_click_id',
			'gbraid' => 'nvx_google_braid',
			'wbraid' => 'nvx_google_wbraid',
			'gclsrc' => 'nvx_google_gclsrc',
		);
		foreach ( $click_id_map as $param => $property ) {
			$value = isset( $_POST[ $param ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $param ] ) ) : '';
			nvx_valoracion_append_field( $fields, $property, $value );
		}

		$managed_text = array(
			'nvx_first_source',
			'nvx_first_medium',
			'nvx_first_campaign_id',
			'nvx_first_referrer_domain',
			'nvx_first_timestamp',
			'nvx_first_channel',
			'nvx_conversion_channel',
			'nvx_conversion_source',
			'nvx_conversion_medium',
			'nvx_conversion_campaign_id',
			'nvx_conversion_timestamp',
			'nvx_attribution_captured_at',
			'nvx_attribution_expires_at',
		);
		foreach ( $managed_text as $property ) {
			$value = isset( $_POST[ $property ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $property ] ) ) : '';
			nvx_valoracion_append_field( $fields, $property, $value );
		}

		foreach ( array( 'nvx_first_landing_url', 'nvx_conversion_landing_url', 'nvx_landing_url' ) as $property ) {
			$value = isset( $_POST[ $property ] ) ? esc_url_raw( wp_unslash( (string) $_POST[ $property ] ) ) : '';
			nvx_valoracion_append_field( $fields, $property, $value );
		}
	}

	// Derive page URI from server-controlled request path with allowlist validation
	// Never trust browser-supplied headers or POST values for context
	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '/madrid/valoracion/';
	$request_uri = '/' . trim( $request_uri, '/' );
	
	// Normalize and validate against allowed paths
	$allowed_paths = array(
		'/madrid/valoracion',
		'/contacto',
		'/gracias',
	);
	$is_allowed = false;
	foreach ( $allowed_paths as $allowed_path ) {
		if ( 0 === strpos( $request_uri, $allowed_path ) ) {
			$is_allowed = true;
			break;
		}
	}
	
	$page_uri = $is_allowed ? home_url( $request_uri ) : home_url( '/madrid/valoracion/' );
	$page_name = is_singular() ? get_the_title() : 'Valoración médica estética en Madrid';

	$context = array(
		'pageUri'  => $page_uri,
		'pageName' => $page_name,
	);

	// Include hutk only when marketing consent is granted and cookie exists
	$hutk_present = 'no';
	if ( $marketing_consent && isset( $_COOKIE['hubspotutk'] ) ) {
		$hutk = sanitize_text_field( wp_unslash( (string) $_COOKIE['hubspotutk'] ) );
		if ( '' !== $hutk ) {
			$context['hutk'] = $hutk;
			$hutk_present = 'yes';
		}
	}

	// QA context for logging (no personal data)
	$portal = function_exists( 'nvx_hubspot_secure_portal_id' )
		? nvx_hubspot_secure_portal_id()
		: ( defined( 'NVX_VALORACION_HS_FRAME_PORTAL_ID' ) ? (string) NVX_VALORACION_HS_FRAME_PORTAL_ID : '147416356' );
	$form = function_exists( 'nvx_hubspot_secure_form_id' )
		? nvx_hubspot_secure_form_id()
		: ( defined( 'NVX_VALORACION_HS_FRAME_FORM_ID' ) ? (string) NVX_VALORACION_HS_FRAME_FORM_ID : '5042522a-0bc5-4381-ac3e-5aee8649b69c' );
	$qa_context = array(
		'portal_id' => $portal,
		'form_id' => $form,
		'pageUri_hash' => substr( md5( $page_uri ), 0, 8 ),
		'consent' => $marketing_consent ? 'granted' : 'denied',
		'hutk_present' => $hutk_present,
		'test_id' => function_exists( 'wp_generate_uuid4' ) ? substr( wp_generate_uuid4(), 0, 8 ) : 'unknown',
	);

	$result = nvx_valoracion_forward_to_hubspot( $fields, $context );
	if ( $result['ok'] ) {
		nvx_valoracion_log_outcome( 'SUCCESS', '', $result['status'], $qa_context );
		wp_safe_redirect( nvx_valoracion_direct_success_redirect_url() );
		exit;
	}

	nvx_valoracion_log_outcome( 'FAILURE', $result['reason'], $result['status'], $qa_context );
	wp_safe_redirect( $fail );
	exit;
}
add_action( 'template_redirect', 'nvx_valoracion_maybe_handle_direct_submit', 0 );

/**
 * POST a lead to the canonical HubSpot form.
 *
 * @param array<int,array{objectTypeId:string,name:string,value:string}> $fields  HubSpot fields.
 * @param array<string,string>                                           $context Submission context.
 * @return array{ok:bool,reason:string,status:int}
 */
function nvx_valoracion_forward_to_hubspot( array $fields, array $context ): array {
	$portal = function_exists( 'nvx_hubspot_secure_portal_id' )
		? nvx_hubspot_secure_portal_id()
		: ( defined( 'NVX_VALORACION_HS_FRAME_PORTAL_ID' ) ? (string) NVX_VALORACION_HS_FRAME_PORTAL_ID : '147416356' );
	$form = function_exists( 'nvx_hubspot_secure_form_id' )
		? nvx_hubspot_secure_form_id()
		: ( defined( 'NVX_VALORACION_HS_FRAME_FORM_ID' ) ? (string) NVX_VALORACION_HS_FRAME_FORM_ID : '5042522a-0bc5-4381-ac3e-5aee8649b69c' );
	$url = 'https://api.hsforms.com/submissions/v3/integration/submit/' . rawurlencode( $portal ) . '/' . rawurlencode( $form );

	$failed = static function ( string $reason, int $status ): array {
		return array( 'ok' => false, 'reason' => $reason, 'status' => $status );
	};

	$body = wp_json_encode(
		array(
			'fields'              => $fields,
			'context'             => $context,
			'legalConsentOptions' => array(
				'consent' => array(
					'consentToProcess' => true,
					'text'             => 'Al facilitar tus datos aceptas la Política de privacidad y el tratamiento de mis datos para gestionar esta solicitud.',
				),
			),
		)
	);
	if ( ! is_string( $body ) ) {
		return $failed( 'hubspot_transport', 0 );
	}

	$response = wp_remote_post(
		$url,
		array(
			'timeout' => 12,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => $body,
		)
	);

	if ( is_wp_error( $response ) ) {
		return $failed( 'hubspot_transport', 0 );
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	if ( $code >= 200 && $code < 300 ) {
		return array( 'ok' => true, 'reason' => '', 'status' => $code );
	}

	return $failed( 'hubspot_http', $code );
}
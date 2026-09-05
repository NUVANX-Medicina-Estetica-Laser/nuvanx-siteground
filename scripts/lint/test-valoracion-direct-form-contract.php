<?php
/**
 * Contract: first-party valoración form, explicit HubSpot identity, one-shot transport and bounded logs.
 *
 * @package nuvanx-medical
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

final class WP_Error {}

$GLOBALS['nvx_test_http_responses'] = array();
$GLOBALS['nvx_test_http_requests']  = array();
$GLOBALS['nvx_test_transients']     = array();
$GLOBALS['nvx_test_inline_scripts'] = array();
$GLOBALS['nvx_test_hubspot_url']    = 'https://api.hsforms.com/submissions/v3/integration/submit/12345678/11111111-1111-4111-8111-111111111111';
$GLOBALS['nvx_test_hubspot_form']   = '11111111-1111-4111-8111-111111111111';
$GLOBALS['nvx_test_observability']  = array();

function add_action( ...$args ): bool { unset( $args ); return true; }
function home_url( $path = '' ): string { return 'https://example.test' . (string) $path; }
function add_query_arg( $key, $value, $url ): string {
	$separator = str_contains( (string) $url, '?' ) ? '&' : '?';
	return (string) $url . $separator . rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
}
function esc_url( $value ): string { return (string) $value; }
function esc_html__( $value, $domain = null ): string { unset( $domain ); return (string) $value; }
function esc_attr( $value ): string { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function sanitize_key( $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ?? '' ); }
function sanitize_text_field( $value ): string { return trim( (string) $value ); }
function wp_unslash( $value ) { return $value; }
function wp_nonce_field( $action, $name, $referer, $display ): string {
	unset( $action, $referer, $display );
	return '<input type="hidden" name="' . $name . '" value="nonce">';
}
function wp_json_encode( $value, $flags = 0 ) {
	$encoded = json_encode( $value, (int) $flags );
	return is_string( $encoded ) ? $encoded : false;
}
function wp_remote_post( $url, $args ) {
	$GLOBALS['nvx_test_http_requests'][] = array( 'url' => $url, 'args' => $args );
	return array_shift( $GLOBALS['nvx_test_http_responses'] );
}
function is_wp_error( $response ): bool { return $response instanceof WP_Error; }
function wp_remote_retrieve_response_code( $response ): int { return (int) ( $response['status'] ?? 0 ); }
function set_transient( $key, $value, $expiration ): bool {
	$GLOBALS['nvx_test_transients'][ (string) $key ] = array( 'value' => $value, 'expiration' => (int) $expiration );
	return true;
}
function get_transient( $key ) { return $GLOBALS['nvx_test_transients'][ (string) $key ]['value'] ?? false; }
function delete_transient( $key ): bool {
	$key = (string) $key;
	$exists = array_key_exists( $key, $GLOBALS['nvx_test_transients'] );
	unset( $GLOBALS['nvx_test_transients'][ $key ] );
	return $exists;
}
function is_page( $page = null ): bool { unset( $page ); return true; }
function nvx_theme_thank_you_page_slugs(): array { return array( 'gracias' ); }
function nocache_headers(): void {}
function wp_print_inline_script_tag( $script ): void { $GLOBALS['nvx_test_inline_scripts'][] = (string) $script; }
function nvx_hubspot_secure_original_url(): string { return (string) ( $GLOBALS['nvx_test_hubspot_url'] ?? '' ); }
function nvx_hubspot_secure_form_id(): string { return (string) ( $GLOBALS['nvx_test_hubspot_form'] ?? '' ); }
function nvx_observability_log( string $domain, string $event, array $context = array() ): void {
	$GLOBALS['nvx_test_observability'][] = array(
		'domain'  => $domain,
		'event'   => $event,
		'context' => $context,
	);
}

$root   = dirname( __DIR__, 2 );
$module = $root . '/wp-content/themes/nuvanx-medical/inc/nvx-valoracion-direct-form.php';
$real   = realpath( $module );
if ( false === $real || ! str_starts_with( $real, $root . DIRECTORY_SEPARATOR ) ) {
	fwrite( STDERR, 'VALORACION_DIRECT_FORM_CONTRACT=FAIL confined path' . PHP_EOL );
	exit( 1 );
}
require_once $real;

$fail = static function ( string $name ): void {
	fwrite( STDERR, $name . '=FAIL' . PHP_EOL );
	exit( 1 );
};
$assert = static function ( bool $condition, string $name ) use ( $fail ): void {
	if ( ! $condition ) { $fail( $name ); }
};
$fields = static function (): array {
	return array(
		array( 'objectTypeId' => '0-1', 'name' => 'firstname', 'value' => 'QA' ),
		array( 'objectTypeId' => '0-1', 'name' => 'lastname', 'value' => 'GoogleAds' ),
		array( 'objectTypeId' => '0-1', 'name' => 'email', 'value' => 'qa@example.test' ),
		array( 'objectTypeId' => '0-1', 'name' => 'phone', 'value' => '600000000' ),
		array( 'objectTypeId' => '0-1', 'name' => 'message', 'value' => 'PRUEBA TECNICA' ),
	);
};

$markup = nvx_valoracion_direct_form_markup();
$assert( false !== strpos( $markup, 'id="nvx-valoracion-lastname"' ), 'LASTNAME_FIELD_ID' );
$assert( false !== strpos( $markup, 'name="lastname"' ), 'LASTNAME_FIELD_NAME' );
$assert( false !== strpos( $markup, 'autocomplete="family-name"' ), 'LASTNAME_AUTOCOMPLETE' );
$assert( 1 === nvx_valoracion_name_length( 'Ñ' ), 'NAME_LENGTH_TILDE' );
$assert( 1 === nvx_valoracion_name_length( '李' ), 'NAME_LENGTH_CJK' );
$assert( 6 === nvx_valoracion_name_length( 'García' ), 'NAME_LENGTH_GARCIA' );

// Missing environment identity must fail before any HTTP request is attempted.
$GLOBALS['nvx_test_hubspot_url']   = '';
$GLOBALS['nvx_test_http_requests'] = array();
$result = nvx_valoracion_forward_to_hubspot( $fields(), array() );
$assert( false === $result['ok'] && 'hubspot_config' === $result['reason'] && 0 === $result['status'], 'HUBSPOT_CONFIG_FAIL_CLOSED' );
$assert( 0 === count( $GLOBALS['nvx_test_http_requests'] ), 'HUBSPOT_CONFIG_ZERO_TRANSPORT' );
$GLOBALS['nvx_test_hubspot_url'] = 'https://api.hsforms.com/submissions/v3/integration/submit/12345678/11111111-1111-4111-8111-111111111111';

$GLOBALS['nvx_test_http_responses'] = array( array( 'status' => 201 ) );
$GLOBALS['nvx_test_http_requests']  = array();
$result = nvx_valoracion_forward_to_hubspot( $fields(), array( 'pageUri' => 'https://example.test/madrid/valoracion/' ) );
$assert( true === $result['ok'] && 201 === $result['status'] && '' === $result['reason'], 'HUBSPOT_2XX_SUCCESS' );
$assert( 1 === count( $GLOBALS['nvx_test_http_requests'] ), 'HUBSPOT_2XX_ONCE' );
$payload = json_decode( (string) ( $GLOBALS['nvx_test_http_requests'][0]['args']['body'] ?? '' ), true );
$names = array_column( is_array( $payload['fields'] ?? null ) ? $payload['fields'] : array(), 'name' );
$assert( in_array( 'lastname', $names, true ), 'HUBSPOT_LASTNAME_PAYLOAD' );
$assert( isset( $payload['legalConsentOptions']['consent']['consentToProcess'] ), 'HUBSPOT_CONSENT_PRESENT' );

foreach (
	array(
		array( array( 'status' => 422 ), 'hubspot_http', 422, 'HUBSPOT_HTTP_FAILURE' ),
		array( new WP_Error(), 'hubspot_transport', 0, 'NO_RETRY_AFTER_TRANSPORT' ),
		array( array( 'status' => 503 ), 'hubspot_http', 503, 'NO_RETRY_AFTER_5XX' ),
	) as $scenario
) {
	$GLOBALS['nvx_test_http_responses'] = array( $scenario[0], array( 'status' => 201 ) );
	$GLOBALS['nvx_test_http_requests'] = array();
	$result = nvx_valoracion_forward_to_hubspot( $fields(), array() );
	$assert( false === $result['ok'] && $scenario[1] === $result['reason'] && $scenario[2] === $result['status'], $scenario[3] );
	$assert( 1 === count( $GLOBALS['nvx_test_http_requests'] ), $scenario[3] . '_ONCE' );
}

// Behavioral proof for the first-party single-use success bridge.
$GLOBALS['nvx_test_transients'] = array();
$GLOBALS['nvx_test_inline_scripts'] = array();
$success_url = nvx_valoracion_direct_success_redirect_url();
$query = array();
parse_str( (string) parse_url( $success_url, PHP_URL_QUERY ), $query );
$token = (string) ( $query['nvx_success'] ?? '' );
$assert( 1 === preg_match( '/^[a-f0-9]{64}$/D', $token ), 'SUCCESS_TOKEN_FORMAT' );
$success_key = 'nvx_success_' . hash( 'sha256', $token );
$assert( isset( $GLOBALS['nvx_test_transients'][ $success_key ] ), 'SUCCESS_TOKEN_HASH_STORED' );
$assert( 600 === (int) $GLOBALS['nvx_test_transients'][ $success_key ]['expiration'], 'SUCCESS_TOKEN_TTL' );
$assert( false === array_key_exists( 'nvx_success_' . $token, $GLOBALS['nvx_test_transients'] ), 'SUCCESS_TOKEN_RAW_NOT_STORED' );

$_GET['nvx_success'] = $token;
nvx_valoracion_prepare_direct_success();
$assert( true === (bool) ( $GLOBALS['nvx_valoracion_direct_success_ready'] ?? false ), 'SUCCESS_TOKEN_CONSUMED' );
$assert( ! isset( $GLOBALS['nvx_test_transients'][ $success_key ] ), 'SUCCESS_TOKEN_DELETED' );
nvx_valoracion_emit_direct_success();
$assert( 1 === count( $GLOBALS['nvx_test_inline_scripts'] ), 'SUCCESS_SIGNAL_ONCE' );
$signal = $GLOBALS['nvx_test_inline_scripts'][0];
foreach ( array( '"event":"nvx_conversion_signal"', '"nvx_event_name":"generate_lead"', '"form_context":"valoracion"', '"form_event_source":"server_redirect"' ) as $index => $required ) {
	$assert( false !== strpos( $signal, $required ), 'SUCCESS_SIGNAL_FIELD_' . $index );
}
nvx_valoracion_emit_direct_success();
$assert( 1 === count( $GLOBALS['nvx_test_inline_scripts'] ), 'SUCCESS_SIGNAL_NO_DOUBLE_RENDER' );

// Logger behavior is routed through the canonical structured owner.
$GLOBALS['nvx_test_observability'] = array();
nvx_valoracion_log_outcome(
	'FAILURE',
	'hubspot_http',
	503,
	array(
		'form_id'      => '11111111-1111-4111-8111-111111111111',
		'pageUri_hash' => 'abcdef12',
		'consent'      => 'granted',
		'hutk_present' => 'yes',
		'test_id'      => '12345678',
	)
);
$assert( 1 === count( $GLOBALS['nvx_test_observability'] ), 'LOG_CANONICAL_OWNER_ONCE' );
$log = $GLOBALS['nvx_test_observability'][0];
$assert( 'valoracion' === $log['domain'] && 'failure' === $log['event'], 'LOG_CANONICAL_DOMAIN_EVENT' );
$assert( 'hubspot_http' === ( $log['context']['reason'] ?? '' ), 'LOG_REASON_BOUNDED' );
$assert( 503 === ( $log['context']['http_status'] ?? 0 ), 'LOG_HTTP_STATUS_BOUNDED' );

$source = (string) file_get_contents( $real );
$assert( false !== strpos( $source, "'hubspot_config'" ), 'HUBSPOT_CONFIG_REASON_DECLARED' );
$assert( false !== strpos( $source, 'nvx_hubspot_secure_original_url' ), 'HUBSPOT_CANONICAL_URL_OWNER' );
$assert( false === strpos( $source, "'event' => 'nvx_valoracion_success'" ), 'NO_LEGACY_SUCCESS_EVENT' );
$assert( false === strpos( $source, '$attempts' ), 'NO_RETRY_ATTEMPTS_ARRAY' );

$logger_start = strpos( $source, 'function nvx_valoracion_log_outcome' );
$logger_end = strpos( $source, '/**', (int) $logger_start + 1 );
$logger_body = false !== $logger_start && false !== $logger_end ? substr( $source, $logger_start, $logger_end - $logger_start ) : '';
$assert( false !== strpos( $logger_body, 'nvx_observability_log(' ), 'LOG_CANONICAL_SINK_PRESENT' );
$assert( false === strpos( $logger_body, 'error_log(' ), 'LOG_DIRECT_SINK_ABSENT' );
foreach ( array( '$firstname', '$lastname', '$email', '$phone', '$message', '$payload', '$hutk', '$ip', '$raw' ) as $index => $forbidden ) {
	$assert( false === strpos( $logger_body, $forbidden ), 'NO_PII_LOG_' . $index );
}

$assert( false === preg_match( '/\berror_log\s*\(/', $source ), 'VALORACION_NO_DIRECT_ERROR_LOG' );

echo 'VALORACION_DIRECT_FORM_LASTNAME=PASS' . PHP_EOL;
echo 'VALORACION_DIRECT_FORM_HUBSPOT_CONFIG=PASS fail_closed=1 zero_transport=1' . PHP_EOL;
echo 'VALORACION_DIRECT_FORM_HUBSPOT_2XX=PASS' . PHP_EOL;
echo 'VALORACION_DIRECT_FORM_FAILURE_BRANCHES=PASS hubspot_config,hubspot_transport,hubspot_http' . PHP_EOL;
echo 'VALORACION_DIRECT_FORM_NO_AMBIGUOUS_RETRY=PASS' . PHP_EOL;
echo 'VALORACION_DIRECT_FORM_LOGGING_NO_PII=PASS owner=nvx_observability' . PHP_EOL;
echo 'VALORACION_DIRECT_FORM_GA4_SUCCESS_BRIDGE=PASS single_use=1 canonical_signal=1 replay_blocked=1' . PHP_EOL;

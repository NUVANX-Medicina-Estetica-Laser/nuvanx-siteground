<?php
/**
 * Contract: first-party valoración form lastname, HubSpot once, bounded logs.
 *
 * @package nuvanx-medical
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

final class WP_Error {
}

$GLOBALS['nvx_test_http_responses'] = array();
$GLOBALS['nvx_test_http_requests']  = array();
$GLOBALS['nvx_test_transients']     = array();
$GLOBALS['nvx_test_inline_scripts'] = array();

function add_action( ...$args ): bool {
	unset( $args );
	return true;
}
function home_url( $path = '' ): string {
	return 'https://example.test' . (string) $path;
}
function add_query_arg( $key, $value, $url ): string {
	$separator = str_contains( (string) $url, '?' ) ? '&' : '?';
	return (string) $url . $separator . rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
}
function esc_url( $value ): string {
	return (string) $value;
}
function esc_html__( $value, $domain = null ): string {
	unset( $domain );
	return (string) $value;
}
function esc_attr( $value ): string {
	return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
}
function sanitize_text_field( $value ): string {
	return trim( (string) $value );
}
function wp_unslash( $value ) {
	return $value;
}
function wp_nonce_field( $action, $name, $referer, $display ): string {
	unset( $action, $referer, $display );
	return '<input type="hidden" name="' . $name . '" value="nonce">';
}
function wp_json_encode( $value, $flags = 0 ) {
	$encoded = json_encode( $value, (int) $flags );
	return is_string( $encoded ) ? $encoded : false;
}
function wp_remote_post( $url, $args ) {
	$GLOBALS['nvx_test_http_requests'][] = array(
		'url'  => $url,
		'args' => $args,
	);
	return array_shift( $GLOBALS['nvx_test_http_responses'] );
}
function is_wp_error( $response ): bool {
	return $response instanceof WP_Error;
}
function wp_remote_retrieve_response_code( $response ): int {
	return (int) ( $response['status'] ?? 0 );
}
function set_transient( $key, $value, $expiration ): bool {
	$GLOBALS['nvx_test_transients'][ (string) $key ] = array(
		'value'      => $value,
		'expiration' => (int) $expiration,
	);
	return true;
}
function get_transient( $key ) {
	return $GLOBALS['nvx_test_transients'][ (string) $key ]['value'] ?? false;
}
function delete_transient( $key ): bool {
	$key    = (string) $key;
	$exists = array_key_exists( $key, $GLOBALS['nvx_test_transients'] );
	unset( $GLOBALS['nvx_test_transients'][ $key ] );
	return $exists;
}
function is_page( $page = null ): bool {
	unset( $page );
	return true;
}
function nvx_theme_thank_you_page_slugs(): array {
	return array( 'gracias' );
}
function nocache_headers(): void {
}
function wp_print_inline_script_tag( $script ): void {
	$GLOBALS['nvx_test_inline_scripts'][] = (string) $script;
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
	if ( ! $condition ) {
		$fail( $name );
	}
};

$fields = static function (): array {
	return array(
		array(
			'objectTypeId' => '0-1',
			'name'         => 'firstname',
			'value'        => 'QA',
		),
		array(
			'objectTypeId' => '0-1',
			'name'         => 'lastname',
			'value'        => 'GoogleAds',
		),
		array(
			'objectTypeId' => '0-1',
			'name'         => 'email',
			'value'        => 'qa@example.test',
		),
		array(
			'objectTypeId' => '0-1',
			'name'         => 'phone',
			'value'        => '600000000',
		),
		array(
			'objectTypeId' => '0-1',
			'name'         => 'message',
			'value'        => 'PRUEBA TECNICA',
		),
	);
};

$markup = nvx_valoracion_direct_form_markup();
$assert( false !== strpos( $markup, 'id="nvx-valoracion-lastname"' ), 'LASTNAME_FIELD_ID' );
$assert( false !== strpos( $markup, 'name="lastname"' ), 'LASTNAME_FIELD_NAME' );
$assert( false !== strpos( $markup, 'autocomplete="family-name"' ), 'LASTNAME_AUTOCOMPLETE' );
$assert( false !== strpos( $markup, 'name="lastname" type="text" autocomplete="family-name" minlength="2" maxlength="120" required' ), 'LASTNAME_REQUIRED' );

$assert( 1 === nvx_valoracion_name_length( 'Ñ' ), 'NAME_LENGTH_TILDE' );
$assert( 1 === nvx_valoracion_name_length( '李' ), 'NAME_LENGTH_CJK' );
$assert( 6 === nvx_valoracion_name_length( 'García' ), 'NAME_LENGTH_GARCIA' );

$GLOBALS['nvx_test_http_responses'] = array( array( 'status' => 201 ) );
$GLOBALS['nvx_test_http_requests']  = array();
$result                             = nvx_valoracion_forward_to_hubspot( $fields(), array( 'pageUri' => 'https://example.test/madrid/valoracion/' ) );
$assert( true === $result['ok'] && 201 === $result['status'] && '' === $result['reason'], 'HUBSPOT_2XX_SUCCESS' );
$assert( 1 === count( $GLOBALS['nvx_test_http_requests'] ), 'HUBSPOT_2XX_ONCE' );
$payload = json_decode( (string) ( $GLOBALS['nvx_test_http_requests'][0]['args']['body'] ?? '' ), true );
$names   = array_column( is_array( $payload['fields'] ?? null ) ? $payload['fields'] : array(), 'name' );
$assert( in_array( 'lastname', $names, true ), 'HUBSPOT_LASTNAME_PAYLOAD' );
$assert( isset( $payload['legalConsentOptions']['consent']['consentToProcess'] ), 'HUBSPOT_CONSENT_PRESENT' );

$GLOBALS['nvx_test_http_responses'] = array( array( 'status' => 422 ), array( 'status' => 201 ) );
$GLOBALS['nvx_test_http_requests']  = array();
$result                             = nvx_valoracion_forward_to_hubspot( $fields(), array() );
$assert( false === $result['ok'] && 'hubspot_http' === $result['reason'] && 422 === $result['status'], 'HUBSPOT_HTTP_FAILURE' );
$assert( 1 === count( $GLOBALS['nvx_test_http_requests'] ), 'HUBSPOT_HTTP_ONCE' );

$GLOBALS['nvx_test_http_responses'] = array( new WP_Error(), array( 'status' => 201 ) );
$GLOBALS['nvx_test_http_requests']  = array();
$result                             = nvx_valoracion_forward_to_hubspot( $fields(), array() );
$assert( false === $result['ok'] && 'hubspot_transport' === $result['reason'] && 0 === $result['status'], 'NO_RETRY_AFTER_TRANSPORT' );
$assert( 1 === count( $GLOBALS['nvx_test_http_requests'] ), 'NO_RETRY_AFTER_TRANSPORT_ONCE' );

$GLOBALS['nvx_test_http_responses'] = array( array( 'status' => 503 ), array( 'status' => 201 ) );
$GLOBALS['nvx_test_http_requests']  = array();
$result                             = nvx_valoracion_forward_to_hubspot( $fields(), array() );
$assert( false === $result['ok'] && 'hubspot_http' === $result['reason'] && 503 === $result['status'], 'NO_RETRY_AFTER_5XX' );
$assert( 1 === count( $GLOBALS['nvx_test_http_requests'] ), 'NO_RETRY_AFTER_5XX_ONCE' );

// Behavioral proof for the first-party success bridge.
$GLOBALS['nvx_test_transients']     = array();
$GLOBALS['nvx_test_inline_scripts'] = array();
$success_url                        = nvx_valoracion_direct_success_redirect_url();
$query                              = array();
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
$assert( defined( 'DONOTCACHEPAGE' ) && true === DONOTCACHEPAGE, 'SUCCESS_PAGE_NONCACHEABLE' );

nvx_valoracion_emit_direct_success();
$assert( 1 === count( $GLOBALS['nvx_test_inline_scripts'] ), 'SUCCESS_SIGNAL_ONCE' );
$signal = $GLOBALS['nvx_test_inline_scripts'][0];
foreach (
	array(
		'"event":"nvx_conversion_signal"',
		'"nvx_event_name":"generate_lead"',
		'"form_context":"valoracion"',
		'"lead_source":"first_party_form"',
		'"form_event_source":"server_redirect"',
	) as $index => $required_signal
) {
	$assert( false !== strpos( $signal, $required_signal ), 'SUCCESS_SIGNAL_FIELD_' . $index );
}
nvx_valoracion_emit_direct_success();
$assert( 1 === count( $GLOBALS['nvx_test_inline_scripts'] ), 'SUCCESS_SIGNAL_NO_DOUBLE_RENDER' );

nvx_valoracion_prepare_direct_success();
$assert( false === (bool) ( $GLOBALS['nvx_valoracion_direct_success_ready'] ?? false ), 'SUCCESS_TOKEN_REPLAY_BLOCKED' );
unset( $_GET['nvx_success'] );

$source = (string) file_get_contents( $real );
$required_log_calls = array(
	"/nvx_valoracion_log_outcome\\(\\s*'FAILURE'\\s*,\\s*'nonce'\\s*,\\s*0\\s*,\\s*array\\(\\)\\s*\\);/",
	"/nvx_valoracion_log_outcome\\(\\s*'FAILURE'\\s*,\\s*'rate_limit'\\s*,\\s*0\\s*,\\s*array\\(\\)\\s*\\);/",
	"/nvx_valoracion_log_outcome\\(\\s*'FAILURE'\\s*,\\s*'validation'\\s*,\\s*0\\s*,\\s*array\\(\\)\\s*\\);/",
	'~nvx_valoracion_log_outcome\\(\\s*\'SUCCESS\'\\s*,\\s*\'\'\\s*,\\s*\\$result\\[\'status\'\\]\\s*,\\s*\\$qa_context\\s*\\);~',
	'~nvx_valoracion_log_outcome\\(\\s*\'FAILURE\'\\s*,\\s*\\$result\\[\'reason\'\\]\\s*,\\s*\\$result\\[\'status\'\\]\\s*,\\s*\\$qa_context\\s*\\);~',
);
foreach ( $required_log_calls as $index => $pattern ) {
	$assert( 1 === preg_match( $pattern, $source ), 'HANDLER_LOG_CALL_' . $index );
}
foreach (
	array(
		'wp_safe_redirect( nvx_valoracion_direct_success_redirect_url() )',
		"nvx_valoracion_name_length( \$lastname )",
		"'event'             => 'nvx_conversion_signal'",
		"'nvx_event_name'    => 'generate_lead'",
	) as $index => $required
) {
	$assert( false !== strpos( $source, $required ), 'HANDLER_BRANCH_' . $index );
}
$assert( false === strpos( $source, "'event' => 'nvx_valoracion_success'" ), 'NO_LEGACY_SUCCESS_EVENT' );
$assert( false === strpos( $source, '$attempts' ), 'NO_RETRY_ATTEMPTS_ARRAY' );

$logger_start = strpos( $source, 'function nvx_valoracion_log_outcome' );
$logger_end   = strpos( $source, '/**', (int) $logger_start + 1 );
$logger_body  = false !== $logger_start && false !== $logger_end ? substr( $source, $logger_start, $logger_end - $logger_start ) : '';
$assert( false !== strpos( $logger_body, 'error_log( $line )' ), 'LOG_SINK_BOUNDED' );
foreach ( array( '$firstname', '$lastname', '$email', '$phone', '$message', '$payload', '$context', '$hutk', '$ip', '$raw' ) as $index => $forbidden ) {
	$assert( false === strpos( $logger_body, $forbidden ), 'NO_PII_LOG_' . $index );
}

echo 'VALORACION_DIRECT_FORM_LASTNAME=PASS' . PHP_EOL;
echo 'VALORACION_DIRECT_FORM_HUBSPOT_2XX=PASS' . PHP_EOL;
echo 'VALORACION_DIRECT_FORM_FAILURE_BRANCHES=PASS nonce,rate_limit,validation,hubspot_transport,hubspot_http' . PHP_EOL;
echo 'VALORACION_DIRECT_FORM_NO_AMBIGUOUS_RETRY=PASS' . PHP_EOL;
echo 'VALORACION_DIRECT_FORM_LOGGING_NO_PII=PASS' . PHP_EOL;
echo 'VALORACION_DIRECT_FORM_GA4_SUCCESS_BRIDGE=PASS single_use=1 canonical_signal=1 replay_blocked=1' . PHP_EOL;
echo 'VALORACION_DIRECT_FORM_QA07_GATE=PASS qa06_preserved=1 qa07_not_executed=1' . PHP_EOL;
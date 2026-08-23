<?php
/**
 * NUVANX analytics context provider.
 *
 * Site Kit is the single owner of Google Tag / GTM / GA4 / Google Ads and
 * Consent Mode snippets. This module never loads GTM, emits a GTM noscript
 * iframe, or resolves Google Ads conversion-action IDs.
 *
 * The theme owns only business context consumed by GTM/dataLayer and by the
 * NUVANX conversion-events client. Keeping this context independent from the
 * GTM loader makes it available before Site Kit's container executes, including
 * when third-party scripts are delayed by the theme performance layer.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve the canonical route metadata for the current request.
 *
 * @return array<string, mixed>
 */
function nvx_gtm_context_route(): array {
	if ( ! function_exists( 'nvx_theme_request_path' ) || ! function_exists( 'nvx_catalog_json_load' ) ) {
		return array();
	}

	$path   = '/' . trim( nvx_theme_request_path(), '/' ) . '/';
	$routes = nvx_catalog_json_load( 'routes.json' );
	$route  = isset( $routes[ $path ] ) && is_array( $routes[ $path ] ) ? $routes[ $path ] : array();

	if ( isset( $route['route_alias'] ) && is_string( $route['route_alias'] ) ) {
		$alias = '/' . trim( $route['route_alias'], '/' ) . '/';
		if ( isset( $routes[ $alias ] ) && is_array( $routes[ $alias ] ) ) {
			$route = $routes[ $alias ];
		}
	}

	return $route;
}

/**
 * Resolve the canonical NUVANX analytics page type for the current request.
 */
function nvx_gtm_context_page_type(): string {
	if ( is_front_page() ) {
		return 'home';
	}

	if ( is_singular( 'post' ) ) {
		return 'blog';
	}

	if ( is_archive() || is_category() ) {
		return 'listado';
	}

	if ( ! is_page() ) {
		return 'other';
	}

	$is_valoracion = function_exists( 'nvx_theme_is_valoracion_form_page' ) && nvx_theme_is_valoracion_form_page();
	$request_path  = function_exists( 'nvx_theme_request_path' ) ? nvx_theme_request_path() : '';
	if ( $is_valoracion || false !== strpos( $request_path, '/valoracion/' ) ) {
		return 'valoracion';
	}

	if ( function_exists( 'nvx_theme_is_thank_you_page' ) && nvx_theme_is_thank_you_page() ) {
		return 'conversion';
	}

	$route        = nvx_gtm_context_route();
	$schema_group = isset( $route['schema_group'] ) && is_string( $route['schema_group'] )
		? $route['schema_group']
		: '';

	if ( 'treatments' === $schema_group ) {
		return 'tratamiento';
	}

	if ( in_array( $schema_group, array( 'clinics', 'clinic_hub' ), true ) ) {
		return 'clinica';
	}

	return 'page';
}

/**
 * Resolve non-Google business configuration consumed by nvx-conversion-events.js.
 *
 * This deliberately excludes GTM and Google Ads conversion IDs. Site Kit and
 * the GTM container own Google tag configuration; the theme only exposes the
 * canonical HubSpot form identity required by the NUVANX event classifier.
 * The secure bridge is the single source of truth for that form identity.
 *
 * @return array{env:string,forms:array{valoracion:string}}
 */
function nvx_gtm_client_context(): array {
	$valoracion_form_id = function_exists( 'nvx_hubspot_secure_form_id' )
		? nvx_hubspot_secure_form_id()
		: '';

	return array(
		'env'   => nvx_environment_is_staging2() ? 'staging2' : 'production',
		'forms' => array(
			'valoracion' => $valoracion_form_id,
		),
	);
}

/**
 * Return deterministic QA identity for the current request.
 *
 * Staging2 is the ONLY environment where is_test_lead is automatically true.
 * The client must NEVER be able to set or override these values.
 *
 * @return array{is_test_lead: bool, test_run_id: string}
 */
function nvx_attribution_qa_context(): array {
	$is_staging2 = function_exists( 'nvx_environment_is_staging2' ) && nvx_environment_is_staging2();

	if ( ! $is_staging2 ) {
		return array(
			'is_test_lead' => false,
			'test_run_id'  => '',
		);
	}

	$sha = defined( 'NVX_DEPLOY_SHA' )
		? substr( (string) NVX_DEPLOY_SHA, 0, 12 )
		: substr( sha1( get_site_url() ), 0, 12 );

	return array(
		'is_test_lead' => true,
		'test_run_id'  => 'staging2-sha-' . $sha,
	);
}

/**
 * Whether the current public request contains a canonical valoración form.
 *
 * The full valoración landing owns the form directly. Most other public pages
 * own it through the site-wide valoración modal. Contacto and post-conversion
 * pages intentionally remain form-free. If the modal module is unavailable,
 * fail open to the previous global behavior rather than dropping attribution.
 */
function nvx_attribution_browser_runtime_required(): bool {
	if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_feed() ) {
		return false;
	}

	if ( function_exists( 'nvx_theme_is_valoracion_form_page' ) && nvx_theme_is_valoracion_form_page() ) {
		return true;
	}

	if ( function_exists( 'nvx_theme_is_valoracion_landing' ) && nvx_theme_is_valoracion_landing() ) {
		return true;
	}

	if ( function_exists( 'nvx_valoracion_modal_enabled' ) ) {
		return nvx_valoracion_modal_enabled();
	}

	return true;
}

/**
 * Enqueue the attribution contract runtime before conversion events.
 * Priority 9 ensures it loads before the conversion relay at priority 10.
 */
function nvx_gtm_enqueue_attribution_contract(): void {
	if ( ! nvx_attribution_browser_runtime_required() ) {
		return;
	}

	wp_enqueue_script(
		'nvx-attribution-contract',
		get_template_directory_uri() . '/assets/js/nvx-attribution-contract.js',
		array(),
		nvx_asset_version( 'assets/js/nvx-attribution-contract.js' ),
		array(
			'in_footer' => false,
			'strategy'  => 'defer',
		)
	);
}
add_action( 'wp_enqueue_scripts', 'nvx_gtm_enqueue_attribution_contract', 9 );

/**
 * Keep the HubSpot attribution synchronizer off pages without any form surface.
 *
 * The synchronizer is registered by nvx-attribution-integration.php at the same
 * enqueue priority. A later dequeue makes the ownership explicit and avoids an
 * unresolved dependency when the contract itself was intentionally not loaded.
 */
function nvx_gtm_scope_attribution_form_assets(): void {
	if ( nvx_attribution_browser_runtime_required() ) {
		return;
	}

	wp_dequeue_script( 'nvx-hubspot-attribution-sync' );
	wp_dequeue_script( 'nvx-attribution-contract' );
}
add_action( 'wp_enqueue_scripts', 'nvx_gtm_scope_attribution_form_assets', 99 );

/**
 * Push NUVANX business context before Site Kit executes the GTM container.
 * Includes the server-owned QA context exposed to the browser runtime.
 */
function nvx_gtm_push_context(): void {
	if ( is_admin() ) {
		return;
	}

	$client_context = nvx_gtm_client_context();
	$qa_context     = nvx_attribution_qa_context();
	$json_flags     = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
	$data_layer     = wp_json_encode(
		array(
			'nvx_env'       => $client_context['env'],
			'nvx_page_type' => nvx_gtm_context_page_type(),
		),
		$json_flags
	);
	$client_env   = wp_json_encode( $client_context['env'], $json_flags );
	$client_forms = wp_json_encode( $client_context['forms'], $json_flags );
	$client_qa    = wp_json_encode(
		array(
			'is_test_lead' => (bool) $qa_context['is_test_lead'],
			'test_run_id'  => (string) $qa_context['test_run_id'],
		),
		$json_flags
	);

	if (
		! is_string( $data_layer ) || '' === $data_layer
		|| ! is_string( $client_env ) || '' === $client_env
		|| ! is_string( $client_forms ) || '' === $client_forms
		|| ! is_string( $client_qa ) || '' === $client_qa
	) {
		return;
	}

	$script = sprintf(
		'window.dataLayer=window.dataLayer||[];window.dataLayer.push(%s);window.nvxConversionEvents=window.nvxConversionEvents||{};window.nvxConversionEvents.env=%s;window.nvxConversionEvents.forms=%s;window.nvxConversionEvents.qa=Object.assign(window.nvxConversionEvents.qa||{},%s);',
		$data_layer,
		$client_env,
		$client_forms,
		$client_qa
	);

	wp_print_inline_script_tag( $script );
}
add_action( 'wp_head', 'nvx_gtm_push_context', 1 );

// Load the secure HubSpot attribution bridge (Runtime Contract v2).
require_once __DIR__ . '/nvx-hubspot-secure-attribution.php';
require_once __DIR__ . '/nvx-attribution-integration.php';

// Mirror successful secure HubSpot submissions into the canonical first-party capture ledger.
require_once __DIR__ . '/nvx-lead-captured-relay.php';

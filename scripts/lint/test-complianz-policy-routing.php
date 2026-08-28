<?php
/**
 * Behavioral contract for canonical Complianz policy routing.
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['nvx_added_filters']   = array();
$GLOBALS['nvx_removed_filters'] = array();

function add_filter( $hook, $callback, $priority = 10 ) {
	$GLOBALS['nvx_added_filters'][] = array( $hook, $callback, $priority );
	return true;
}
function remove_filter( $hook, $callback, $priority = 10 ) {
	$GLOBALS['nvx_removed_filters'][] = array( $hook, $callback, $priority );
	return true;
}
function home_url( $path = '/' ) { return 'https://nuvanx.com' . $path; }
function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
function esc_url( $value ) {
	// Deliberately mutate braces so the test detects accidental escaping of
	// Complianz's literal {url} template placeholder.
	return str_replace( array( '{', '}' ), array( '%7B', '%7D' ), $value );
}

require_once __DIR__ . '/../../wp-content/themes/nuvanx-medical/inc/nvx-complianz-policy-routing.php';

function nvx_assert_contains( string $needle, string $actual, string $case ): void {
	if ( false === strpos( $actual, $needle ) ) {
		fwrite( STDERR, "COMPLIANZ_POLICY_ROUTING=FAIL case={$case}\nACTUAL={$actual}\n" );
		exit( 1 );
	}
}

function nvx_assert_same( string $expected, string $actual, string $case ): void {
	if ( $expected !== $actual ) {
		fwrite( STDERR, "COMPLIANZ_POLICY_ROUTING=FAIL case={$case}\nEXPECTED={$expected}\nACTUAL={$actual}\n" );
		exit( 1 );
	}
}

function nvx_assert_filter_registration( array $needle, array $haystack, string $case ): void {
	foreach ( $haystack as $entry ) {
		if ( $needle === $entry ) {
			return;
		}
	}
	fwrite( STDERR, "COMPLIANZ_POLICY_ROUTING=FAIL case={$case}\n" );
	exit( 1 );
}

$privacy = nvx_rewrite_complianz_policy_links(
	'<a class="cmplz-link" href="#" data-relative_url="/politica-privacidad/">Política de privacidad</a>'
);
nvx_assert_contains( 'href="https://nuvanx.com/politica-privacidad/"', $privacy, 'translated_privacy_attr_after_href' );

$cookies = nvx_rewrite_complianz_policy_links(
	'<a data-relative_url="/politica-de-cookies-ue/" href="#" class="cmplz-link">Política de cookies</a>'
);
nvx_assert_contains( 'href="https://nuvanx.com/politica-de-cookies-ue/"', $cookies, 'translated_cookies_attr_before_href' );

$legal = nvx_rewrite_complianz_policy_links(
	'<a class="cmplz-link" href="#" data-relative_url="/aviso-legal/"><span>Aviso legal</span></a>'
);
nvx_assert_contains( 'href="https://nuvanx.com/aviso-legal/"', $legal, 'translated_legal_nested_label' );

$metadata_driven = nvx_rewrite_complianz_policy_links(
	'<a href="#" data-relative_url="/politica-privacidad/">Más información</a>'
);
nvx_assert_contains( 'href="https://nuvanx.com/politica-privacidad/"', $metadata_driven, 'relative_url_is_authoritative' );

$metadata_cookie_over_stale_privacy_label = nvx_rewrite_complianz_policy_links(
	'<a href="#" data-relative_url="/politica-de-cookies-ue/">Privacy policy</a>'
);
nvx_assert_contains(
	'href="https://nuvanx.com/politica-de-cookies-ue/"',
	$metadata_cookie_over_stale_privacy_label,
	'metadata_cookie_overrides_stale_privacy_label'
);

$metadata_privacy_over_stale_cookie_label = nvx_rewrite_complianz_policy_links(
	'<a href="#" data-relative_url="/politica-privacidad/">Política de cookies</a>'
);
nvx_assert_contains(
	'href="https://nuvanx.com/politica-privacidad/"',
	$metadata_privacy_over_stale_cookie_label,
	'metadata_privacy_overrides_stale_cookie_label'
);

$manage = nvx_rewrite_complianz_policy_links(
	'<a class="cmplz-manage-options" href="#" data-relative_url="#cmplz-manage">Gestionar opciones</a>'
);
nvx_assert_contains( 'href="#"', $manage, 'js_managed_consent_control' );

$hash_metadata_with_policy_label = nvx_rewrite_complianz_policy_links(
	'<a class="cmplz-manage-options" href="#" data-relative_url="#cmplz-manage">Política de privacidad</a>'
);
nvx_assert_contains( 'href="#"', $hash_metadata_with_policy_label, 'hash_metadata_suppresses_policy_label_fallback' );

$ordinary_hash = '<a class="local-anchor" href="#">Abrir panel local</a>';
nvx_assert_same( $ordinary_hash, nvx_rewrite_complianz_policy_links( $ordinary_hash ), 'ordinary_hash_untouched' );

$template_privacy = nvx_rewrite_complianz_policy_links(
	'<a class="cmplz-link" href="/politica-privacidad/">{title}</a>'
);
nvx_assert_contains( 'Política de privacidad', $template_privacy, 'template_title_replaced_from_href' );

$template_url = nvx_rewrite_complianz_policy_links(
	'<a class="cmplz-link" href="{url}">{title}</a>'
);
nvx_assert_contains( 'href="{url}"', $template_url, 'template_url_placeholder_preserved' );
nvx_assert_contains( 'Política de cookies', $template_url, 'template_title_fallback_preserved' );

$already_concrete = '<a href="/custom-privacy-document/" data-relative_url="/politica-privacidad/">Política de privacidad</a>';
nvx_assert_same( $already_concrete, nvx_rewrite_complianz_policy_links( $already_concrete ), 'concrete_href_not_overwritten' );

$label_only_privacy = nvx_rewrite_complianz_policy_links( '<a class="cmplz-link" href="#">Política de privacidad</a>' );
nvx_assert_contains( 'href="https://nuvanx.com/politica-privacidad/"', $label_only_privacy, 'label_only_privacy_hash' );

$label_only_cookies = nvx_rewrite_complianz_policy_links( '<a class="cmplz-link" href="#">Política de cookies</a>' );
nvx_assert_contains( 'href="https://nuvanx.com/politica-de-cookies-ue/"', $label_only_cookies, 'label_only_cookies_hash' );

$label_only_legal = nvx_rewrite_complianz_policy_links( '<a class="cmplz-link" href="#">Aviso legal</a>' );
nvx_assert_contains( 'href="https://nuvanx.com/aviso-legal/"', $label_only_legal, 'label_only_legal_hash' );

$label_only_empty_href = nvx_rewrite_complianz_policy_links( '<a class="cmplz-link" href="">Política de privacidad</a>' );
nvx_assert_contains( 'href="https://nuvanx.com/politica-privacidad/"', $label_only_empty_href, 'label_only_empty_href' );

nvx_assert_filter_registration(
	array( 'cmplz_banner_html', 'nvx_sanitize_complianz_banner_html', 20 ),
	$GLOBALS['nvx_removed_filters'],
	'legacy_banner_filter_retired'
);
nvx_assert_filter_registration(
	array( 'cmplz_template', 'nvx_sanitize_complianz_banner_html', 20 ),
	$GLOBALS['nvx_removed_filters'],
	'legacy_template_filter_retired'
);
nvx_assert_filter_registration(
	array( 'cmplz_banner_html', 'nvx_rewrite_complianz_policy_links', 20 ),
	$GLOBALS['nvx_added_filters'],
	'canonical_banner_filter_registered'
);
nvx_assert_filter_registration(
	array( 'cmplz_template', 'nvx_rewrite_complianz_policy_links', 20 ),
	$GLOBALS['nvx_added_filters'],
	'canonical_template_filter_registered'
);

fwrite( STDOUT, "COMPLIANZ_POLICY_ROUTING=PASS cases=20 privacy=canonical cookies=canonical legal=canonical metadata=authoritative hash_metadata=authoritative js_controls=preserved template_url=preserved owner=single\n" );

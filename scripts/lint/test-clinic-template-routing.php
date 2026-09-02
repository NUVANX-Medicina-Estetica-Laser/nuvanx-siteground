<?php
/**
 * P0 regression: clinic template identity must resolve exactly from clinics.json.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'NVX_HOOK_PRIO_CONTACT_MAPS' ) ) {
	define( 'NVX_HOOK_PRIO_CONTACT_MAPS', 80 );
}

$GLOBALS['nvx_test_path']      = '/';
$GLOBALS['nvx_test_hub']       = false;
$GLOBALS['nvx_test_query_var'] = array();

function add_filter( ...$args ): bool { unset( $args ); return true; }
function is_email( string $email ): bool { return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ); }
function nvx_whatsapp_url_from_phone( string $phone ): string { return 'https://wa.me/' . preg_replace( '/\D+/', '', $phone ); }
function nvx_theme_request_path(): string { return (string) $GLOBALS['nvx_test_path']; }
function nvxIsClinicsHub(): bool { return (bool) $GLOBALS['nvx_test_hub']; }
function get_theme_file_path( string $path = '' ): string { return '/theme' . $path; }
function set_query_var( string $key, $value ): void { $GLOBALS['nvx_test_query_var'][ $key ] = $value; }
function esc_html__( string $text, string $domain = '' ): string { unset( $domain ); return $text; }
function esc_html( string $text ): string { return $text; }
function nvx_lazy_map_embed_markup( string $src, string $title, string $class ): string { unset( $src, $title, $class ); return ''; }
function is_admin(): bool { return false; }
function nvx_is_contacto_page_request(): bool { return false; }

function nvx_p0_clinic_assert( bool $condition, string $name ): void {
	if ( ! $condition ) {
		fwrite( STDERR, 'CLINIC_TEMPLATE_ROUTING=FAIL invariant=' . $name . PHP_EOL );
		exit( 1 );
	}
}

$root = dirname( __DIR__, 2 );
require_once $root . '/wp-content/themes/nuvanx-medical/inc/nvx-business-config.php';

$clinics = nvx_get_clinics_config();
nvx_p0_clinic_assert( isset( $clinics['chamberi'], $clinics['goya'] ), 'CANONICAL_CLINICS_LOADED' );

$chamberi_path = (string) $clinics['chamberi']['landing_path'];
$goya_path     = (string) $clinics['goya']['landing_path'];
nvx_p0_clinic_assert( 'chamberi' === nvx_clinic_key_from_landing_path( $chamberi_path ), 'CHAMBERI_EXACT' );
nvx_p0_clinic_assert( 'goya' === nvx_clinic_key_from_landing_path( $goya_path ), 'GOYA_EXACT' );
nvx_p0_clinic_assert( 'chamberi' === nvx_clinic_key_from_landing_path( $chamberi_path . '?utm_source=test' ), 'QUERY_IGNORED' );

// Slug fragments and nested paths must not become clinic identity.
nvx_p0_clinic_assert( null === nvx_clinic_key_from_landing_path( '/foo-goya-salamanca/' ), 'SUBSTRING_BLOCKED' );
nvx_p0_clinic_assert( null === nvx_clinic_key_from_landing_path( rtrim( $chamberi_path, '/' ) . '/extra/' ), 'NESTED_PATH_BLOCKED' );
nvx_p0_clinic_assert( null === nvx_clinic_key_from_landing_path( '/unrelated/' ), 'UNKNOWN_BLOCKED' );

$sede_template = '/theme/templates/page-sede.php';
$GLOBALS['nvx_test_path'] = '/unrelated/';
$blocked = nvx_clinic_template_fail_closed( $sede_template );
nvx_p0_clinic_assert( '/theme/page.php' === $blocked, 'MISASSIGNED_SEDE_TEMPLATE_FAILS_CLOSED' );
nvx_p0_clinic_assert( array() === $GLOBALS['nvx_test_query_var'], 'MISASSIGNED_PAGE_HAS_NO_CLINIC_IDENTITY' );

$GLOBALS['nvx_test_path'] = $goya_path;
$allowed                  = nvx_clinic_template_fail_closed( $sede_template );
nvx_p0_clinic_assert( $sede_template === $allowed, 'CANONICAL_GOYA_TEMPLATE_ALLOWED' );
nvx_p0_clinic_assert( 'goya' === ( $GLOBALS['nvx_test_query_var']['nvx_clinic_key'] ?? '' ), 'CANONICAL_GOYA_KEY_EXPOSED' );

$GLOBALS['nvx_test_hub']  = true;
$GLOBALS['nvx_test_path'] = '/clinicas-de-medicina-estetica-nuvanx/';
$hub_template             = nvx_clinic_template_fail_closed( $sede_template );
nvx_p0_clinic_assert( $sede_template === $hub_template, 'MANAGED_CLINICS_HUB_PRESERVED' );

echo 'CLINIC_TEMPLATE_ROUTING=PASS source=clinics-json exact=2 substring=blocked nested=blocked template=fail_closed' . PHP_EOL;

<?php
/**
 * Exact-path clinic identity/NAP regression contract.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'NVX_HOOK_PRIO_CONTACT_MAPS' ) ) {
	define( 'NVX_HOOK_PRIO_CONTACT_MAPS', 80 );
}

$GLOBALS['nvx_test_clinic_path'] = '/medicina-estetica-chamberi/';
$GLOBALS['nvx_test_front']       = false;
$GLOBALS['nvx_test_post']        = false;
$GLOBALS['nvx_test_hub']         = false;

function add_filter( ...$args ): bool { unset( $args ); return true; }
function sanitize_key( $value ): string { return preg_replace( '/[^a-z0-9_-]/i', '', (string) $value ) ?? ''; }
function is_email( string $email ): bool { return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ); }
function nvx_whatsapp_url_from_phone( string $phone ): string { return 'https://wa.me/' . preg_replace( '/\D+/', '', $phone ); }
function nvx_theme_request_path(): string { return (string) $GLOBALS['nvx_test_clinic_path']; }
function is_front_page(): bool { return (bool) $GLOBALS['nvx_test_front']; }
function is_singular( string $type = '' ): bool { return 'post' === $type && (bool) $GLOBALS['nvx_test_post']; }
function nvxIsClinicsHub(): bool { return (bool) $GLOBALS['nvx_test_hub']; }
function esc_html__( string $text, string $domain = '' ): string { unset( $domain ); return $text; }
function esc_html( string $text ): string { return $text; }
function nvx_lazy_map_embed_markup( string $src, string $title, string $class ): string { unset( $src, $title, $class ); return ''; }
function is_admin(): bool { return false; }
function nvx_is_contacto_page_request(): bool { return false; }

function nvx_clinic_test_assert( bool $condition, string $name ): void {
	if ( ! $condition ) {
		fwrite( STDERR, 'CLINIC_IDENTITY_NAP=FAIL invariant=' . $name . PHP_EOL );
		exit( 1 );
	}
}

$root = dirname( __DIR__, 2 );
require_once $root . '/wp-content/themes/nuvanx-medical/inc/nvx-business-config.php';
require_once $root . '/wp-content/themes/nuvanx-medical/inc/nvx-clinic-identity-governance.php';

$clinics       = nvx_get_clinics_config();
$chamberi_path = (string) ( $clinics['chamberi']['landing_path'] ?? '' );
$goya_path     = (string) ( $clinics['goya']['landing_path'] ?? '' );

nvx_clinic_test_assert( '' !== $chamberi_path && '' !== $goya_path, 'CANONICAL_PATHS_AVAILABLE' );
nvx_clinic_test_assert( 'chamberi' === nvx_clinic_key_from_landing_path( $chamberi_path ), 'CHAMBERI_EXACT_PATH' );
nvx_clinic_test_assert( 'goya' === nvx_clinic_key_from_landing_path( $goya_path . '?utm_source=test' ), 'GOYA_EXACT_PATH_QUERY_NORMALIZED' );
nvx_clinic_test_assert( null === nvx_clinic_key_from_landing_path( rtrim( $chamberi_path, '/' ) . '/fake-child/' ), 'CHAMBERI_NESTED_REJECTED' );
nvx_clinic_test_assert( null === nvx_clinic_key_from_landing_path( '/foo-medicina-estetica-chamberi-bar/' ), 'SUBSTRING_REJECTED' );

$graph = array(
	array(
		'@type'           => array( 'Organization', 'MedicalOrganization' ),
		'@id'             => 'https://nuvanx.test/#organization',
		'subOrganization' => array( array( '@id' => 'https://nuvanx.test/#chamberi' ), array( '@id' => 'https://nuvanx.test/#goya' ) ),
		'department'      => array( array( '@id' => 'https://nuvanx.test/#chamberi' ), array( '@id' => 'https://nuvanx.test/#goya' ) ),
	),
	array( '@type' => array( 'MedicalClinic', 'LocalBusiness' ), '@id' => 'https://nuvanx.test/#chamberi', 'branchCode' => 'chamberi', 'telephone' => '+34111111111' ),
	array( '@type' => array( 'MedicalClinic', 'LocalBusiness' ), '@id' => 'https://nuvanx.test/#goya', 'branchCode' => 'goya', 'telephone' => '+34222222222' ),
);

$GLOBALS['nvx_test_clinic_path'] = '/unrelated-page/';
$clean = nvx_clinic_identity_schema_graph( $graph );
nvx_clinic_test_assert( 1 === count( $clean ), 'UNRELATED_SCHEMA_REMOVES_BOTH_CLINICS' );
nvx_clinic_test_assert( ! isset( $clean[0]['subOrganization'], $clean[0]['department'] ), 'UNRELATED_SCHEMA_REMOVES_DANGLING_REFS' );

$GLOBALS['nvx_test_clinic_path'] = $chamberi_path;
$chamberi = nvx_clinic_identity_schema_graph( $graph );
nvx_clinic_test_assert( 2 === count( $chamberi ), 'CHAMBERI_SCHEMA_SINGLE_BRANCH' );
nvx_clinic_test_assert( 'chamberi' === ( $chamberi[1]['branchCode'] ?? '' ), 'CHAMBERI_SCHEMA_CORRECT_BRANCH' );
nvx_clinic_test_assert( 1 === count( $chamberi[0]['subOrganization'] ?? array() ), 'CHAMBERI_SCHEMA_SINGLE_REF' );
nvx_clinic_test_assert( 'https://nuvanx.test/#chamberi' === ( $chamberi[0]['subOrganization'][0]['@id'] ?? '' ), 'CHAMBERI_SCHEMA_CORRECT_REF' );

$GLOBALS['nvx_test_clinic_path'] = $goya_path;
$goya = nvx_clinic_identity_schema_graph( $graph );
nvx_clinic_test_assert( 2 === count( $goya ), 'GOYA_SCHEMA_SINGLE_BRANCH' );
nvx_clinic_test_assert( 'goya' === ( $goya[1]['branchCode'] ?? '' ), 'GOYA_SCHEMA_CORRECT_BRANCH' );

$GLOBALS['nvx_test_clinic_path'] = rtrim( $chamberi_path, '/' ) . '/fake-child/';
$nested = nvx_clinic_identity_schema_graph( $graph );
nvx_clinic_test_assert( 1 === count( $nested ), 'NESTED_SCHEMA_FAILS_CLOSED' );

$GLOBALS['nvx_test_hub']         = true;
$GLOBALS['nvx_test_clinic_path'] = '/clinicas-de-medicina-estetica-nuvanx/';
$hub = nvx_clinic_identity_schema_graph( $graph );
nvx_clinic_test_assert( 3 === count( $hub ), 'CLINICS_HUB_PRESERVES_BOTH_BRANCHES' );
$GLOBALS['nvx_test_hub'] = false;

$GLOBALS['nvx_test_clinic_path'] = '/equipo-medico/';
$team = nvx_clinic_identity_schema_graph( $graph );
nvx_clinic_test_assert( 3 === count( $team ), 'TEAM_HUB_PRESERVES_BOTH_BRANCHES' );

$GLOBALS['nvx_test_front']       = true;
$GLOBALS['nvx_test_clinic_path'] = '/';
$home = nvx_clinic_identity_schema_graph( $graph );
nvx_clinic_test_assert( 3 === count( $home ), 'HOME_PRESERVES_BOTH_BRANCHES' );
$GLOBALS['nvx_test_front'] = false;

$GLOBALS['nvx_test_post']        = true;
$GLOBALS['nvx_test_clinic_path'] = '/journal-example/';
$post = nvx_clinic_identity_schema_graph( $graph );
nvx_clinic_test_assert( 3 === count( $post ), 'EDITORIAL_POST_PRESERVES_BOTH_BRANCHES' );
$GLOBALS['nvx_test_post'] = false;

$identity_source = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-clinic-identity-governance.php' );
$business_source = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-business-config.php' );
$bootstrap       = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-theme-bootstrap.php' );

nvx_clinic_test_assert( ! str_contains( $identity_source, 'template_include' ), 'IDENTITY_FENCE_NOT_TEMPLATE_OWNER' );
nvx_clinic_test_assert( ! str_contains( $identity_source, 'get_page_template_slug' ), 'PERSISTED_TEMPLATE_NOT_SCHEMA_OWNER' );
nvx_clinic_test_assert( ! str_contains( $identity_source, 'strpos( $path' ), 'NO_SUBSTRING_ROUTE_OWNER' );
nvx_clinic_test_assert( str_contains( $identity_source, 'nvx_current_clinic_landing_key()' ), 'SCHEMA_DELEGATES_TO_ROUTE_OWNER' );
nvx_clinic_test_assert( str_contains( $business_source, 'function nvx_clinic_key_from_landing_path' ), 'BUSINESS_CONFIG_EXACT_RESOLVER' );
nvx_clinic_test_assert( str_contains( $identity_source, 'PHP_INT_MAX - 1' ), 'FINAL_SCHEMA_FENCE_PRIORITY' );
nvx_clinic_test_assert( strpos( $bootstrap, "'inc/nvx-business-config.php'" ) < strpos( $bootstrap, "'inc/nvx-clinic-identity-governance.php'" ), 'BOOTSTRAP_DEPENDENCY_ORDER' );

echo 'CLINIC_IDENTITY_NAP=PASS route_owner=business-config schema_owner=delegated-final-fence exact=2 nested=blocked refs=clean aggregate_surfaces=preserved' . PHP_EOL;

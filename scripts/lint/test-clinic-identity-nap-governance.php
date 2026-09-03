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

$GLOBALS['nvx_test_clinic_path'] = '/';
$GLOBALS['nvx_test_front']       = false;
$GLOBALS['nvx_test_post']        = false;

function add_filter( ...$args ): bool { unset( $args ); return true; }
function is_email( string $email ): bool { return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ); }
function nvx_whatsapp_url_from_phone( string $phone ): string { return 'https://wa.me/' . preg_replace( '/\D+/', '', $phone ); }
function nvx_theme_request_path(): string { return (string) $GLOBALS['nvx_test_clinic_path']; }
function is_front_page(): bool { return (bool) $GLOBALS['nvx_test_front']; }
function is_singular( string $type = '' ): bool { return 'post' === $type && (bool) $GLOBALS['nvx_test_post']; }
function sanitize_key( $value ): string { return preg_replace( '/[^a-z0-9_-]/i', '', (string) $value ) ?? ''; }
function esc_html__( string $text, string $domain = '' ): string { unset( $domain ); return $text; }
function esc_html( string $text ): string { return $text; }
function nvx_lazy_map_embed_markup( string $src, string $title, string $class ): string { unset( $src, $title, $class ); return ''; }
function is_admin(): bool { return false; }
function nvx_is_contacto_page_request(): bool { return false; }

function nvx_clinic_identity_test_assert( bool $condition, string $name ): void {
	if ( ! $condition ) {
		fwrite( STDERR, 'CLINIC_IDENTITY_NAP=FAIL invariant=' . $name . PHP_EOL );
		exit( 1 );
	}
}

$root = dirname( __DIR__, 2 );
require_once $root . '/wp-content/themes/nuvanx-medical/inc/nvx-business-config.php';
require_once $root . '/wp-content/themes/nuvanx-medical/inc/nvx-clinic-identity-governance.php';

$clinics = nvx_get_clinics_config();
nvx_clinic_identity_test_assert( isset( $clinics['chamberi'], $clinics['goya'] ), 'CANONICAL_CLINICS_LOADED' );

$chamberi_path = (string) $clinics['chamberi']['landing_path'];
$goya_path     = (string) $clinics['goya']['landing_path'];
nvx_clinic_identity_test_assert( 'chamberi' === nvx_clinic_key_from_landing_path( $chamberi_path ), 'CHAMBERI_EXACT_PATH' );
nvx_clinic_identity_test_assert( 'goya' === nvx_clinic_key_from_landing_path( $goya_path . '?utm_source=test' ), 'GOYA_QUERY_NORMALIZED' );
nvx_clinic_identity_test_assert( null === nvx_clinic_key_from_landing_path( rtrim( $chamberi_path, '/' ) . '/fake-child/' ), 'CHAMBERI_NESTED_REJECTED' );
nvx_clinic_identity_test_assert( null === nvx_clinic_key_from_landing_path( '/foo-goya-salamanca/' ), 'SUBSTRING_REJECTED' );

$graph = array(
	array(
		'@type'           => array( 'Organization', 'MedicalOrganization' ),
		'@id'             => 'https://nuvanx.test/#organization',
		'subOrganization' => array(
			array( '@id' => 'https://nuvanx.test/#chamberi' ),
			array( '@id' => 'https://nuvanx.test/#goya' ),
		),
		'department'      => array( '@id' => 'https://nuvanx.test/#goya' ),
	),
	array(
		'@type'      => array( 'MedicalClinic', 'LocalBusiness' ),
		'@id'        => 'https://nuvanx.test/#chamberi',
		'branchCode' => 'chamberi',
		'telephone'  => '+34111111111',
	),
	array(
		'@type'      => array( 'MedicalClinic', 'LocalBusiness' ),
		'@id'        => 'https://nuvanx.test/#goya',
		'branchCode' => 'goya',
		'telephone'  => '+34222222222',
	),
);

$GLOBALS['nvx_test_clinic_path'] = '/unrelated-page/';
$clean = nvx_clinic_identity_schema_graph( $graph );
nvx_clinic_identity_test_assert( 1 === count( $clean ), 'UNRELATED_SCHEMA_REMOVES_BOTH_CLINICS' );
nvx_clinic_identity_test_assert( ! isset( $clean[0]['subOrganization'], $clean[0]['department'] ), 'UNRELATED_SCHEMA_REMOVES_DANGLING_REFS' );

$GLOBALS['nvx_test_clinic_path'] = $chamberi_path;
$chamberi = nvx_clinic_identity_schema_graph( $graph );
nvx_clinic_identity_test_assert( 2 === count( $chamberi ), 'CHAMBERI_SCHEMA_SINGLE_BRANCH' );
nvx_clinic_identity_test_assert( 'chamberi' === ( $chamberi[1]['branchCode'] ?? '' ), 'CHAMBERI_SCHEMA_CORRECT_BRANCH' );
nvx_clinic_identity_test_assert( 1 === count( $chamberi[0]['subOrganization'] ?? array() ), 'CHAMBERI_SCHEMA_SINGLE_LIST_REF' );
nvx_clinic_identity_test_assert( ! isset( $chamberi[0]['department'] ), 'CHAMBERI_SCHEMA_REMOVES_SINGLE_GOYA_REF' );

$GLOBALS['nvx_test_clinic_path'] = $goya_path;
$goya = nvx_clinic_identity_schema_graph( $graph );
nvx_clinic_identity_test_assert( 2 === count( $goya ), 'GOYA_SCHEMA_SINGLE_BRANCH' );
nvx_clinic_identity_test_assert( 'goya' === ( $goya[1]['branchCode'] ?? '' ), 'GOYA_SCHEMA_CORRECT_BRANCH' );
nvx_clinic_identity_test_assert( 'https://nuvanx.test/#goya' === ( $goya[0]['department']['@id'] ?? '' ), 'GOYA_SCHEMA_SINGLE_ASSOC_REF_PRESERVED' );

$GLOBALS['nvx_test_clinic_path'] = rtrim( $chamberi_path, '/' ) . '/fake-child/';
$nested = nvx_clinic_identity_schema_graph( $graph );
nvx_clinic_identity_test_assert( 1 === count( $nested ), 'NESTED_SCHEMA_FAILS_CLOSED' );

$GLOBALS['nvx_test_clinic_path'] = '/clinicas-de-medicina-estetica-nuvanx/';
$hub = nvx_clinic_identity_schema_graph( $graph );
nvx_clinic_identity_test_assert( 3 === count( $hub ), 'CLINICS_HUB_PRESERVES_BOTH_BRANCHES' );

$GLOBALS['nvx_test_clinic_path'] = '/equipo-medico/';
$team = nvx_clinic_identity_schema_graph( $graph );
nvx_clinic_identity_test_assert( 3 === count( $team ), 'TEAM_HUB_PRESERVES_BOTH_BRANCHES' );

$GLOBALS['nvx_test_clinic_path'] = '/contacto/';
$contact = nvx_clinic_identity_schema_graph( $graph );
nvx_clinic_identity_test_assert( 3 === count( $contact ), 'CONTACTO_EXACT_PRESERVES_BOTH_BRANCHES' );
nvx_clinic_identity_test_assert( 2 === count( $contact[0]['subOrganization'] ?? array() ), 'CONTACTO_PRESERVES_BOTH_LIST_REFS' );
nvx_clinic_identity_test_assert( 'https://nuvanx.test/#goya' === ( $contact[0]['department']['@id'] ?? '' ), 'CONTACTO_PRESERVES_ASSOC_REF' );

$GLOBALS['nvx_test_clinic_path'] = '/foo/contacto/';
$nested_contact = nvx_clinic_identity_schema_graph( $graph );
nvx_clinic_identity_test_assert( 1 === count( $nested_contact ), 'CONTACTO_NESTED_FAILS_CLOSED' );
nvx_clinic_identity_test_assert( ! isset( $nested_contact[0]['subOrganization'], $nested_contact[0]['department'] ), 'CONTACTO_NESTED_REMOVES_REFS' );

$GLOBALS['nvx_test_clinic_path'] = '/contacto/extra/';
$contact_child = nvx_clinic_identity_schema_graph( $graph );
nvx_clinic_identity_test_assert( 1 === count( $contact_child ), 'CONTACTO_CHILD_FAILS_CLOSED' );

$GLOBALS['nvx_test_clinic_path'] = '/';
$GLOBALS['nvx_test_front']       = true;
$home = nvx_clinic_identity_schema_graph( $graph );
nvx_clinic_identity_test_assert( 3 === count( $home ), 'HOME_PRESERVES_BOTH_BRANCHES' );
$GLOBALS['nvx_test_front'] = false;

$GLOBALS['nvx_test_clinic_path'] = '/journal-entry/';
$GLOBALS['nvx_test_post']        = true;
$post = nvx_clinic_identity_schema_graph( $graph );
nvx_clinic_identity_test_assert( 3 === count( $post ), 'JOURNAL_POST_PRESERVES_BOTH_BRANCHES' );
$GLOBALS['nvx_test_post'] = false;

$schema_source   = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-clinic-identity-governance.php' );
$routing_source  = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-business-config.php' );
$template_source = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/templates/page-sede.php' );

nvx_clinic_identity_test_assert( false === strpos( $schema_source, 'template_include' ), 'SCHEMA_OWNER_HAS_NO_TEMPLATE_AUTHORITY' );
nvx_clinic_identity_test_assert( false === strpos( $schema_source, 'get_page_template_slug' ), 'PERSISTED_TEMPLATE_NOT_SCHEMA_OWNER' );
nvx_clinic_identity_test_assert( false === strpos( $schema_source, 'strpos( $path' ), 'SCHEMA_OWNER_NO_SUBSTRING_ROUTING' );
nvx_clinic_identity_test_assert( false !== strpos( $schema_source, "'/contacto/' === \$path" ), 'CONTACTO_USES_EXACT_IMMUTABLE_PATH' );
nvx_clinic_identity_test_assert( false === strpos( $schema_source, 'nvx_is_contacto_page_request()' ), 'CONTACTO_BROAD_HELPER_NOT_TRUST_ROOT' );
nvx_clinic_identity_test_assert( false !== strpos( $schema_source, 'PHP_INT_MAX - 1' ), 'FINAL_SCHEMA_FENCE_PRIORITY' );
nvx_clinic_identity_test_assert( 1 === substr_count( $routing_source, "add_filter( 'template_include'" ), 'SINGLE_TEMPLATE_ROUTING_OWNER' );
nvx_clinic_identity_test_assert( false === strpos( $template_source, 'strpos( $current_slug' ), 'TEMPLATE_SLUG_INFERENCE_REMOVED' );
nvx_clinic_identity_test_assert( false === strpos( $template_source, '$clinic_key   = \'chamberi\'' ), 'TEMPLATE_DEFAULT_CLINIC_REMOVED' );

echo 'CLINIC_IDENTITY_NAP=PASS source=clinics-json exact=2 nested=blocked substring=blocked contacto=exact-only template_owner=single schema=final_fence refs=list+associative hubs=bounded' . PHP_EOL;

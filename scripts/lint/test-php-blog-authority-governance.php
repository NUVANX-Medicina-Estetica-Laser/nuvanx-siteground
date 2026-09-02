<?php
/**
 * Block 6 regression: Blog + medical review + authority / clinical governance.
 *
 * @package nuvanx-medical
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$GLOBALS['nvx_test_btl_request'] = true;

function add_action( ...$args ): void { unset( $args ); }
function add_filter( ...$args ): bool { unset( $args ); return true; }
function is_admin(): bool { return false; }
function wp_doing_ajax(): bool { return false; }
function is_page(): bool { return ! empty( $GLOBALS['nvx_test_btl_request'] ); }
function get_post_field( $field, $id = 0 ): string { unset( $field, $id ); return $GLOBALS['nvx_test_btl_slug'] ?? 'exion-face'; }
function get_queried_object_id(): int { return 1; }
function __( string $text, string $domain = '' ): string { unset( $domain ); return $text; }
function esc_html__( string $text, string $domain = '' ): string { unset( $domain ); return $text; }
function esc_html( string $text ): string { return $text; }
function esc_attr( string $text ): string { return $text; }
function esc_url( string $text ): string { return $text; }
function home_url( string $path = '' ): string { return 'https://nuvanx.test' . $path; }

function nvx_block6_assert( bool $condition, string $name ): void {
	if ( ! $condition ) {
		fwrite( STDERR, 'PHP_BLOG_AUTHORITY_GOVERNANCE=FAIL invariant=' . $name . PHP_EOL );
		exit( 1 );
	}
}

$root = dirname( __DIR__, 2 );
require_once $root . '/wp-content/themes/nuvanx-medical/inc/nvx-constants.php';
require_once $root . '/wp-content/themes/nuvanx-medical/inc/nvx-medical-review.php';
require_once $root . '/wp-content/themes/nuvanx-medical/inc/nvx-btl-clinical-governance.php';
require_once $root . '/wp-content/themes/nuvanx-medical/inc/nvx-btl-detail-pages.php';

$complete = array(
	'name'    => 'Dr. Test',
	'license' => '12345',
	'url'     => 'https://nuvanx.test/equipo/#doctor',
	'id'      => 'https://nuvanx.test/equipo/#doctor',
	'title'   => 'Director médico',
);
nvx_block6_assert( nvx_medical_review_reviewer_complete( $complete ), 'COMPLETE_REVIEWER_ACCEPTED' );
foreach ( array_keys( $complete ) as $field ) {
	$invalid           = $complete;
	$invalid[ $field ] = '   ';
	nvx_block6_assert( ! nvx_medical_review_reviewer_complete( $invalid ), 'EMPTY_REVIEWER_FIELD_REJECTED_' . $field );
}

$div_byline     = '<div class="nvx-medical-byline"><div class="nvx-medical-byline__text"><strong>X</strong></div></div>';
$address_byline = '<address class="nvx-medical-byline"><div class="nvx-medical-byline__text"><strong>Y</strong></div></address>';
$stripped       = nvx_medical_review_strip_unconditional_bylines( '<p>keep</p>' . $div_byline . $address_byline );
nvx_block6_assert( false === strpos( $stripped, 'nvx-medical-byline' ), 'UNCONDITIONAL_BYLINES_STRIPPED' );
nvx_block6_assert( false !== strpos( $stripped, '<p>keep</p>' ), 'NON_BYLINE_CONTENT_PRESERVED' );

$base = '<article><p>Contenido BTL</p><!-- nvx:clinical-note-anchor --></article>';
$once = nvx_btl_govern_rendered_content( $base );
nvx_block6_assert( 1 === substr_count( $once, 'data-nvx-btl-clinical-note="1"' ), 'BTL_NOTE_INSERTED_ONCE' );
$twice = nvx_btl_govern_rendered_content( $once );
nvx_block6_assert( 1 === substr_count( $twice, 'data-nvx-btl-clinical-note="1"' ), 'BTL_NOTE_IDEMPOTENT' );

$with_cta = '<article><p>Contenido BTL</p><section class="nvx-closing-cta">CTA</section></article>';
$via_cta  = nvx_btl_govern_rendered_content( $with_cta );
nvx_block6_assert( 1 === substr_count( $via_cta, 'data-nvx-btl-clinical-note="1"' ), 'BTL_NOTE_INSERTED_VIA_CTA' );

$without_anchor = '<article><p>Contenido BTL sin CTA ni anchor</p></article>';
$no_insertion   = nvx_btl_govern_rendered_content( $without_anchor );
nvx_block6_assert( 0 === substr_count( $no_insertion, 'data-nvx-btl-clinical-note="1"' ), 'BTL_NOTE_NO_BLIND_APPEND' );

$competitor = '<article><details><summary>Comparación</summary>Morpheus8 frente a EXION</details></article>';
$governed   = nvx_btl_govern_rendered_content( $competitor );
nvx_block6_assert( false === strpos( $governed, 'Morpheus8' ), 'COMPETITOR_DETAIL_REMOVED' );

$journal_source = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-journal-laserlipolisis-vs-lipo.php' );
$bootstrap      = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-theme-bootstrap.php' );
$catalog_owner  = strpos( $bootstrap, "'inc/nvx-catalog-json.php'" );
$journal_owner  = strpos( $bootstrap, "'inc/nvx-journal-laserlipolisis-vs-lipo.php'" );
nvx_block6_assert( false !== $catalog_owner && false !== $journal_owner && $catalog_owner < $journal_owner, 'CATALOG_OWNER_PRECEDES_JOURNAL' );
nvx_block6_assert( false === strpos( $journal_source, "require_once __DIR__ . '/nvx-catalog-json.php'" ), 'JOURNAL_LATERAL_OWNER_REMOVED_AFTER_CONSOLIDATION' );

foreach ( array( 'exion-face', 'exion-body', 'exion-fractional', 'emfusion' ) as $btl_slug ) {
	$GLOBALS['nvx_test_btl_slug'] = $btl_slug;
	$raw_markup                   = nvx_btl_detail_page_markup( $btl_slug );
	nvx_block6_assert( '' !== $raw_markup, 'BTL_PAGE_MARKUP_NON_EMPTY_' . $btl_slug );
	nvx_block6_assert( false !== strpos( $raw_markup, '<!-- nvx:clinical-note-anchor -->' ), 'BTL_PAGE_CONTAINS_ANCHOR_' . $btl_slug );
	$governed_page = nvx_btl_govern_rendered_content( $raw_markup );
	nvx_block6_assert( 1 === substr_count( $governed_page, 'data-nvx-btl-clinical-note="1"' ), 'BTL_GENERATED_PAGE_HAS_ONE_NOTE_' . $btl_slug );
	nvx_block6_assert( false === strpos( $governed_page, '<!-- nvx:clinical-note-anchor -->' ), 'BTL_ANCHOR_REPLACED_' . $btl_slug );
}

echo 'PHP_BLOG_AUTHORITY_GOVERNANCE=PASS reviewer=fail_closed btl_note=idempotent fallback=present btl_canonical_pages=4' . PHP_EOL;

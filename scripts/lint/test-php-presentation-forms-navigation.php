<?php
/**
 * Block 4 regression: shared presentation, forms and navigation runtime contracts.
 *
 * @package nuvanx-medical
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['nvx_test_modal_enabled']    = false;
$GLOBALS['nvx_test_valoracion_page'] = false;

function home_url( string $path = '' ): string {
	return 'https://nuvanx.test' . $path;
}
function nvx_whatsapp_url( string $key ): string {
	unset( $key );
	return 'https://wa.me/34123456789';
}
function nvx_valoracion_modal_enabled(): bool {
	return ! empty( $GLOBALS['nvx_test_modal_enabled'] );
}
function nvx_theme_is_valoracion_form_page(): bool {
	return ! empty( $GLOBALS['nvx_test_valoracion_page'] );
}
function get_permalink(): string {
	return 'https://nuvanx.test/madrid/valoracion/';
}
function trailingslashit( string $value ): string {
	return rtrim( $value, '/' ) . '/';
}
function esc_attr( $value ): string { return (string) $value; }
function esc_url( $value ): string { return (string) $value; }
function esc_html__( string $value, string $domain = '' ): string { unset( $domain ); return $value; }
function esc_attr__( string $value, string $domain = '' ): string { unset( $domain ); return $value; }

function nvx_block4_assert( bool $condition, string $name ): void {
	if ( ! $condition ) {
		fwrite( STDERR, 'PHP_PRESENTATION_FORMS_NAVIGATION=FAIL invariant=' . $name . PHP_EOL );
		exit( 1 );
	}
}

$root = dirname( __DIR__, 2 );
require_once $root . '/wp-content/themes/nuvanx-medical/inc/nvx-cta-components.php';

// No modal: CTA must remain an ordinary navigation control with no false dialog semantics.
$GLOBALS['nvx_test_modal_enabled'] = false;
$pair = nvx_cta_pair_markup();
nvx_block4_assert( false === strpos( $pair, 'nvx-open-valoracion-modal' ), 'NO_MODAL_CLASS_WHEN_DISABLED' );
nvx_block4_assert( false === strpos( $pair, 'data-nvx-valoracion-modal="1"' ), 'NO_MODAL_DATA_WHEN_DISABLED' );
nvx_block4_assert( false === strpos( $pair, 'aria-haspopup="dialog"' ), 'NO_DIALOG_ARIA_WHEN_DISABLED' );
nvx_block4_assert( false !== strpos( $pair, 'href="https://nuvanx.test/madrid/valoracion/"' ), 'DIRECT_VALORACION_HREF_PRESERVED' );

$closing = nvx_site_closing_cta_markup();
nvx_block4_assert( false === strpos( $closing, 'aria-haspopup="dialog"' ), 'CLOSING_CTA_NO_DIALOG_ARIA_WHEN_DISABLED' );

// Modal available: the canonical modal contract must be restored consistently.
$GLOBALS['nvx_test_modal_enabled'] = true;
$pair = nvx_cta_pair_markup();
nvx_block4_assert( false !== strpos( $pair, 'nvx-open-valoracion-modal' ), 'MODAL_CLASS_WHEN_ENABLED' );
nvx_block4_assert( false !== strpos( $pair, 'data-nvx-valoracion-modal="1"' ), 'MODAL_DATA_WHEN_ENABLED' );
nvx_block4_assert( false !== strpos( $pair, 'aria-haspopup="dialog"' ), 'DIALOG_ARIA_WHEN_ENABLED' );

// Full valoración page: even if a caller toggles the modal capability unexpectedly,
// the canonical href must still target the first-party form stage.
$GLOBALS['nvx_test_valoracion_page'] = true;
$GLOBALS['nvx_test_modal_enabled']    = true;
$pair = nvx_cta_pair_markup();
nvx_block4_assert(
	false !== strpos( $pair, 'href="https://nuvanx.test/madrid/valoracion/#nvx-hubspot-form"' ),
	'VALORACION_PAGE_FORM_ANCHOR'
);
nvx_block4_assert( false === strpos( $pair, 'nvx-open-valoracion-modal' ), 'VALORACION_PAGE_NO_MODAL_CLASS' );
nvx_block4_assert( false === strpos( $pair, 'data-nvx-valoracion-modal="1"' ), 'VALORACION_PAGE_NO_MODAL_DATA' );
nvx_block4_assert( false === strpos( $pair, 'aria-haspopup="dialog"' ), 'VALORACION_PAGE_NO_FALSE_DIALOG' );

// Inventory guard: immutable request path API must exist for the later consolidation
// of direct-form request context; do not regress back to an ungoverned request surface.
$request_source = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-theme-request.php' );
nvx_block4_assert( false !== strpos( $request_source, 'function nvx_theme_request_path()' ), 'IMMUTABLE_REQUEST_PATH_API_PRESENT' );

// Inventory guard: canonical bootstrap must own dependencies before their consumers.
$bootstrap = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-theme-bootstrap.php' );
$consent   = strpos( $bootstrap, "'inc/nvx-marketing-consent.php'" );
$direct    = strpos( $bootstrap, "'inc/nvx-valoracion-direct-form.php'" );
$cta       = strpos( $bootstrap, "'inc/nvx-cta-components.php'" );
$content   = strpos( $bootstrap, "'inc/nvx-content-presentation.php'" );
nvx_block4_assert( false !== $consent && false !== $direct && $consent < $direct, 'CONSENT_PRECEDES_DIRECT_FORM' );
nvx_block4_assert( false !== $cta && false !== $content && $cta < $content, 'CTA_PRECEDES_CONTENT_PRESENTATION' );

echo 'PHP_PRESENTATION_FORMS_NAVIGATION=PASS modal_semantics=request_aware valoracion_anchor=direct bootstrap_order=verified' . PHP_EOL;

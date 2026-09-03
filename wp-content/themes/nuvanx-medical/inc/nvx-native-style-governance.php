<?php
/**
 * Native WordPress asset governance for theme-owned templates.
 *
 * Static CSS is a versioned release asset owned by assets/css/*.css and is
 * emitted through WordPress stylesheet links. Runtime PHP must not rebuild,
 * concatenate, read or inline permanent stylesheets. Dynamic values may still
 * use WordPress inline-style APIs on their canonical enqueued handle.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Public HTML uses linked, browser-cacheable theme stylesheets. */
function nvx_theme_public_delivers_inline_styles(): bool {
	return false;
}

/** Whether the theme owns the complete body markup for the current page. */
function nvx_theme_owns_complete_page_markup(): bool {
	return is_front_page();
}

/**
 * Remove long-form prose semantics from managed component documents.
 *
 * @param string $content Rendered post content.
 * @return string
 */
function nvx_theme_normalize_managed_component_prose_wrapper( string $content ): string {
	if ( is_admin() || '' === $content ) {
		return $content;
	}

	if ( ! function_exists( 'nvx_get_page_owner' ) || empty( nvx_get_page_owner() ) ) {
		return $content;
	}

	$has_component_sections = false !== strpos( $content, 'nvx-brand-section' )
		|| false !== strpos( $content, 'nvx-aes-section' );

	if ( false === strpos( $content, 'entry-content nvx-page__content nvx-prose' )
		|| false === strpos( $content, 'nvx-brand-hero' )
		|| ! $has_component_sections ) {
		return $content;
	}

	$normalized = preg_replace(
		'/\bentry-content\s+nvx-page__content\s+nvx-prose\b/',
		'entry-content nvx-page__content',
		$content,
		1
	);

	return is_string( $normalized ) ? $normalized : $content;
}
add_filter( 'the_content', 'nvx_theme_normalize_managed_component_prose_wrapper', NVX_HOOK_PRIO_MANAGED_COMPONENT_PROSE_WRAPPER );

/**
 * Complete static CSS inventory used by release validation.
 *
 * This list is not a runtime bundle graph. WordPress enqueue ownership remains
 * in functions.php and route modules; this inventory exists only to prove that
 * every tracked stylesheet belongs to the governed release surface.
 *
 * @return string[] Relative paths below the active theme directory.
 */
function nvx_theme_critical_stylesheet_files(): array {
	return array(
		'assets/css/nvx-fonts.css',
		'assets/css/nvx-tokens.css',
		'assets/css/nvx-base.css',
		'assets/css/nvx-site-layout.css',
		'assets/css/nvx-components.css',
		'assets/css/nvx-patterns-editorial.css',
		'assets/css/nvx-treatment-authority.css',
		'assets/css/nvx-header.css',
		'assets/css/nvx-footer.css',
		'assets/css/nvx-accessibility-governance.css',
		'assets/css/nvx-home-v3.css',
		'assets/css/nvx-posts.css',
		'assets/css/nvx-soluciones-medicas.css',
		'assets/css/nvx-cases.css',
		'assets/css/nvx-equipo-medico.css',
		'assets/css/nvx-portfolio-hub.css',
	);
}

/**
 * Handles owned by local static theme stylesheets.
 *
 * @return string[]
 */
function nvx_theme_local_style_handles(): array {
	return array(
		'nvx-fonts',
		'nvx-tokens',
		'nvx-base',
		'nvx-layout',
		'nvx-components',
		'nvx-patterns',
		'nvx-treatment-authority',
		'nvx-header',
		'nvx-footer',
		'nvx-accessibility-governance',
		'nvx-home-v3',
		'nvx-portfolio-hub',
		'nvx-posts',
		'nvx-soluciones-medicas',
		'nvx-cases',
		'nvx-equipo-medico',
	);
}

/**
 * Start Google Fonts without blocking first paint.
 *
 * @param string $html   Generated stylesheet tag.
 * @param string $handle Registered stylesheet handle.
 * @param string $href   Stylesheet URL.
 * @param string $media  Original media attribute.
 */
function nvx_theme_nonblocking_google_fonts( string $html, string $handle, string $href, string $media ): string {
	unset( $media );
	if ( 'nvx-google-fonts' !== $handle || '' === $href ) {
		return $html;
	}

	$safe_href = esc_url( $href );
	$id        = esc_attr( $handle . '-css' );

	return '<link rel="preload" as="style" href="' . $safe_href . '" />' . "\n"
		. '<link rel="stylesheet" id="' . $id . '" href="' . $safe_href . '" media="print" onload="this.onload=null;this.media=\'all\'" />' . "\n"
		. '<noscript><link rel="stylesheet" id="' . esc_attr( $handle . '-css-noscript' ) . '" href="' . $safe_href . '" /></noscript>' . "\n";
}
add_filter( 'style_loader_tag', 'nvx_theme_nonblocking_google_fonts', 20, 4 );

/**
 * Keep theme JS off the LCP critical path even if a plugin strips defer.
 *
 * @param string $tag    Generated script tag.
 * @param string $handle Registered script handle.
 * @param string $src    Script URL.
 */
function nvx_theme_defer_local_script_tags( string $tag, string $handle, string $src = '' ): string {
	if ( is_admin() || ! str_contains( $tag, '<script' ) ) {
		return $tag;
	}

	$handles  = array( 'nvx-main', 'nvx-conversion-events', 'nvx-runtime-governance', 'nvx-home-video', 'nvx-attribution-contract' );
	$is_local = in_array( $handle, $handles, true )
		|| ( '' !== $src && str_contains( $src, '/themes/nuvanx-medical/assets/js/' ) );
	if ( ! $is_local ) {
		return $tag;
	}

	if ( preg_match( '/\sdefer(=|>|\s)/i', $tag ) ) {
		return $tag;
	}

	$tag = (string) preg_replace( '/\s(?:async)(?:=(?:"[^"]*"|\'[^\']*\'|[^\s>]+))?/i', '', $tag );
	return (string) preg_replace( '/^<script\b/i', '<script defer', $tag, 1 );
}
add_filter( 'script_loader_tag', 'nvx_theme_defer_local_script_tags', 20, 3 );

/** Dequeue block styles only when the rendered page contains no block markup. */
function nvx_theme_dequeue_native_block_styles(): void {
	if ( is_admin() || ! nvx_theme_owns_complete_page_markup() ) {
		return;
	}

	$handles = array(
		'global-styles',
		'classic-theme-styles',
		'wp-block-library',
		'wp-block-library-theme',
		'core-block-supports',
	);

	foreach ( $handles as $handle ) {
		wp_dequeue_style( $handle );
	}
}
add_action( 'wp_enqueue_scripts', 'nvx_theme_dequeue_native_block_styles', 100 );

/**
 * Dequeue stylesheet handles that are no longer shipped with the theme.
 * Prevents broken enqueues stored in the database or third-party plugins.
 */
function nvx_theme_dequeue_retired_stylesheet_handles(): void {
	if ( is_admin() ) {
		return;
	}

	$handles = array(
		'nvx-mobile-hero-hierarchy',
		'nvx-canonical-page-hero',
		'nvx-full-site-ui-governance',
		'nvx-editorial-coherence',
		'nvx-site-coherence',
		'nvx-ui-regressions',
		'nvx-hero-layout-coherence',
		'nvx-integrations',
	);

	foreach ( $handles as $handle ) {
		wp_dequeue_style( $handle );
		wp_deregister_style( $handle );
	}
}
add_action( 'wp_enqueue_scripts', 'nvx_theme_dequeue_retired_stylesheet_handles', 999 );
add_action( 'wp_head', 'nvx_theme_dequeue_retired_stylesheet_handles', 1 );

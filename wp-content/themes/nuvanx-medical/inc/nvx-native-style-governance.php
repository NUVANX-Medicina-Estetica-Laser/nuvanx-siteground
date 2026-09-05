<?php
/**
 * Native WordPress presentation governance.
 *
 * Static theme CSS is owned by versioned source files and delivered through
 * normal WordPress stylesheet links. This module contains only runtime markup,
 * script and native-style policies that cannot live in static CSS.
 *
 * @package nuvanx-medical
 */
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Whether the theme owns the complete body markup for the current page. */
function nvx_theme_owns_complete_page_markup(): bool {
	return is_front_page();
}

/**
 * Remove long-form prose semantics from managed component documents.
 *
 * Several canonical page renderers intentionally emit a full-bleed hero plus
 * component sections inside the standard entry-content wrapper. Keeping the
 * outer nvx-prose class makes descendant long-form rules override the spacing
 * owned by hero/section components, especially on narrow viewports.
 *
 * Only managed pages with a brand hero and component sections
 * (nvx-brand-section or nvx-aes-section) are normalized; posts, legal
 * documents and ordinary CMS prose remain unchanged.
 *
 * @param string $content Rendered post content.
 * @return string
 */
function nvx_theme_normalize_managed_component_prose_wrapper( string $content ): string {
	if ( is_admin() || '' === $content ) {
		return $content;
	}

	if ( ! function_exists( 'nvx_get_page_owner' ) ) {
		return $content;
	}
	$owner = nvx_get_page_owner();
	if (
		empty( $owner )
		|| ( defined( 'NVX_CANONICAL_PAGE_UNOWNED' ) && NVX_CANONICAL_PAGE_UNOWNED === $owner )
	) {
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
 * Start Google Fonts without blocking first paint.
 *
 * The stylesheet remains an external browser-cacheable resource. Local theme
 * styles remain ordinary WordPress stylesheet links and are never re-embedded.
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
 * Keep theme JS off the LCP critical path even if a plugin strips strategy=defer.
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

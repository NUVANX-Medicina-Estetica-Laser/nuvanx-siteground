<?php
/**
 * NUVANX Performance Suite — centralised performance optimisations.
 *
 * What this file owns:
 *   1. LCP hero preload (`wp_head` priority 1) with WebP source hint and
 *      `fetchpriority="high"`.
 *   2. Additional `<link rel="preconnect">` / `<link rel="dns-prefetch">` hints
 *      for third-party origins NOT already emitted by header.php
 *      (fonts.googleapis.com / fonts.gstatic.com are already in header.php).
 *   3. Hero `<img>` attribute filter: force `loading="eager"` + `fetchpriority="high"`.
 *   4. Responsive hero image sizes registered on `after_setup_theme`.
 *   5. Head hygiene: remove WordPress generator meta, RSD link, WLW manifest,
 *      wp-shortlink, and oEmbed discovery links.
 *   6. External-link `rel="noopener noreferrer"` injection via `the_content`.
 *   7. `?ver=` query-string strip on enqueued styles and scripts.
 *
 * What this file deliberately DOES NOT own:
 *   - GTM loading: Site Kit is the single GTM owner (nvx-gtm-integration.php).
 *   - Script deferral: `nvx_theme_defer_local_script_tags` in
 *     nvx-native-style-governance.php already defers theme JS.
 *   - Preconnects to fonts.googleapis.com / fonts.gstatic.com: hardcoded in
 *     header.php at document-parse time (earliest possible slot).
 *   - CF7 dequeue: Contact Form 7 is not used in this theme.
 *
 * Installation:
 *   1. File location: inc/performance/nuvanx-performance.php
 *   2. Already required from functions.php (Section 1 — Infrastructure).
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ──────────────────────────────────────────────────────────────────────────────
// CONFIG
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Third-party origins that need an early preconnect / dns-prefetch hint
 * but are NOT already emitted by header.php (fonts.googleapis.com and
 * fonts.gstatic.com are already there).
 *
 * @var string[]
 */
if ( ! defined( 'NUVANX_PRECONNECT_ORIGINS' ) ) {
	define( 'NUVANX_PRECONNECT_ORIGINS', array(
		'https://www.googletagmanager.com',
		'https://www.google-analytics.com',
		'https://stats.g.doubleclick.net',
	) );
}

/**
 * ACF option-page field key used to resolve the front-page hero image.
 *
 * @var string
 */
if ( ! defined( 'NUVANX_HERO_ACF_FIELD' ) ) {
	define( 'NUVANX_HERO_ACF_FIELD', 'hero_image' );
}

/**
 * Theme customizer mod used as secondary fallback for the front-page hero.
 *
 * @var string
 */
if ( ! defined( 'NUVANX_HERO_THEME_MOD' ) ) {
	define( 'NUVANX_HERO_THEME_MOD', 'nuvanx_hero_image_id' );
}

/**
 * WordPress image-size handle to request for the LCP preload `href`.
 *
 * @var string
 */
if ( ! defined( 'NUVANX_HERO_IMAGE_SIZE' ) ) {
	define( 'NUVANX_HERO_IMAGE_SIZE', 'hero-desktop' );
}

// ──────────────────────────────────────────────────────────────────────────────
// 1. LCP HERO PRELOAD
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Resolve the attachment ID for the LCP hero on the current request.
 *
 * Resolution order:
 *   Front page: theme-mod → ACF options → featured image
 *   Singular:   featured image
 *   Archive:    none (returns 0)
 *
 * @return int Attachment ID, or 0 when not resolvable.
 */
function nuvanx_resolve_hero_image_id(): int {
	if ( is_front_page() ) {
		$id = (int) get_theme_mod( NUVANX_HERO_THEME_MOD, 0 );
		if ( $id > 0 ) {
			return $id;
		}

		if ( function_exists( 'get_field' ) ) {
			$acf = get_field( NUVANX_HERO_ACF_FIELD, 'option' );
			if ( is_array( $acf ) && isset( $acf['ID'] ) ) {
				return (int) $acf['ID'];
			}
			if ( is_numeric( $acf ) && (int) $acf > 0 ) {
				return (int) $acf;
			}
		}

		$post = get_queried_object();
		if ( $post instanceof WP_Post ) {
			$thumb = (int) get_post_thumbnail_id( $post->ID );
			if ( $thumb > 0 ) {
				return $thumb;
			}
		}

		return 0;
	}

	if ( is_singular() ) {
		$thumb = (int) get_post_thumbnail_id();
		return $thumb > 0 ? $thumb : 0;
	}

	return 0;
}

/**
 * Emit `<link rel="preload" as="image">` for the LCP hero in `<head>` priority 1.
 *
 * Attachment metadata can outlive physical derivatives in uploads. Preload is
 * a browser fetch contract, so never advertise a local candidate that the
 * public-media filesystem guard cannot verify as readable.
 */
function nuvanx_preload_lcp_hero(): void {
	$hero_id = nuvanx_resolve_hero_image_id();
	if ( $hero_id <= 0 ) {
		return;
	}

	$canonical_url  = '';
	$canonical_size = 'large';

	foreach ( array( NUVANX_HERO_IMAGE_SIZE, 'hero-full', 'large', 'full' ) as $size ) {
		$url = (string) wp_get_attachment_image_url( $hero_id, $size );
		if ( '' === $url ) {
			continue;
		}
		if ( function_exists( 'nvx_public_media_upload_url_is_readable' ) && ! nvx_public_media_upload_url_is_readable( $url ) ) {
			continue;
		}
		$canonical_size = $size;
		$canonical_url  = $url;
		break;
	}

	if ( '' === $canonical_url ) {
		return;
	}

	$srcset = (string) wp_get_attachment_image_srcset( $hero_id, $canonical_size );
	if ( '' !== $srcset && function_exists( 'nvx_public_media_runtime_filter_srcset_attribute' ) ) {
		$srcset = nvx_public_media_runtime_filter_srcset_attribute( $srcset );
	}
	$sizes = is_front_page()
		? '100vw'
		: '(max-width: 768px) 100vw, (max-width: 1200px) 100vw, 1440px';

	$srcset_attr = $srcset ? ' imagesrcset="' . esc_attr( $srcset ) . '"' : '';
	$sizes_attr  = ' imagesizes="' . esc_attr( $sizes ) . '"';

	$webp_url      = (string) preg_replace( '/\.(jpe?g|png)$/i', '.webp', $canonical_url );
	$webp_readable = $webp_url !== $canonical_url
		&& ( ! function_exists( 'nvx_public_media_upload_url_is_readable' ) || nvx_public_media_upload_url_is_readable( $webp_url ) );

	echo "\n<!-- nuvanx LCP preload -->\n";

	if ( $webp_readable ) {
		echo '<link rel="preload" as="image"'
			. ' href="' . esc_url( $webp_url ) . '"'
			. $srcset_attr // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			. $sizes_attr // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			. ' type="image/webp"'
			. ' fetchpriority="high"'
			. ">\n";
	}

	echo '<link rel="preload" as="image"'
		. ' href="' . esc_url( $canonical_url ) . '"'
		. $srcset_attr // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		. $sizes_attr // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		. ' fetchpriority="high"'
		. ">\n";
}
add_action( 'wp_head', 'nuvanx_preload_lcp_hero', 1 );

/**
 * Override `<img>` attributes for the LCP hero to force eager loading.
 *
 * @param string[]          $attr       Associative array of img attributes.
 * @param WP_Post           $attachment The attachment post object.
 * @param string|int[]      $size       Requested image size.
 * @return string[]
 */
function nuvanx_hero_image_priority_attrs( array $attr, WP_Post $attachment, $size ): array {
	if ( ! is_singular() && ! is_front_page() ) {
		return $attr;
	}

	$hero_id = nuvanx_resolve_hero_image_id();
	if ( $hero_id <= 0 || (int) $attachment->ID !== $hero_id ) {
		return $attr;
	}

	$attr['loading']       = 'eager';
	$attr['fetchpriority'] = 'high';
	$attr['decoding']      = 'sync';

	return $attr;
}
add_filter( 'wp_get_attachment_image_attributes', 'nuvanx_hero_image_priority_attrs', 10, 3 );

// ──────────────────────────────────────────────────────────────────────────────
// 2. PRECONNECT / DNS-PREFETCH
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Emit `<link rel="preconnect">` and `<link rel="dns-prefetch">` hints.
 * fires at `wp_head` priority 1 (before render-blocking resources).
 */
function nuvanx_emit_preconnect_hints(): void {
	foreach ( (array) NUVANX_PRECONNECT_ORIGINS as $origin ) {
		$origin = (string) $origin;
		if ( '' === $origin ) {
			continue;
		}
		echo '<link rel="preconnect" href="' . esc_url( $origin ) . '" crossorigin>' . "\n";
		echo '<link rel="dns-prefetch" href="' . esc_url( $origin ) . '">' . "\n";
	}
}
add_action( 'wp_head', 'nuvanx_emit_preconnect_hints', 1 );

// ──────────────────────────────────────────────────────────────────────────────
// 3. RESPONSIVE HERO IMAGE SIZES
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Register hero image sizes for responsive srcset generation.
 *
 * Run `wp media regenerate --yes` after activating to create derivatives.
 */
function nuvanx_register_hero_image_sizes(): void {
	add_image_size( 'hero-mobile',  768,  512, true );  // 1× mobile / 2× small mobile
	add_image_size( 'hero-tablet', 1024, 683, true );   // tablet / 2× mobile
	add_image_size( 'hero-desktop', 1440, 960, true );  // desktop
}
add_action( 'after_setup_theme', 'nuvanx_register_hero_image_sizes', 20 );

// ──────────────────────────────────────────────────────────────────────────────
// 4. HEAD HYGIENE
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Strip WordPress head tags that expose version information or are obsolete.
 *
 * Removed: wp_generator, rsd_link, wlwmanifest_link, wp_shortlink_wp_head,
 * oEmbed discovery links.
 */
function nuvanx_strip_wp_head_noise(): void {
	remove_action( 'wp_head', 'wp_generator' );
	remove_action( 'wp_head', 'rsd_link' );
	remove_action( 'wp_head', 'wlwmanifest_link' );
	remove_action( 'wp_head', 'wp_shortlink_wp_head', 10 );
	remove_action( 'wp_head', 'wp_oembed_add_discovery_links', 10 );
	remove_action( 'wp_head', 'wp_oembed_add_host_js' );
}
add_action( 'init', 'nuvanx_strip_wp_head_noise' );

// ──────────────────────────────────────────────────────────────────────────────
// 5. `?ver=` QUERY STRING STRIP ON ENQUEUED ASSETS
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Remove `?ver=` from enqueued style and script URLs.
 *
 * SiteGround Dynamic Cache and CDNs use URL-based cache keys; the ver query
 * arg prevents proxy caches from serving cached copies. Theme assets already
 * use filemtime-based versions (nvx_asset_version), making the arg redundant.
 *
 * @param string $src Asset URL.
 * @return string URL without `ver` query parameter.
 */
function nuvanx_strip_asset_version_query( string $src ): string {
	if ( str_contains( $src, 'ver=' ) ) {
		$src = (string) remove_query_arg( 'ver', $src );
	}

	return $src;
}
add_filter( 'style_loader_src',  'nuvanx_strip_asset_version_query', 9999 );
add_filter( 'script_loader_src', 'nuvanx_strip_asset_version_query', 9999 );

// ──────────────────────────────────────────────────────────────────────────────
// 6. EXTERNAL LINKS — auto rel="noopener noreferrer"
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Inject `rel="noopener noreferrer"` on external links inside post content.
 *
 * Does not overwrite an existing `rel` attribute.
 *
 * @param string $content Post content HTML.
 * @return string Modified content.
 */
function nuvanx_external_links_rel( string $content ): string {
	if ( '' === $content ) {
		return $content;
	}

	$home_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	if ( '' === $home_host ) {
		return $content;
	}

	return (string) preg_replace_callback(
		'/<a\s([^>]*)>/i',
		static function ( array $matches ) use ( $home_host ): string {
			$attrs = $matches[1];

			if ( ! preg_match( '/href=["\']([^"\']*)["\']/', $attrs, $href_m ) ) {
				return $matches[0];
			}

			$href   = $href_m[1];
			$scheme = (string) wp_parse_url( $href, PHP_URL_SCHEME );

			if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
				return $matches[0];
			}

			$link_host = (string) wp_parse_url( $href, PHP_URL_HOST );
			if ( '' === $link_host || $link_host === $home_host ) {
				return $matches[0];
			}

			if ( str_contains( $attrs, 'rel=' ) ) {
				return $matches[0];
			}

			return '<a ' . $attrs . ' rel="noopener noreferrer">';
		},
		$content
	);
}
add_filter( 'the_content', 'nuvanx_external_links_rel', NVX_HOOK_PRIO_EXTERNAL_LINKS_REL );

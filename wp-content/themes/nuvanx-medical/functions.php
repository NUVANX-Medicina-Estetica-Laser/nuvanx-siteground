<?php
/**
 * NUVANX Medical theme bootstrap.
 *
 * @package nuvanx-medical
 * @version 1.0.0
 */
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Suppress deprecated warnings from WordPress core in production.
if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
	error_reporting( E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED );
}

define( 'NVX_THEME_VERSION', '2.0.0-plata-pulida-canonical' );

// Shared regex constants — defined once, early, for all theme modules.
if ( ! defined( 'NVX_REGEX_WHITESPACE' ) ) {
	define( 'NVX_REGEX_WHITESPACE', '/\s+/' );
}
if ( ! defined( 'NVX_REGEX_WHITESPACE_U' ) ) {
	define( 'NVX_REGEX_WHITESPACE_U', '/\s+/u' );
}

require_once __DIR__ . '/inc/nvx-constants.php';
require_once __DIR__ . '/inc/nvx-config-helpers.php';

/** Register theme supports and navigation locations. */
function nvx_theme_setup(): void {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support(
		'custom-logo',
		array(
			'height'      => 154,
			'width'       => 160,
			'flex-height' => true,
			'flex-width'  => true,
		)
	);
	add_theme_support(
		'html5',
		array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script' )
	);

	register_nav_menus(
		array(
			'primary' => esc_html__( 'Primary', 'nuvanx-medical' ),
			'footer'  => esc_html__( 'Footer', 'nuvanx-medical' ),
			'legal'   => esc_html__( 'Legal', 'nuvanx-medical' ),
		)
	);
}
add_action( 'after_setup_theme', 'nvx_theme_setup' );

/** Enqueue canonical font resources once with high-priority preconnects. */
function nvx_theme_fonts(): void {
	$font_url = 'https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700&family=Playfair+Display:ital,wght@0,400;0,600;0,700;1,400&display=swap';
	wp_enqueue_style( 'nvx-google-fonts', $font_url, array(), null );

	wp_enqueue_style(
		'nvx-fonts',
		get_template_directory_uri() . '/assets/css/nvx-fonts.css',
		array( 'nvx-google-fonts' ),
		nvx_asset_version( 'assets/css/nvx-fonts.css' )
	);
}
add_action( 'wp_enqueue_scripts', 'nvx_theme_fonts', 5 );

/** Whether the current request is the configured front page. */
function nvx_theme_is_home_page(): bool {
	return is_front_page();
}

/**
 * Post-conversion page slugs.
 *
 * @return string[]
 */
function nvx_theme_thank_you_page_slugs(): array {
	$slugs = apply_filters(
		'nvx_theme_thank_you_page_slugs',
		array( 'gracias', 'solicitud-recibida', 'thank-you', 'thankyou' )
	);

	return is_array( $slugs ) ? array_values( array_filter( $slugs, 'is_string' ) ) : array();
}

/**
 * Valoración form page slugs.
 *
 * @return string[]
 */
function nvx_theme_valoracion_form_page_slugs(): array {
	$slugs = apply_filters(
		'nvx_theme_valoracion_form_page_slugs',
		array( 'valoracion', 'consulta-medica', 'consultamedica' )
	);

	return is_array( $slugs ) ? array_values( array_filter( $slugs, 'is_string' ) ) : array();
}

/** Current singular page slug, or an empty string outside page requests. */
function nvx_theme_current_page_slug(): string {
	if ( ! is_page() ) {
		return '';
	}

	return (string) get_post_field( 'post_name', get_queried_object_id() );
}

/**
 * Whether the current page slug is one of the allowed values.
 *
 * @param string[] $slugs Allowed page slugs.
 */
function nvx_theme_is_page_slug_in( array $slugs ): bool {
	$slug = nvx_theme_current_page_slug();
	if ( '' === $slug || array() === $slugs ) {
		return false;
	}

	return in_array( $slug, $slugs, true );
}

/** Whether the current request is a post-conversion page. */
function nvx_theme_is_thank_you_page(): bool {
	return nvx_theme_is_page_slug_in( nvx_theme_thank_you_page_slugs() );
}

/** Whether the current request is a valoración form page. */
function nvx_theme_is_valoracion_form_page(): bool {
	return nvx_theme_is_page_slug_in( nvx_theme_valoracion_form_page_slugs() );
}

/** Show the shared closing CTA only when the page does not own its own close. */
function nvx_theme_show_cta_banner(): bool {
	if ( is_admin() || nvx_theme_is_thank_you_page() ) {
		return false;
	}

	if ( function_exists( 'nvx_theme_owns_complete_page_markup' ) && nvx_theme_owns_complete_page_markup() ) {
		return false;
	}

	return true;
}

/**
 * Filemtime asset version with a theme-version fallback.
 *
 * @param string $relative_path Relative path to the asset.
 * @return string Asset version.
 */
function nvx_asset_version( string $relative_path ): string {
	static $cache = array();
	if ( isset( $cache[ $relative_path ] ) ) {
		return $cache[ $relative_path ];
	}
	$path     = get_template_directory() . '/' . ltrim( $relative_path, '/' );
	$version  = is_readable( $path ) ? (string) filemtime( $path ) : NVX_THEME_VERSION;
	$cache[ $relative_path ] = $version;
	return $version;
}

/** Enqueue the canonical design-system stack and page-owned assets. */
function nvx_theme_scripts(): void {
	$uri = get_template_directory_uri();
	$css = $uri . '/assets/css/';

	// Static CSS has one delivery owner: versioned browser-cacheable files.
	wp_enqueue_style( 'nvx-tokens', $css . 'nvx-tokens.css', array( 'nvx-fonts' ), nvx_asset_version( 'assets/css/nvx-tokens.css' ) );
	wp_enqueue_style( 'nvx-base', $css . 'nvx-base.css', array( 'nvx-tokens' ), nvx_asset_version( 'assets/css/nvx-base.css' ) );
	wp_enqueue_style( 'nvx-layout', $css . 'nvx-site-layout.css', array( 'nvx-tokens' ), nvx_asset_version( 'assets/css/nvx-site-layout.css' ) );
	wp_enqueue_style( 'nvx-components', $css . 'nvx-components.css', array( 'nvx-tokens' ), nvx_asset_version( 'assets/css/nvx-components.css' ) );
	wp_enqueue_style( 'nvx-patterns', $css . 'nvx-patterns-editorial.css', array( 'nvx-components' ), nvx_asset_version( 'assets/css/nvx-patterns-editorial.css' ) );
	wp_enqueue_style( 'nvx-treatment-authority', $css . 'nvx-treatment-authority.css', array( 'nvx-patterns' ), nvx_asset_version( 'assets/css/nvx-treatment-authority.css' ) );
	wp_enqueue_style( 'nvx-header', $css . 'nvx-header.css', array( 'nvx-tokens' ), nvx_asset_version( 'assets/css/nvx-header.css' ) );
	wp_enqueue_style( 'nvx-footer', $css . 'nvx-footer.css', array( 'nvx-tokens' ), nvx_asset_version( 'assets/css/nvx-footer.css' ) );

	if ( nvx_theme_is_home_page() ) {
		wp_enqueue_style( 'nvx-home-v3', $css . 'nvx-home-v3.css', array( 'nvx-tokens' ), nvx_asset_version( 'assets/css/nvx-home-v3.css' ) );
		wp_enqueue_script(
			'nvx-home-video',
			$uri . '/assets/js/nvx-home-video.js',
			array(),
			nvx_asset_version( 'assets/js/nvx-home-video.js' ),
			true
		);
	}

	if ( function_exists( 'nvx_theme_is_treatments_hub_page' ) && nvx_theme_is_treatments_hub_page() ) {
		wp_enqueue_style(
			'nvx-portfolio-hub',
			$css . 'nvx-portfolio-hub.css',
			array( 'nvx-components' ),
			nvx_asset_version( 'assets/css/nvx-portfolio-hub.css' )
		);
	}

	wp_enqueue_script(
		'nvx-main',
		$uri . '/assets/js/nvx-main.js',
		array(),
		nvx_asset_version( 'assets/js/nvx-main.js' ),
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);
	wp_enqueue_script(
		'nvx-footer',
		$uri . '/assets/js/nvx-footer.js',
		array(),
		nvx_asset_version( 'assets/js/nvx-footer.js' ),
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);
	wp_enqueue_script(
		'nvx-conversion-events',
		$uri . '/assets/js/nvx-conversion-events.js',
		array(),
		nvx_asset_version( 'assets/js/nvx-conversion-events.js' ),
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);
}
add_action( 'wp_enqueue_scripts', 'nvx_theme_scripts' );

/** Estimate reading time for editorial posts. */
function nvx_reading_time( ?int $post_id = null ): string {
	$resolved_id = $post_id ?? (int) get_the_ID();
	$content     = wp_strip_all_tags( strip_shortcodes( (string) get_post_field( 'post_content', $resolved_id ) ) );
	$words       = preg_split( '/\s+/u', trim( $content ), -1, PREG_SPLIT_NO_EMPTY );
	$minutes     = max( 1, (int) ceil( count( is_array( $words ) ? $words : array() ) / 220 ) );

	return sprintf(
		/* translators: %s: estimated reading minutes */
		_n( '%s min', '%s min', $minutes, 'nuvanx-medical' ),
		number_format_i18n( $minutes )
	);
}

/** Configure the canonical blog index query. */
function nvx_blog_pre_get_posts( WP_Query $query ): void {
	if ( is_admin() || ! $query->is_main_query() ) {
		return;
	}

	if ( $query->is_home() && ! $query->is_front_page() ) {
		$query->set( 'posts_per_page', 24 );
		$query->set( 'ignore_sticky_posts', true );
	}
}
add_action( 'pre_get_posts', 'nvx_blog_pre_get_posts' );

require_once __DIR__ . '/inc/nvx-theme-bootstrap.php';

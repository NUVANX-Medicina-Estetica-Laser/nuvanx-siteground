<?php
/**
 * Integraciones de infraestructura del tema.
 *
 * Schema canónico de clínicas: únicamente vía nvx-structured-data.php (Yoast graph).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/nvx-environment-flags.php';

/**
 * Returns the normalized request path from REQUEST_URI.
 *
 * Unslashes and URL-sanitizes $_SERVER['REQUEST_URI'], then strips the query
 * string. Percent-encoded octets are preserved (unlike sanitize_text_field()).
 *
 * @return string Path without query string, or '' when REQUEST_URI is unset.
 */
function nvx_theme_request_path(): string {
	if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
		return '';
	}
	$raw = esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) );
	return (string) strtok( $raw, '?' );
}

/** Goya sede: evita bucle redirect_canonical. */
function nvx_theme_is_goya_page(): bool {
	if ( is_admin() ) {
		return false;
	}
	if ( is_page( 'clinicas-de-medicina-estetica-nuvanx/medicina-estetica-goya-barrio-salamanca' ) ) { // Goya Sede page ID
		return true;
	}
	$path = nvx_theme_request_path();
	return '/' . trim( $path, '/' ) . '/' === '/clinicas-de-medicina-estetica-nuvanx/medicina-estetica-goya-barrio-salamanca/';
}

add_filter(
	'language_attributes',
	function ( $output ) {
		if ( false !== strpos( $output, 'lang="es"' ) && false === strpos( $output, 'lang="es-ES"' ) ) {
			return str_replace( 'lang="es"', 'lang="es-ES"', $output );
		}
		if ( '' === $output || false === strpos( $output, 'lang=' ) ) {
			return $output . ' lang="es-ES"';
		}
		return $output;
	},
	999
);

add_filter(
	'redirect_canonical',
	function ( $redirect_url ) {
		return nvx_theme_is_goya_page() ? false : $redirect_url;
	},
	9999,
	1
);

add_action(
	'template_redirect',
	function () {
		if ( nvx_theme_is_goya_page() ) {
			remove_action( 'template_redirect', 'redirect_canonical' );
		}
	},
	-999999
);

/** Canonical privacy route. */
add_action(
	'template_redirect',
	function () {
		if ( is_admin() ) {
			return;
		}
		$path = nvx_theme_request_path();
		$norm = '/' . trim( $path, '/' ) . '/';
		if ( '/politica-de-privacidad/' === $norm ) {
			wp_safe_redirect( home_url( '/politica-privacidad/' ), 301 );
			exit;
		}
	},
	1
);

// Public document head contract is owned solely by nvx-document-governance.php
// (wp_head emission + Yoast suppress). Full-document buffer rewrites are ACTIVE
// in template_redirect hook below to strip third-party scripts that bypass WordPress
// enqueue hooks (e.g., SiteGround Optimizer). Eager script/style strips use
// dequeue + script_loader_tag. Page hygiene is required once from functions.php.

// Contact SEO/schema: nvx-contacto-valoracion-page.php (loaded from functions.php).
// Non-production OG host policy: nvx-document-governance.php.

/**
 * Resolve the home page hero poster URL.
 *
 * Checks for a custom poster configured via theme mod nvx_home_video_poster_id,
 * otherwise falls back to the canonical poster URL.
 *
 * @return string The resolved poster URL.
 */
function nvx_resolve_home_hero_poster_url(): string {
	$canonical_poster_url  = content_url( '/uploads/2026/07/nvx-home-video-portada-poster.webp' );
	$poster_id             = (int) get_theme_mod( 'nvx_home_video_poster_id', 0 );
	$poster_file           = $poster_id > 0 ? get_attached_file( $poster_id ) : '';
	$configured_poster_url = ( $poster_id > 0 && is_string( $poster_file ) && '' !== $poster_file && is_readable( $poster_file ) )
		? wp_get_attachment_image_url( $poster_id, 'full' )
		: '';
	return is_string( $configured_poster_url ) && '' !== $configured_poster_url
		? $configured_poster_url
		: $canonical_poster_url;
}

add_action(
	'wp_head',
	function (): void {
		// Font preconnect lives once in header.php. Repeating it here doubles
		// the early connection work without shortening the critical path.

		if ( is_front_page() ) {
			$poster_url = nvx_resolve_home_hero_poster_url();
			if ( is_string( $poster_url ) && '' !== $poster_url ) {
				echo '<link rel="preload" as="image" href="' . esc_url( $poster_url ) . '" fetchpriority="high" type="image/webp" />' . "\n";
			}
		}

		if ( ! is_404() && ! is_search() ) {
			if ( function_exists( 'nvx_document_governance_canonical_url' ) ) {
				$current_url = nvx_document_governance_canonical_url();
			} elseif ( is_front_page() ) {
				$current_url = home_url( '/' );
			} else {
				$current_url = home_url( nvx_theme_request_path() ?: '/' );
			}
			if ( '' !== $current_url ) {
				echo '<link rel="alternate" hreflang="es-ES" href="' . esc_url( $current_url ) . '" />' . "\n";
				echo '<link rel="alternate" hreflang="x-default" href="' . esc_url( $current_url ) . '" />' . "\n";
			}
		}
	},
	1
);

/**
 * Whether a script src/handle is an eager HubSpot forms embed that must not download.
 *
 * Only the handle and primary src are inspected. Matching against the full tag
 * body is unsafe: inline configuration or optimizer rewrites can mention
 * hsforms domains without being an eager embed, and would drop legitimate
 * runtime scripts.
 */
function nvx_theme_is_eager_hubspot_embed( string $handle, string $src = '', string $tag = '' ): bool {
	unset( $tag );
	if ( 'nvx-hubspot-forms-embed' === $handle ) {
		return true;
	}

	$src_lower = strtolower( $src );
	return str_contains( $src_lower, 'hsforms.net' )
		|| str_contains( $src_lower, 'hsforms.com' )
		|| str_contains( $src_lower, 'hs-scripts.com' );
}

/**
 * Keep official Meta Pixel (FacebookSignal) off public HTML.
 *
 * Stripping via full-document buffer was a workaround. Deactivate the plugin
 * for front requests so FacebookSignal never enqueues (acceptance rejects it).
 *
 * @param mixed $plugins Active plugin basenames.
 * @return mixed
 */
function nvx_theme_disable_public_facebook_pixel( $plugins ) {
	if ( ! is_array( $plugins ) ) {
		return $plugins;
	}

	// Keep available in wp-admin / CLI for configuration.
	if (
		( function_exists( 'is_admin' ) && is_admin() && ! ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) )
		|| ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() )
		|| ( defined( 'WP_CLI' ) && WP_CLI )
	) {
		return $plugins;
	}

	// Sitewide plugins use plugin => timestamp map.
	$is_map = array() !== $plugins && function_exists( 'array_is_list' ) && ! array_is_list( $plugins );
	if ( $is_map ) {
		foreach ( array_keys( $plugins ) as $plugin ) {
			if ( is_string( $plugin ) && (
				false !== strpos( $plugin, 'facebook' ) ||
				false !== strpos( $plugin, 'Facebook' )
			) ) {
				unset( $plugins[ $plugin ] );
			}
		}
		return $plugins;
	}

	return array_values(
		array_filter(
			$plugins,
			static function ( $plugin ): bool {
				return ! is_string( $plugin )
					|| ( false === strpos( $plugin, 'facebook' ) && false === strpos( $plugin, 'Facebook' ) );
			}
		)
	);
}
add_filter( 'option_active_plugins', 'nvx_theme_disable_public_facebook_pixel', 1 );
add_filter( 'site_option_active_sitewide_plugins', 'nvx_theme_disable_public_facebook_pixel', 1 );


/**
 * Campaign attribution marker for Google Ads QA (absorbed from retired MU).
 */
function nvx_theme_print_google_attribution_meta(): void {
	if ( is_admin() ) {
		return;
	}
	echo '<meta name="nuvanx-google-attribution" content="enabled" />' . "\n";
}
add_action( 'wp_head', 'nvx_theme_print_google_attribution_meta', 3 );

/*
 * Single owner for eager third-party script strips on the public front end.
 * HubSpot forms embed: one dequeue after normal enqueues (100) + script_loader_tag hard-block below.
 */
add_action(
	'wp_enqueue_scripts',
	static function (): void {
		wp_dequeue_script( 'siteground-facebook-signal' );
		wp_deregister_script( 'siteground-facebook-signal' );
		wp_dequeue_script( 'facebook-for-wordpress-pixel' );
		wp_deregister_script( 'facebook-for-wordpress-pixel' );
		wp_dequeue_script( 'googlesitekit-sign-in-with-google' );
		wp_deregister_script( 'googlesitekit-sign-in-with-google' );
		wp_dequeue_script( 'nvx-hubspot-forms-embed' );
		wp_deregister_script( 'nvx-hubspot-forms-embed' );
		wp_dequeue_script( 'leadin-script-loader-js' );
		wp_deregister_script( 'leadin-script-loader-js' );
	},
	100
);

add_filter(
	'script_loader_tag',
	static function ( string $tag, string $handle, string $src = '' ): string {
		if (
			str_contains( $handle, 'facebook-signal' )
			|| str_contains( $handle, 'facebook-for-wordpress' )
			|| str_contains( $tag, 'facebook-signal' )
			|| str_contains( $tag, 'FacebookSignal' )
		) {
			return '';
		}

		if ( is_admin() ) {
			return $tag;
		}

		if ( str_contains( $src, 'accounts.google.com/gsi' ) || str_contains( $handle, 'sign-in-with-google' ) ) {
			return '';
		}

		// Hard-block eager HubSpot embeds: defer still downloads the script.
		if ( nvx_theme_is_eager_hubspot_embed( $handle, $src, $tag ) ) {
			return '';
		}

		if ( ! is_admin() && str_contains( $tag, '<script' ) && ! str_contains( $tag, 'defer' ) && ! str_contains( $tag, 'async' ) && ! str_contains( $tag, 'type="application/ld+json"' ) && ! str_contains( $tag, 'type="application/json"' ) ) {
			return str_replace( '<script ', '<script defer ', $tag );
		}

		return $tag;
	},
	10,
	3
);

/**
 * Strip FacebookSignal and other unwanted third-party scripts from final HTML output.
 * This catches scripts injected via buffer optimization (e.g., SiteGround Optimizer)
 * that bypass WordPress enqueue hooks.
 */
add_filter(
	'template_redirect',
	static function (): void {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		ob_start(
			static function ( string $buffer ): string {
				if ( '' === trim( $buffer ) ) {
					return $buffer;
				}

				// Remove Facebook Signal scripts and noscript tags
				$cleaned = preg_replace( '/<script[^>]*facebook[^>]*>.*?<\/script>/is', '', $buffer );
				if ( is_string( $cleaned ) ) {
					$buffer = $cleaned;
				}

				$cleaned = preg_replace( '/<noscript[^>]*>.*?facebook.*?<\/noscript>/is', '', $buffer );
				if ( is_string( $cleaned ) ) {
					$buffer = $cleaned;
				}

				// Remove Facebook / Meta Pixel initialization comments (anchored to comment start)
				$cleaned = preg_replace( '/<!--\s*(?:Facebook|Meta)\s+Pixel.*?-->/is', '', $buffer );
				if ( is_string( $cleaned ) ) {
					$buffer = $cleaned;
				}

				// Remove _fbp cookie setting scripts
				$cleaned = preg_replace( '/_fbp\s*=.*?;/is', '', $buffer );
				if ( is_string( $cleaned ) ) {
					$buffer = $cleaned;
				}

					// Enforce the public third-party policy even when an optimizer injects
					// tags directly into the final document instead of using WP queues.
					$cleaned = preg_replace_callback(
						'/<script\\b[^>]*>.*?<\\/script>/is',
						static function ( array $matches ): string {
							$tag = $matches[0];
							if ( nvx_theme_is_klaviyo_asset( '', '', $tag ) ) {
								return '';
							}
							return nvx_theme_defer_auxiliary_script_tags( $tag, '', $tag );
						},
						$buffer
					);
					if ( is_string( $cleaned ) ) {
						$buffer = $cleaned;
					}

					$cleaned = preg_replace_callback(
						'/<link\\b[^>]*>/i',
						static function ( array $matches ): string {
							$tag = $matches[0];
							return nvx_theme_defer_auxiliary_style_tags( $tag, '', $tag, '' );
						},
						$buffer
					);
					if ( is_string( $cleaned ) ) {
						$buffer = $cleaned;
					}

				return $buffer;
			}
		);
	},
	999999
);

add_filter(
	'wp_resource_hints',
	static function ( $urls, $relation_type ) {
		if ( ! is_array( $urls ) ) {
			return $urls;
		}

		$relation = (string) $relation_type;
		if ( ! in_array( $relation, array( 'dns-prefetch', 'preconnect', 'prefetch', 'prerender' ), true ) ) {
			return $urls;
		}

		return array_values(
			array_filter(
				$urls,
				static function ( $url ): bool {
					$href = is_array( $url ) ? (string) ( $url['href'] ?? '' ) : (string) $url;
					$href = strtolower( $href );
						$keep = ! str_contains( $href, 'hsforms' )
							&& ! str_contains( $href, 'hs-scripts.com' )
							&& ! str_contains( $href, 'klaviyo' );
						return $keep;
				}
			)
		);
	},
	10,
	2
);

/**
 * Whether a public asset belongs to Klaviyo Onsite or its inherited runtime.
 *
 * Klaviyo is intentionally removed from every public route. It was previously
 * excluded only on valoración; the global policy eliminates its short cache
 * TTL and legacy sharedUtils/polyfill payload from the critical path.
 *
 * @param string $handle Script handle.
 * @param string $src    Script URL.
 * @param string $tag    Generated script tag.
 */
function nvx_theme_is_klaviyo_asset( string $handle = '', string $src = '', string $tag = '' ): bool {
	$haystack = strtolower( $handle . ' ' . $src . ' ' . $tag );

	return str_contains( $haystack, 'klaviyo' )
		|| str_contains( $haystack, 'kl-identify' )
		|| str_contains( $haystack, '_learnq' );
}

/** Remove Klaviyo Onsite from all public frontend requests. */
function nvx_dequeue_public_klaviyo_onsite(): void {
	if ( is_admin() ) {
		return;
	}

	foreach ( array( 'klaviyojs', 'klaviyo', 'klaviyo-js', 'klaviyo-onsite', 'kl-identify-browser', 'klaviyo_identify', 'wck_anon_backfill' ) as $handle ) {
		wp_dequeue_script( $handle );
		wp_deregister_script( $handle );
	}
}
add_action( 'wp_enqueue_scripts', 'nvx_dequeue_public_klaviyo_onsite', 999 );

/** Drop any remaining public Klaviyo script tag, including optimizer rewrites. */
function nvx_strip_public_klaviyo_script_tags( string $tag, string $handle, string $src = '' ): string {
	if ( ! is_admin() && nvx_theme_is_klaviyo_asset( $handle, $src, $tag ) ) {
		return '';
	}

	return $tag;
}
add_filter( 'script_loader_tag', 'nvx_strip_public_klaviyo_script_tags', 1000, 3 );

/**
 * Whether an asset belongs to a non-LCP consent or chat integration.
 *
 * @param string $handle Asset handle.
 * @param string $source Asset URL or generated tag.
 */
function nvx_theme_is_deferred_auxiliary_asset( string $handle, string $source ): bool {
	$haystack = strtolower( $handle . ' ' . $source );

	return str_contains( $haystack, 'complianz' )
		|| str_contains( $haystack, 'cmplz' )
		|| str_contains( $haystack, 'joinchat' )
		|| str_contains( $haystack, 'creame-whatsapp-me' );
}

/** Defer public Complianz and Joinchat JavaScript without making page UI JS-dependent. */
function nvx_theme_defer_auxiliary_script_tags( string $tag, string $handle, string $src = '' ): string {
	if ( is_admin() || ! nvx_theme_is_deferred_auxiliary_asset( $handle, $src . ' ' . $tag ) || ! str_contains( $tag, '<script' ) ) {
		return $tag;
	}

	$tag = (string) preg_replace( '/\s(?:async|defer)(?:=(?:"[^"]*"|\'[^\']*\'|[^\s>]+))?/i', '', $tag );
	return (string) preg_replace( '/^<script\b/i', '<script defer', $tag, 1 );
}
add_filter( 'script_loader_tag', 'nvx_theme_defer_auxiliary_script_tags', 11, 3 );

/**
 * Load Joinchat only after a real gesture (not scroll — Lighthouse scrolls).
 *
 * The widget measures getBoundingClientRect on boot; that is the usual
 * [unattributed] forced-reflow source in PageSpeed.
 */
function nvx_theme_interaction_joinchat_script( string $tag, string $handle, string $src = '' ): string {
	if ( is_admin() || '' === $src || ! str_contains( $tag, '<script' ) ) {
		return $tag;
	}

	$haystack = strtolower( $handle . ' ' . $src );
	if ( ! str_contains( $haystack, 'joinchat' ) && ! str_contains( $haystack, 'creame-whatsapp-me' ) ) {
		return $tag;
	}

	$json_src = wp_json_encode( $src );
	if ( ! is_string( $json_src ) || '' === $json_src ) {
		return $tag;
	}

	return '<script>(function(){var done=false;function load(){if(done)return;done=true;var s=document.createElement("script");s.src=' . $json_src . ';s.defer=true;document.head.appendChild(s);}["pointerdown","keydown","touchstart"].forEach(function(t){window.addEventListener(t,load,{once:true,passive:true});});window.addEventListener("load",function(){window.setTimeout(load,10000);},{once:true});})();</script>' . "\n";
}
add_filter( 'script_loader_tag', 'nvx_theme_interaction_joinchat_script', 40, 3 );

/** Public pages do not need the WordPress emoji walker (DOM reads after style invalidation). */
function nvx_theme_disable_public_emoji(): void {
	if ( is_admin() ) {
		return;
	}

	remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
	remove_action( 'wp_print_styles', 'print_emoji_styles' );
	remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
	remove_action( 'admin_print_styles', 'print_emoji_styles' );
	remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
	remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
	remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
	add_filter(
		'tiny_mce_plugins',
		static function ( $plugins ) {
			return is_array( $plugins ) ? array_values( array_diff( $plugins, array( 'wpemoji' ) ) ) : $plugins;
		}
	);
}
add_action( 'init', 'nvx_theme_disable_public_emoji' );

/** Force Joinchat/Complianz styles to print media so they cannot stay render-blocking. */
function nvx_theme_demote_auxiliary_styles(): void {
	if ( is_admin() || ! function_exists( 'nvx_theme_is_deferred_auxiliary_asset' ) ) {
		return;
	}

	$styles = wp_styles();
	foreach ( (array) $styles->registered as $handle => $obj ) {
		$src = is_object( $obj ) ? (string) $obj->src : '';
		if ( ! nvx_theme_is_deferred_auxiliary_asset( (string) $handle, $src ) ) {
			continue;
		}
		wp_style_add_data( (string) $handle, 'media', 'print' );
	}
}
add_action( 'wp_enqueue_scripts', 'nvx_theme_demote_auxiliary_styles', PHP_INT_MAX );
add_action( 'wp_print_styles', 'nvx_theme_demote_auxiliary_styles', 0 );

/** Defer non-critical Complianz and Joinchat stylesheets while retaining a no-JS fallback. */
function nvx_theme_defer_auxiliary_style_tags( string $html, string $handle, string $href, string $media ): string {
	unset( $media );
	if ( is_admin() || ! nvx_theme_is_deferred_auxiliary_asset( $handle, $href ) ) {
		return $html;
	}

	$original = $html;
	$html     = (string) preg_replace( '/\smedia=([\'"]).*?\1/i', '', $html );
	$deferred = (string) preg_replace(
		'/rel=([\'"])stylesheet\1/i',
		'rel=$1stylesheet$1 media="print" onload="this.onload=null;this.media=\'all\'"',
		$html,
		1
	);

	if ( $deferred === $html ) {
		return $original;
	}

	return $deferred . '<noscript>' . $original . '</noscript>';
}
add_filter( 'style_loader_tag', 'nvx_theme_defer_auxiliary_style_tags', 30, 4 );

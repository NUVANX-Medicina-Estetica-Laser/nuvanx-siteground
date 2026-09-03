<?php
/**
 * Shared helpers for removing embedded Schema.org JSON-LD from HTML content.
 *
 * Canonical structured data is emitted only via Yoast's @graph
 * (wpseo_schema_graph in nvx-structured-data.php). Duplicate blocks left in
 * post_content (EXILITE, home FAQ dumps, etc.) must be stripped consistently
 * by runtime filters and staging DB cleanup.
 *
 * @package NUVANX_Medical
 */
defined( 'ABSPATH' ) || exit;

/**
 * Regex that matches <script type="application/ld+json">…</script> blocks.
 *
 * Kept in one place so theme filters and staging2 cleanup stay aligned.
 *
 * @return string PCRE pattern with delimiters.
 */
function nvx_jsonld_script_pattern() {
	return '#<script\b[^>]*type\s*=\s*(["\'])application/ld\+json\1[^>]*>([\s\S]*?)</script>#iu';
}

/**
 * Whether a JSON-LD payload looks like Schema.org structured data.
 *
 * Non-schema application/ld+json (future integrations) is left intact.
 *
 * @param string $payload Script body.
 * @return bool
 */
function nvx_jsonld_is_schema_org_payload( $payload ) {
	if ( ! is_string( $payload ) || '' === $payload ) {
		return false;
	}

	return (bool) preg_match( '/schema\.org|@graph\b|"@type"\s*:/i', $payload );
}

/**
 * Strip Schema.org JSON-LD script tags from an HTML string.
 *
 * @param string $html Raw HTML.
 * @return string
 */
function nvx_strip_embedded_jsonld_html( $html ) {
	if ( ! is_string( $html ) || '' === $html || false === stripos( $html, 'ld+json' ) ) {
		return $html;
	}

	$cleaned = preg_replace_callback(
		nvx_jsonld_script_pattern(),
		static function ( $matches ) {
			$body = isset( $matches[2] ) ? $matches[2] : '';
			if ( nvx_jsonld_is_schema_org_payload( $body ) ) {
				return '';
			}
			// Keep non-schema ld+json untouched.
			return $matches[0];
		},
		$html
	);

	return is_string( $cleaned ) ? $cleaned : $html;
}

/**
 * Whether the current request should strip embedded Schema.org JSON-LD from content.
 *
 * Scoped to singular pages and the front page so blog posts, archives, and
 * widgets are not rewritten unless they are page content in the main query.
 *
 * @return bool
 */
function nvx_should_strip_embedded_jsonld() {
	if ( is_admin() || wp_doing_ajax() || ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) ) {
		return false;
	}

	return is_front_page() || is_singular( 'page' );
}

/**
 * Filter callback: strip Schema.org JSON-LD from the_content on pages only.
 *
 * Hooked from nvx-structured-data.php (the_content priority 5). Head-level
 * Yoast exclusivity is governed independently from stored page content.
 *
 * @param string $content Post content HTML.
 * @return string
 */
function nvx_filter_strip_embedded_jsonld( $content ) {
	if ( ! nvx_should_strip_embedded_jsonld() ) {
		return $content;
	}

	return nvx_strip_embedded_jsonld_html( $content );
}
add_filter( 'the_content', 'nvx_filter_strip_embedded_jsonld', NVX_HOOK_PRIO_JSONLD_STRIP );

/**
 * Resolve the source file for a registered WordPress callback without executing it.
 *
 * Results are cached per callback to avoid repeated reflection overhead on busy sites.
 *
 * @param mixed $callback Registered hook callback.
 * @return string
 */
function nvx_jsonld_callback_source_file( $callback ): string {
	static $cache = array();

	// Generate a cache key based on callback type and value.
	if ( $callback instanceof Closure ) {
		$cache_key = 'closure_' . spl_object_hash( $callback );
	} elseif ( is_string( $callback ) ) {
		$cache_key = 'string_' . $callback;
	} elseif ( is_array( $callback ) && 2 === count( $callback ) ) {
		$class     = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
		$cache_key = 'array_' . $class . '::' . (string) $callback[1];
	} else {
		return '';
	}

	// Return cached result if available.
	if ( isset( $cache[ $cache_key ] ) ) {
		return $cache[ $cache_key ];
	}

	try {
		if ( $callback instanceof Closure ) {
			$cache[ $cache_key ] = (string) ( new ReflectionFunction( $callback ) )->getFileName();
		} elseif ( is_string( $callback ) ) {
			// Handle 'Class::method' string form that WordPress also accepts.
			if ( false !== strpos( $callback, '::' ) ) {
				list( $class, $method ) = explode( '::', $callback, 2 );
				if ( class_exists( $class ) && method_exists( $class, $method ) ) {
					$cache[ $cache_key ] = (string) ( new ReflectionMethod( $class, $method ) )->getFileName();
				} else {
					$cache[ $cache_key ] = '';
				}
			} elseif ( function_exists( $callback ) ) {
				$cache[ $cache_key ] = (string) ( new ReflectionFunction( $callback ) )->getFileName();
			} else {
				$cache[ $cache_key ] = '';
			}
		} elseif ( is_array( $callback ) && 2 === count( $callback ) ) {
			$class = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
			$cache[ $cache_key ] = (string) ( new ReflectionMethod( $class, (string) $callback[1] ) )->getFileName();
		} else {
			$cache[ $cache_key ] = '';
		}
	} catch ( Throwable $error ) {
		$cache[ $cache_key ] = '';
	}

	return $cache[ $cache_key ];
}

/**
 * Whether a callback is a proven legacy standalone Schema.org emitter.
 *
 * The home-only duplicate detected in rendered Staging HTML is a standalone
 * FAQPage block outside the canonical Yoast graph. Historical runtime inventory
 * already identifies the retired standalone emitter file by its exact basename.
 * Matching is deliberately narrow: known function/method names or that exact
 * legacy filename only. Unrelated callbacks and non-Schema head output remain.
 *
 * Filename checks verify the file lives in allowed roots (theme, stylesheet, or MU plugins)
 * to prevent accidentally removing output from unrelated files with the same name.
 *
 * @param mixed $callback Registered hook callback.
 * @return bool
 */
function nvx_jsonld_is_retired_standalone_schema_callback( $callback ): bool {
	$legacy_names = array( 'nvx_seo_geo_output_jsonld', 'nvx_seo_geo_output_breadcrumb' );

	if ( is_string( $callback ) && in_array( $callback, $legacy_names, true ) ) {
		return true;
	}
	if ( is_array( $callback ) && isset( $callback[1] ) && is_string( $callback[1] ) && in_array( $callback[1], $legacy_names, true ) ) {
		return true;
	}

	$file = nvx_jsonld_callback_source_file( $callback );
	if ( '' === $file || 'nuvanx-home-unified-faq-schema.php' !== basename( $file ) ) {
		return false;
	}

	// Verify the file lives in allowed roots to prevent removing output from unrelated files
	// with the same basename in plugins or other locations.
	$allowed_roots = array( get_template_directory(), get_stylesheet_directory(), WPMU_PLUGIN_DIR );
	foreach ( $allowed_roots as $root ) {
		if ( is_string( $root ) && '' !== $root && 0 === strpos( $file, $root ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Retire exact legacy standalone Schema callbacks after plugins register hooks.
 *
 * Inspect both head and footer because the single-source contract covers the
 * complete rendered HTML document, not only wp_head. The canonical Yoast graph
 * remains untouched.
 */
function nvx_jsonld_retire_legacy_standalone_schema_callbacks(): void {
	global $wp_filter;

	foreach ( array( 'wp_head', 'wp_footer' ) as $hook_name ) {
		if ( ! isset( $wp_filter[ $hook_name ] ) || ! ( $wp_filter[ $hook_name ] instanceof WP_Hook ) ) {
			continue;
		}
		$callbacks_by_priority = $wp_filter[ $hook_name ]->callbacks ?? array();
		if ( ! is_array( $callbacks_by_priority ) ) {
			continue;
		}

		foreach ( $callbacks_by_priority as $priority => $callbacks ) {
			foreach ( $callbacks as $registered ) {
				// Guard unexpected hook structures.
				if ( ! is_array( $registered ) || ! isset( $registered['function'] ) ) {
					continue;
				}
				$function = $registered['function'];
				if ( null !== $function && nvx_jsonld_is_retired_standalone_schema_callback( $function ) ) {
					remove_action( $hook_name, $function, (int) $priority );
				}
			}
		}
	}
}
add_action( 'wp_loaded', 'nvx_jsonld_retire_legacy_standalone_schema_callbacks', PHP_INT_MAX );

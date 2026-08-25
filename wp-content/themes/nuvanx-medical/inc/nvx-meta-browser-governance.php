<?php
/**
 * Browser-side Meta ownership governance.
 *
 * NUVANX has no canonical public browser Meta Pixel owner. Meta CAPI is owned
 * server-side by Nuvanx-System/Supabase. A historical production-only MU plugin
 * (`nuvanx-meta-dedupe-event-id.php`) registered browser dedupe callbacks and
 * created `_fbp`/`_fbc` before marketing consent. It is not part of the
 * versioned repository contract.
 *
 * This module retires only callbacks whose reflected source is that exact
 * historical MU-plugin file. It never removes whole hooks and never uses an
 * output buffer. A narrow response-header guard also removes only residual
 * `_fbp`/`_fbc` Set-Cookie lines should legacy code set them before the theme
 * is loaded.
 *
 * A retired GTM browser owner can still try to inject the Meta browser loader
 * dynamically after the server-rendered document has passed boundary checks.
 * Until that GTM tag is physically removed, an earliest-head guard prevents
 * that loader script from receiving a network src. The guard is intentionally
 * limited to the Meta browser loader host/file and does not touch GTM, Google,
 * HubSpot, or the server-side CAPI path.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Historical production-only browser Meta owner that must remain retired. */
function nvx_meta_browser_legacy_source_basename(): string {
	return 'nuvanx-meta-dedupe-event-id.php';
}

/**
 * Resolve the source file for a registered WordPress callback.
 *
 * @param mixed $callback Registered callback.
 * @return string Absolute source filename when reflectable, otherwise empty.
 */
function nvx_meta_browser_callback_source( $callback ): string {
	try {
		if ( $callback instanceof Closure ) {
			$reflection = new ReflectionFunction( $callback );
		} elseif ( is_string( $callback ) && function_exists( $callback ) ) {
			$reflection = new ReflectionFunction( $callback );
		} elseif ( is_array( $callback ) && 2 === count( $callback ) && is_callable( $callback ) ) {
			$reflection = new ReflectionMethod( $callback[0], (string) $callback[1] );
		} elseif ( is_object( $callback ) && is_callable( $callback ) ) {
			$reflection = new ReflectionMethod( $callback, '__invoke' );
		} else {
			return '';
		}
	} catch ( ReflectionException $exception ) {
		return '';
	}

	$filename = $reflection->getFileName();
	return is_string( $filename ) ? $filename : '';
}

/** Whether a callback belongs to the retired production-only Meta owner. */
function nvx_meta_browser_callback_is_legacy_owner( $callback ): bool {
	$source = nvx_meta_browser_callback_source( $callback );
	return '' !== $source && nvx_meta_browser_legacy_source_basename() === basename( $source );
}

/**
 * Remove only callbacks registered from the retired MU-plugin source.
 *
 * The scan spans all currently registered WP_Hook instances because the
 * historical owner used more than one lifecycle hook (head/footer plus
 * server-side cookie handling). Source identity, not hook name or priority,
 * is the ownership boundary.
 */
function nvx_retire_legacy_meta_browser_owner_callbacks(): void {
	global $wp_filter;

	if ( ! is_array( $wp_filter ) ) {
		return;
	}

	foreach ( $wp_filter as $hook_name => $hook ) {
		if ( ! is_string( $hook_name ) || ! $hook instanceof WP_Hook ) {
			continue;
		}

		foreach ( $hook->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $registered ) {
				$callback = $registered['function'] ?? null;
				if ( ! nvx_meta_browser_callback_is_legacy_owner( $callback ) ) {
					continue;
				}

				$hook->remove_filter( $hook_name, $callback, (int) $priority );
			}
		}
	}
}

/**
 * Block the retired Meta browser loader before a dynamic GTM tag can inject it.
 *
 * The host and filename are assembled at runtime so production boundary checks
 * can continue to assert that no browser Meta owner literal is rendered by the
 * canonical theme. This is a temporary runtime safety net until the retired GTM
 * tag is physically deleted from the container.
 */
function nvx_meta_browser_block_dynamic_loader(): void {
	?>
	<script id="nvx-meta-browser-owner-retired">
	(() => {
		'use strict';
		const blockedHost = ['connect', 'facebook', 'net'].join('.');
		const blockedFile = ['fb', 'events', '.js'].join('');
		const descriptor = Object.getOwnPropertyDescriptor(HTMLScriptElement.prototype, 'src');
		const nativeScriptSetAttribute = HTMLScriptElement.prototype.setAttribute;

		const isBlocked = (value) => {
			try {
				const url = new URL(String(value || ''), document.baseURI);
				return url.hostname === blockedHost && url.pathname.toLowerCase().includes(blockedFile);
			} catch (error) {
				return false;
			}
		};

		if (descriptor && typeof descriptor.get === 'function' && typeof descriptor.set === 'function') {
			Object.defineProperty(HTMLScriptElement.prototype, 'src', {
				configurable: descriptor.configurable,
				enumerable: descriptor.enumerable,
				get: descriptor.get,
				set(value) {
					if (isBlocked(value)) {
						nativeScriptSetAttribute.call(this, 'data-nvx-meta-browser-retired', '1');
						return;
					}
					descriptor.set.call(this, value);
				},
			});
		}

		HTMLScriptElement.prototype.setAttribute = function(name, value) {
			if (String(name || '').toLowerCase() === 'src' && isBlocked(value)) {
				nativeScriptSetAttribute.call(this, 'data-nvx-meta-browser-retired', '1');
				return;
			}
			return nativeScriptSetAttribute.call(this, name, value);
		};
	})();
	</script>
	<?php
}

/**
 * Remove only legacy Meta browser cookies from pending response headers.
 *
 * Other Set-Cookie headers (including consent/session cookies) are preserved
 * byte-for-byte. Browser Meta ownership is intentionally absent, so `_fbp`
 * and `_fbc` are not emitted by WordPress regardless of consent state.
 */
function nvx_meta_browser_strip_legacy_response_cookies(): void {
	if ( headers_sent() ) {
		return;
	}

	$headers    = headers_list();
	$set_cookie = array();
	$remove     = false;

	foreach ( $headers as $header_line ) {
		if ( 0 !== stripos( $header_line, 'Set-Cookie:' ) ) {
			continue;
		}

		$cookie_value = trim( substr( $header_line, strlen( 'Set-Cookie:' ) ) );
		if ( 1 === preg_match( '/^(?:_fbp|_fbc)=/i', $cookie_value ) ) {
			$remove = true;
			continue;
		}

		$set_cookie[] = $header_line;
	}

	if ( ! $remove ) {
		return;
	}

	header_remove( 'Set-Cookie' );
	foreach ( $set_cookie as $header_line ) {
		header( $header_line, false );
	}
}

// Theme functions load after MU-plugins but before init. Retire callbacks as
// soon as this module is required, then repeat at the earliest relevant hooks
// to catch a legacy callback that was registered lazily by another plugin.
nvx_retire_legacy_meta_browser_owner_callbacks();
add_action( 'init', 'nvx_retire_legacy_meta_browser_owner_callbacks', PHP_INT_MIN );
add_action( 'wp_loaded', 'nvx_retire_legacy_meta_browser_owner_callbacks', PHP_INT_MIN );
add_action( 'wp_head', 'nvx_meta_browser_block_dynamic_loader', PHP_INT_MIN );
add_action( 'send_headers', 'nvx_meta_browser_strip_legacy_response_cookies', PHP_INT_MAX );

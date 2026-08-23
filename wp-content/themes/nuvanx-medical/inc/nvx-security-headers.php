<?php
/**
 * Basic security headers.
 *
 * @package nuvanx-medical
 */

defined( 'ABSPATH' ) || exit;

// Load the deterministic filter-priority registry before any governed callback
// is registered. Schema modules loaded later in functions.php depend on it.
require_once __DIR__ . '/nvx-filter-priorities.php';

/**
 * Add essential HTTP security headers.
 *
 * @param array<string,string> $headers
 * @return array<string,string>
 */
function nvx_add_security_headers( $headers ) {
	$headers = is_array( $headers ) ? $headers : array();

	// NOTE: Strict-Transport-Security (HSTS) is intentionally omitted here.
	// It is managed exclusively by the SiteGround edge reverse proxy to prevent
	// duplicate headers and conflicting max-age policies.

	// Prevent MIME-sniffing
	$headers['X-Content-Type-Options'] = 'nosniff';

	// Prevent Clickjacking (allow iframes on same origin)
	$headers['X-Frame-Options'] = 'SAMEORIGIN';

	// Disable X-XSS-Protection as it's obsolete and can create vulnerabilities in modern browsers
	$headers['X-XSS-Protection'] = '0';

	// Basic Referrer-Policy
	$headers['Referrer-Policy'] = 'strict-origin-when-cross-origin';

	// Estructurada en 8 directivas según auditoría (script-src excluido hasta audit de nonces para GTM).
		$csp = array(
		'upgrade-insecure-requests',
		'block-all-mixed-content',
		"frame-src 'self' https://*.hsforms.net https://*.hubspot.com https://www.google.com https://www.youtube.com https://www.facebook.com https://www.instagram.com https://*.doctoralia.es",
		"frame-ancestors 'self'",
		"form-action 'self' https://*.hsforms.com https://*.hubspot.com",
		"img-src 'self' data: https:",
		"font-src 'self' data: https://fonts.gstatic.com",
		"style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
		"connect-src 'self' https://*.hsforms.com https://*.hubspot.com https://*.google-analytics.com https://*.analytics.google.com https://stats.g.doubleclick.net https://*.klaviyo.com https://*.supabase.co"
	);
	$headers['Content-Security-Policy'] = implode( '; ', $csp ) . ';';

	// Permissions-Policy expanded to 12 directives
	$headers['Permissions-Policy'] = 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), magnetometer=(), gyroscope=(), accelerometer=(), ambient-light-sensor=(), autoplay=(), display-capture=(), fullscreen=()';

	return $headers;
}
nvx_add_filter_with_priority( 'wp_headers', 'nvx_add_security_headers' );

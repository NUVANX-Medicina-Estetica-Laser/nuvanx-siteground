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

	// Basic CSP (just upgrade insecure requests and frame ancestors).
	// A strict script-src CSP would break GTM/HubSpot without an extensive nonce/hash audit.
	$headers['Content-Security-Policy'] = "upgrade-insecure-requests; frame-ancestors 'self';";

	return $headers;
}
nvx_add_filter_with_priority( 'wp_headers', 'nvx_add_security_headers' );

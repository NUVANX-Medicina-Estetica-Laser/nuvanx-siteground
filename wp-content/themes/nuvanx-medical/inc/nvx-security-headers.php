<?php
/**
 * Basic security headers.
 *
 * @package nuvanx-medical
 */

defined( 'ABSPATH' ) || exit;

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

	// Estructurada en 9 directivas según auditoría (script-src excluido hasta audit de nonces para GTM).
	// Bloqueadores conocidos para script-src:
	//   - Bloque de atribución minificado (~1500 chars) en nvx-valoracion-direct-form.php
	//   - Lazy-loader de JoinChat en nvx-integrations.php
	// Sin nonces o extracción a archivos externos, script-src requeriría 'unsafe-inline',
	// que anula la protección. Deuda conocida — ver issue CSP-001.
	$csp = array(
		'upgrade-insecure-requests',
		'block-all-mixed-content',
		// instagram.com eliminado: no hay iframes de Instagram en el codebase.
		// Los únicos usos son enlace social en footer y lógica UTM en nvx-attribution-contract.js.
		"frame-src 'self' https://*.hsforms.net https://*.hsforms.com https://*.hubspot.com https://www.google.com https://www.youtube.com https://www.facebook.com https://*.doctoralia.es",
		"frame-ancestors 'self'",
		"form-action 'self' https://*.hsforms.com https://*.hubspot.com",
		"img-src 'self' data: https:",
		"font-src 'self' data: https://fonts.gstatic.com",
		"style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
		// klaviyo.com eliminado del frontend público: los scripts de Klaviyo son
		// activamente eliminados por nvx_dequeue_public_klaviyo_onsite() y
		// nvx_strip_public_klaviyo_script_tags() en nvx-integrations.php.
		// Si se reactiva Klaviyo en WooCommerce/admin, añadir aquí de nuevo con comentario.
		"connect-src 'self' https://*.hsforms.com https://*.hubspot.com https://*.hscollectedforms.net https://*.google-analytics.com https://*.analytics.google.com https://stats.g.doubleclick.net https://*.supabase.co"
	);
	$headers['Content-Security-Policy'] = implode( '; ', $csp ) . ';';

	// Permissions-Policy expanded to 12 directives
	$headers['Permissions-Policy'] = 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), magnetometer=(), gyroscope=(), accelerometer=(), ambient-light-sensor=(), autoplay=(), display-capture=(), fullscreen=()';

	return $headers;
}
add_filter( 'wp_headers', 'nvx_add_security_headers', 42 );

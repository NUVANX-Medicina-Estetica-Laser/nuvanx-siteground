<?php
/**
 * Canonical NUVANX theme module dependency graph.
 *
 * functions.php owns this bootstrap. The immutable request boundary is captured
 * immediately when this file is loaded; all remaining modules are loaded once,
 * in dependency order, during after_setup_theme before normal theme setup.
 *
 * Internal package ownership remains explicit. In particular,
 * nvx-structured-data.php is the sole loader for its schema-* implementation
 * files; those implementation files must not also appear in this manifest.
 *
 * @package nuvanx-medical
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Capture the browser-owned request before peer theme modules can mutate it.
require_once __DIR__ . '/nvx-theme-request.php';

/**
 * Return the ordered canonical module manifest.
 *
 * @return string[] Relative paths below the theme root.
 */
function nvx_theme_bootstrap_manifest(): array {
	return array(
		// Infrastructure and request-independent governance.
		'inc/nvx-observability.php',
		'inc/nvx-page-registry.php',
		'inc/nvx-business-config.php',
		'inc/nvx-clinical-governance.php',
		'inc/nvx-environment-flags.php',
		'inc/nvx-meta-browser-governance.php',
		'inc/nvx-page-render-helpers.php',
		'inc/nvx-authentic-page-photography.php',
		'inc/nvx-public-media-runtime-governance.php',
		'inc/nvx-document-governance.php',
		'inc/nvx-native-style-governance.php',
		'inc/nvx-page-hygiene.php',
		'inc/nvx-security-headers.php',
		'inc/nvx-retired-strategy-redirects.php',
		'inc/nvx-brand-page-wrapper-governance.php',
		'inc/nvx-integrations.php',
		'inc/nvx-complianz-policy-routing.php',
		'inc/performance/nuvanx-performance.php',
		'inc/nvx-catalog-json.php',

		// Consent, measurement and server-side transport ownership.
		'inc/nvx-marketing-consent.php',
		'inc/nvx-ads-conversion-catalog.php',
		'inc/nvx-hubspot-secure-attribution.php',
		'inc/nvx-gtm-integration.php',
		'inc/nvx-attribution-integration.php',
		'inc/nvx-supabase-relay-operations.php',
		'inc/nvx-supabase-relay-queue-policy.php',
		'inc/nvx-supabase-relay-queue.php',
		'inc/nvx-lead-captured-relay.php',
		'inc/nvx-google-attribution-relay-auth.php',

		// Data, SEO and structured-data package owners.
		'inc/nvx-gbp-local.php',
		'inc/nvx-tariff-shortcode.php',
		'inc/nvx-jsonld-content.php',
		'inc/nvx-seo-metadata.php',
		'inc/nvx-seo-production-readiness.php',
		'inc/nvx-seo-legacy-retirement.php',
		'inc/nvx-gracias-robots-governance.php',
		'inc/nvx-structured-data.php',
		'inc/nvx-schema-website-governance.php',
		'inc/nvx-schema-semantic-governance.php',
		'inc/nvx-clinic-identity-governance.php',
		'inc/nvx-deploy-stamp.php',

		// Shared presentation primitives.
		'inc/nvx-cta-components.php',
		'inc/nvx-content-presentation.php',
		'inc/nvx-hero-and-forms.php',
		'inc/nvx-valoracion-modal.php',
		'inc/nvx-valoracion-direct-form.php',
		'inc/nvx-navigation-filters.php',

		// Editorial and page modules.
		'inc/nvx-strategy-pages.php',
		'inc/nvx-signature-catalog.php',
		'inc/nvx-signature-phase-pages.php',
		'inc/nvx-bridal-page.php',
		'inc/nvx-aesthetic-treatment-pages.php',
		'inc/nvx-aesthetic-treatment-schema.php',
		'inc/nvx-blog-system.php',
		'inc/nvx-governed-blog-runtime.php',
		'inc/nvx-journal-laserlipolisis-vs-lipo.php',
		'inc/nvx-medical-review.php',
		'inc/nvx-endolift-authority-graph.php',
		'inc/nvx-btl-clinical-governance.php',
		'inc/nvx-treatment-hub-schema.php',
		'inc/nvx-treatments-catalog.php',
		'inc/nvx-solutions-page.php',
		'inc/nvx-endolift-page.php',
		'inc/nvx-exion-page.php',
		'inc/nvx-profhilo-page.php',
		'inc/nvx-endolaser-page.php',
		'inc/nvx-co2-page.php',
		'inc/nvx-btl-detail-pages.php',
		'inc/nvx-equipo-page.php',
		'inc/nvx-nosotros-page.php',
		'inc/nvx-contacto-valoracion-page.php',
		'inc/nvx-valoracion-managed-page.php',
		'inc/nvx-laser-medicine-page.php',
		'inc/nvx-aesthetic-medicine-page.php',
		'inc/nvx-clinics-dom-helpers.php',
		'inc/nvx-clinics-hub.php',
		'inc/nvx-dr-rivera-page.php',
		'inc/nvx-que-exigir-page.php',
	);
}

/** Load the complete theme module graph exactly once. */
function nvx_theme_bootstrap_modules(): void {
	$base  = get_template_directory();
	$paths = array();

	foreach ( nvx_theme_bootstrap_manifest() as $module ) {
		$path = $base . '/' . $module;
		if ( ! is_readable( $path ) ) {
			wp_die(
				esc_html__( 'NUVANX runtime configuration is unavailable.', 'nuvanx-medical' ),
				esc_html__( 'Service unavailable', 'nuvanx-medical' ),
				array( 'response' => 503 )
			);
		}
		$paths[] = $path;
	}

	foreach ( $paths as $path ) {
		require_once $path;
	}
}

if ( false === has_action( 'after_setup_theme', 'nvx_theme_bootstrap_modules' ) ) {
	add_action( 'after_setup_theme', 'nvx_theme_bootstrap_modules', -1000 );
}

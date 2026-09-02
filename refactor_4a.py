import re

with open('wp-content/themes/nuvanx-medical/functions.php', 'r') as f:
    content = f.read()

# find all require_once get_template_directory() lines
matches = re.findall(r"^require_once get_template_directory\(\) \. '([^']+)';$", content, re.MULTILINE)

# The missing lateral dependencies that must be added:
# - nvx-meta-browser-governance.php (after nvx-environment-flags.php)
# - nvx-marketing-consent.php (before forms)
# - nvx-governed-blog-runtime.php (before nvx-blog-system.php)
# - nvx-ads-conversion-catalog.php (before gtm)
# - nvx-hubspot-secure-attribution.php (before gtm)
# - nvx-attribution-integration.php (before gtm)
# - nvx-supabase-relay-queue.php (before gtm)
# - nvx-lead-captured-relay.php (before gtm)
# - nvx-google-attribution-relay-auth.php (before gtm)
# - nvx-brand-page-wrapper-governance.php (in infrastructure)

# We can construct the new manifest
manifest = [
    '/inc/nvx-page-registry.php',
    '/inc/nvx-theme-request.php',
    '/inc/nvx-business-config.php',
    '/inc/nvx-clinical-governance.php',
    '/inc/nvx-environment-flags.php',
    '/inc/nvx-meta-browser-governance.php',
    '/inc/nvx-page-render-helpers.php',
    '/inc/nvx-authentic-page-photography.php',
    '/inc/nvx-public-media-runtime-governance.php',
    '/inc/nvx-document-governance.php',
    '/inc/nvx-native-style-governance.php',
    '/inc/nvx-page-hygiene.php',
    '/inc/nvx-security-headers.php',
    '/inc/nvx-retired-strategy-redirects.php',
    '/inc/nvx-brand-page-wrapper-governance.php',
    '/inc/nvx-integrations.php',
    '/inc/nvx-marketing-consent.php',
    '/inc/nvx-ads-conversion-catalog.php',
    '/inc/nvx-hubspot-secure-attribution.php',
    '/inc/nvx-attribution-integration.php',
    '/inc/nvx-supabase-relay-queue.php',
    '/inc/nvx-lead-captured-relay.php',
    '/inc/nvx-google-attribution-relay-auth.php',
    '/inc/nvx-gtm-integration.php',
    '/inc/nvx-complianz-policy-routing.php',
    '/inc/performance/nuvanx-performance.php',
    '/inc/nvx-catalog-json.php',
    '/inc/nvx-gbp-local.php',
    '/inc/nvx-tariff-shortcode.php',
    '/inc/nvx-jsonld-content.php',
    '/inc/nvx-seo-metadata.php',
    '/inc/nvx-seo-production-readiness.php',
    '/inc/nvx-seo-legacy-retirement.php',
    '/inc/nvx-gracias-robots-governance.php',
    '/inc/nvx-schema-foundation.php',
    '/inc/nvx-schema-faq.php',
    '/inc/nvx-schema-treatments.php',
    '/inc/nvx-schema-physicians.php',
    '/inc/nvx-schema-graph.php',
    '/inc/nvx-structured-data.php',
    '/inc/nvx-schema-website-governance.php',
    '/inc/nvx-schema-semantic-governance.php',
    '/inc/nvx-deploy-stamp.php',
    '/inc/nvx-cta-components.php',
    '/inc/nvx-content-presentation.php',
    '/inc/nvx-hero-and-forms.php',
    '/inc/nvx-valoracion-modal.php',
    '/inc/nvx-valoracion-direct-form.php',
    '/inc/nvx-navigation-filters.php',
    '/inc/nvx-strategy-pages.php',
    '/inc/nvx-signature-catalog.php',
    '/inc/nvx-signature-phase-pages.php',
    '/inc/nvx-bridal-page.php',
    '/inc/nvx-aesthetic-treatment-pages.php',
    '/inc/nvx-aesthetic-treatment-schema.php',
    '/inc/nvx-governed-blog-runtime.php',
    '/inc/nvx-blog-system.php',
    '/inc/nvx-journal-laserlipolisis-vs-lipo.php',
    '/inc/nvx-medical-review.php',
    '/inc/nvx-endolift-authority-graph.php',
    '/inc/nvx-btl-clinical-governance.php',
    '/inc/nvx-treatment-hub-schema.php',
    '/inc/nvx-treatments-catalog.php',
    '/inc/nvx-solutions-page.php',
    '/inc/nvx-endolift-page.php',
    '/inc/nvx-exion-page.php',
    '/inc/nvx-profhilo-page.php',
    '/inc/nvx-endolaser-page.php',
    '/inc/nvx-co2-page.php',
    '/inc/nvx-btl-detail-pages.php',
    '/inc/nvx-equipo-page.php',
    '/inc/nvx-nosotros-page.php',
    '/inc/nvx-contacto-valoracion-page.php',
    '/inc/nvx-valoracion-managed-page.php',
    '/inc/nvx-laser-medicine-page.php',
    '/inc/nvx-aesthetic-medicine-page.php',
    '/inc/nvx-clinics-dom-helpers.php',
    '/inc/nvx-clinics-hub.php',
    '/inc/nvx-dr-rivera-page.php',
    '/inc/nvx-que-exigir-page.php'
]

# We should construct the PHP function
php_code = """
/**
 * Single Canonical Dependency Graph
 *
 * Bootstraps all theme modules during after_setup_theme at priority -1000.
 */
function nvx_theme_bootstrap(): void {
\t$base = get_template_directory();

\t$manifest = array(
"""
for m in manifest:
    php_code += f"\t\t'{m[1:]}',\n"
php_code += """\t);

\tforeach ( $manifest as $module ) {
\t\trequire_once $base . '/' . $module;
\t}
}
add_action( 'after_setup_theme', 'nvx_theme_bootstrap', -1000 );
"""

# Replace all require_once block and comments
# The block starts with "// 1. Infrastructure & Helpers"
start_idx = content.find("// 1. Infrastructure & Helpers")
if start_idx != -1:
    new_content = content[:start_idx] + php_code
    with open('wp-content/themes/nuvanx-medical/functions.php', 'w') as f:
        f.write(new_content)
    print("Replaced functions.php successfully.")
else:
    print("Could not find start index.")

<?php
const BOTOX_REGEX = '/(?:botox|bótox|toxina[[:space:]]+botulínica)/iu';
const RINO_URL = 'https://nuvanx.com/rinomodelacion-sin-cirugia-madrid/' ;

/**
 * Lightweight contract test for governed REST/headless SEO metadata.
 *
 * Runs without booting WordPress by stubbing only the functions needed by the
 * aesthetic-treatment branch. Hook stubs intentionally mirror WordPress call
 * signatures so static analyzers do not reinterpret production hook calls as
 * invalid test-harness calls.
 */

define( 'ABSPATH', __DIR__ . '/' );

/** Fail the blocking static gate when any governed data JSON is malformed. */
function nvx_test_governed_json_integrity(): void {
    $data_dir = dirname( __DIR__, 2 ) . '/wp-content/themes/nuvanx-medical/inc/data';
    $failures = array();

    // Check if data directory exists and is readable
    if ( ! is_dir( $data_dir ) || ! is_readable( $data_dir ) ) {
        $failures[] = 'Data directory not accessible: ' . $data_dir;
    } else {
        // Recursively scan all JSON files in the data directory
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $data_dir, RecursiveDirectoryIterator::SKIP_DOTS )
        );

        foreach ( $iterator as $file ) {
            if ( 'json' !== $file->getExtension() ) {
                continue;
            }

            $path = $file->getPathname();
            $raw = file_get_contents( $path );
            if ( false === $raw ) {
                $failures[] = 'Unreadable JSON: ' . $path;
                continue;
            }

            json_decode( $raw, true );
            if ( JSON_ERROR_NONE !== json_last_error() ) {
                $failures[] = 'Malformed JSON: ' . $path . ' — ' . json_last_error_msg();
            }
        }
    }

    if ( array() !== $failures ) {
        fwrite( STDERR, 'GOVERNED_JSON_INTEGRITY_TEST=FAIL' . PHP_EOL );
        fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
        exit( 1 );
    }

    echo 'GOVERNED_JSON_INTEGRITY_TEST=PASS' . PHP_EOL;
}

nvx_test_governed_json_integrity();

/** Fail the blocking static gate when any merge conflict markers exist in the codebase. */
function nvx_test_merge_conflict_integrity(): void {
    $repo_root = dirname( __DIR__, 2 );
    $failures = array();

    // Search for merge conflict markers in all text files
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $repo_root, RecursiveDirectoryIterator::SKIP_DOTS )
    );

    foreach ( $iterator as $file ) {
        // Skip common binary/vcs directories
        $path = $file->getPathname();
        if ( strpos( $path, '.git' ) !== false || strpos( $path, 'node_modules' ) !== false || strpos( $path, 'vendor' ) !== false ) {
            continue;
        }

        // Only check text files
        if ( ! $file->isFile() ) {
            continue;
        }

        $ext = $file->getExtension();
        if ( ! in_array( $ext, array( 'php', 'js', 'mjs', 'json', 'css', 'md', 'txt', 'yml', 'yaml' ), true ) ) {
            continue;
        }

        $raw = file_get_contents( $path );
        if ( false === $raw ) {
            continue;
        }

        // Match only actual Git conflict markers. Long Markdown separators such
        // as "================" are legitimate document content and must pass.
        if ( preg_match( '/^(?:<{7}(?: .*)?|={7}|>{7}(?: .*)?)\h*$/m', $raw ) ) {
            $rel_path = str_replace( $repo_root . '/', '', $path );
            $failures[] = 'Merge conflict markers found: ' . $rel_path;
        }
    }

    if ( array() !== $failures ) {
        fwrite( STDERR, 'MERGE_CONFLICT_INTEGRITY_TEST=FAIL' . PHP_EOL );
        fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
        exit( 1 );
    }

    echo 'MERGE_CONFLICT_INTEGRITY_TEST=PASS' . PHP_EOL;
}

nvx_test_merge_conflict_integrity();

/** Return true when a value contains a restricted prescription-drug advertising term. */
function nvxAdsRestrictedTerm( string $value, string $pattern ): bool {
    return 1 === preg_match( $pattern, $value );
}

/** Check public governed metadata files that may become advertising surfaces. */
function nvxAdsMetadataFailures( string $dataDir, string $pattern ): array {
    $failures = array();
    $publicFiles = array(
        'seo-metadata.json',
        'seo-blog-post-metadata.json',
        'publication-manifest.json',
        'treatment-hub-schema.json',
    );

    foreach ( $publicFiles as $filename ) {
        $raw = file_get_contents( $dataDir . '/' . $filename );
        if ( false === $raw ) {
            $failures[] = 'Unreadable compliance source: ' . $filename;
            continue;
        }
        $decoded = json_decode( $raw, true );
        if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
            $failures[] = 'Unable to decode compliance source: ' . $filename;
            continue;
        }
        $normalized = json_encode( $decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        if ( false === $normalized ) {
            $failures[] = 'Unable to normalize compliance source: ' . $filename;
            continue;
        }
        if ( nvxAdsRestrictedTerm( $normalized, $pattern ) ) {
            $failures[] = 'Restricted prescription-drug term in public governed metadata: ' . $filename;
        }
    }

    return $failures;
}

/** Validate a legacy route containing a restricted term. */
function nvxAdsLegacyRouteFailure( string $route, $config, array $routes, string $pattern ): ?string {
    $failure = null;

    if ( nvxAdsRestrictedTerm( $route, $pattern ) ) {
        if ( ! is_array( $config ) ) {
            $failure = 'Restricted route is not an alias object: ' . $route;
        } else {
            $keys = array_keys( $config );
            sort( $keys );
            $alias = (string) ( $config['route_alias'] ?? '' );

            if ( array( 'route_alias' ) !== $keys || '' === $alias ) {
                $failure = 'Restricted route is not alias-only to a clean canonical target: ' . $route;
            } elseif ( nvxAdsRestrictedTerm( $alias, $pattern ) ) {
                $failure = 'Restricted term in route_alias target: ' . $route . ' -> ' . $alias;
            } elseif ( ! array_key_exists( $alias, $routes ) ) {
                $failure = 'Restricted legacy alias points outside canonical route catalog: ' . $route . ' -> ' . $alias;
            } elseif ( ! is_array( $routes[ $alias ] ) || array_key_exists( 'route_alias', $routes[ $alias ] ) ) {
                $failure = 'Restricted legacy alias does not point to a canonical route: ' . $route . ' -> ' . $alias;
            }
        }
    }

    return $failure;
}

/** Validate canonical route configuration that does not use a restricted legacy key. */
function nvxAdsCanonicalRouteFailure( string $route, $config, string $pattern ): ?string {
    if ( nvxAdsRestrictedTerm( $route, $pattern ) || ! is_array( $config ) ) {
        return null;
    }

    $encoded = json_encode( $config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
    if ( false !== $encoded && nvxAdsRestrictedTerm( $encoded, $pattern ) ) {
        return 'Restricted prescription-drug term in canonical route configuration: ' . $route;
    }

    return null;
}

/** Check route aliases and canonical route configuration. */
function nvxAdsRouteFailures( string $dataDir, string $pattern ): array {
    $raw = file_get_contents( $dataDir . '/routes.json' );
    $routes = false === $raw ? null : json_decode( $raw, true );
    if ( ! is_array( $routes ) ) {
        return array( 'Unable to parse routes.json for healthcare compliance' );
    }

    $failures = array();
    foreach ( $routes as $route => $config ) {
        $route = (string) $route;
        $legacyFailure = nvxAdsLegacyRouteFailure( $route, $config, $routes, $pattern );
        $canonicalFailure = nvxAdsCanonicalRouteFailure( $route, $config, $pattern );
        if ( null !== $legacyFailure ) {
            $failures[] = $legacyFailure;
        }
        if ( null !== $canonicalFailure ) {
            $failures[] = $canonicalFailure;
        }
    }

    return $failures;
}

/**
 * Block prescription-drug advertising terms from public governed SEO surfaces.
 * Historical URLs may retain restricted words only as alias-only redirects to
 * clean, existing canonical routes.
 */
function nvxTestGoogleAdsHealthcareCompliance(): void {
    $dataDir = dirname( __DIR__, 2 ) . '/wp-content/themes/nuvanx-medical/inc/data';
    $pattern = BOTOX_REGEX;
    $failures = array_merge(
        nvxAdsMetadataFailures( $dataDir, $pattern ),
        nvxAdsRouteFailures( $dataDir, $pattern )
    );

    if ( array() !== $failures ) {
        fwrite( STDERR, 'GOOGLE_ADS_HEALTHCARE_COMPLIANCE_TEST=FAIL' . PHP_EOL );
        fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
        exit( 1 );
    }

    echo 'GOOGLE_ADS_HEALTHCARE_COMPLIANCE_TEST=PASS' . PHP_EOL;
}

nvxTestGoogleAdsHealthcareCompliance();

/** Reject restricted terms hidden in valid JSON through Unicode escaping. */
function nvxAdsAssertUnicodeEscapedTermBlocked(): void {
    $pattern    = BOTOX_REGEX;
    $raw        = '{"title":"b\\u00f3tox"}';
    $decoded    = json_decode( $raw, true );
    $normalized = json_encode( $decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

    if ( false === $normalized || ! nvxAdsRestrictedTerm( $normalized, $pattern ) ) {
        fwrite( STDERR, 'GOOGLE_ADS_UNICODE_ESCAPE_REGRESSION_TEST=FAIL' . PHP_EOL );
        exit( 1 );
    }
    echo 'GOOGLE_ADS_UNICODE_ESCAPE_REGRESSION_TEST=PASS' . PHP_EOL;
}

nvxAdsAssertUnicodeEscapedTermBlocked();

/** Reject restricted slugs that alias through another alias instead of a canonical route. */
function nvxAdsAssertLegacyAliasChainRejected(): void {
    $pattern = BOTOX_REGEX;
    $routes  = array(
        '/neuromoduladores-botox-madrid/' => array( 'route_alias' => '/legacy-neuromoduladores/' ),
        '/legacy-neuromoduladores/'       => array( 'route_alias' => '/neuromoduladores-faciales-madrid/' ),
        '/neuromoduladores-faciales-madrid/' => array( 'seo_id' => 'neuromodulador' ),
    );
    $failure = nvxAdsLegacyRouteFailure(
        '/neuromoduladores-botox-madrid/',
        $routes['/neuromoduladores-botox-madrid/'],
        $routes,
        $pattern
    );
    if ( null === $failure ) {
        fwrite( STDERR, 'GOOGLE_ADS_ALIAS_CHAIN_REGRESSION_TEST=FAIL' . PHP_EOL );
        exit( 1 );
    }
    echo 'GOOGLE_ADS_ALIAS_CHAIN_REGRESSION_TEST=PASS' . PHP_EOL;
}

nvxAdsAssertLegacyAliasChainRejected();

function add_filter( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
    unset( $hook_name, $callback, $priority, $accepted_args );
    return true;
}

function add_action( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
    unset( $hook_name, $callback, $priority, $accepted_args );
    return true;
}

function get_post_type( $post_id ) {
    return 4242 === (int) $post_id ? 'page' : '';
}

function get_post_field( $field, $post_id ) {
    if ( 4242 !== (int) $post_id ) {
        return '';
    }
    return 'post_name' === $field ? 'rinomodelacion-sin-cirugia-madrid' : '';
}

function get_permalink( $post_id ) {
    return 4242 === (int) $post_id ? RINO_URL : false;
}

function nvx_aesthetic_treatment_catalog() {
    return array(
        'rhinoplasty' => array(
            'slug'        => 'rinomodelacion-sin-cirugia-madrid',
            'seo_title'   => 'Rinomodelación Sin Cirugía Madrid | NUVANX',
            'description' => 'Rinomodelación médica sin cirugía en Madrid con ácido hialurónico, valoración individual y criterio anatómico para un resultado natural.',
        ),
    );
}

function wp_parse_url( $url, $component = -1 ) {
    if ( RINO_URL === $url ) {
        return array(
            'scheme' => 'https',
            'host'   => 'nuvanx.com',
            'path'   => '/rinomodelacion-sin-cirugia-madrid/',
        );
    }
    return false;
}
require_once dirname( __DIR__, 2 ) . '/wp-content/themes/nuvanx-medical/inc/nvx-seo-metadata.php';

$actual   = nvx_seo_governed_metadata_for_post_id( 4242 );
$expected = array(
    'title'       => 'Rinomodelación Sin Cirugía Madrid | NUVANX',
    'description' => 'Rinomodelación médica sin cirugía en Madrid con ácido hialurónico, valoración individual y criterio anatómico para un resultado natural.',
    'canonical'   => RINO_URL,
);

if ( $actual !== $expected ) {
    fwrite( STDERR, 'SEO_GOVERNED_METADATA_TEST=FAIL' . PHP_EOL );
    fwrite( STDERR, var_export( $actual, true ) . PHP_EOL );
    exit( 1 );
}

$presentation        = (object) array( 'title' => 'Taxonomy title' );
$taxonomy_indexable  = (object) array( 'object_id' => 4242, 'object_type' => 'term' );
$taxonomy_context    = (object) array( 'indexable' => $taxonomy_indexable );
$taxonomy_result     = nvx_seo_signature_yoast_presentation( $presentation, $taxonomy_context );

if ( $taxonomy_result !== $presentation || 'Taxonomy title' !== $taxonomy_result->title ) {
    fwrite( STDERR, 'SEO_TAXONOMY_ISOLATION_TEST=FAIL' . PHP_EOL );
    exit( 1 );
}

// -----------------------------------------------------------------------------
// Test FAQPage Schema Gate: Ensure FAQPage is never emitted without visible FAQs
// -----------------------------------------------------------------------------

$GLOBALS['test_is_front_page'] = true;
$GLOBALS['test_queried_id']     = 0;

function is_front_page() {
    return ! empty( $GLOBALS['test_is_front_page'] );
}

function is_admin() {
    return false;
}

function is_feed() {
    return false;
}

function get_queried_object_id() {
    return (int) ( $GLOBALS['test_queried_id'] ?? 0 );
}

function home_url( $path = '' ) {
    return 'https://nuvanx.com' . $path;
}

function get_template_directory() {
    return dirname( __DIR__, 2 ) . '/wp-content/themes/nuvanx-medical';
}

require_once dirname( __DIR__, 2 ) . '/wp-content/themes/nuvanx-medical/inc/nvx-structured-data.php';

// Test 1: Front page must report NO visible FAQ and return null for FAQ node
$GLOBALS['test_is_front_page'] = true;
$GLOBALS['test_queried_id']     = 0;

if ( nvx_schema_page_has_visible_faq() !== false ) {
    fwrite( STDERR, 'FAQ_GATE_FRONT_PAGE_TEST=FAIL (has_visible_faq must be false on front page)' . PHP_EOL );
    exit( 1 );
}

if ( null !== nvx_schema_faq_node( 0 ) ) {
    fwrite( STDERR, 'FAQ_GATE_FRONT_PAGE_NODE_TEST=FAIL (faq node must be null on front page)' . PHP_EOL );
    exit( 1 );
}

// Test 2: Gate filter must strip standalone and composite FAQPage on pages without visible FAQs
$orphan_graph = array(
    array(
        '@type' => 'FAQPage',
        '@id'   => 'https://nuvanx.com/#faq',
        'mainEntity' => array(
            array(
                '@type' => 'Question',
                'name'  => 'Orphan question?',
                'acceptedAnswer' => array( '@type' => 'Answer', 'text' => 'Orphan answer.' ),
            ),
        ),
    ),
    array(
        '@type' => array( 'WebPage', 'FAQPage' ),
        '@id'   => 'https://nuvanx.com/#webpage',
        'url'   => 'https://nuvanx.com/',
        'mainEntity' => array(
            array(
                '@type' => 'Question',
                'name'  => 'Composite orphan?',
                'acceptedAnswer' => array( '@type' => 'Answer', 'text' => 'Composite answer.' ),
            ),
        ),
    ),
    array(
        '@type' => 'WebSite',
        '@id'   => 'https://nuvanx.com/#website',
    ),
);

$sanitized = nvx_schema_gate_faq_emission( $orphan_graph );

foreach ( $sanitized as $node ) {
    $types = (array) ( $node['@type'] ?? array() );
    if ( in_array( 'FAQPage', $types, true ) ) {
        fwrite( STDERR, 'FAQ_GATE_STRIP_TEST=FAIL (FAQPage type found in sanitized graph)' . PHP_EOL );
        exit( 1 );
    }
    if ( isset( $node['mainEntity'] ) && is_array( $node['mainEntity'] ) ) {
        $first = reset( $node['mainEntity'] );
        if ( is_array( $first ) && ( $first['@type'] ?? '' ) === 'Question' ) {
            fwrite( STDERR, 'FAQ_GATE_ORPHAN_MAIN_ENTITY_TEST=FAIL (Orphan Question mainEntity remained)' . PHP_EOL );
            exit( 1 );
        }
    }
}

if ( count( $sanitized ) !== 2 ) {
    fwrite( STDERR, 'FAQ_GATE_NODE_COUNT_TEST=FAIL (Expected 2 nodes after removing standalone FAQPage, got ' . count( $sanitized ) . ')' . PHP_EOL );
    exit( 1 );
}

echo 'SEO_GOVERNED_METADATA_TEST=PASS' . PHP_EOL;
echo 'SEO_TAXONOMY_ISOLATION_TEST=PASS' . PHP_EOL;
echo 'FAQ_SCHEMA_GATE_TEST=PASS' . PHP_EOL;

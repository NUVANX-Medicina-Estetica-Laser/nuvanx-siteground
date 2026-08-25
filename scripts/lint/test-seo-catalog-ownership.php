<?php
/**
 * Static ownership contract for canonical SEO text metadata.
 *
 * Every canonical route with seo_id must resolve to a complete catalog record,
 * and known page-local legacy title/description filters must be retired after
 * registration so nvx-seo-metadata.php remains the sole textual owner.
 */

declare(strict_types=1);

$root       = dirname( __DIR__, 2 );
$data_dir   = $root . '/wp-content/themes/nuvanx-medical/inc/data';
$routes_raw = file_get_contents( $data_dir . '/routes.json' );
$seo_raw    = file_get_contents( $data_dir . '/seo-metadata.json' );
$retirement = file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-seo-legacy-retirement.php' );
$central    = file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-seo-metadata.php' );

if ( false === $routes_raw || false === $seo_raw || false === $retirement || false === $central ) {
    fwrite( STDERR, "SEO_CATALOG_OWNERSHIP_TEST=FAIL reason=unreadable_contract_source\n" );
    exit( 1 );
}

$routes = json_decode( $routes_raw, true );
$seo    = json_decode( $seo_raw, true );
if ( ! is_array( $routes ) || ! is_array( $seo ) ) {
    fwrite( STDERR, "SEO_CATALOG_OWNERSHIP_TEST=FAIL reason=invalid_json\n" );
    exit( 1 );
}

$failures = array();
foreach ( $routes as $route => $config ) {
    if ( ! is_array( $config ) || isset( $config['route_alias'] ) || empty( $config['seo_id'] ) ) {
        continue;
    }
    $seo_id = (string) $config['seo_id'];
    $record = $seo[ $seo_id ] ?? null;
    if (
        ! is_array( $record )
        || '' === trim( (string) ( $record['title'] ?? '' ) )
        || '' === trim( (string) ( $record['description'] ?? '' ) )
    ) {
        $failures[] = sprintf( 'missing_catalog_record route=%s seo_id=%s', $route, $seo_id );
    }
}

$legacy = array(
    array( 'wpseo_title', 'nvx_filter_valoracion_document_title', 21 ),
    array( 'wpseo_metadesc', 'nvx_filter_valoracion_metadesc', 21 ),
    array( 'wpseo_title', 'nvx_filter_contacto_document_title', 21 ),
    array( 'wpseo_metadesc', 'nvx_filter_contacto_metadesc', 21 ),
    array( 'wpseo_title', 'nvx_contacto_seo_title', 10 ),
    array( 'wpseo_metadesc', 'nvx_contacto_seo_metadesc', 10 ),
    array( 'wpseo_opengraph_title', 'nvx_filter_contacto_social_title', 110 ),
    array( 'wpseo_twitter_title', 'nvx_filter_contacto_social_title', 110 ),
    array( 'wpseo_opengraph_desc', 'nvx_filter_contacto_social_description', 110 ),
    array( 'wpseo_twitter_description', 'nvx_filter_contacto_social_description', 110 ),
);

foreach ( $legacy as $registration ) {
    $needle = sprintf( "array( '%s', '%s', %d )", $registration[0], $registration[1], $registration[2] );
    if ( false === strpos( $retirement, $needle ) ) {
        $failures[] = 'legacy_retirement_missing ' . $registration[1] . '@' . $registration[2];
    }
}

if ( false === strpos( $central, "add_filter( 'wpseo_title', 'nvx_seo_filter_title', 100 );" ) ) {
    $failures[] = 'canonical_title_owner_missing';
}
if ( false === strpos( $central, "add_filter( 'wpseo_metadesc', 'nvx_seo_filter_description', 100 );" ) ) {
    $failures[] = 'canonical_description_owner_missing';
}

$contact = $seo['contacto'] ?? null;
if (
    ! is_array( $contact )
    || 'Clínicas NUVANX Madrid: Contacto, Teléfonos y Sedes | Chamberí y Salamanca–Goya' !== ( $contact['title'] ?? null )
    || 'Contacto NUVANX Madrid: direcciones, teléfonos, WhatsApp y horarios de las clínicas Chamberí (CS20144) y Salamanca–Goya (CS20073). Valoración médica presencial para medicina estética láser.' !== ( $contact['description'] ?? null )
) {
    $failures[] = 'contacto_catalog_parity_missing';
}

// Local intent and business hours are one governed SEO/entity contract. Run the
// dedicated source-level test from this already-blocking CI entry point.
$local_contract = __DIR__ . '/test-local-seo-ownership.php';
if ( ! is_file( $local_contract ) ) {
    $failures[] = 'local_seo_ownership_contract_missing';
} else {
    $command = 'php ' . escapeshellarg( $local_contract );
    passthru( $command, $local_status );
    if ( 0 !== $local_status ) {
        $failures[] = 'local_seo_ownership_contract_failed exit=' . $local_status;
    }
}

// Goya NAP display is governed separately from machine-readable E.164 values.
// This blocks copy/paste regressions that reformat the public number ad hoc.
$nap_contract = __DIR__ . '/test-goya-nap-display-contract.php';
if ( ! is_file( $nap_contract ) ) {
    $failures[] = 'goya_nap_display_contract_missing';
} else {
    $command = 'php ' . escapeshellarg( $nap_contract );
    passthru( $command, $nap_status );
    if ( 0 !== $nap_status ) {
        $failures[] = 'goya_nap_display_contract_failed exit=' . $nap_status;
    }
}

// Search Analytics is a production telemetry contract, but its code/auth/privacy
// guarantees are static and must block CI before a candidate can reach master.
$gsc_contract = __DIR__ . '/test-gsc-search-analytics-contract.mjs';
if ( ! is_file( $gsc_contract ) ) {
    $failures[] = 'gsc_search_analytics_contract_missing';
} else {
    $command = 'node ' . escapeshellarg( $gsc_contract );
    passthru( $command, $gsc_status );
    if ( 0 !== $gsc_status ) {
        $failures[] = 'gsc_search_analytics_contract_failed exit=' . $gsc_status;
    }
}

if ( array() !== $failures ) {
    fwrite( STDERR, "SEO_CATALOG_OWNERSHIP_TEST=FAIL\n" . implode( "\n", $failures ) . "\n" );
    exit( 1 );
}

echo 'SEO_CATALOG_OWNERSHIP_TEST=PASS' . PHP_EOL;
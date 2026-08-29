<?php
/**
 * Static ownership contract for canonical SEO text metadata.
 *
 * Every canonical route with seo_id must resolve to a complete catalog record,
 * known page-local legacy title/description filters must remain registered for
 * retirement and forbidden from reintroduction, and website-emitted external
 * identity links must stay explicitly governed.
 */

declare(strict_types=1);

$root       = dirname( __DIR__, 2 );
$data_dir   = $root . '/wp-content/themes/nuvanx-medical/inc/data';
$theme_dir  = $root . '/wp-content/themes/nuvanx-medical';
$routes_raw = file_get_contents( $data_dir . '/routes.json' );
$seo_raw    = file_get_contents( $data_dir . '/seo-metadata.json' );
$retirement = file_get_contents( $theme_dir . '/inc/nvx-seo-legacy-retirement.php' );
$central    = file_get_contents( $theme_dir . '/inc/nvx-seo-metadata.php' );
$schema     = file_get_contents( $theme_dir . '/inc/nvx-schema-foundation.php' );

if ( false === $routes_raw || false === $seo_raw || false === $retirement || false === $central || false === $schema ) {
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

$forbidden_callbacks = array(
    'nvx_filter_valoracion_document_title',
    'nvx_filter_valoracion_metadesc',
    'nvx_filter_contacto_document_title',
    'nvx_filter_contacto_metadesc',
    'nvx_contacto_seo_title',
    'nvx_contacto_seo_metadesc',
    'nvx_filter_contacto_social_title',
    'nvx_filter_contacto_social_description',
);
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator( $theme_dir, FilesystemIterator::SKIP_DOTS )
);
foreach ( $iterator as $file ) {
    if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
        continue;
    }
    $path = $file->getPathname();
    if ( str_ends_with( $path, '/inc/nvx-seo-legacy-retirement.php' ) ) {
        continue;
    }
    $source = file_get_contents( $path );
    if ( false === $source ) {
        $failures[] = 'unreadable_php_source ' . $path;
        continue;
    }
    foreach ( $forbidden_callbacks as $callback ) {
        if ( false !== strpos( $source, $callback ) ) {
            $failures[] = 'legacy_seo_callback_forbidden callback=' . $callback . ' file=' . str_replace( $root . '/', '', $path );
        }
    }
}

if ( false === strpos( $central, "add_filter( 'wpseo_title', 'nvx_seo_filter_title', 100 );" ) ) {
    $failures[] = 'canonical_title_owner_missing';
}
if ( false === strpos( $central, "add_filter( 'wpseo_metadesc', 'nvx_seo_filter_description', 100 );" ) ) {
    $failures[] = 'canonical_description_owner_missing';
}

$canonical_goya_doctoralia = 'https://www.doctoralia.es/clinicas/nuvanx-medicina-estetica-laser-sede-goya';
if ( ! str_contains( $schema, "'{$canonical_goya_doctoralia}'" ) ) {
    $failures[] = 'goya_medicalclinic_doctoralia_sameas_missing';
}
foreach ( array( 'yolanda piñero' ) as $unverified_external_identity ) {
    if ( str_contains( strtolower( $schema ), $unverified_external_identity ) ) {
        $failures[] = 'unverified_external_identity_leaked_into_schema';
    }
}

$contact = $seo['contacto'] ?? null;
if (
    ! is_array( $contact )
    || 'Clínicas NUVANX Madrid: Contacto, Teléfonos y Sedes | Chamberí y Salamanca–Goya' !== ( $contact['title'] ?? null )
    || 'Contacto NUVANX Madrid: direcciones, teléfonos, WhatsApp y horarios de las clínicas Chamberí (CS20144) y Salamanca–Goya (CS20073). Valoración médica presencial para medicina estética láser.' !== ( $contact['description'] ?? null )
) {
    $failures[] = 'contacto_catalog_parity_missing';
}

$contracts = array(
    array( 'file' => 'test-theme-data-ownership.php', 'runtime' => 'php', 'label' => 'theme_data_ownership_contract' ),
    array( 'file' => 'test-local-seo-ownership.php', 'runtime' => 'php', 'label' => 'local_seo_ownership_contract' ),
    array( 'file' => 'test-goya-nap-display-contract.php', 'runtime' => 'php', 'label' => 'goya_nap_display_contract' ),
    array( 'file' => 'test-gsc-search-analytics-contract.mjs', 'runtime' => 'node', 'label' => 'gsc_search_analytics_contract' ),
);
foreach ( $contracts as $contract ) {
    $path = __DIR__ . '/' . $contract['file'];
    if ( ! is_file( $path ) ) {
        $failures[] = $contract['label'] . '_missing';
        continue;
    }
    $command = $contract['runtime'] . ' ' . escapeshellarg( $path );
    passthru( $command, $status );
    if ( 0 !== $status ) {
        $failures[] = $contract['label'] . '_failed exit=' . $status;
    }
}

if ( array() !== $failures ) {
    fwrite( STDERR, "SEO_CATALOG_OWNERSHIP_TEST=FAIL\n" . implode( "\n", $failures ) . "\n" );
    exit( 1 );
}

echo 'SEO_CATALOG_OWNERSHIP_TEST=PASS' . PHP_EOL;
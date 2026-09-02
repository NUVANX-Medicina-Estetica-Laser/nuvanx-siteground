<?php
require_once __DIR__ . '/../../lib/nvx-content-hygiene-rules.php';

// ── Safety gate — verify staging environment before any mutation ─────────────
// Note: require_once above only declares functions, so this remains the first
// executable statement that checks environment invariants.

$nvx_staging_identity = [
    'db_name'             => defined( 'DB_NAME' ) ? (string) DB_NAME : '',
    'home'                => (string) get_option( 'home' ),
    'siteurl'             => (string) get_option( 'siteurl' ),
    'nvx_env'             => defined( 'NVX_ENV' ) ? (string) NVX_ENV : '',
    'wp_environment'      => function_exists( 'wp_get_environment_type' ) ? (string) wp_get_environment_type() : '',
];

$nvx_is_staging2_cli = 'dbshcocboodiwr' === $nvx_staging_identity['db_name']
    && 'https://staging2.nuvanx.com' === $nvx_staging_identity['home']
    && 'https://staging2.nuvanx.com' === $nvx_staging_identity['siteurl']
    && 'staging' === $nvx_staging_identity['nvx_env']
    && 'staging' === $nvx_staging_identity['wp_environment'];

if ( ! $nvx_is_staging2_cli ) {
    fwrite(
        STDERR,
        "[FATAL] content-hygiene-staging-only.php executed outside the canonical Staging2 WP-CLI identity. Aborting.\n"
    );
    echo "Status: STAGING_ONLY_ABORT\n";
    exit( 1 );
}

if ( ! function_exists( 'nvx_aesthetic_treatment_catalog' ) ) {
    fwrite(
        STDERR,
        "[FATAL] nvx_aesthetic_treatment_catalog() not available. Theme may not be fully loaded. Aborting.\n"
    );
    echo "Status: STAGING_ONLY_ABORT\n";
    exit( 1 );
}

if ( ! function_exists( 'nvx_clinics_hub_equipment_catalog' ) ) {
    fwrite(
        STDERR,
        "[FATAL] nvx_clinics_hub_equipment_catalog() not available. Theme may not be fully loaded. Aborting.\n"
    );
    echo "Status: STAGING_ONLY_ABORT\n";
    exit( 1 );
}

$equipment_catalog = nvx_clinics_hub_equipment_catalog();
if ( ! is_array( $equipment_catalog ) || 7 !== count( $equipment_catalog ) ) {
    fwrite( STDERR, "[FATAL] Clinic equipment catalog must contain exactly 7 governed assets. Aborting.\n" );
    echo "Status: STAGING_ONLY_ABORT\n";
    exit( 1 );
}

// ── Bootstrap ─────────────────────────────────────────────────────────────────

global $wpdb;

$dry_run = '1' === getenv( 'MIGRATION_DRY_RUN' );
$start   = microtime( true );
$catalog = nvx_aesthetic_treatment_catalog();

printf( "=== NVX Staging-Only Content Migration ===\n" );
printf( "Mode        : %s\n", $dry_run ? 'DRY RUN (no writes)' : 'LIVE' );
printf( "Site        : %s\n", get_option( 'siteurl' ) );
printf( "Started     : %s\n\n", current_time( 'Y-m-d H:i:s' ) );
printf( "Catalog keys loaded : %d\n\n", count( $catalog ) );

// ── Block A: Aesthetic treatment seed normalization ───────────────────────────

echo "--- Block A: Aesthetic Treatment Seed Pages ---\n";

$seed_pages = $wpdb->get_results(
    "SELECT ID, post_name, post_status, post_content, post_excerpt
       FROM {$wpdb->posts}
      WHERE post_status IN ('publish','draft')
        AND post_type = 'page'
        AND post_content LIKE '%nvx-aesthetic-treatment-source%'
      ORDER BY ID ASC",
    ARRAY_A
);

printf( "Seed pages found : %d\n\n", count( $seed_pages ) );

$blocks_ok   = 0;
$blocks_fail = 0;

foreach ( $seed_pages as $page ) {
    $pid = (int) $page['ID'];

    // 1. Resolve treatment key: postmeta first, data-attribute fallback.
    $key = (string) get_post_meta( $pid, '_nvx_aesthetic_treatment_key', true );

    if ( '' === $key ) {
        if ( preg_match( '/data-nvx-treatment=["\']([^"\']+)["\']/', $page['post_content'], $attr ) ) {
            $key = $attr[1];
        }
    }

    if ( '' === $key ) {
        printf( "[SKIP] ID %d /%s/ — no treatment key resolved.\n", $pid, $page['post_name'] );
        $blocks_ok++;
        continue;
    }

    // 2. Key not in catalog → draft the page.
    if ( ! array_key_exists( $key, $catalog ) ) {
        printf(
            "[DRAFT%s] ID %d /%s/ — key \"%s\" absent from catalog.\n",
            $dry_run ? '-DRY' : '     ',
            $pid, $page['post_name'], $key
        );

        if ( ! $dry_run ) {
            $result = $wpdb->update(
                $wpdb->posts,
                [ 'post_status' => 'draft' ],
                [ 'ID' => $pid ],
                [ '%s' ], [ '%d' ]
            );
            if ( false === $result ) {
                fwrite( STDERR, "[ERROR] Could not draft ID {$pid}: " . $wpdb->last_error . "\n" );
                $blocks_fail++;
                continue;
            }
        }

        $blocks_ok++;
        continue;
    }

    // 3. Key is valid → normalize seed marker, excerpt, meta, review status.
    printf(
        "[NORMALIZE%s] ID %d /%s/ — key \"%s\"\n",
        $dry_run ? '-DRY' : '   ',
        $pid, $page['post_name'], $key
    );

    if ( ! $dry_run ) {
        // Normalize the marker: strip any extra attributes appended to the class.
        // This ensures the marker matches the canonical format <div class="nvx-aesthetic-treatment-source" data-nvx-treatment="...">
        // by removing any stray attributes that may have been added by editors.
        $new_content = preg_replace(
            '/nvx-aesthetic-treatment-source[^\s"\'>\]]*/',
            'nvx-aesthetic-treatment-source',
            $page['post_content']
        );

        $catalog_entry = $catalog[ $key ];
        $updates = [
            'post_content' => $new_content ?? $page['post_content'],
            'post_excerpt' => $catalog_entry['excerpt'] ?? $page['post_excerpt'],
        ];

        $result = $wpdb->update(
            $wpdb->posts,
            $updates,
            [ 'ID' => $pid ],
            array_fill( 0, count( $updates ), '%s' ),
            [ '%d' ]
        );

        if ( false === $result ) {
            fwrite( STDERR, "[ERROR] Could not normalize ID {$pid}: " . $wpdb->last_error . "\n" );
            $blocks_fail++;
            continue;
        }

        update_post_meta( $pid, '_nvx_aesthetic_treatment_key', $key );
        update_post_meta( $pid, '_nvx_medical_review_status', 'pending' );
    }

    $blocks_ok++;
}

// ── Block B: Featured-media filesystem parity ─────────────────────────────────
// Staging and Production use separate uploads trees. Content parity can therefore
// leave a valid featured-image attachment record in Staging while the referenced
// file is physically absent. Browser acceptance must treat that as a real defect.
// Production is read-only. Required originals must exist in Production before
// Staging can be considered in parity; governed equipment must also be readable
// images. Missing, zero-byte, size-mismatched or unreadable required Staging
// copies are repaired from the canonical Production uploads tree.

echo "\n--- Block B: Featured Media Filesystem Parity ---\n";

$production_root        = '/home/customer/www/nuvanx.com/public_html';
$production_uploads_dir = $production_root . '/wp-content/uploads';
$staging_uploads        = wp_get_upload_dir();
$staging_uploads_dir    = isset( $staging_uploads['basedir'] ) ? (string) $staging_uploads['basedir'] : '';

$normalize_media_path = static function ( string $path ) {
    $path = str_replace( '\\', '/', trim( $path ) );

    if ( '' === $path || str_starts_with( $path, '/' ) || false !== strpos( $path, "\0" ) ) {
        return '';
    }

    if ( preg_match( '#(^|/)\.\.(/|$)#', $path ) ) {
        return '';
    }

    return $path;
};

$production_root_real    = realpath( $production_root );
$production_uploads_real = realpath( $production_uploads_dir );
$staging_uploads_real    = realpath( $staging_uploads_dir );

if (
    false === $production_root_real
    || false === $production_uploads_real
    || false === $staging_uploads_real
    || $production_uploads_real === $staging_uploads_real
    || ! str_starts_with( $production_uploads_real, $production_root_real . DIRECTORY_SEPARATOR )
) {
    fwrite( STDERR, "[ERROR] Featured-media parity filesystem boundary unresolvable or invalid from staging environment.\n" );
    printf( "STAGING_FEATURED_MEDIA_PARITY=FAIL reason=invalid-uploads-boundary\n" );
    $blocks_fail++;
} else {
    $published_ids = get_posts(
        array(
            'post_type'              => array( 'post', 'page' ),
            'post_status'            => 'publish',
            'posts_per_page'         => -1,
            'fields'                 => 'ids',
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'no_found_rows'          => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
        )
    );

    $featured_attachment_ids = array();
    foreach ( $published_ids as $published_id ) {
        $attachment_id = (int) get_post_thumbnail_id( (int) $published_id );
        if ( $attachment_id > 0 ) {
            $featured_attachment_ids[ $attachment_id ] = true;
        }
    }

    $media_paths         = array();
    $required_originals  = array();
    $equipment_originals = array();

    foreach ( array_keys( $featured_attachment_ids ) as $attachment_id ) {
        $relative = $normalize_media_path( (string) get_post_meta( $attachment_id, '_wp_attached_file', true ) );
        if ( '' === $relative ) {
            printf( "[MEDIA-SKIP] attachment=%d reason=missing-or-invalid-attached-file\n", $attachment_id );
            continue;
        }

        $media_paths[ $relative ]        = true;
        $required_originals[ $relative ] = true;

        $metadata = wp_get_attachment_metadata( $attachment_id );
        if ( is_array( $metadata ) ) {
            $relative_dir = dirname( $relative );
            if ( '.' === $relative_dir ) {
                $relative_dir = '';
            }

            if ( ! empty( $metadata['original_image'] ) && is_string( $metadata['original_image'] ) ) {
                $orig_file = $normalize_media_path( $metadata['original_image'] );
                if ( '' !== $orig_file && false === strpos( $orig_file, '/' ) ) {
                    $orig_relative = $normalize_media_path( ( '' !== $relative_dir ? $relative_dir . '/' : '' ) . $orig_file );
                    if ( '' !== $orig_relative ) {
                        $media_paths[ $orig_relative ] = true;
                    }
                }
            }

            if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
                foreach ( $metadata['sizes'] as $size_data ) {
                    if ( ! is_array( $size_data ) || empty( $size_data['file'] ) ) {
                        continue;
                    }

                    $size_file = $normalize_media_path( (string) $size_data['file'] );
                    if ( '' === $size_file || false !== strpos( $size_file, '/' ) ) {
                        continue;
                    }

                    $size_relative = $normalize_media_path(
                        ( '' !== $relative_dir ? $relative_dir . '/' : '' ) . $size_file
                    );
                    if ( '' !== $size_relative ) {
                        $media_paths[ $size_relative ] = true;
                    }
                }
            }
        }
    }

    // Also scan published post_content for any inline referenced media.
    $inline_contents = $wpdb->get_col(
        "SELECT post_content FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('post', 'page') AND post_content LIKE '%/wp-content/uploads/%'"
    );
    if ( is_array( $inline_contents ) ) {
        foreach ( $inline_contents as $content_str ) {
            if ( preg_match_all( '~/wp-content/uploads/([^\'"\s<>?#\),]+)~i', (string) $content_str, $content_matches ) ) {
                foreach ( $content_matches[1] as $matched_path ) {
                    $cleaned = urldecode( rtrim( (string) $matched_path, '),' ) );
                    $norm    = $normalize_media_path( $cleaned );
                    if ( '' !== $norm && preg_match( '#\.(jpe?g|png|webp|gif|svg|avif|ico|pdf|mp4)$#i', $norm ) ) {
                        $media_paths[ $norm ] = true;
                    }
                }
            }
        }
    }

    // Also ensure governed clinic gallery and equipment catalog assets are synced.
    foreach ( $equipment_catalog as $eq ) {
        $eq_path = $normalize_media_path( (string) ( $eq['uploads_path'] ?? '' ) );
        if ( '' === $eq_path ) {
            fwrite( STDERR, "[FATAL] Clinic equipment catalog contains an invalid uploads_path. Aborting.\n" );
            echo "Status: MIGRATION_FAIL\n";
            exit( 1 );
        }
        $media_paths[ $eq_path ]         = true;
        $required_originals[ $eq_path ]  = true;
        $equipment_originals[ $eq_path ] = true;
    }

    if ( function_exists( 'nvx_clinic_editorial_photo_map' ) ) {
        foreach ( array( 'goya', 'chamberi' ) as $clinic_key ) {
            foreach ( nvx_clinic_editorial_photo_map( $clinic_key ) as $photo ) {
                $photo_path = $normalize_media_path( (string) ( $photo['uploads_path'] ?? '' ) );
                if ( '' !== $photo_path ) {
                    $media_paths[ $photo_path ] = true;
                }
            }
        }
    }

    ksort( $media_paths );

    $media_copied          = 0;
    $media_already_present = 0;
    $media_source_missing  = 0;
    $media_copy_failures   = 0;

    foreach ( array_keys( $media_paths ) as $relative ) {
        $source       = $production_uploads_real . DIRECTORY_SEPARATOR . $relative;
        $destination  = $staging_uploads_real . DIRECTORY_SEPARATOR . $relative;
        $is_required  = isset( $required_originals[ $relative ] );
        $is_equipment = isset( $equipment_originals[ $relative ] );

        // Required originals are a source-of-truth contract. Never accept a
        // pre-existing Staging file when the canonical Production source is
        // missing, zero-byte or (for governed equipment) not a readable image.
        if ( $is_required && ( ! is_file( $source ) || filesize( $source ) <= 0 ) ) {
            $media_source_missing++;
            fwrite( STDERR, "[MEDIA-ERROR] required Production original missing or empty: {$relative}\n" );
            $media_copy_failures++;
            continue;
        }

        if ( $is_equipment && ( ! is_readable( $source ) || false === @getimagesize( $source ) ) ) {
            fwrite( STDERR, "[MEDIA-ERROR] required Production equipment image unreadable: {$relative}\n" );
            $media_copy_failures++;
            continue;
        }

        if ( file_exists( $destination ) ) {
            if ( is_dir( $destination ) ) {
                fwrite( STDERR, "[MEDIA-ERROR] destination exists as a directory collision: {$relative}\n" );
                $media_copy_failures++;
                continue;
            }

            if ( is_file( $destination ) && filesize( $destination ) > 0 ) {
                if ( ! $is_required ) {
                    $media_already_present++;
                    continue;
                }

                $destination_matches_source = filesize( $destination ) === filesize( $source );
                if ( $destination_matches_source ) {
                    $source_hash = md5_file( $source );
                    $dest_hash = md5_file( $destination );
                    if ( is_string( $source_hash ) && is_string( $dest_hash ) ) {
                        $destination_matches_source = $source_hash === $dest_hash;
                    } else {
                        $destination_matches_source = false;
                    }
                }
                if ( $is_equipment ) {
                    $destination_matches_source = $destination_matches_source
                        && is_readable( $destination )
                        && false !== @getimagesize( $destination );
                }

                if ( $destination_matches_source ) {
                    $media_already_present++;
                    continue;
                }

                printf( "[MEDIA-REPAIR] required Staging media stale or unreadable: %s\n", $relative );
            }
        }

        if ( ! is_file( $source ) || filesize( $source ) <= 0 ) {
            $media_source_missing++;
            printf( "[MEDIA-WARN] Production derivative missing or empty: %s\n", $relative );
            continue;
        }

        if ( $dry_run ) {
            printf( "[MEDIA-COPY-DRY] %s\n", $relative );
            $media_copied++;
            continue;
        }

        $destination_dir = dirname( $destination );
        if ( ! wp_mkdir_p( $destination_dir ) || ! copy( $source, $destination ) ) {
            fwrite( STDERR, "[MEDIA-ERROR] unable to copy featured media: {$relative}\n" );
            $media_copy_failures++;
            continue;
        }

        clearstatcache( true, $source );
        clearstatcache( true, $destination );
        $copied_source_hash = md5_file( $source );
        $copied_dest_hash   = md5_file( $destination );
        if ( ! is_file( $destination ) || filesize( $destination ) !== filesize( $source )
            || ! is_string( $copied_source_hash ) || ! is_string( $copied_dest_hash )
            || $copied_source_hash !== $copied_dest_hash ) {
            fwrite( STDERR, "[MEDIA-ERROR] copied media failed size verification: {$relative}\n" );
            @unlink( $destination );
            $media_copy_failures++;
            continue;
        }

        if ( $is_equipment && ( ! is_readable( $destination ) || false === @getimagesize( $destination ) ) ) {
            fwrite( STDERR, "[MEDIA-ERROR] copied equipment media failed image verification: {$relative}\n" );
            @unlink( $destination );
            $media_copy_failures++;
            continue;
        }

        printf( "[MEDIA-COPIED] %s\n", $relative );
        $media_copied++;
    }

    $parity_status = 'FAIL';
    if ( 0 === $media_copy_failures ) {
        $parity_status = $dry_run ? 'DRY_RUN_PASS' : 'PASS';
    }

    printf(
        "STAGING_FEATURED_MEDIA_PARITY=%s attachments=%d referenced=%d copied=%d already_present=%d source_missing=%d copy_failures=%d mode=%s\n",
        $parity_status,
        count( $featured_attachment_ids ),
        count( $media_paths ),
        $media_copied,
        $media_already_present,
        $media_source_missing,
        $media_copy_failures,
        $dry_run ? 'dry-run' : 'live'
    );

    if ( $media_copy_failures > 0 ) {
        $blocks_fail++;
    } else {
        $blocks_ok++;
    }
}

// ── Fix Staging2 Primary Menu ────────────────────────────────────────────────
// The canonical menu is defined in nvx-navigation-filters.php (fallback).
// Staging2 has a stale DB menu that lacks "Protocolo Novias", breaking E2E.
// By unsetting the primary location, we force the canonical fallback to be used.
$menu_locations = get_theme_mod( 'nav_menu_locations' );
if ( is_array( $menu_locations ) && isset( $menu_locations['primary'] ) ) {
    if ( ! $dry_run ) {
        unset( $menu_locations['primary'] );
        set_theme_mod( 'nav_menu_locations', $menu_locations );
    }
    printf( "STAGING_MENU_SYNC: Unset primary menu location to force canonical fallback%s.\n", $dry_run ? ' (dry-run)' : '' );
}
// ── Summary ───────────────────────────────────────────────────────────────────

$elapsed = round( microtime( true ) - $start, 2 );

echo "\n=== STAGING-ONLY SUMMARY ===\n";
printf( "Mode        : %s\n", $dry_run ? 'DRY RUN' : 'LIVE' );
printf( "Seed pages  : %d\n", count( $seed_pages ) );
printf( "Blocks OK   : %d\n", $blocks_ok );
printf( "Blocks FAIL : %d\n", $blocks_fail );
printf( "Elapsed     : %ss\n\n", $elapsed );

if ( 0 === $blocks_fail ) {
    echo "Status: MIGRATION_OK\n";
    exit( 0 );
}

printf( "Status: MIGRATION_FAIL (%d block(s) failed)\n", $blocks_fail );
exit( 1 );

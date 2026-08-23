<?php
/**
 * NUVANX final-close WordPress collector.
 *
 * This file is designed for `wp eval-file --skip-plugins --skip-themes`.
 * It performs read-only queries and emits a redacted JSON report to STDOUT.
 */

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "FINAL_CLOSE_AUDIT=FAIL reason=wordpress_not_bootstrapped\n" );
    exit( 1 );
}

/** @var wpdb $wpdb */
global $wpdb;

$scannedAt     = gmdate( 'c' );
$prefix        = $wpdb->prefix;
$report        = array(
    'schema_version' => 1,
    'collector'      => 'final-close-wp-inventory',
    'scanned_at'     => $scannedAt,
    'mode'           => 'read_only_skip_plugins_skip_themes',
    'redaction'      => 'Values and source code are never emitted; only metadata, hashes, sizes and matched categories are reported.',
    'failures'       => array(),
    'warnings'       => array(),
);

/** Return a deterministic hash without retaining or emitting the original value. */
function nvx_final_close_hash( $value ) {
    return hash( 'sha256', (string) $value );
}

/** Return categories matched by a value, without emitting the matched data. */
function nvx_final_close_categories( $value ) {
    $value      = (string) $value;
    $categories = array();
    $patterns   = array(
        'SECRET'                   => '/(?:client_secret|refresh_token|developer_token|access_token|api_key|authorization|bearer|password|secret|token)/i',
        'ENVIRONMENT_SPECIFIC'     => '/(?:localhost|127\.0\.0\.1|\/home\/customer\/|staging2\.nuvanx\.com)/i',
        'STABLE_PUBLIC_IDENTIFIER' => '/(?:\bGTM-[A-Z0-9-]+|\bG-[A-Z0-9]+|\bAW-[0-9]+|\bact_[0-9]+|portalId)/i',
        'CONTENT_IDENTIFIER'       => '/(?:page_id|post_id|\bis_page\s*\(|\bget_post\s*\()/i',
        'BUSINESS_CONFIG'          => '/(?:hubspot|klaviyo|google ads|google analytics|google tag manager|meta platforms|joinchat|complianz)/i',
        'URL_OR_PATH'              => '/(?:https?:\/\/|nuvanx\.com|\/home\/customer\/)/i',
    );

    foreach ( $patterns as $category => $pattern ) {
        if ( preg_match( $pattern, $value ) ) {
            $categories[] = $category;
        }
    }

    return $categories;
}

/** Return redacted metadata for an arbitrary database value. */
function nvx_final_close_value_meta( $value ) {
    $value = (string) $value;
    return array(
        'bytes'      => strlen( $value ),
        'sha256'     => nvx_final_close_hash( $value ),
        'categories' => nvx_final_close_categories( $value ),
    );
}

/** Add a structured failure without leaking values. */
function nvx_final_close_failure( &$report, $area, $reason, $details = array() ) {
    $report['failures'][] = array_merge(
        array(
            'area'   => (string) $area,
            'reason' => (string) $reason,
        ),
        $details
    );
}

$report['wordpress'] = array(
    'version'      => get_bloginfo( 'version' ),
    'site_url'     => (string) get_option( 'siteurl' ),
    'home_url'     => (string) get_option( 'home' ),
    'environment'  => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'unknown',
    'active_theme' => (string) get_option( 'stylesheet' ),
);

$report['database'] = array(
    'table_prefix' => $prefix,
    'charset'      => $wpdb->charset,
    'collate'      => $wpdb->collate,
    'tables'       => array(),
);

$tables = $wpdb->get_col( 'SHOW TABLES' );
if ( ! is_array( $tables ) ) {
    nvx_final_close_failure( $report, 'database', 'show_tables_failed' );
    $tables = array();
}
foreach ( $tables as $table ) {
    $report['database']['tables'][] = preg_replace( '/^' . preg_quote( $prefix, '/' ) . '/', 'wp_', (string) $table );
}
sort( $report['database']['tables'] );

$report['plugins'] = array(
    'active'      => array_values( (array) get_option( 'active_plugins', array() ) ),
    'must_use_dir' => defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : null,
    'dropins'      => array(),
);
$dropinCandidates = array( 'advanced-cache.php', 'db.php', 'object-cache.php', 'sunrise.php', 'maintenance.php' );
foreach ( $dropinCandidates as $file ) {
    $path = WP_CONTENT_DIR . '/' . $file;
    if ( is_file( $path ) ) {
        $report['plugins']['dropins'][] = array(
            'file'   => $file,
            'bytes'  => filesize( $path ),
            'sha256' => hash_file( 'sha256', $path ),
        );
    }
}

$report['mu_plugins'] = array();
if ( defined( 'WPMU_PLUGIN_DIR' ) && is_dir( WPMU_PLUGIN_DIR ) ) {
    $files = glob( WPMU_PLUGIN_DIR . '/*.php' );
    foreach ( $files ?: array() as $file ) {
        $report['mu_plugins'][] = array(
            'file'   => basename( $file ),
            'bytes'  => filesize( $file ),
            'sha256' => hash_file( 'sha256', $file ),
            'backup' => (bool) preg_match( '/(?:\.bak|\.old|\.disabled)$/i', basename( $file ) ),
        );
    }
}
usort( $report['mu_plugins'], static function ( $a, $b ) { return strcmp( $a['file'], $b['file'] ); } );

$optionsTable = $wpdb->options;
$report['options'] = array(
    'large_autoload' => array(),
    'transients'     => array(),
    'signals'        => array(),
    'required_states' => array(),
);

$largeOptions = $wpdb->get_results(
    "SELECT option_name, autoload, LENGTH(option_value) AS bytes, option_value
     FROM {$optionsTable}
     WHERE autoload IN ('yes', 'on', 'auto', 'auto-on')
     ORDER BY LENGTH(option_value) DESC
     LIMIT 50",
    ARRAY_A
);
foreach ( $largeOptions ?: array() as $row ) {
    $report['options']['large_autoload'][] = array(
        'name'       => (string) $row['option_name'],
        'autoload'   => (string) $row['autoload'],
        'value_meta' => nvx_final_close_value_meta( $row['option_value'] ),
    );
}

$transients = $wpdb->get_results(
    "SELECT option_name, autoload, LENGTH(option_value) AS bytes, option_value
     FROM {$optionsTable}
     WHERE option_name LIKE '\\_transient\\_%' OR option_name LIKE '\\_site\\_transient\\_%'
     ORDER BY LENGTH(option_value) DESC
     LIMIT 100",
    ARRAY_A
);
foreach ( $transients ?: array() as $row ) {
    $report['options']['transients'][] = array(
        'name'       => (string) $row['option_name'],
        'autoload'   => (string) $row['autoload'],
        'value_meta' => nvx_final_close_value_meta( $row['option_value'] ),
    );
}

$signalOptions = $wpdb->get_results(
    "SELECT option_name, autoload, option_value
     FROM {$optionsTable}
     WHERE option_name REGEXP 'hubspot|klaviyo|google|gtm|ga4|ads|meta|complianz|joinchat|token|secret|cron|snippet|privacy|policy|nvx|nuvanx'
     ORDER BY option_name ASC
     LIMIT 500",
    ARRAY_A
);
foreach ( $signalOptions ?: array() as $row ) {
    $report['options']['signals'][] = array(
        'name'       => (string) $row['option_name'],
        'autoload'   => (string) $row['autoload'],
        'value_meta' => nvx_final_close_value_meta( $row['option_value'] ),
    );
}

// Explicit one-time states are reported even when absent, so a final-close
// audit can distinguish a missing migration seal from a collector blind spot.
$requiredStateNames = array( 'nvx_privacy_policy_reconciled_20260808' );
foreach ( $requiredStateNames as $optionName ) {
    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT option_name, autoload, option_value FROM {$optionsTable} WHERE option_name = %s LIMIT 1",
            $optionName
        ),
        ARRAY_A
    );
    $report['options']['required_states'][] = array(
        'name'   => $optionName,
        'exists' => is_array( $row ),
        'autoload' => is_array( $row ) ? (string) $row['autoload'] : null,
        'value_meta' => is_array( $row ) ? nvx_final_close_value_meta( $row['option_value'] ) : null,
    );
}

$report['cron'] = array( 'events' => array(), 'option_meta' => null );
$cronRaw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$optionsTable} WHERE option_name = %s", 'cron' ) );
if ( null !== $cronRaw ) {
    $report['cron']['option_meta'] = nvx_final_close_value_meta( $cronRaw );
    
    // Security: Prevent PHP Object Injection via deserialization.
    // Explicitly reject Object (O, C) and Reference (R, r) tokens.
    if ( ! is_string( $cronRaw ) || ! preg_match( '/^a:\d+:\{.*\}$/s', $cronRaw ) ) {
        nvx_final_close_failure( $report, 'cron', 'cron_option_not_serialized_array' );
    } elseif ( preg_match( '/(?:^|[;{}])(?:[OCRr]:[0-9]+:)/', $cronRaw ) ) {
        nvx_final_close_failure( $report, 'cron', 'cron_option_contains_objects_or_references' );
    } else {
        $cronUnserializeWarning = false;
        set_error_handler(
            static function ( $severity ) use ( &$cronUnserializeWarning ) {
                if ( E_WARNING === $severity || E_NOTICE === $severity ) {
                    $cronUnserializeWarning = true;
                    return true;
                }
                return false;
            },
            E_WARNING | E_NOTICE
        );
        try {
            $cron = unserialize( $cronRaw, ['allowed_classes' => false] );
        } finally {
            restore_error_handler();
        }

        if ( $cronUnserializeWarning || ! is_array( $cron ) ) {
            nvx_final_close_failure( $report, 'cron', 'cron_option_not_serialized_array' );
        } else {
            foreach ( $cron as $timestamp => $hooks ) {
                if ( ! is_array( $hooks ) || ! ctype_digit( (string) $timestamp ) ) {
                    continue;
                }
                foreach ( $hooks as $hook => $instances ) {
                    $report['cron']['events'][] = array(
                        'next_timestamp_utc' => gmdate( 'c', (int) $timestamp ),
                        'hook'               => (string) $hook,
                        'instances'          => is_array( $instances ) ? count( $instances ) : 0,
                        'categories'         => nvx_final_close_categories( (string) $hook ),
                    );
                }
            }
        }
    }
}

$report['content'] = array(
    'critical_pages' => array(),
    'signals'        => array(),
    'postmeta'       => array(),
);
$criticalSlugs = array( 'madrid', 'valoracion', 'gracias', 'protocolo-novias-madrid', 'protocolos-signature', 'politica-privacidad' );
$placeholders  = implode( ',', array_fill( 0, count( $criticalSlugs ), '%s' ) );
$query         = $wpdb->prepare(
    "SELECT ID, post_name, post_type, post_status FROM {$wpdb->posts}
     WHERE post_name IN ({$placeholders}) AND post_type IN ('page','post')
     ORDER BY post_name ASC",
    $criticalSlugs
);
$report['content']['critical_pages'] = $wpdb->get_results( $query, ARRAY_A ) ?: array();

$posts = $wpdb->get_results(
    "SELECT ID, post_name, post_type, post_status, post_title, post_content, post_excerpt
     FROM {$wpdb->posts}
     WHERE post_status NOT IN ('auto-draft','inherit','trash')
       AND post_type NOT IN ('revision','attachment','nav_menu_item')
     ORDER BY ID ASC",
    ARRAY_A
);
foreach ( $posts ?: array() as $post ) {
    foreach ( array( 'post_title', 'post_content', 'post_excerpt' ) as $field ) {
        $meta = nvx_final_close_value_meta( $post[ $field ] );
        if ( empty( $meta['categories'] ) ) {
            continue;
        }
        $report['content']['signals'][] = array(
            'post_id'     => (int) $post['ID'],
            'slug'        => (string) $post['post_name'],
            'post_type'   => (string) $post['post_type'],
            'post_status' => (string) $post['post_status'],
            'field'       => $field,
            'value_meta'  => $meta,
        );
    }
}

$postmetaRows = $wpdb->get_results(
    "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
     WHERE meta_key REGEXP 'hubspot|klaviyo|google|gtm|ga4|ads|meta|token|secret|portal|page_id|post_id'
     ORDER BY post_id, meta_key ASC
     LIMIT 2000",
    ARRAY_A
);
foreach ( $postmetaRows ?: array() as $row ) {
    $report['content']['postmeta'][] = array(
        'post_id'    => (int) $row['post_id'],
        'meta_key'   => (string) $row['meta_key'],
        'value_meta' => nvx_final_close_value_meta( $row['meta_value'] ),
    );
}

$report['snippets'] = array( 'storage' => 'not_found', 'records' => array() );
$snippetTables      = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix . 'snippets' ) ) );
if ( ! empty( $snippetTables ) ) {
    $snippetTable                = (string) $snippetTables[0];
    $report['snippets']['storage'] = preg_replace( '/^' . preg_quote( $prefix, '/' ) . '/', 'wp_', $snippetTable );
    $columns                     = $wpdb->get_col( "SHOW COLUMNS FROM `{$snippetTable}`", 0 );
    $allowed                     = array_intersect( array( 'id', 'name', 'description', 'code', 'tags', 'scope', 'priority', 'active', 'modified', 'type' ), $columns ?: array() );
    if ( ! empty( $allowed ) ) {
        $select = implode( ',', array_map( static function ( $column ) { return "`{$column}`"; }, $allowed ) );
        $rows   = $wpdb->get_results( "SELECT {$select} FROM `{$snippetTable}` ORDER BY id ASC", ARRAY_A );
        foreach ( $rows ?: array() as $row ) {
            $record = array();
            foreach ( $row as $key => $value ) {
                if ( 'code' === $key ) {
                    $record['code_meta'] = nvx_final_close_value_meta( $value );
                    continue;
                }
                if ( in_array( $key, array( 'description' ), true ) ) {
                    $record[ $key . '_meta' ] = nvx_final_close_value_meta( $value );
                    continue;
                }
                $record[ $key ] = $value;
            }
            $report['snippets']['records'][] = $record;
        }
    }
}

$report['filesystem'] = array(
    'runtime_candidates' => array(),
    'sensitive_candidates' => array(),
);
$root = ABSPATH;
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
    RecursiveIteratorIterator::LEAVES_ONLY
);
$limit = 0;
foreach ( $iterator as $file ) {
    if ( ++$limit > 250000 ) {
        $report['warnings'][] = 'filesystem_scan_limit_reached';
        break;
    }
    if ( ! $file->isFile() ) {
        continue;
    }
    $relative = ltrim( str_replace( $root, '', $file->getPathname() ), DIRECTORY_SEPARATOR );
    $base     = $file->getFilename();
    if ( preg_match( '/(?:\.bak|\.old|\.disabled|_backup|_archive|quarantine)/i', $relative ) ) {
        $report['filesystem']['runtime_candidates'][] = array(
            'path'        => $relative,
            'bytes'       => $file->getSize(),
            'permissions' => substr( sprintf( '%o', $file->getPerms() ), -4 ),
            'sha256'      => hash_file( 'sha256', $file->getPathname() ),
        );
    }
    if ( preg_match( '/(?:wp-config\.php(?:\.|$)|\.env(?:\.|$)|credentials|secret|token)/i', $base ) ) {
        $report['filesystem']['sensitive_candidates'][] = array(
            'path'        => $relative,
            'bytes'       => $file->getSize(),
            'permissions' => substr( sprintf( '%o', $file->getPerms() ), -4 ),
            'sha256'      => hash_file( 'sha256', $file->getPathname() ),
        );
    }
}

$report['summary'] = array(
    'tables'              => count( $report['database']['tables'] ),
    'active_plugins'      => count( $report['plugins']['active'] ),
    'mu_plugins'          => count( $report['mu_plugins'] ),
    'dropins'             => count( $report['plugins']['dropins'] ),
    'large_autoload'      => count( $report['options']['large_autoload'] ),
    'transients'          => count( $report['options']['transients'] ),
    'cron_events'         => count( $report['cron']['events'] ),
    'content_signals'     => count( $report['content']['signals'] ),
    'postmeta_signals'    => count( $report['content']['postmeta'] ),
    'snippet_records'     => count( $report['snippets']['records'] ),
    'runtime_candidates'  => count( $report['filesystem']['runtime_candidates'] ),
    'sensitive_candidates'=> count( $report['filesystem']['sensitive_candidates'] ),
    'failures'            => count( $report['failures'] ),
);

$json = wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
if ( false === $json ) {
    fwrite( STDERR, "FINAL_CLOSE_AUDIT=FAIL reason=json_encode_failed\n" );
    exit( 1 );
}

echo $json . PHP_EOL;
fwrite( STDERR, "FINAL_CLOSE_AUDIT=PASS mode=read_only\n" );
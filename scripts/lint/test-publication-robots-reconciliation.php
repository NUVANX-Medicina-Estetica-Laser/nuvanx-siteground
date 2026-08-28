<?php
/**
 * Static contract for publication-manifest robots reconciliation.
 */

$root          = dirname( __DIR__, 2 );
$manifest_path = $root . '/wp-content/themes/nuvanx-medical/inc/data/publication-manifest.json';
$blog_metadata_path = $root . '/wp-content/themes/nuvanx-medical/inc/data/seo-blog-post-metadata.json';
$migration     = $root . '/tools/migrations/reconcile-publication-robots.php';
$indexables_migration = $root . '/tools/migrations/reconcile-publication-indexables.php';
$yoast_rebuild = $root . '/tools/migrations/run-yoast-indexable-rebuild.php';
$sitemap_selection_audit = $root . '/tools/migrations/audit-publication-sitemap-selection.php';
$sitemap_cache_invalidation = $root . '/tools/migrations/invalidate-publication-sitemap-cache.php';
$runtime_indexables_audit = $root . '/tools/migrations/audit-publication-indexables-runtime.php';
$seo_metadata  = $root . '/wp-content/themes/nuvanx-medical/inc/nvx-seo-metadata.php';
	$seo_retirement = $root . '/wp-content/themes/nuvanx-medical/inc/nvx-seo-legacy-retirement.php';
	$sitemap_from_xml = $root . '/scripts/staging2/verify-publication-sitemap-from-xml.mjs';
	$page_hygiene  = $root . '/wp-content/themes/nuvanx-medical/inc/nvx-page-hygiene.php';
$gracias_behavior = $root . '/scripts/lint/test-gracias-robots-governance.php';
$staging       = $root . '/.github/workflows/staging.yml';
$production    = $root . '/.github/workflows/production.yml';
$deploy        = $root . '/tools/deploy/deploy-to-prod.sh';

$manifest_raw = file_get_contents( $manifest_path );
$manifest     = false === $manifest_raw ? null : json_decode( $manifest_raw, true );
if ( ! is_array( $manifest ) || 'nuvanx-publication-manifest' !== (string) ( $manifest['schema'] ?? '' ) || ! is_array( $manifest['routes'] ?? null ) ) {
	fwrite( STDERR, "PUBLICATION_ROBOTS_RECONCILIATION_STATIC=FAIL reason=manifest_invalid\n" );
	exit( 1 );
}

$blog_metadata_raw = file_get_contents( $blog_metadata_path );
$blog_metadata     = false === $blog_metadata_raw ? null : json_decode( $blog_metadata_raw, true );
if ( ! is_array( $blog_metadata ) ) {
	fwrite( STDERR, "PUBLICATION_ROBOTS_RECONCILIATION_STATIC=FAIL reason=blog_metadata_invalid\n" );
	exit( 1 );
}

$indexable = 0;
$noindex   = 0;
$ids       = array();
foreach ( $manifest['routes'] as $route => $config ) {
	if ( ! is_array( $config ) || 'publish' !== (string) ( $config['status'] ?? '' ) || ! is_array( $config['robots'] ?? null ) ) {
		fwrite( STDERR, "PUBLICATION_ROBOTS_RECONCILIATION_STATIC=FAIL reason=invalid_route route={$route}\n" );
		exit( 1 );
	}
	if ( ! is_bool( $config['robots']['index'] ?? null ) || true !== ( $config['robots']['follow'] ?? null ) ) {
		fwrite( STDERR, "PUBLICATION_ROBOTS_RECONCILIATION_STATIC=FAIL reason=unsupported_robots route={$route}\n" );
		exit( 1 );
	}
	$id = (int) ( $config['post_id'] ?? 0 );
	if ( $id <= 0 || isset( $ids[ $id ] ) ) {
		fwrite( STDERR, "PUBLICATION_ROBOTS_RECONCILIATION_STATIC=FAIL reason=invalid_post_identity route={$route}\n" );
		exit( 1 );
	}
	$ids[ $id ] = true;
	if ( $config['robots']['index'] ) {
		++$indexable;
	} else {
		++$noindex;
	}

	if ( true === $config['robots']['index'] && 'post' === (string) ( $config['post_type'] ?? '' ) ) {
		$slug = (string) ( $config['slug'] ?? '' );
		if ( '' !== $slug && isset( $blog_metadata[ $slug ] ) && is_array( $blog_metadata[ $slug ] ) ) {
			$canonical_path = trim( (string) ( $blog_metadata[ $slug ]['canonical_path'] ?? '' ) );
			if ( '' !== $canonical_path ) {
				$canonical_route = '/' . trim( $canonical_path, '/' ) . '/';
				if ( $canonical_route !== $route ) {
					fwrite( STDERR, "PUBLICATION_ROBOTS_RECONCILIATION_STATIC=FAIL reason=indexable_cross_canonical route={$route} canonical={$canonical_route}\n" );
					exit( 1 );
				}
			}
		}
	}
}

foreach ( array( $migration, $indexables_migration, $yoast_rebuild, $sitemap_selection_audit, $sitemap_cache_invalidation, $runtime_indexables_audit, $seo_metadata, $seo_retirement, $sitemap_from_xml, $page_hygiene, $gracias_behavior, $staging, $production, $deploy ) as $path ) {
	if ( ! is_file( $path ) || false === file_get_contents( $path ) ) {
		fwrite( STDERR, "PUBLICATION_ROBOTS_RECONCILIATION_STATIC=FAIL reason=unreadable_dependency\n" );
		exit( 1 );
	}
}

$migration_raw    = file_get_contents( $migration );
$indexables_migration_raw = file_get_contents( $indexables_migration );
$yoast_rebuild_raw = file_get_contents( $yoast_rebuild );
$sitemap_selection_audit_raw = file_get_contents( $sitemap_selection_audit );
$sitemap_cache_invalidation_raw = file_get_contents( $sitemap_cache_invalidation );
$runtime_indexables_audit_raw = file_get_contents( $runtime_indexables_audit );
$seo_metadata_raw = file_get_contents( $seo_metadata );
$seo_retirement_raw = file_get_contents( $seo_retirement );
$sitemap_from_xml_raw = file_get_contents( $sitemap_from_xml );
$page_hygiene_raw = file_get_contents( $page_hygiene );
$staging_raw      = file_get_contents( $staging );
$production_raw = file_get_contents( $production );
$deploy_raw     = file_get_contents( $deploy );

$required = array(
	array( $migration_raw, "_yoast_wpseo_meta-robots-noindex" ),
	array( $migration_raw, "_yoast_wpseo_meta-robots-nofollow" ),
	array( $migration_raw, "_yoast_wpseo_canonical" ),
	array( $migration_raw, "PUBLICATION_CANONICAL_ROUTE" ),
	array( $migration_raw, '&& \'\' !== $current_canonical' ),
	array( $migration_raw, "delete_post_meta" ),
	array( $migration_raw, "update_post_meta" ),
	array( $migration_raw, "PUBLICATION_ROBOTS_RECONCILIATION=PASS" ),
	array( $indexables_migration_raw, "PUBLICATION_INDEXABLE_RECONCILIATION=PASS" ),
	array( $indexables_migration_raw, "build_for_id_and_type" ),
	array( $indexables_migration_raw, "NVX_ALLOW_STAGING_YOAST_INDEXABLE_REBUILD" ),
	array( $yoast_rebuild_raw, "YOAST_INDEXABLE_REBUILD=PASS" ),
	array( $yoast_rebuild_raw, "WP_CLI::runcommand" ),
	array( $yoast_rebuild_raw, "'launch'     => false" ),
	array( $sitemap_selection_audit_raw, "PUBLICATION_SITEMAP_SELECTION=PASS" ),
	array( $sitemap_selection_audit_raw, "wpseo_exclude_from_sitemap_by_post_ids" ),
	array( $sitemap_selection_audit_raw, "wpseo_sitemap_entry" ),
	array( $sitemap_cache_invalidation_raw, "PUBLICATION_SITEMAP_CACHE_INVALIDATION=PASS" ),
	array( $sitemap_cache_invalidation_raw, "WPSEO_Sitemaps_Cache_Validator::invalidate_storage" ),
	array( $runtime_indexables_audit_raw, "PUBLICATION_INDEXABLE_RUNTIME_AUDIT=PASS" ),
	array( $runtime_indexables_audit_raw, "canonical_mismatch" ),
	array( $runtime_indexables_audit_raw, "nvx_seo_is_nonproduction_environment" ),
	array( $page_hygiene_raw, "sgo_exclude_urls_from_cache" ),
	array( $page_hygiene_raw, "sitemap_index.xml" ),
	array( $page_hygiene_raw, "page-sitemap.xml" ),
	array( $page_hygiene_raw, "post-sitemap.xml" ),
	array( $seo_metadata_raw, "defined( 'WP_CLI' ) && WP_CLI && '1' === getenv( 'NVX_ALLOW_STAGING_YOAST_INDEXABLE_REBUILD' )" ),
	array( $seo_metadata_raw, "Yoast\\\\WP\\\\SEO\\\\should_index_indexables" ),
	array( $seo_metadata_raw, "nvx_seo_allow_controlled_yoast_indexable_rebuild" ),
	array( $seo_retirement_raw, "nvx_seo_retirement_block_divergent_canonical_persistence" ),
	array( $seo_retirement_raw, "add_post_metadata" ),
	array( $seo_retirement_raw, "update_post_metadata" ),
	array( $seo_retirement_raw, "_yoast_wpseo_canonical" ),
	array( $sitemap_from_xml_raw, "SITEMAP_XML_CONTENT" ),
	array( $sitemap_from_xml_raw, "SITEMAP_MANIFEST_COVERAGE=PASS" ),
	array( $staging_raw, "verify-publication-sitemap-from-xml.mjs" ),
	array( $staging_raw, "SITEMAP_XML_CONTENT" ),
	array( $staging_raw, "reconcile-publication-robots.php" ),
	array( $staging_raw, 'REMOTE_RELEASE=\'$REMOTE_RELEASE\' bash -se' ),
	array( $staging_raw, '"$REMOTE_RELEASE/migration-robots-${{ github.sha }}.log"' ),
	array( $staging_raw, 'original_env="$(wp config get WP_ENVIRONMENT_TYPE)"' ),
	array( $staging_raw, '[[ "$original_env" == \'staging\' ]]' ),
	array( $staging_raw, 'wp config set WP_ENVIRONMENT_TYPE production' ),
	array( $staging_raw, 'wp config set WP_ENVIRONMENT_TYPE "$original_env"' ),
	array( $staging_raw, 'NVX_ALLOW_STAGING_YOAST_INDEXABLE_REBUILD=1 wp eval-file "$REMOTE_RELEASE/tools/migrations/run-yoast-indexable-rebuild.php" --allow-root' ),
	array( $staging_raw, "run-yoast-indexable-rebuild.php" ),
	array( $staging_raw, "YOAST_INDEXABLE_REBUILD=PASS" ),
	array( $staging_raw, "audit-publication-indexables-runtime.php" ),
	array( $staging_raw, "PUBLICATION_INDEXABLE_RUNTIME_AUDIT=PASS" ),
	array( $staging_raw, "reconcile-publication-indexables.php" ),
	array( $staging_raw, "PUBLICATION_INDEXABLE_RECONCILIATION=PASS" ),
	array( $staging_raw, "invalidate-publication-sitemap-cache.php" ),
	array( $staging_raw, "PUBLICATION_SITEMAP_CACHE_INVALIDATION=PASS" ),
	array( $staging_raw, "audit-publication-sitemap-selection.php" ),
	array( $staging_raw, "PUBLICATION_SITEMAP_SELECTION=PASS" ),
	array( $staging_raw, "verify-publication-sitemap-from-xml.mjs" ),
	array( $staging_raw, "STAGING_SITEMAP_MANIFEST_COVERAGE=PASS" ),
	array( $staging_raw, "Keep Optimizer active" ),
	array( $staging_raw, 'wp plugin is-active "$plugin_slug"' ),
	array( $production_raw, "reconcile-publication-robots.php" ),
	array( $deploy_raw, "ROBOTS_RECONCILIATION_SCRIPT" ),
	array( $deploy_raw, "INDEXABLES_RECONCILIATION_SCRIPT" ),
	array( $deploy_raw, "YOAST_INDEXABLE_REBUILD_SCRIPT" ),
	array( $deploy_raw, "run-yoast-indexable-rebuild.php" ),
	array( $deploy_raw, "SITEMAP_SELECTION_AUDIT_SCRIPT" ),
	array( $deploy_raw, "audit-publication-sitemap-selection.php" ),
	array( $deploy_raw, "SITEMAP_CACHE_INVALIDATION_SCRIPT" ),
	array( $deploy_raw, "invalidate-publication-sitemap-cache.php" ),
);
foreach ( $required as $pair ) {
	if ( false === strpos( $pair[0], $pair[1] ) ) {
		fwrite( STDERR, "PUBLICATION_ROBOTS_RECONCILIATION_STATIC=FAIL reason=missing_contract_marker marker={$pair[1]}\n" );
		exit( 1 );
	}
}

$behavior_output = array();
$behavior_code   = 0;
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $gracias_behavior ), $behavior_output, $behavior_code );
if ( 0 !== $behavior_code ) {
	fwrite( STDERR, implode( "\n", $behavior_output ) . "\n" );
	fwrite( STDERR, "PUBLICATION_ROBOTS_RECONCILIATION_STATIC=FAIL reason=gracias_behavioral_contract\n" );
	exit( 1 );
}
foreach ( $behavior_output as $line ) {
	echo $line . "\n";
}

printf(
	"PUBLICATION_ROBOTS_RECONCILIATION_STATIC=PASS routes=%d indexable=%d noindex=%d\n",
	count( $manifest['routes'] ),
	$indexable,
	$noindex
);

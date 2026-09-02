<?php
/**
 * Canonical Staging2 content-hygiene orchestrator.
 *
 * The historical hygiene implementation is retained as an internal core. H1
 * editorial seed reconciliation is planned and validated before that core can
 * mutate content, then applied atomically after the core succeeds. This file is
 * the single executable owner invoked by the rollback-protected Staging job.
 *
 * @package nuvanx-medical
 */

if ( 'cli' !== PHP_SAPI || ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "[FATAL] canonical Staging2 content hygiene requires WP-CLI.\n" );
	echo "Status: STAGING_ONLY_ABORT\n";
	exit( 1 );
}

$nvx_staging_identity = array(
	'db_name'        => defined( 'DB_NAME' ) ? (string) DB_NAME : '',
	'home'           => (string) get_option( 'home' ),
	'siteurl'        => (string) get_option( 'siteurl' ),
	'nvx_env'        => defined( 'NVX_ENV' ) ? (string) NVX_ENV : '',
	'wp_environment' => function_exists( 'wp_get_environment_type' ) ? (string) wp_get_environment_type() : '',
);

$nvx_is_staging2_cli = 'dbshcocboodiwr' === $nvx_staging_identity['db_name']
	&& 'https://staging2.nuvanx.com' === $nvx_staging_identity['home']
	&& 'https://staging2.nuvanx.com' === $nvx_staging_identity['siteurl']
	&& 'staging' === $nvx_staging_identity['nvx_env']
	&& 'staging' === $nvx_staging_identity['wp_environment'];

if ( ! $nvx_is_staging2_cli ) {
	fwrite( STDERR, "[FATAL] canonical Staging2 identity mismatch. Aborting before content mutation.\n" );
	echo "Status: STAGING_ONLY_ABORT\n";
	exit( 1 );
}

require_once __DIR__ . '/h1-content-seed-reconciliation.php';

$nvx_h1_plan = nvx_h1_build_plan();
foreach ( $nvx_h1_plan['errors'] as $error ) {
	printf(
		"H1_SEED_VALIDATION_FAIL scope=%s action=%s slug=%s\n",
		sanitize_key( (string) ( $error['scope'] ?? '' ) ),
		sanitize_key( (string) ( $error['action'] ?? '' ) ),
		sanitize_title( (string) ( $error['slug'] ?? '' ) )
	);
}
if ( ! empty( $nvx_h1_plan['errors'] ) ) {
	echo "H1_SEED_RECONCILIATION=FAIL reason=prevalidation\n";
	echo "Status: MIGRATION_FAIL\n";
	exit( 1 );
}

$nvx_release_path = str_replace( '\\', '/', __FILE__ );
$nvx_in_release   = str_contains( $nvx_release_path, '/wp-content/.nuvanx-deployments/' );
$nvx_dry_env      = getenv( 'MIGRATION_DRY_RUN' );
if ( '1' === $nvx_dry_env ) {
	$nvx_dry_run = true;
} elseif ( '0' === $nvx_dry_env ) {
	$nvx_dry_run = false;
} else {
	// Canonical workflow payloads run live under a verified rollback snapshot.
	// Ad-hoc/manual executions default to dry-run unless explicitly armed.
	$nvx_dry_run = ! $nvx_in_release;
}

printf(
	"H1_SEED_PLAN=PASS ops=%d noops=%d mode=%s owner=content-hygiene-staging-only\n",
	count( $nvx_h1_plan['ops'] ),
	count( $nvx_h1_plan['noops'] ),
	$nvx_dry_run ? 'DRY_RUN' : 'LIVE'
);
foreach ( $nvx_h1_plan['ops'] as $operation ) {
	printf(
		"H1_SEED_PLAN_ITEM scope=%s action=%s slug=%s\n",
		sanitize_key( (string) ( $operation['scope'] ?? '' ) ),
		sanitize_key( (string) ( $operation['action'] ?? '' ) ),
		sanitize_title( (string) ( $operation['slug'] ?? '' ) )
	);
}

$core_path = __DIR__ . '/content-hygiene-staging-core.php';
if ( ! is_file( $core_path ) || ! is_readable( $core_path ) ) {
	fwrite( STDERR, "[FATAL] canonical Staging hygiene core is unavailable.\n" );
	echo "Status: MIGRATION_FAIL\n";
	exit( 1 );
}

$core_command = sprintf(
	'MIGRATION_DRY_RUN=%s wp eval-file %s --allow-root',
	$nvx_dry_run ? '1' : '0',
	escapeshellarg( $core_path )
);
passthru( $core_command, $core_status );
if ( 0 !== $core_status ) {
	fwrite( STDERR, "[FATAL] canonical Staging hygiene core failed.\n" );
	echo "H1_SEED_RECONCILIATION=FAIL reason=hygiene_core\n";
	echo "Status: MIGRATION_FAIL\n";
	exit( 1 );
}

if ( $nvx_dry_run ) {
	echo "H1_SEED_RECONCILIATION=PASS mode=DRY_RUN writes=0\n";
	echo "Status: MIGRATION_OK\n";
	exit( 0 );
}

// The core runs in a child WP-CLI process; discard any pre-core object cache
// before enforcing the prevalidated H1 plan against its persisted result.
wp_cache_flush();

try {
	$nvx_h1_result = nvx_h1_apply_plan( $nvx_h1_plan );
} catch ( Throwable $error ) {
	fwrite( STDERR, '[FATAL] H1 seed reconciliation failed: ' . sanitize_key( $error->getMessage() ) . "\n" );
	echo "H1_SEED_RECONCILIATION=FAIL reason=apply\n";
	echo "Status: MIGRATION_FAIL\n";
	exit( 1 );
}

if ( 0 === (int) $nvx_h1_result['planned'] ) {
	echo "H1_SEED_RECONCILIATION=NOOP writes=0\n";
} else {
	printf(
		"H1_SEED_RECONCILIATION=PASS planned=%d applied=%d\n",
		(int) $nvx_h1_result['planned'],
		(int) $nvx_h1_result['applied']
	);
}

echo "Status: MIGRATION_OK\n";
exit( 0 );

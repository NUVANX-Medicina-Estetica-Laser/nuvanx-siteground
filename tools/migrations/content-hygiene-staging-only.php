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

/** Whether one plan error is the bounded legacy Bridal partial-provenance case. */
function nvx_h1_is_bridal_partial_provenance_error( array $error ): bool {
	return 'bridal' === (string) ( $error['scope'] ?? '' )
		&& 'provenance_mismatch' === (string) ( $error['action'] ?? '' )
		&& 'protocolo-novias-madrid' === (string) ( $error['slug'] ?? '' );
}

/**
 * Classify the only two safe Bridal partial-provenance repairs.
 *
 * Production-synchronized editorial content can legitimately leave the old
 * Staging-only seed meta behind. Conversely, the exact legacy HTML marker is
 * sufficient to recover a missing technical key. Any conflicting non-empty
 * key remains fail-closed.
 *
 * @return array{action:string,id:int}
 */
function nvx_h1_bridal_partial_provenance_repair(): array {
	$bridal = get_page_by_path( 'protocolo-novias-madrid', OBJECT, 'page' );
	if ( ! ( $bridal instanceof WP_Post ) ) {
		return array( 'action' => 'ambiguous', 'id' => 0 );
	}

	$seed_key   = trim( (string) get_post_meta( $bridal->ID, '_nvx_aesthetic_treatment_key', true ) );
	$content    = (string) $bridal->post_content;
	$has_meta   = 'bridal_protocol' === $seed_key;
	$has_marker = str_contains( $content, 'data-nvx-treatment="bridal_protocol"' )
		|| str_contains( $content, "data-nvx-treatment='bridal_protocol'" );

	if ( $has_meta && ! $has_marker ) {
		return array( 'action' => 'clear_stale_meta', 'id' => (int) $bridal->ID );
	}
	if ( ! $has_meta && $has_marker && '' === $seed_key ) {
		return array( 'action' => 'restore_seed_meta', 'id' => (int) $bridal->ID );
	}

	return array( 'action' => 'ambiguous', 'id' => (int) $bridal->ID );
}

/** Apply one prevalidated Bridal metadata repair and verify its postcondition. */
function nvx_h1_apply_bridal_partial_provenance_repair( array $repair ): void {
	$post_id = (int) ( $repair['id'] ?? 0 );
	$action  = (string) ( $repair['action'] ?? '' );
	$post    = get_post( $post_id );
	if ( ! ( $post instanceof WP_Post ) || 'protocolo-novias-madrid' !== (string) $post->post_name ) {
		throw new RuntimeException( 'bridal_repair_post_changed' );
	}

	$seed_key   = trim( (string) get_post_meta( $post_id, '_nvx_aesthetic_treatment_key', true ) );
	$content    = (string) $post->post_content;
	$has_marker = str_contains( $content, 'data-nvx-treatment="bridal_protocol"' )
		|| str_contains( $content, "data-nvx-treatment='bridal_protocol'" );

	if ( 'clear_stale_meta' === $action ) {
		if ( 'bridal_protocol' !== $seed_key || $has_marker ) {
			throw new RuntimeException( 'bridal_stale_meta_precondition_changed' );
		}
		delete_post_meta( $post_id, '_nvx_aesthetic_treatment_key' );
		if ( '' !== trim( (string) get_post_meta( $post_id, '_nvx_aesthetic_treatment_key', true ) ) ) {
			throw new RuntimeException( 'bridal_stale_meta_cleanup_failed' );
		}
		return;
	}

	if ( 'restore_seed_meta' === $action ) {
		if ( '' !== $seed_key || ! $has_marker ) {
			throw new RuntimeException( 'bridal_seed_meta_precondition_changed' );
		}
		update_post_meta( $post_id, '_nvx_aesthetic_treatment_key', 'bridal_protocol' );
		if ( 'bridal_protocol' !== (string) get_post_meta( $post_id, '_nvx_aesthetic_treatment_key', true ) ) {
			throw new RuntimeException( 'bridal_seed_meta_restore_failed' );
		}
		return;
	}

	throw new RuntimeException( 'bridal_partial_provenance_ambiguous' );
}

$nvx_h1_plan           = nvx_h1_build_plan();
$nvx_bridal_mismatches = array_values( array_filter( $nvx_h1_plan['errors'], 'nvx_h1_is_bridal_partial_provenance_error' ) );
$nvx_other_errors      = array_values(
	array_filter(
		$nvx_h1_plan['errors'],
		static fn( array $error ): bool => ! nvx_h1_is_bridal_partial_provenance_error( $error )
	)
);

// Never mutate metadata while any unrelated H1 prevalidation error exists.
if ( ! empty( $nvx_other_errors ) ) {
	foreach ( $nvx_h1_plan['errors'] as $error ) {
		printf(
			"H1_SEED_VALIDATION_FAIL scope=%s action=%s slug=%s\n",
			sanitize_key( (string) ( $error['scope'] ?? '' ) ),
			sanitize_key( (string) ( $error['action'] ?? '' ) ),
			sanitize_title( (string) ( $error['slug'] ?? '' ) )
		);
	}
	echo "H1_SEED_RECONCILIATION=FAIL reason=prevalidation\n";
	echo "Status: MIGRATION_FAIL\n";
	exit( 1 );
}

if ( ! empty( $nvx_bridal_mismatches ) ) {
	$nvx_bridal_repair = nvx_h1_bridal_partial_provenance_repair();
	if ( 'ambiguous' === $nvx_bridal_repair['action'] ) {
		echo "H1_SEED_VALIDATION_FAIL scope=bridal action=provenance_mismatch slug=protocolo-novias-madrid\n";
		echo "H1_SEED_RECONCILIATION=FAIL reason=bridal_partial_provenance_ambiguous\n";
		echo "Status: MIGRATION_FAIL\n";
		exit( 1 );
	}

	printf(
		"H1_BRIDAL_PROVENANCE_REPAIR=%s action=%s writes=%d\n",
		$nvx_dry_run ? 'PLAN' : 'APPLY',
		sanitize_key( $nvx_bridal_repair['action'] ),
		$nvx_dry_run ? 0 : 1
	);

	if ( $nvx_dry_run ) {
		// The mismatch is known-safe but remains unmodified in dry-run mode.
		$nvx_h1_plan['errors'] = array();
	} else {
		try {
			nvx_h1_apply_bridal_partial_provenance_repair( $nvx_bridal_repair );
		} catch ( Throwable $error ) {
			fwrite( STDERR, '[FATAL] Bridal provenance repair failed: ' . sanitize_key( $error->getMessage() ) . "\n" );
			echo "H1_SEED_RECONCILIATION=FAIL reason=bridal_repair\n";
			echo "Status: MIGRATION_FAIL\n";
			exit( 1 );
		}
		wp_cache_flush();
		$nvx_h1_plan = nvx_h1_build_plan();
	}
}

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

// The wrapper is the sole owner of the public "Status:" contract. Stream the
// child output through this process so its historical Status line is fenced but
// diagnostics remain visible even if the child hangs or the wrapper is killed.
$core_handle = popen( $core_command . ' 2>&1', 'r' );
if ( false === $core_handle ) {
	fwrite( STDERR, "[FATAL] canonical Staging hygiene core could not start.\n" );
	echo "H1_SEED_RECONCILIATION=FAIL reason=hygiene_core_start\n";
	echo "Status: MIGRATION_FAIL\n";
	exit( 1 );
}
while ( ! feof( $core_handle ) ) {
	$core_line = fgets( $core_handle );
	if ( false === $core_line ) {
		continue;
	}
	$core_line = rtrim( (string) $core_line, "\r\n" );
	if ( str_starts_with( $core_line, 'Status: ' ) ) {
		$core_token = preg_replace( '/[^A-Z0-9_:-]/', '', substr( $core_line, 8 ) );
		printf( "H1_HYGIENE_CORE_STATUS=%s\n", is_string( $core_token ) ? $core_token : 'INVALID' );
		continue;
	}
	echo $core_line . "\n";
}
$core_status = pclose( $core_handle );
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

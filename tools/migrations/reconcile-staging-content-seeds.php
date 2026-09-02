<?php
/**
 * Reconcile legacy Staging2 content seeds outside public runtime.
 *
 * This file is executed through `wp eval-file`; do not add strict_types here.
 * WP-CLI evaluates the file inside an existing PHP execution context.
 *
 * Dry run (default):
 *   MIGRATION_DRY_RUN=1 wp eval-file tools/migrations/reconcile-staging-content-seeds.php --allow-root
 *
 * Apply explicitly:
 *   MIGRATION_DRY_RUN=0 wp eval-file tools/migrations/reconcile-staging-content-seeds.php --allow-root
 *
 * @package nuvanx-medical
 */

if ( 'cli' !== PHP_SAPI || ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "H1_SEED_RECONCILIATION=FAIL reason=wp_cli_required\n" );
	exit( 1 );
}

$nvx_h1_identity = array(
	'db_name'        => defined( 'DB_NAME' ) ? (string) DB_NAME : '',
	'home'           => (string) get_option( 'home' ),
	'siteurl'        => (string) get_option( 'siteurl' ),
	'nvx_env'        => defined( 'NVX_ENV' ) ? (string) NVX_ENV : '',
	'wp_environment' => function_exists( 'wp_get_environment_type' ) ? (string) wp_get_environment_type() : '',
);

$nvx_h1_is_staging2 = 'dbshcocboodiwr' === $nvx_h1_identity['db_name']
	&& 'https://staging2.nuvanx.com' === $nvx_h1_identity['home']
	&& 'https://staging2.nuvanx.com' === $nvx_h1_identity['siteurl']
	&& 'staging' === $nvx_h1_identity['nvx_env']
	&& 'staging' === $nvx_h1_identity['wp_environment'];

if ( ! $nvx_h1_is_staging2 ) {
	fwrite( STDERR, "H1_SEED_RECONCILIATION=FAIL reason=staging_identity_mismatch\n" );
	exit( 1 );
}

$nvx_h1_required_functions = array(
	'nvx_aesthetic_treatment_catalog',
	'nvx_strategy_page_catalog',
	'nvx_journal_tech_article_map',
	'nvx_journal_tech_article_catalog',
);
foreach ( $nvx_h1_required_functions as $nvx_h1_function ) {
	if ( ! function_exists( $nvx_h1_function ) ) {
		fwrite( STDERR, 'H1_SEED_RECONCILIATION=FAIL reason=dependency_missing function=' . sanitize_key( $nvx_h1_function ) . "\n" );
		exit( 1 );
	}
}
unset( $nvx_h1_function );

/** @return array{planned:int,applied:int,noop:int,failures:int} */
function nvx_h1_seed_stats(): array {
	return array(
		'planned'  => 0,
		'applied'  => 0,
		'noop'     => 0,
		'failures' => 0,
	);
}

/** Emit one bounded migration event. */
function nvx_h1_seed_event( string $state, string $scope, string $action, string $slug ): void {
	printf(
		'H1_SEED_%s scope=%s action=%s slug=%s' . "\n",
		strtoupper( sanitize_key( $state ) ),
		sanitize_key( $scope ),
		sanitize_key( $action ),
		sanitize_title( $slug )
	);
}

/** Record an idempotent no-op. */
function nvx_h1_seed_noop( string $scope, string $reason, string $slug, array &$stats ): void {
	++$stats['noop'];
	nvx_h1_seed_event( 'noop', $scope, $reason, $slug );
}

/** Record a fail-closed inconsistency without mutating data. */
function nvx_h1_seed_failure( string $scope, string $reason, string $slug, array &$stats ): void {
	++$stats['failures'];
	nvx_h1_seed_event( 'fail', $scope, $reason, $slug );
}

/** Execute one mutation only in explicit live mode and verify its persisted result. */
function nvx_h1_seed_mutation(
	bool $dry_run,
	string $scope,
	string $action,
	string $slug,
	callable $write,
	callable $verify,
	array &$stats
): void {
	++$stats['planned'];
	nvx_h1_seed_event( $dry_run ? 'plan' : 'apply', $scope, $action, $slug );

	if ( $dry_run ) {
		return;
	}

	try {
		$write_ok = true === $write();
		$verified = $write_ok && true === $verify();
	} catch ( Throwable $error ) {
		unset( $error );
		$verified = false;
	}

	if ( $verified ) {
		++$stats['applied'];
		nvx_h1_seed_event( 'verified', $scope, $action, $slug );
		return;
	}

	++$stats['failures'];
	nvx_h1_seed_event( 'fail', $scope, $action . '_post_write_verification', $slug );
}

/** Exact marker used only by legacy strategy seeds. */
function nvx_h1_strategy_marker( string $key ): string {
	return '<!-- NUVANX_STRATEGY_PAGE:' . sanitize_key( $key ) . ' -->';
}

/** Reconcile strategy page seeds without overwriting editorial pages. */
function nvx_h1_reconcile_strategy( bool $dry_run, array &$stats ): void {
	foreach ( nvx_strategy_page_catalog() as $raw_key => $page ) {
		if ( ! is_array( $page ) ) {
			nvx_h1_seed_failure( 'strategy', 'invalid_catalog_record', (string) $raw_key, $stats );
			continue;
		}

		$key           = sanitize_key( (string) $raw_key );
		$slug          = sanitize_title( (string) ( $page['slug'] ?? '' ) );
		$title         = wp_strip_all_tags( (string) ( $page['title'] ?? '' ) );
		$review_status = sanitize_key( (string) ( $page['review_status'] ?? '' ) );
		$marker        = nvx_h1_strategy_marker( $key );

		if ( '' === $key || '' === $slug || '' === $title || '' === $review_status ) {
			nvx_h1_seed_failure( 'strategy', 'invalid_catalog_record', $slug ?: $key, $stats );
			continue;
		}

		$existing = get_page_by_path( $slug, OBJECT, 'page' );
		if ( $existing instanceof WP_Post ) {
			if ( ! str_contains( (string) $existing->post_content, $marker ) ) {
				nvx_h1_seed_noop( 'strategy', 'existing_editorial', $slug, $stats );
				continue;
			}

			$current = (string) get_post_meta( $existing->ID, '_nvx_strategy_review_status', true );
			if ( $review_status === $current ) {
				nvx_h1_seed_noop( 'strategy', 'seed_current', $slug, $stats );
				continue;
			}

			nvx_h1_seed_mutation(
				$dry_run,
				'strategy',
				'update_seed_review_meta',
				$slug,
				static function () use ( $existing, $review_status ): bool {
					update_post_meta( $existing->ID, '_nvx_strategy_review_status', $review_status );
					return true;
				},
				static function () use ( $existing, $review_status ): bool {
					return $review_status === (string) get_post_meta( $existing->ID, '_nvx_strategy_review_status', true );
				},
				$stats
			);
			continue;
		}

		$created_id = 0;
		nvx_h1_seed_mutation(
			$dry_run,
			'strategy',
			'create_seed',
			$slug,
			static function () use ( $slug, $title, $marker, $review_status, &$created_id ): bool {
				$result = wp_insert_post(
					array(
						'post_type'    => 'page',
						'post_status'  => 'publish',
						'post_title'   => $title,
						'post_name'    => $slug,
						'post_content' => $marker,
					),
					true
				);
				if ( is_wp_error( $result ) || (int) $result <= 0 ) {
					return false;
				}
				$created_id = (int) $result;
				update_post_meta( $created_id, '_nvx_strategy_review_status', $review_status );
				return true;
			},
			static function () use ( $slug, $marker, $review_status, &$created_id ): bool {
				$created = $created_id > 0 ? get_post( $created_id ) : get_page_by_path( $slug, OBJECT, 'page' );
				return $created instanceof WP_Post
					&& str_contains( (string) $created->post_content, $marker )
					&& $review_status === (string) get_post_meta( $created->ID, '_nvx_strategy_review_status', true );
			},
			$stats
		);
	}
}

/** Exact marker used only by legacy aesthetic seeds. */
function nvx_h1_aesthetic_marker( string $key ): string {
	return sprintf(
		'<div class="nvx-aesthetic-treatment-source" data-nvx-treatment="%s"></div>',
		esc_attr( sanitize_key( $key ) )
	);
}

/** Whether a page proves exact legacy aesthetic-seed provenance. */
function nvx_h1_is_aesthetic_seed( WP_Post $post, string $key ): bool {
	$key     = sanitize_key( $key );
	$content = (string) $post->post_content;
	return '' !== $key
		&& str_contains( $content, 'nvx-aesthetic-treatment-source' )
		&& (
			str_contains( $content, 'data-nvx-treatment="' . $key . '"' )
			|| str_contains( $content, "data-nvx-treatment='" . $key . "'" )
		);
}

/** Reconcile aesthetic treatment seeds without overwriting editorial pages. */
function nvx_h1_reconcile_aesthetic( bool $dry_run, array &$stats ): void {
	foreach ( nvx_aesthetic_treatment_catalog() as $raw_key => $page ) {
		if ( ! is_array( $page ) ) {
			nvx_h1_seed_failure( 'aesthetic', 'invalid_catalog_record', (string) $raw_key, $stats );
			continue;
		}

		$key     = sanitize_key( (string) $raw_key );
		$slug    = sanitize_title( (string) ( $page['slug'] ?? '' ) );
		$title   = wp_strip_all_tags( (string) ( $page['h1'] ?? '' ) );
		$excerpt = sanitize_textarea_field( (string) ( $page['description'] ?? '' ) );

		if ( '' === $key || '' === $slug || '' === $title ) {
			nvx_h1_seed_failure( 'aesthetic', 'invalid_catalog_record', $slug ?: $key, $stats );
			continue;
		}

		$existing = get_page_by_path( $slug, OBJECT, 'page' );
		if ( $existing instanceof WP_Post ) {
			if ( ! nvx_h1_is_aesthetic_seed( $existing, $key ) ) {
				nvx_h1_seed_noop( 'aesthetic', 'existing_editorial', $slug, $stats );
				continue;
			}

			$current_key    = (string) get_post_meta( $existing->ID, '_nvx_aesthetic_treatment_key', true );
			$current_review = (string) get_post_meta( $existing->ID, '_nvx_medical_review_status', true );
			if ( $key === $current_key && 'pending' === $current_review ) {
				nvx_h1_seed_noop( 'aesthetic', 'seed_current', $slug, $stats );
				continue;
			}

			nvx_h1_seed_mutation(
				$dry_run,
				'aesthetic',
				'repair_seed_meta',
				$slug,
				static function () use ( $existing, $key ): bool {
					update_post_meta( $existing->ID, '_nvx_aesthetic_treatment_key', $key );
					update_post_meta( $existing->ID, '_nvx_medical_review_status', 'pending' );
					return true;
				},
				static function () use ( $existing, $key ): bool {
					return $key === (string) get_post_meta( $existing->ID, '_nvx_aesthetic_treatment_key', true )
						&& 'pending' === (string) get_post_meta( $existing->ID, '_nvx_medical_review_status', true );
				},
				$stats
			);
			continue;
		}

		$marker     = nvx_h1_aesthetic_marker( $key );
		$created_id = 0;
		nvx_h1_seed_mutation(
			$dry_run,
			'aesthetic',
			'create_seed',
			$slug,
			static function () use ( $key, $slug, $title, $excerpt, $marker, &$created_id ): bool {
				$result = wp_insert_post(
					array(
						'post_title'   => $title,
						'post_name'    => $slug,
						'post_excerpt' => $excerpt,
						'post_content' => $marker,
						'post_status'  => 'publish',
						'post_type'    => 'page',
					),
					true
				);
				if ( is_wp_error( $result ) || (int) $result <= 0 ) {
					return false;
				}
				$created_id = (int) $result;
				update_post_meta( $created_id, '_nvx_aesthetic_treatment_key', $key );
				update_post_meta( $created_id, '_nvx_medical_review_status', 'pending' );
				return true;
			},
			static function () use ( $key, $slug, &$created_id ): bool {
				$created = $created_id > 0 ? get_post( $created_id ) : get_page_by_path( $slug, OBJECT, 'page' );
				return $created instanceof WP_Post
					&& nvx_h1_is_aesthetic_seed( $created, $key )
					&& $key === (string) get_post_meta( $created->ID, '_nvx_aesthetic_treatment_key', true )
					&& 'pending' === (string) get_post_meta( $created->ID, '_nvx_medical_review_status', true );
			},
			$stats
		);
	}
}

/** Reconcile journal seeds without modifying existing posts. */
function nvx_h1_reconcile_journal( bool $dry_run, array &$stats ): void {
	foreach ( nvx_journal_tech_article_map() as $raw_slug => $meta ) {
		if ( ! is_array( $meta ) ) {
			nvx_h1_seed_failure( 'journal', 'invalid_catalog_record', (string) $raw_slug, $stats );
			continue;
		}

		$slug   = sanitize_title( (string) $raw_slug );
		$marker = (string) ( $meta['marker'] ?? '' );
		if ( '' === $slug || '' === $marker ) {
			nvx_h1_seed_failure( 'journal', 'invalid_catalog_record', $slug, $stats );
			continue;
		}

		$existing = get_page_by_path( $slug, OBJECT, 'post' );
		if ( $existing instanceof WP_Post ) {
			nvx_h1_seed_noop( 'journal', 'existing_post', $slug, $stats );
			continue;
		}

		$data = nvx_journal_tech_article_catalog( $slug );
		if ( ! is_array( $data ) || array() === $data ) {
			nvx_h1_seed_failure( 'journal', 'catalog_unavailable', $slug, $stats );
			continue;
		}

		$title      = wp_strip_all_tags( (string) ( $data['title'] ?? $slug ) );
		$excerpt    = sanitize_textarea_field( (string) ( $data['excerpt'] ?? '' ) );
		$created_id = 0;
		nvx_h1_seed_mutation(
			$dry_run,
			'journal',
			'create_seed',
			$slug,
			static function () use ( $slug, $title, $excerpt, $marker, &$created_id ): bool {
				$result = wp_insert_post(
					array(
						'post_type'    => 'post',
						'post_status'  => 'publish',
						'post_title'   => $title,
						'post_excerpt' => $excerpt,
						'post_name'    => $slug,
						'post_content' => $marker,
					),
					true
				);
				if ( is_wp_error( $result ) || (int) $result <= 0 ) {
					return false;
				}
				$created_id = (int) $result;
				return true;
			},
			static function () use ( $slug, $marker, &$created_id ): bool {
				$created = $created_id > 0 ? get_post( $created_id ) : get_page_by_path( $slug, OBJECT, 'post' );
				return $created instanceof WP_Post && str_contains( (string) $created->post_content, $marker );
			},
			$stats
		);
	}
}

/** Retire only the exact legacy Bridal seed, never an editorial page. */
function nvx_h1_reconcile_bridal( bool $dry_run, array &$stats ): void {
	$slug = 'protocolo-novias-madrid';
	$page = get_page_by_path( $slug, OBJECT, 'page' );
	if ( ! ( $page instanceof WP_Post ) ) {
		nvx_h1_seed_noop( 'bridal', 'page_absent', $slug, $stats );
		return;
	}

	$seed_key   = (string) get_post_meta( $page->ID, '_nvx_aesthetic_treatment_key', true );
	$content    = (string) $page->post_content;
	$has_meta   = 'bridal_protocol' === $seed_key;
	$has_marker = str_contains( $content, 'data-nvx-treatment="bridal_protocol"' )
		|| str_contains( $content, "data-nvx-treatment='bridal_protocol'" );

	if ( $has_meta !== $has_marker ) {
		nvx_h1_seed_failure( 'bridal', 'provenance_mismatch', $slug, $stats );
		return;
	}
	if ( ! $has_meta ) {
		nvx_h1_seed_noop( 'bridal', 'existing_editorial', $slug, $stats );
		return;
	}
	if ( in_array( $page->post_status, array( 'draft', 'trash' ), true ) ) {
		nvx_h1_seed_noop( 'bridal', 'already_retired', $slug, $stats );
		return;
	}

	nvx_h1_seed_mutation(
		$dry_run,
		'bridal',
		'retire_exact_seed',
		$slug,
		static function () use ( $page ): bool {
			$result = wp_update_post(
				array(
					'ID'          => $page->ID,
					'post_status' => 'draft',
				),
				true
			);
			return ! is_wp_error( $result );
		},
		static function () use ( $page ): bool {
			return 'draft' === get_post_status( $page->ID );
		},
		$stats
	);
}

$nvx_h1_dry_run = '0' !== getenv( 'MIGRATION_DRY_RUN' );
$nvx_h1_stats   = nvx_h1_seed_stats();

printf(
	"H1_SEED_RECONCILIATION_MODE=%s site=staging2.nuvanx.com\n",
	$nvx_h1_dry_run ? 'DRY_RUN' : 'LIVE'
);

nvx_h1_reconcile_strategy( $nvx_h1_dry_run, $nvx_h1_stats );
nvx_h1_reconcile_aesthetic( $nvx_h1_dry_run, $nvx_h1_stats );
nvx_h1_reconcile_journal( $nvx_h1_dry_run, $nvx_h1_stats );
nvx_h1_reconcile_bridal( $nvx_h1_dry_run, $nvx_h1_stats );

printf(
	"H1_SEED_RECONCILIATION_SUMMARY mode=%s planned=%d applied=%d noop=%d failures=%d\n",
	$nvx_h1_dry_run ? 'dry-run' : 'live',
	$nvx_h1_stats['planned'],
	$nvx_h1_stats['applied'],
	$nvx_h1_stats['noop'],
	$nvx_h1_stats['failures']
);

if ( $nvx_h1_stats['failures'] > 0 ) {
	echo "H1_SEED_RECONCILIATION=FAIL\n";
	exit( 1 );
}

if ( 0 === $nvx_h1_stats['planned'] ) {
	echo "H1_SEED_RECONCILIATION=NOOP\n";
	exit( 0 );
}

echo "H1_SEED_RECONCILIATION=PASS\n";
exit( 0 );

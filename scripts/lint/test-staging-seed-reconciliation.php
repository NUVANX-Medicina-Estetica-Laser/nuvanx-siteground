<?php
/**
 * Behavioral verification for Staging2 seed reconciliation migration:
 * - Preservation of valid medical approval metadata on marked aesthetic seeds.
 * - Repair of missing treatment keys while preserving valid approval state.
 * - Reset to pending on invalid/incomplete approval metadata.
 * - Fallible update_post_meta and rollback of inserted post on write failure.
 * - No-op preservation of editorial pages.
 *
 * @package nuvanx-medical
 */

declare(strict_types=1);

$root           = dirname( __DIR__, 2 );
$migration_file = $root . '/tools/migrations/reconcile-staging-content-seeds.php';

if ( ! is_file( $migration_file ) ) {
	fwrite( STDERR, "Missing migration file: {$migration_file}\n" );
	exit( 1 );
}

if ( ! isset( $argv[1] ) ) {
	$scenarios = array(
		'approved_seed_preserved',
		'approved_seed_repair_key',
		'invalid_approval_reset',
		'meta_failure_rollback',
		'editorial_preserved',
	);

	foreach ( $scenarios as $scenario ) {
		$output  = array();
		$code    = 0;
		$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $scenario );
		exec( $command, $output, $code );
		if ( 0 !== $code ) {
			fwrite( STDERR, "Scenario {$scenario} FAILED with exit code {$code}:\n" . implode( "\n", $output ) . "\n" );
			exit( 1 );
		}
	}

	echo "STAGING_SEED_RECONCILIATION_TEST=PASS scenarios=5 approved_metadata_preserved=1 rollback_verified=1\n";
	exit( 0 );
}

$scenario = (string) $argv[1];

// Setup WordPress environment mocks.
define( 'WP_CLI', true );
define( 'ABSPATH', $root . '/' );
define( 'DB_NAME', 'dbshcocboodiwr' );
define( 'NVX_ENV', 'staging' );
define( 'OBJECT', 'OBJECT' );

putenv( 'MIGRATION_DRY_RUN=0' );

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public int $ID            = 0;
		public string $post_title   = '';
		public string $post_name    = '';
		public string $post_content = '';
		public string $post_excerpt = '';
		public string $post_status  = 'publish';
		public string $post_type    = 'page';

		public function __construct( array $props ) {
			foreach ( $props as $k => $v ) {
				$this->{$k} = $v;
			}
		}
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public string $message;
		public function __construct( string $message = '' ) {
			$this->message = $message;
		}
	}
}

function is_wp_error( $thing ): bool {
	return $thing instanceof WP_Error;
}

$GLOBALS['mock_options'] = array(
	'home'    => 'https://staging2.nuvanx.com',
	'siteurl' => 'https://staging2.nuvanx.com',
);

function get_option( string $key, $default = false ) {
	return $GLOBALS['mock_options'][ $key ] ?? $default;
}

function wp_get_environment_type(): string {
	return 'staging';
}

function sanitize_key( $key ): string {
	return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $key ) );
}

function sanitize_title( $title ): string {
	return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $title ) );
}

function wp_strip_all_tags( $s ): string {
	return strip_tags( (string) $s );
}

function sanitize_textarea_field( $s ): string {
	return trim( strip_tags( (string) $s ) );
}

function esc_attr( $s ): string {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}

function nvx_aesthetic_treatment_catalog(): array {
	return array(
		'neuromoduladores_avanzados' => array(
			'slug'        => 'neuromoduladores-avanzados',
			'h1'          => 'Neuromoduladores avanzados',
			'description' => 'Tratamiento facial.',
		),
	);
}

function nvx_strategy_page_catalog(): array {
	return array();
}

function nvx_journal_tech_article_map(): array {
	return array();
}

function nvx_journal_tech_article_catalog( string $slug ): array {
	unset( $slug );
	return array();
}

function nvx_medical_reviewers(): array {
	return array(
		'rivera' => array(
			'name'    => 'Dr. Javier Rivera Tejeda',
			'license' => '282869502',
			'url'     => 'https://staging2.nuvanx.com/equipo-medico/#physician-rivera-tejeda',
			'id'      => 'https://staging2.nuvanx.com/equipo-medico/#physician-rivera-tejeda',
			'title'   => 'Director médico NUVANX',
		),
	);
}

function nvx_medical_review_valid_date( string $date ): bool {
	if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $match ) ) {
		return false;
	}
	return checkdate( (int) $match[2], (int) $match[3], (int) $match[1] );
}

$GLOBALS['mock_posts']       = array();
$GLOBALS['mock_meta']        = array();
$GLOBALS['deleted_post_ids'] = array();
$GLOBALS['fail_meta_keys']   = array();

function get_page_by_path( string $path, $output = OBJECT, $post_type = 'page' ): ?WP_Post {
	unset( $output, $post_type );
	foreach ( $GLOBALS['mock_posts'] as $post ) {
		if ( $post->post_name === $path ) {
			return $post;
		}
	}
	return null;
}

function get_post( int $id ): ?WP_Post {
	return $GLOBALS['mock_posts'][ $id ] ?? null;
}

function get_post_status( int $id ): string {
	return isset( $GLOBALS['mock_posts'][ $id ] ) ? $GLOBALS['mock_posts'][ $id ]->post_status : '';
}

function get_post_meta( int $post_id, string $key = '', bool $single = false ) {
	unset( $single );
	return $GLOBALS['mock_meta'][ $post_id ][ $key ] ?? '';
}

function update_post_meta( int $post_id, string $key, $value ): bool {
	if ( in_array( $key, $GLOBALS['fail_meta_keys'], true ) ) {
		return false;
	}
	$existing = $GLOBALS['mock_meta'][ $post_id ][ $key ] ?? null;
	if ( (string) $existing === (string) $value ) {
		return false;
	}
	$GLOBALS['mock_meta'][ $post_id ][ $key ] = (string) $value;
	return true;
}

function wp_insert_post( array $data, bool $wp_error = false ) {
	unset( $wp_error );
	$id         = count( $GLOBALS['mock_posts'] ) + 100;
	$data['ID'] = $id;
	$post       = new WP_Post( $data );
	$GLOBALS['mock_posts'][ $id ] = $post;
	return $id;
}

function wp_delete_post( int $id, bool $force = false ): bool {
	unset( $force );
	$GLOBALS['deleted_post_ids'][] = $id;
	unset( $GLOBALS['mock_posts'][ $id ] );
	unset( $GLOBALS['mock_meta'][ $id ] );
	return true;
}

function wp_update_post( array $data, bool $wp_error = false ) {
	unset( $wp_error );
	$id = (int) ( $data['ID'] ?? 0 );
	if ( ! isset( $GLOBALS['mock_posts'][ $id ] ) ) {
		return 0;
	}
	foreach ( $data as $k => $v ) {
		$GLOBALS['mock_posts'][ $id ]->{$k} = $v;
	}
	return $id;
}

// Setup scenario state.
switch ( $scenario ) {
	case 'approved_seed_preserved':
		$id = 101;
		$GLOBALS['mock_posts'][ $id ] = new WP_Post(
			array(
				'ID'           => $id,
				'post_name'    => 'neuromoduladores-avanzados',
				'post_title'   => 'Neuromoduladores avanzados',
				'post_content' => '<div class="nvx-aesthetic-treatment-source" data-nvx-treatment="neuromoduladores_avanzados"></div>',
				'post_status'  => 'publish',
				'post_type'    => 'page',
			)
		);
		$GLOBALS['mock_meta'][ $id ]  = array(
			'_nvx_aesthetic_treatment_key' => 'neuromoduladores_avanzados',
			'_nvx_medical_review_status'   => 'approved',
			'_nvx_medical_reviewer'        => 'rivera',
			'_nvx_medical_review_date'     => '2026-03-15',
		);

		register_shutdown_function(
			static function () use ( $id ) {
				$status   = get_post_meta( $id, '_nvx_medical_review_status', true );
				$reviewer = get_post_meta( $id, '_nvx_medical_reviewer', true );
				$date     = get_post_meta( $id, '_nvx_medical_review_date', true );

				if ( 'approved' !== $status ) {
					fwrite( STDERR, "Assertion failed: review status was overwritten to '{$status}'\n" );
					exit( 1 );
				}
				if ( 'rivera' !== $reviewer || '2026-03-15' !== $date ) {
					fwrite( STDERR, "Assertion failed: reviewer/date was altered\n" );
					exit( 1 );
				}
			}
		);
		break;

	case 'approved_seed_repair_key':
		$id = 102;
		$GLOBALS['mock_posts'][ $id ] = new WP_Post(
			array(
				'ID'           => $id,
				'post_name'    => 'neuromoduladores-avanzados',
				'post_title'   => 'Neuromoduladores avanzados',
				'post_content' => '<div class="nvx-aesthetic-treatment-source" data-nvx-treatment="neuromoduladores_avanzados"></div>',
				'post_status'  => 'publish',
				'post_type'    => 'page',
			)
		);
		// Missing key, but complete approval metadata.
		$GLOBALS['mock_meta'][ $id ] = array(
			'_nvx_medical_review_status' => 'approved',
			'_nvx_medical_reviewer'      => 'rivera',
			'_nvx_medical_review_date'   => '2026-03-15',
		);

		register_shutdown_function(
			static function () use ( $id ) {
				$key    = get_post_meta( $id, '_nvx_aesthetic_treatment_key', true );
				$status = get_post_meta( $id, '_nvx_medical_review_status', true );

				if ( 'neuromoduladores_avanzados' !== $key ) {
					fwrite( STDERR, "Assertion failed: treatment key was not repaired; got '{$key}'\n" );
					exit( 1 );
				}
				if ( 'approved' !== $status ) {
					fwrite( STDERR, "Assertion failed: approved status was overwritten to '{$status}'\n" );
					exit( 1 );
				}
			}
		);
		break;

	case 'invalid_approval_reset':
		$id = 103;
		$GLOBALS['mock_posts'][ $id ] = new WP_Post(
			array(
				'ID'           => $id,
				'post_name'    => 'neuromoduladores-avanzados',
				'post_title'   => 'Neuromoduladores avanzados',
				'post_content' => '<div class="nvx-aesthetic-treatment-source" data-nvx-treatment="neuromoduladores_avanzados"></div>',
				'post_status'  => 'publish',
				'post_type'    => 'page',
			)
		);
		// Status is approved but reviewer and date are missing (invalid).
		$GLOBALS['mock_meta'][ $id ] = array(
			'_nvx_aesthetic_treatment_key' => 'neuromoduladores_avanzados',
			'_nvx_medical_review_status'   => 'approved',
		);

		register_shutdown_function(
			static function () use ( $id ) {
				$status = get_post_meta( $id, '_nvx_medical_review_status', true );
				if ( 'pending' !== $status ) {
					fwrite( STDERR, "Assertion failed: invalid approval was not reset to pending; got '{$status}'\n" );
					exit( 1 );
				}
			}
		);
		break;

	case 'meta_failure_rollback':
		// No existing post -> will attempt create_seed, but fail update_post_meta.
		$GLOBALS['fail_meta_keys'] = array( '_nvx_aesthetic_treatment_key' );

		register_shutdown_function(
			static function () {
				if ( empty( $GLOBALS['deleted_post_ids'] ) ) {
					fwrite( STDERR, "Assertion failed: wp_delete_post was not called on meta write failure\n" );
					exit( 1 );
				}
				if ( ! empty( $GLOBALS['mock_posts'] ) ) {
					fwrite( STDERR, "Assertion failed: orphan post remained in mock_posts after failure\n" );
					exit( 1 );
				}
				// The migration should fail with exit code 1.
				exit( 0 ); // Convert expected migration failure to test success.
			}
		);
		break;

	case 'editorial_preserved':
		$id = 105;
		$GLOBALS['mock_posts'][ $id ] = new WP_Post(
			array(
				'ID'           => $id,
				'post_name'    => 'neuromoduladores-avanzados',
				'post_title'   => 'Página Editorial Humana',
				'post_content' => '<p>Contenido editorial manual sin marcador de seeder.</p>',
				'post_status'  => 'publish',
				'post_type'    => 'page',
			)
		);

		register_shutdown_function(
			static function () use ( $id ) {
				$post = get_post( $id );
				if ( null === $post || str_contains( $post->post_content, 'nvx-aesthetic-treatment-source' ) ) {
					fwrite( STDERR, "Assertion failed: editorial post was altered\n" );
					exit( 1 );
				}
			}
		);
		break;

	default:
		fwrite( STDERR, "Unknown scenario: {$scenario}\n" );
		exit( 1 );
}

require $migration_file;

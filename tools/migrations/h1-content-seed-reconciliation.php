<?php
/**
 * H1 Staging2 content-seed reconciliation primitives.
 *
 * This file only declares planning/apply functions. The canonical executable
 * owner is content-hygiene-staging-only.php, which runs inside the rollback-
 * protected Staging workflow.
 *
 * @package nuvanx-medical
 */

/** Return the review status that must survive reconciliation. */
function nvx_h1_target_review_status( bool $approved_before ): string {
	return $approved_before ? 'approved' : 'pending';
}

/** Add one bounded plan event. */
function nvx_h1_plan_add( array &$plan, string $bucket, string $scope, string $action, string $slug, array $payload = array() ): void {
	$plan[ $bucket ][] = array(
		'scope'   => sanitize_key( $scope ),
		'action'  => sanitize_key( $action ),
		'slug'    => sanitize_title( $slug ),
		'payload' => $payload,
	);
}

/** Exact marker used only by legacy strategy seeds. */
function nvx_h1_strategy_marker( string $key ): string {
	return '<!-- NUVANX_STRATEGY_PAGE:' . sanitize_key( $key ) . ' -->';
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

/**
 * Read one postmeta value directly from durable storage.
 *
 * Planning cannot authorize a NOOP from WordPress object cache because a stale
 * cache snapshot can outlive an exact-key invalidation on persistent backends.
 * Identical duplicate rows are tolerated; conflicting durable rows are not a
 * coherent source of truth and therefore fail closed before mutation.
 */
function nvx_h1_durable_meta_value( int $post_id, string $meta_key ): string {
	global $wpdb;

	if (
		$post_id <= 0
		|| '' === $meta_key
		|| ! isset( $wpdb )
		|| ! isset( $wpdb->postmeta )
		|| ! method_exists( $wpdb, 'prepare' )
		|| ! method_exists( $wpdb, 'get_col' )
	) {
		throw new RuntimeException( 'post_meta_durable_reader_unavailable' );
	}

	$stored_values = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC",
			$post_id,
			$meta_key
		)
	);
	if ( ! is_array( $stored_values ) ) {
		throw new RuntimeException( 'post_meta_durable_read_failed_' . ltrim( sanitize_key( $meta_key ), '_' ) );
	}
	if ( array() === $stored_values ) {
		return '';
	}

	$expected = (string) maybe_unserialize( $stored_values[0] );
	foreach ( $stored_values as $stored_value ) {
		if ( $expected !== (string) maybe_unserialize( $stored_value ) ) {
			throw new RuntimeException( 'post_meta_durable_conflict_' . ltrim( sanitize_key( $meta_key ), '_' ) );
		}
	}

	return $expected;
}

/**
 * Resolve aesthetic medical-review state exclusively from durable postmeta.
 *
 * @return array{approved:bool,status:string,reviewer:string,date:string}
 */
function nvx_h1_durable_aesthetic_review_state( int $post_id ): array {
	$status    = strtolower( trim( nvx_h1_durable_meta_value( $post_id, '_nvx_medical_review_status' ) ) );
	$reviewer  = strtolower( trim( nvx_h1_durable_meta_value( $post_id, '_nvx_medical_reviewer' ) ) );
	$date      = trim( nvx_h1_durable_meta_value( $post_id, '_nvx_medical_review_date' ) );
	$reviewers = nvx_medical_reviewers();
	$approved  = 'approved' === $status
		&& isset( $reviewers[ $reviewer ] )
		&& nvx_medical_review_valid_date( $date )
		&& nvx_medical_review_reviewer_complete( $reviewers[ $reviewer ] );

	return array(
		'approved' => $approved,
		'status'   => $status,
		'reviewer' => $reviewer,
		'date'     => $date,
	);
}

/**
 * Build and validate the entire H1 plan before the canonical migration writes.
 *
 * @return array{ops:array<int,array<string,mixed>>,noops:array<int,array<string,mixed>>,errors:array<int,array<string,mixed>>}
 */
function nvx_h1_build_plan(): array {
	$plan = array(
		'ops'    => array(),
		'noops'  => array(),
		'errors' => array(),
	);

	$required = array(
		'nvx_aesthetic_treatment_catalog',
		'nvx_strategy_page_catalog',
		'nvx_journal_tech_article_map',
		'nvx_journal_tech_article_catalog',
		'nvx_medical_review_record',
		'nvx_medical_reviewers',
		'nvx_medical_review_valid_date',
		'nvx_medical_review_reviewer_complete',
	);
	foreach ( $required as $function_name ) {
		if ( ! function_exists( $function_name ) ) {
			nvx_h1_plan_add( $plan, 'errors', 'bootstrap', 'dependency_missing', $function_name );
		}
	}
	if ( ! empty( $plan['errors'] ) ) {
		return $plan;
	}

	foreach ( nvx_strategy_page_catalog() as $raw_key => $page ) {
		if ( ! is_array( $page ) ) {
			nvx_h1_plan_add( $plan, 'errors', 'strategy', 'invalid_catalog_record', (string) $raw_key );
			continue;
		}
		$key           = sanitize_key( (string) $raw_key );
		$slug          = sanitize_title( (string) ( $page['slug'] ?? '' ) );
		$title         = wp_strip_all_tags( (string) ( $page['title'] ?? '' ) );
		$review_status = sanitize_key( (string) ( $page['review_status'] ?? '' ) );
		$marker        = nvx_h1_strategy_marker( $key );
		if ( '' === $key || '' === $slug || '' === $title || '' === $review_status ) {
			nvx_h1_plan_add( $plan, 'errors', 'strategy', 'invalid_catalog_record', $slug ?: $key );
			continue;
		}

		$existing = get_page_by_path( $slug, OBJECT, 'page' );
		if ( $existing instanceof WP_Post ) {
			if ( ! str_contains( (string) $existing->post_content, $marker ) ) {
				nvx_h1_plan_add( $plan, 'noops', 'strategy', 'existing_editorial', $slug );
				continue;
			}
			$current = nvx_h1_durable_meta_value( (int) $existing->ID, '_nvx_strategy_review_status' );
			if ( $review_status === $current ) {
				nvx_h1_plan_add( $plan, 'noops', 'strategy', 'seed_current', $slug );
				continue;
			}
			nvx_h1_plan_add(
				$plan,
				'ops',
				'strategy',
				'update_seed_review_meta',
				$slug,
				array( 'id' => (int) $existing->ID, 'marker' => $marker, 'review_status' => $review_status )
			);
			continue;
		}

		nvx_h1_plan_add(
			$plan,
			'ops',
			'strategy',
			'create_seed',
			$slug,
			array( 'title' => $title, 'marker' => $marker, 'review_status' => $review_status )
		);
	}

	foreach ( nvx_aesthetic_treatment_catalog() as $raw_key => $page ) {
		if ( ! is_array( $page ) ) {
			nvx_h1_plan_add( $plan, 'errors', 'aesthetic', 'invalid_catalog_record', (string) $raw_key );
			continue;
		}
		$key     = sanitize_key( (string) $raw_key );
		$slug    = sanitize_title( (string) ( $page['slug'] ?? '' ) );
		$title   = wp_strip_all_tags( (string) ( $page['h1'] ?? '' ) );
		$excerpt = sanitize_textarea_field( (string) ( $page['description'] ?? '' ) );
		if ( '' === $key || '' === $slug || '' === $title ) {
			nvx_h1_plan_add( $plan, 'errors', 'aesthetic', 'invalid_catalog_record', $slug ?: $key );
			continue;
		}

		$existing = get_page_by_path( $slug, OBJECT, 'page' );
		if ( $existing instanceof WP_Post ) {
			if ( ! nvx_h1_is_aesthetic_seed( $existing, $key ) ) {
				nvx_h1_plan_add( $plan, 'noops', 'aesthetic', 'existing_editorial', $slug );
				continue;
			}

			$review_state   = nvx_h1_durable_aesthetic_review_state( (int) $existing->ID );
			$approved       = (bool) $review_state['approved'];
			$target_review  = nvx_h1_target_review_status( $approved );
			$current_key    = nvx_h1_durable_meta_value( (int) $existing->ID, '_nvx_aesthetic_treatment_key' );
			$current_review = (string) $review_state['status'];

			if ( $key !== $current_key || $target_review !== $current_review ) {
				nvx_h1_plan_add(
					$plan,
					'ops',
					'aesthetic',
					'repair_seed_meta',
					$slug,
					array(
						'id'            => (int) $existing->ID,
						'key'           => $key,
						'target_review' => $target_review,
						'reviewer'      => $approved ? (string) $review_state['reviewer'] : '',
						'review_date'   => $approved ? (string) $review_state['date'] : '',
					)
				);
			} else {
				nvx_h1_plan_add( $plan, 'noops', 'aesthetic', 'seed_current', $slug );
			}
			continue;
		}

		nvx_h1_plan_add(
			$plan,
			'ops',
			'aesthetic',
			'create_seed',
			$slug,
			array( 'key' => $key, 'title' => $title, 'excerpt' => $excerpt )
		);
	}

	foreach ( nvx_journal_tech_article_map() as $raw_slug => $meta ) {
		if ( ! is_array( $meta ) ) {
			nvx_h1_plan_add( $plan, 'errors', 'journal', 'invalid_catalog_record', (string) $raw_slug );
			continue;
		}
		$slug   = sanitize_title( (string) $raw_slug );
		$marker = (string) ( $meta['marker'] ?? '' );
		if ( '' === $slug || '' === $marker ) {
			nvx_h1_plan_add( $plan, 'errors', 'journal', 'invalid_catalog_record', $slug );
			continue;
		}
		$existing = get_page_by_path( $slug, OBJECT, 'post' );
		if ( $existing instanceof WP_Post ) {
			nvx_h1_plan_add( $plan, 'noops', 'journal', 'existing_post', $slug );
			continue;
		}
		$data = nvx_journal_tech_article_catalog( $slug );
		if ( ! is_array( $data ) || array() === $data ) {
			nvx_h1_plan_add( $plan, 'errors', 'journal', 'catalog_unavailable', $slug );
			continue;
		}
		nvx_h1_plan_add(
			$plan,
			'ops',
			'journal',
			'create_seed',
			$slug,
			array(
				'marker'  => $marker,
				'title'   => wp_strip_all_tags( (string) ( $data['title'] ?? $slug ) ),
				'excerpt' => sanitize_textarea_field( (string) ( $data['excerpt'] ?? '' ) ),
			)
		);
	}

	$bridal_slug = 'protocolo-novias-madrid';
	$bridal      = get_page_by_path( $bridal_slug, OBJECT, 'page' );
	if ( ! ( $bridal instanceof WP_Post ) ) {
		nvx_h1_plan_add( $plan, 'noops', 'bridal', 'page_absent', $bridal_slug );
	} else {
		$seed_key   = nvx_h1_durable_meta_value( (int) $bridal->ID, '_nvx_aesthetic_treatment_key' );
		$content    = (string) $bridal->post_content;
		$has_meta   = 'bridal_protocol' === $seed_key;
		$has_marker = str_contains( $content, 'data-nvx-treatment="bridal_protocol"' )
			|| str_contains( $content, "data-nvx-treatment='bridal_protocol'" );

		if ( $has_meta !== $has_marker ) {
			nvx_h1_plan_add( $plan, 'errors', 'bridal', 'provenance_mismatch', $bridal_slug );
		} elseif ( ! $has_meta ) {
			nvx_h1_plan_add( $plan, 'noops', 'bridal', 'existing_editorial', $bridal_slug );
		} elseif ( in_array( $bridal->post_status, array( 'draft', 'trash' ), true ) ) {
			nvx_h1_plan_add( $plan, 'noops', 'bridal', 'already_retired', $bridal_slug );
		} else {
			nvx_h1_plan_add( $plan, 'ops', 'bridal', 'retire_exact_seed', $bridal_slug, array( 'id' => (int) $bridal->ID ) );
		}
	}

	return $plan;
}

/**
 * Persist one postmeta value inside the SQL transaction without invoking the
 * WordPress metadata API. `update_post_meta()` invalidates persistent object
 * cache immediately, before COMMIT, so it cannot be the write owner inside a
 * transaction whose visibility boundary is the database commit.
 */
function nvx_h1_set_meta_verified( int $post_id, string $meta_key, string $value ): void {
	global $wpdb;

	$meta_key = (string) $meta_key;
	if ( $post_id <= 0 || '' === $meta_key ) {
		throw new RuntimeException( 'post_meta_invalid_write_target' );
	}

	$serialized    = maybe_serialize( $value );
	$stored_values = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC",
			$post_id,
			$meta_key
		)
	);
	if ( ! is_array( $stored_values ) ) {
		throw new RuntimeException( 'post_meta_prewrite_read_failed:' . sanitize_key( $meta_key ) );
	}

	if ( array() === $stored_values ) {
		$inserted = $wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => $post_id,
				'meta_key'   => $meta_key,
				'meta_value' => $serialized,
			),
			array( '%d', '%s', '%s' )
		);
		if ( false === $inserted || $inserted < 1 ) {
			throw new RuntimeException( 'post_meta_transactional_insert_failed:' . sanitize_key( $meta_key ) );
		}
	} else {
		$needs_update = false;
		foreach ( $stored_values as $stored_value ) {
			if ( $value !== (string) maybe_unserialize( $stored_value ) ) {
				$needs_update = true;
				break;
			}
		}
		if ( $needs_update ) {
			$updated = $wpdb->update(
				$wpdb->postmeta,
				array( 'meta_value' => $serialized ),
				array( 'post_id' => $post_id, 'meta_key' => $meta_key ),
				array( '%s' ),
				array( '%d', '%s' )
			);
			if ( false === $updated ) {
				throw new RuntimeException( 'post_meta_transactional_update_failed:' . sanitize_key( $meta_key ) );
			}
		}
	}

	$stored_values = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC",
			$post_id,
			$meta_key
		)
	);
	if ( ! is_array( $stored_values ) || array() === $stored_values ) {
		throw new RuntimeException( 'post_meta_durable_value_missing:' . sanitize_key( $meta_key ) );
	}
	foreach ( $stored_values as $stored_value ) {
		if ( $value !== (string) maybe_unserialize( $stored_value ) ) {
			throw new RuntimeException( 'post_meta_durable_verification_failed:' . sanitize_key( $meta_key ) );
		}
	}
}

/** Verify one metadata value after COMMIT against durable storage and runtime. */
function nvx_h1_verify_meta_after_commit( int $post_id, string $meta_key, string $expected ): void {
	global $wpdb;

	if ( $post_id <= 0 ) {
		throw new RuntimeException( 'post_meta_postcommit_invalid_post_id' );
	}

	$failure_key   = ltrim( sanitize_key( $meta_key ), '_' );
	$stored_values = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC",
			$post_id,
			$meta_key
		)
	);
	if ( ! is_array( $stored_values ) || array() === $stored_values ) {
		throw new RuntimeException( 'post_meta_postcommit_durable_value_missing_' . $failure_key );
	}
	foreach ( $stored_values as $stored_value ) {
		if ( $expected !== (string) maybe_unserialize( $stored_value ) ) {
			throw new RuntimeException( 'post_meta_postcommit_durable_verification_failed_' . $failure_key );
		}
	}

	// The verifier must never become a cache writer. Invalidate the exact keys,
	// let WordPress perform its canonical runtime read, then invalidate again in
	// a finally block so a lazy cache population cannot survive this verifier.
	nvx_h1_invalidate_post_cache( $post_id );
	$runtime_value = '';
	try {
		$runtime_value = (string) get_post_meta( $post_id, $meta_key, true );
	} finally {
		nvx_h1_invalidate_post_cache( $post_id );
	}

	// Re-read durable state after the runtime read. If another request changed
	// the value during verification, fail closed instead of accepting a mixed
	// snapshot or reinstalling stale metadata into persistent object cache.
	$post_runtime_values = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC",
			$post_id,
			$meta_key
		)
	);
	if ( ! is_array( $post_runtime_values ) || array() === $post_runtime_values ) {
		throw new RuntimeException( 'post_meta_postcommit_concurrent_change_' . $failure_key );
	}
	foreach ( $post_runtime_values as $stored_value ) {
		if ( $expected !== (string) maybe_unserialize( $stored_value ) ) {
			throw new RuntimeException( 'post_meta_postcommit_concurrent_change_' . $failure_key );
		}
	}

	if ( $expected !== $runtime_value ) {
		throw new RuntimeException( 'post_meta_postcommit_runtime_verification_failed_' . $failure_key );
	}
}

/** Invalidate post-level and query caches for one affected post after COMMIT/ROLLBACK. */
function nvx_h1_invalidate_post_cache( int $post_id ): void {
	if ( $post_id <= 0 ) {
		return;
	}
	wp_cache_delete( $post_id, 'post_meta' );
	wp_cache_delete( $post_id, 'posts' );
	clean_post_cache( $post_id );
	wp_cache_delete( $post_id, 'post_meta' );
	wp_cache_delete( $post_id, 'posts' );
	wp_cache_delete( 'last_changed', 'posts' );

	// Some persistent-cache backends can report an exact-key delete miss while
	// this PHP process still carries a stale runtime snapshot. Clear only the
	// request-local cache when the active backend explicitly supports it; never
	// use a global persistent-cache flush for H1 correctness.
	if (
		function_exists( 'wp_cache_supports' )
		&& function_exists( 'wp_cache_flush_runtime' )
		&& wp_cache_supports( 'flush_runtime' )
	) {
		wp_cache_flush_runtime();
	}
}

/** Invalidate every exact post touched by a failed plan without a global flush. */
function nvx_h1_invalidate_plan_caches( array $plan, array $created_ids ): void {
	$ops = isset( $plan['ops'] ) && is_array( $plan['ops'] ) ? $plan['ops'] : array();
	foreach ( $ops as $op ) {
		$scope   = (string) ( $op['scope'] ?? '' );
		$action  = (string) ( $op['action'] ?? '' );
		$slug    = (string) ( $op['slug'] ?? '' );
		$payload = isset( $op['payload'] ) && is_array( $op['payload'] ) ? $op['payload'] : array();
		$post_id = (int) ( $payload['id'] ?? 0 );
		if ( 'create_seed' === $action && in_array( $scope, array( 'strategy', 'aesthetic', 'journal' ), true ) ) {
			$post_id = (int) ( $created_ids[ $scope . '|' . $slug ] ?? 0 );
		}
		nvx_h1_invalidate_post_cache( $post_id );
	}
}

/** Resolve and verify the WordPress runtime view after COMMIT. */
function nvx_h1_verify_runtime_plan( array $plan, array $created_ids ): void {
	$ops = isset( $plan['ops'] ) && is_array( $plan['ops'] ) ? $plan['ops'] : array();
	foreach ( $ops as $op ) {
		$scope   = (string) ( $op['scope'] ?? '' );
		$action  = (string) ( $op['action'] ?? '' );
		$slug    = (string) ( $op['slug'] ?? '' );
		$payload = isset( $op['payload'] ) && is_array( $op['payload'] ) ? $op['payload'] : array();
		$post_id = (int) ( $payload['id'] ?? 0 );

		if ( 'create_seed' === $action && in_array( $scope, array( 'strategy', 'aesthetic', 'journal' ), true ) ) {
			$post_id = (int) ( $created_ids[ $scope . '|' . $slug ] ?? 0 );
		}

		if ( $post_id <= 0 ) {
			throw new RuntimeException( 'postcommit_runtime_invalid_post_id_' . sanitize_key( $scope ) );
		}

		nvx_h1_invalidate_post_cache( $post_id );
		try {
			if ( 'strategy' === $scope && in_array( $action, array( 'update_seed_review_meta', 'create_seed' ), true ) ) {
				$expected = (string) ( $payload['review_status'] ?? '' );
				nvx_h1_verify_meta_after_commit( $post_id, '_nvx_strategy_review_status', $expected );
				continue;
			}

			if ( 'aesthetic' === $scope && in_array( $action, array( 'repair_seed_meta', 'create_seed' ), true ) ) {
				$expected_key    = sanitize_key( (string) ( $payload['key'] ?? '' ) );
				$expected_review = 'create_seed' === $action ? 'pending' : (string) ( $payload['target_review'] ?? 'pending' );
				nvx_h1_verify_meta_after_commit( $post_id, '_nvx_aesthetic_treatment_key', $expected_key );
				nvx_h1_verify_meta_after_commit( $post_id, '_nvx_medical_review_status', $expected_review );
				if ( 'approved' === $expected_review && null === nvx_medical_review_record( $post_id ) ) {
					throw new RuntimeException( 'approved_review_postcommit_verification_failed' );
				}
				continue;
			}

			if ( 'journal' === $scope && 'create_seed' === $action ) {
				$post = get_post( $post_id );
				if ( ! ( $post instanceof WP_Post ) || 'post' !== $post->post_type || 'publish' !== $post->post_status || $slug !== (string) $post->post_name ) {
					throw new RuntimeException( 'journal_postcommit_runtime_verification_failed' );
				}
				continue;
			}

			if ( 'bridal' === $scope && 'retire_exact_seed' === $action ) {
				if ( 'draft' !== get_post_status( $post_id ) ) {
					throw new RuntimeException( 'bridal_postcommit_runtime_verification_failed' );
				}
				continue;
			}
		} finally {
			// Never leave cache state populated by verification reads behind.
			nvx_h1_invalidate_post_cache( $post_id );
		}
	}
}

/** Assert that one prevalidated post still exists before applying its operation. */
function nvx_h1_require_post( int $post_id ): WP_Post {
	$post = get_post( $post_id );
	if ( ! ( $post instanceof WP_Post ) ) {
		throw new RuntimeException( 'planned_post_missing' );
	}
	return $post;
}

/** Apply a fully prevalidated H1 plan atomically at the database layer. */
function nvx_h1_apply_plan( array $plan ): array {
	global $wpdb;

	if ( ! empty( $plan['errors'] ) ) {
		throw new RuntimeException( 'plan_contains_validation_errors' );
	}
	$ops = isset( $plan['ops'] ) && is_array( $plan['ops'] ) ? $plan['ops'] : array();
	if ( array() === $ops ) {
		return array( 'planned' => 0, 'applied' => 0 );
	}

	if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
		throw new RuntimeException( 'transaction_start_failed' );
	}

	$applied     = 0;
	$committed   = false;
	$created_ids = array();
	try {
		foreach ( $ops as $op ) {
			$scope   = (string) ( $op['scope'] ?? '' );
			$action  = (string) ( $op['action'] ?? '' );
			$slug    = (string) ( $op['slug'] ?? '' );
			$payload = isset( $op['payload'] ) && is_array( $op['payload'] ) ? $op['payload'] : array();

			if ( 'strategy' === $scope && 'update_seed_review_meta' === $action ) {
				$post = nvx_h1_require_post( (int) ( $payload['id'] ?? 0 ) );
				if ( ! str_contains( (string) $post->post_content, (string) ( $payload['marker'] ?? '' ) ) ) {
					throw new RuntimeException( 'strategy_provenance_changed' );
				}
				nvx_h1_set_meta_verified( $post->ID, '_nvx_strategy_review_status', (string) ( $payload['review_status'] ?? '' ) );
			} elseif ( 'strategy' === $scope && 'create_seed' === $action ) {
				if ( get_page_by_path( $slug, OBJECT, 'page' ) instanceof WP_Post ) {
					throw new RuntimeException( 'strategy_create_precondition_changed' );
				}
				$result = wp_insert_post(
					array(
						'post_type'    => 'page',
						'post_status'  => 'publish',
						'post_title'   => (string) ( $payload['title'] ?? '' ),
						'post_name'    => $slug,
						'post_content' => (string) ( $payload['marker'] ?? '' ),
					),
					true
				);
				if ( is_wp_error( $result ) || (int) $result <= 0 ) {
					throw new RuntimeException( 'strategy_insert_failed' );
				}
				$created_ids[ $scope . '|' . $slug ] = (int) $result;
				nvx_h1_set_meta_verified( (int) $result, '_nvx_strategy_review_status', (string) ( $payload['review_status'] ?? '' ) );
			} elseif ( 'aesthetic' === $scope && 'repair_seed_meta' === $action ) {
				$post = nvx_h1_require_post( (int) ( $payload['id'] ?? 0 ) );
				$key  = sanitize_key( (string) ( $payload['key'] ?? '' ) );
				if ( ! nvx_h1_is_aesthetic_seed( $post, $key ) ) {
					throw new RuntimeException( 'aesthetic_provenance_changed' );
				}
				$target_review = (string) ( $payload['target_review'] ?? 'pending' );
				if ( 'approved' === $target_review ) {
					if (
						nvx_h1_durable_meta_value( $post->ID, '_nvx_medical_reviewer' ) !== (string) ( $payload['reviewer'] ?? '' )
						|| nvx_h1_durable_meta_value( $post->ID, '_nvx_medical_review_date' ) !== (string) ( $payload['review_date'] ?? '' )
					) {
						throw new RuntimeException( 'approved_review_provenance_changed' );
					}
				}
				nvx_h1_set_meta_verified( $post->ID, '_nvx_aesthetic_treatment_key', $key );
				nvx_h1_set_meta_verified( $post->ID, '_nvx_medical_review_status', $target_review );
			} elseif ( 'aesthetic' === $scope && 'create_seed' === $action ) {
				if ( get_page_by_path( $slug, OBJECT, 'page' ) instanceof WP_Post ) {
					throw new RuntimeException( 'aesthetic_create_precondition_changed' );
				}
				$key    = sanitize_key( (string) ( $payload['key'] ?? '' ) );
				$marker = sprintf( '<div class="nvx-aesthetic-treatment-source" data-nvx-treatment="%s"></div>', esc_attr( $key ) );
				$result = wp_insert_post(
					array(
						'post_type'    => 'page',
						'post_status'  => 'publish',
						'post_title'   => (string) ( $payload['title'] ?? '' ),
						'post_name'    => $slug,
						'post_excerpt' => (string) ( $payload['excerpt'] ?? '' ),
						'post_content' => $marker,
					),
					true
				);
				if ( is_wp_error( $result ) || (int) $result <= 0 ) {
					throw new RuntimeException( 'aesthetic_insert_failed' );
				}
				$created_ids[ $scope . '|' . $slug ] = (int) $result;
				nvx_h1_set_meta_verified( (int) $result, '_nvx_aesthetic_treatment_key', $key );
				nvx_h1_set_meta_verified( (int) $result, '_nvx_medical_review_status', 'pending' );
			} elseif ( 'journal' === $scope && 'create_seed' === $action ) {
				if ( get_page_by_path( $slug, OBJECT, 'post' ) instanceof WP_Post ) {
					throw new RuntimeException( 'journal_create_precondition_changed' );
				}
				$result = wp_insert_post(
					array(
						'post_type'    => 'post',
						'post_status'  => 'publish',
						'post_title'   => (string) ( $payload['title'] ?? '' ),
						'post_excerpt' => (string) ( $payload['excerpt'] ?? '' ),
						'post_name'    => $slug,
						'post_content' => (string) ( $payload['marker'] ?? '' ),
					),
					true
				);
				if ( is_wp_error( $result ) || (int) $result <= 0 ) {
					throw new RuntimeException( 'journal_insert_failed' );
				}
				$created_ids[ $scope . '|' . $slug ] = (int) $result;
			} elseif ( 'bridal' === $scope && 'retire_exact_seed' === $action ) {
				$post       = nvx_h1_require_post( (int) ( $payload['id'] ?? 0 ) );
				$seed_key   = nvx_h1_durable_meta_value( $post->ID, '_nvx_aesthetic_treatment_key' );
				$content    = (string) $post->post_content;
				$has_meta   = 'bridal_protocol' === $seed_key;
				$has_marker = str_contains( $content, 'data-nvx-treatment="bridal_protocol"' )
					|| str_contains( $content, "data-nvx-treatment='bridal_protocol'" );
				if ( ! $has_meta || ! $has_marker ) {
					throw new RuntimeException( 'bridal_provenance_changed' );
				}
				$result = wp_update_post( array( 'ID' => $post->ID, 'post_status' => 'draft' ), true );
				if ( is_wp_error( $result ) || 'draft' !== get_post_status( $post->ID ) ) {
					throw new RuntimeException( 'bridal_retirement_failed' );
				}
			} else {
				throw new RuntimeException( 'unknown_h1_operation' );
			}

			++$applied;
			printf( "H1_SEED_APPLIED scope=%s action=%s slug=%s\n", sanitize_key( $scope ), sanitize_key( $action ), sanitize_title( $slug ) );
		}

		if ( false === $wpdb->query( 'COMMIT' ) ) {
			throw new RuntimeException( 'transaction_commit_failed' );
		}
		$committed = true;
		nvx_h1_verify_runtime_plan( $plan, $created_ids );
	} catch ( Throwable $error ) {
		if ( ! $committed ) {
			$wpdb->query( 'ROLLBACK' );
		}
		nvx_h1_invalidate_plan_caches( $plan, $created_ids );
		throw $error;
	}

	return array( 'planned' => count( $ops ), 'applied' => $applied );
}

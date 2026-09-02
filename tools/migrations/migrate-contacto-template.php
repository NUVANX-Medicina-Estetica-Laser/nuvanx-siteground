<?php
/**
 * Migrate legacy Contacto template meta.
 *
 * Rewrites `_wp_page_template` from `templates/template-contact.php`
 * to `templates/page-contacto.php` idempotently and fail-closed.
 *
 * @package nuvanx-medical
 */

if ( 'cli' !== php_sapi_name() ) {
	echo "FAIL: Must run from CLI\n";
	exit( 1 );
}

require_once dirname( __DIR__, 2 ) . '/wp-load.php';

$query = new WP_Query(
	array(
		'post_type'      => 'page',
		'posts_per_page' => -1,
		'post_status'    => 'any',
		'meta_query'     => array(
			array(
				'key'   => '_wp_page_template',
				'value' => 'templates/template-contact.php',
			),
		),
	)
);

if ( empty( $query->posts ) ) {
	echo "NOOP: No pages found with legacy 'templates/template-contact.php' template.\n";
	exit( 0 );
}

$migrated = 0;
foreach ( $query->posts as $page ) {
	$page_id = (int) $page->ID;
	
	// Double-check the actual value before writing
	$current = get_post_meta( $page_id, '_wp_page_template', true );
	if ( 'templates/template-contact.php' !== $current ) {
		continue;
	}

	echo sprintf( "Migrating Page ID %d ('%s'): %s -> templates/page-contacto.php... ", $page_id, $page->post_title, $current );
	$updated = update_post_meta( $page_id, '_wp_page_template', 'templates/page-contacto.php' );
	
	if ( $updated ) {
		echo "PASS\n";
		$migrated++;
	} else {
		echo "FAIL\n";
	}
}

echo sprintf( "Migration complete. %d pages migrated.\n", $migrated );
exit( 0 );

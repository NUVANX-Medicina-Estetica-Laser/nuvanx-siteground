<?php
/** Behavioral contract for H1 medical-review preservation. */

require_once dirname( __DIR__, 2 ) . '/tools/migrations/h1-content-seed-reconciliation.php';

$cases = array(
	true  => 'approved',
	false => 'pending',
);

foreach ( $cases as $approved => $expected ) {
	$actual = nvx_h1_target_review_status( (bool) $approved );
	if ( $expected !== $actual ) {
		fwrite( STDERR, "H1_REVIEW_PRESERVATION=FAIL\n" );
		exit( 1 );
	}
}

echo "H1_REVIEW_PRESERVATION=PASS approved=preserved unapproved=pending\n";

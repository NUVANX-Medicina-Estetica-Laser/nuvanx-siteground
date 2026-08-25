<?php
/**
 * Blocking contract for Salamanca–Goya phone display ownership.
 *
 * Human-readable surfaces must consume the governed display value instead of
 * rebuilding the E.164 phone in ad-hoc three-digit groups. Machine-readable
 * links and Schema remain E.164.
 */

declare(strict_types=1);

$root       = dirname( __DIR__, 2 );
$theme_root = $root . '/wp-content/themes/nuvanx-medical';
$fail       = static function ( string $message ): void {
	fwrite( STDERR, 'GOYA_NAP_DISPLAY_TEST=FAIL ' . $message . PHP_EOL );
	exit( 1 );
};

$paths = array(
	'config'  => $theme_root . '/inc/nvx-business-config.php',
	'sede'    => $theme_root . '/templates/page-sede.php',
	'contact' => $theme_root . '/templates/page-contacto.php',
	'hub'     => $theme_root . '/inc/nvx-clinics-hub.php',
	'footer'  => $theme_root . '/footer.php',
	'schema'  => $theme_root . '/inc/nvx-schema-graph.php',
	'llms'    => $root . '/llms.txt',
);

$sources = array();
foreach ( $paths as $key => $path ) {
	$source = file_get_contents( $path );
	if ( false === $source ) {
		$fail( 'unreadable source=' . $key );
	}
	$sources[ $key ] = $source;
}

$display = '647 50 51 07';
$e164    = '+34647505107';
$legacy  = '647 505 107';

$goya_start = strpos( $sources['config'], "'goya'" );
if ( false === $goya_start ) {
	$fail( 'missing Goya SSOT block' );
}
$goya_block = substr( $sources['config'], $goya_start );
if ( ! str_contains( $goya_block, "'phone'         => '" . $display . "'" ) ) {
	$fail( 'Goya governed display phone drift' );
}
if ( ! str_contains( $goya_block, "'phone_href'    => '" . $e164 . "'" ) ) {
	$fail( 'Goya governed E.164 phone drift' );
}

// Theme-owned visible renderers must consume the governed display value.
if ( ! str_contains( $sources['sede'], '$clinic_config[\'phone\']' ) ) {
	$fail( 'Sede renderer does not consume clinic display phone' );
}
if ( ! str_contains( $sources['contact'], '$config[\'goya\'][\'phone\']' ) ) {
	$fail( 'Contacto renderer does not consume Goya display SSOT' );
}
if ( ! str_contains( $sources['hub'], '$config[\'goya\'][\'phone\']' ) ) {
	$fail( 'Clinics hub does not consume Goya display SSOT' );
}

// Block the exact copy/paste patterns that previously rebuilt Goya into 3-3-3.
$forbidden_patterns = array(
	'contact' => array(
		'chunk_split( (string) preg_replace( \'/^\\+34/\', \'\', $goya_phone )',
	),
	'hub' => array(
		'nvx_clinics_hub_phone_display( $goya_phone )',
	),
);
foreach ( $forbidden_patterns as $surface => $patterns ) {
	foreach ( $patterns as $pattern ) {
		if ( str_contains( $sources[ $surface ], $pattern ) ) {
			$fail( 'ad-hoc Goya phone reconstruction surface=' . $surface );
		}
	}
}

// Footer may remain static markup until its Figma refactor, but its public text
// and tel target must still be canonical and independently guarded.
if ( ! str_contains( $sources['footer'], 'tel:' . $e164 ) || ! str_contains( $sources['footer'], '>' . $display . '</a>' ) ) {
	$fail( 'footer Goya phone display/E.164 parity drift' );
}

// Schema must stay machine-readable; never replace telephone with display text.
if ( ! str_contains( $sources['schema'], "['goya']['phone_href']" ) ) {
	$fail( 'Schema no longer consumes machine-readable Goya phone_href' );
}
if ( str_contains( $sources['schema'], $display ) ) {
	$fail( 'human display phone leaked into Schema source' );
}

// Ban the known legacy public representation across governed text/code sources.
$scan_files = array_merge(
	glob( $theme_root . '/*.php' ) ?: array(),
	glob( $theme_root . '/inc/*.php' ) ?: array(),
	glob( $theme_root . '/templates/*.php' ) ?: array(),
	glob( $theme_root . '/inc/data/*.json' ) ?: array(),
	array( $paths['llms'] )
);
foreach ( $scan_files as $file ) {
	$source = file_get_contents( $file );
	if ( false !== $source && str_contains( $source, $legacy ) ) {
		$fail( 'legacy Goya phone display remains file=' . str_replace( $root . '/', '', $file ) );
	}
}

echo 'GOYA_NAP_DISPLAY_TEST=PASS display=ssot machine=e164 legacy=blocked renderers=3' . PHP_EOL;

<?php
/**
 * Verify the tracked static CSS release surface without loading WordPress.
 *
 * Used by remote deploy hosts where Node is not a release dependency.
 *
 * @package nuvanx-medical
 */

declare(strict_types=1);

$theme_root = dirname( __DIR__ );
$css_root   = $theme_root . '/assets/css';
$governance = $theme_root . '/inc/nvx-native-style-governance.php';
$dist_root  = $theme_root . '/dist';

$fail = static function ( string $reason ): never {
	fwrite( STDERR, 'CSS_RELEASE_VERIFICATION=FAIL reason=' . $reason . PHP_EOL );
	exit( 1 );
};

if ( is_dir( $dist_root ) ) {
	$fail( 'legacy_dist_present' );
}

$source = file_get_contents( $governance );
if ( false === $source ) {
	$fail( 'governance_unreadable' );
}
if ( preg_match( '/dist\/manifest\.json|nvx-critical-inline|nvx_theme_get_compiled_critical_css_bundle/', $source ) ) {
	$fail( 'runtime_generated_css_owner_present' );
}
if ( ! preg_match( '/function\s+nvx_theme_public_delivers_inline_styles\s*\(\s*\)\s*:\s*bool\s*\{\s*return\s+false\s*;\s*\}/s', $source ) ) {
	$fail( 'linked_delivery_not_canonical' );
}
if ( ! preg_match( '/function\s+nvx_theme_critical_stylesheet_files\s*\([^)]*\)\s*:\s*array\s*\{([\s\S]*?)\n\}/', $source, $inventory_match ) ) {
	$fail( 'source_inventory_missing' );
}

preg_match_all( '/[\'\"](assets\/css\/[A-Za-z0-9._-]+\.css)[\'\"]/', $inventory_match[1], $declared_match );
$declared = $declared_match[1] ?? array();
sort( $declared );
if ( count( $declared ) !== count( array_unique( $declared ) ) ) {
	$fail( 'source_inventory_duplicate' );
}

$actual = array();
foreach ( new DirectoryIterator( $css_root ) as $entry ) {
	if ( ! $entry->isFile() || 'css' !== strtolower( $entry->getExtension() ) ) {
		continue;
	}
	$actual[] = 'assets/css/' . $entry->getFilename();
}
sort( $actual );
if ( $actual !== $declared ) {
	$fail( 'source_inventory_mismatch' );
}

$hash  = hash_init( 'sha256' );
$bytes = 0;
foreach ( $actual as $relative ) {
	$file = $theme_root . '/' . $relative;
	$size = filesize( $file );
	if ( false === $size || $size <= 0 ) {
		$fail( 'source_empty:' . $relative );
	}
	$content = file_get_contents( $file );
	if ( false === $content ) {
		$fail( 'source_unreadable:' . $relative );
	}
	$bytes += $size;
	hash_update( $hash, $relative . "\0" . $content . "\0" );
}

printf(
	"CSS_RELEASE_VERIFICATION=PASS sources=%d bytes=%d fingerprint=%s delivery=linked artifact_owner=git_exact_sha legacy_dist=absent source_coverage=complete\n",
	count( $actual ),
	$bytes,
	hash_final( $hash )
);

<?php
/**
 * Test contract for nvx_robots_txt_governance.
 *
 * @package nuvanx-medical
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );

function home_url( string $path = '' ): string {
	return 'https://nuvanx.com' . $path;
}
function add_filter( ...$args ): bool {
	unset( $args );
	return true;
}

$GLOBALS['nvx_test_is_nonprod'] = false;
function nvx_seo_is_nonproduction_environment(): bool {
	return (bool) $GLOBALS['nvx_test_is_nonprod'];
}

require_once dirname( __DIR__, 2 ) . '/wp-content/themes/nuvanx-medical/inc/nvx-seo-production-readiness.php';

// Test 1: Production output contains explicit Allow for AI bots and Sitemaps
$GLOBALS['nvx_test_is_nonprod'] = false;
$prod_robots = nvx_robots_txt_governance( '', true );

$required_bots = array(
	'OAI-SearchBot',
	'ChatGPT-User',
	'GPTBot',
	'PerplexityBot',
	'ClaudeBot',
	'anthropic-ai',
	'Google-Extended',
	'GoogleOther',
);

foreach ( $required_bots as $bot ) {
	if ( ! str_contains( $prod_robots, "User-agent: {$bot}\nAllow: /" ) ) {
		fwrite( STDERR, "ROBOTS_TXT_GOVERNANCE=FAIL missing Allow for {$bot}\n" );
		exit( 1 );
	}
}

if ( ! str_contains( $prod_robots, 'Sitemap: https://nuvanx.com/sitemap.xml' ) ) {
	fwrite( STDERR, "ROBOTS_TXT_GOVERNANCE=FAIL missing sitemap.xml\n" );
	exit( 1 );
}
if ( ! str_contains( $prod_robots, 'Sitemap: https://nuvanx.com/sitemap_index.xml' ) ) {
	fwrite( STDERR, "ROBOTS_TXT_GOVERNANCE=FAIL missing sitemap_index.xml\n" );
	exit( 1 );
}

// Test 2: Non-production / Staging output is strictly Disallow: /
$GLOBALS['nvx_test_is_nonprod'] = true;
$staging_robots = nvx_robots_txt_governance( '', true );
if ( trim( $staging_robots ) !== "User-agent: *\nDisallow: /" ) {
	fwrite( STDERR, "ROBOTS_TXT_GOVERNANCE=FAIL staging must be Disallow: /\n" );
	exit( 1 );
}

echo "ROBOTS_TXT_GOVERNANCE=PASS prod_ai_bots=8 sitemaps=2 staging_fail_closed=1\n";

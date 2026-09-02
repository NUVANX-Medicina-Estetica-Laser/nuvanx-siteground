<?php
/**
 * Regression contract for governed blog routes under stale/corrupt query state.
 *
 * The governed runtime must be registered by the canonical theme manifest after
 * the blog system defines its shared query helpers. Runtime behavior must trust
 * the immutable request path + authoritative wp_posts row rather than a stale
 * neighbouring cache-aware query.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'OBJECT', 'OBJECT' );

class WP_Post {
	public int $ID;
	public string $post_status;
	public string $post_name;
	public string $post_type;
	public string $post_title;

	public function __construct( $id_or_row, ?string $slug = null, string $title = '' ) {
		if ( is_object( $id_or_row ) ) {
			$this->ID          = (int) ( $id_or_row->ID ?? 0 );
			$this->post_status = (string) ( $id_or_row->post_status ?? '' );
			$this->post_name   = (string) ( $id_or_row->post_name ?? '' );
			$this->post_type   = (string) ( $id_or_row->post_type ?? 'post' );
			$this->post_title  = (string) ( $id_or_row->post_title ?? '' );
			return;
		}

		$this->ID          = (int) $id_or_row;
		$this->post_status = 'publish';
		$this->post_name   = (string) $slug;
		$this->post_type   = 'post';
		$this->post_title  = $title;
	}
}

class WP_Query {
	public bool $is_page = false;
	public bool $is_single = false;
	public bool $is_singular = false;
	public bool $is_404 = true;
	public bool $is_archive = true;
	public bool $is_home = false;
	private array $values = array();

	public function is_main_query(): bool {
		return true;
	}

	public function set( string $key, $value ): void {
		$this->values[ $key ] = $value;
	}

	public function get( string $key ) {
		return $this->values[ $key ] ?? null;
	}
}

class wpdb {
	public string $posts = 'wp_posts';

	public function prepare( string $query, ...$args ): string {
		$GLOBALS['nvx_test_db_prepared_args'][] = $args;
		return $query;
	}

	public function get_var( string $query ) {
		unset( $query );
		return 3334;
	}

	public function get_row( string $query ) {
		unset( $query );
		$GLOBALS['nvx_test_db_row_calls']++;
		return $GLOBALS['nvx_test_db_row'] ?? null;
	}
}

function clean_post_cache( $post_id ) {
	$GLOBALS['nvx_test_clean_post_cache_calls'][] = (int) $post_id;
}

function wp_cache_set( $key, $value, $group ) {
	$GLOBALS['nvx_test_wp_cache_set_calls'][] = array(
		'key'   => (int) $key,
		'value' => $value,
		'group' => (string) $group,
	);
	return true;
}

$GLOBALS['nvx_test_path'] = '/matriz-diagnostico-facial-estructura-piel-musculo-grasa/';
$GLOBALS['nvx_test_404'] = true;
$GLOBALS['nvx_test_page_by_path_calls'] = 0;
$GLOBALS['nvx_test_get_posts_calls'] = 0;
$GLOBALS['nvx_test_db_row_calls'] = 0;
$GLOBALS['nvx_test_db_prepared_args'] = array();
$GLOBALS['nvx_test_clean_post_cache_calls'] = array();
$GLOBALS['nvx_test_wp_cache_set_calls'] = array();
$GLOBALS['nvx_test_setup_postdata_calls'] = array();
$GLOBALS['nvx_test_db_row'] = (object) array(
	'ID'          => 3334,
	'post_status' => 'publish',
	'post_name'   => 'matriz-diagnostico-facial-estructura-piel-musculo-grasa',
	'post_type'   => 'post',
	'post_title'  => 'Matriz de diagnóstico facial: estructura, músculo, piel y grasa',
);
$GLOBALS['wpdb'] = new wpdb();
$_SERVER['REQUEST_URI'] = $GLOBALS['nvx_test_path'];
nvx_theme_request_context();

function add_filter() { return true; }
function add_action() { return true; }
function remove_action() { return true; }
function is_admin() { return false; }
function wp_doing_ajax() { return false; }
function is_404() { return (bool) $GLOBALS['nvx_test_404']; }
function is_search() { return false; }
function is_feed() { return false; }
function is_preview() { return false; }
function is_front_page() { return false; }
function is_home() { return false; }
function is_singular() { return false; }
function get_queried_object_id() { return 0; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function home_url( $path = '' ) { return 'https://nuvanx.com' . $path; }
function get_template_directory() { return dirname( __DIR__, 2 ) . '/wp-content/themes/nuvanx-medical'; }
function sanitize_title( $value ) { return strtolower( trim( (string) $value ) ); }
function esc_url( $value ) { return (string) $value; }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function setup_postdata( $post ) { $GLOBALS['nvx_test_setup_postdata_calls'][] = (int) $post->ID; }

// Cache-aware APIs are deliberately poisoned with a neighbouring ID/content but
// the requested post_name. A slug-only validator would incorrectly accept it.
function get_page_by_path( $slug ) {
	$GLOBALS['nvx_test_page_by_path_calls']++;
	return new WP_Post(
		3310,
		(string) $slug,
		'Tratamientos faciales sin cirugía: guía médica para elegir según el diagnóstico'
	);
}

function get_posts() {
	$GLOBALS['nvx_test_get_posts_calls']++;
	return array(
		new WP_Post(
			3310,
			'matriz-diagnostico-facial-estructura-piel-musculo-grasa',
			'Tratamientos faciales sin cirugía: guía médica para elegir según el diagnóstico'
		),
	);
}

function get_post( $post_id ) {
	return new WP_Post(
		(int) $post_id,
		'matriz-diagnostico-facial-estructura-piel-musculo-grasa',
		'Poisoned cache object'
	);
}

function nvx_seo_is_nonproduction_environment() { return false; }

function nvx_theme_request_context(): array {
	static $context = null;
	if ( is_array( $context ) ) {
		return $context;
	}
	$uri = $_SERVER['REQUEST_URI'] ?? '/';
	$context = array(
		'uri'  => $uri,
		'path' => rtrim( $uri, '/' ) . '/',
	);
	return $context;
}

function nvx_seo_blog_post_metadata_catalog() {
	return array(
		'tratamientos-faciales-sin-cirugia-guia-medica-diagnostico' => array(
			'title'       => 'Tratamientos faciales sin cirugía: guía médica | NUVANX',
			'description' => 'Guía de tratamientos faciales.',
		),
		'matriz-diagnostico-facial-estructura-piel-musculo-grasa' => array(
			'title'       => 'Matriz de diagnóstico facial | NUVANX Madrid',
			'description' => 'Guía de diagnóstico facial.',
		),
	);
}

function nvx_single_post_rebind_query( WP_Query $query, WP_Post $post, ?string $slug = null ): void {
	$slug = null !== $slug && '' !== $slug ? $slug : $post->post_name;
	$query->set( 'p', $post->ID );
	$query->set( 'name', $slug );
	$query->set( 'post_type', 'post' );
	$query->is_page     = false;
	$query->is_single   = true;
	$query->is_singular = true;
	$query->is_404      = false;
	$query->is_archive  = false;
	$query->is_home     = false;
}

require_once dirname( __DIR__, 2 ) . '/wp-content/themes/nuvanx-medical/inc/nvx-document-governance.php';
require_once dirname( __DIR__, 2 ) . '/wp-content/themes/nuvanx-medical/inc/nvx-governed-blog-runtime.php';

$matrix_slug = 'matriz-diagnostico-facial-estructura-piel-musculo-grasa';
$_SERVER['REQUEST_URI'] = '/tratamientos-faciales-sin-cirugia-guia-medica-diagnostico/';
if (
	$matrix_slug !== nvx_governed_blog_runtime_request_slug()
	|| '/' . $matrix_slug . '/' !== nvx_document_governance_request_path()
) {
	fwrite( STDERR, 'GOVERNED_BLOG_IMMUTABLE_REQUEST_URI=FAIL' . PHP_EOL );
	exit( 1 );
}

$expected_contract = '20260815-immutable-request-final-query-lock-v3';
if ( ! defined( 'NVX_GOVERNED_BLOG_RUNTIME_CONTRACT' ) || $expected_contract !== NVX_GOVERNED_BLOG_RUNTIME_CONTRACT ) {
	fwrite( STDERR, 'GOVERNED_BLOG_RUNTIME_CONTRACT_VERSION=FAIL' . PHP_EOL );
	exit( 1 );
}

$resolved = nvx_governed_blog_runtime_db_post_by_slug( $matrix_slug );
$cache_set_call = $GLOBALS['nvx_test_wp_cache_set_calls'][0] ?? null;
if (
	! ( $resolved instanceof WP_Post )
	|| 3334 !== $resolved->ID
	|| $matrix_slug !== $resolved->post_name
	|| 0 !== $GLOBALS['nvx_test_page_by_path_calls']
	|| 0 !== $GLOBALS['nvx_test_get_posts_calls']
	|| 1 !== $GLOBALS['nvx_test_db_row_calls']
	|| array( 3334 ) !== $GLOBALS['nvx_test_clean_post_cache_calls']
	|| ! is_array( $cache_set_call )
	|| 3334 !== ( $cache_set_call['key'] ?? 0 )
	|| 'posts' !== ( $cache_set_call['group'] ?? '' )
	|| ! ( ( $cache_set_call['value'] ?? null ) instanceof WP_Post )
	|| 3334 !== ( $cache_set_call['value']->ID ?? 0 )
) {
	fwrite( STDERR, 'GOVERNED_BLOG_DB_AUTHORITATIVE_RUNTIME=FAIL' . PHP_EOL );
	exit( 1 );
}

$counts_before_memo = array(
	'db_row'    => $GLOBALS['nvx_test_db_row_calls'],
	'clean'     => count( $GLOBALS['nvx_test_clean_post_cache_calls'] ),
	'cache_set' => count( $GLOBALS['nvx_test_wp_cache_set_calls'] ),
);
$resolved_memoized = nvx_governed_blog_runtime_db_post_by_slug( $matrix_slug );
$counts_after_memo = array(
	'db_row'    => $GLOBALS['nvx_test_db_row_calls'],
	'clean'     => count( $GLOBALS['nvx_test_clean_post_cache_calls'] ),
	'cache_set' => count( $GLOBALS['nvx_test_wp_cache_set_calls'] ),
);
if ( ! ( $resolved_memoized instanceof WP_Post ) || 3334 !== $resolved_memoized->ID || $counts_before_memo !== $counts_after_memo ) {
	fwrite( STDERR, 'GOVERNED_BLOG_REQUEST_MEMOIZATION=FAIL' . PHP_EOL );
	exit( 1 );
}

$title = nvx_governed_blog_runtime_title( 'Wrong neighbouring title' );
$canonical = nvx_governed_blog_runtime_canonical(
	'https://nuvanx.com/tratamientos-faciales-sin-cirugia-guia-medica-diagnostico/'
);
$og_url = nvx_governed_blog_runtime_opengraph_url(
	'https://nuvanx.com/tratamientos-faciales-sin-cirugia-guia-medica-diagnostico/'
);
$expected_url = 'https://nuvanx.com/matriz-diagnostico-facial-estructura-piel-musculo-grasa/';
if ( 'Matriz de diagnóstico facial | NUVANX Madrid' !== $title || $expected_url !== $canonical || $expected_url !== $og_url ) {
	fwrite( STDERR, 'GOVERNED_BLOG_RUNTIME_METADATA=FAIL' . PHP_EOL );
	exit( 1 );
}

$GLOBALS['wp_query'] = new WP_Query();
$GLOBALS['wp_query']->set( 'p', 3310 );
$GLOBALS['wp_query']->set( 'name', 'tratamientos-faciales-sin-cirugia-guia-medica-diagnostico' );
$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
$GLOBALS['wp'] = (object) array(
	'query_vars' => array(
		'p'         => 3310,
		'name'      => 'tratamientos-faciales-sin-cirugia-guia-medica-diagnostico',
		'post_type' => 'post',
	),
);
$GLOBALS['post'] = new WP_Post( 3310, 'tratamientos-faciales-sin-cirugia-guia-medica-diagnostico' );
$rebound = nvx_governed_blog_runtime_rebind_queries();
if (
	! ( $rebound instanceof WP_Post )
	|| 3334 !== $rebound->ID
	|| 3334 !== $GLOBALS['wp_query']->get( 'p' )
	|| $matrix_slug !== $GLOBALS['wp_query']->get( 'name' )
	|| 3334 !== (int) ( $GLOBALS['wp']->query_vars['p'] ?? 0 )
	|| $matrix_slug !== ( $GLOBALS['wp']->query_vars['name'] ?? '' )
	|| 3334 !== $GLOBALS['post']->ID
	|| $GLOBALS['wp_query']->is_404
	|| ! $GLOBALS['wp_query']->is_single
	|| ! $GLOBALS['wp_query']->is_singular
	|| $GLOBALS['wp_query']->is_archive
) {
	fwrite( STDERR, 'GOVERNED_BLOG_EARLY_QUERY_REBIND=FAIL' . PHP_EOL );
	exit( 1 );
}

$forced_posts = nvx_governed_blog_runtime_force_the_posts(
	array( new WP_Post( 3310, 'tratamientos-faciales-sin-cirugia-guia-medica-diagnostico' ) ),
	$GLOBALS['wp_query']
);
$forced_template = nvx_governed_blog_runtime_template_include( '/tmp/single.php' );
$expected_template = get_template_directory() . '/single-post.php';
if (
	1 !== count( $forced_posts )
	|| ! ( $forced_posts[0] instanceof WP_Post )
	|| 3334 !== $forced_posts[0]->ID
	|| $expected_template !== $forced_template
) {
	fwrite( STDERR, 'GOVERNED_BLOG_FINAL_QUERY_TEMPLATE_LOCK=FAIL' . PHP_EOL );
	exit( 1 );
}

ob_start();
nvx_governed_blog_runtime_print_head_contract();
$head_contract = (string) ob_get_clean();
if (
	false === strpos( $head_contract, 'name="nvx-document-contract"' )
	|| false === strpos( $head_contract, 'name="nvx-governed-blog-runtime-contract"' )
	|| false === strpos( $head_contract, $expected_contract )
	|| false !== strpos( $head_contract, '<link rel="canonical"' )
) {
	fwrite( STDERR, 'GOVERNED_BLOG_HTTP_RUNTIME_SENTINEL=FAIL' . PHP_EOL );
	exit( 1 );
}

$root = dirname( __DIR__, 2 ) . '/wp-content/themes/nuvanx-medical/';
$bootstrap_source = file_get_contents( $root . 'inc/nvx-theme-bootstrap.php' );
$blog_system = file_get_contents( $root . 'inc/nvx-blog-system.php' );
$runtime_source = file_get_contents( $root . 'inc/nvx-governed-blog-runtime.php' );
$single_entrypoint = file_get_contents( $root . 'single-post.php' );

$rebind_definition_pos = is_string( $blog_system ) ? strpos( $blog_system, 'function nvx_single_post_rebind_query' ) : false;
$blog_manifest_pos = is_string( $bootstrap_source ) ? strpos( $bootstrap_source, "'inc/nvx-blog-system.php'" ) : false;
$runtime_manifest_pos = is_string( $bootstrap_source ) ? strpos( $bootstrap_source, "'inc/nvx-governed-blog-runtime.php'" ) : false;
$pre_get_posts_hook_pos = is_string( $runtime_source ) ? strpos( $runtime_source, "add_action( 'pre_get_posts', 'nvx_governed_blog_runtime_pre_get_posts', PHP_INT_MAX )" ) : false;
$the_posts_hook_pos = is_string( $runtime_source ) ? strpos( $runtime_source, "add_filter( 'the_posts', 'nvx_governed_blog_runtime_force_the_posts', PHP_INT_MAX, 2 )" ) : false;
$wp_hook_pos = is_string( $runtime_source ) ? strpos( $runtime_source, "add_action( 'wp', 'nvx_governed_blog_runtime_rebind_queries_action', PHP_INT_MAX )" ) : false;
$template_redirect_hook_pos = is_string( $runtime_source ) ? strpos( $runtime_source, "add_action( 'template_redirect', 'nvx_governed_blog_runtime_rebind_queries_action', PHP_INT_MAX )" ) : false;
$template_include_hook_pos = is_string( $runtime_source ) ? strpos( $runtime_source, "add_filter( 'template_include', 'nvx_governed_blog_runtime_template_include', PHP_INT_MAX )" ) : false;
$canonical_filter_pos = is_string( $runtime_source ) ? strpos( $runtime_source, "add_filter( 'wpseo_canonical', 'nvx_governed_blog_runtime_canonical', PHP_INT_MAX )" ) : false;
$runtime_require_pos = is_string( $single_entrypoint ) ? strpos( $single_entrypoint, "require_once __DIR__ . '/inc/nvx-governed-blog-runtime.php'" ) : false;
$db_resolver_pos = is_string( $single_entrypoint ) ? strpos( $single_entrypoint, 'nvx_governed_blog_runtime_db_post_by_slug' ) : false;
$query_name_pos = is_string( $single_entrypoint ) ? strpos( $single_entrypoint, '$wp_query->get( \'name\' )' ) : false;

if (
	false === $rebind_definition_pos
	|| false === $blog_manifest_pos
	|| false === $runtime_manifest_pos
	|| $blog_manifest_pos >= $runtime_manifest_pos
	|| false === $pre_get_posts_hook_pos
	|| false === $the_posts_hook_pos
	|| false === $wp_hook_pos
	|| false === $template_redirect_hook_pos
	|| false === $template_include_hook_pos
	|| false === $canonical_filter_pos
	|| false !== $runtime_require_pos
	|| false === $db_resolver_pos
	|| false === $query_name_pos
	|| $db_resolver_pos >= $query_name_pos
) {
	fwrite( STDERR, 'GOVERNED_BLOG_BOOTSTRAP_ORDER=FAIL' . PHP_EOL );
	exit( 1 );
}

echo 'GOVERNED_BLOG_DB_AUTHORITATIVE_RUNTIME=PASS requested_post=3334 cache_poison_id=3310 cache_poison_slug=requested cache_apis_called=0' . PHP_EOL;
echo 'GOVERNED_BLOG_REQUEST_MEMOIZATION=PASS db_queries_after_first_resolution=0' . PHP_EOL;
echo 'GOVERNED_BLOG_IMMUTABLE_REQUEST_URI=PASS requested_post=3334 mutated_server_uri=3310' . PHP_EOL;
echo 'GOVERNED_BLOG_EARLY_QUERY_REBIND=PASS requested_post=3334 stale_query_post=3310 hook=wp priority=PHP_INT_MAX' . PHP_EOL;
echo 'GOVERNED_BLOG_FINAL_QUERY_TEMPLATE_LOCK=PASS requested_post=3334 incoming_post=3310 template=single-post.php' . PHP_EOL;
echo 'GOVERNED_BLOG_CANONICAL_OWNER=PASS owner=yoast filter=wpseo_canonical manual_governed_canonical=0' . PHP_EOL;
echo 'GOVERNED_BLOG_HTTP_RUNTIME_SENTINEL=PASS contract=' . NVX_GOVERNED_BLOG_RUNTIME_CONTRACT . PHP_EOL;

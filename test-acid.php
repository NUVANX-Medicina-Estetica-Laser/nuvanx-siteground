<?php
define('WP_USE_THEMES', false);
require('wp-load.php');
$post = get_page_by_path('acido-hialuronico-relleno-madrid');
if (!$post) { echo "No post\n"; exit; }
global $wp_query;
$wp_query = new WP_Query(['p' => $post->ID, 'post_type' => 'any']);
$wp_query->the_post();
$graph = nvx_schema_build_graph();
$has_faq = false;
foreach ($graph['@graph'] as $node) {
  if (in_array('FAQPage', (array)($node['@type'] ?? []))) {
    $has_faq = true; break;
  }
}
echo "FAQ: " . ($has_faq ? "YES" : "NO") . "\n";

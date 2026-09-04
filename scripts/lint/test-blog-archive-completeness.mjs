#!/usr/bin/env node

import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../..');
const themeRoot = resolve(root, 'wp-content/themes/nuvanx-medical');
const functionsPath = resolve(themeRoot, 'functions.php');
const archivePath = resolve(themeRoot, 'template-parts/content/nvx-blog-archive.php');

const [functions, archive] = await Promise.all([
  readFile(functionsPath, 'utf8'),
  readFile(archivePath, 'utf8'),
]);

function fail(reason) {
  console.error(`BLOG_ARCHIVE_COMPLETENESS=FAIL reason=${reason}`);
  process.exit(1);
}

const postsPerPageMatch = functions.match(/->set\(\s*'posts_per_page',\s*(\d+)\s*\)/);
if (!postsPerPageMatch) {
  fail('posts_per_page_not_set');
}

const postsPerPage = parseInt(postsPerPageMatch[1], 10);
if (postsPerPage !== 24) {
  fail(`posts_per_page_invalid:${postsPerPage}`);
}

if (!functions.includes('function nvx_blog_pre_get_posts')) {
  fail('blog_pre_get_posts_function_missing');
}
if (!functions.includes("add_action( 'pre_get_posts', 'nvx_blog_pre_get_posts' )")) {
  fail('blog_pre_get_posts_hook_missing');
}
if (!functions.includes("if ( $query->is_home() && ! $query->is_front_page() )")) {
  fail('blog_home_front_page_guard_missing');
}
if (!functions.includes("->set( 'ignore_sticky_posts', true )")) {
  fail('ignore_sticky_posts_not_set');
}
if (functions.includes("->set( 'posts_per_page', -1 )")) {
  fail('posts_per_page_unlimited_forbidden');
}

// Journal media is a governed editorial decision. Arbitrary WordPress featured
// images are not authoritative because they can be stale, duplicated or belong
// to another campaign/surface. The named catalog requires at least two semantic
// signals and fails closed to a text card when no strong match exists.
if (!archive.includes('function nvx_blog_archive_semantic_image')) {
  fail('semantic_media_resolver_missing');
}
if (!archive.includes("0 === strpos( $id, 'novias-' )")) {
  fail('bridal_asset_exclusion_missing');
}
if (!archive.includes('if ( ! is_array( $best ) || $best_score < 2 )')) {
  fail('strong_semantic_score_gate_missing');
}
if (!archive.includes('isset( $used[ $id ] )')) {
  fail('page_level_media_uniqueness_missing');
}
for (const forbidden of ['has_post_thumbnail(', 'get_post_thumbnail_id(', 'get_the_post_thumbnail(']) {
  if (archive.includes(forbidden)) {
    fail(`arbitrary_featured_image_authority:${forbidden}`);
  }
}
if (!archive.includes("return '';")) {
  fail('no_media_fail_closed_missing');
}
if (archive.includes('nvx_blog_archive_card_image(')) {
  fail('legacy_arbitrary_media_resolver_still_used_by_archive');
}

console.log(
  `BLOG_ARCHIVE_COMPLETENESS=PASS posts_per_page=${postsPerPage} function=exists hook=registered ` +
  'guard=home_not_front_page ignore_sticky=true unlimited=forbidden media=strong_semantic_fail_closed ' +
  'featured_authority=forbidden bridal_assets=forbidden duplicate_assets=forbidden threshold=2'
);

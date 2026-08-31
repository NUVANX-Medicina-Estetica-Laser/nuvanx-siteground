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

// Verify posts_per_page is set to 24 (not 12, not -1)
const postsPerPageMatch = functions.match(/->set\(\s*'posts_per_page',\s*(\d+)\s*\)/);
if (!postsPerPageMatch) {
  fail('posts_per_page_not_set');
}

const postsPerPage = parseInt(postsPerPageMatch[1], 10);
if (postsPerPage !== 24) {
  fail(`posts_per_page_invalid:${postsPerPage}`);
}

// Verify the function exists and is hooked correctly
if (!functions.includes('function nvx_blog_pre_get_posts')) {
  fail('blog_pre_get_posts_function_missing');
}

if (!functions.includes("add_action( 'pre_get_posts', 'nvx_blog_pre_get_posts' )")) {
  fail('blog_pre_get_posts_hook_missing');
}

// Verify it only applies to home (blog index) and not front page
if (!functions.includes("if ( $query->is_home() && ! $query->is_front_page() )")) {
  fail('blog_home_front_page_guard_missing');
}

// Verify ignore_sticky_posts is set (standard for blog index)
if (!functions.includes("->set( 'ignore_sticky_posts', true )")) {
  fail('ignore_sticky_posts_not_set');
}

// Verify the value is not -1 (unlimited) which would cause unbounded growth
if (functions.includes("->set( 'posts_per_page', -1 )")) {
  fail('posts_per_page_unlimited_forbidden');
}

// The Journal archive must fail closed on media. It may render a valid featured
// image or a positively matched clinical asset, but it must never use the legacy
// "next unused image" fallback that caused unrelated Bridal photography to be
// attached to Exposoma, Well-aging and CO2 articles.
if (!archive.includes('function nvx_blog_archive_semantic_image')) {
  fail('semantic_media_resolver_missing');
}
if (!archive.includes("0 === strpos( $id, 'novias-' )")) {
  fail('bridal_fallback_exclusion_missing');
}
if (!archive.includes('if ( ! is_array( $best ) || $best_score < 1 )')) {
  fail('positive_semantic_score_gate_missing');
}
if (!archive.includes("return '';")) {
  fail('no_media_fail_closed_missing');
}
if (archive.includes('nvx_blog_archive_card_image(')) {
  fail('legacy_arbitrary_media_resolver_still_used_by_archive');
}

console.log(`BLOG_ARCHIVE_COMPLETENESS=PASS posts_per_page=${postsPerPage} function=exists hook=registered guard=home_not_front_page ignore_sticky=true unlimited=forbidden media=semantic_fail_closed bridal_fallback=forbidden`);

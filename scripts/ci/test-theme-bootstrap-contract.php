<?php
/**
 * Theme bootstrap contract to detect inter-module fatals and redeclarations
 */

// Mock basic WordPress core constants and functions needed to include files
define( 'ABSPATH', __DIR__ . '/' );
function add_action() {}
function add_filter() {}
function apply_filters($tag, $value) { return $value; }
function add_theme_support() {}
function load_theme_textdomain() {}
function get_template_directory() { return dirname(__DIR__, 2) . '/wp-content/themes/nuvanx-medical'; }
function get_template_directory_uri() { return ''; }
function wp_enqueue_style() {}
function wp_enqueue_script() {}

require_once get_template_directory() . '/functions.php';
nvx_theme_bootstrap_runtime();
echo "BOOTSTRAP_CONTRACT=PASS\n";

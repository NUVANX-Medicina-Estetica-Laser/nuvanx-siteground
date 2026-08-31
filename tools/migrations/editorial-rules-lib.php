<?php
/**
 * Backward-compatible migration shim for the canonical shared editorial rules.
 *
 * @package NVX\Migrations
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/../../lib/nvx-editorial-rules.php';

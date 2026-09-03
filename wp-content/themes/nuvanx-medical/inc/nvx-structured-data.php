<?php
/**
 * NUVANX structured-data bootstrap.
 *
 * The implementation is split by responsibility while preserving the original
 * function names, hook ownership, and deterministic load order.
 *
 * @package NUVANX
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/nvx-schema-foundation.php';
require_once __DIR__ . '/nvx-schema-faq.php';
require_once __DIR__ . '/nvx-schema-treatments.php';
require_once __DIR__ . '/nvx-schema-physicians.php';
require_once __DIR__ . '/nvx-schema-graph.php';
require_once __DIR__ . '/nvx-tariff-output-guard.php';

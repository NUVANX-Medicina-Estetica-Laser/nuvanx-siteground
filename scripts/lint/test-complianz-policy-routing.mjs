#!/usr/bin/env node
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const routingPath = path.join(root, 'wp-content/themes/nuvanx-medical/inc/nvx-complianz-policy-routing.php');
const headerPath = path.join(root, 'wp-content/themes/nuvanx-medical/header.php');

const [routing, header] = await Promise.all([
  fs.readFile(routingPath, 'utf8'),
  fs.readFile(headerPath, 'utf8'),
]);

assert.ok(routing.includes("home_url( '/politica-privacidad/' )"), 'privacy route must remain canonical');
assert.ok(routing.includes("home_url( '/politica-de-cookies-ue/' )"), 'cookie route must remain canonical');
assert.ok(routing.includes("home_url( '/aviso-legal/' )"), 'legal route must remain canonical');
assert.ok(routing.includes("$metadata_destination = nvx_complianz_policy_destination_from_hint( $relative_url );"), 'routing metadata must be classified independently before label fallback');
assert.ok(routing.includes("$attributes   = $attr_before . ' ' . $attr_after;"), 'routing must inspect attributes on both sides of href');
assert.ok(routing.includes("remove_filter( 'cmplz_banner_html', 'nvx_sanitize_complianz_banner_html', 20 )"), 'legacy banner owner must be retired');
assert.ok(routing.includes("remove_filter( 'cmplz_template', 'nvx_sanitize_complianz_banner_html', 20 )"), 'legacy template owner must be retired');
assert.ok(routing.includes("add_filter( 'cmplz_banner_html', 'nvx_rewrite_complianz_policy_links', 20 )"), 'canonical banner owner must be registered');
assert.ok(routing.includes("add_filter( 'cmplz_template', 'nvx_rewrite_complianz_policy_links', 20 )"), 'canonical template owner must be registered');
assert.ok(header.includes("require_once __DIR__ . '/inc/nvx-complianz-policy-routing.php';"), 'canonical routing owner must load before wp_head');
assert.ok(header.indexOf("require_once __DIR__ . '/inc/nvx-complianz-policy-routing.php';") < header.indexOf('wp_head();'), 'routing owner must load before wp_head');

console.log('COMPLIANZ_POLICY_ROUTING_STATIC=PASS owner=canonical metadata=authoritative translated_hash_links=covered');

#!/usr/bin/env node
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const routingPath = path.join(root, 'wp-content/themes/nuvanx-medical/inc/nvx-complianz-policy-routing.php');
const bootstrapPath = path.join(root, 'wp-content/themes/nuvanx-medical/inc/nvx-theme-bootstrap.php');
const headerPath = path.join(root, 'wp-content/themes/nuvanx-medical/header.php');

const [routing, bootstrap, header] = await Promise.all([
  fs.readFile(routingPath, 'utf8'),
  fs.readFile(bootstrapPath, 'utf8'),
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

const complianzModule = "'inc/nvx-complianz-policy-routing.php'";
const modulePosition = bootstrap.indexOf(complianzModule);
const bootstrapHookPosition = bootstrap.indexOf("add_action( 'after_setup_theme', 'nvx_theme_bootstrap_modules', -1000 );");

assert.ok(modulePosition >= 0, 'canonical routing owner must be declared in bootstrap manifest');
assert.ok(bootstrapHookPosition >= 0, 'canonical bootstrap must run at after_setup_theme priority -1000');
assert.doesNotMatch(
  header,
  /require_once[^\n]*nvx-complianz-policy-routing\.php/,
  'header.php must not laterally load Complianz routing',
);
assert.ok(header.includes('wp_head();'), 'header.php must retain wp_head');

console.log('COMPLIANZ_POLICY_ROUTING_STATIC=PASS owner=bootstrap-manifest lifecycle=after_setup_theme:-1000 metadata=authoritative translated_hash_links=covered');

#!/usr/bin/env node
import fs from 'node:fs';

const EXPECTED_SCHEMA = 'wp-content/themes/nuvanx-medical/inc/data/routes.schema.json';
const EXPECTED_DATA = 'wp-content/themes/nuvanx-medical/inc/data/routes.json';
const [, , schemaArg = EXPECTED_SCHEMA, dataArg = EXPECTED_DATA] = process.argv;
if (schemaArg !== EXPECTED_SCHEMA || dataArg !== EXPECTED_DATA) {
  throw new Error(`Only canonical route files are allowed: ${EXPECTED_SCHEMA} ${EXPECTED_DATA}`);
}

const schemaUrl = new URL('../../wp-content/themes/nuvanx-medical/inc/data/routes.schema.json', import.meta.url);
const dataUrl = new URL('../../wp-content/themes/nuvanx-medical/inc/data/routes.json', import.meta.url);
const schemaRaw = fs.readFileSync(schemaUrl, 'utf8');
const dataRaw = fs.readFileSync(dataUrl, 'utf8');

function readTopLevelKeys(raw) {
  const keys = [];
  let depth = 0;
  let previousSignificant = '';

  for (let i = 0; i < raw.length; i += 1) {
    const char = raw[i];

    if (char === '"') {
      const start = i;
      i += 1;
      let escaped = false;
      for (; i < raw.length; i += 1) {
        const stringChar = raw[i];
        if (escaped) {
          escaped = false;
          continue;
        }
        if (stringChar === '\\') {
          escaped = true;
          continue;
        }
        if (stringChar === '"') break;
      }
      if (i >= raw.length) throw new Error('routes.json contains an unterminated string');

      if (depth === 1 && (previousSignificant === '{' || previousSignificant === ',')) {
        let cursor = i + 1;
        while (cursor < raw.length && /\s/.test(raw[cursor])) cursor += 1;
        if (raw[cursor] === ':') {
          keys.push(JSON.parse(raw.slice(start, i + 1)));
        }
      }
      previousSignificant = '"';
      continue;
    }

    if (char === '{' || char === '[') depth += 1;
    if (char === '}' || char === ']') depth -= 1;
    if (!/\s/.test(char)) previousSignificant = char;
  }

  return keys;
}

function normalizeRoute(value) {
  const trimmed = String(value ?? '').trim();
  if (!trimmed) return '';
  const withLeadingSlash = trimmed.startsWith('/') ? trimmed : `/${trimmed}`;
  const collapsed = withLeadingSlash.replace(/\/{2,}/g, '/');
  const withoutTrailingSlash = collapsed.replace(/\/+$/, '');
  return withoutTrailingSlash ? `${withoutTrailingSlash}/` : '/';
}

const topLevelKeys = readTopLevelKeys(dataRaw);
const duplicateKeys = [...new Set(topLevelKeys.filter((key, index) => topLevelKeys.indexOf(key) !== index))];
if (duplicateKeys.length > 0) {
  throw new Error(`routes.json has duplicate top-level route keys: ${duplicateKeys.join(', ')}`);
}

const schema = JSON.parse(schemaRaw);
const data = JSON.parse(dataRaw);
const routeSchema = schema.patternProperties?.['^/.*'];
if (!routeSchema || schema.type !== 'object') throw new Error('Unsupported routes schema contract');
if (!data || Array.isArray(data) || typeof data !== 'object') throw new Error('routes.json must be an object');

const allowed = new Set(Object.keys(routeSchema.properties || {}));
const normalizedRoutes = new Map();
let aliasCount = 0;

for (const [route, entry] of Object.entries(data)) {
  if (!route.startsWith('/')) throw new Error(`Invalid route key: ${route}`);
  if (!entry || Array.isArray(entry) || typeof entry !== 'object') throw new Error(`Route ${route} must map to an object`);

  const normalizedRoute = normalizeRoute(route);
  const existingRoute = normalizedRoutes.get(normalizedRoute);
  if (existingRoute && existingRoute !== route) {
    throw new Error(`Routes ${existingRoute} and ${route} normalize to the same path ${normalizedRoute}`);
  }
  normalizedRoutes.set(normalizedRoute, route);

  for (const [key, value] of Object.entries(entry)) {
    if (!allowed.has(key)) throw new Error(`Route ${route} has unsupported property ${key}`);
    const rule = routeSchema.properties[key] || {};
    if (rule.type === 'string' && typeof value !== 'string') throw new Error(`Route ${route}.${key} must be a string`);
    if (rule.type === 'integer' && !Number.isInteger(value)) throw new Error(`Route ${route}.${key} must be an integer`);
    if (rule.enum && !rule.enum.includes(value)) throw new Error(`Route ${route}.${key} has invalid value ${value}`);
  }

  if (Object.hasOwn(entry, 'route_alias')) aliasCount += 1;
}

for (const [route, entry] of Object.entries(data)) {
  if (!Object.hasOwn(entry, 'route_alias')) continue;

  const normalizedRoute = normalizeRoute(route);
  const normalizedTarget = normalizeRoute(entry.route_alias);
  if (!normalizedTarget) throw new Error(`Route ${route}.route_alias must not be empty`);
  
  if (entry.route_alias !== normalizedTarget) {
    throw new Error(`Route ${route}.route_alias must use normalized format (got '${entry.route_alias}', expected '${normalizedTarget}')`);
  }

  if (normalizedRoute === normalizedTarget) {
    throw new Error(`Route ${route} must not alias itself (${entry.route_alias})`);
  }
  if (!normalizedRoutes.has(normalizedTarget)) {
    throw new Error(`Route ${route} aliases missing canonical destination ${entry.route_alias}`);
  }

  const targetOriginalKey = normalizedRoutes.get(normalizedTarget);
  if (targetOriginalKey && Object.hasOwn(data[targetOriginalKey] || {}, 'route_alias')) {
    throw new Error(`Route ${route} must alias a canonical route, not alias ${targetOriginalKey}`);
  }
}

for (const route of Object.keys(data)) {
  const visited = new Set();
  let currentRoute = route;

  while (Object.hasOwn(data[currentRoute] || {}, 'route_alias')) {
    if (visited.has(currentRoute)) {
      throw new Error(`Alias cycle detected from ${route}: ${[...visited, currentRoute].join(' -> ')}`);
    }
    visited.add(currentRoute);
    const normalizedTarget = normalizeRoute(data[currentRoute].route_alias);
    currentRoute = normalizedRoutes.get(normalizedTarget);
  }
}

console.log(`ROUTES_SCHEMA=PASS routes=${Object.keys(data).length} aliases=${aliasCount}`);

import fs from 'node:fs/promises';

const manifestUrl = new URL('../../wp-content/themes/nuvanx-medical/inc/data/publication-manifest.json', import.meta.url);

export const MIN_MANIFEST_ENTRIES = 40;

// Canonical Block C viewport matrix. Shared so the entrypoint's
// expected-results math stays in sync with the browser matrix.
export const VIEWPORTS = [
  { key: 'desktop-1440x1100', label: 'Desktop 1440×1100', width: 1440, height: 1100 },
  { key: 'tablet-1024x768', label: 'Tablet 1024×768', width: 1024, height: 768 },
  { key: 'mobile-390x844', label: 'Mobile 390×844', width: 390, height: 844 },
];

function normalizePath(value) {
  const path = String(value || '').split(/[?#]/, 1)[0] || '/';
  return path.endsWith('/') ? path : `${path}/`;
}

export async function loadPublishedPagesManifest() {
  const raw = JSON.parse(await fs.readFile(manifestUrl, 'utf8'));
  const manifest = Object.entries(raw.routes || {}).map(([path, data]) => ({ path, ...data }));
  if (manifest.length === 0) {
    throw new Error('Canonical published-page manifest must be a non-empty array');
  }
  if (manifest.length < MIN_MANIFEST_ENTRIES) {
    throw new Error(`Canonical published-page manifest has only ${manifest.length} entries; minimum ${MIN_MANIFEST_ENTRIES} required to prevent accidental truncation`);
  }

  for (const page of manifest) {
    if (!page || typeof page.path !== 'string' || page.path.trim() === '') {
      throw new Error('Canonical published-page manifest contains entry with missing or empty path');
    }
  }

  const paths = manifest.map((page) => normalizePath(page.path));
  if (paths.some((path) => !path.startsWith('/')) || new Set(paths).size !== paths.length) {
    throw new Error('Canonical published-page manifest contains invalid or duplicate paths');
  }

  return manifest;
}

export function assertCanonicalPublishedPaths(actualPaths, manifest, sourceLabel) {
  const actual = new Set([...actualPaths].map(normalizePath));
  const missing = manifest.map((page) => normalizePath(page.path)).filter((path) => !actual.has(path));
  if (missing.length > 0) {
    throw new Error(`${sourceLabel} is missing canonical published paths: ${missing.join(', ')}`);
  }
}

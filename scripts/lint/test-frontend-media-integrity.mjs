#!/usr/bin/env node
import fs from 'node:fs';
import crypto from 'node:crypto';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const theme = path.join(root, 'wp-content/themes/nuvanx-medical');
const failures = [];
const requireInvariant = (condition, name) => {
  if (!condition) failures.push(name);
};
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8');

const registry = read('wp-content/themes/nuvanx-medical/inc/nvx-page-registry.php');
const bridal = read('wp-content/themes/nuvanx-medical/inc/nvx-bridal-page.php');
const casesTemplate = read('wp-content/themes/nuvanx-medical/page-casos-de-pacientes.php');
const blogArchive = read('wp-content/themes/nuvanx-medical/template-parts/content/nvx-blog-archive.php');
const blogSystem = read('wp-content/themes/nuvanx-medical/inc/nvx-blog-system.php');
const casesData = JSON.parse(read('wp-content/themes/nuvanx-medical/inc/data/patient-cases.json'));

// Bridal must participate in the same canonical page ownership that removes
// long-form prose constraints from managed component documents.
requireInvariant(registry.includes("'/protocolo-novias-madrid/' => array("), 'BRIDAL_ROUTE_REGISTERED');
requireInvariant(registry.includes("'owner'    => 'nvx_bridal_page'"), 'BRIDAL_CANONICAL_OWNER');
requireInvariant(registry.includes("'renderer' => 'nvx_bridal_inject_media'"), 'BRIDAL_CANONICAL_RENDERER');
requireInvariant(!bridal.includes("require_once __DIR__ . '/nvx-page-render-helpers.php'"), 'BRIDAL_NO_LATERAL_HELPER_BOOTSTRAP');

// Clinical case media must be explicit, scoped and fail closed. Consent alone
// never authorizes a photographic pair.
const allowedMediaStates = new Set(['approved', 'quarantined']);
const pairIds = new Set();
const approvedPaths = new Map();
const approvedHashes = new Map();
const forbiddenClinicalMedia = /novias|bridal|postpart|embaraz|pregnan|recuperacion_postparto/i;
const cases = Array.isArray(casesData.cases) ? casesData.cases : [];
requireInvariant(cases.length > 0, 'CASES_EXIST');

for (const clinicalCase of cases) {
  const id = String(clinicalCase.id || 'unknown');
  requireInvariant(clinicalCase.media_scope === 'clinical_case', `CASE_MEDIA_SCOPE_${id}`);
  requireInvariant(allowedMediaStates.has(clinicalCase.media_status), `CASE_MEDIA_STATUS_${id}`);
  requireInvariant(clinicalCase.media_kind === 'before_after', `CASE_MEDIA_KIND_${id}`);
  const pairId = String(clinicalCase.media_pair_id || '');
  requireInvariant(pairId !== '', `CASE_PAIR_ID_${id}`);
  requireInvariant(!pairIds.has(pairId), `CASE_PAIR_ID_UNIQUE_${id}`);
  pairIds.add(pairId);

  if (clinicalCase.media_status !== 'approved') continue;

  const before = String(clinicalCase.image_before || '');
  const after = String(clinicalCase.image_after || '');
  requireInvariant(before !== '' && after !== '', `APPROVED_CASE_HAS_PAIR_${id}`);
  requireInvariant(before !== after, `APPROVED_CASE_PATHS_DIFFER_${id}`);
  requireInvariant(!forbiddenClinicalMedia.test(before) && !forbiddenClinicalMedia.test(after), `APPROVED_CASE_SCOPE_SAFE_${id}`);

  for (const [role, relative] of [['before', before], ['after', after]]) {
    const absolute = path.join(theme, relative.replace(/^\/+/, ''));
    requireInvariant(fs.existsSync(absolute), `APPROVED_CASE_FILE_EXISTS_${id}_${role}`);
    if (!fs.existsSync(absolute)) continue;
    requireInvariant(!approvedPaths.has(relative), `APPROVED_CASE_PATH_UNIQUE_${id}_${role}`);
    approvedPaths.set(relative, `${id}:${role}`);
    const hash = crypto.createHash('sha256').update(fs.readFileSync(absolute)).digest('hex');
    requireInvariant(!approvedHashes.has(hash), `APPROVED_CASE_BINARY_UNIQUE_${id}_${role}`);
    approvedHashes.set(hash, `${id}:${role}`);
  }
}

const case01 = cases.find((entry) => entry.id === 'caso-01-papada-submenton');
const case03 = cases.find((entry) => entry.id === 'caso-03-abdomen-firmeza');
requireInvariant(case01?.media_status === 'quarantined', 'PAPADA_PAIR_QUARANTINED_PENDING_VERIFICATION');
requireInvariant(case03?.media_status === 'quarantined', 'ABDOMEN_PAIR_QUARANTINED_PENDING_VERIFICATION');
requireInvariant(casesTemplate.includes("'clinical_case' === ( $case['media_scope'] ?? '' )"), 'CASE_TEMPLATE_REQUIRES_MEDIA_SCOPE');
requireInvariant(casesTemplate.includes("'approved' === ( $case['media_status'] ?? '' )"), 'CASE_TEMPLATE_REQUIRES_MEDIA_APPROVAL');
requireInvariant(casesTemplate.includes('$media_is_approved && ! empty( $case[\'image_before\'] )'), 'CASE_TEMPLATE_FAILS_CLOSED_BEFORE_AFTER');

// Journal archive cannot inherit arbitrary WP featured media. Only the governed
// named catalog may supply an image, with a positive semantic threshold and
// page-level uniqueness.
for (const forbidden of ['has_post_thumbnail(', 'get_post_thumbnail_id(', 'get_the_post_thumbnail(']) {
  requireInvariant(!blogArchive.includes(forbidden), `JOURNAL_NO_ARBITRARY_FEATURED_IMAGE_${forbidden}`);
}
requireInvariant(blogArchive.includes("0 === strpos( $id, 'novias-' )"), 'JOURNAL_EXCLUDES_BRIDAL_ASSETS');
requireInvariant(blogArchive.includes('$best_score < 2'), 'JOURNAL_REQUIRES_STRONG_SEMANTIC_MATCH');
requireInvariant(blogArchive.includes('isset( $used[ $id ] )'), 'JOURNAL_PREVENTS_PAGE_DUPLICATE_ASSET');

const catalogStart = blogSystem.indexOf('function nvx_blog_named_image_catalog');
const catalogEnd = catalogStart >= 0 ? blogSystem.indexOf('function nvx_blog_match_named_image', catalogStart) : -1;
const catalogBlock = catalogStart >= 0 && catalogEnd > catalogStart ? blogSystem.slice(catalogStart, catalogEnd) : '';
requireInvariant(catalogBlock !== '', 'JOURNAL_NAMED_CATALOG_PRESENT');
requireInvariant(!/assets\/images\/cases\//i.test(catalogBlock), 'JOURNAL_CATALOG_NO_PATIENT_CASE_ASSETS');
requireInvariant(!/postpart|embaraz|pregnan|recuperacion_postparto/i.test(catalogBlock), 'JOURNAL_CATALOG_NO_POSTMATERNITY_CONTEXT_ASSETS');

if (failures.length > 0) {
  console.error('FRONTEND_MEDIA_INTEGRITY=FAIL');
  for (const failure of failures) console.error(` - ${failure}`);
  process.exit(1);
}

console.log(
  `FRONTEND_MEDIA_INTEGRITY=PASS bridal_owner=1 clinical_cases=${cases.length} ` +
  `approved_media_pairs=${approvedHashes.size / 2} quarantined_pairs=2 journal_featured_authority=0 journal_semantic_threshold=2`
);

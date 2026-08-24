#!/usr/bin/env node

import { readFile, readdir } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { dirname, extname, join, resolve } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../..');
const themeRoot = resolve(root, 'wp-content/themes/nuvanx-medical');
const matrixPath = resolve(themeRoot, 'inc/data/clinical-matrix.json');
const governancePath = resolve(themeRoot, 'inc/nvx-clinical-governance.php');
const constantsPath = resolve(themeRoot, 'inc/nvx-constants.php');

const [matrixRaw, governance, constants] = await Promise.all([
  readFile(matrixPath, 'utf8'),
  readFile(governancePath, 'utf8'),
  readFile(constantsPath, 'utf8'),
]);

const matrix = JSON.parse(matrixRaw);
const treatments = matrix?.treatments ?? {};

function fail(reason) {
  console.error(`CLINICAL_EVIDENCE_CONTRACT=FAIL reason=${reason}`);
  process.exit(1);
}

async function collectPublicCopyFiles(dir) {
  const out = [];
  const entries = await readdir(dir, { withFileTypes: true });
  for (const entry of entries) {
    if (entry.name === 'vendor' || entry.name === 'node_modules' || entry.name.startsWith('.git')) continue;
    const path = join(dir, entry.name);
    if (entry.isDirectory()) {
      out.push(...await collectPublicCopyFiles(path));
      continue;
    }
    if (entry.isFile() && ['.php', '.json'].includes(extname(entry.name).toLowerCase())) out.push(path);
  }
  return out;
}

const required = {
  endolift_facial: ['38886198', '39827299', '35083532'],
  laser_co2: ['22766970', '42334669'],
  exion_face: ['40243133'],
};

for (const [treatmentId, pmids] of Object.entries(required)) {
  const evidence = treatments?.[treatmentId]?.evidence;
  if (!Array.isArray(evidence) || evidence.length !== pmids.length) {
    fail(`evidence_count:${treatmentId}`);
  }

  const found = new Set();
  for (const row of evidence) {
    for (const field of ['study_type', 'sample_size', 'title', 'summary', 'limitation', 'source_label', 'source_url', 'pmid']) {
      if (typeof row?.[field] !== 'string' || row[field].trim() === '') {
        fail(`missing_${field}:${treatmentId}`);
      }
    }
    if (!/^https:\/\/pubmed\.ncbi\.nlm\.nih\.gov\/\d+\/$/.test(row.source_url)) {
      fail(`non_pubmed_source:${treatmentId}:${row.pmid}`);
    }
    if (!row.source_url.includes(`/${row.pmid}/`) || !row.source_label.includes(row.pmid)) {
      fail(`pmid_source_mismatch:${treatmentId}:${row.pmid}`);
    }
    found.add(row.pmid);
  }

  for (const pmid of pmids) {
    if (!found.has(pmid)) fail(`required_pmid_missing:${treatmentId}:${pmid}`);
  }
}

const forbidden = [
  /Endolift[^\n]{0,120}20\s*[%–-]\s*40\s*%/iu,
  /EXION[^\n]{0,120}37\s*%[^\n]{0,80}col[aá]geno/iu,
  /94\s*%[^\n]{0,100}n\s*=\s*47/iu,
];
const publicCopyFiles = await collectPublicCopyFiles(themeRoot);
for (const file of publicCopyFiles) {
  const content = await readFile(file, 'utf8');
  for (const pattern of forbidden) {
    if (pattern.test(content)) {
      fail(`forbidden_unqualified_claim:${file.slice(themeRoot.length + 1)}:${pattern.source}`);
    }
  }
}

for (const marker of [
  'data-nvx-clinical-evidence=',
  "esc_html__( 'Límite de la evidencia:'",
  "esc_url( $source_url )",
  "NVX_HOOK_PRIO_CLINICAL_EVIDENCE",
]) {
  if (!governance.includes(marker)) fail(`render_contract_missing:${marker}`);
}

if (!governance.includes("if ( ! is_array( $treatment ) )")) {
  fail('missing_treatment_null_guard');
}

if (!constants.includes('const NVX_HOOK_PRIO_CLINICAL_EVIDENCE = 98;')) {
  fail('clinical_evidence_priority_missing');
}

const exion = treatments.exion_face.evidence[0];
if (!/n=7 total; RF\+TUS n=3/.test(exion.sample_size)) fail('exion_small_subgroup_not_explicit');
if (!/endpoint histol[oó]gico/iu.test(exion.limitation)) fail('exion_histology_limitation_missing');
if (!/financiad[oa] por BTL Industries/iu.test(exion.limitation)) fail('exion_funding_disclosure_missing');

const endoliftEvidence = treatments.endolift_facial.evidence;
const endoliftCritical = endoliftEvidence.find((row) => row.pmid === '39827299');
if (!/alto riesgo de sesgo/iu.test(endoliftCritical?.summary ?? '')) fail('endolift_2025_bias_finding_missing');
if (!/falta de estandarizaci[oó]n/iu.test(endoliftCritical?.summary ?? '')) fail('endolift_2025_standardization_finding_missing');
if (!/no demuestra por sí sola ausencia de efecto/iu.test(endoliftCritical?.limitation ?? '')) fail('endolift_2025_balance_qualifier_missing');

const endoliftSmall = endoliftEvidence.find((row) => row.pmid === '35083532');
if (!/Muestra muy pequeña/iu.test(endoliftSmall?.limitation ?? '')) fail('endolift_small_sample_limitation_missing');

const co2Rct = treatments.laser_co2.evidence.find((row) => row.pmid === '22766970');
if (!/6,15 a 3,89/.test(co2Rct?.summary ?? '') || !/5,72 a 3,56/.test(co2Rct?.summary ?? '')) {
  fail('co2_rct_endpoint_values_missing');
}

const co2Meta = treatments.laser_co2.evidence.find((row) => row.pmid === '42334669');
if (!/RR 1,10/.test(co2Meta?.summary ?? '')) fail('co2_meta_categorical_success_missing');
if (!/RR 3,04/.test(co2Meta?.summary ?? '')) fail('co2_meta_pih_risk_missing');
if (!/frente a RF microneedling, el dolor fue menor con CO₂/iu.test(co2Meta?.summary ?? '')) fail('co2_meta_pain_comparison_missing');
if (!/I² 97% y 92%/.test(co2Meta?.limitation ?? '')) fail('co2_meta_heterogeneity_missing');

console.log(`CLINICAL_EVIDENCE_CONTRACT=PASS treatments=3 sources=6 balanced_evidence=1 public_copy_files=${publicCopyFiles.length} forbidden_claims=absent`);

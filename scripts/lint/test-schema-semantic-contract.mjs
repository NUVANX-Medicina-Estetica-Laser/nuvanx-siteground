#!/usr/bin/env node
/**
 * Schema Source Pattern Contract Test
 *
 * Fast fail-closed lint for prohibited schema source patterns. This does not
 * replace the rendered JSON-LD contract executed against WordPress/Yoast in
 * staging acceptance.
 */

import fs from 'node:fs/promises';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoPath = path.join(__dirname, '../..');
const themePath = path.join(repoPath, 'wp-content/themes/nuvanx-medical');
const ALLOWED_PROCEDURE_TYPES = new Set([
  'https://schema.org/PercutaneousProcedure',
  'https://schema.org/NoninvasiveProcedure',
]);
const ALLOWED_MEDICAL_SPECIALTIES = new Set([
  'Anesthesia', 'Cardiovascular', 'CommunityHealth', 'Dentistry', 'Dermatology', 'DietNutrition',
  'Emergency', 'Endocrine', 'Gastroenterologic', 'Genetic', 'Geriatric', 'Gynecologic', 'Hematologic', 'Infectious',
  'LaboratoryScience', 'Midwifery', 'Musculoskeletal', 'Neurologic', 'Nursing', 'Obstetric', 'Oncologic', 'Optometric',
  'Otolaryngologic', 'Pathology', 'Pediatric', 'PharmacySpecialty', 'Physiotherapy', 'PlasticSurgery', 'Podiatric',
  'PrimaryCare', 'Psychiatric', 'PublicHealth', 'Pulmonary', 'Radiography', 'Renal', 'RespiratoryTherapy',
  'Rheumatologic', 'SpeechPathology', 'Surgical', 'Toxicologic', 'Urologic',
].map((member) => `https://schema.org/${member}`));

const violations = [];

function addViolation(file, type, context, count = 1) {
  violations.push({ file, type, context, count });
}

function extractPhpFunctionBody(content, functionName) {
  const marker = `function ${functionName}`;
  const start = content.indexOf(marker);
  if (start < 0) return '';
  const open = content.indexOf('{', start + marker.length);
  if (open < 0) return '';
  let depth = 0;
  let quote = '';
  let escaped = false;
  let comment = false;
  let commentLine = false;
  for (let i = open; i < content.length; i += 1) {
    const ch = content[i];
    const next = content[i + 1] || '';

    // Handle comment transitions
    if (!quote && !escaped) {
      if (commentLine) {
        if (ch === '\n') commentLine = false;
        continue;
      }
      if (comment) {
        if (ch === '*' && next === '/') {
          comment = false;
          i += 1;
          continue;
        }
        continue;
      }
      if (ch === '/' && next === '/') {
        commentLine = true;
        i += 1;
        continue;
      }
      if (ch === '/' && next === '*') {
        comment = true;
        i += 1;
        continue;
      }
    }

    if (comment || commentLine) continue;

    if (quote) {
      if (escaped) {
        escaped = false;
      } else if (ch === '\\') {
        escaped = true;
      } else if (ch === quote) {
        quote = '';
      }
      continue;
    }
    if (ch === "'" || ch === '"') {
      quote = ch;
      continue;
    }
    if (ch === '{') depth += 1;
    if (ch === '}') {
      depth -= 1;
      if (depth === 0) return content.slice(open + 1, i);
    }
  }
  return '';
}

function validatePhpProcedureTypes(file, content) {
  const regex = /['"`]procedureType['"`]\s*=>\s*['"`]([^'"`]+)['"`]/g;
  for (const match of content.matchAll(regex)) {
    const value = match[1];
    const normalized = value.startsWith('https://') ? value : `https://schema.org/${value}`;
    if (!ALLOWED_PROCEDURE_TYPES.has(normalized)) {
      addViolation(file, 'procedureType', `Invalid procedureType value: ${value}`);
    }
  }
  const invalidLegacy = content.match(/MinimallyInvasiveProcedure/g) || [];
  if (invalidLegacy.length > 0) {
    addViolation(file, 'procedureType', 'MinimallyInvasiveProcedure is not a valid MedicalProcedureType value', invalidLegacy.length);
  }
}

function validatePhpMedicalSpecialties(file, content) {
  const direct = /['"`]medicalSpecialty['"`]\s*=>\s*['"`]([^'"`]+)['"`]/g;
  for (const match of content.matchAll(direct)) {
    if (!ALLOWED_MEDICAL_SPECIALTIES.has(match[1])) {
      addViolation(file, 'medicalSpecialty', `medicalSpecialty must be a Schema.org MedicalSpecialty enum URL: ${match[1]}`);
    }
  }

  const literalArrays = /['"`]medicalSpecialty['"`]\s*=>\s*array\s*\(([^)]*)\)/g;
  for (const match of content.matchAll(literalArrays)) {
    const values = [...match[1].matchAll(/['"`]([^'"`]+)['"`]/g)].map((item) => item[1]);
    for (const value of values) {
      if (!ALLOWED_MEDICAL_SPECIALTIES.has(value)) {
        addViolation(file, 'medicalSpecialty', `medicalSpecialty array contains non-enum value: ${value}`);
      }
    }
  }

  const shortArrays = /['"`]medicalSpecialty['"`]\s*=>\s*\[([^\]]*)\]/g;
  for (const match of content.matchAll(shortArrays)) {
    const values = [...match[1].matchAll(/['"`]([^'"`]+)['"`]/g)].map((item) => item[1]);
    for (const value of values) {
      if (!ALLOWED_MEDICAL_SPECIALTIES.has(value)) {
        addViolation(file, 'medicalSpecialty', `medicalSpecialty array contains non-enum value: ${value}`);
      }
    }
  }
}

console.log('Testing Schema Source Pattern Contract...\n');

const phpFiles = [
  'inc/nvx-structured-data.php',
  'inc/nvx-schema-foundation.php',
  'inc/nvx-schema-faq.php',
  'inc/nvx-schema-treatments.php',
  'inc/nvx-schema-physicians.php',
  'inc/nvx-schema-graph.php',
  'inc/nvx-aesthetic-treatment-schema.php',
  'inc/nvx-treatment-hub-schema.php',
  'inc/nvx-seo-production-readiness.php',
  'inc/nvx-contacto-valoracion-page.php',
];

for (const file of phpFiles) {
  const filePath = path.join(themePath, file);
  try {
    const content = await fs.readFile(filePath, 'utf8');

    const reviewedByMatches = content.match(/['"`]reviewedBy['"`]\s*=>/g) || [];
    if (reviewedByMatches.length > 0) {
      addViolation(file, 'reviewedBy', 'reviewedBy must be governed by WebPage review code, not procedure/service emitters', reviewedByMatches.length);
    }

    const performerMatches = content.match(/['"`]performer['"`]\s*=>/g) || [];
    if (performerMatches.length > 0) {
      addViolation(file, 'performer', 'performer is not allowed on MedicalProcedure/Service emitters', performerMatches.length);
    }

    validatePhpProcedureTypes(file, content);
    validatePhpMedicalSpecialties(file, content);

    const doctoraliaPatterns = [
      { pattern: /function\s+nvx_schema_enrich_organization[\s\S]{0,2000}?sameAs[\s\S]{0,500}?doctoralia\.es\/clinicas\//gi, context: 'Organization enrichment contains clinic Doctoralia URL' },
    ];
    for (const { pattern, context } of doctoraliaPatterns) {
      if (pattern.test(content)) {
        addViolation(file, 'sameAs', context);
      }
    }

    const recognizingAuthorityProperties = content.match(
      /(?:['"`]recognizingAuthority['"`]\s*=>|\[\s*['"`]recognizingAuthority['"`]\s*\]\s*=)/g,
    ) || [];
    if (recognizingAuthorityProperties.length > 0) {
      addViolation(file, 'recognizingAuthority', 'recognizingAuthority is forbidden in governed MedicalProcedure/Service source emitters regardless of value', recognizingAuthorityProperties.length);
    }

    const wrongPapadaMatches = content.match(/nvx_endolift_papada_price_eur/g) || [];
    if (wrongPapadaMatches.length > 0) {
      addViolation(file, 'function', 'Use nvx_endolift_price_papada_eur()', wrongPapadaMatches.length);
    }

    const hubSpecificIds = content.match(/\$?url\s*\.\s*['"`]#(?!(?:faq|organization|medical-clinic|physician|main|treatments-list|medical-procedure)['"`])[a-z-]+['"`]/g) || [];
    if (hubSpecificIds.length > 0) {
      addViolation(file, 'duplicateIdentity', 'Treatment entities must use canonical #medical-procedure IDs', hubSpecificIds.length);
    }

    if (file === 'inc/nvx-schema-graph.php') {
      const organizationBody = extractPhpFunctionBody(content, 'nvx_schema_enrich_organization');
      if (!organizationBody) {
        addViolation(file, 'organizationContract', 'Could not locate nvx_schema_enrich_organization() for source validation');
      } else {
        const priceRangeMatches = organizationBody.match(/['"`]priceRange['"`]/g) || [];
        if (priceRangeMatches.length > 0) {
          addViolation(file, 'priceRange', 'Corporate Organization enrichment must not emit priceRange', priceRangeMatches.length);
        }
      }
    }
  } catch (error) {
    if (error.code === 'ENOENT') {
      addViolation(file, 'missingSource', `Required PHP file not found: ${file}`);
    } else {
      throw error;
    }
  }
}

const jsonFiles = [
  'inc/data/aesthetic-treatment-pages.json',
  'inc/data/treatment-hub-schema.json',
];

for (const file of jsonFiles) {
  const filePath = path.join(themePath, file);
  try {
    const json = JSON.parse(await fs.readFile(filePath, 'utf8'));

    function searchInObject(obj, objectPath = '$') {
      if (!obj || typeof obj !== 'object') return;
      if (Array.isArray(obj)) {
        obj.forEach((value, index) => searchInObject(value, `${objectPath}[${index}]`));
        return;
      }
      for (const [key, value] of Object.entries(obj)) {
        const currentPath = `${objectPath}.${key}`;
        if (key === 'reviewedBy') addViolation(file, 'reviewedBy', `reviewedBy found in catalog at ${currentPath}`);
        if (key === 'performer') addViolation(file, 'performer', `performer found in catalog at ${currentPath}`);
        if (key === 'procedureType') {
          const values = Array.isArray(value) ? value : [value];
          for (const candidate of values) {
            const normalized = typeof candidate === 'string' ? candidate : candidate?.['@id'];
            if (!ALLOWED_PROCEDURE_TYPES.has(normalized)) {
              addViolation(file, 'procedureType', `Invalid procedureType at ${currentPath}: ${normalized || JSON.stringify(candidate)}`);
            }
          }
        }
        if (key === 'medicalSpecialty') {
          const values = Array.isArray(value) ? value : [value];
          for (const candidate of values) {
            const normalized = typeof candidate === 'string' ? candidate : candidate?.['@id'];
            if (!ALLOWED_MEDICAL_SPECIALTIES.has(normalized)) {
              addViolation(file, 'medicalSpecialty', `Invalid MedicalSpecialty enum at ${currentPath}: ${normalized || JSON.stringify(candidate)}`);
            }
          }
        }
        if (key === 'recognizingAuthority') {
          addViolation(file, 'recognizingAuthority', `recognizingAuthority is forbidden in governed schema catalog at ${currentPath}`);
        }
        if (file === 'inc/data/treatment-hub-schema.json' && key === 'additionalFields') {
          addViolation(file, 'hubArchitecture', `Treatment hub is reference-only; additionalFields is dead/duplicated metadata at ${currentPath}`);
        }
        if (value && typeof value === 'object') searchInObject(value, currentPath);
      }
    }

    searchInObject(json);
  } catch (error) {
    if (error.code === 'ENOENT') {
      addViolation(file, 'missingSource', `Required JSON catalog not found: ${file}`);
    } else {
      throw error;
    }
  }
}

const bootstrapPath = path.join(themePath, 'functions.php');
const bootstrapManifestPath = path.join(themePath, 'inc/nvx-theme-bootstrap.php');
const hubPath = path.join(themePath, 'inc/nvx-treatment-hub-schema.php');
const semanticGovernancePath = path.join(themePath, 'inc/nvx-schema-semantic-governance.php');
const [bootstrap, bootstrapManifest, hubSource, semanticGovernance] = await Promise.all([
  fs.readFile(bootstrapPath, 'utf8'),
  fs.readFile(bootstrapManifestPath, 'utf8'),
  fs.readFile(hubPath, 'utf8'),
  fs.readFile(semanticGovernancePath, 'utf8'),
]);

for (const [file, content] of [
  ['functions.php', bootstrap],
  ['inc/nvx-treatment-hub-schema.php', hubSource],
]) {
  const staleHubPredicate = content.match(/nvx_theme_is_treatments_hub\s*\(\s*\)/g) || [];
  if (staleHubPredicate.length > 0) {
    addViolation(file, 'hubPredicate', 'Use canonical nvx_theme_is_treatments_hub_page() predicate', staleHubPredicate.length);
  }
}

// With centralized bootstrap, verify semantic governance is in bootstrap manifest
if (!bootstrapManifest.includes("'inc/nvx-schema-semantic-governance.php'")) {
  addViolation('inc/nvx-theme-bootstrap.php', 'semanticGovernance', 'Final semantic governance module is not in bootstrap manifest');
}
// Verify it's not laterally loaded from functions.php
if (bootstrap.includes("require_once get_template_directory() . '/inc/nvx-schema-semantic-governance.php';")) {
  addViolation('functions.php', 'semanticGovernance', 'Semantic governance must be loaded from bootstrap manifest, not laterally');
}
if (!/add_filter\(\s*['"]wpseo_schema_graph['"]\s*,\s*['"]nvx_schema_semantic_normalize_graph['"]\s*,\s*PHP_INT_MAX\s*-\s*2\s*,\s*1\s*\)/.test(semanticGovernance)) {
  addViolation('inc/nvx-schema-semantic-governance.php', 'semanticGovernance', 'Final graph normalizer must run at PHP_INT_MAX - 2');
}
if (!/array_key_exists\(\s*'recognizingAuthority'\s*,\s*\$node\s*\)[\s\S]{0,300}array_intersect\([\s\S]{0,120}'MedicalProcedure'[\s\S]{0,120}'Service'/.test(semanticGovernance)) {
  addViolation('inc/nvx-schema-semantic-governance.php', 'recognizingAuthority', 'Governance must remove recognizingAuthority by property for MedicalProcedure and Service regardless of authority value');
}

const semanticPhpTest = spawnSync(
  'php',
  [path.join(repoPath, 'scripts/lint/test-schema-semantic-governance.php')],
  {
    encoding: 'utf8',
    timeout: 30_000,
  }
);

if (semanticPhpTest.error) {
  let message;
  if (semanticPhpTest.error.code === 'ENOENT') {
    message = 'PHP executable not found on PATH; semantic governance contract cannot be enforced.';
  } else {
    message = `Error invoking PHP semantic governance test: ${semanticPhpTest.error.message}`;
  }
  addViolation(
    'scripts/lint/test-schema-semantic-governance.php',
    'semanticGovernanceRuntime',
    message
  );
} else if (semanticPhpTest.status === null) {
  const signalInfo = semanticPhpTest.signal ? ` (terminated with signal ${semanticPhpTest.signal})` : '';
  addViolation(
    'scripts/lint/test-schema-semantic-governance.php',
    'semanticGovernanceRuntime',
    `PHP semantic governance test timed out after 30 seconds${signalInfo}`
  );
} else {
  const stdout = semanticPhpTest.stdout || '';
  const stderr = semanticPhpTest.stderr || '';
  const combined = `${stdout}\n${stderr}`.trim();

  if (semanticPhpTest.status !== 0) {
    addViolation(
      'scripts/lint/test-schema-semantic-governance.php',
      'semanticGovernanceRuntime',
      combined || `PHP test exited ${semanticPhpTest.status}`
    );
  } else {
    if (stdout) {
      process.stdout.write(stdout);
    }
    if (stderr.trim()) {
      addViolation(
        'scripts/lint/test-schema-semantic-governance.php',
        'semanticGovernanceRuntimeStderr',
        stderr.trim()
      );
    }
  }
}

if (violations.length > 0) {
  console.error('SCHEMA_SOURCE_PATTERN_CONTRACT=FAIL');
  for (const [index, violation] of violations.entries()) {
    console.error(`${index + 1}. ${violation.file} [${violation.type}] ${violation.context} count=${violation.count}`);
  }
  process.exit(1);
}

console.log('SCHEMA_SOURCE_PATTERN_CONTRACT=PASS');
console.log('✓ procedureType PHP/JSON values are whitelist-validated');
console.log('✓ medicalSpecialty literal values use Schema.org MedicalSpecialty enums');
console.log('✓ corporate Organization source has no priceRange or clinic Doctoralia sameAs');
console.log('✓ procedure/service emitters have no reviewedBy or performer');
console.log('✓ treatment source IDs use canonical #medical-procedure');
console.log('✓ treatment hub uses the canonical runtime predicate');
console.log('✓ final semantic normalizer is loaded, ordered and unit-tested');
console.log('✓ treatment hub has no dead additionalFields or recognizingAuthority in governed sources');

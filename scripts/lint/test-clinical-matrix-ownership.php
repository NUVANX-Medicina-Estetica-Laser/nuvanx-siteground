<?php
/**
 * Linter: Verify Clinical Matrix completeness, evidence provenance and governance.
 */

$file = __DIR__ . '/../../wp-content/themes/nuvanx-medical/inc/data/clinical-matrix.json';
if ( ! file_exists( $file ) ) {
    echo "FAIL: clinical-matrix.json not found.\n";
    exit( 1 );
}

$raw = (string) file_get_contents( $file );
$data = json_decode( $raw, true );
if ( JSON_ERROR_NONE !== json_last_error() ) {
    echo "FAIL: clinical-matrix.json is invalid JSON.\n";
    exit( 1 );
}

$treatments = $data['treatments'] ?? array();
if ( empty( $treatments ) ) {
    echo "FAIL: No treatments defined in clinical-matrix.json.\n";
    exit( 1 );
}

$required_fields = array(
    'name', 'indications', 'contraindications', 'mechanism',
    'applicators', 'published_parameters', 'anesthesia',
    'duration', 'recovery', 'sessions',
    'medical_responsible', 'scientific_review_date'
);

$errors = 0;
foreach ( $treatments as $id => $t ) {
    foreach ( $required_fields as $field ) {
        if ( ! array_key_exists( $field, $t ) ) {
            echo "ERROR: Treatment '{$id}' is missing required field '{$field}'.\n";
            $errors++;
        }
    }
}

$evidence_contract = array(
    'endolift_facial' => array( '38886198', '39827299', '35083532' ),
    'laser_co2'       => array( '22766970', '42334669' ),
    'exion_face'      => array( '40243133' ),
);
$evidence_fields = array( 'study_type', 'sample_size', 'title', 'summary', 'limitation', 'source_label', 'source_url', 'pmid' );

foreach ( $evidence_contract as $treatment_id => $required_pmids ) {
    $rows = $treatments[ $treatment_id ]['evidence'] ?? null;
    if ( ! is_array( $rows ) || count( $rows ) !== count( $required_pmids ) ) {
        echo "ERROR: Treatment '{$treatment_id}' does not expose the required governed evidence set.\n";
        $errors++;
        continue;
    }

    $found_pmids = array();
    foreach ( $rows as $row ) {
        if ( ! is_array( $row ) ) {
            echo "ERROR: Treatment '{$treatment_id}' contains a non-object evidence row.\n";
            $errors++;
            continue;
        }

        foreach ( $evidence_fields as $field ) {
            if ( ! isset( $row[ $field ] ) || ! is_string( $row[ $field ] ) || '' === trim( $row[ $field ] ) ) {
                echo "ERROR: Evidence '{$treatment_id}' is missing non-empty '{$field}'.\n";
                $errors++;
            }
        }

        $pmid       = trim( (string) ( $row['pmid'] ?? '' ) );
        $source_url = trim( (string) ( $row['source_url'] ?? '' ) );
        $source     = trim( (string) ( $row['source_label'] ?? '' ) );
        if ( '' !== $pmid ) {
            $found_pmids[] = $pmid;
        }
        if ( ! preg_match( '#^https://pubmed\.ncbi\.nlm\.nih\.gov/[0-9]+/$#', $source_url ) ) {
            echo "ERROR: Evidence '{$treatment_id}' source must be a canonical PubMed URL.\n";
            $errors++;
        }
        if ( '' !== $pmid && ( false === strpos( $source_url, '/' . $pmid . '/' ) || false === strpos( $source, $pmid ) ) ) {
            echo "ERROR: Evidence '{$treatment_id}' PMID does not match its URL/label: {$pmid}.\n";
            $errors++;
        }
    }

    foreach ( $required_pmids as $pmid ) {
        if ( ! in_array( $pmid, $found_pmids, true ) ) {
            echo "ERROR: Required PMID {$pmid} is missing for '{$treatment_id}'.\n";
            $errors++;
        }
    }
}

// Fast SSOT-local guard. The Node contract below performs the broader theme-wide scan.
$forbidden_patterns = array(
    '/Endolift[^\n]{0,120}20\s*[%–-]\s*40\s*%/iu',
    '/EXION[^\n]{0,120}37\s*%[^\n]{0,80}col[aá]geno/iu',
    '/94\s*%[^\n]{0,100}n\s*=\s*47/iu',
);
foreach ( $forbidden_patterns as $pattern ) {
    if ( preg_match( $pattern, $raw ) ) {
        echo "ERROR: Unsupported/unqualified clinical SSOT claim detected: {$pattern}.\n";
        $errors++;
    }
}

$exion_evidence = $treatments['exion_face']['evidence'][0] ?? array();
if ( 'n=7 total; RF+TUS n=3' !== ( $exion_evidence['sample_size'] ?? '' ) ) {
    echo "ERROR: EXION evidence must expose the total and RF+TUS subgroup sizes.\n";
    $errors++;
}
if ( ! preg_match( '/endpoint histol[oó]gico/iu', (string) ( $exion_evidence['limitation'] ?? '' ) ) ) {
    echo "ERROR: EXION evidence must identify the mechanistic histology endpoint.\n";
    $errors++;
}
if ( false === stripos( (string) ( $exion_evidence['limitation'] ?? '' ), 'BTL Industries' ) ) {
    echo "ERROR: EXION evidence must disclose BTL Industries funding.\n";
    $errors++;
}

$endolift_critical = null;
$endolift_small    = null;
foreach ( (array) ( $treatments['endolift_facial']['evidence'] ?? array() ) as $row ) {
    if ( '39827299' === ( $row['pmid'] ?? '' ) ) {
        $endolift_critical = $row;
    }
    if ( '35083532' === ( $row['pmid'] ?? '' ) ) {
        $endolift_small = $row;
    }
}
if ( ! is_array( $endolift_critical ) || false === stripos( (string) ( $endolift_critical['summary'] ?? '' ), 'alto riesgo de sesgo' ) ) {
    echo "ERROR: Endolift 2025 systematic review must retain the high-risk-of-bias finding.\n";
    $errors++;
}
if ( ! is_array( $endolift_critical ) || false === stripos( (string) ( $endolift_critical['summary'] ?? '' ), 'falta de estandarización' ) ) {
    echo "ERROR: Endolift 2025 systematic review must retain the parameter-standardization limitation.\n";
    $errors++;
}
if ( ! is_array( $endolift_critical ) || false === stripos( (string) ( $endolift_critical['limitation'] ?? '' ), 'no demuestra por sí sola ausencia de efecto' ) ) {
    echo "ERROR: Endolift 2025 evidence must remain balanced rather than being converted into a no-effect claim.\n";
    $errors++;
}
if ( ! is_array( $endolift_small ) || false === stripos( (string) ( $endolift_small['limitation'] ?? '' ), 'Muestra muy pequeña' ) ) {
    echo "ERROR: Endolift n=9 evidence must carry the small-sample limitation.\n";
    $errors++;
}

$co2_rct  = null;
$co2_meta = null;
foreach ( (array) ( $treatments['laser_co2']['evidence'] ?? array() ) as $row ) {
    if ( '22766970' === ( $row['pmid'] ?? '' ) ) {
        $co2_rct = $row;
    }
    if ( '42334669' === ( $row['pmid'] ?? '' ) ) {
        $co2_meta = $row;
    }
}
$co2_summary = is_array( $co2_rct ) ? (string) ( $co2_rct['summary'] ?? '' ) : '';
if ( false === strpos( $co2_summary, '6,15 a 3,89' ) || false === strpos( $co2_summary, '5,72 a 3,56' ) ) {
    echo "ERROR: CO2 RCT must retain its exact published endpoint values.\n";
    $errors++;
}
$co2_meta_summary = is_array( $co2_meta ) ? (string) ( $co2_meta['summary'] ?? '' ) : '';
$co2_meta_limit   = is_array( $co2_meta ) ? (string) ( $co2_meta['limitation'] ?? '' ) : '';
if ( false === strpos( $co2_meta_summary, 'RR 1,10' ) ) {
    echo "ERROR: CO2 meta-analysis must retain categorical treatment-success evidence.\n";
    $errors++;
}
if ( false === strpos( $co2_meta_summary, 'RR 3,04' ) ) {
    echo "ERROR: CO2 meta-analysis must retain the PIH risk comparison.\n";
    $errors++;
}
if ( false === stripos( $co2_meta_summary, 'frente a RF microneedling, el dolor fue menor con CO₂' ) ) {
    echo "ERROR: CO2 meta-analysis must retain the lower-pain comparison against RF microneedling.\n";
    $errors++;
}
if ( false === strpos( $co2_meta_limit, 'I² 97% y 92%' ) ) {
    echo "ERROR: CO2 meta-analysis must retain the high-heterogeneity limitation.\n";
    $errors++;
}

// This PHP linter is part of the canonical required workflow, so invoking the
// focused Node contract here makes its theme-wide public-copy scan blocking CI.
$node_contract = __DIR__ . '/test-clinical-evidence-contract.mjs';
if ( ! is_file( $node_contract ) ) {
    echo "ERROR: Clinical evidence Node contract is missing.\n";
    $errors++;
} else {
    $command = 'node ' . escapeshellarg( $node_contract );
    passthru( $command, $node_status );
    if ( 0 !== $node_status ) {
        echo "ERROR: Theme-wide clinical evidence contract failed with exit {$node_status}.\n";
        $errors++;
    }
}

if ( $errors > 0 ) {
    echo "\nFAIL: $errors E-E-A-T clinical governance violations found.\n";
    exit( 1 );
}

echo "OK: Clinical Matrix validation passed with source-traceable balanced evidence contract.\n";
exit( 0 );

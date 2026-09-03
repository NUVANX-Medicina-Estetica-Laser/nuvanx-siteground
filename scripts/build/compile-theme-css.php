<?php

declare(strict_types=1);

/**
 * Deterministic CSS compiler and manifest generator for the NUVANX theme.
 *
 * Repository build tooling only. The theme owns CSS sources and immutable dist
 * artifacts; this compiler is not part of the WordPress runtime PHP surface.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CSS_COMPILATION=FAIL reason=cli_only\n");
    exit(1);
}

$rootDir = dirname(__DIR__, 2);
$themeDir = $rootDir . '/wp-content/themes/nuvanx-medical';
$cssSrcDir = $themeDir . '/assets/css';
$distDir = $themeDir . '/dist';

$bundleDefinitions = [
    'core' => [
        'assets/css/nvx-fonts.css',
        'assets/css/nvx-tokens.css',
        'assets/css/nvx-base.css',
        'assets/css/nvx-site-layout.css',
        'assets/css/nvx-components.css',
        'assets/css/nvx-patterns-editorial.css',
        'assets/css/nvx-treatment-authority.css',
        'assets/css/nvx-header.css',
        'assets/css/nvx-footer.css',
        'assets/css/nvx-accessibility-governance.css',
    ],
];

function nvx_css_normalize(string $raw): string
{
    return trim(str_replace(["\r\n", "\r"], "\n", $raw));
}

function nvx_css_read_required(string $path): string
{
    if (!is_readable($path)) {
        throw new RuntimeException('missing_or_unreadable_source:' . $path);
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('source_read_failed:' . $path);
    }

    return nvx_css_normalize($contents);
}

function nvx_css_hash(string $content): string
{
    return substr(hash('sha256', $content), 0, 10);
}

try {
    if (!is_dir($cssSrcDir)) {
        throw new RuntimeException('css_source_directory_missing');
    }

    if (!is_dir($distDir) && !mkdir($distDir, 0775, true) && !is_dir($distDir)) {
        throw new RuntimeException('dist_directory_create_failed');
    }

    $existing = scandir($distDir);
    if ($existing === false) {
        throw new RuntimeException('dist_directory_scan_failed');
    }

    foreach ($existing as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        if (str_ends_with($file, '.css') || $file === 'manifest.json') {
            $target = $distDir . '/' . $file;
            if (is_file($target) && !unlink($target)) {
                throw new RuntimeException('stale_dist_cleanup_failed:' . $file);
            }
        }
    }

    $sourceDateEpochRaw = getenv('SOURCE_DATE_EPOCH');
    $sourceDateEpoch = ($sourceDateEpochRaw === false || $sourceDateEpochRaw === '') ? 0 : $sourceDateEpochRaw;
    if (!is_numeric($sourceDateEpoch) || (int) $sourceDateEpoch < 0) {
        throw new RuntimeException('invalid_source_date_epoch');
    }
    $sourceDateEpoch = (int) $sourceDateEpoch;

    $manifest = [
        'schema' => 1,
        'generated' => gmdate('Y-m-d\\TH:i:s.000\\Z', $sourceDateEpoch),
        'bundles' => [],
        'files' => [],
    ];

    $bundledSources = [];
    foreach ($bundleDefinitions as $sourceList) {
        foreach ($sourceList as $source) {
            $bundledSources[$source] = true;
        }
    }

    $sourceEntries = scandir($cssSrcDir);
    if ($sourceEntries === false) {
        throw new RuntimeException('css_source_scan_failed');
    }
    sort($sourceEntries, SORT_STRING);

    foreach ($sourceEntries as $srcFile) {
        if (!str_ends_with($srcFile, '.css')) {
            continue;
        }

        $relativeSource = 'assets/css/' . $srcFile;
        if (isset($bundledSources[$relativeSource])) {
            continue;
        }

        $content = nvx_css_read_required($cssSrcDir . '/' . $srcFile);
        $hash = nvx_css_hash($content);
        $baseName = substr($srcFile, 0, -4);
        $distFileName = $baseName . '.' . $hash . '.css';

        if (file_put_contents($distDir . '/' . $distFileName, $content . "\n") === false) {
            throw new RuntimeException('route_dist_write_failed:' . $srcFile);
        }

        $manifest['files'][$relativeSource] = [
            'file' => $distFileName,
            'hash' => $hash,
            'size' => strlen($content),
        ];
    }

    foreach ($bundleDefinitions as $bundleName => $sourceList) {
        if (count($sourceList) < 2) {
            throw new RuntimeException('single_source_bundle_forbidden:' . $bundleName);
        }

        $parts = [];
        foreach ($sourceList as $relativeSource) {
            $content = nvx_css_read_required($themeDir . '/' . $relativeSource);
            $parts[] = '/* ' . basename($relativeSource) . " */\n" . $content;
        }

        $bundleContent = implode("\n\n", $parts);
        $hash = nvx_css_hash($bundleContent);
        $distFileName = 'nvx-' . $bundleName . '.' . $hash . '.css';

        if (file_put_contents($distDir . '/' . $distFileName, $bundleContent . "\n") === false) {
            throw new RuntimeException('bundle_dist_write_failed:' . $bundleName);
        }

        $manifest['bundles'][$bundleName] = [
            'file' => $distFileName,
            'hash' => $hash,
            'size' => strlen($bundleContent),
            'sources' => $sourceList,
        ];
    }

    $manifestJson = json_encode(
        $manifest,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );

    if (file_put_contents($distDir . '/manifest.json', $manifestJson . "\n") === false) {
        throw new RuntimeException('manifest_write_failed');
    }

    // Bootstrap-only diagnostic. Removed before merge once the initial tracked
    // dist graph is materialized in the branch.
    if (getenv('NVX_CSS_EMIT_BASE64') === '1') {
        $artifacts = scandir($distDir);
        if ($artifacts === false) {
            throw new RuntimeException('dist_artifact_scan_failed');
        }
        sort($artifacts, SORT_STRING);
        foreach ($artifacts as $artifact) {
            if ($artifact === '.' || $artifact === '..') {
                continue;
            }
            if (!str_ends_with($artifact, '.css') && $artifact !== 'manifest.json') {
                continue;
            }
            $artifactContents = file_get_contents($distDir . '/' . $artifact);
            if ($artifactContents === false) {
                throw new RuntimeException('dist_artifact_read_failed:' . $artifact);
            }
            echo 'CSS_ARTIFACT_BASE64 ' . $artifact . ' ' . base64_encode($artifactContents) . "\n";
        }
    }

    printf(
        "CSS_COMPILATION=PASS bundles=%d route_files=%d dist=wp-content/themes/nuvanx-medical/dist\n",
        count($manifest['bundles']),
        count($manifest['files'])
    );
} catch (Throwable $error) {
    fwrite(STDERR, 'CSS_COMPILATION=FAIL reason=' . $error->getMessage() . "\n");
    exit(1);
}

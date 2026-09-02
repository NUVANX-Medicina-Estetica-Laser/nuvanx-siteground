<?php
/**
 * Detect duplicate top-level function declarations across the public theme.
 *
 * Per-file `php -l` cannot detect collisions between modules. This contract
 * tokenizes the complete theme graph and fails when the same global function
 * name is declared more than once outside classes/interfaces/traits/enums.
 */

declare(strict_types=1);

$root      = dirname(__DIR__, 2);
$themeRoot = $root . '/wp-content/themes/nuvanx-medical';

if (!is_dir($themeRoot)) {
    fwrite(STDERR, "THEME_FUNCTION_COLLISIONS=FAIL reason=theme_missing\n");
    exit(1);
}

$files = array();
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($themeRoot, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile()) {
        continue;
    }
    $path = $file->getPathname();
    if (substr($path, -4) !== '.php' || str_contains($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
        continue;
    }
    $files[] = $path;
}
sort($files, SORT_STRING);

/** @var array<string,list<array{file:string,line:int}>> $definitions */
$definitions = array();

foreach ($files as $path) {
    $source = file_get_contents($path);
    if (!is_string($source)) {
        fwrite(STDERR, 'THEME_FUNCTION_COLLISIONS=FAIL reason=unreadable file=' . $path . PHP_EOL);
        exit(1);
    }

    $tokens = token_get_all($source);
    $braceDepth = 0;
    $classDepths = array();
    $pendingClass = false;
    $previousMeaningful = null;
    $tokenCount = count($tokens);

    for ($i = 0; $i < $tokenCount; $i++) {
        $token = $tokens[$i];

        if (is_string($token)) {
            if ('{' === $token) {
                $braceDepth++;
                if ($pendingClass) {
                    $classDepths[] = $braceDepth;
                    $pendingClass = false;
                }
            } elseif ('}' === $token) {
                if (!empty($classDepths) && end($classDepths) === $braceDepth) {
                    array_pop($classDepths);
                }
                $braceDepth = max(0, $braceDepth - 1);
            }

            if (!ctype_space($token)) {
                $previousMeaningful = $token;
            }
            continue;
        }

        [$id, $text, $line] = $token;

        if (in_array($id, array(T_CLASS, T_INTERFACE, T_TRAIT), true)
            || (defined('T_ENUM') && T_ENUM === $id)) {
            if (T_DOUBLE_COLON !== $previousMeaningful) {
                $pendingClass = true;
            }
            $previousMeaningful = $id;
            continue;
        }

        if (T_FUNCTION === $id && empty($classDepths)) {
            $name = null;
            for ($j = $i + 1; $j < $tokenCount; $j++) {
                $next = $tokens[$j];
                if (is_array($next) && in_array($next[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
                    continue;
                }
                if ('&' === $next) {
                    continue;
                }
                if (is_array($next) && T_STRING === $next[0]) {
                    $name = strtolower($next[1]);
                }
                break;
            }

            if (null !== $name) {
                $relative = ltrim(str_replace($root, '', $path), DIRECTORY_SEPARATOR);
                $definitions[$name][] = array('file' => $relative, 'line' => $line);
            }
        }

        if (!in_array($id, array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
            $previousMeaningful = $id;
        }
    }
}

$collisions = array_filter(
    $definitions,
    static fn(array $owners): bool => count($owners) > 1
);

if (!empty($collisions)) {
    ksort($collisions, SORT_STRING);
    fwrite(STDERR, 'THEME_FUNCTION_COLLISIONS=FAIL collisions=' . count($collisions) . PHP_EOL);
    foreach ($collisions as $name => $owners) {
        $locations = array_map(
            static fn(array $owner): string => $owner['file'] . ':' . $owner['line'],
            $owners
        );
        fwrite(STDERR, ' - ' . $name . ' => ' . implode(', ', $locations) . PHP_EOL);
    }
    exit(1);
}

echo 'THEME_FUNCTION_COLLISIONS=PASS files=' . count($files) . ' functions=' . count($definitions) . PHP_EOL;

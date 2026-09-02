<?php
/**
 * Enforce the canonical the_content hook-priority registry.
 *
 * Every add_filter( 'the_content', ... ) call in the production theme must use
 * an NVX_HOOK_PRIO_* constant declared in inc/nvx-constants.php. This keeps the
 * render graph explicit and prevents silent ordering drift from magic numbers,
 * PHP_INT_MAX expressions or ad-hoc variables.
 */

declare(strict_types=1);

$root          = dirname(__DIR__, 2);
$theme_root    = $root . '/wp-content/themes/nuvanx-medical';
$constants_file = $theme_root . '/inc/nvx-constants.php';

if (!is_dir($theme_root) || !is_readable($constants_file)) {
    fwrite(STDERR, "Hook-priority registry prerequisites are missing.\n");
    exit(1);
}

$constants_source = file_get_contents($constants_file);
if (false === $constants_source) {
    fwrite(STDERR, "Unable to read {$constants_file}.\n");
    exit(1);
}

preg_match_all('/\bconst\s+(NVX_HOOK_PRIO_[A-Z0-9_]+)\s*=/', $constants_source, $constant_matches);
$registered = array_fill_keys($constant_matches[1] ?? array(), true);

if (array() === $registered) {
    fwrite(STDERR, "No NVX_HOOK_PRIO_* constants found in canonical registry.\n");
    exit(1);
}

/**
 * Convert a token slice back to source text.
 *
 * @param array<int, array{0:int,1:string,2:int}|string> $tokens Token slice.
 */
function nvx_lint_tokens_to_text(array $tokens): string
{
    $text = '';
    foreach ($tokens as $token) {
        $text .= is_array($token) ? $token[1] : $token;
    }

    return trim($text);
}

/**
 * Parse top-level function arguments starting at an opening parenthesis.
 *
 * @param array<int, array{0:int,1:string,2:int}|string> $tokens       Full token stream.
 * @param int                                            $open_index   Opening-parenthesis index.
 * @return array<int, string>|null Parsed arguments, or null for malformed source.
 */
function nvx_lint_parse_call_arguments(array $tokens, int $open_index): ?array
{
    $arguments   = array();
    $current     = array();
    $paren_depth = 1;
    $bracket_depth = 0;
    $brace_depth = 0;
    $count       = count($tokens);

    for ($index = $open_index + 1; $index < $count; $index++) {
        $token = $tokens[$index];
        $text  = is_array($token) ? $token[1] : $token;

        if ('(' === $text) {
            $paren_depth++;
            $current[] = $token;
            continue;
        }

        if (')' === $text) {
            $paren_depth--;
            if (0 === $paren_depth) {
                $arguments[] = nvx_lint_tokens_to_text($current);
                return $arguments;
            }
            $current[] = $token;
            continue;
        }

        if ('[' === $text) {
            $bracket_depth++;
            $current[] = $token;
            continue;
        }

        if (']' === $text) {
            $bracket_depth--;
            $current[] = $token;
            continue;
        }

        if ('{' === $text) {
            $brace_depth++;
            $current[] = $token;
            continue;
        }

        if ('}' === $text) {
            $brace_depth--;
            $current[] = $token;
            continue;
        }

        if (',' === $text && 1 === $paren_depth && 0 === $bracket_depth && 0 === $brace_depth) {
            $arguments[] = nvx_lint_tokens_to_text($current);
            $current     = array();
            continue;
        }

        $current[] = $token;
    }

    return null;
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($theme_root, FilesystemIterator::SKIP_DOTS)
);

$violations = array();
$checked     = 0;

/** @var SplFileInfo $file */
foreach ($iterator as $file) {
    if (!$file->isFile() || 'php' !== strtolower($file->getExtension())) {
        continue;
    }

    $path = $file->getPathname();
    if (str_contains($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
        continue;
    }

    $source = file_get_contents($path);
    if (false === $source) {
        $violations[] = str_replace($root . '/', '', $path) . ': unreadable PHP source';
        continue;
    }

    $tokens = token_get_all($source);
    $total  = count($tokens);

    for ($index = 0; $index < $total; $index++) {
        $token = $tokens[$index];
        if (!is_array($token) || T_STRING !== $token[0] || 'add_filter' !== strtolower($token[1])) {
            continue;
        }

        $line = $token[2];
        $next = $index + 1;
        while ($next < $total && is_array($tokens[$next]) && in_array($tokens[$next][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
            $next++;
        }

        if ($next >= $total || '(' !== $tokens[$next]) {
            continue;
        }

        $arguments = nvx_lint_parse_call_arguments($tokens, $next);
        $relative  = str_replace($root . '/', '', $path);

        if (null === $arguments) {
            $violations[] = sprintf('%s:%d has unparseable add_filter call', $relative, $line);
            continue;
        }

        if (count($arguments) < 1) {
            continue;
        }

        $hook = trim($arguments[0]);
        if ("'the_content'" !== $hook && '"the_content"' !== $hook) {
            continue;
        }

        if (count($arguments) < 3) {
            $violations[] = sprintf('%s:%d omits explicit NVX_HOOK_PRIO_* priority argument', $relative, $line);
            continue;
        }

        $checked++;
        $priority = trim($arguments[2]);

        if (1 !== preg_match('/^NVX_HOOK_PRIO_[A-Z0-9_]+$/', $priority)) {
            $violations[] = sprintf('%s:%d uses non-canonical the_content priority `%s`', $relative, $line, $priority);
            continue;
        }

        if (!isset($registered[$priority])) {
            $violations[] = sprintf('%s:%d references unregistered priority `%s`', $relative, $line, $priority);
        }
    }
}

if (array() !== $violations) {
    fwrite(STDERR, "THE_CONTENT_HOOK_PRIORITY_REGISTRY=FAIL\n");
    foreach ($violations as $violation) {
        fwrite(STDERR, " - {$violation}\n");
    }
    exit(1);
}

if (0 === $checked) {
    fwrite(STDERR, "THE_CONTENT_HOOK_PRIORITY_REGISTRY=FAIL no the_content filters inspected\n");
    exit(1);
}

printf("THE_CONTENT_HOOK_PRIORITY_REGISTRY=PASS filters=%d constants=%d\n", $checked, count($registered));

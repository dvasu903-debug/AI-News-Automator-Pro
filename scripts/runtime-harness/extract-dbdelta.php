<?php

declare(strict_types=1);

/**
 * Extracts dbDelta() and wp_should_upgrade_global_tables() out of a
 * downloaded wp-admin/includes/upgrade.php, verbatim, using PHP's own
 * tokenizer to find function boundaries — robust across WordPress
 * versions, unlike a fixed line-range slice (which is what the first,
 * ad-hoc version of this harness used and would silently break the
 * moment WP_CORE_VERSION changes).
 *
 * Usage: php extract-dbdelta.php <path-to-upgrade.php> <output-path>
 */

[, $source, $output] = $argv;

$code = file_get_contents($source);
if ($code === false) {
    fwrite(STDERR, "Could not read $source\n");
    exit(1);
}

$tokens = token_get_all($code);
$wanted = ['dbdelta', 'wp_should_upgrade_global_tables'];
$extracted = [];

$count = count($tokens);
for ($i = 0; $i < $count; $i++) {
    if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
        continue;
    }

    // Find the function name (next T_STRING token).
    $j = $i + 1;
    while ($j < $count && (!is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING)) {
        $j++;
    }
    if ($j >= $count) {
        continue;
    }
    $name = strtolower($tokens[$j][1]);
    if (!in_array($name, $wanted, true)) {
        continue;
    }

    // Walk forward to the opening brace of the function body, then match braces.
    $k = $j;
    $depth = 0;
    $started = false;
    $startTokenIndex = $i;
    for (; $k < $count; $k++) {
        $t = $tokens[$k];
        $text = is_array($t) ? $t[1] : $t;
        if ($text === '{') {
            $depth++;
            $started = true;
        } elseif ($text === '}') {
            $depth--;
            if ($started && $depth === 0) {
                break;
            }
        }
    }
    if (!$started) {
        fwrite(STDERR, "Could not find body for function $name\n");
        exit(1);
    }

    $snippet = '';
    for ($m = $startTokenIndex; $m <= $k; $m++) {
        $snippet .= is_array($tokens[$m]) ? $tokens[$m][1] : $tokens[$m];
    }
    $extracted[$name] = $snippet;
}

foreach ($wanted as $name) {
    if (!isset($extracted[$name])) {
        fwrite(STDERR, "Function $name not found in $source — WP core structure may have changed.\n");
        exit(1);
    }
}

$out = "<?php\n// Extracted verbatim from WordPress core's wp-admin/includes/upgrade.php\n"
    . "// by scripts/runtime-harness/extract-dbdelta.php. Not vendored source —\n"
    . "// regenerated fresh from the pinned WP_CORE_VERSION on every verification run.\n\n"
    . implode("\n\n", $extracted) . "\n";

file_put_contents($output, $out);
echo "Extracted " . implode(', ', array_keys($extracted)) . " to $output\n";

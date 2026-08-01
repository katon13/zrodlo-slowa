<?php
declare(strict_types=1);

/**
 * Zamienia podwójnie cytowane wartości w stałych tekstowych SQL na standardowe
 * pojedyncze literały, zachowując poprawne escapowanie ciągów PHP.
 *
 * Domyślnie wykonuje wyłącznie analizę. Zapis wymaga parametru --apply.
 */

$root = dirname(__DIR__, 2);
$apply = in_array('--apply', $argv, true);
$directories = [$root . '/app', $root . '/scripts', $root . '/tests'];
$files = [];
foreach ($directories as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            $path = $file->getPathname();
            if ($path !== __FILE__) {
                $files[] = $path;
            }
        }
    }
}
sort($files, SORT_STRING);

$changedFiles = [];
$changedLiterals = 0;
foreach ($files as $file) {
    $source = file_get_contents($file);
    if (!is_string($source)) {
        throw new RuntimeException("Nie udało się odczytać {$file}.");
    }

    $result = '';
    $fileChanges = 0;
    foreach (token_get_all($source, TOKEN_PARSE) as $token) {
        if (!is_array($token)) {
            $result .= $token;
            continue;
        }

        [$type, $text] = $token;
        if (
            $type === T_CONSTANT_ENCAPSED_STRING
            && str_starts_with($text, "'")
            && str_contains($text, '"')
            && looksLikeSql($text)
        ) {
            $converted = preg_replace_callback(
                '/"([^"\\\\]*(?:\\\\.[^"\\\\]*)*)"/',
                static fn(array $match): string => "\\'" . $match[1] . "\\'",
                $text
            );
            if (is_string($converted) && $converted !== $text) {
                $text = $converted;
                $fileChanges++;
            }
        }
        $result .= $text;
    }

    if ($fileChanges === 0) {
        continue;
    }
    $relative = str_replace('\\', '/', substr($file, strlen($root) + 1));
    $changedFiles[$relative] = $fileChanges;
    $changedLiterals += $fileChanges;
    if ($apply && file_put_contents($file, $result) === false) {
        throw new RuntimeException("Nie udało się zapisać {$file}.");
    }
}

foreach ($changedFiles as $file => $count) {
    fwrite(STDOUT, sprintf("%3d  %s\n", $count, $file));
}
fwrite(
    STDOUT,
    sprintf(
        "%s: %d literałów w %d plikach.\n",
        $apply ? 'Zmieniono' : 'Do zmiany',
        $changedLiterals,
        count($changedFiles)
    )
);

function looksLikeSql(string $literal): bool
{
    return preg_match(
        '/\b(?:SELECT|INSERT\s+INTO|INSERT\s+IGNORE|UPDATE|DELETE\s+FROM|CREATE\s+TABLE|'
        . 'ALTER\s+TABLE|WHERE|VALUES|JOIN|HAVING|CASE\s+WHEN|SET)\b/i',
        $literal
    ) === 1;
}

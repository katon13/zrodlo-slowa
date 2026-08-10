<?php
declare(strict_types=1);

use App\Services\AuditArtifactSanitizer;
require_once dirname(__DIR__) . '/vendor/autoload.php';

if ($argc !== 3) {
    fwrite(STDERR, "Użycie: php scripts/sanitize_audit_artifacts.php KATALOG_WEJSCIOWY KATALOG_WYJSCIOWY\n");
    exit(2);
}

$source = realpath((string)$argv[1]);
$destination = rtrim((string)$argv[2], '/\\');
if ($source === false || !is_dir($source)) {
    fwrite(STDERR, "Katalog wejściowy nie istnieje.\n");
    exit(2);
}
if (file_exists($destination)) {
    fwrite(STDERR, "Katalog wyjściowy już istnieje; narzędzie nie nadpisuje artefaktów.\n");
    exit(2);
}

$blockedNames = ['local.properties'];
$blockedExtensions = [
    'jks', 'keystore', 'p12', 'pfx', 'pem', 'key', 'sqlite', 'sqlite3', 'dump',
    'zip', '7z', 'rar', 'tar', 'gz', 'tgz',
];
$textExtensions = ['txt', 'log', 'out', 'json', 'xml', 'html', 'htm', 'md', 'csv', 'tsv', 'yml', 'yaml'];
$sanitizer = new AuditArtifactSanitizer();
$processed = 0;
$copied = 0;
$skipped = 0;

if (!mkdir($destination, 0700, true) && !is_dir($destination)) {
    throw new RuntimeException('Nie udało się utworzyć katalogu wyjściowego.');
}

$iterator = new \RecursiveIteratorIterator(
    new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
    \RecursiveIteratorIterator::SELF_FIRST,
);
foreach ($iterator as $item) {
    if ($item->isLink()) {
        ++$skipped;
        continue;
    }
    $relative = ltrim(str_replace('\\', '/', substr($item->getPathname(), strlen($source))), '/');
    $target = $destination . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if ($item->isDir()) {
        if (!is_dir($target) && !mkdir($target, 0700, true) && !is_dir($target)) {
            throw new RuntimeException('Nie udało się utworzyć katalogu: ' . $relative);
        }
        continue;
    }

    $name = strtolower($item->getFilename());
    $extension = strtolower($item->getExtension());
    if (
        AuditArtifactSanitizer::isBlockedEnvironmentFileName($name)
        || in_array($name, $blockedNames, true)
        || in_array($extension, $blockedExtensions, true)
    ) {
        ++$skipped;
        continue;
    }
    $parent = dirname($target);
    if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
        throw new RuntimeException('Nie udało się utworzyć katalogu: ' . $parent);
    }

    if (in_array($extension, $textExtensions, true)) {
        $content = file_get_contents($item->getPathname());
        if ($content === false) {
            throw new RuntimeException('Nie udało się odczytać: ' . $relative);
        }
        $safe = $sanitizer->sanitize($content);
        if ($sanitizer->containsSensitiveData($safe)) {
            throw new RuntimeException('Po sanityzacji nadal wykryto dane wrażliwe: ' . $relative);
        }
        if (file_put_contents($target, $safe, LOCK_EX) === false) {
            throw new RuntimeException('Nie udało się zapisać: ' . $relative);
        }
        ++$processed;
        continue;
    }

    if (!copy($item->getPathname(), $target)) {
        throw new RuntimeException('Nie udało się skopiować: ' . $relative);
    }
    ++$copied;
}

fwrite(STDOUT, sprintf(
    "Sanityzacja zakończona: tekst=%d, binarne=%d, pominięte=%d\n",
    $processed,
    $copied,
    $skipped,
));

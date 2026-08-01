<?php
declare(strict_types=1);

/**
 * Kontrolowane odtworzenie plików wskazanych przez media #8-#10.
 * Źródła pozostają nietknięte, a ich znane sumy SHA-256 są wymagane.
 */

$options = getopt('', ['op-source:', 'pz-source:']);
$opSource = (string)($options['op-source'] ?? '');
$pzSource = (string)($options['pz-source'] ?? '');
if ($opSource === '' || $pzSource === '') {
    fwrite(STDERR, "Użycie: php scripts/recover_selected_media.php --op-source=plik.jpg --pz-source=plik.webp\n");
    exit(2);
}

$expected = [
    $opSource => '9bdf41f684023bcae2e9eaa60b60358a6daf6fe59b8bc6e8237c20e7a23f7c8d',
    $pzSource => '5933f1cd5e3a1855581dc6e848ab6e14d24ea71294d9643a05da8859988f022e',
];
foreach ($expected as $path => $hash) {
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException("Brak czytelnego źródła: {$path}");
    }
    $actual = hash_file('sha256', $path);
    if (!is_string($actual) || !hash_equals($hash, strtolower($actual))) {
        throw new RuntimeException("Źródło ma inną sumę SHA-256: {$path}");
    }
}

$root = dirname(__DIR__);
$destination = $root . '/public/uploads/articles';
if (!is_dir($destination) || !is_writable($destination)) {
    throw new RuntimeException('Katalog docelowy uploads/articles jest niedostępny.');
}

$image = imagecreatefromjpeg($opSource);
if (!$image instanceof GdImage) {
    throw new RuntimeException('Nie udało się odczytać źródłowego obrazu op,.jpg.');
}
if (function_exists('exif_read_data')) {
    $orientation = (int)((exif_read_data($opSource)['Orientation'] ?? 1));
    $image = match ($orientation) {
        3 => imagerotate($image, 180, 0),
        6 => imagerotate($image, -90, 0),
        8 => imagerotate($image, 90, 0),
        default => $image,
    };
}
if (!$image instanceof GdImage) {
    throw new RuntimeException('Nie udało się zastosować orientacji obrazu op,.jpg.');
}
imagepalettetotruecolor($image);
imagealphablending($image, true);
imagesavealpha($image, true);
$width = imagesx($image);
$height = imagesy($image);
if ($width > 1600 || $height > 1600) {
    $ratio = min(1600 / $width, 1600 / $height);
    $resized = imagescale(
        $image,
        max(1, (int)round($width * $ratio)),
        max(1, (int)round($height * $ratio)),
        IMG_BICUBIC_FIXED
    );
    imagedestroy($image);
    if (!$resized instanceof GdImage) {
        throw new RuntimeException('Nie udało się przeskalować obrazu op,.jpg.');
    }
    $image = $resized;
}

$opTarget = $destination . '/op.webp';
$op2Target = $destination . '/op-2.webp';
$pzTarget = $destination . '/pz.webp';
if (!imagewebp($image, $opTarget, 82)) {
    throw new RuntimeException('Nie udało się odtworzyć op.webp.');
}
imagedestroy($image);
if (!copy($opTarget, $op2Target) || !copy($pzSource, $pzTarget)) {
    throw new RuntimeException('Nie udało się odtworzyć kopii op-2.webp lub pz.webp.');
}

$manifest = [];
foreach ([$opTarget, $op2Target, $pzTarget] as $target) {
    $manifest[] = [
        'path' => str_replace('\\', '/', substr($target, strlen($root))),
        'bytes' => filesize($target),
        'sha256' => hash_file('sha256', $target),
        'mime' => (new finfo(FILEINFO_MIME_TYPE))->file($target),
    ];
}
echo json_encode(
    ['ok' => true, 'sources_preserved' => true, 'files' => $manifest],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
) . PHP_EOL;

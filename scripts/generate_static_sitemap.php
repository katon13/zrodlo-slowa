<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\App;
use App\Services\ArticleSeoService;

$rootPath = dirname(__DIR__);
$app = App::boot($rootPath);
$languagesPath = $rootPath . '/config/languages.php';
$languages = is_file($languagesPath) ? require $languagesPath : [];
$sites = $app->config['sites'] ?? [];

$seo = new ArticleSeoService($app->db, is_array($languages) ? $languages : [], is_array($sites) ? $sites : []);
$items = $seo->sitemapItems();

$xml = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . PHP_EOL;
foreach ($items as $item) {
    $loc = (string)($item['loc'] ?? '');
    if ($loc === '') {
        continue;
    }
    $xml .= '  <url>' . PHP_EOL;
    $xml .= '    <loc>' . htmlspecialchars($loc, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>' . PHP_EOL;
    $lastmod = trim((string)($item['lastmod'] ?? ''));
    if ($lastmod !== '') {
        $xml .= '    <lastmod>' . htmlspecialchars($lastmod, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</lastmod>' . PHP_EOL;
    }
    $hreflang = is_array($item['hreflang'] ?? null) ? $item['hreflang'] : [];
    foreach ($hreflang as $language => $href) {
        $language = (string)$language;
        $href = (string)$href;
        if ($language === '' || $href === '') {
            continue;
        }
        $xml .= '    <xhtml:link rel="alternate" hreflang="' . htmlspecialchars($language, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '" href="' . htmlspecialchars($href, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '" />' . PHP_EOL;
    }
    $xml .= '  </url>' . PHP_EOL;
}
$xml .= '</urlset>' . PHP_EOL;

$target = $rootPath . '/public/sitemap.xml';
if (@file_put_contents($target, $xml) === false) {
    fwrite(STDERR, 'BŁĄD: Nie można zapisać public/sitemap.xml' . PHP_EOL);
    exit(1);
}

echo 'Wygenerowano public/sitemap.xml' . PHP_EOL;
echo 'Liczba URL: ' . count($items) . PHP_EOL;

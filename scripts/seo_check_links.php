<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\App;
use App\Services\ArticleSeoService;

$app = App::boot(dirname(__DIR__));
$seo = new ArticleSeoService($app->db, $app->config['languages'] ?? [], $app->config['sites'] ?? []);
$items = $seo->sitemapItems();

$published = (int)$app->db->cell('SELECT COUNT(*) FROM articles WHERE status=\'published\'');
$publishedWithSlug = (int)$app->db->cell('SELECT COUNT(*) FROM articles WHERE status=\'published\' AND slug IS NOT NULL AND slug<>\'\'');
$translationsWithSlug = (int)$app->db->cell('SELECT COUNT(*) FROM article_translations WHERE language<>\'pl\' AND slug IS NOT NULL AND slug<>\'\'');
$translationsWithoutSlug = (int)$app->db->cell('SELECT COUNT(*) FROM article_translations WHERE language<>\'pl\' AND title IS NOT NULL AND title<>\'\' AND (slug IS NULL OR slug=\'\')');

echo "SEO CHECK\n";
echo "published_articles={$published}\n";
echo "published_articles_with_slug={$publishedWithSlug}\n";
echo "translations_with_slug={$translationsWithSlug}\n";
echo "translations_without_slug={$translationsWithoutSlug}\n";
echo "sitemap_items=" . count($items) . "\n";

foreach (array_slice($items, 0, 20) as $item) {
    echo '- ' . ($item['loc'] ?? '') . "\n";
}

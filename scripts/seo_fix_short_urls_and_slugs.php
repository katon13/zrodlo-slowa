<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\App;
use App\Services\SeoSlugService;

$rootPath = dirname(__DIR__);
$app = App::boot($rootPath);
$db = $app->db;
$slugger = SeoSlugService::fromConfigFile($db, $rootPath);

$dryRun = in_array('--dry-run', $argv, true);
$updatedArticles = 0;
$updatedTranslations = 0;

function cli_line(string $message): void
{
    echo $message . PHP_EOL;
}

cli_line('SEO FIX 9.5 — krótkie adresy i naprawa slugów');
cli_line($dryRun ? 'TRYB: dry-run, bez zapisu' : 'TRYB: zapis do bazy');

$articles = $db->all('SELECT id, title, slug FROM articles WHERE title IS NOT NULL AND title<>\'\' ORDER BY id ASC');
foreach ($articles as $article) {
    $id = (int)($article['id'] ?? 0);
    $title = (string)($article['title'] ?? '');
    $current = trim((string)($article['slug'] ?? ''));
    if ($id <= 0 || trim($title) === '') {
        continue;
    }
    $target = $slugger->uniqueArticleSlug($title, $id);
    if ($target === '' || $target === $current) {
        continue;
    }
    cli_line('[PL] article #' . $id . ' ' . ($current !== '' ? $current : '(brak)') . ' -> ' . $target);
    if (!$dryRun) {
        $db->query('UPDATE articles SET slug=:slug, updated_at=COALESCE(updated_at, NOW()) WHERE id=:id', [
            'slug' => $target,
            'id' => $id,
        ]);
    }
    $updatedArticles++;
}

$translations = $db->all(
    'SELECT id, article_id, language, title, slug
     FROM article_translations
     WHERE language<>\'pl\' AND title IS NOT NULL AND title<>\'\'
     ORDER BY article_id ASC,
              CASE language
                  WHEN \'en\' THEN 1 WHEN \'de\' THEN 2 WHEN \'fr\' THEN 3
                  WHEN \'it\' THEN 4 WHEN \'es\' THEN 5 ELSE 99
              END,
              language ASC'
);
foreach ($translations as $translation) {
    $id = (int)($translation['id'] ?? 0);
    $articleId = (int)($translation['article_id'] ?? 0);
    $language = strtolower(trim((string)($translation['language'] ?? '')));
    $title = (string)($translation['title'] ?? '');
    $current = trim((string)($translation['slug'] ?? ''));
    if ($id <= 0 || $articleId <= 0 || $language === '' || trim($title) === '') {
        continue;
    }
    $target = $slugger->uniqueTranslationSlug($title, $language, $articleId, $id);
    if ($target === '' || $target === $current) {
        continue;
    }
    cli_line('[' . strtoupper($language) . '] translation #' . $id . ' article #' . $articleId . ' ' . ($current !== '' ? $current : '(brak)') . ' -> ' . $target);
    if (!$dryRun) {
        $db->query('UPDATE article_translations SET slug=:slug, updated_at=COALESCE(updated_at, NOW()) WHERE id=:id', [
            'slug' => $target,
            'id' => $id,
        ]);
    }
    $updatedTranslations++;
}

cli_line('Do zmiany articles.slug: ' . $updatedArticles);
cli_line('Do zmiany article_translations.slug: ' . $updatedTranslations);
cli_line('Po zapisie uruchom: php scripts\\generate_static_sitemap.php');

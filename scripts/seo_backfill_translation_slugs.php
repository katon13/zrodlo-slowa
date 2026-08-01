<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\App;
use App\Services\SeoSlugService;

$app = App::boot(dirname(__DIR__));
$slugger = SeoSlugService::fromConfigFile($app->db, $app->rootPath);

$rows = $app->db->all(
    'SELECT id, article_id, language, title, slug
     FROM article_translations
     WHERE language<>\'pl\'
       AND title IS NOT NULL AND title<>\'\'
       AND (slug IS NULL OR slug=\'\')
     ORDER BY article_id,
              CASE language
                  WHEN \'en\' THEN 1 WHEN \'de\' THEN 2 WHEN \'fr\' THEN 3
                  WHEN \'it\' THEN 4 WHEN \'es\' THEN 5 ELSE 99
              END,
              language, id'
);

$count = 0;
foreach ($rows as $row) {
    $id = (int)($row['id'] ?? 0);
    $articleId = (int)($row['article_id'] ?? 0);
    $language = (string)($row['language'] ?? '');
    $title = (string)($row['title'] ?? '');
    if ($id <= 0 || $articleId <= 0 || $language === '' || trim($title) === '') {
        continue;
    }

    $slug = $slugger->uniqueTranslationSlug($title, $language, $articleId, $id);
    $app->db->query(
        'UPDATE article_translations SET slug=:slug, updated_at=updated_at WHERE id=:id',
        ['slug' => $slug, 'id' => $id]
    );
    $count++;
    echo '[' . strtoupper($language) . '] #' . $articleId . ' -> ' . $slug . PHP_EOL;
}

echo 'Uzupełnione slugi tłumaczeń: ' . $count . PHP_EOL;

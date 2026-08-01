<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Services\ArticleEconomyService;
use App\Services\ArticleService;

final class ArticleEconomyValuationTest extends DatabaseTestCase
{
    public function testValuationUpdatesLabelWithoutChangingArticleStatus(): void
    {
        $article = $this->database->one(
            'SELECT id,status FROM articles WHERE status=\'published\' ORDER BY id LIMIT 1'
        );
        $adminId = $this->database->cell(
            'SELECT user_id FROM user_roles WHERE role=\'admin\' ORDER BY user_id LIMIT 1'
        );
        if ($article === null || !$adminId) {
            self::markTestSkipped('Brak artykułu lub administratora w bazie testowej.');
        }

        (new ArticleEconomyService($this->database))->valueArticle(
            (int)$article['id'],
            (int)$adminId,
            [
                'access_mode' => 'free',
                'price' => '0',
                'currency' => 'PLN',
                'article_label' => 'Important',
                'author_share_percent' => '65',
                'editor_valuation_note' => 'Test niezależnej aktualizacji danych.',
            ]
        );

        $updated = $this->database->one(
            'SELECT status,access_mode,price_minor,article_label,author_share_percent,platform_share_percent
             FROM articles
             WHERE id=:id',
            ['id' => $article['id']]
        );
        self::assertSame($article['status'], $updated['status']);
        self::assertSame('free', $updated['access_mode']);
        self::assertSame(0, (int)$updated['price_minor']);
        self::assertSame('Important', $updated['article_label']);
        self::assertSame(65.0, (float)$updated['author_share_percent']);
        self::assertSame(35.0, (float)$updated['platform_share_percent']);

        $publicArticle = array_values(array_filter(
            (new ArticleService($this->database))->published(100),
            static fn(array $row): bool => (int)$row['id'] === (int)$article['id']
        ))[0] ?? null;
        self::assertIsArray($publicArticle);
        self::assertSame('Important', $publicArticle['article_label']);

        $eventPayload = $this->database->cell(
            'SELECT payload_json
             FROM article_events
             WHERE article_id=:article AND event=\'valued\'
             ORDER BY id DESC
             LIMIT 1',
            ['article' => $article['id']]
        );
        self::assertIsString($eventPayload);
        self::assertSame(
            'Important',
            json_decode($eventPayload, true, 512, JSON_THROW_ON_ERROR)['article_label'] ?? null
        );
    }
}

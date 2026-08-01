<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Services\ArticleTranslationService;

final class ArticleTranslationPublicationTest extends DatabaseTestCase
{
    public function testDraftTranslationIsNotVisibleForPublishedArticle(): void
    {
        $translation = $this->database->one(
            'SELECT t.id,t.article_id,t.language
             FROM article_translations t
             JOIN articles a ON a.id=t.article_id
             WHERE a.status=\'published\'
             ORDER BY t.id
             LIMIT 1'
        );
        if ($translation === null) {
            self::markTestSkipped('Baza testowa nie zawiera tłumaczenia opublikowanego artykułu.');
        }

        $this->database->query(
            'UPDATE article_translations SET status=\'ai_draft\' WHERE id=:id',
            ['id' => $translation['id']]
        );

        $service = new ArticleTranslationService($this->database);
        self::assertNull(
            $service->findPublished((int)$translation['article_id'], (string)$translation['language'])
        );
        self::assertNotContains(
            (string)$translation['language'],
            $service->availablePublishedLanguages((int)$translation['article_id'])
        );
        self::assertArrayNotHasKey(
            (string)$translation['language'],
            $service->publishedLanguageMap((int)$translation['article_id'])
        );
        self::assertArrayNotHasKey(
            (string)$translation['language'],
            $service->mapForArticles([(int)$translation['article_id']])[(int)$translation['article_id']] ?? []
        );
    }

    public function testEditingPublishedTranslationCreatesHistoryAndReturnsItToDraft(): void
    {
        $translation = $this->database->one(
            'SELECT t.*
             FROM article_translations t
             JOIN articles a ON a.id=t.article_id
             WHERE a.status=\'published\' AND t.status=\'published\'
             ORDER BY t.id
             LIMIT 1'
        );
        if ($translation === null) {
            self::markTestSkipped('Baza testowa nie zawiera opublikowanego tłumaczenia.');
        }

        $before = (int)$this->database->cell(
            'SELECT COUNT(*) FROM article_translation_versions WHERE translation_id=:id',
            ['id' => $translation['id']]
        );
        $service = new ArticleTranslationService($this->database);
        $translationId = $service->saveEditorialVersion(
            (int)$translation['article_id'],
            (string)$translation['language'],
            [
                'source_language' => (string)$translation['source_language'],
                'title' => (string)$translation['title'] . ' — wersja testowa',
                'lead' => $translation['lead'],
                'body' => (string)$translation['body'],
                'translation_instructions' => $translation['translation_instructions'],
            ],
            isset($translation['updated_by']) ? (int)$translation['updated_by'] : null
        );

        $current = $this->database->one(
            'SELECT status,title FROM article_translations WHERE id=:id',
            ['id' => $translationId]
        );
        $snapshot = $this->database->one(
            'SELECT status,title
             FROM article_translation_versions
             WHERE translation_id=:id
             ORDER BY version_no DESC
             LIMIT 1',
            ['id' => $translationId]
        );

        self::assertSame('draft', $current['status']);
        self::assertSame('published', $snapshot['status']);
        self::assertSame($translation['title'], $snapshot['title']);
        self::assertSame(
            $before + 1,
            (int)$this->database->cell(
                'SELECT COUNT(*) FROM article_translation_versions WHERE translation_id=:id',
                ['id' => $translationId]
            )
        );
        self::assertNull(
            $service->findPublished((int)$translation['article_id'], (string)$translation['language'])
        );
    }

    public function testPublisherCanApproveAndPublishTranslationAtomically(): void
    {
        $translation = $this->database->one(
            'SELECT t.*
             FROM article_translations t
             JOIN articles a ON a.id=t.article_id
             WHERE a.status=\'published\'
               AND t.language<>a.source_language
               AND t.title IS NOT NULL AND t.title<>\'\'
               AND t.body IS NOT NULL AND t.body<>\'\'
               AND t.slug IS NOT NULL AND t.slug<>\'\'
             ORDER BY t.id
             LIMIT 1'
        );
        $publisherId = (int)($this->database->cell('SELECT id FROM users ORDER BY id LIMIT 1') ?: 0);
        if ($translation === null || $publisherId <= 0) {
            self::markTestSkipped('Baza testowa nie zawiera kompletnego tłumaczenia albo użytkownika Wydawcy.');
        }

        $translationId = (int)$translation['id'];
        $beforeVersions = (int)$this->database->cell(
            'SELECT COUNT(*) FROM article_translation_versions WHERE translation_id=:id',
            ['id' => $translationId]
        );
        $this->database->query(
            'UPDATE article_translations
             SET status=\'ai_draft\',
                 reviewed_by=NULL,
                 reviewed_at=NULL,
                 published_by=NULL,
                 published_at=NULL
             WHERE id=:id',
            ['id' => $translationId]
        );

        $service = new ArticleTranslationService($this->database);
        $service->approveAndPublish($translationId, $publisherId);

        $current = $this->database->one(
            'SELECT status,reviewed_by,reviewed_at,published_by,published_at,updated_by
             FROM article_translations
             WHERE id=:id',
            ['id' => $translationId]
        );
        self::assertSame('published', $current['status']);
        self::assertSame($publisherId, (int)$current['reviewed_by']);
        self::assertSame($publisherId, (int)$current['published_by']);
        self::assertSame($publisherId, (int)$current['updated_by']);
        self::assertNotEmpty($current['reviewed_at']);
        self::assertNotEmpty($current['published_at']);
        self::assertSame(
            $beforeVersions + 1,
            (int)$this->database->cell(
                'SELECT COUNT(*) FROM article_translation_versions WHERE translation_id=:id',
                ['id' => $translationId]
            )
        );
        self::assertNotNull(
            $service->findPublished((int)$translation['article_id'], (string)$translation['language'])
        );
    }

    public function testArticleSourceLanguageIsNotExposedAsATranslation(): void
    {
        $translation = $this->database->one(
            'SELECT t.article_id,t.language
             FROM article_translations t
             JOIN articles a ON a.id=t.article_id
             WHERE a.status=\'published\'
               AND t.status=\'published\'
               AND t.language<>a.source_language
             ORDER BY t.id
             LIMIT 1'
        );
        if ($translation === null) {
            self::markTestSkipped('Baza testowa nie zawiera opublikowanego tłumaczenia.');
        }

        $articleId = (int)$translation['article_id'];
        $newSourceLanguage = (string)$translation['language'];
        $this->database->query(
            'UPDATE articles SET source_language=:source_language WHERE id=:id',
            ['source_language' => $newSourceLanguage, 'id' => $articleId]
        );

        $service = new ArticleTranslationService($this->database);
        self::assertNull($service->findPublished($articleId, $newSourceLanguage));
        self::assertArrayNotHasKey($newSourceLanguage, $service->publishedLanguageMap($articleId));
        self::assertNotContains($newSourceLanguage, $service->availablePublishedLanguages($articleId));
    }

    public function testRejectedTranslationMustBeEditedBeforePublication(): void
    {
        $translation = $this->database->one(
            'SELECT t.id
             FROM article_translations t
             JOIN articles a ON a.id=t.article_id
             WHERE t.language<>a.source_language
               AND t.title IS NOT NULL AND t.title<>\'\'
               AND t.body IS NOT NULL AND t.body<>\'\'
               AND t.slug IS NOT NULL AND t.slug<>\'\'
             ORDER BY t.id
             LIMIT 1'
        );
        $publisherId = (int)($this->database->cell('SELECT id FROM users ORDER BY id LIMIT 1') ?: 0);
        if ($translation === null || $publisherId <= 0) {
            self::markTestSkipped('Baza testowa nie zawiera kompletnego tłumaczenia albo użytkownika Wydawcy.');
        }

        $translationId = (int)$translation['id'];
        $this->database->query(
            'UPDATE article_translations SET status=\'rejected\' WHERE id=:id',
            ['id' => $translationId]
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ponownie zapisać');
        (new ArticleTranslationService($this->database))->approveAndPublish($translationId, $publisherId);
    }
}

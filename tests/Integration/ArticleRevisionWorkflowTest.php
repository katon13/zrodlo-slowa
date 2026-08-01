<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Services\ArticleService;

final class ArticleRevisionWorkflowTest extends DatabaseTestCase
{
    public function testEditingPublishedArticleCreatesIsolatedDraftRevision(): void
    {
        $published = $this->database->one(
            'SELECT id,author_id,title,`lead`,body,access_mode,price_minor,source_language
             FROM articles WHERE status=\'published\' ORDER BY id LIMIT 1'
        );
        if ($published === null) {
            self::markTestSkipped('Baza testowa nie zawiera opublikowanego artykułu.');
        }

        $service = new ArticleService($this->database);
        $revisionId = $service->updateDraft((int)$published['id'], (int)$published['author_id'], [
            'title' => (string)$published['title'] . ' — rewizja testowa',
            'lead' => $published['lead'],
            'body' => $published['body'],
            'access_mode' => $published['access_mode'],
            'price_minor' => $published['price_minor'],
            'source_language' => $published['source_language'],
        ]);

        self::assertNotSame((int)$published['id'], $revisionId);
        $original = $this->database->one('SELECT title,status FROM articles WHERE id=:id', ['id' => $published['id']]);
        $revision = $this->database->one(
            'SELECT revision_of_article_id,status FROM articles WHERE id=:id',
            ['id' => $revisionId]
        );
        self::assertSame($published['title'], $original['title']);
        self::assertSame('published', $original['status']);
        self::assertSame((int)$published['id'], (int)$revision['revision_of_article_id']);
        self::assertSame('draft', $revision['status']);
    }

    public function testEditorialEditOfPublishedArticleAlsoCreatesRevision(): void
    {
        $published = $this->database->one(
            'SELECT * FROM articles WHERE status=\'published\' ORDER BY id LIMIT 1'
        );
        $adminId = $this->database->cell(
            'SELECT user_id FROM user_roles WHERE role=\'admin\' ORDER BY user_id LIMIT 1'
        );
        if ($published === null || !$adminId) {
            self::markTestSkipped('Brak opublikowanego artykułu lub administratora.');
        }

        $service = new ArticleService($this->database);
        $revisionId = $service->updateEditorial((int)$published['id'], (int)$adminId, [
            'title' => (string)$published['title'] . ' — korekta redakcyjna',
            'lead' => $published['lead'],
            'body' => $published['body'],
            'source_language' => $published['source_language'],
            'display_order' => $published['display_order'],
            'editorial_weight' => $published['editorial_weight'],
            'is_featured' => $published['is_featured'],
        ]);

        $original = $this->database->one(
            'SELECT title,status FROM articles WHERE id=:id',
            ['id' => $published['id']]
        );
        $revision = $this->database->one(
            'SELECT revision_of_article_id,status,title FROM articles WHERE id=:id',
            ['id' => $revisionId]
        );

        self::assertNotSame((int)$published['id'], $revisionId);
        self::assertSame($published['title'], $original['title']);
        self::assertSame('published', $original['status']);
        self::assertSame((int)$published['id'], (int)$revision['revision_of_article_id']);
        self::assertSame('draft', $revision['status']);
        self::assertStringContainsString('korekta redakcyjna', (string)$revision['title']);
    }

    public function testPublishedArticleCannotBeEditedByProofreader(): void
    {
        $published = $this->database->one(
            'SELECT id,`lead`,body FROM articles WHERE status=\'published\' ORDER BY id LIMIT 1'
        );
        $proofreaderId = $this->database->cell(
            'SELECT user_id FROM user_roles
             WHERE role IN (\'proofreader\',\'admin\')
             ORDER BY user_id LIMIT 1'
        );
        if ($published === null || !$proofreaderId) {
            self::markTestSkipped('Brak opublikowanego artykułu lub korektora.');
        }

        $this->expectException(\RuntimeException::class);
        (new ArticleService($this->database))->updateProofreading(
            (int)$published['id'],
            (int)$proofreaderId,
            ['lead' => $published['lead'], 'body' => $published['body']]
        );
    }
}

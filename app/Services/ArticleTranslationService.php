<?php
namespace App\Services;

use App\Core\Database;
use App\Models\ArticleTranslation;

final class ArticleTranslationService
{
    private ?SeoSlugService $seoSlugService = null;

    public function __construct(
        private readonly Database $db,
        private readonly array $languageConfig = []
    ) {}

    public function statuses(): array
    {
        return ArticleTranslation::STATUSES;
    }

    public function normalizeLanguage(string $language): string
    {
        $language = strtolower(trim($language));
        $enabled = $this->languageConfig['public_enabled'] ?? ['pl', 'en', 'de', 'fr', 'it', 'es'];
        if (!in_array($language, $enabled, true)) {
            return (string)($this->languageConfig['default'] ?? 'pl');
        }
        return $language;
    }

    public function findPublished(int $articleId, string $language): ?array
    {
        $language = $this->normalizeLanguage($language);
        if ($language === $this->sourceLanguageForArticle($articleId)) {
            return null;
        }

        return $this->db->one(
            'SELECT * FROM article_translations
             WHERE article_id=:article AND language=:language AND status=:status
             LIMIT 1',
            [
                'article' => $articleId,
                'language' => $language,
                'status' => ArticleTranslation::STATUS_PUBLISHED,
            ]
        );
    }

    public function availablePublishedLanguages(int $articleId): array
    {
        $rows = $this->db->all(
            'SELECT t.language
             FROM article_translations t
             JOIN articles a ON a.id=t.article_id
             WHERE t.article_id=:article
               AND t.status=:status
               AND t.language<>a.source_language
             ORDER BY ' . $this->languageOrder('t.language') . ', t.language',
            ['article' => $articleId, 'status' => ArticleTranslation::STATUS_PUBLISHED]
        );
        return array_values(array_unique(array_map(static fn(array $row): string => (string)$row['language'], $rows)));
    }


    /**
     * @return array<string, mixed>
     */
    public function publishedLanguageMap(int $articleId): array
    {
        $rows = $this->db->all(
            'SELECT t.language, t.title, t.slug
             FROM article_translations t
             JOIN articles a ON a.id=t.article_id
             WHERE t.article_id=:article
               AND t.status=:status
               AND t.language<>a.source_language
               AND t.title IS NOT NULL AND t.title<>\'\'
               AND t.body IS NOT NULL AND t.body<>\'\'
               AND t.slug IS NOT NULL AND t.slug<>\'\'
             ORDER BY ' . $this->languageOrder('t.language') . ', t.language',
            ['article' => $articleId, 'status' => ArticleTranslation::STATUS_PUBLISHED]
        );

        $map = [];
        foreach ($rows as $row) {
            $language = (string)($row['language'] ?? '');
            if ($language !== '') {
                $map[$language] = $row;
            }
        }
        return $map;
    }


    /**
     * @return array<string, array<string, mixed>>
     */
    public function languageMapForEditor(int $articleId): array
    {
        $rows = $this->db->all(
            'SELECT language, title, slug, status FROM article_translations
             WHERE article_id=:article
             ORDER BY ' . $this->languageOrder('language') . ', language',
            ['article' => $articleId]
        );

        $map = [];
        foreach ($rows as $row) {
            $language = (string)($row['language'] ?? '');
            if ($language !== '') {
                $map[$language] = $row;
            }
        }
        return $map;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function allForArticle(int $articleId): array
    {
        return $this->db->all(
            'SELECT * FROM article_translations
             WHERE article_id=:article
             ORDER BY ' . $this->languageOrder('language') . ', language',
            ['article' => $articleId]
        );
    }

    /**
     * @param array<int, int> $articleIds
     * @return array<int, array<string, array<string, mixed>>>
     */
    public function mapForArticles(array $articleIds, bool $publishedOnly = true): array
    {
        $articleIds = array_values(array_unique(array_filter(array_map('intval', $articleIds), static fn(int $id): bool => $id > 0)));
        if ($articleIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($articleIds), '?'));
        $statusFilter = $publishedOnly ? ' AND t.status=\'published\'' : '';
        $rows = $this->db->all(
            'SELECT t.id, t.article_id, t.language, t.title, t.`lead`, t.body, t.slug,
                    t.status, t.updated_at, t.reviewed_at, t.published_at
             FROM article_translations t
             JOIN articles a ON a.id=t.article_id
             WHERE t.article_id IN (' . $placeholders . ')
               AND t.language<>a.source_language' . $statusFilter . '
             ORDER BY t.article_id, ' . $this->languageOrder('t.language') . ', t.language',
            $articleIds
        );

        $map = [];
        foreach ($rows as $row) {
            $articleId = (int)($row['article_id'] ?? 0);
            $language = (string)($row['language'] ?? '');
            if ($articleId > 0 && $language !== '') {
                $map[$articleId][$language] = $row;
            }
        }
        return $map;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function saveFromEditor(int $articleId, string $language, array $data, ?int $userId = null): int
    {
        $language = $this->normalizeLanguage($language);
        $sourceLanguage = $this->sourceLanguageForArticle($articleId);
        if ($language === $sourceLanguage) {
            throw new \InvalidArgumentException('Język oryginału jest zapisany w artykule i nie może być zapisany drugi raz jako tłumaczenie.');
        }

        $status = (string)($data['status'] ?? ArticleTranslation::STATUS_DRAFT);
        if (!ArticleTranslation::isValidStatus($status)) {
            throw new \InvalidArgumentException('Nieprawidłowy status tłumaczenia.');
        }
        return $this->db->transaction(function () use ($articleId, $language, $data, $userId, $status): int {
            $savedStatus = in_array(
                $status,
                [ArticleTranslation::STATUS_APPROVED, ArticleTranslation::STATUS_PUBLISHED],
                true
            ) ? ArticleTranslation::STATUS_DRAFT : $status;

            $payload = $data;
            $payload['status'] = $savedStatus;
            $id = $this->saveDraft($articleId, $language, $payload, $userId);

            if (in_array($status, [ArticleTranslation::STATUS_APPROVED, ArticleTranslation::STATUS_PUBLISHED], true)) {
                $this->markReviewed($id, $userId ?? 0, ArticleTranslation::STATUS_APPROVED);
            }
            if ($status === ArticleTranslation::STATUS_PUBLISHED) {
                $this->publish($id, $userId ?? 0);
            }

            return $id;
        });
    }


    /**
     * Zapis wersji językowej przez Wydawcę.
     * Nie publikuje, nie zatwierdza i nie zmienia statusu artykułu.
     * Pozwala zapisać także PL, jeżeli polski nie jest językiem oryginału.
     *
     * @param array<string, mixed> $data
     */
    public function saveEditorialVersion(int $articleId, string $language, array $data, ?int $userId = null): int
    {
        $language = $this->normalizeLanguage($language);
        $sourceLanguage = $this->sourceLanguageForArticle($articleId);
        if ($language === $sourceLanguage) {
            throw new \InvalidArgumentException('Język oryginału jest zapisany w artykule i nie może być zapisany drugi raz jako tłumaczenie.');
        }
        $title = trim((string)($data['title'] ?? ''));
        $lead = trim((string)($data['lead'] ?? ''));
        $body = trim((string)($data['body'] ?? ''));

        if ($title === '' || $body === '') {
            throw new \InvalidArgumentException('Tytuł i treść wersji językowej są wymagane.');
        }

        $existing = $this->findForEditor($articleId, $language);
        // Każda zmiana treści wymaga ponownej akceptacji. W szczególności
        // opublikowana wersja nie może zostać nadpisana jako nadal publiczna.
        $status = ArticleTranslation::STATUS_DRAFT;

        $existingId = $existing ? (int)$existing['id'] : null;
        $slug = $this->translationSlug($title, $language, $articleId, $existingId);

        $params = [
            'article_id' => $articleId,
            'language' => $language,
            'source_language' => $sourceLanguage,
            'target_language' => $language,
            'title' => $title,
            'lead' => $lead !== '' ? $lead : null,
            'body' => $body,
            'status' => $status,
            'slug' => $slug,
            'translation_instructions' => $data['translation_instructions'] ?? null,
            'updated_by' => $userId,
        ];

        if ($existing) {
            $updateParams = $params;
            unset($updateParams['article_id'], $updateParams['language']);
            $updateParams['id'] = (int)$existing['id'];
            return $this->db->transaction(function (Database $db) use ($updateParams, $userId): int {
                $locked = $db->one(
                    'SELECT * FROM article_translations WHERE id=:id LIMIT 1 FOR UPDATE',
                    ['id' => $updateParams['id']]
                );
                if (!$locked) {
                    throw new \RuntimeException('Nie znaleziono tłumaczenia do aktualizacji.');
                }
                $this->snapshotTranslation($locked, $userId);
                $db->query(
                    'UPDATE article_translations
                     SET source_language=:source_language,
                         target_language=:target_language,
                         title=:title,
                         `lead`=:lead,
                         body=:body,
                          status=:status,
                          slug=:slug,
                          translation_instructions=:translation_instructions,
                          reviewed_by=NULL,
                          reviewed_at=NULL,
                          published_by=NULL,
                          published_at=NULL,
                          updated_by=:updated_by,
                          updated_at=NOW()
                     WHERE id=:id',
                    $updateParams
                );
                return (int)$updateParams['id'];
            });
        }

        $params['created_by'] = $userId;
        return $this->db->insert(
            'INSERT INTO article_translations(article_id, language, source_language, target_language, title, `lead`, body, status, slug, translation_instructions, created_by, updated_by, created_at, updated_at)
             VALUES(:article_id, :language, :source_language, :target_language, :title, :lead, :body, :status, :slug, :translation_instructions, :created_by, :updated_by, NOW(), NOW())',
            $params
        );
    }

    /**
     * Pakietowy zapis tłumaczeń AI.
     * Nie publikuje, nie zatwierdza i nie zmienia artykułu głównego.
     *
     * @param array<string, mixed> $translations
     * @param array<int, string> $targetLanguages
     * @return array<int, string>
     */
    public function saveAiTranslationPackage(int $articleId, string $sourceLanguage, array $translations, array $targetLanguages, string $instructions, ?int $userId, ?int $aiJobId, ?string $model = null): array
    {
        $sourceLanguage = $this->normalizeLanguage($sourceLanguage);
        if ($sourceLanguage !== $this->sourceLanguageForArticle($articleId)) {
            throw new \InvalidArgumentException('Język źródłowy pakietu AI nie zgadza się z językiem oryginału artykułu.');
        }

        $saved = [];
        foreach (array_values($targetLanguages) as $language) {
            $language = $this->normalizeLanguage((string)$language);
            if ($language === $sourceLanguage) {
                throw new \InvalidArgumentException('Język docelowy nie może być językiem źródłowym: ' . $language);
            }
            if (!isset($translations[$language]) || !is_array($translations[$language])) {
                throw new \InvalidArgumentException('Brak tłumaczenia dla języka: ' . strtoupper($language));
            }

            $data = $translations[$language];
            $title = trim((string)($data['title'] ?? ''));
            $lead = trim((string)($data['lead'] ?? ''));
            $body = trim((string)($data['body'] ?? ''));
            if ($title === '' || $body === '') {
                throw new \InvalidArgumentException('Puste pole title/body dla języka: ' . strtoupper($language));
            }

            $existing = $this->findForEditor($articleId, $language);
            $existingId = $existing ? (int)$existing['id'] : null;
            $slug = $this->translationSlug($title, $language, $articleId, $existingId);

            $params = [
                'article_id' => $articleId,
                'language' => $language,
                'source_language' => $sourceLanguage,
                'target_language' => $language,
                'title' => $title,
                'lead' => $lead !== '' ? $lead : null,
                'body' => $body,
                'status' => \App\Models\ArticleTranslation::STATUS_AI_DRAFT,
                'slug' => $slug,
                'translation_instructions' => $instructions !== '' ? $instructions : null,
                'ai_provider' => 'openai',
                'ai_model' => $model,
                'provider' => 'openai',
                'ai_job_id' => $aiJobId,
                'updated_by' => $userId,
            ];

            if ($existing) {
                $updateParams = $params;
                unset($updateParams['article_id'], $updateParams['language']);
                $updateParams['id'] = (int)$existing['id'];
                $this->db->transaction(function (Database $db) use ($updateParams, $userId): void {
                    $locked = $db->one(
                        'SELECT * FROM article_translations WHERE id=:id LIMIT 1 FOR UPDATE',
                        ['id' => $updateParams['id']]
                    );
                    if (!$locked) {
                        throw new \RuntimeException('Nie znaleziono tłumaczenia do aktualizacji.');
                    }
                    $this->snapshotTranslation($locked, $userId);
                    $db->query(
                        'UPDATE article_translations
                         SET source_language=:source_language,
                             target_language=:target_language,
                             title=:title,
                             `lead`=:lead,
                             body=:body,
                             status=:status,
                             slug=:slug,
                             translation_instructions=:translation_instructions,
                             ai_provider=:ai_provider,
                             ai_model=:ai_model,
                              provider=:provider,
                              ai_job_id=:ai_job_id,
                              reviewed_by=NULL,
                              reviewed_at=NULL,
                              published_by=NULL,
                              published_at=NULL,
                              updated_by=:updated_by,
                              updated_at=NOW()
                         WHERE id=:id',
                        $updateParams
                    );
                });
            } else {
                $params['created_by'] = $userId;
                $this->db->insert(
                    'INSERT INTO article_translations(article_id, language, source_language, target_language, title, `lead`, body, status, slug, translation_instructions, ai_provider, ai_model, provider, ai_job_id, created_by, updated_by, created_at, updated_at)
                     VALUES(:article_id, :language, :source_language, :target_language, :title, :lead, :body, :status, :slug, :translation_instructions, :ai_provider, :ai_model, :provider, :ai_job_id, :created_by, :updated_by, NOW(), NOW())',
                    $params
                );
            }
            $saved[] = $language;
        }
        return $saved;
    }

    public function markReviewed(int $translationId, int $reviewerId, string $status = ArticleTranslation::STATUS_APPROVED): void
    {
        if (!in_array($status, [ArticleTranslation::STATUS_EDITOR_REVIEW, ArticleTranslation::STATUS_APPROVED, ArticleTranslation::STATUS_REJECTED], true)) {
            throw new \InvalidArgumentException('Ten status nie jest statusem korekty redakcyjnej.');
        }

        $this->db->transaction(function (Database $db) use ($translationId, $reviewerId, $status): void {
            $translation = $db->one(
                'SELECT * FROM article_translations WHERE id=:id LIMIT 1 FOR UPDATE',
                ['id' => $translationId]
            );
            if (!$translation) {
                throw new \RuntimeException('Nie znaleziono tłumaczenia.');
            }
            if ((string)($translation['status'] ?? '') === ArticleTranslation::STATUS_PUBLISHED) {
                throw new \RuntimeException('Opublikowanego tłumaczenia nie można zmienić bez utworzenia nowej wersji roboczej.');
            }

            $this->snapshotTranslation($translation, $reviewerId);
            $db->query(
                'UPDATE article_translations
                 SET status=:status,
                     reviewed_by=:reviewed_by,
                     reviewed_at=NOW(),
                     published_by=NULL,
                     published_at=NULL,
                     updated_by=:updated_by,
                     updated_at=NOW()
                 WHERE id=:id',
                [
                    'id' => $translationId,
                    'reviewed_by' => $reviewerId,
                    'updated_by' => $reviewerId,
                    'status' => $status,
                ]
            );
        });
    }


    private function slugService(): SeoSlugService
    {
        if ($this->seoSlugService === null) {
            $this->seoSlugService = SeoSlugService::fromConfigFile($this->db, dirname(__DIR__, 2));
        }
        return $this->seoSlugService;
    }

    private function translationSlug(string $title, string $language, int $articleId, ?int $existingTranslationId = null): string
    {
        return $this->slugService()->uniqueTranslationSlug($title, $language, $articleId, $existingTranslationId);
    }

    public function findForEditor(int $articleId, string $language): ?array
    {
        $language = $this->normalizeLanguage($language);
        return $this->db->one(
            'SELECT * FROM article_translations WHERE article_id=:article AND language=:language LIMIT 1',
            ['article' => $articleId, 'language' => $language]
        );
    }

    public function saveDraft(int $articleId, string $language, array $data, ?int $userId = null): int
    {
        $language = $this->normalizeLanguage($language);
        $sourceLanguage = $this->sourceLanguageForArticle($articleId);
        if ($language === $sourceLanguage) {
            throw new \InvalidArgumentException('Język oryginału jest zapisany w tabeli articles, nie w article_translations.');
        }

        $status = (string)($data['status'] ?? ArticleTranslation::STATUS_DRAFT);
        if (!ArticleTranslation::isValidStatus($status)) {
            throw new \InvalidArgumentException('Nieprawidłowy status tłumaczenia.');
        }
        if ($status === ArticleTranslation::STATUS_PUBLISHED) {
            throw new \InvalidArgumentException('Publikacja wymaga osobnego kroku zatwierdzenia i publikacji.');
        }

        $existing = $this->findForEditor($articleId, $language);
        $titleForSlug = trim((string)($data['title'] ?? ''));
        $existingId = $existing ? (int)$existing['id'] : null;
        $slug = $titleForSlug !== '' ? $this->translationSlug($titleForSlug, $language, $articleId, $existingId) : null;

        $params = [
            'article_id' => $articleId,
            'language' => $language,
            'source_language' => $sourceLanguage,
            'target_language' => $language,
            'title' => $data['title'] ?? null,
            'lead' => $data['lead'] ?? null,
            'body' => $data['body'] ?? null,
            'seo_title' => $data['seo_title'] ?? null,
            'seo_description' => $data['seo_description'] ?? null,
            'seo_keywords' => $data['seo_keywords'] ?? null,
            'slug' => $slug,
            'status' => $status,
            'translation_instructions' => $data['translation_instructions'] ?? null,
            'ai_provider' => $data['ai_provider'] ?? null,
            'ai_model' => $data['ai_model'] ?? null,
            'provider' => $data['provider'] ?? ($data['ai_provider'] ?? null),
            'ai_job_id' => $data['ai_job_id'] ?? null,
            'updated_by' => $userId,
        ];

        if ($existing) {
            $updateParams = $params;
            unset($updateParams['article_id'], $updateParams['language']);
            $updateParams['id'] = (int)$existing['id'];
            return $this->db->transaction(function (Database $db) use ($updateParams, $userId): int {
                $locked = $db->one(
                    'SELECT * FROM article_translations WHERE id=:id LIMIT 1 FOR UPDATE',
                    ['id' => $updateParams['id']]
                );
                if (!$locked) {
                    throw new \RuntimeException('Nie znaleziono tłumaczenia do aktualizacji.');
                }
                $this->snapshotTranslation($locked, $userId);
                $db->query(
                    'UPDATE article_translations
                     SET source_language=:source_language,
                         target_language=:target_language,
                         title=:title,
                         `lead`=:lead,
                         body=:body,
                         seo_title=:seo_title,
                         seo_description=:seo_description,
                         seo_keywords=:seo_keywords,
                         slug=:slug,
                         status=:status,
                         translation_instructions=:translation_instructions,
                         ai_provider=:ai_provider,
                         ai_model=:ai_model,
                          provider=:provider,
                          ai_job_id=:ai_job_id,
                          reviewed_by=NULL,
                          reviewed_at=NULL,
                          published_by=NULL,
                          published_at=NULL,
                          updated_by=:updated_by,
                          updated_at=NOW()
                     WHERE id=:id',
                    $updateParams
                );
                return (int)$updateParams['id'];
            });
        }

        $params['created_by'] = $userId;
        return $this->db->insert(
            'INSERT INTO article_translations(article_id, language, source_language, target_language, title, `lead`, body, seo_title, seo_description, seo_keywords, slug, status, translation_instructions, ai_provider, ai_model, provider, ai_job_id, created_by, updated_by, created_at, updated_at)
             VALUES(:article_id, :language, :source_language, :target_language, :title, :lead, :body, :seo_title, :seo_description, :seo_keywords, :slug, :status, :translation_instructions, :ai_provider, :ai_model, :provider, :ai_job_id, :created_by, :updated_by, NOW(), NOW())',
            $params
        );
    }

    public function submitForReview(int $translationId, int $reviewerId): void
    {
        $translation = $this->findById($translationId);
        if (!$translation) {
            throw new \RuntimeException('Nie znaleziono tłumaczenia.');
        }

        $this->assertHasEditableContent($translation, 'przekazania do korekty');
        $this->markReviewed($translationId, $reviewerId, ArticleTranslation::STATUS_EDITOR_REVIEW);
    }

    public function reject(int $translationId, int $reviewerId): void
    {
        $translation = $this->findById($translationId);
        if (!$translation) {
            throw new \RuntimeException('Nie znaleziono tłumaczenia.');
        }

        if ((string)($translation['status'] ?? '') === ArticleTranslation::STATUS_PUBLISHED) {
            throw new \RuntimeException('Nie można odrzucić tłumaczenia, które jest już publicznie opublikowane.');
        }

        $this->markReviewed($translationId, $reviewerId, ArticleTranslation::STATUS_REJECTED);
    }

    public function approveAndPublish(int $translationId, int $publisherId): void
    {
        if ($translationId <= 0 || $publisherId <= 0) {
            throw new \InvalidArgumentException('Brak poprawnego tłumaczenia albo Wydawcy.');
        }

        $this->db->transaction(function (Database $db) use ($translationId, $publisherId): void {
            $translation = $db->one(
                'SELECT * FROM article_translations WHERE id=:id LIMIT 1 FOR UPDATE',
                ['id' => $translationId]
            );
            if (!$translation) {
                throw new \RuntimeException('Nie znaleziono tłumaczenia.');
            }
            if ((string)($translation['status'] ?? '') === ArticleTranslation::STATUS_PUBLISHED) {
                return;
            }
            if (!in_array(
                (string)($translation['status'] ?? ''),
                [
                    ArticleTranslation::STATUS_DRAFT,
                    ArticleTranslation::STATUS_AI_DRAFT,
                    ArticleTranslation::STATUS_EDITOR_REVIEW,
                    ArticleTranslation::STATUS_APPROVED,
                ],
                true
            )) {
                throw new \RuntimeException('To tłumaczenie trzeba najpierw poprawić i ponownie zapisać przed publikacją.');
            }

            $this->assertReadyForPublication($translation);
            $this->snapshotTranslation($translation, $publisherId);

            $db->query(
                'UPDATE article_translations
                 SET status=:status,
                     reviewed_by=:reviewed_by,
                     reviewed_at=NOW(),
                     published_by=:published_by,
                     published_at=NOW(),
                     updated_by=:updated_by,
                     updated_at=NOW()
                 WHERE id=:id',
                [
                    'status' => ArticleTranslation::STATUS_PUBLISHED,
                    'reviewed_by' => $publisherId,
                    'published_by' => $publisherId,
                    'updated_by' => $publisherId,
                    'id' => $translationId,
                ]
            );
        });
    }

    public function publish(int $translationId, int $publisherId): void
    {
        $this->db->transaction(function (Database $db) use ($translationId, $publisherId): void {
            $translation = $db->one(
                'SELECT * FROM article_translations WHERE id=:id LIMIT 1 FOR UPDATE',
                ['id' => $translationId]
            );
            if (!$translation) {
                throw new \RuntimeException('Nie znaleziono tłumaczenia.');
            }

            if ((string)($translation['status'] ?? '') !== ArticleTranslation::STATUS_APPROVED) {
                throw new \RuntimeException('Tłumaczenie musi być najpierw zatwierdzone przez redakcję.');
            }

            $this->assertReadyForPublication($translation);
            $this->snapshotTranslation($translation, $publisherId);

            $db->query(
                'UPDATE article_translations
                 SET status=\'published\',
                     published_by=:published_by,
                     published_at=NOW(),
                     updated_by=:updated_by,
                     updated_at=NOW()
                 WHERE id=:id',
                [
                    'id' => $translationId,
                    'published_by' => $publisherId,
                    'updated_by' => $publisherId,
                ]
            );
        });
    }

    public function findById(int $translationId): ?array
    {
        return $this->db->one(
            'SELECT * FROM article_translations WHERE id=:id LIMIT 1',
            ['id' => $translationId]
        );
    }

    public function invalidateForArticle(int $articleId, ?int $changedBy, ?string $instructions = null): void
    {
        $this->db->transaction(function (Database $db) use ($articleId, $changedBy, $instructions): void {
            $translations = $db->all(
                'SELECT * FROM article_translations
                 WHERE article_id=:article_id
                 ORDER BY id
                 FOR UPDATE',
                ['article_id' => $articleId]
            );

            foreach ($translations as $translation) {
                $this->snapshotTranslation($translation, $changedBy);
            }
            if ($translations === []) {
                return;
            }

            $db->query(
                'UPDATE article_translations
                 SET status=:status,
                     translation_instructions=CASE
                         WHEN :replace_instructions=1 THEN :instructions
                         ELSE translation_instructions
                     END,
                     reviewed_by=NULL,
                     reviewed_at=NULL,
                     published_by=NULL,
                     published_at=NULL,
                     updated_by=:updated_by,
                     updated_at=NOW()
                 WHERE article_id=:article_id',
                [
                    'status' => ArticleTranslation::STATUS_DRAFT,
                    'replace_instructions' => $instructions !== null ? 1 : 0,
                    'instructions' => $instructions,
                    'updated_by' => $changedBy !== null && $changedBy > 0 ? $changedBy : null,
                    'article_id' => $articleId,
                ]
            );
        });
    }

    private function assertReadyForPublication(array $translation): void
    {
        $this->assertHasEditableContent($translation, 'publikacji');

        $slug = trim((string)($translation['slug'] ?? ''));
        if ($slug === '') {
            throw new \RuntimeException('Przed publikacją tłumaczenie musi mieć slug wersji językowej.');
        }
    }

    /**
     * @param array<string, mixed> $translation
     */
    private function snapshotTranslation(array $translation, ?int $changedBy): void
    {
        $translationId = (int)($translation['id'] ?? 0);
        if ($translationId <= 0) {
            throw new \RuntimeException('Nie można zapisać historii nieistniejącego tłumaczenia.');
        }

        $versionNo = (int)$this->db->cell(
            'SELECT COALESCE(MAX(version_no), 0) + 1
             FROM article_translation_versions
             WHERE translation_id=:translation_id',
            ['translation_id' => $translationId]
        );

        $this->db->query(
            'INSERT INTO article_translation_versions(
                translation_id, version_no, title, `lead`, body, seo_title,
                seo_description, seo_keywords, slug, status,
                translation_instructions, changed_by, created_at
             ) VALUES(
                :translation_id, :version_no, :title, :lead, :body, :seo_title,
                :seo_description, :seo_keywords, :slug, :status,
                :translation_instructions, :changed_by, NOW()
             )',
            [
                'translation_id' => $translationId,
                'version_no' => $versionNo,
                'title' => (string)($translation['title'] ?? ''),
                'lead' => $translation['lead'] ?? null,
                'body' => (string)($translation['body'] ?? ''),
                'seo_title' => $translation['seo_title'] ?? null,
                'seo_description' => $translation['seo_description'] ?? null,
                'seo_keywords' => $translation['seo_keywords'] ?? null,
                'slug' => $translation['slug'] ?? null,
                'status' => (string)($translation['status'] ?? ArticleTranslation::STATUS_DRAFT),
                'translation_instructions' => $translation['translation_instructions'] ?? null,
                'changed_by' => $changedBy !== null && $changedBy > 0 ? $changedBy : null,
            ]
        );
    }

    private function assertHasEditableContent(array $translation, string $context): void
    {
        $title = trim((string)($translation['title'] ?? ''));
        $body = trim((string)($translation['body'] ?? ''));

        if ($title === '' || $body === '') {
            throw new \RuntimeException('Przed ' . $context . ' tłumaczenie musi mieć co najmniej tytuł i treść.');
        }
    }

    private function sourceLanguageForArticle(int $articleId): string
    {
        if ($articleId <= 0) {
            throw new \InvalidArgumentException('Brak poprawnego artykułu.');
        }

        $sourceLanguage = $this->db->cell(
            'SELECT source_language FROM articles WHERE id=:id LIMIT 1',
            ['id' => $articleId]
        );
        if ($sourceLanguage === false || $sourceLanguage === null) {
            throw new \RuntimeException('Nie znaleziono artykułu.');
        }

        return $this->normalizeLanguage((string)$sourceLanguage);
    }

    private function languageOrder(string $column): string
    {
        if (!in_array($column, ['language', 't.language'], true)) {
            throw new \InvalidArgumentException('Nieprawidłowa kolumna języka.');
        }
        return "CASE {$column}
                    WHEN 'pl' THEN 1 WHEN 'en' THEN 2 WHEN 'de' THEN 3
                    WHEN 'fr' THEN 4 WHEN 'it' THEN 5 WHEN 'es' THEN 6
                    ELSE 99
                END";
    }
}

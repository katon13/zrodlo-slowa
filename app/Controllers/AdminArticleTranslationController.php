<?php
namespace App\Controllers;

use App\Services\AiFoundationService;
use App\Services\ArticleTranslationService;
use App\Services\OpenAiClient;
use App\Services\RoleService;
use App\Services\TranslationAiService;
use App\Services\TranslationPromptBuilder;
use App\Services\AiBackgroundJobService;
use App\Services\DurableJobQueue;
use App\Services\StructuredAuditService;
use App\Security\Authorization\PermissionCatalog;

final class AdminArticleTranslationController extends BaseController
{
    public function review(): never
    {
        $publisherId = $this->requireAdminOrRoles([RoleService::ROLE_PUBLISHER]);
        $articleId = (int)($_POST['article_id'] ?? 0);
        $translationId = (int)($_POST['translation_id'] ?? 0);
        $action = (string)($_POST['review_action'] ?? '');
        $language = '';
        $success = false;
        $message = '';
        $resultingStatus = '';

        try {
            if (!in_array($action, ['approve_publish', 'reject'], true)) {
                throw new \InvalidArgumentException('Nieprawidłowa decyzja Wydawcy.');
            }

            $service = new ArticleTranslationService($this->app->db, $this->app->config['languages'] ?? []);
            $translation = $service->findById($translationId);
            if (!$translation || (int)($translation['article_id'] ?? 0) !== $articleId) {
                throw new \RuntimeException('Nie znaleziono tłumaczenia przypisanego do tego artykułu.');
            }
            $language = strtolower((string)($translation['language'] ?? ''));

            if ($action === 'approve_publish') {
                $service->approveAndPublish($translationId, $publisherId);
                $message = 'Wydawca zaakceptował i opublikował tłumaczenie ' . strtoupper($language) . '.';
                $resultingStatus = 'published';
            } else {
                $service->reject($translationId, $publisherId);
                $message = 'Wydawca odrzucił tłumaczenie ' . strtoupper($language) . ' do poprawy.';
                $resultingStatus = 'rejected';
            }

            $this->slowoSnajper()->audit($publisherId, 'article_translation_publisher_review', [
                'article_id' => $articleId,
                'translation_id' => $translationId,
                'language' => $language,
                'decision' => $action,
            ]);
            $success = true;
        } catch (\Throwable $e) {
            $message = 'Nie udało się zapisać decyzji Wydawcy: ' . $e->getMessage();
        }

        if ($this->isAjax()) {
            if (!$success) {
                http_response_code(422);
            }
            $this->json([
                'success' => $success,
                'message' => $message,
                'article_id' => $articleId,
                'translation_id' => $translationId,
                'language' => $language,
                'status' => $resultingStatus,
                'review_action' => $action,
            ]);
        }

        $this->app->session->flash($success ? 'success' : 'error', $message);
        redirect('/admin/editorial/edit?id=' . $articleId . '&translation_lang=' . rawurlencode($language) . '#translation-' . rawurlencode($language));
    }

    public function save(): never
    {
        $adminId = $this->requireAdmin();
        $articleId = (int)($_POST['article_id'] ?? 0);
        $language = (string)($_POST['language'] ?? '');

        try {
            $service = new ArticleTranslationService($this->app->db, $this->app->config['languages'] ?? []);
            $service->saveFromEditor($articleId, $language, [
                'title' => trim((string)($_POST['title'] ?? '')),
                'lead' => trim((string)($_POST['lead'] ?? '')),
                'body' => trim((string)($_POST['body'] ?? '')),
                'seo_title' => trim((string)($_POST['seo_title'] ?? '')),
                'seo_description' => trim((string)($_POST['seo_description'] ?? '')),
                'seo_keywords' => trim((string)($_POST['seo_keywords'] ?? '')),
                'slug' => trim((string)($_POST['slug'] ?? '')),
                'status' => (string)($_POST['status'] ?? 'draft'),
                'translation_instructions' => trim((string)($_POST['translation_instructions'] ?? '')),
            ], $adminId);

            $this->slowoSnajper()->audit($adminId, 'article_translation_save', [
                'article_id' => $articleId,
                'language' => $language,
                'status' => (string)($_POST['status'] ?? 'draft'),
            ]);
            $this->app->session->flash('success', 'Tłumaczenie artykułu zostało zapisane.');
        } catch (\Throwable $e) {
            $this->app->session->flash('error', 'Nie udało się zapisać tłumaczenia: ' . $e->getMessage());
        }

        redirect('/admin/articles?translation_article=' . $articleId . '&translation_lang=' . rawurlencode($language) . '#translation-' . $articleId);
    }

    public function generateAiPackage(): string
    {
        $adminId = $this->requirePermission(PermissionCatalog::AI_TRANSLATION_RUN);
        $articleId = (int)($_POST['article_id'] ?? 0);
        $instructions = trim((string)($_POST['translation_instructions'] ?? ''));
        $languageConfig = $this->app->config['languages'] ?? [];
        $returnUrl = '/admin/role-panel?panel=moderator#article-' . $articleId;

        try {
            /** ETAP 14: controller pobiera konkretny tekst i ustala kontekst pakietu AI. */
            $article = $this->articleForAiPackage($articleId);
            $sourceLanguage = $this->sourceLanguageForAiPackage($article, $languageConfig);
            $instructions = $this->translationInstructionsForAiPackage($articleId, $sourceLanguage, $instructions);
            $targetLanguages = $this->targetLanguagesForAiPackage($sourceLanguage, $languageConfig);
            if ($targetLanguages === []) {
                throw new \RuntimeException('Brak języków docelowych do tłumaczenia.');
            }

            $sourceHash = hash('sha256', json_encode($article, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            $job = (new AiBackgroundJobService(
                new DurableJobQueue($this->app->db, $this->app->queueSignals),
                new StructuredAuditService($this->app->db),
            ))->queueArticleTranslation(
                $adminId,
                PermissionCatalog::AI_TRANSLATION_RUN,
                $articleId,
                $targetLanguages,
                $instructions,
                $sourceHash,
            );
            $this->slowoSnajper()->audit($adminId, 'article_translation_ai_package.queued', [
                'article_id' => $articleId,
                'source_language' => $sourceLanguage,
                'target_languages' => $targetLanguages,
                'background_job_id' => (int)$job['id'],
            ]);

            return $this->aiPackageResponse(true, 202, [
                'message' => 'Tłumaczenie zostało dodane do izolowanej kolejki AI.',
                'article_id' => $articleId,
                'source_language' => $sourceLanguage,
                'target_languages' => $targetLanguages,
                'background_job_id' => (int)$job['id'],
                'background_job_public_id' => (string)$job['public_id'],
                'redirect_url' => $returnUrl,
            ]);
        } catch (\Throwable $e) {
            return $this->aiPackageResponse(false, 422, [
                'message' => 'Błąd tłumaczenia AI: ' . $e->getMessage(),
                'article_id' => $articleId,
                'redirect_url' => $returnUrl,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function articleForAiPackage(int $articleId): array
    {
        if ($articleId <= 0) {
            throw new \InvalidArgumentException('Brak poprawnego article_id.');
        }
        $article = $this->app->db->one(
            'SELECT `id`,`title`,`lead`,`body`,`source_language`,`updated_at` FROM `articles` WHERE `id`=:id LIMIT 1',
            ['id' => $articleId]
        );
        if (!$article) {
            throw new \RuntimeException('Nie znaleziono artykułu.');
        }
        return $article;
    }

    /**
     * @param array<string, mixed> $article
     * @param array<string, mixed> $languageConfig
     */
    private function sourceLanguageForAiPackage(array $article, array $languageConfig): string
    {
        $sourceLanguage = strtolower(trim((string)($article['source_language'] ?? '')));
        $enabled = $this->publicEnabledLanguagesForAiPackage($languageConfig);
        if ($sourceLanguage === '' || !in_array($sourceLanguage, $enabled, true)) {
            return 'pl';
        }
        return $sourceLanguage;
    }


    private function translationInstructionsForAiPackage(int $articleId, string $sourceLanguage, string $postedInstructions): string
    {
        $postedInstructions = trim($postedInstructions);
        if ($postedInstructions !== '') {
            return $postedInstructions;
        }

        try {
            $articleInstruction = $this->app->db->cell(
                'SELECT translation_instructions
                 FROM article_translations
                 WHERE article_id=:article_id
                   AND translation_instructions IS NOT NULL
                   AND TRIM(translation_instructions) <> \'\'
                 ORDER BY CASE WHEN language=:source_language_1 THEN 0 WHEN source_language=:source_language_2 THEN 1 ELSE 2 END, updated_at DESC, id DESC
                 LIMIT 1',
                [
                    'article_id' => $articleId,
                    'source_language_1' => $sourceLanguage,
                    'source_language_2' => $sourceLanguage,
                ]
            );
            $articleInstruction = trim((string)$articleInstruction);
            if ($articleInstruction !== '') {
                return $articleInstruction;
            }
        } catch (\Throwable) {}

        $settings = (new AiFoundationService($this->app->db))->settings();
        return trim((string)($settings['ai.translation.default_instruction'] ?? ''));
    }

    /**
     * @param array<string, mixed> $languageConfig
     * @return array<int, string>
     */
    private function targetLanguagesForAiPackage(string $sourceLanguage, array $languageConfig): array
    {
        return array_values(array_filter(
            $this->publicEnabledLanguagesForAiPackage($languageConfig),
            static fn(string $language): bool => $language !== $sourceLanguage
        ));
    }

    /**
     * @param array<string, mixed> $languageConfig
     * @return array<int, string>
     */
    private function publicEnabledLanguagesForAiPackage(array $languageConfig): array
    {
        $enabled = $languageConfig['public_enabled'] ?? ['pl', 'en', 'de', 'fr', 'it', 'es'];
        if (!is_array($enabled)) {
            $enabled = ['pl', 'en', 'de', 'fr', 'it', 'es'];
        }

        $enabled = array_values(array_unique(array_filter(
            array_map(static fn($language): string => strtolower(trim((string)$language)), $enabled),
            static fn(string $language): bool => $language !== ''
        )));

        return $enabled !== [] ? $enabled : ['pl', 'en', 'de', 'fr', 'it', 'es'];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function aiPackageResponse(bool $success, int $statusCode, array $payload): string
    {
        if (!$success) {
            http_response_code($statusCode);
        }

        if ($this->expectsJsonResponse()) {
            header('Content-Type: application/json');
            $payload['success'] = $success;
            return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"success":false,"message":"Błąd odpowiedzi JSON."}';
        }

        $this->app->session->flash($success ? 'success' : 'error', (string)($payload['message'] ?? ''));
        redirect((string)($payload['redirect_url'] ?? '/admin/role-panel?panel=moderator'));
    }

    private function expectsJsonResponse(): bool
    {
        $requestedWith = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        return $requestedWith === 'xmlhttprequest' || str_contains($accept, 'application/json');
    }



}

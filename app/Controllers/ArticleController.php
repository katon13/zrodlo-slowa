<?php
namespace App\Controllers;

use App\Services\ArticleService;
use App\Services\SupportService;
use App\Services\ArticleEconomyService;
use App\Services\ArticleTranslationService;
use App\Services\ArticleSeoService;

final class ArticleController extends BaseController
{
    public function index(): string
    {
        $cat = $_GET['cat'] ?? null;
        $service = new ArticleService($this->app->db);
        $limit = $this->slowoSnajperConfig()->limit('public_articles', 20, 100);
        if ($cat) {
            $articles = $service->publishedByCategory($cat, $limit);
        } else {
            $articles = $service->published($limit);
        }
        $articles = $this->applyPublicLanguageToArticleList($articles);
        return $this->view('articles/index', [
            'title' => (function_exists('t') ? t('articles.index.title') : 'Teksty'),
            'articles' => $articles
        ]);
    }

    public function show(): string
    {
        $service = new ArticleService($this->app->db);
        $languages = $this->loadLanguageConfig();
        $translationService = new ArticleTranslationService($this->app->db, $languages);
        $currentLanguage = function_exists('public_language') ? public_language() : (string)($languages['default'] ?? 'pl');

        $seoSlug = trim((string)($_GET['seo_slug'] ?? ''));
        $requestedLanguage = $translationService->normalizeLanguage((string)($_GET['lang'] ?? $currentLanguage));

        $articleId = (int)($_GET['id'] ?? 0);
        $article = null;
        $isPrivatePreview = false;
        if ($seoSlug !== '') {
            $article = $this->findPublishedArticleByTranslationSlug($seoSlug, $requestedLanguage)
                ?? $this->findPublishedArticleByGeneratedTranslationSlug($seoSlug, $requestedLanguage)
                ?? $this->findPublishedArticleByBaseSlug($seoSlug);
            $articleId = (int)($article['id'] ?? 0);
        } elseif ($articleId > 0) {
            $article = $service->findPublished($articleId);
            if (!$article) {
                $candidate = $service->findAnyWithAuthor($articleId);
                if ($candidate && $this->canPreviewPrivateArticle($candidate)) {
                    $article = $candidate;
                    $isPrivatePreview = true;
                }
            }
        }

        if (!$article) {
            http_response_code(404);
            return $this->view('layouts/error', ['title' => '404', 'message' => 'Nie znaleziono tekstu.']);
        }

        $previewLanguage = $translationService->normalizeLanguage((string)($_GET['preview_lang'] ?? ''));
        $hasPreviewLanguage = isset($_GET['preview_lang']) && trim((string)$_GET['preview_lang']) !== '';
        $canPreviewDraftTranslation = $hasPreviewLanguage && $this->canPreviewDraftTranslation();
        $requestedLanguage = $canPreviewDraftTranslation
            ? $previewLanguage
            : $translationService->normalizeLanguage((string)($_GET['lang'] ?? $requestedLanguage));

        $sourceArticle = $article;
        $sourceLanguage = $translationService->normalizeLanguage((string)($sourceArticle['source_language'] ?? ($languages['default'] ?? 'pl')));
        $publishedTranslation = $translationService->findPublished($articleId, $requestedLanguage);
        $publishedLanguageMap = $translationService->publishedLanguageMap($articleId);
        $editorTranslationMap = $canPreviewDraftTranslation ? $translationService->languageMapForEditor($articleId) : [];
        $availableLanguages = $canPreviewDraftTranslation
            ? array_values(array_unique(array_merge([$sourceLanguage], array_keys($publishedLanguageMap), array_keys($editorTranslationMap))))
            : array_values(array_unique(array_merge([$sourceLanguage], array_keys($publishedLanguageMap))));
        $displayLanguage = $sourceLanguage;
        $translationFallback = false;
        $previewTranslation = null;

        if ($requestedLanguage !== $sourceLanguage) {
            if ($canPreviewDraftTranslation) {
                $previewTranslation = $translationService->findForEditor($articleId, $requestedLanguage);
                if ($previewTranslation === null) {
                    http_response_code(404);
                    return $this->view('layouts/error', [
                        'title' => 'Brak wersji językowej',
                        'message' => 'Nie znaleziono zapisanej wersji językowej: ' . strtoupper($requestedLanguage) . '.',
                    ]);
                }
                $article = $this->applyPublishedTranslation($article, $previewTranslation);
                $displayLanguage = $requestedLanguage;
            } elseif ($publishedTranslation !== null) {
                $article = $this->applyPublishedTranslation($article, $publishedTranslation);
                $displayLanguage = $requestedLanguage;
            } else {
                http_response_code(404);
                return $this->view('articles/translation_unavailable', [
                    'title' => function_exists('t') ? t('article.translation.unavailable.title', $requestedLanguage) : 'Tłumaczenie niedostępne',
                    'article' => $sourceArticle,
                    'requested_article_language' => $requestedLanguage,
                    'display_article_language' => $requestedLanguage,
                    'article_language_versions' => $availableLanguages,
                    'article_language_map' => $publishedLanguageMap,
                    'source_article' => $sourceArticle,
                ]);
            }
        }

        $ipHash = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? 'cli') . env('APP_KEY', 'local'));
        $userId = $this->app->session->userId();
        if (!$isPrivatePreview) {
            $service->recordRead($articleId, $userId, $ipHash);
        }

        $grant = $isPrivatePreview
            ? ['status' => 'active', 'source' => 'private_preview', 'expires_at' => null]
            : $service->getAccessGrant($userId, $articleId);
        $seo = new ArticleSeoService($this->app->db, $languages, $this->app->config['sites'] ?? []);
        $seoMeta = $seo->buildArticleMeta($sourceArticle, $article, $displayLanguage, $publishedLanguageMap);
        $articleReadProof = !$isPrivatePreview && $userId !== null && $grant !== null
            ? $this->articleReadProofService()->start($userId, $articleId)
            : null;

        return $this->view('articles/show', [
            'title' => $seoMeta['title'] ?: $article['title'],
            'seo_meta' => $seoMeta,
            'article' => $article,
            'source_article' => $sourceArticle,
            'article_translation' => $previewTranslation ?? $publishedTranslation,
            'article_language_versions' => $availableLanguages,
            'article_language_map' => $publishedLanguageMap,
            'requested_article_language' => $requestedLanguage,
            'display_article_language' => $displayLanguage,
            'article_translation_fallback' => $translationFallback,
            'is_private_preview' => $isPrivatePreview,
            'media' => $service->getMedia($articleId, $this->slowoSnajperConfig()->limit('article_media', 12, 50)),
            'has_access' => $grant !== null,
            'access_grant' => $grant,
            'article_read_proof' => $articleReadProof,
            'flash_success' => $this->app->session->pullFlash('success'),
            'flash_error' => $this->app->session->pullFlash('error')
        ]);
    }

    private function canPreviewPrivateArticle(array $article): bool
    {
        $userId = $this->app->session->userId();
        if (!$userId) {
            return false;
        }
        if ((int)($article['author_id'] ?? 0) === (int)$userId) {
            return true;
        }
        $roles = $this->currentUserRoles();
        if (in_array('admin', $roles, true)) {
            return true;
        }
        return array_intersect($roles, [
            'moderator',
            'editor',
            'publisher',
            'proofreader',
            'redaktor_naczelny',
            'edytor',
            'wydawca',
            'korektor',
        ]) !== [];
    }

    /**
     * @param array<int, array<string, mixed>> $articles
     * @return array<int, array<string, mixed>>
     */
    private function applyPublicLanguageToArticleList(array $articles): array
    {
        $languages = $this->loadLanguageConfig();
        $currentLanguage = function_exists('public_language') ? public_language() : (string)($languages['default'] ?? 'pl');
        $translationService = new ArticleTranslationService($this->app->db, $languages);
        $language = $translationService->normalizeLanguage((string)$currentLanguage);
        if ($articles === []) {
            return $articles;
        }

        $articleIds = array_values(array_unique(array_map(static fn(array $article): int => (int)($article['id'] ?? 0), $articles)));
        $translationsMap = $translationService->mapForArticles($articleIds);
        $filtered = [];
        foreach ($articles as $article) {
            $articleId = (int)($article['id'] ?? 0);
            $sourceLanguage = $translationService->normalizeLanguage((string)($article['source_language'] ?? ($languages['default'] ?? 'pl')));
            if ($language === $sourceLanguage) {
                $article['display_language'] = $sourceLanguage;
                $article['translation_available'] = true;
                $filtered[] = $article;
                continue;
            }

            $translation = $translationsMap[$articleId][$language] ?? null;
            if (!is_array($translation)) {
                continue;
            }
            $title = trim((string)($translation['title'] ?? ''));
            $lead = trim((string)($translation['lead'] ?? ''));
            $body = trim((string)($translation['body'] ?? ''));
            $slug = trim((string)($translation['slug'] ?? ''));
            if ($title === '' || $body === '' || $slug === '') {
                continue;
            }
            $article['title'] = $title;
            $article['lead'] = $lead !== '' ? $lead : ($article['lead'] ?? null);
            $article['body'] = $body;
            $article['slug'] = $slug;
            $article['display_language'] = $language;
            $article['translation_available'] = true;
            $filtered[] = $article;
        }

        return $filtered;
    }

    public function support(): never
    {
        $readerId = $this->requireAuth();
        $articleId = (int)($_POST['article_id'] ?? 0);
        try {
            (new SupportService($this->app->db, $this->notificationOutboxDispatcher()))
                ->supportArticle($readerId, $articleId, (int)$_POST['amount_minor'], trim($_POST['note'] ?? ''));
            $message = t('article.support.success');
            if ($this->isAjax()) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => $message]);
                exit;
            }
            $this->app->session->flash('success', $message);
        } catch (\Throwable $e) {
            $message = $this->safeError($e, t('article.support.error'), 'article_support');
            if ($this->isAjax()) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $message]);
                exit;
            }
            $this->app->session->flash('error', $message);
        }
        redirect('/article?id=' . $articleId);
    }

    public function buy(): never
    {
        $userId = $this->requireAuth();
        $articleId = (int)($_POST['article_id'] ?? 0);
        try {
            (new ArticleEconomyService($this->app->db, $this->notificationOutboxDispatcher()))
                ->purchaseWithWallet($userId, $articleId);
            $this->app->session->flash('success', 'Dostęp został wykupiony. Autor otrzymał 70%, a 30% zostało zaksięgowane dla serwisu.');
        } catch (\Throwable $e) {
            $this->app->session->flash('error', $this->safeError($e, 'Nie udało się wykupić dostępu.', 'article_purchase'));
        }
        redirect('/article?id=' . $articleId);
    }

    private function findPublishedArticleByBaseSlug(string $slug): ?array
    {
        return $this->app->db->one(
            'SELECT a.*, u.display_name AS author_name, u.avatar_path AS author_avatar_path, u.avatar_updated_at AS author_avatar_updated_at
             FROM articles a
             JOIN users u ON u.id=a.author_id
             WHERE a.slug=:slug AND a.status=\'published\'
             LIMIT 1',
            ['slug' => $slug]
        );
    }

    private function findPublishedArticleByTranslationSlug(string $slug, string $language): ?array
    {
        return $this->app->db->one(
            'SELECT a.*, u.display_name AS author_name, u.avatar_path AS author_avatar_path, u.avatar_updated_at AS author_avatar_updated_at
             FROM article_translations t
             JOIN articles a ON a.id=t.article_id
             JOIN users u ON u.id=a.author_id
             WHERE t.language=:language AND t.slug=:slug
               AND t.status=\'published\' AND a.status=\'published\'
             LIMIT 1',
            ['language' => $language, 'slug' => $slug]
        );
    }

    private function findPublishedArticleByGeneratedTranslationSlug(string $slug, string $language): ?array
    {
        $rows = $this->app->db->all(
            'SELECT a.*, t.title AS translation_title, u.display_name AS author_name, u.avatar_path AS author_avatar_path, u.avatar_updated_at AS author_avatar_updated_at
             FROM article_translations t
             JOIN articles a ON a.id=t.article_id
             JOIN users u ON u.id=a.author_id
             WHERE t.language=:language
               AND t.status=\'published\' AND a.status=\'published\'
               AND (t.slug IS NULL OR t.slug=\'\') AND t.title IS NOT NULL AND t.title<>\'\'
             ORDER BY a.published_at DESC, a.id DESC
             LIMIT 300',
            ['language' => $language]
        );
        if ($rows === []) {
            return null;
        }
        $slugger = \App\Services\SeoSlugService::fromConfigFile($this->app->db, $this->app->rootPath);
        foreach ($rows as $row) {
            if ($slugger->slugify((string)($row['translation_title'] ?? '')) === $slug) {
                unset($row['translation_title']);
                return $row;
            }
        }
        return null;
    }

    private function canPreviewDraftTranslation(): bool
    {
        if (!$this->app->session->userId()) {
            return false;
        }

        $roles = $this->currentUserRoles();
        $allowed = ['admin', 'publisher', 'editor', 'moderator', 'chief_editor', 'proofreader'];
        return array_intersect($allowed, $roles) !== [];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadLanguageConfig(): array
    {
        $path = dirname(__DIR__, 2) . '/config/languages.php';
        $config = is_file($path) ? require $path : [];
        return is_array($config) ? $config : [];
    }

    /**
     * @param array<string, mixed> $article
     * @param array<string, mixed> $translation
     * @return array<string, mixed>
     */
    private function applyPublishedTranslation(array $article, array $translation): array
    {
        foreach (['title', 'lead', 'body', 'seo_title', 'seo_description', 'seo_keywords', 'slug'] as $field) {
            if (array_key_exists($field, $translation) && $translation[$field] !== null && $translation[$field] !== '') {
                $article[$field] = $translation[$field];
            }
        }

        $article['_translation_id'] = (int)($translation['id'] ?? 0);
        $article['_translation_language'] = (string)($translation['language'] ?? '');
        $article['_is_translated'] = true;

        return $article;
    }
}

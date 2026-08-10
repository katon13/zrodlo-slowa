<?php
namespace App\Controllers;

use App\Services\ArticleService;
use App\Services\EconomyMapService;
use App\Services\ArticleTranslationService;
use App\Services\MainBannerService;
use App\Services\CampaignService;
use App\Services\FraudGuardService;

final class HomeController extends BaseController
{
    public function index(): string
    {
        $limit = min(6, $this->slowoSnajperConfig()->limit('public_articles', 20, 100));
        $articleService = new ArticleService($this->app->db);
        $currentLanguage = function_exists('public_language') ? public_language() : (string)($this->app->config['languages']['default'] ?? 'pl');

        $featuredArticle = $this->loadFeaturedArticle();
        $featuredList = $featuredArticle ? $this->applyPublicLanguageToArticleList([$featuredArticle]) : [];
        $featuredArticle = $featuredList[0] ?? null;

        $articles = $articleService->published($limit + 1);
        if ($featuredArticle) {
            $featuredId = (int)($featuredArticle['id'] ?? 0);
            $articles = array_values(array_filter($articles, static fn(array $article): bool => (int)($article['id'] ?? 0) !== $featuredId));
        }
        $articles = array_slice($articles, 0, $limit);
        $articles = $this->applyPublicLanguageToArticleList($articles);

        $flows = (new EconomyMapService($this->app->db))->publicFlows();
        $mainBanner = $this->app->cache->remember("main_banner_public:{$currentLanguage}", 3600, function() use ($currentLanguage) {
            return (new MainBannerService($this->app->db))->activeForPublic($currentLanguage);
        });
        return $this->view('articles/index', [
            'title' => (function_exists('t') ? t('brand.name') : t('brand.name')),
            'articles' => $articles,
            'is_homepage' => true,
            'money_flows' => $flows,
            'main_banner' => $mainBanner,
            'featured_article' => $featuredArticle,
            'placement_campaigns' => (new CampaignService(
                $this->app->db,
                $this->talentService(),
                new FraudGuardService($this->app->db, $this->slowoSnajperConfig()),
            ))->activeForPlacement('home'),
        ]);
    }


    /**
     * Pierwszy artykuł wyróżniający dla strony głównej.
     * Źródłem pozostaje tabela articles — nie mieszamy tego z Banerem Głównym.
     *
     * @return array<string, mixed>|null
     */
    private function loadFeaturedArticle(): ?array
    {
        return $this->app->db->one('SELECT a.id,a.author_id,a.title,a.slug,a.`lead`,a.body,a.status,a.published_at,a.updated_at,a.access_mode,a.price_minor,a.currency,a.is_premium,a.is_unique,a.is_featured,a.display_order,a.editorial_weight,u.display_name AS author_name, u.avatar_path AS author_avatar_path, u.avatar_updated_at AS author_avatar_updated_at, (SELECT path FROM media WHERE article_id=a.id ORDER BY id DESC LIMIT 1) as main_image, (SELECT image_position FROM media WHERE article_id=a.id ORDER BY id DESC LIMIT 1) as main_image_position FROM articles a JOIN users u ON u.id=a.author_id WHERE a.status=\'published\' AND a.response_to_article_id IS NULL AND a.is_featured=1 ORDER BY a.display_order ASC, a.editorial_weight DESC, a.published_at DESC, a.id DESC LIMIT 1');
    }

    /**
     * @param array<int, array<string, mixed>> $articles
     * @return array<int, array<string, mixed>>
     */
    private function applyPublicLanguageToArticleList(array $articles): array
    {
        $languages = $this->app->config['languages'] ?? [];
        $currentLanguage = function_exists('public_language') ? public_language() : (string)($languages['default'] ?? 'pl');
        $translationService = new ArticleTranslationService($this->app->db, $languages);
        $language = $translationService->normalizeLanguage((string)$currentLanguage);
        if ($language === 'pl' || $articles === []) {
            return $articles;
        }

        $articleIds = array_values(array_unique(array_map(static fn(array $article): int => (int)($article['id'] ?? 0), $articles)));
        $translationsMap = $translationService->mapForArticles($articleIds);
        $filtered = [];
        foreach ($articles as $article) {
            $articleId = (int)($article['id'] ?? 0);
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

    public function economy(): string
    {
        $language = public_language();
        $policy = (new \App\Services\SafetyFundService($this->app->db))->currentPolicy();
        $flows = (new EconomyMapService($this->app->db))->publicFlows($language);
        $referralService = $this->appReferralService();
        $promotion = $referralService->currentPromotion();
        $userId = $this->app->session->userId();
        if ($promotion !== null && $userId !== null) {
            $overview = $referralService->userOverview($userId);
            if (($overview['pool_exhausted'] ?? false) === true) {
                $promotion = null;
            }
        }
        return $this->view('economy/show', [
            'title' => t('economy.page_title', $language),
            'money_flows' => $flows,
            'split_policy' => $policy,
            'talent_promotion' => $promotion,
        ]);
    }
}

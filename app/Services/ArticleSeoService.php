<?php
namespace App\Services;

use App\Core\Database;

final class ArticleSeoService
{
    /** @var array<string, mixed> */
    private array $seoConfig;

    public function __construct(
        private readonly Database $db,
        private readonly array $languageConfig = [],
        private readonly array $siteConfig = []
    ) {
        $this->seoConfig = $this->loadSeoConfig();
    }

    /**
     * @param array<string, mixed> $sourceArticle
     * @param array<string, mixed> $displayArticle
     * @param array<string, array<string, mixed>> $translations
     * @return array<string, mixed>
     */
    public function buildArticleMeta(array $sourceArticle, array $displayArticle, string $displayLanguage, array $translations): array
    {
        $articleId = (int)($sourceArticle['id'] ?? $displayArticle['id'] ?? 0);
        $displayLanguage = $this->normalizeLanguage($displayLanguage);

        $title = trim((string)($displayArticle['title'] ?? ''));
        $description = $this->excerpt((string)($displayArticle['lead'] ?? ''), 155);
        if ($description === '') {
            $description = $this->excerpt((string)($displayArticle['body'] ?? ''), 155);
        }

        $canonicalSlug = (string)($displayArticle['slug'] ?? ($sourceArticle['slug'] ?? ''));
        $canonical = $this->articleUrl($articleId, $displayLanguage, $canonicalSlug);

        $alternates = [];
        $sourceLanguage = $this->normalizeLanguage((string)($sourceArticle['source_language'] ?? ($this->languageConfig['default'] ?? 'pl')));
        $sourceSlug = trim((string)($sourceArticle['slug'] ?? ''));
        if ($sourceSlug !== '') {
            $alternates[$sourceLanguage] = $this->articleUrl($articleId, $sourceLanguage, $sourceSlug);
        }
        foreach ($translations as $language => $translation) {
            $language = $this->normalizeLanguage((string)$language);
            if ($language === $sourceLanguage) {
                continue;
            }
            $slug = trim((string)($translation['slug'] ?? ''));
            if ($slug === '') {
                continue;
            }
            $alternates[$language] = $this->articleUrl($articleId, $language, $slug);
        }
        if (isset($alternates[$sourceLanguage])) {
            $alternates['x-default'] = $alternates[$sourceLanguage];
        }

        $image = $this->articleImageUrl($articleId);
        $jsonLd = [
            '@context' => 'https://schema.org',
            '@type' => $this->schemaType($displayLanguage),
            'headline' => $title,
            'description' => $description,
            'author' => [
                '@type' => 'Person',
                'name' => (string)($sourceArticle['author_name'] ?? $displayArticle['author_name'] ?? 'Autor'),
            ],
            'publisher' => [
                '@type' => 'Organization',
                'name' => 'ŹRÓDŁO SŁOWA',
            ],
            'datePublished' => $this->isoDate((string)($sourceArticle['published_at'] ?? $sourceArticle['created_at'] ?? '')),
            'dateModified' => $this->isoDate((string)($sourceArticle['updated_at'] ?? $sourceArticle['published_at'] ?? '')),
            'inLanguage' => $displayLanguage,
            'mainEntityOfPage' => $canonical,
        ];
        if ($image !== '') {
            $jsonLd['image'] = $image;
        }

        return [
            'title' => $title,
            'description' => $description,
            'keywords' => '',
            'canonical' => $canonical,
            'alternates' => $alternates,
            'robots' => 'index,follow',
            'language' => $displayLanguage,
            'json_ld' => $jsonLd,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function sitemapItems(): array
    {
        $articles = $this->db->all(
            'SELECT a.id, a.title, a.slug, a.source_language, a.updated_at, a.published_at
             FROM articles a
             WHERE a.status=\'published\' AND a.slug IS NOT NULL AND a.slug<>\'\'
             ORDER BY a.published_at DESC, a.id DESC'
        );
        if ($articles === []) {
            return [];
        }

        $ids = array_map(static fn(array $row): int => (int)$row['id'], $articles);
        $translations = $this->translationsForArticles($ids);

        $items = [];
        foreach ($articles as $article) {
            $articleId = (int)$article['id'];
            $sourceLanguage = $this->normalizeLanguage((string)($article['source_language'] ?? ($this->languageConfig['default'] ?? 'pl')));
            $lastmod = $this->dateOnly((string)($article['updated_at'] ?? $article['published_at'] ?? ''));
            $hreflang = $this->sitemapAlternates($articleId, $article, $translations[$articleId] ?? []);

            $items[] = [
                'loc' => $this->articleUrl($articleId, $sourceLanguage, (string)$article['slug']),
                'lastmod' => $lastmod,
                'hreflang' => $hreflang,
            ];

            foreach (($translations[$articleId] ?? []) as $language => $translation) {
                $slug = trim((string)($translation['slug'] ?? ''));
                if ($slug === '') {
                    continue;
                }
                $items[] = [
                    'loc' => $this->articleUrl($articleId, (string)$language, $slug),
                    'lastmod' => $this->dateOnly((string)($translation['updated_at'] ?? $article['updated_at'] ?? '')),
                    'hreflang' => $hreflang,
                ];
            }
        }

        return $items;
    }

    public function articleUrl(int $articleId, string $language, string $slug = ''): string
    {
        $language = $this->normalizeLanguage($language);
        $slug = trim($slug);
        if ($slug === '') {
            $path = '/article?id=' . $articleId . '&lang=' . rawurlencode($language);
        } elseif ($this->shortArticleUrlsEnabled()) {
            $path = '/' . rawurlencode($slug);
        } else {
            $path = '/' . $this->articlePath($language) . '/' . rawurlencode($slug);
        }

        return $this->absoluteLanguageUrl($language, $path);
    }

    public function articlePath(string $language): string
    {
        $language = $this->normalizeLanguage($language);
        $path = (string)($this->seoConfig['languages'][$language]['article_path'] ?? ($language === 'pl' ? 'artykul' : 'article'));
        $path = trim($path, '/');
        return $path !== '' ? $path : 'article';
    }

    private function shortArticleUrlsEnabled(): bool
    {
        return (bool)($this->seoConfig['seo']['short_article_urls'] ?? true);
    }


    private function canonicalScheme(): string
    {
        $scheme = strtolower(trim((string)($this->seoConfig['seo']['canonical_scheme'] ?? 'https')));
        return in_array($scheme, ['http', 'https'], true) ? $scheme : 'https';
    }

    private function absoluteLanguageUrl(string $language, string $path): string
    {
        $language = $this->normalizeLanguage($language);
        $scheme = $this->canonicalScheme();

        if (class_exists('\App\Services\PublicSiteResolver')) {
            $resolver = new \App\Services\PublicSiteResolver($this->siteConfig, $this->languageConfig);
            return $resolver->languageUrl($language, $path, $scheme);
        }

        if (function_exists('public_language_url')) {
            $previousHttps = $_SERVER['HTTPS'] ?? null;
            $_SERVER['HTTPS'] = $scheme === 'https' ? 'on' : 'off';
            $url = public_language_url($language, $path);
            if ($previousHttps === null) {
                unset($_SERVER['HTTPS']);
            } else {
                $_SERVER['HTTPS'] = $previousHttps;
            }
            return preg_replace('~^https?://~i', $scheme . '://', $url) ?? $url;
        }

        return $path;
    }

    /** @param array<int, int> $articleIds @return array<int, array<string, array<string, mixed>>> */
    private function translationsForArticles(array $articleIds): array
    {
        $articleIds = array_values(array_filter(array_unique(array_map('intval', $articleIds)), static fn(int $id): bool => $id > 0));
        if ($articleIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($articleIds), '?'));
        $rows = $this->db->all(
            'SELECT t.article_id, t.language, t.title, t.slug, t.updated_at
             FROM article_translations t
             JOIN articles a ON a.id=t.article_id
             WHERE t.article_id IN (' . $placeholders . ')
               AND t.status=\'published\'
               AND t.language<>a.source_language
               AND t.title IS NOT NULL AND t.title<>\'\'
               AND t.body IS NOT NULL AND t.body<>\'\'
             ORDER BY t.article_id,
                      CASE t.language
                          WHEN \'pl\' THEN 1 WHEN \'en\' THEN 2 WHEN \'de\' THEN 3
                          WHEN \'fr\' THEN 4 WHEN \'it\' THEN 5 WHEN \'es\' THEN 6
                          ELSE 99
                      END,
                      t.language',
            $articleIds
        );

        $map = [];
        foreach ($rows as $row) {
            $articleId = (int)($row['article_id'] ?? 0);
            $language = $this->normalizeLanguage((string)($row['language'] ?? ''));
            if ($articleId > 0) {
                $map[$articleId][$language] = $row;
            }
        }
        return $map;
    }

    /** @param array<string, mixed> $article @param array<string, array<string, mixed>> $translations @return array<string, string> */
    private function sitemapAlternates(int $articleId, array $article, array $translations): array
    {
        $alternates = [];
        $sourceLanguage = $this->normalizeLanguage((string)($article['source_language'] ?? ($this->languageConfig['default'] ?? 'pl')));
        $sourceSlug = trim((string)($article['slug'] ?? ''));
        if ($sourceSlug !== '') {
            $alternates[$sourceLanguage] = $this->articleUrl($articleId, $sourceLanguage, $sourceSlug);
        }
        foreach ($translations as $language => $translation) {
            $slug = trim((string)($translation['slug'] ?? ''));
            if ($slug !== '') {
                $alternates[(string)$language] = $this->articleUrl($articleId, (string)$language, $slug);
            }
        }
        if (isset($alternates[$sourceLanguage])) {
            $alternates['x-default'] = $alternates[$sourceLanguage];
        }
        return $alternates;
    }

    private function articleImageUrl(int $articleId): string
    {
        $path = (string)($this->db->cell('SELECT path FROM media WHERE article_id=:id ORDER BY id DESC LIMIT 1', ['id' => $articleId]) ?? '');
        if ($path === '') {
            return '';
        }
        if (preg_match('~^https?://~i', $path)) {
            return $path;
        }
        $path = '/' . ltrim($path, '/');
        return ApplicationUrl::absolute($path);
    }

    /** @return array<string, mixed> */
    private function loadSeoConfig(): array
    {
        $path = dirname(__DIR__, 2) . '/config/seo_languages.json';
        if (is_file($path)) {
            $content = @file_get_contents($path);
            $decoded = is_string($content) ? json_decode($content, true) : null;
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }

    private function schemaType(string $language): string
    {
        $language = $this->normalizeLanguage($language);
        return (string)($this->seoConfig['languages'][$language]['schema_type'] ?? 'NewsArticle');
    }

    private function normalizeLanguage(string $language): string
    {
        $language = strtolower(trim($language));
        $enabled = $this->languageConfig['public_enabled'] ?? ['pl', 'en', 'de', 'fr', 'it', 'es'];
        return in_array($language, $enabled, true) ? $language : (string)($this->languageConfig['default'] ?? 'pl');
    }

    private function excerpt(string $text, int $limit): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');
        if (mb_strlen($text, 'UTF-8') <= $limit) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, $limit - 1, 'UTF-8')) . '…';
    }

    private function dateOnly(string $value): string
    {
        $time = strtotime($value);
        return $time ? date('Y-m-d', $time) : date('Y-m-d');
    }

    private function isoDate(string $value): string
    {
        $time = strtotime($value);
        return $time ? date('c', $time) : date('c');
    }
}

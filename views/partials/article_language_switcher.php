<?php
$currentLanguage = strtolower((string)($display_article_language ?? $current_language ?? (function_exists('public_language') ? public_language() : 'pl')));
$requestedLanguage = strtolower((string)($requested_article_language ?? $currentLanguage));
$languageMap = $article_language_map ?? [];
$sourceArticle = $source_article ?? $article ?? [];
$sourceLanguage = strtolower((string)($sourceArticle['source_language'] ?? 'pl'));
$availableLanguages = $article_language_versions ?? [$sourceLanguage];
$availableLanguages = array_values(array_unique(array_map(static fn($value): string => strtolower((string)$value), is_array($availableLanguages) ? $availableLanguages : [$sourceLanguage])));
if (!in_array($sourceLanguage, $availableLanguages, true)) {
    array_unshift($availableLanguages, $sourceLanguage);
}

$shortLabels = $language_short_labels ?? [];
$brandNames = $language_brand_names ?? [];
$flagEmoji = [
    'pl' => '🇵🇱',
    'en' => '🇬🇧',
    'de' => '🇩🇪',
    'fr' => '🇫🇷',
    'it' => '🇮🇹',
    'es' => '🇪🇸',
];

$articleId = (int)($article['id'] ?? 0);
?>
<?php if ($articleId > 0 && count($availableLanguages) > 0): ?>
  <nav class="article-language-switcher" aria-label="<?= e(t('article.language_versions', $currentLanguage)) ?>">
    <span class="article-language-label"><?= e(t('article.language_versions', $currentLanguage)) ?></span>
    <div class="article-language-list">
      <?php foreach ($availableLanguages as $language): ?>
        <?php
          $short = (string)($shortLabels[$language] ?? strtoupper($language));
          $brand = (string)($brandNames[$language] ?? $short);
          $flag = $flagEmoji[$language] ?? $short;
          $isActive = $language === $currentLanguage;
          $slug = $language === $sourceLanguage ? (string)($sourceArticle['slug'] ?? '') : (string)($languageMap[$language]['slug'] ?? '');
          $url = function_exists('public_article_language_url') ? public_article_language_url($articleId, $language, $slug) : ('/article?id=' . $articleId . '&lang=' . rawurlencode($language));
        ?>
        <a class="article-language-pill<?= $isActive ? ' is-active' : '' ?>" href="<?= e($url) ?>" hreflang="<?= e($language) ?>" lang="<?= e($language) ?>" title="<?= e($brand) ?>">
          <span class="article-language-flag" aria-hidden="true"><?= e($flag) ?></span>
          <span><?= e($short) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </nav>
<?php endif; ?>

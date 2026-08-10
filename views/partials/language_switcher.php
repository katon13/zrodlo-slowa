<?php
$currentLanguage = strtolower((string)($current_language ?? (function_exists('public_language') ? public_language() : 'pl')));
$publicLanguages = $public_languages ?? ['pl'];
$shortLabels = $language_short_labels ?? [];
$brandNames = $language_brand_names ?? [];
$flagCodes = $language_flag_codes ?? [];

$flagEmoji = [
    'pl' => '🇵🇱',
    'en' => '🇬🇧',
    'de' => '🇩🇪',
    'fr' => '🇫🇷',
    'it' => '🇮🇹',
    'es' => '🇪🇸',
];

$articleContext = is_array($source_article ?? null) ? $source_article : (is_array($article ?? null) ? $article : null);
$articleContextId = $articleContext ? (int)($articleContext['id'] ?? 0) : 0;
$articleSourceLanguage = strtolower((string)($articleContext['source_language'] ?? 'pl'));
$articleLanguageMap = is_array($article_language_map ?? null) ? $article_language_map : [];
$isArticlePageLanguageSwitch = $articleContextId > 0 && function_exists('public_article_language_url');

$buildLanguageUrl = static function (string $language) use ($isArticlePageLanguageSwitch, $articleContextId, $articleContext, $articleSourceLanguage, $articleLanguageMap): string {
    $language = strtolower(trim($language));
    if ($isArticlePageLanguageSwitch) {
        if ($language === $articleSourceLanguage) {
            $slug = trim((string)($articleContext['slug'] ?? ''));
        } else {
            $translation = $articleLanguageMap[$language] ?? [];
            $slug = is_array($translation) ? trim((string)($translation['slug'] ?? '')) : '';
        }
        if ($slug === '') {
            return public_language_url($language, '/articles');
        }
        return public_article_language_url($articleContextId, $language, $slug);
    }

    return function_exists('public_language_url') ? public_language_url($language) : ('?lang=' . rawurlencode($language));
};

$currentShort = (string)($shortLabels[$currentLanguage] ?? strtoupper($currentLanguage));
$label = t('language.switcher.label', $currentLanguage);
?>
<details class="language-switcher" data-language-switcher>
  <summary class="language-trigger" aria-label="<?= e($label) ?>">
    <span class="language-globe" aria-hidden="true">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="12" cy="12" r="9"></circle>
        <path d="M3 12h18"></path>
        <path d="M12 3c2.2 2.4 3.3 5.4 3.3 9S14.2 18.6 12 21"></path>
        <path d="M12 3C9.8 5.4 8.7 8.4 8.7 12S9.8 18.6 12 21"></path>
      </svg>
    </span>
    <span class="language-current"><?= e($currentShort) ?></span>
    <span class="language-caret" aria-hidden="true">▾</span>
  </summary>
  <div class="language-menu" role="menu">
    <?php foreach ($publicLanguages as $language): ?>
      <?php
        $language = strtolower((string)$language);
        $short = (string)($shortLabels[$language] ?? strtoupper($language));
        $brand = (string)($brandNames[$language] ?? $short);
        $flag = $flagEmoji[$language] ?? (string)($flagCodes[$language] ?? $short);
        $url = $buildLanguageUrl($language);
        $active = $language === $currentLanguage;
      ?>
      <a class="language-menu-item<?= $active ? ' is-active' : '' ?>" href="<?= e($url) ?>" hreflang="<?= e($language) ?>" lang="<?= e($language) ?>" role="menuitem">
        <span class="language-flag" aria-hidden="true"><?= e($flag) ?></span>
        <span class="language-code"><?= e($short) ?></span>
        <span class="language-brand"><?= e($brand) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</details>

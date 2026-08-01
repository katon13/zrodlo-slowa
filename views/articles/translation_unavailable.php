<?php
$uiLanguage = strtolower((string)($display_article_language ?? $requested_article_language ?? $current_language ?? (function_exists('public_language') ? public_language() : 'pl')));
$article = $article ?? [];
$articleId = (int)($article['id'] ?? 0);
$sourceLanguage = strtolower((string)($article['source_language'] ?? 'pl'));
$sourceTitle = (string)($article['title'] ?? '');
?>

<div class="article-layout">
  <article>
    <div class="breadcrumb"><?= e(t('article.breadcrumb.home', $uiLanguage)) ?> › <?= e(t('article.breadcrumb.texts', $uiLanguage)) ?></div>
    <?php require __DIR__ . '/../partials/article_language_switcher.php'; ?>

    <section class="admin-section article-translation-unavailable" style="margin-top: 24px;">
      <p class="kicker"><?= e(strtoupper($uiLanguage)) ?></p>
      <h1 class="article-title"><?= e(t('article.translation.unavailable.title', $uiLanguage)) ?></h1>
      <p class="lead"><?= e(t('article.translation.unavailable.message', $uiLanguage)) ?></p>
      <?php if ($sourceTitle !== ''): ?>
        <p><strong><?= e(t('article.translation.unavailable.source_label', $uiLanguage)) ?>:</strong> <?= e($sourceTitle) ?></p>
      <?php endif; ?>
      <?php if ($articleId > 0): ?>
        <?php $originalUrl = function_exists('public_article_language_url') ? public_article_language_url($articleId, $sourceLanguage, (string)($article['slug'] ?? '')) : ('/article?id=' . $articleId . '&lang=' . rawurlencode($sourceLanguage)); ?>
        <p><a class="read-more" href="<?= e($originalUrl) ?>"><?= e(t('article.translation.unavailable.read_original', $uiLanguage)) ?> <span>→</span></a></p>
      <?php endif; ?>
    </section>
  </article>
</div>

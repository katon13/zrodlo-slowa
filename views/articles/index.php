<?php
$articles = $articles ?? [];
$isHome = !empty($is_homepage);
$featuredArticle = ($isHome && is_array($featured_article ?? null)) ? $featured_article : null;
$homeArticles = $articles;
if ($featuredArticle) {
    $featuredArticleId = (int)($featuredArticle['id'] ?? 0);
    $homeArticles = array_values(array_filter($homeArticles, static fn(array $article): bool => (int)($article['id'] ?? 0) !== $featuredArticleId));
} elseif ($isHome && !empty($homeArticles)) {
    // Jeżeli nie wskazano osobnego artykułu wyróżniającego, pierwszy publiczny artykuł
    // przejmuje układ hero. Dzięki temu pierwszy tekst zawsze ma ten sam styl bazowy
    // co Baner Główny i nie dubluje się niżej w siatce.
    $featuredArticle = array_shift($homeArticles);
}
$leadArticle = $homeArticles[0] ?? null;
$cards = array_slice($homeArticles, 0, 3);
$latest = array_slice($homeArticles, 3, 8);
$currentLanguage = (string)($current_language ?? (function_exists('public_language') ? public_language() : 'pl'));
$articleUrl = static function (array $article) use ($currentLanguage): string {
    $articleId = (int)($article['id'] ?? 0);
    $slug = (string)($article['slug'] ?? '');
    if (function_exists('public_article_language_url')) {
        return public_article_language_url($articleId, $currentLanguage, $slug);
    }
    return '/article?id=' . $articleId;
};
$readMoreLabel = static fn(string $language): string => t('article.read_more', $language);
$editorialArticleLabel = static function (array $article) use ($currentLanguage): ?string {
    return \App\Services\ArticleLabelPresenter::display(
        (string)($article['article_label'] ?? ''),
        $currentLanguage
    );
};
$articleLabel = static function (array $article) use ($currentLanguage, $editorialArticleLabel): string {
    $editorialLabel = $editorialArticleLabel($article);
    if ($editorialLabel !== null) {
        return $editorialLabel;
    }

    $isPaid = (($article['access_mode'] ?? 'free') === 'paid');
    if (!$isPaid) {
        return t('article.type.text', $currentLanguage);
    }
    if ((int)($article['is_unique'] ?? 0) === 1) {
        return t('article.unique.badge', $currentLanguage);
    }
    if ((int)($article['is_premium'] ?? 0) === 1) {
        return t('article.premium.badge', $currentLanguage);
    }
    return t('article.paid.badge', $currentLanguage);
};
?>

<?php if ($isHome): ?>
  <?php
    $mainBanner = is_array($main_banner ?? null) ? $main_banner : null;
  ?>
  <?php if ($mainBanner && !empty($mainBanner['is_active'])): ?>
    <?php
      $mainBannerKicker = (string)($mainBanner['kicker'] ?? '');
      $mainBannerTitle = (string)($mainBanner['title'] ?? '');
      $mainBannerLead = (string)($mainBanner['lead'] ?? '');
      $mainBannerBody = (string)($mainBanner['body'] ?? '');
      $mainBannerButtonLabel = (string)($mainBanner['button_label'] ?? '');
      $mainBannerButtonUrl = (string)($mainBanner['button_url'] ?? '/register');
      $mainBannerImage = (string)($mainBanner['image_path'] ?? '/assets/img/banners/main-banner-editorial-soft-bg.webp');
    ?>
    <section class="hero main-banner main-banner-background" style="--main-banner-bg: url('<?= e($mainBannerImage) ?>');">
      <div>
        <?php if ($mainBannerKicker !== ''): ?>
          <div class="kicker"><?= e($mainBannerKicker) ?></div>
        <?php endif; ?>
        <?php if ($mainBannerTitle !== ''): ?>
          <h1><?= e($mainBannerTitle) ?></h1>
        <?php endif; ?>
        <?php if ($mainBannerLead !== ''): ?>
          <p class="lead"><?= e($mainBannerLead) ?></p>
        <?php endif; ?>
        <?php if ($mainBannerBody !== ''): ?>
          <p class="lead lead-small"><?= e($mainBannerBody) ?></p>
        <?php endif; ?>
        <div class="meta"><span><?= e(t('home.hero.meta.write', $currentLanguage)) ?></span><span>·</span><span><?= e(t('home.hero.meta.publish', $currentLanguage)) ?></span><span>·</span><span><?= e(t('home.hero.meta.earn', $currentLanguage)) ?></span></div>
        <?php if ($mainBannerButtonLabel !== '' && $mainBannerButtonUrl !== ''): ?>
          <a class="read-more" href="<?= e($mainBannerButtonUrl) ?>"><?= e($mainBannerButtonLabel) ?> <span>→</span></a>
        <?php endif; ?>
      </div>
    </section>
  <?php endif; ?>

  <?php if ($featuredArticle): ?>
    <?php
      $featuredImage = (string)($featuredArticle['main_image'] ?? '');
      if ($featuredImage === '') {
          $featuredImage = '/assets/img/articles/thumb-report.svg';
      }
      $featuredLead = trim((string)($featuredArticle['lead'] ?? ''));
      if ($featuredLead === '') {
          $featuredLead = mb_substr(strip_tags((string)($featuredArticle['body'] ?? '')), 0, 180);
      }
      $featuredImagePosition = (int)($featuredArticle['main_image_position'] ?? 50);
      $featuredEditorialLabel = $editorialArticleLabel($featuredArticle);
    ?>
    <section class="hero main-banner featured-article">
      <div>
        <div class="kicker"><?= e($articleLabel($featuredArticle)) ?></div>
        <h1><a class="hero-title-link" href="<?= e($articleUrl($featuredArticle)) ?>"><?= e($featuredArticle['title'] ?? '') ?></a></h1>
        <?php if ($featuredLead !== ''): ?>
          <p class="lead"><?= e($featuredLead) ?></p>
        <?php endif; ?>
        <div class="meta">
          <?php if (!empty($featuredArticle['author_name'])): ?>
            <span><?= e($featuredArticle['author_name']) ?></span>
          <?php endif; ?>
          <?php if ($featuredEditorialLabel !== null): ?>
            <span class="zs-public-article-label" aria-label="<?= e(str_replace('{label}', $featuredEditorialLabel, t('article.label.aria'))) ?>"><?= e($featuredEditorialLabel) ?></span>
          <?php else: ?>
            <span>·</span>
            <span><?= e(t('article.type.text', $currentLanguage)) ?></span>
          <?php endif; ?>
        </div>
        <a class="read-more" href="<?= e($articleUrl($featuredArticle)) ?>"><?= e($readMoreLabel($currentLanguage)) ?> <span>→</span></a>
      </div>
      <a href="<?= e($articleUrl($featuredArticle)) ?>" aria-label="<?= e($featuredArticle['title'] ?? '') ?>">
        <img class="hero-image" src="<?= e($featuredImage) ?>" alt="" style="object-position: center <?= $featuredImagePosition ?>%">
      </a>
    </section>
  <?php endif; ?>

  <?php if (!empty($placement_campaigns)): require __DIR__ . '/../partials/campaign_slot.php'; endif; ?>

  <section class="card-grid">
    <?php 
    $gridArticles = $isHome ? $cards : $articles;
    foreach ($gridArticles as $idx => $a): 
    ?>
      <?php $thumbs = ['thumb-society.svg','thumb-culture.svg','thumb-faith.svg','thumb-report.svg']; ?>
      <article class="card editorial-card" onclick="window.location.href='<?= e($articleUrl($a)) ?>'" style="cursor: pointer; position: relative; height: 100%;">
        <a href="<?= e($articleUrl($a)) ?>" aria-label="<?= e($a['title'] ?? '') ?>" style="position: absolute; inset: 0; z-index: 5; text-decoration: none; color: inherit;"></a>
        <div style="display: flex; flex-direction: column; height: 100%;">
          <div class="kicker"><?= e($articleLabel($a)) ?></div>
          <h2 style="margin-top: 8px;"><a href="<?= e($articleUrl($a)) ?>" onclick="event.stopPropagation();"><?= e($a['title']) ?></a></h2>
          <p style="flex-grow: 1;"><?= e($a['lead'] ?: mb_substr(strip_tags($a['body'] ?? ''), 0, 120)) ?></p>
          <div class="meta" style="display: flex; align-items: center; gap: 8px; margin-top: auto; padding-top: 16px; border-top: 1px solid #eee;">
            <?php if (!empty($a['author_avatar_path'])): ?>
              <img src="<?= e($a['author_avatar_path']) ?>?t=<?= strtotime($a['author_avatar_updated_at'] ?? 'now') ?>" alt="" style="width: 20px; height: 20px; border-radius: 50%; object-fit: cover;">
            <?php else: ?>
              <span style="width: 20px; height: 20px; border-radius: 50%; background: #f0f0f0; display: inline-flex; align-items: center; justify-content: center; color: #999;">
                <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
              </span>
            <?php endif; ?>
            <span><?= e($a['author_name'] ?? '') ?></span>
            <?php if (($cardEditorialLabel = $editorialArticleLabel($a)) !== null): ?>
              <span class="zs-public-article-label" aria-label="<?= e(str_replace('{label}', $cardEditorialLabel, t('article.label.aria'))) ?>"><?= e($cardEditorialLabel) ?></span>
            <?php endif; ?>
          </div>
        </div>
        <?php if (!empty($a['main_image'])): ?>
          <img src="<?= e($a['main_image']) ?>" alt="" style="object-position: center <?= (int)($a['main_image_position'] ?? 50) ?>%">
        <?php else: ?>
          <?php $thumbs = ['thumb-society.svg','thumb-culture.svg','thumb-faith.svg']; ?>
          <img src="/assets/img/articles/<?= e($thumbs[$idx] ?? 'thumb-report.svg') ?>" alt="">
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  </section>

  <section class="premium-strip">
    <div>
      <strong><?= e(t('home.premium.title', $currentLanguage)) ?></strong><br>
      <?= e(t('home.premium.description', $currentLanguage)) ?>
    </div>
    <a class="read-more" href="/jak-zarabiac"><?= e(t('home.hero.cta_earning', $currentLanguage)) ?> <span>→</span></a>
  </section>



  <?php if (!empty($money_flows)): ?>
    <section class="money-home-section">
      <div class="admin-section-head">
        <div>
          <p class="kicker"><?= e(t('home.value_flow.kicker', $currentLanguage)) ?></p>
          <h2><?= e(t('home.value_flow.title', $currentLanguage)) ?></h2>
        </div>
        <a class="text-link" href="/jak-zarabiac"><?= e(t('home.value_flow.full_map', $currentLanguage)) ?></a>
      </div>
      <div class="money-home-grid">
        <?php foreach (array_slice($money_flows, 0, 4) as $flow): ?>
          <article class="money-home-card">
            <span><?= e($flow['label']) ?></span>
            <strong><?= e($flow['receiver']) ?></strong>
            <small><?= e($flow['note']) ?></small>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <section class="latest">
    <h2><?= e(t('home.latest.title', $currentLanguage)) ?></h2>
    <div class="latest-list">
      <?php 
      $latestToShow = $isHome ? $latest : $articles;
      foreach ($latestToShow as $a): 
      ?>
        <article class="latest-item" onclick="window.location.href='<?= e($articleUrl($a)) ?>'" style="cursor: pointer; position: relative;">
          <a href="<?= e($articleUrl($a)) ?>" aria-label="<?= e($a['title'] ?? '') ?>" style="position: absolute; inset: 0; z-index: 5; text-decoration: none; color: inherit;"></a>
          <div class="kicker"><?= e($articleLabel($a)) ?></div>
          <h3><a href="<?= e($articleUrl($a)) ?>" onclick="event.stopPropagation();"><?= e($a['title']) ?></a></h3>
          <div class="meta" style="display: flex; align-items: center; gap: 6px;">
            <?php if (!empty($a['author_avatar_path'])): ?>
              <img src="<?= e($a['author_avatar_path']) ?>?t=<?= strtotime($a['author_avatar_updated_at'] ?? 'now') ?>" alt="" style="width: 16px; height: 16px; border-radius: 50%; object-fit: cover;">
            <?php else: ?>
              <span style="width: 16px; height: 16px; border-radius: 50%; background: #f0f0f0; display: inline-flex; align-items: center; justify-content: center; color: #999;">
                <svg viewBox="0 0 24 24" width="10" height="10" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
              </span>
            <?php endif; ?>
            <span><?= e($a['author_name'] ?? '') ?></span>
            <?php if (($latestEditorialLabel = $editorialArticleLabel($a)) !== null): ?>
              <span class="zs-public-article-label" aria-label="<?= e(str_replace('{label}', $latestEditorialLabel, t('article.label.aria'))) ?>"><?= e($latestEditorialLabel) ?></span>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
      <?php if (!$latestToShow && $isHome): ?><p><?= e(t('home.latest.empty', $currentLanguage)) ?></p><?php endif; ?>
    </div>
  </section>
<?php else: ?>
  <div class="kicker"><?= e(t('articles.index.kicker', $currentLanguage)) ?></div>
  <h1 class="article-title"><?= e(t('articles.index.title', $currentLanguage)) ?></h1>
  <p class="lead"><?= e(t('articles.index.lead', $currentLanguage)) ?></p>
  <div class="grid">
    <?php 
    $gridToShow = $isHome ? [] : $articles; 
    foreach ($gridToShow as $a): 
    ?>
      <article class="card" onclick="window.location.href='<?= e($articleUrl($a)) ?>'" style="cursor: pointer; position: relative;">
        <a href="<?= e($articleUrl($a)) ?>" aria-label="<?= e($a['title'] ?? '') ?>" style="position: absolute; inset: 0; z-index: 5; text-decoration: none; color: inherit;"></a>
        <div class="card-thumb-wrap">
          <?php if (!empty($a['main_image'])): ?>
            <img src="<?= e($a['main_image']) ?>" alt="" style="object-position: center <?= (int)($a['main_image_position'] ?? 50) ?>%">
          <?php else: ?>
            <img src="/assets/img/articles/thumb-report.svg" alt="">
          <?php endif; ?>
        </div>
        <div class="card-content">
          <div class="kicker"><?= e($articleLabel($a)) ?></div>
          <h2><a href="<?= e($articleUrl($a)) ?>" onclick="event.stopPropagation();"><?= e($a['title']) ?></a></h2>
          <p><?= e($a['lead'] ?: mb_substr(strip_tags($a['body'] ?? ''),0,160)) ?></p>
          <div style="display: flex; align-items: center; gap: 8px; margin-top: 8px;">
            <?php if (!empty($a['author_avatar_path'])): ?>
              <img src="<?= e($a['author_avatar_path'] ?? '') ?>?t=<?= strtotime($a['author_avatar_updated_at'] ?? 'now') ?>" alt="" style="width: 24px; height: 24px; border-radius: 50%; object-fit: cover; border: 1px solid #eee;">
            <?php else: ?>
              <span style="width: 24px; height: 24px; border-radius: 50%; background: #f0f0f0; display: inline-flex; align-items: center; justify-content: center; color: #999; border: 1px solid #eee;">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
              </span>
            <?php endif; ?>
            <small style="margin: 0;"><?= e($a['author_name'] ?? '') ?></small>
            <?php if (($gridEditorialLabel = $editorialArticleLabel($a)) !== null): ?>
              <span class="zs-public-article-label" aria-label="<?= e(str_replace('{label}', $gridEditorialLabel, t('article.label.aria'))) ?>"><?= e($gridEditorialLabel) ?></span>
            <?php endif; ?>
          </div>
        </div>
      </article>
    <?php endforeach; ?>
    <?php if (!$articles && !$isHome): ?><p><?= e(t('home.latest.empty', $currentLanguage)) ?></p><?php endif; ?>
  </div>
<?php endif; ?>

<?php
$lang = (string)($current_language ?? (function_exists('public_language') ? public_language() : 'pl'));
$articleCount = count($articles ?? []);
?>

<section class="admin-page-head zs-reader-head">
  <p class="kicker">STREFA CZYTELNIKA</p>
  <h1>Panel czytelnika</h1>
  <p>Twoje centrum czytania, ustawień konta i rozliczeń w jednym spójnym miejscu.</p>
</section>

<section class="zs-reader-overview" aria-label="Skróty panelu czytelnika">
  <a class="zs-reader-shortcut is-primary" href="<?= e(public_language_url($lang, '/wallet')) ?>">
    <span class="zs-reader-shortcut-icon"><?= zs_icon('wallet') ?></span>
    <strong>Portfel</strong>
    <small>Saldo, Talent i historia operacji</small>
  </a>
  <a class="zs-reader-shortcut" href="<?= e(public_language_url($lang, '/account/settings')) ?>">
    <span class="zs-reader-shortcut-icon"><?= zs_icon('admin') ?></span>
    <strong>Ustawienia</strong>
    <small>Język, waluta i zdjęcie profilowe</small>
  </a>
  <a class="zs-reader-shortcut" href="<?= e(public_language_url($lang, '/account/security')) ?>">
    <span class="zs-reader-shortcut-icon"><?= zs_icon('shield') ?></span>
    <strong>Bezpieczeństwo</strong>
    <small>E-mail, hasło i logowanie 2FA</small>
  </a>
  <a class="zs-reader-shortcut" href="<?= e(public_language_url($lang, '/author')) ?>">
    <span class="zs-reader-shortcut-icon"><?= zs_icon('author') ?></span>
    <strong>Twoje teksty</strong>
    <small>Panel autora i status publikacji</small>
  </a>
</section>

<section class="admin-panel-block">
  <div class="admin-section-head">
    <div>
      <p class="kicker">NAJNOWSZE</p>
      <h2>Teksty dla Ciebie</h2>
    </div>
    <span><?= $articleCount ?> pozycji</span>
  </div>

  <?php if ($articleCount === 0): ?>
    <div class="empty-state">
      <h3>Brak opublikowanych tekstów</h3>
      <p>Nowe materiały pojawią się tutaj po publikacji przez redakcję.</p>
    </div>
  <?php else: ?>
    <div class="zs-reader-article-grid">
      <?php foreach ($articles as $article): ?>
        <?php
          $readerEditorialLabel = \App\Services\ArticleLabelPresenter::display(
              (string)($article['article_label'] ?? ''),
              $lang
          );
        ?>
        <article class="zs-reader-article-card">
          <?php if (!empty($article['main_image'])): ?>
            <img
              src="<?= e((string)$article['main_image']) ?>"
              alt=""
              loading="lazy"
              style="object-position: center <?= (int)($article['main_image_position'] ?? 50) ?>%"
            >
          <?php else: ?>
            <div class="zs-reader-article-placeholder">TEKST</div>
          <?php endif; ?>
          <div class="zs-reader-article-copy">
            <p class="kicker"><?= e($readerEditorialLabel ?? (!empty($article['is_premium']) ? 'PREMIUM' : 'TEKST')) ?></p>
            <h3><?= e((string)$article['title']) ?></h3>
            <?php if (!empty($article['lead'])): ?>
              <p><?= e(mb_substr((string)$article['lead'], 0, 150)) ?><?= mb_strlen((string)$article['lead']) > 150 ? '…' : '' ?></p>
            <?php endif; ?>
            <div class="zs-reader-article-meta">
              <span><?= e((string)($article['author_name'] ?? 'Redakcja')) ?></span>
              <?php if ($readerEditorialLabel !== null): ?>
                <span class="zs-public-article-label" aria-label="Etykieta artykułu: <?= e($readerEditorialLabel) ?>"><?= e($readerEditorialLabel) ?></span>
              <?php endif; ?>
              <?php if (!empty($article['published_at'])): ?>
                <span><?= e(date('d.m.Y', strtotime((string)$article['published_at']))) ?></span>
              <?php endif; ?>
            </div>
            <a class="zs-btn-text" href="<?= e(public_language_url($lang, '/article?id=' . (int)$article['id'])) ?>">Czytaj dalej →</a>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

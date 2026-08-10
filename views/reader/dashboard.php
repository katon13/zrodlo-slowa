<?php
$lang = (string)($current_language ?? (function_exists('public_language') ? public_language() : 'pl'));
$articleCount = count($articles ?? []);
?>

<section class="admin-page-head zs-reader-head">
  <p class="kicker"><?= e(t('ui.reader.dashboard.strefa_czytelnika')) ?></p>
  <h1><?= e(t('ui.reader.dashboard.panel_czytelnika')) ?></h1>
  <p><?= e(t('ui.reader.dashboard.twoje_centrum_czytania_ustawien_konta_i_rozliczen_w_jed_245567a8')) ?></p>
</section>

<section class="zs-reader-overview" aria-label="<?= e(t('ui.reader.dashboard.skroty_panelu_czytelnika')) ?>">
  <a class="zs-reader-shortcut is-primary" href="<?= e(public_language_url($lang, '/wallet')) ?>">
    <span class="zs-reader-shortcut-icon"><?= zs_icon('wallet') ?></span>
    <strong><?= e(t('wallet.title')) ?></strong>
    <small><?= e(t('ui.reader.dashboard.saldo_talent_i_historia_operacji')) ?></small>
  </a>
  <a class="zs-reader-shortcut" href="<?= e(public_language_url($lang, '/account/settings')) ?>">
    <span class="zs-reader-shortcut-icon"><?= zs_icon('admin') ?></span>
    <strong><?= e(t('admin.ai.ustawienia')) ?></strong>
    <small><?= e(t('ui.reader.dashboard.jezyk_waluta_i_zdjecie_profilowe')) ?></small>
  </a>
  <a class="zs-reader-shortcut" href="<?= e(public_language_url($lang, '/account/security')) ?>">
    <span class="zs-reader-shortcut-icon"><?= zs_icon('shield') ?></span>
    <strong><?= e(t('admin.roles.bezpieczenstwo')) ?></strong>
    <small><?= e(t('ui.reader.dashboard.e_mail_haso_i_logowanie_2fa')) ?></small>
  </a>
  <a class="zs-reader-shortcut" href="<?= e(public_language_url($lang, '/author')) ?>">
    <span class="zs-reader-shortcut-icon"><?= zs_icon('author') ?></span>
    <strong><?= e(t('ui.reader.dashboard.twoje_teksty')) ?></strong>
    <small><?= e(t('ui.reader.dashboard.panel_autora_i_status_publikacji')) ?></small>
  </a>
</section>

<section class="admin-panel-block">
  <div class="admin-section-head">
    <div>
      <p class="kicker"><?= e(t('ui.reader.dashboard.najnowsze')) ?></p>
      <h2><?= e(t('ui.reader.dashboard.teksty_dla_ciebie')) ?></h2>
    </div>
    <span><?= $articleCount ?> pozycji</span>
  </div>

  <?php if ($articleCount === 0): ?>
    <div class="empty-state">
      <h3><?= e(t('ui.reader.dashboard.brak_opublikowanych_tekstow')) ?></h3>
      <p><?= e(t('ui.reader.dashboard.nowe_materiay_pojawia_sie_tutaj_po_publikacji_przez_redakcje')) ?></p>
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
            <div class="zs-reader-article-placeholder"><?= e(t('ui.reader.dashboard.tekst')) ?></div>
          <?php endif; ?>
          <div class="zs-reader-article-copy">
          <p class="kicker"><?= e($readerEditorialLabel ?? t(!empty($article['is_premium']) ? 'reader.dashboard.premium_label' : 'reader.dashboard.article_label')) ?></p>
            <h3><?= e((string)$article['title']) ?></h3>
            <?php if (!empty($article['lead'])): ?>
              <p><?= e(mb_substr((string)$article['lead'], 0, 150)) ?><?= mb_strlen((string)$article['lead']) > 150 ? '…' : '' ?></p>
            <?php endif; ?>
            <div class="zs-reader-article-meta">
        <span><?= e((string)($article['author_name'] ?? t('common.editorial'))) ?></span>
              <?php if ($readerEditorialLabel !== null): ?>
                <span class="zs-public-article-label" aria-label="<?= e(str_replace('{label}', $readerEditorialLabel, t('article.label.aria'))) ?>"><?= e($readerEditorialLabel) ?></span>
              <?php endif; ?>
              <?php if (!empty($article['published_at'])): ?>
                <span><?= e(date('d.m.Y', strtotime((string)$article['published_at']))) ?></span>
              <?php endif; ?>
            </div>
            <a class="zs-btn-text" href="<?= e(public_language_url($lang, '/article?id=' . (int)$article['id'])) ?>"><?= e(t('ui.reader.dashboard.czytaj_dalej')) ?></a>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

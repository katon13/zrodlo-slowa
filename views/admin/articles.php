<?php
$statusLabels = [
    'draft' => t('article.status.draft'),
    'submitted' => t('admin.articles.tekst_przyszed_od_autora'),
    'review' => t('article.status.review'),
    'approved' => t('article.status.approved'),
    'published' => t('article.status.published'),
    'rejected' => t('article.status.rejected'),
    'archived' => t('article.status.archived'),
];
?>

<section class="admin-page-head zs-operator-page-head">
  <p class="kicker"><?= e(t('admin.articles.redakcja_gowna')) ?></p>
  <h1><?= e(t('admin.articles.teksty_redakcji_gownej')) ?></h1>
  <p><?= e(t('admin.articles.redaktor_gowny_przyjmuje_tekst_od_autora_prowadzi_go_pr_ac5e2975')) ?></p>
</section>

<?php
$articleStatusCounts = array_count_values(array_map(static fn(array $article): string => (string)($article['status'] ?? 'submitted'), $articles));
?>
<section class="zs-operator-overview" aria-label="<?= e(t('admin.articles.stan_obiegu_tekstow')) ?>">
  <article><span><?= e(t('admin.articles.w_obiegu_redakcji')) ?></span><strong><?= count($articles) ?></strong><small><?= e(t('admin.articles.tekstow_na_biezacej_liscie')) ?></small></article>
  <article class="<?= (int)($articleStatusCounts['submitted'] ?? 0) > 0 ? 'is-warning' : 'is-ready' ?>"><span><?= e(t('admin.articles.nowe_od_autorow')) ?></span><strong><?= (int)($articleStatusCounts['submitted'] ?? 0) ?></strong><small><?= e(t('admin.articles.czekaja_na_podjecie_pracy')) ?></small></article>
  <article><span><?= e(t('admin.articles.w_opracowaniu')) ?></span><strong><?= (int)($articleStatusCounts['review'] ?? 0) ?></strong><small><?= e(t('admin.articles.redakcja_pracuje_nad_tekstem')) ?></small></article>
  <article class="is-ready"><span><?= e(t('admin.articles.zatwierdzone')) ?></span><strong><?= (int)($articleStatusCounts['approved'] ?? 0) ?></strong><small><?= e(t('admin.articles.przekazane_do_kolejnego_etapu')) ?></small></article>
</section>

<section class="admin-panel-block zs-operator-panel">
  <div class="admin-section-head">
    <div>
      <p class="kicker"><?= e(t('admin.articles.materia_do_decyzji')) ?></p>
      <h2><?= e(t('admin.articles.lista_tekstow')) ?></h2>
    </div>
    <span><?= e(str_replace('{count}', (string)count($articles), t('admin.common.items_count'))) ?></span>
  </div>

  <?php if (empty($articles)): ?>
    <p class="admin-note"><?= e(t('admin.articles.brak_tekstow_w_obiegu_redakcji_gownej')) ?></p>
  <?php else: ?>
    <div class="chief-editor-list">
      <?php foreach ($articles as $a): ?>
        <?php
          $articleId = (int)($a['id'] ?? 0);
          $status = (string)($a['status'] ?? 'submitted');
          $updatedAt = (string)($a['updated_at'] ?? '');
          $authorId = (int)($a['author_id'] ?? 0);
          $blockedUntil = $a['author_submit_blocked_until'] ?? null;
          $isAuthorBlocked = $blockedUntil !== null && strtotime((string)$blockedUntil) > time();
        ?>
        <article class="chief-editor-card" id="article-<?= $articleId ?>">
          <div class="chief-editor-main">
            <span class="admin-label"><?= e(str_replace('{id}', (string)$articleId, t('admin.common.text_number'))) ?></span>
            <?php if (!empty($a['response_to_article_id'])): ?>
              <span class="zs-status-badge review"><?= e(str_replace('{id}', (string)(int)$a['response_to_article_id'], t('admin.editorial_edit.response_heading'))) ?></span>
            <?php endif; ?>
            <h3><?= e((string)($a['title'] ?? '')) ?></h3>
            <?php if (!empty($a['lead'])): ?>
              <p><?= e(mb_strimwidth((string)$a['lead'], 0, 220, '…', 'UTF-8')) ?></p>
            <?php endif; ?>
            <div class="chief-editor-meta">
              <span><?= e(t('admin.articles.author_prefix')) ?> <?= e((string)($a['author_name'] ?? '—')) ?></span>
              <span><?= e(t('admin.articles.status')) ?> <b><?= e($statusLabels[$status] ?? $status) ?></b></span>
              <span><?= e(t('admin.role_panel.updated_prefix')) ?> <?= e($updatedAt ?: '—') ?></span>
              <?php if (!empty($a['response_to_article_id'])): ?>
                <span><?= e(t('admin.articles.talent')) ?> <b><?= $a['response_reward_qualified'] === null ? e(t('admin.articles.reward_decided_at_publication')) : (!empty($a['response_reward_qualified']) ? e(str_replace('{points}', (string)(int)$a['response_reward_points'], t('admin.articles.reward_points'))) : e(t('admin.articles.0_tt_regua_nieaktywna_przy_publikacji'))) ?></b></span>
              <?php endif; ?>
              <?php if ($isAuthorBlocked): ?>
                <span class="author-submit-blocked"><?= e(t('admin.articles.autor_zablokowany_do')) ?> <b><?= e((string)$blockedUntil) ?></b></span>
              <?php endif; ?>
            </div>
            <div class="author-submit-block-tools">
              <span><?= e(t('admin.articles.blokada_wysyania_tekstow_autora')) ?></span>
              <form method="post" action="/admin/authors/submit-block">
                <?= csrf_field() ?>
                <input type="hidden" name="author_id" value="<?= $authorId ?>">
                <input type="hidden" name="article_id" value="<?= $articleId ?>">
                <input type="hidden" name="duration" value="24h">
                <button class="btn-line compact mini" type="submit"><?= e(t('admin.articles.24h')) ?></button>
              </form>
              <form method="post" action="/admin/authors/submit-block">
                <?= csrf_field() ?>
                <input type="hidden" name="author_id" value="<?= $authorId ?>">
                <input type="hidden" name="article_id" value="<?= $articleId ?>">
                <input type="hidden" name="duration" value="7d">
                <button class="btn-line compact mini" type="submit"><?= e(t('admin.articles.7_dni')) ?></button>
              </form>
              <form method="post" action="/admin/authors/submit-block">
                <?= csrf_field() ?>
                <input type="hidden" name="author_id" value="<?= $authorId ?>">
                <input type="hidden" name="article_id" value="<?= $articleId ?>">
                <input type="hidden" name="duration" value="30d">
                <button class="btn-line compact mini" type="submit"><?= e(t('admin.articles.30_dni')) ?></button>
              </form>
              <?php if ($isAuthorBlocked): ?>
                <form method="post" action="/admin/authors/submit-block">
                  <?= csrf_field() ?>
                  <input type="hidden" name="author_id" value="<?= $authorId ?>">
                  <input type="hidden" name="article_id" value="<?= $articleId ?>">
                  <input type="hidden" name="duration" value="clear">
                  <button class="btn-line compact mini" type="submit"><?= e(t('admin.articles.zdejmij')) ?></button>
                </form>
              <?php endif; ?>
            </div>
          </div>

          <div class="chief-editor-actions">
            <a class="btn-line compact" href="/article?id=<?= $articleId ?>" target="_blank" rel="noopener"><?= e(t('editorial.editing.preview')) ?></a>

            <?php if ($status === 'submitted'): ?>
              <form method="post" action="/admin/articles/status">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $articleId ?>">
                <input type="hidden" name="status" value="review">
                <button class="btn-red compact" type="submit"><?= e(t('admin.articles.podejmij_prace')) ?></button>
              </form>
            <?php endif; ?>

            <?php if (in_array($status, ['submitted', 'review'], true)): ?>
              <form method="post" action="/admin/articles/status">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $articleId ?>">
                <input type="hidden" name="status" value="approved">
                <button class="btn-red compact" type="submit"><?= e(t('admin.articles.zatwierdz')) ?></button>
              </form>
              <form method="post" action="/admin/articles/status">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $articleId ?>">
                <input type="hidden" name="status" value="rejected">
                <button class="btn-line compact" type="submit"><?= e(t('admin.articles.odrzuc_cofnij')) ?></button>
              </form>
            <?php endif; ?>

            <?php if ($status === 'approved'): ?>
              <span class="admin-note compact-note"><?= e(t('admin.articles.przekazany_do_wydawcy_i_moderatora')) ?></span>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="zs-pagination-bar">
    <?php $prev = max(1, (int)($snajper_page ?? 1) - 1); $next = (int)($snajper_page ?? 1) + 1; ?>
    <a class="zs-btn-small" href="/admin/articles?page=<?= $prev ?>"><?= e(t('admin.articles.laquo_poprzednia_strona')) ?></a>
    <span class="zs-pagination-info"><?= e(str_replace('{page}', (string)(int)($snajper_page ?? 1), t('admin.common.page_number'))) ?></span>
    <a class="zs-btn-small" href="/admin/articles?page=<?= $next ?>"><?= e(t('admin.articles.nastepna_strona_raquo')) ?></a>
  </div>
</section>

<style>
.chief-editor-list { display: flex; flex-direction: column; gap: 1rem; margin-top: 1.25rem; }
.chief-editor-card { display: flex; justify-content: space-between; gap: 1.5rem; padding: 1.25rem; border: 1px solid #e5e7eb; background: #fff; }
.chief-editor-main { flex: 1; }
.chief-editor-main h3 { margin: .35rem 0; font-size: 1.25rem; }
.chief-editor-main p { margin: .35rem 0 .75rem; color: #374151; line-height: 1.55; }
.chief-editor-meta { display: flex; flex-wrap: wrap; gap: .75rem 1.25rem; color: #6b7280; font-size: .9rem; }
.chief-editor-actions { min-width: 190px; display: flex; flex-direction: column; gap: .6rem; align-items: stretch; justify-content: center; }
.chief-editor-actions form { margin: 0; }
.chief-editor-actions .btn-line,
.chief-editor-actions .btn-red { width: 100%; text-align: center; }
.compact-note { display: block; font-size: .82rem; text-align: center; margin: 0; }
.author-submit-blocked { color: #b91c1c; }
.author-submit-block-tools { margin-top: .8rem; display: flex; align-items: center; flex-wrap: wrap; gap: .4rem; color: #6b7280; font-size: .82rem; }
.author-submit-block-tools form { margin: 0; }
.author-submit-block-tools .mini { padding: .35rem .55rem; font-size: .72rem; letter-spacing: .08em; }
@media (max-width: 760px) {
  .chief-editor-card { flex-direction: column; }
  .chief-editor-actions { min-width: 0; }
}
</style>

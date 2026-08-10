<?php
$statusLabels = [
    'draft' => 'szkic',
    'submitted' => 'tekst przyszedł od autora',
    'review' => 'redaktor pracuje nad tekstem',
    'approved' => 'zaakceptowany i przekazany dalej',
    'published' => 'opublikowany',
    'rejected' => 'odrzucony',
    'archived' => 'archiwum',
];
?>

<section class="admin-page-head zs-operator-page-head">
  <p class="kicker">REDAKCJA GŁÓWNA</p>
  <h1>Teksty Redakcji Głównej</h1>
  <p>Redaktor Główny przyjmuje tekst od autora, prowadzi go przez ocenę i podejmuje decyzję o zatwierdzeniu albo odrzuceniu.</p>
</section>

<?php
$articleStatusCounts = array_count_values(array_map(static fn(array $article): string => (string)($article['status'] ?? 'submitted'), $articles));
?>
<section class="zs-operator-overview" aria-label="Stan obiegu tekstów">
  <article><span>W obiegu redakcji</span><strong><?= count($articles) ?></strong><small>tekstów na bieżącej liście</small></article>
  <article class="<?= (int)($articleStatusCounts['submitted'] ?? 0) > 0 ? 'is-warning' : 'is-ready' ?>"><span>Nowe od autorów</span><strong><?= (int)($articleStatusCounts['submitted'] ?? 0) ?></strong><small>czekają na podjęcie pracy</small></article>
  <article><span>W opracowaniu</span><strong><?= (int)($articleStatusCounts['review'] ?? 0) ?></strong><small>redakcja pracuje nad tekstem</small></article>
  <article class="is-ready"><span>Zatwierdzone</span><strong><?= (int)($articleStatusCounts['approved'] ?? 0) ?></strong><small>przekazane do kolejnego etapu</small></article>
</section>

<section class="admin-panel-block zs-operator-panel">
  <div class="admin-section-head">
    <div>
      <p class="kicker">Materiał do decyzji</p>
      <h2>Lista tekstów</h2>
    </div>
    <span><?= count($articles) ?> pozycji</span>
  </div>

  <?php if (empty($articles)): ?>
    <p class="admin-note">Brak tekstów w obiegu Redakcji Głównej.</p>
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
            <span class="admin-label">Tekst #<?= $articleId ?></span>
            <?php if (!empty($a['response_to_article_id'])): ?>
              <span class="zs-status-badge review">OPINIA / POLEMIKA DO #<?= (int)$a['response_to_article_id'] ?></span>
            <?php endif; ?>
            <h3><?= e((string)($a['title'] ?? '')) ?></h3>
            <?php if (!empty($a['lead'])): ?>
              <p><?= e(mb_strimwidth((string)$a['lead'], 0, 220, '…', 'UTF-8')) ?></p>
            <?php endif; ?>
            <div class="chief-editor-meta">
              <span>Autor: <?= e((string)($a['author_name'] ?? '—')) ?></span>
              <span>Status: <b><?= e($statusLabels[$status] ?? $status) ?></b></span>
              <span>Aktualizacja: <?= e($updatedAt ?: '—') ?></span>
              <?php if (!empty($a['response_to_article_id'])): ?>
                <span>Talent: <b><?= $a['response_reward_qualified'] === null ? 'snapshot dopiero przy publikacji' : (!empty($a['response_reward_qualified']) ? ((int)$a['response_reward_points'] . ' TT · job ' . e((string)$a['response_reward_job_public_id'])) : '0 TT · reguła nieaktywna przy publikacji') ?></b></span>
              <?php endif; ?>
              <?php if ($isAuthorBlocked): ?>
                <span class="author-submit-blocked">Autor zablokowany do: <b><?= e((string)$blockedUntil) ?></b></span>
              <?php endif; ?>
            </div>
            <div class="author-submit-block-tools">
              <span>Blokada wysyłania tekstów autora:</span>
              <form method="post" action="/admin/authors/submit-block">
                <?= csrf_field() ?>
                <input type="hidden" name="author_id" value="<?= $authorId ?>">
                <input type="hidden" name="article_id" value="<?= $articleId ?>">
                <input type="hidden" name="duration" value="24h">
                <button class="btn-line compact mini" type="submit">24h</button>
              </form>
              <form method="post" action="/admin/authors/submit-block">
                <?= csrf_field() ?>
                <input type="hidden" name="author_id" value="<?= $authorId ?>">
                <input type="hidden" name="article_id" value="<?= $articleId ?>">
                <input type="hidden" name="duration" value="7d">
                <button class="btn-line compact mini" type="submit">7 dni</button>
              </form>
              <form method="post" action="/admin/authors/submit-block">
                <?= csrf_field() ?>
                <input type="hidden" name="author_id" value="<?= $authorId ?>">
                <input type="hidden" name="article_id" value="<?= $articleId ?>">
                <input type="hidden" name="duration" value="30d">
                <button class="btn-line compact mini" type="submit">30 dni</button>
              </form>
              <?php if ($isAuthorBlocked): ?>
                <form method="post" action="/admin/authors/submit-block">
                  <?= csrf_field() ?>
                  <input type="hidden" name="author_id" value="<?= $authorId ?>">
                  <input type="hidden" name="article_id" value="<?= $articleId ?>">
                  <input type="hidden" name="duration" value="clear">
                  <button class="btn-line compact mini" type="submit">Zdejmij</button>
                </form>
              <?php endif; ?>
            </div>
          </div>

          <div class="chief-editor-actions">
            <a class="btn-line compact" href="/article?id=<?= $articleId ?>" target="_blank" rel="noopener">Podgląd</a>

            <?php if ($status === 'submitted'): ?>
              <form method="post" action="/admin/articles/status">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $articleId ?>">
                <input type="hidden" name="status" value="review">
                <button class="btn-red compact" type="submit">Podejmij pracę</button>
              </form>
            <?php endif; ?>

            <?php if (in_array($status, ['submitted', 'review'], true)): ?>
              <form method="post" action="/admin/articles/status">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $articleId ?>">
                <input type="hidden" name="status" value="approved">
                <button class="btn-red compact" type="submit">Zatwierdź</button>
              </form>
              <form method="post" action="/admin/articles/status">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $articleId ?>">
                <input type="hidden" name="status" value="rejected">
                <button class="btn-line compact" type="submit">Odrzuć / cofnij</button>
              </form>
            <?php endif; ?>

            <?php if ($status === 'approved'): ?>
              <span class="admin-note compact-note">Przekazany do Wydawcy i Moderatora</span>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="zs-pagination-bar">
    <?php $prev = max(1, (int)($snajper_page ?? 1) - 1); $next = (int)($snajper_page ?? 1) + 1; ?>
    <a class="zs-btn-small" href="/admin/articles?page=<?= $prev ?>">&laquo; Poprzednia strona</a>
    <span class="zs-pagination-info">Strona <?= (int)($snajper_page ?? 1); ?></span>
    <a class="zs-btn-small" href="/admin/articles?page=<?= $next ?>">Następna strona &raquo;</a>
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

<?php
$articleId = (int)($article['id'] ?? 0);
$proofreadAt = (string)($article['proofread_at'] ?? '');
?>

<section class="admin-page-head">
  <p class="kicker">KOREKTA</p>
  <h1>Korekta tekstu</h1>
  <p>Korektor poprawia tylko lead i treść. Tytuł, zdjęcie, status, cena, premium i publikacja pozostają zablokowane.</p>
</section>

<section class="admin-panel-block proofreader-edit-wrap">
  <div class="admin-section-head">
    <div>
      <p class="kicker">Tekst #<?= $articleId ?></p>
      <h2><?= e((string)($article['title'] ?? '')) ?></h2>
    </div>
    <a class="zs-btn-small" href="/admin/role-panel?panel=proofreader">Powrót do listy</a>
  </div>

  <div class="proofreader-meta">
    <span>Autor: <b><?= e((string)($article['author_name'] ?? '—')) ?></b></span>
    <?php if ($proofreadAt !== ''): ?>
      <span class="zs-status-badge review">KOREKTA</span>
      <span>Data korekty: <b><?= e(date('d.m.Y H:i', strtotime($proofreadAt))) ?></b></span>
    <?php else: ?>
      <span class="zs-status-badge submitted">DO KOREKTY</span>
    <?php endif; ?>
  </div>

  <form method="post" action="/admin/proofreader/update" class="proofreader-form">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $articleId ?>">

    <div class="field proofreader-readonly-title">
      <span>Tytuł</span>
      <strong><?= e((string)($article['title'] ?? '')) ?></strong>
      <small>Tytuł jest tylko do odczytu. Korektor nie może go zmieniać.</small>
    </div>

    <label class="field">
      <span>Lead</span>
      <textarea name="lead" rows="4"><?= e((string)($article['lead'] ?? '')) ?></textarea>
    </label>

    <label class="field">
      <span>Treść</span>
      <?php if ($proofreadAt !== ''): ?>
        <em class="proofreader-correction-mark">KOREKTA — <?= e(date('d.m.Y H:i', strtotime($proofreadAt))) ?></em>
      <?php endif; ?>
      <textarea name="body" rows="20" required><?= e((string)($article['body'] ?? '')) ?></textarea>
    </label>

    <div class="proofreader-actions">
      <button class="btn-red" type="submit">Zapisz korektę</button>
      <a class="btn-line" href="/article?id=<?= $articleId ?>" target="_blank" rel="noopener">Podgląd</a>
    </div>
  </form>
</section>

<style>
.proofreader-edit-wrap { max-width: none; }
.proofreader-meta { display: flex; flex-wrap: wrap; gap: .75rem 1rem; align-items: center; margin: 1rem 0 1.5rem; color: #374151; }
.proofreader-form { display: grid; gap: 1.25rem; }
.proofreader-form .field { display: grid; gap: .45rem; }
.proofreader-form .field span { font-size: .75rem; letter-spacing: .12em; text-transform: uppercase; font-weight: 700; }
.proofreader-form input,
.proofreader-form textarea { width: 100%; border: 1px solid #d1d5db; padding: .8rem .9rem; font: inherit; background: #fff; }
.proofreader-readonly-title { border: 1px solid #e5e0dc; padding: .8rem .9rem; background: #fafafa; }
.proofreader-readonly-title strong { font-size: 1.1rem; }
.proofreader-readonly-title small { color: #6b7280; }
.proofreader-correction-mark { display: inline-flex; width: max-content; margin-bottom: .35rem; border: 1px solid #b91c1c; color: #b91c1c; padding: .25rem .5rem; font-size: .75rem; letter-spacing: .08em; text-transform: uppercase; font-style: normal; }
.proofreader-actions { display: flex; gap: .75rem; align-items: center; }
</style>

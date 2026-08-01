<?php $money = fn($minor) => number_format(((int)$minor) / 100, 2, ',', ' ') . ' zł'; ?>
<?php if ($campaign): ?>
<section class="admin-page-head">
  <p class="kicker">Wspólne źródło</p>
  <h1><?= e($campaign['name']) ?></h1>
  <p><?= nl2br(e($campaign['description'])) ?></p>
</section>

<section class="admin-panel-block">
  <div class="donation-progress" aria-label="Postęp zbiórki">
    <progress value="<?= max(0, min(100, (int)$campaign['progress_percent'])) ?>" max="100"><?= (int)$campaign['progress_percent'] ?>%</progress>
    <strong><?= (int)$campaign['progress_percent'] ?>%</strong>
  </div>
  <p>Zebrano: <strong><?= $money($campaign['current_amount_minor']) ?></strong> z <?= $money($campaign['target_amount_minor']) ?></p>
</section>

<section class="admin-panel-block">
  <div class="admin-section-head">
    <div>
      <span>Wsparcie</span>
      <h2>Wesprzyj kampanię</h2>
    </div>
  </div>
  <form method="post" action="/donations/manual" class="form-grid two">
    <?= csrf_field() ?>
    <input type="hidden" name="campaign_id" value="<?= (int)$campaign['id'] ?>">
    <label class="field"><span>Email opcjonalnie</span><input type="email" name="email"></label>
    <label class="field"><span>Kwota w groszach</span><input type="number" name="amount_minor" min="100" value="1000"></label>
    <label class="field full"><span>Notatka</span><textarea name="note" rows="3"></textarea></label>
    <button class="btn-red" type="submit">Wpłać / zarejestruj wpłatę</button>
  </form>
</section>
<?php else: ?>
<section class="error-panel">
  <p class="kicker">Nie znaleziono</p>
  <h1>Nie znaleziono kampanii</h1>
  <p>Wybrana kampania darowizn nie istnieje lub została zakończona.</p>
  <a class="btn-red" href="/">Powrót do strony głównej</a>
</section>
<?php endif; ?>

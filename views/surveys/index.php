<section class="admin-page-head">
  <p class="kicker">Twoja opinia też ma wartość</p>
  <h1>Ankiety i sondaże</h1>
  <p>Wypełniaj ankiety redakcyjne, społeczne, lokalne i sondaże. Za udział system zapisuje nagrodę w Twoim portfelu.</p>
</section>

<section class="articles-grid">
  <?php if (empty($surveys)): ?>
    <div class="form-card"><p>Nie ma aktualnie aktywnych ankiet ani sondaży.</p></div>
  <?php else: ?>
    <?php foreach ($surveys as $survey): ?>
      <article class="article-card">
        <div class="kicker"><?= e($survey['type']) ?></div>
        <h2><?= e($survey['title']) ?></h2>
        <p><?= e(mb_strimwidth((string)($survey['description'] ?? ''), 0, 180, '…', 'UTF-8')) ?></p>
        <div class="wallet-row"><span>Nagroda</span><strong><?= number_format(((int)$survey['reward_amount_minor'])/100, 2, ',', ' ') ?> PLN</strong></div>
        <div class="wallet-row"><span>Odpowiedzi</span><strong><?= (int)($survey['responses_count'] ?? 0) ?><?= !empty($survey['max_responses']) ? ' / ' . (int)$survey['max_responses'] : '' ?></strong></div>
        <p><a class="btn-red" href="/survey?id=<?= (int)$survey['id'] ?>">Weź udział</a></p>
      </article>
    <?php endforeach; ?>
  <?php endif; ?>
</section>

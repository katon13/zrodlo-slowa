<?php $money = fn($minor) => number_format(((int)$minor) / 100, 2, ',', ' ') . ' zł'; ?>
<article class="article-layout campaign-detail-layout">
  <main>
    <p class="breadcrumb"><a href="/campaigns">Akcje i kampanie</a> / <?= e($types[$campaign['type']] ?? $campaign['type']) ?></p>
    <p class="kicker">Możesz zarobić za udział</p>
    <h1 class="article-title"><?= e($campaign['name']) ?></h1>
    <p class="lead"><?= e($campaign['description'] ?? '') ?></p>
    <?php if (!empty($campaign['target_url'])): ?><p><a class="text-link" href="<?= e($campaign['target_url']) ?>" target="_blank" rel="noopener">Otwórz link kampanii</a></p><?php endif; ?>
  </main>

  <aside class="premium-card">
    <h3>Akcja użytkownika</h3>
    <p>System zapisze aktywność w portfelu i pokaże komunikat live „Zarobiłeś za...”.</p>
    <p class="price"><?= $money($campaign['reward_for_user_minor']) ?></p>
    <?php if(!empty($_SESSION['user_id'])): ?>
      <div class="campaign-action-stack">
        <form method="post" action="/campaign/view" class="inline-form js-watch-form"><?= csrf_field() ?><input type="hidden" name="campaign_id" value="<?= (int)$campaign['id'] ?>"><input type="hidden" name="watch_seconds" value="0" data-watch-seconds><button class="btn-red" type="submit">Zapisz obejrzenie reklamy</button><small class="admin-note">SNAJPER mierzy minimalny czas oglądania.</small></form>
        <form method="post" action="/campaign/click" class="inline-form"><?= csrf_field() ?><input type="hidden" name="campaign_id" value="<?= (int)$campaign['id'] ?>"><button class="btn-line" type="submit">Kliknij reklamę</button></form>
        <form method="post" action="/campaign/sponsored-read" class="inline-form"><?= csrf_field() ?><input type="hidden" name="campaign_id" value="<?= (int)$campaign['id'] ?>"><button class="btn-line" type="submit">Zapisz czytanie sponsorowane</button></form>
        <form method="post" action="/campaign/ppv" class="inline-form"><?= csrf_field() ?><input type="hidden" name="campaign_id" value="<?= (int)$campaign['id'] ?>"><button class="btn-line" type="submit">Zapisz udział PPV</button></form>
        <form method="post" action="/campaign/live-join" class="inline-form"><?= csrf_field() ?><input type="hidden" name="campaign_id" value="<?= (int)$campaign['id'] ?>"><button class="btn-line" type="submit">Zapisz udział live</button></form>
      </div>
    <?php else: ?>
      <p><a class="btn-red" href="/login">Zaloguj się, żeby zapisać bonus</a></p>
    <?php endif; ?>
  </aside>
</article>

<script>
(function(){
  const started = Date.now();
  document.querySelectorAll('[data-watch-seconds]').forEach(function(input){
    const form = input.closest('form');
    if (!form) return;
    form.addEventListener('submit', function(){
      input.value = String(Math.max(0, Math.floor((Date.now() - started) / 1000)));
    });
  });
})();
</script>

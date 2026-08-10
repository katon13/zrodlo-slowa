<?php
$points = (int)($invitation['reward_points'] ?? 0);
?>
<section class="referral-landing-shell">
  <article class="referral-landing-card">
    <span class="referral-promoted-badge">PROMOWANE</span>
    <p class="eyebrow">Program Talent</p>
    <h1>Zainstaluj aplikację i odbierz <?= number_format($points, 0, ',', ' ') ?> TT</h1>
    <p class="referral-lead">Ty i osoba polecająca otrzymacie po <strong><?= number_format($points, 0, ',', ' ') ?> TT</strong>. Nagroda jest już zapisana w tym zaproszeniu.</p>
    <ol class="referral-steps">
      <li>Zainstaluj i otwórz aplikację ŹRÓDŁO SŁOWA.</li>
      <li>Załóż pierwsze konto na adres wskazany w zaproszeniu.</li>
      <li>Uruchom pierwszą prawidłową sesję w aplikacji.</li>
    </ol>
    <div class="referral-landing-actions">
      <a class="btn-red" href="<?= e((string)$app_link) ?>">Otwórz w aplikacji</a>
      <a class="btn-outline" href="<?= e((string)$play_store_url) ?>" rel="nofollow">Zainstaluj z Google Play</a>
    </div>
    <p class="referral-expiry">Zaproszenie ważne do <?= e((string)($invitation['expires_at'] ?? '')) ?> UTC.</p>
  </article>
</section>

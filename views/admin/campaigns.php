<?php
$money = static fn(mixed $minor): string => number_format(((int)$minor) / 100, 2, ',', ' ') . ' PLN';
$selected = is_array($selected_campaign ?? null) ? $selected_campaign : null;
$activeCampaigns = count(array_filter($campaigns, static fn(array $campaign): bool => ($campaign['status'] ?? '') === 'active'));
$campaignBudget = array_sum(array_map(static fn(array $campaign): int => (int)($campaign['budget_minor'] ?? 0), $campaigns));
$campaignSpent = array_sum(array_map(static fn(array $campaign): int => (int)($campaign['spent_minor'] ?? 0), $campaigns));
$verifiedEvents = array_sum(array_map(static fn(array $campaign): int => (int)($campaign['verified_events_count'] ?? 0), $campaigns));
?>

<section class="admin-page-head zs-operator-page-head zs-campaign-admin-head">
  <p class="kicker">Kampanie i Zaangażowanie</p>
  <h1>Reklamodawca płaci za efekt, który potrafimy wykazać</h1>
  <p>Jedno miejsce do utworzenia zlecenia, kontroli budżetu, weryfikacji działania użytkownika, naliczenia TT i przygotowania czytelnego raportu.</p>
  <div class="zs-campaign-trust-row" aria-label="Zasady rozliczania kampanii">
    <span>01 · Zdarzenie potwierdza serwer</span>
    <span>02 · Duplikat nie zużywa budżetu</span>
    <span>03 · TT przechodzi przez Talent i ledger</span>
  </div>
</section>

<section class="zs-operator-overview" aria-label="Podsumowanie kampanii">
  <article><span>Wszystkie kampanie</span><strong><?= count($campaigns) ?></strong><small>zlecenia zapisane w systemie</small></article>
  <article class="<?= $activeCampaigns > 0 ? 'is-ready' : 'is-muted' ?>"><span>Aktywne teraz</span><strong><?= $activeCampaigns ?></strong><small>z potwierdzonym budżetem i regułą TT</small></article>
  <article><span>Potwierdzone efekty</span><strong><?= $verifiedEvents ?></strong><small>tylko zdarzenia zaakceptowane</small></article>
  <article><span>Naliczony przychód</span><strong><?= $money($campaignSpent) ?></strong><small>z budżetu <?= $money($campaignBudget) ?></small></article>
</section>

<div class="campaign-admin-page zs-operator-page">
  <?php if ($selected !== null): ?>
    <section class="admin-panel-block zs-campaign-editor-panel">
      <div class="admin-section-head">
        <div><p class="kicker">Edycja kampanii #<?= (int)$selected['id'] ?></p><h2><?= e((string)$selected['name']) ?></h2></div>
        <div class="zs-campaign-head-actions"><a class="btn-line compact" href="/admin/campaigns">Nowa kampania</a><a class="btn-red compact" href="/admin/campaigns/report?id=<?= (int)$selected['id'] ?>">Otwórz raport</a></div>
      </div>
      <div class="zs-campaign-economy-strip">
        <div><span>Za efekt</span><strong><?= $money($selected['event_cost_minor'] ?? 0) ?></strong></div>
        <div><span>Nagroda</span><strong><?= (int)($selected['talent_points'] ?? 0) ?> TT</strong></div>
        <div><span>Szacowany koszt TT</span><strong><?= $money($selected['talent_cost_minor'] ?? 0) ?></strong></div>
        <div class="<?= (int)($selected['margin_per_event_minor'] ?? 0) > 0 ? 'is-positive' : 'is-warning' ?>"><span>Marża na efekcie</span><strong><?= $money($selected['margin_per_event_minor'] ?? 0) ?></strong></div>
      </div>
      <?php
      $campaignFormData = $selected;
      $campaignFormAction = '/admin/campaigns/update';
      $campaignFormSubmit = 'Zapisz kampanię';
      require __DIR__ . '/partials/campaign_form.php';
      ?>
    </section>
  <?php else: ?>
    <section class="admin-panel-block zs-campaign-editor-panel">
      <div class="admin-section-head"><div><p class="kicker">Nowe zlecenie</p><h2>Utwórz kampanię</h2></div><span class="zs-campaign-safe-label">Kontrola przed aktywacją</span></div>
      <p class="zs-campaign-form-intro">Wypełnij cztery krótkie sekcje. Szkic można zapisać zawsze. Aktywacja jest możliwa dopiero wtedy, gdy istnieje wiarygodny dowód, potwierdzony budżet, dodatnia marża i aktywna reguła Talent.</p>
      <?php
      $campaignFormData = [];
      $campaignFormAction = '/admin/campaigns';
      $campaignFormSubmit = 'Utwórz kampanię';
      require __DIR__ . '/partials/campaign_form.php';
      ?>
    </section>
  <?php endif; ?>

  <section class="admin-panel-block">
    <div class="admin-section-head">
      <div><p class="kicker">Kontrola</p><h2>Wszystkie kampanie</h2></div>
      <span><?= count($campaigns) ?> pozycji</span>
    </div>
    <?php if ($campaigns === []): ?>
      <div class="empty-state"><h3>Nie ma jeszcze kampanii</h3><p>Pierwsze zlecenie utworzysz powyżej. Zacznij od szkicu, ustaw Talent i dopiero potem uruchom emisję.</p></div>
    <?php else: ?>
      <div class="admin-table-wrap">
        <table class="zs-admin-table zs-campaign-table">
          <thead><tr><th>Kampania</th><th>Rozliczany efekt</th><th>Stan</th><th>Budżet</th><th>Wynik</th><th>Kontrola</th></tr></thead>
          <tbody>
          <?php foreach ($campaigns as $campaign):
            $remaining = max(0, (int)$campaign['budget_minor'] - (int)($campaign['spent_minor'] ?? 0));
            $isReady = ($campaign['runtime_ready'] ?? false) === true && !empty($campaign['budget_confirmed']);
          ?>
            <tr>
              <td><strong><?= e((string)$campaign['name']) ?></strong><div class="admin-note"><?= e((string)($campaign['client_name'] ?? '')) ?> · #<?= (int)$campaign['id'] ?></div></td>
              <td><strong><?= e((string)($types[$campaign['type']] ?? $campaign['type'])) ?></strong><div class="admin-note"><?= $money($campaign['event_cost_minor'] ?? 0) ?> / <?= (int)($campaign['talent_points'] ?? 0) ?> TT</div></td>
              <td><span class="status-pill <?= $isReady ? 'is-ready' : 'is-muted' ?>"><?= e((string)($statuses[$campaign['status']] ?? $campaign['status'])) ?></span><div class="admin-note"><?= !empty($campaign['budget_confirmed']) ? 'budżet potwierdzony' : 'budżet niepotwierdzony' ?></div></td>
              <td><span class="money-display"><?= $money($remaining) ?></span><div class="admin-note">pozostało z <?= $money($campaign['budget_minor']) ?></div></td>
              <td><strong><?= (int)($campaign['verified_events_count'] ?? 0) ?> zaakceptowanych</strong><div class="admin-note"><?= (int)($campaign['rejected_events_count'] ?? 0) ?> odrzuconych · <?= (int)($campaign['duplicate_attempts_count'] ?? 0) ?> duplikatów</div></td>
              <td><a class="text-link" href="/admin/campaigns?id=<?= (int)$campaign['id'] ?>">Edytuj</a> · <a class="text-link" href="/admin/campaigns/report?id=<?= (int)$campaign['id'] ?>">Raport</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>

<script>
(function () {
  const definitions = <?= json_encode($type_definitions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  document.querySelectorAll('[data-campaign-form]').forEach(function (form) {
    const select = form.querySelector('[data-campaign-type]');
    const card = form.querySelector('[data-campaign-proof]');
    const priceFields = form.querySelectorAll('[data-campaign-price-field]');
    if (!select || !card) return;
    function render() {
      const definition = definitions[select.value] || {};
      const ready = definition.ready === true;
      const talentReady = definition.talent_active === true && Number(definition.talent_points || 0) > 0;
      priceFields.forEach(function (field) {
        field.hidden = field.getAttribute('data-campaign-price-field') !== String(definition.cost_field || '');
      });
      card.className = 'zs-campaign-proof-card ' + (ready && talentReady ? 'is-ready' : 'is-waiting');
      card.innerHTML = '<div><span>' + (ready ? 'DOWÓD GOTOWY' : 'TYLKO SZKIC') + '</span><strong>'
        + String(definition.proof || '') + '</strong></div><div><span>PROGRAM TALENT</span><strong>'
        + (talentReady ? String(definition.talent_points) + ' TT za zaakceptowany efekt' : 'Ustaw i włącz regułę w Ustawienia → Program Talent')
        + '</strong></div>';
    }
    select.addEventListener('change', render);
    render();
  });
})();
</script>

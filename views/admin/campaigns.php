<?php $money = fn($minor) => number_format(((int)$minor) / 100, 2, ',', ' ') . ' zł'; $selected = $selected_campaign ?? null; ?>
<section class="admin-page-head zs-operator-page-head">
  <p class="kicker">Sprzedaż / kampanie</p>
  <h1>Reklamy, kliknięcia, PPV i live</h1>
  <p>Moduł łączy źródła przychodu z aktywnością użytkownika: reklamy, kliknięcia, artykuły sponsorowane, transmisje live, PPV i ankiety reklamowe.</p>
</section>

<?php
$activeCampaigns = count(array_filter($campaigns, static fn(array $campaign): bool => ($campaign['status'] ?? '') === 'active'));
$campaignBudget = array_sum(array_map(static fn(array $campaign): int => (int)($campaign['budget_minor'] ?? 0), $campaigns));
$campaignSpent = array_sum(array_map(static fn(array $campaign): int => (int)($campaign['spent_minor'] ?? 0), $campaigns));
?>
<section class="zs-operator-overview" aria-label="Podsumowanie kampanii">
  <article><span>Wszystkie kampanie</span><strong><?= count($campaigns) ?></strong><small>utworzone w systemie</small></article>
  <article class="<?= $activeCampaigns > 0 ? 'is-ready' : 'is-muted' ?>"><span>Aktywne teraz</span><strong><?= $activeCampaigns ?></strong><small>kampanie emitowane użytkownikom</small></article>
  <article><span>Łączny budżet</span><strong><?= $money($campaignBudget) ?></strong><small>zaplanowany przez zleceniodawców</small></article>
  <article><span>Wykorzystano</span><strong><?= $money($campaignSpent) ?></strong><small>zaksięgowane zdarzenia</small></article>
</section>

<div class="campaign-admin-page zs-operator-page">
<section class="admin-form-grid">
  <div class="admin-panel-block">
    <div class="admin-section-head"><div><p class="kicker">Nowa</p><h2>Kampania</h2></div></div>
    <form method="post" action="/admin/campaigns" class="form-grid two">
      <?= csrf_field() ?>
      <h3 class="form-group-title">Podstawowe dane</h3>
      <label class="field"><span>Klient</span><input name="client_name"></label>
      <label class="field"><span>Nazwa</span><input name="name" required></label>
      
      <h3 class="form-group-title">Typ i Status</h3>
      <label class="field"><span>Typ</span><select name="type"><?php foreach($types as $k=>$v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>Status</span><select name="status"><?php foreach($statuses as $k=>$v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></label>
      
      <h3 class="form-group-title">Budżet i Nagrody</h3>
      <label class="field"><span>Budżet PLN</span><input name="budget" value="0,00"></label>
      <label class="field"><span>Koszt za obejrzenie PLN</span><input name="cost_per_view" value="0,00"></label>
      <label class="field"><span>Koszt za kliknięcie PLN</span><input name="cost_per_click" value="0,00"></label>
      <label class="field"><span>Koszt za ankietę PLN</span><input name="cost_per_completed_survey" value="0,00"></label>
      <label class="field"><span>Nagroda użytkownika PLN</span><input name="reward_for_user" value="0,30"></label>
      
      <h3 class="form-group-title">Terminy</h3>
      <label class="field"><span>Start</span><input name="starts_at" type="datetime-local"></label>
      <label class="field"><span>Koniec</span><input name="ends_at" type="datetime-local"></label>
      
      <h3 class="form-group-title">Link i Opis</h3>
      <label class="field full"><span>Link docelowy</span><input name="target_url" type="url" placeholder="https://..."></label>
      <label class="field full"><span>Opis</span><textarea name="description" rows="5"></textarea></label>
      
      <div class="field full">
        <button class="btn-red" type="submit">Utwórz kampanię</button>
      </div>
    </form>
  </div>

  <div class="admin-panel-block">
    <?php if($selected): ?>
      <div class="admin-section-head"><div><p class="kicker">Edycja</p><h2>Kampania #<?= (int)$selected['id'] ?></h2></div><a class="btn-line compact" href="/admin/campaigns/report?id=<?= (int)$selected['id'] ?>">Raport</a></div>
      <form method="post" action="/admin/campaigns/update" class="form-grid two">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$selected['id'] ?>">
        
        <h3 class="form-group-title">Podstawowe dane</h3>
        <label class="field"><span>Klient</span><input name="client_name" value="<?= e($selected['client_name'] ?? '') ?>"></label>
        <label class="field"><span>Nazwa</span><input name="name" required value="<?= e($selected['name']) ?>"></label>
        
        <h3 class="form-group-title">Typ i Status</h3>
        <label class="field"><span>Typ</span><select name="type"><?php foreach($types as $k=>$v): ?><option value="<?= e($k) ?>" <?= $selected['type']===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>Status</span><select name="status"><?php foreach($statuses as $k=>$v): ?><option value="<?= e($k) ?>" <?= $selected['status']===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select></label>
        
        <h3 class="form-group-title">Budżet i Nagrody</h3>
        <label class="field"><span>Budżet PLN</span><input name="budget" value="<?= number_format(((int)$selected['budget_minor'])/100,2,',','') ?>"></label>
        <label class="field"><span>Koszt view PLN</span><input name="cost_per_view" value="<?= number_format(((int)$selected['cost_per_view_minor'])/100,2,',','') ?>"></label>
        <label class="field"><span>Koszt click PLN</span><input name="cost_per_click" value="<?= number_format(((int)$selected['cost_per_click_minor'])/100,2,',','') ?>"></label>
        <label class="field"><span>Koszt ankiety PLN</span><input name="cost_per_completed_survey" value="<?= number_format(((int)$selected['cost_per_completed_survey_minor'])/100,2,',','') ?>"></label>
        <label class="field"><span>Nagroda usera PLN</span><input name="reward_for_user" value="<?= number_format(((int)$selected['reward_for_user_minor'])/100,2,',','') ?>"></label>
        
        <h3 class="form-group-title">Terminy</h3>
        <label class="field"><span>Start</span><input name="starts_at" type="datetime-local" value="<?= e($selected['starts_at'] ? str_replace(' ','T',substr($selected['starts_at'],0,16)) : '') ?>"></label>
        <label class="field"><span>Koniec</span><input name="ends_at" type="datetime-local" value="<?= e($selected['ends_at'] ? str_replace(' ','T',substr($selected['ends_at'],0,16)) : '') ?>"></label>
        
        <h3 class="form-group-title">Link i Opis</h3>
        <label class="field full"><span>Link docelowy</span><input name="target_url" type="url" value="<?= e($selected['target_url'] ?? '') ?>"></label>
        <label class="field full"><span>Opis</span><textarea name="description" rows="5"><?= e($selected['description'] ?? '') ?></textarea></label>
        
        <div class="field full">
          <button class="btn-red" type="submit">Zapisz kampanię</button>
        </div>
      </form>
    <?php else: ?>
      <div class="empty-state">
        <h3>Wybierz kampanię do edycji</h3>
        <p>Kampania zapisuje koszt zleceniodawcy, nagrodę użytkownika, transakcję portfela i komunikat live.</p>
      </div>
    <?php endif; ?>
  </div>
</section>

<section class="admin-panel-block">
  <div class="admin-section-head">
    <div><p class="kicker">Lista</p><h2>Kampanie</h2></div>
    <span><?= count($campaigns) ?> pozycji</span>
  </div>
  <div class="admin-table-wrap">
    <table class="zs-admin-table">
      <thead>
        <tr>
          <th>ID</th>
          <th>Nazwa</th>
          <th>Typ</th>
          <th>Status</th>
          <th>Budżet</th>
          <th>Koszt</th>
          <th>Zdarzenia</th>
          <th>Akcja</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($campaigns as $c): ?>
          <tr>
            <td class="admin-id">#<?= (int)$c['id'] ?></td>
            <td>
              <strong><?= e($c['name']) ?></strong>
              <div class="admin-note"><?= e($c['client_name'] ?? '') ?></div>
            </td>
            <td><?= e($types[$c['type']] ?? $c['type']) ?></td>
            <td><span class="status-pill"><?= e($statuses[$c['status']] ?? $c['status']) ?></span></td>
            <td><span class="money-display"><?= $money($c['budget_minor']) ?></span></td>
            <td><span class="money-display"><?= $money($c['spent_minor'] ?? 0) ?></span></td>
            <td><?= (int)($c['events_count'] ?? 0) ?></td>
            <td>
              <a class="text-link" href="/admin/campaigns?id=<?= (int)$c['id'] ?>">Edytuj</a> · 
              <a class="text-link" href="/admin/campaigns/report?id=<?= (int)$c['id'] ?>">Raport</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
</div>

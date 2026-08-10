<?php
$money = static fn(mixed $minor): string => number_format(((int)$minor) / 100, 2, ',', ' ') . ' PLN';
$statusLabel = static fn(string $status): string => $status === 'verified' ? 'Zaakceptowane' : 'Odrzucone';
?>
<section class="admin-page-head zs-campaign-report-head">
  <p class="kicker">Przejrzysty raport kampanii</p>
  <h1><?= e((string)$campaign['name']) ?></h1>
  <p>Każda naliczona pozycja ma użytkownika, czas, rodzaj dowodu, wynik FraudGuard, koszt z budżetu i snapshot TT. Zdarzenie odrzucone nie obciąża zleceniodawcy.</p>
  <div class="zs-campaign-trust-row">
    <span><?= e((string)($campaign['client_name'] ?? 'Zleceniodawca')) ?></span>
    <span><?= e((string)($campaign['order_reference'] ?? 'Bez numeru zlecenia')) ?></span>
    <span><?= !empty($campaign['budget_confirmed']) ? 'Budżet potwierdzony' : 'Budżet niepotwierdzony' ?></span>
  </div>
</section>

<section class="zs-operator-overview" aria-label="Wynik kampanii">
  <article class="is-ready"><span>Zaakceptowane efekty</span><strong><?= (int)$summary['verified'] ?></strong><small>tylko one zużywają budżet</small></article>
  <article><span>Odrzucone / duplikaty</span><strong><?= (int)$summary['rejected'] ?> / <?= (int)$summary['duplicates'] ?></strong><small>koszt dla klienta: 0 PLN</small></article>
  <article><span>Naliczony koszt</span><strong><?= $money($summary['spent_minor']) ?></strong><small>pozostało <?= $money($summary['remaining_minor']) ?></small></article>
  <article class="<?= (int)$summary['estimated_margin_minor'] >= 0 ? 'is-ready' : 'is-warning' ?>"><span>Szacowana marża</span><strong><?= $money($summary['estimated_margin_minor']) ?></strong><small>po koszcie <?= (int)$summary['rewarded_points'] ?> TT</small></article>
</section>

<section class="admin-panel-block zs-campaign-proof-summary">
  <div class="admin-section-head"><div><p class="kicker">Co potwierdzamy</p><h2>Dowód rozliczanego efektu</h2></div><span><?= (int)($campaign['talent_points'] ?? 0) ?> TT / efekt</span></div>
  <p><?= e((string)($campaign['proof_description'] ?? '')) ?></p>
  <div class="zs-campaign-economy-strip">
    <div><span>Cena efektu</span><strong><?= $money($campaign['event_cost_minor'] ?? 0) ?></strong></div>
    <div><span>Koszt TT</span><strong><?= $money($campaign['talent_cost_minor'] ?? 0) ?></strong></div>
    <div><span>Marża na efekcie</span><strong><?= $money($campaign['margin_per_event_minor'] ?? 0) ?></strong></div>
    <div><span>Limit efektów</span><strong><?= (int)$campaign['max_verified_events'] > 0 ? (int)$campaign['max_verified_events'] : 'budżet' ?></strong></div>
  </div>
</section>

<section class="admin-panel-block">
  <div class="admin-section-head"><div><p class="kicker">Podsumowanie</p><h2>Wyniki według rodzaju zdarzenia</h2></div><span><?= count($events) ?> pozycji</span></div>
  <?php if ($events === []): ?>
    <div class="empty-state"><h3>Jeszcze bez zdarzeń</h3><p>Raport zacznie się wypełniać po pierwszej prawidłowej interakcji użytkownika.</p></div>
  <?php else: ?>
    <div class="admin-table-wrap"><table class="zs-admin-table"><thead><tr><th>Zdarzenie</th><th>Weryfikacja</th><th>Liczba</th><th>Naliczony koszt</th><th>TT</th></tr></thead><tbody>
    <?php foreach ($events as $row): ?><tr><td><?= e((string)$row['event_type']) ?></td><td><span class="status-pill <?= $row['verification_status'] === 'verified' ? 'is-ready' : 'is-muted' ?>"><?= e($statusLabel((string)$row['verification_status'])) ?></span></td><td><?= (int)$row['cnt'] ?></td><td><?= $money($row['cost_minor']) ?></td><td><?= (int)$row['reward_points'] ?> TT</td></tr><?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</section>

<section class="admin-panel-block">
  <div class="admin-section-head"><div><p class="kicker">Ścieżka audytowa</p><h2>Ostatnie zdarzenia</h2></div></div>
  <?php if ($recent === []): ?>
    <div class="empty-state"><p>Brak zdarzeń do pokazania.</p></div>
  <?php else: ?>
    <div class="admin-table-wrap"><table class="zs-admin-table"><thead><tr><th>Czas / ID</th><th>Efekt i dowód</th><th>Użytkownik</th><th>Wynik</th><th>Koszt / TT</th></tr></thead><tbody>
    <?php foreach ($recent as $event): ?><tr>
      <td><?= e((string)$event['created_at']) ?><div class="admin-note"><?= e((string)$event['public_id']) ?></div></td>
      <td><strong><?= e((string)$event['event_type']) ?></strong><div class="admin-note"><?= e((string)($event['proof_type'] ?? '')) ?> · <?= (int)$event['watch_seconds'] ?> s</div></td>
      <td><?= e((string)($event['user_name'] ?: $event['email'] ?: ('ID ' . $event['user_id']))) ?></td>
      <td><span class="status-pill <?= $event['verification_status'] === 'verified' ? 'is-ready' : 'is-muted' ?>"><?= e($statusLabel((string)$event['verification_status'])) ?></span><div class="admin-note"><?= e((string)($event['verification_reason'] ?? '')) ?></div></td>
      <td><strong><?= $money($event['cost_minor']) ?></strong><div class="admin-note"><?= (int)$event['talent_points_snapshot'] ?> TT snapshot · wypłacono <?= (int)$event['reward_points'] ?> TT</div></td>
    </tr><?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</section>

<p><a class="btn-line" href="/admin/campaigns?id=<?= (int)$campaign['id'] ?>">Wróć do kampanii</a></p>

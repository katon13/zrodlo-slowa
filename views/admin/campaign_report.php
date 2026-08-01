<?php $money = static fn($minor) => number_format(((int)$minor) / 100, 2, ',', ' ') . ' zł'; ?>
<section class="admin-page-head">
  <p class="kicker">Raport kampanii</p>
  <h1><?= e($campaign['name']) ?></h1>
  <p>Koszty zleceniodawcy, nagrody użytkowników i zdarzenia kampanii w jednym miejscu.</p>
</section>

<section class="admin-panel-block">
  <div class="admin-section-head"><div><p class="kicker">Podsumowanie</p><h2>Zdarzenia</h2></div><span><?= count($events) ?> typów</span></div>
  <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Zdarzenie</th><th>Liczba</th><th>Koszt</th><th>Nagrody</th></tr></thead><tbody><?php foreach($events as $row): ?><tr><td><?= e($row['event_type']) ?></td><td><?= (int)$row['cnt'] ?></td><td><?= $money($row['cost_minor']) ?></td><td><?= $money($row['reward_minor']) ?></td></tr><?php endforeach; ?></tbody></table></div>
</section>

<section class="admin-panel-block">
  <div class="admin-section-head"><div><p class="kicker">Historia</p><h2>Ostatnie zdarzenia</h2></div></div>
  <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Czas</th><th>Typ</th><th>Użytkownik</th><th>Koszt</th><th>Nagroda</th></tr></thead><tbody><?php foreach($recent as $e): ?><tr><td><?= e($e['created_at']) ?></td><td><?= e($e['event_type']) ?></td><td><?= e($e['user_name'] ?: $e['email'] ?: ('ID '.$e['user_id'])) ?></td><td><?= $money($e['cost_minor']) ?></td><td><?= $money($e['reward_minor']) ?></td></tr><?php endforeach; ?></tbody></table></div>
</section>

<p><a class="btn-line" href="/admin/campaigns?id=<?= (int)$campaign['id'] ?>">Wróć do kampanii</a></p>

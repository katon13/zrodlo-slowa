<section class="admin-page-head">
  <p class="kicker">Raport ankiety</p>
  <h1><?= e($survey['title']) ?></h1>
  <p>Odpowiedzi i przyznanie TT w jednym czytelnym zestawieniu.</p>
</section>

<section class="admin-panel-block">
  <div class="admin-section-head"><div><p class="kicker">Podsumowanie</p><h2>Odpowiedzi</h2></div><span><?= count($responses) ?> odpowiedzi</span></div>
  <?php foreach ($summary as $row): ?>
    <div class="form-card editorial-note">
      <h3><?= e($row['question']['question_text']) ?></h3>
      <?php if (empty($row['answers'])): ?>
        <p class="admin-note">Brak odpowiedzi.</p>
      <?php else: ?>
        <?php foreach ($row['answers'] as $answer => $count): ?>
          <div class="wallet-row"><span><?= e($answer) ?></span><strong><?= (int)$count ?></strong></div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</section>

<section class="admin-panel-block">
  <div class="admin-section-head"><div><p class="kicker">Uczestnicy</p><h2>Historia odpowiedzi i Talentu</h2></div></div>
  <div class="admin-table-wrap">
    <table class="admin-table admin-table-wide">
      <thead><tr><th>Użytkownik</th><th>Talent</th><th>Stan</th><th>Data</th></tr></thead>
      <tbody>
        <?php foreach ($responses as $r): ?>
          <tr>
            <td><?= e($r['display_name']) ?><span class="admin-note"><?= e($r['email']) ?></span></td>
            <td><?= (int)($survey_reward_points ?? 0) ?> TT</td>
            <td><?= match((string)$r['reward_status']){'paid'=>'Rozliczone','pending'=>'Czeka na przetworzenie','rejected'=>'Bez nagrody',default=>'Zapisane'} ?></td>
            <td><?= e($r['completed_at']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p><a class="btn-line" href="/admin/surveys?id=<?= (int)$survey['id'] ?>">Wróć do ankiety</a></p>
</section>

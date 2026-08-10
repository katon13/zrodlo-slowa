<section class="admin-page-head">
  <p class="kicker"><?= e(t('admin.survey_report.raport_ankiety')) ?></p>
  <h1><?= e($survey['title']) ?></h1>
  <p><?= e(t('admin.survey_report.odpowiedzi_i_przyznanie_tt_w_jednym_czytelnym_zestawieniu')) ?></p>
</section>

<section class="admin-panel-block">
  <div class="admin-section-head"><div><p class="kicker"><?= e(t('admin.survey_report.podsumowanie')) ?></p><h2><?= e(t('survey.answers')) ?></h2></div><span><?= count($responses) ?> odpowiedzi</span></div>
  <?php foreach ($summary as $row): ?>
    <div class="form-card editorial-note">
      <h3><?= e($row['question']['question_text']) ?></h3>
      <?php if (empty($row['answers'])): ?>
        <p class="admin-note"><?= e(t('admin.survey_report.brak_odpowiedzi')) ?></p>
      <?php else: ?>
        <?php foreach ($row['answers'] as $answer => $count): ?>
          <div class="wallet-row"><span><?= e($answer) ?></span><strong><?= (int)$count ?></strong></div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</section>

<section class="admin-panel-block">
  <div class="admin-section-head"><div><p class="kicker"><?= e(t('admin.survey_report.uczestnicy')) ?></p><h2><?= e(t('admin.survey_report.historia_odpowiedzi_i_talentu')) ?></h2></div></div>
  <div class="admin-table-wrap">
    <table class="admin-table admin-table-wide">
      <thead><tr><th><?= e(t('wallet.orders.table.user')) ?></th><th><?= e(t('author.dashboard.permission_talent')) ?></th><th><?= e(t('admin.campaigns.stan')) ?></th><th><?= e(t('wallet.history.table.date')) ?></th></tr></thead>
      <tbody>
        <?php foreach ($responses as $r): ?>
          <tr>
            <td><?= e($r['display_name']) ?><span class="admin-note"><?= e($r['email']) ?></span></td>
            <td><?= (int)($survey_reward_points ?? 0) ?> TT</td>
          <td><?= e(match((string)$r['reward_status']){'paid'=>t('admin.survey_report.reward_paid'),'pending'=>t('admin.survey_report.reward_pending'),'rejected'=>t('admin.survey_report.reward_rejected'),default=>t('admin.survey_report.reward_saved')}) ?></td>
            <td><?= e($r['completed_at']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p><a class="btn-line" href="/admin/surveys?id=<?= (int)$survey['id'] ?>"><?= e(t('admin.survey_report.wroc_do_ankiety')) ?></a></p>
</section>

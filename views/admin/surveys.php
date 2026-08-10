<?php
$typeLabels = [
  'consumer' => t('survey.type.consumer'),
  'political_poll' => t('admin.surveys.sondaz_wyborczy'),
  'social_poll' => t('admin.surveys.sondaz_spoeczny'),
  'local_poll' => t('admin.surveys.sondaz_lokalny'),
  'advertising' => t('survey.type.advertising'),
  'editorial' => t('survey.type.editorial'),
  'market_research' => t('survey.type.market_research'),
];
$statusLabels = ['draft'=>t('article.status.draft'),'active'=>t('admin.surveys.status_active'),'paused'=>t('admin.surveys.status_paused'),'closed'=>t('admin.surveys.zamknieta')];
$editing = $selected_survey ?? null;
?>
<section class="admin-page-head zs-operator-page-head">
  <p class="kicker"><?= e(t('admin.surveys.redakcja_badania')) ?></p>
  <h1><?= e(t('survey.index.title')) ?></h1>
  <p><?= e(t('admin.surveys.zleceniodawca_lub_redakcja_przygotowuje_badanie_uzytkow_096cc694')) ?></p>
</section>

<?php
$activeSurveys = count(array_filter($surveys, static fn(array $survey): bool => ($survey['status'] ?? '') === 'active'));
$surveyResponses = array_sum(array_map(static fn(array $survey): int => (int)($survey['responses_count'] ?? 0), $surveys));
$surveyQuestions = array_sum(array_map(static fn(array $survey): int => (int)($survey['questions_count'] ?? 0), $surveys));
?>
<section class="zs-operator-overview" aria-label="<?= e(t('admin.surveys.podsumowanie_ankiet')) ?>">
  <article><span><?= e(t('admin.surveys.wszystkie_badania')) ?></span><strong><?= count($surveys) ?></strong><small><?= e(t('admin.surveys.ankiety_i_sondaze')) ?></small></article>
  <article class="<?= $activeSurveys > 0 ? 'is-ready' : 'is-muted' ?>"><span><?= e(t('admin.campaigns.aktywne_teraz')) ?></span><strong><?= $activeSurveys ?></strong><small><?= e(t('admin.surveys.przyjmuja_odpowiedzi')) ?></small></article>
  <article><span><?= e(t('survey.answers')) ?></span><strong><?= $surveyResponses ?></strong><small><?= e(t('admin.surveys.zapisane_formularze')) ?></small></article>
  <article><span><?= e(t('admin.surveys.pytania')) ?></span><strong><?= $surveyQuestions ?></strong><small><?= e(t('admin.surveys.w_arkuszach_badan')) ?></small></article>
</section>

<section class="admin-panel-block zs-operator-panel">
  <div class="admin-section-head">
  <div><p class="kicker"><?= e(t($editing ? 'admin.surveys.edit_heading' : 'admin.surveys.new_heading')) ?></p><h2><?= $editing ? e($editing['title']) : e(t('admin.surveys.utworz_ankiete_sondaz')) ?></h2></div>
  </div>
  <form class="zs-survey-form" method="post" action="<?= $editing ? '/admin/surveys/update' : '/admin/surveys' ?>">
    <?= csrf_field() ?>
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><?php endif; ?>
    <label class="field-full"><span><?= e(t('admin.surveys.tytu_ankiety')) ?></span><input name="title" value="<?= e((string)($editing['title'] ?? '')) ?>" placeholder="<?= e(t('admin.surveys.np_badanie_opinii_o_nowych_funkcjach_portfela')) ?>" required></label>
    <label><span><?= e(t('admin.surveys.typ_badania')) ?></span><select name="type"><?php foreach ($types as $type): ?><option value="<?= e($type) ?>" <?= (($editing['type'] ?? '') === $type) ? 'selected' : '' ?>><?= e($typeLabels[$type] ?? $type) ?></option><?php endforeach; ?></select></label>
    <label><span><?= e(t('admin.surveys.status_publikacji')) ?></span><select name="status"><?php foreach ($statusLabels as $value => $label): ?><option value="<?= e($value) ?>" <?= (($editing['status'] ?? 'draft') === $value) ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
    <label><span><?= e(t('admin.surveys.zleceniodawca_klient')) ?></span><input name="client_name" value="<?= e((string)($editing['client_name'] ?? '')) ?>" placeholder="<?= e(t('admin.surveys.redakcja_zroda_sowa')) ?>"></label>
    <label><span><?= e(t('admin.surveys.budzet_cakowity_pln')) ?></span><input name="budget" value="<?= isset($editing['budget_minor']) ? number_format(((int)$editing['budget_minor'])/100, 2, ',', '') : '0,00' ?>"></label>
    <label><span><?= e(t('admin.surveys.nagroda_dla_respondenta_pln')) ?></span><input name="reward_amount" value="<?= isset($editing['reward_amount_minor']) ? number_format(((int)$editing['reward_amount_minor'])/100, 2, ',', '') : '0,50' ?>"></label>
    <label><span><?= e(t('admin.partials.campaign_surveys.limit_odpowiedzi')) ?></span><input type="number" name="max_responses" value="<?= e((string)($editing['max_responses'] ?? '')) ?>" placeholder="<?= e(t('admin.surveys.0_brak_limitu')) ?>"></label>
    <label><span><?= e(t('admin.surveys.data_rozpoczecia')) ?></span><input type="datetime-local" name="starts_at" value="<?= !empty($editing['starts_at']) ? e(date('Y-m-d\TH:i', strtotime((string)$editing['starts_at']))) : '' ?>"></label>
    <label><span><?= e(t('admin.surveys.data_zakonczenia')) ?></span><input type="datetime-local" name="ends_at" value="<?= !empty($editing['ends_at']) ? e(date('Y-m-d\TH:i', strtotime((string)$editing['ends_at']))) : '' ?>"></label>
    <label class="field-full"><span><?= e(t('admin.surveys.opis_ankiety_i_cel_badania')) ?></span><textarea name="description" rows="4" placeholder="<?= e(t('admin.surveys.opisz_krotko_czego_dotyczy_badanie')) ?>"><?= e((string)($editing['description'] ?? '')) ?></textarea></label>
    <div class="field-full"><button class="btn-red" type="submit"><?= e(t($editing ? 'admin.surveys.save_changes' : 'admin.surveys.utworz_nowa_ankiete')) ?></button></div>
  </form>
</section>

<?php if ($editing): ?>
<section class="admin-panel-block zs-operator-panel">
  <div class="admin-section-head"><div><p class="kicker"><?= e(t('admin.surveys.pytania')) ?></p><h2><?= e(t('admin.partials.campaign_surveys.pytania_ankiety')) ?></h2></div><span><?= e(str_replace('{count}', (string)count($selected_questions), t('admin.surveys.questions_count'))) ?></span></div>
  <?php if (empty($selected_questions)): ?><p class="admin-note"><?= e(t('admin.surveys.dodaj_pierwsze_pytanie_ankieta_bez_pytan_nie_przyjmie_odpowiedzi')) ?></p><?php endif; ?>
  <?php foreach ($selected_questions as $q): ?>
    <?php $options = json_decode((string)($q['options_json'] ?? '[]'), true) ?: []; ?>
    <div class="zs-question-card">
      <strong>#<?= (int)$q['sort_order'] ?> &nbsp; <?= e($q['question_text']) ?></strong>
      <div class="admin-note">
        <?= e(t('admin.surveys.type_prefix')) ?> <?= e($q['question_type']) ?>
        <span style="color: var(--zs-line); margin: 0 8px;">|</span>
              <?= (int)$q['is_required'] === 1 ? ' <span style="color: var(--zs-red);">' . e(t('admin.surveys.required')) . '</span>' : e(t('admin.surveys.optional')) ?>
      </div>
      <?php if ($options): ?>
        <div class="admin-note" style="margin-top: 8px; text-transform: none; letter-spacing: 0;">
          <span style="font-weight: 700;"><?= e(t('admin.surveys.opcje')) ?></span> <?= e(implode(' / ', $options)) ?>
        </div>
      <?php endif; ?>
      <form method="post" action="/admin/surveys/questions/delete" class="inline">
        <?= csrf_field() ?>
        <input type="hidden" name="survey_id" value="<?= (int)$editing['id'] ?>">
        <input type="hidden" name="question_id" value="<?= (int)$q['id'] ?>">
        <button class="btn-line compact" type="submit"><?= e(t('admin.surveys.usun_to_pytanie')) ?></button>
      </form>
    </div>
  <?php endforeach; ?>

  <form class="zs-survey-form" method="post" action="/admin/surveys/questions">
    <?= csrf_field() ?>
    <input type="hidden" name="survey_id" value="<?= (int)$editing['id'] ?>">
    <label class="field-full"><span><?= e(t('admin.partials.campaign_surveys.tresc_pytania')) ?></span><input name="question_text" placeholder="<?= e(t('admin.surveys.wpisz_tresc_pytania')) ?>" required></label>
    <label><span><?= e(t('admin.surveys.typ_odpowiedzi')) ?></span><select name="question_type"><option value="single_choice"><?= e(t('admin.surveys.jedna_odpowiedz')) ?></option><option value="multiple_choice"><?= e(t('admin.surveys.wiele_odpowiedzi')) ?></option><option value="text"><?= e(t('admin.surveys.odpowiedz_tekstowa')) ?></option></select></label>
    <label><span><?= e(t('editorial.editing.display_order')) ?></span><input type="number" name="sort_order" value="0"></label>
    <div style="display: flex; align-items: center; gap: 10px; padding-top: 25px;">
      <input type="checkbox" name="is_required" id="is_req" checked style="width: auto !important;">
      <label for="is_req" style="display: inline; font-size: 14px; text-transform: none; letter-spacing: 0; color: var(--zs-black);"><?= e(t('admin.surveys.pytanie_wymagane')) ?></label>
    </div>
    <label class="field-full"><span><?= e(t('admin.surveys.opcje_wyboru_kazda_w_osobnej_linii')) ?></span><textarea name="options" rows="5" placeholder="<?= e(t('admin.surveys.opcja_1_10_opcja_2_10_opcja_3')) ?>"></textarea></label>
    <div class="field-full"><button class="btn-red" type="submit"><?= e(t('admin.surveys.dodaj_pytanie_do_ankiety')) ?></button></div>
  </form>
</section>
<?php endif; ?>

<section class="admin-panel-block zs-operator-panel">
  <div class="admin-section-head"><div><p class="kicker"><?= e(t('admin.surveys.lista')) ?></p><h2><?= e(t('admin.partials.campaign_surveys.ankiety_w_systemie')) ?></h2></div><span><?= e(str_replace('{count}', (string)count($surveys), t('admin.common.items_count'))) ?></span></div>
  <div class="admin-table-wrap" style="background: #fff; border: 1px solid var(--zs-line); padding: 10px;">
    <table class="zs-admin-table">
      <thead><tr><th>ID</th><th><?= e(t('admin.surveys.tytu_i_parametry_ankiety')) ?></th><th><?= e(t('wallet.history.table.status')) ?></th><th><?= e(t('admin.surveys.nagroda')) ?></th><th><?= e(t('admin.surveys.postep_pytania')) ?></th><th><?= e(t('admin.categories.akcje')) ?></th></tr></thead>
      <tbody>
        <?php foreach ($surveys as $s): ?>
          <tr>
            <td style="color: var(--zs-muted); font-family: monospace;">#<?= (int)$s['id'] ?></td>
            <td>
              <div style="margin-bottom: 4px;"><strong><?= e($s['title']) ?></strong></div>
              <div class="admin-note" style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em;">
                <?= e($typeLabels[$s['type']] ?? $s['type']) ?>
                <?= !empty($s['client_name']) ? ' <span style="color: var(--zs-line); margin: 0 4px;">|</span> ' . e($s['client_name']) : '' ?>
              </div>
            </td>
            <td><span class="status-pill status-<?= e($s['status']) ?>"><?= e($statusLabels[$s['status']] ?? $s['status']) ?></span></td>
            <td style="font-weight: 700; color: var(--zs-red);"><?= number_format(((int)$s['reward_amount_minor'])/100, 2, ',', ' ') ?> PLN</td>
            <td>
              <div style="font-size: 13px;">
                <strong><?= (int)($s['responses_count'] ?? 0) ?></strong><?= !empty($s['max_responses']) ? ' / ' . (int)$s['max_responses'] : '' ?> <small style="color: var(--zs-muted);"><?= e(t('admin.surveys.respondentow')) ?></small>
              </div>
              <div style="font-size: 11px; color: var(--zs-muted); margin-top: 2px;">
                <?= e(str_replace('{count}', (string)(int)($s['questions_count'] ?? 0), t('admin.surveys.questions_in_sheet'))) ?>
              </div>
            </td>
            <td style="white-space: nowrap;">
              <a class="btn-line compact" href="/admin/surveys?id=<?= (int)$s['id'] ?>"><?= e(t('author.dashboard.edit')) ?></a>
              <a class="btn-red compact" href="/admin/surveys/report?id=<?= (int)$s['id'] ?>"><?= e(t('admin.campaigns.raport')) ?></a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('form').forEach(function(form) {
    form.addEventListener('submit', function() {
      var btn = form.querySelector('button[type="submit"]');
      if (btn) {
        btn.disabled = true;
        btn.innerText = '...';
      }
    });
  });
});
</script>

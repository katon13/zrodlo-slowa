<?php
$typeLabels = [
  'consumer' => 'konsumencka',
  'political_poll' => 'sondaż wyborczy',
  'social_poll' => 'sondaż społeczny',
  'local_poll' => 'sondaż lokalny',
  'advertising' => 'reklamowa',
  'editorial' => 'redakcyjna',
  'market_research' => 'badanie rynku',
];
$statusLabels = ['draft'=>'szkic','active'=>'aktywna','paused'=>'pauza','closed'=>'zamknięta'];
$editing = $selected_survey ?? null;
?>
<section class="admin-page-head zs-operator-page-head">
  <p class="kicker">Redakcja / badania</p>
  <h1>Ankiety i sondaże</h1>
  <p>Zleceniodawca lub redakcja przygotowuje badanie, użytkownik odpowiada, a system zapisuje wynik i należną nagrodę.</p>
</section>

<?php
$activeSurveys = count(array_filter($surveys, static fn(array $survey): bool => ($survey['status'] ?? '') === 'active'));
$surveyResponses = array_sum(array_map(static fn(array $survey): int => (int)($survey['responses_count'] ?? 0), $surveys));
$surveyQuestions = array_sum(array_map(static fn(array $survey): int => (int)($survey['questions_count'] ?? 0), $surveys));
?>
<section class="zs-operator-overview" aria-label="Podsumowanie ankiet">
  <article><span>Wszystkie badania</span><strong><?= count($surveys) ?></strong><small>ankiety i sondaże</small></article>
  <article class="<?= $activeSurveys > 0 ? 'is-ready' : 'is-muted' ?>"><span>Aktywne teraz</span><strong><?= $activeSurveys ?></strong><small>przyjmują odpowiedzi</small></article>
  <article><span>Odpowiedzi</span><strong><?= $surveyResponses ?></strong><small>zapisane formularze</small></article>
  <article><span>Pytania</span><strong><?= $surveyQuestions ?></strong><small>w arkuszach badań</small></article>
</section>

<section class="admin-panel-block zs-operator-panel">
  <div class="admin-section-head">
    <div><p class="kicker"><?= $editing ? 'Edycja ankiety' : 'Nowa ankieta' ?></p><h2><?= $editing ? e($editing['title']) : 'Utwórz ankietę / sondaż' ?></h2></div>
  </div>
  <form class="zs-survey-form" method="post" action="<?= $editing ? '/admin/surveys/update' : '/admin/surveys' ?>">
    <?= csrf_field() ?>
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><?php endif; ?>
    <label class="field-full"><span>Tytuł ankiety</span><input name="title" value="<?= e((string)($editing['title'] ?? '')) ?>" placeholder="np. Badanie opinii o nowych funkcjach portfela" required></label>
    <label><span>Typ badania</span><select name="type"><?php foreach ($types as $type): ?><option value="<?= e($type) ?>" <?= (($editing['type'] ?? '') === $type) ? 'selected' : '' ?>><?= e($typeLabels[$type] ?? $type) ?></option><?php endforeach; ?></select></label>
    <label><span>Status publikacji</span><select name="status"><?php foreach ($statusLabels as $value => $label): ?><option value="<?= e($value) ?>" <?= (($editing['status'] ?? 'draft') === $value) ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
    <label><span>Zleceniodawca / Klient</span><input name="client_name" value="<?= e((string)($editing['client_name'] ?? '')) ?>" placeholder="Redakcja Źródła Słowa"></label>
    <label><span>Budżet całkowity (PLN)</span><input name="budget" value="<?= isset($editing['budget_minor']) ? number_format(((int)$editing['budget_minor'])/100, 2, ',', '') : '0,00' ?>"></label>
    <label><span>Nagroda dla respondenta (PLN)</span><input name="reward_amount" value="<?= isset($editing['reward_amount_minor']) ? number_format(((int)$editing['reward_amount_minor'])/100, 2, ',', '') : '0,50' ?>"></label>
    <label><span>Limit odpowiedzi</span><input type="number" name="max_responses" value="<?= e((string)($editing['max_responses'] ?? '')) ?>" placeholder="0 = brak limitu"></label>
    <label><span>Data rozpoczęcia</span><input type="datetime-local" name="starts_at" value="<?= !empty($editing['starts_at']) ? e(date('Y-m-d\TH:i', strtotime((string)$editing['starts_at']))) : '' ?>"></label>
    <label><span>Data zakończenia</span><input type="datetime-local" name="ends_at" value="<?= !empty($editing['ends_at']) ? e(date('Y-m-d\TH:i', strtotime((string)$editing['ends_at']))) : '' ?>"></label>
    <label class="field-full"><span>Opis ankiety i cel badania</span><textarea name="description" rows="4" placeholder="Opisz krótko, czego dotyczy badanie..."><?= e((string)($editing['description'] ?? '')) ?></textarea></label>
    <div class="field-full"><button class="btn-red" type="submit"><?= $editing ? 'Zapisz zmiany w ankiecie' : 'Utwórz nową ankietę' ?></button></div>
  </form>
</section>

<?php if ($editing): ?>
<section class="admin-panel-block zs-operator-panel">
  <div class="admin-section-head"><div><p class="kicker">Pytania</p><h2>Pytania ankiety</h2></div><span><?= count($selected_questions) ?> pytań</span></div>
  <?php if (empty($selected_questions)): ?><p class="admin-note">Dodaj pierwsze pytanie. Ankieta bez pytań nie przyjmie odpowiedzi.</p><?php endif; ?>
  <?php foreach ($selected_questions as $q): ?>
    <?php $options = json_decode((string)($q['options_json'] ?? '[]'), true) ?: []; ?>
    <div class="zs-question-card">
      <strong>#<?= (int)$q['sort_order'] ?> &nbsp; <?= e($q['question_text']) ?></strong>
      <div class="admin-note">
        Typ: <?= e($q['question_type']) ?> 
        <span style="color: var(--zs-line); margin: 0 8px;">|</span>
        <?= (int)$q['is_required'] === 1 ? ' <span style="color: var(--zs-red);">Wymagane</span>' : 'Opcjonalne' ?>
      </div>
      <?php if ($options): ?>
        <div class="admin-note" style="margin-top: 8px; text-transform: none; letter-spacing: 0;">
          <span style="font-weight: 700;">Opcje:</span> <?= e(implode(' / ', $options)) ?>
        </div>
      <?php endif; ?>
      <form method="post" action="/admin/surveys/questions/delete" class="inline">
        <?= csrf_field() ?>
        <input type="hidden" name="survey_id" value="<?= (int)$editing['id'] ?>">
        <input type="hidden" name="question_id" value="<?= (int)$q['id'] ?>">
        <button class="btn-line compact" type="submit">Usuń to pytanie</button>
      </form>
    </div>
  <?php endforeach; ?>

  <form class="zs-survey-form" method="post" action="/admin/surveys/questions">
    <?= csrf_field() ?>
    <input type="hidden" name="survey_id" value="<?= (int)$editing['id'] ?>">
    <label class="field-full"><span>Treść pytania</span><input name="question_text" placeholder="Wpisz treść pytania..." required></label>
    <label><span>Typ odpowiedzi</span><select name="question_type"><option value="single_choice">jedna odpowiedź</option><option value="multiple_choice">wiele odpowiedzi</option><option value="text">odpowiedź tekstowa</option></select></label>
    <label><span>Kolejność</span><input type="number" name="sort_order" value="0"></label>
    <div style="display: flex; align-items: center; gap: 10px; padding-top: 25px;">
      <input type="checkbox" name="is_required" id="is_req" checked style="width: auto !important;">
      <label for="is_req" style="display: inline; font-size: 14px; text-transform: none; letter-spacing: 0; color: var(--zs-black);">Pytanie wymagane</label>
    </div>
    <label class="field-full"><span>Opcje wyboru (każda w osobnej linii)</span><textarea name="options" rows="5" placeholder="Opcja 1&#10;Opcja 2&#10;Opcja 3"></textarea></label>
    <div class="field-full"><button class="btn-red" type="submit">Dodaj pytanie do ankiety</button></div>
  </form>
</section>
<?php endif; ?>

<section class="admin-panel-block zs-operator-panel">
  <div class="admin-section-head"><div><p class="kicker">Lista</p><h2>Ankiety w systemie</h2></div><span><?= count($surveys) ?> pozycji</span></div>
  <div class="admin-table-wrap" style="background: #fff; border: 1px solid var(--zs-line); padding: 10px;">
    <table class="zs-admin-table">
      <thead><tr><th>ID</th><th>Tytuł i parametry ankiety</th><th>Status</th><th>Nagroda</th><th>Postęp / Pytania</th><th>Akcje</th></tr></thead>
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
                <strong><?= (int)($s['responses_count'] ?? 0) ?></strong><?= !empty($s['max_responses']) ? ' / ' . (int)$s['max_responses'] : '' ?> <small style="color: var(--zs-muted);">respondentów</small>
              </div>
              <div style="font-size: 11px; color: var(--zs-muted); margin-top: 2px;">
                <?= (int)($s['questions_count'] ?? 0) ?> pytań w arkuszu
              </div>
            </td>
            <td style="white-space: nowrap;">
              <a class="btn-line compact" href="/admin/surveys?id=<?= (int)$s['id'] ?>">Edytuj</a>
              <a class="btn-red compact" href="/admin/surveys/report?id=<?= (int)$s['id'] ?>">Raport</a>
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

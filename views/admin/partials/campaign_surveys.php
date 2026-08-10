<?php
$surveyEdit = is_array($selected_survey ?? null) ? $selected_survey : [];
$surveyStatuses = ['draft'=>'Szkic','active'=>'Aktywna','paused'=>'Wstrzymana','closed'=>'Zakończona'];
$surveyTypeLabels = ['consumer'=>'Konsumencka','political_poll'=>'Sondaż polityczny','social_poll'=>'Sondaż społeczny','local_poll'=>'Sondaż lokalny','advertising'=>'Reklamowa','editorial'=>'Redakcyjna','market_research'=>'Badanie rynku'];
$questionTypeLabels = ['single_choice'=>'Jedna odpowiedź','multiple_choice'=>'Wiele odpowiedzi','text'=>'Odpowiedź tekstowa'];
?>
<section class="admin-panel-block zs-survey-workbench" id="ankiety">
  <div class="admin-section-head"><div><p class="kicker">Treść ankiety</p><h2><?= $surveyEdit ? 'Edytuj ankietę' : 'Utwórz ankietę' ?></h2><p>Najpierw przygotuj ankietę i pytania. Następnie przypnij ją do zlecenia reklamodawcy powyżej.</p></div><?php if($surveyEdit):?><a class="btn-line compact" href="/admin/campaigns?tab=survey#ankiety">Nowa ankieta</a><?php endif;?></div>
  <aside class="zs-human-note"><strong>Jedno proste rozliczenie</strong><p>Budżet i stawkę PLN ustawiasz w kampanii przypiętej do ankiety. Nagrodę TT za ukończenie ustawiasz w Programie Talent.</p></aside>
  <form method="post" action="<?= $surveyEdit ? '/admin/surveys/update' : '/admin/surveys' ?>" class="form-grid two">
    <?= csrf_field() ?><?php if($surveyEdit):?><input type="hidden" name="id" value="<?= (int)$surveyEdit['id'] ?>"><?php endif;?>
    <label class="field"><span>Tytuł</span><input name="title" required value="<?= e((string)($surveyEdit['title']??'')) ?>"></label>
    <label class="field"><span>Rodzaj</span><select name="type"><?php foreach($survey_types as $type):?><option value="<?= e($type) ?>" <?= ($surveyEdit['type']??'editorial')===$type?'selected':'' ?>><?= e($surveyTypeLabels[$type]??$type) ?></option><?php endforeach;?></select></label>
    <label class="field"><span>Zleceniodawca</span><input name="client_name" value="<?= e((string)($surveyEdit['client_name']??'')) ?>"></label>
    <label class="field"><span>Stan ankiety</span><select name="status"><?php foreach($surveyStatuses as $key=>$label):?><option value="<?= e($key) ?>" <?= ($surveyEdit['status']??'draft')===$key?'selected':'' ?>><?= e($label) ?></option><?php endforeach;?></select></label>
    <input type="hidden" name="budget" value="0"><input type="hidden" name="reward_amount" value="0">
    <label class="field"><span>Limit odpowiedzi</span><input type="number" min="0" name="max_responses" value="<?= (int)($surveyEdit['max_responses']??0) ?>"><small>0 oznacza brak limitu liczbowego.</small></label>
    <label class="field full"><span>Opis</span><textarea name="description" rows="3"><?= e((string)($surveyEdit['description']??'')) ?></textarea></label>
    <label class="field"><span>Start</span><input type="datetime-local" name="starts_at" value="<?= e(!empty($surveyEdit['starts_at'])?str_replace(' ','T',substr((string)$surveyEdit['starts_at'],0,16)):'') ?>"></label>
    <label class="field"><span>Koniec</span><input type="datetime-local" name="ends_at" value="<?= e(!empty($surveyEdit['ends_at'])?str_replace(' ','T',substr((string)$surveyEdit['ends_at'],0,16)):'') ?>"></label>
    <div class="full"><button class="btn-red" type="submit"><?= $surveyEdit?'Zapisz ankietę':'Utwórz ankietę' ?></button></div>
  </form>

  <?php if($surveyEdit):?>
    <details class="zs-human-details" open><summary><span>+</span><strong>Pytania ankiety</strong></summary>
      <form method="post" action="/admin/surveys/questions" class="form-grid two">
        <?= csrf_field() ?><input type="hidden" name="survey_id" value="<?= (int)$surveyEdit['id'] ?>">
        <label class="field full"><span>Treść pytania</span><input name="question_text" required></label>
        <label class="field"><span>Rodzaj odpowiedzi</span><select name="question_type"><option value="single_choice">Jedna odpowiedź</option><option value="multiple_choice">Wiele odpowiedzi</option><option value="text">Odpowiedź tekstowa</option></select></label>
        <label class="field"><span>Kolejność</span><input type="number" name="sort_order" value="0"></label>
        <label class="field full"><span>Opcje odpowiedzi</span><textarea name="options" rows="4" placeholder="Każda opcja w osobnej linii"></textarea><small>Dla odpowiedzi tekstowej pozostaw puste.</small></label>
        <label class="field zs-checkbox-field"><input type="checkbox" name="is_required" value="1" checked><span>Odpowiedź wymagana</span></label>
        <div><button class="btn-red" type="submit">Dodaj pytanie</button></div>
      </form>
      <div class="zs-question-list"><?php foreach($selected_questions as $question):?><article><div><strong><?= e((string)$question['question_text']) ?></strong><small><?= e($questionTypeLabels[(string)$question['question_type']]??'Odpowiedź') ?><?= (int)$question['is_required']===1?' · wymagane':'' ?></small></div><form method="post" action="/admin/surveys/questions/delete" onsubmit="return confirm('Usunąć to pytanie?')"><?= csrf_field() ?><input type="hidden" name="survey_id" value="<?= (int)$surveyEdit['id'] ?>"><input type="hidden" name="question_id" value="<?= (int)$question['id'] ?>"><button class="btn-line compact" type="submit">Usuń</button></form></article><?php endforeach;?></div>
    </details>
  <?php endif;?>
</section>

<section class="admin-panel-block"><div class="admin-section-head"><div><p class="kicker">Ankiety w systemie</p><h2>Wybierz, edytuj lub sprawdź wyniki</h2></div><span><?= count($surveys) ?> pozycji</span></div>
  <?php if($surveys===[]):?><div class="empty-state"><p>Nie ma jeszcze ankiet.</p></div><?php else:?><div class="admin-table-wrap"><table class="zs-admin-table"><thead><tr><th>Ankieta</th><th>Stan</th><th>Odpowiedzi</th><th>Kampania</th><th>Działanie</th></tr></thead><tbody><?php foreach($surveys as $survey):?><tr><td><strong><?= e((string)$survey['title']) ?></strong><small><?= e((string)($survey['client_name']??'Redakcja')) ?></small></td><td><?= e($surveyStatuses[(string)$survey['status']]??'Nieznany') ?></td><td><?= (int)($survey['responses_count']??0) ?><?= !empty($survey['max_responses'])?' / '.(int)$survey['max_responses']:'' ?></td><td><?php if(!empty($survey['campaign_id'])):?><a class="text-link" href="/admin/campaigns?tab=survey&id=<?= (int)$survey['campaign_id'] ?>"><?= e((string)$survey['campaign_name']) ?></a><small><?= e($surveyStatuses[(string)$survey['campaign_status']]??'Zapisana') ?></small><?php else:?><span class="status-pill is-muted">Jeszcze nieprzypięta</span><?php endif;?></td><td><a class="text-link" href="/admin/campaigns?tab=survey&survey_id=<?= (int)$survey['id'] ?>#ankiety">Edytuj</a> · <a class="text-link" href="/admin/surveys/report?id=<?= (int)$survey['id'] ?>">Raport</a></td></tr><?php endforeach;?></tbody></table></div><?php endif;?>
</section>

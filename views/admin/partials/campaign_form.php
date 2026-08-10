<?php
$campaignFormData = is_array($campaignFormData ?? null) ? $campaignFormData : [];
$campaignFormAction = (string)($campaignFormAction ?? '/admin/campaigns');
$campaignFormSubmit = (string)($campaignFormSubmit ?? 'Zapisz kampanię');
$campaignValue = static fn(string $key, mixed $fallback = ''): mixed => $campaignFormData[$key] ?? $fallback;
$moneyValue = static fn(string $key): string => number_format(((int)($campaignFormData[$key] ?? 0)) / 100, 2, ',', '');
$selectedType = (string)($campaignFormType ?? $campaignValue('type', 'ad_click'));
$selectedStatus = (string)$campaignValue('status', 'draft');
$surveyStatusLabels = ['draft'=>'Szkic','active'=>'Aktywna','paused'=>'Wstrzymana','closed'=>'Zakończona'];
$priceField = (string)($type_definitions[$selectedType]['cost_field'] ?? 'cost_per_view_minor');
$priceName = match ($priceField) {
  'cost_per_click_minor' => 'cost_per_click',
  'cost_per_completed_survey_minor' => 'cost_per_completed_survey',
  default => 'cost_per_view',
};
$priceLabel = match ($selectedType) {
  'ad_click' => 'Cena za potwierdzone przejście',
  'ad_view' => 'Cena za ukończone obejrzenie',
  'sponsored_article' => 'Cena za potwierdzone przeczytanie',
  default => 'Cena za ukończoną ankietę',
};
?>
<form method="post" action="<?= e($campaignFormAction) ?>" enctype="multipart/form-data" class="zs-campaign-form" data-campaign-form>
  <?= csrf_field() ?>
  <input type="hidden" name="type" value="<?= e($selectedType) ?>">
  <?php if (!empty($campaignFormData['id'])): ?><input type="hidden" name="id" value="<?= (int)$campaignFormData['id'] ?>"><?php endif; ?>

  <details class="zs-human-details" open>
    <summary><span>1</span><strong>Zleceniodawca i nazwa kampanii</strong></summary>
    <div class="form-grid two">
      <label class="field"><span>Zleceniodawca</span><input name="client_name" required value="<?= e((string)$campaignValue('client_name')) ?>" placeholder="Nazwa firmy lub organizacji"></label>
      <label class="field"><span>E-mail kontaktowy</span><input name="client_email" type="email" value="<?= e((string)$campaignValue('client_email')) ?>" placeholder="kampania@example.com"></label>
      <label class="field"><span>Nazwa widoczna w serwisie</span><input name="name" required value="<?= e((string)$campaignValue('name')) ?>" placeholder="Krótka, czytelna nazwa"></label>
      <label class="field"><span>Numer zlecenia lub umowy</span><input name="order_reference" value="<?= e((string)$campaignValue('order_reference')) ?>" placeholder="Opcjonalnie"></label>
    </div>
  </details>

  <details class="zs-human-details" open>
    <summary><span>2</span><strong>Materiał i miejsce emisji</strong></summary>
    <div class="form-grid two">
      <?php if (in_array($selectedType, ['ad_click','ad_view'], true)): ?>
        <label class="field"><span>Miejsce emisji</span><select name="placement"><?php foreach ($placements as $key => $label): ?><option value="<?= e($key) ?>" <?= (string)$campaignValue('placement','campaigns') === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
        <?php if ($selectedType === 'ad_view'): ?><label class="field"><span>Wymagany czas oglądania</span><div class="zs-input-with-unit"><input type="number" min="1" max="600" name="minimum_view_seconds" value="<?= (int)$campaignValue('minimum_view_seconds',15) ?>"><b>s</b></div></label><?php endif; ?>
        <div class="zs-upload-module full">
          <span class="field-label"><?= $selectedType === 'ad_click' ? 'Grafika banera' : 'Film kampanii' ?></span>
          <input type="file" name="creative" id="campaign-creative" accept="<?= $selectedType === 'ad_click' ? 'image/jpeg,image/png,image/webp' : 'video/mp4,video/webm' ?>" hidden>
          <button type="button" class="zs-upload-dropzone zs-simple-dropzone" data-campaign-file-select>
            <span>Przeciągnij plik tutaj albo wybierz go z komputera.</span><strong>Wybierz plik</strong>
            <?php if (!empty($campaignFormData['creative_path'])): ?>
              <?php if ($selectedType === 'ad_click'): ?><img src="<?= e((string)$campaignFormData['creative_path']) ?>" alt="Podgląd banera" data-campaign-image-preview><?php else: ?><video src="<?= e((string)$campaignFormData['creative_path']) ?>" controls muted data-campaign-video-preview></video><?php endif; ?>
            <?php else: ?>
              <img alt="" data-campaign-image-preview hidden><video controls muted data-campaign-video-preview hidden></video>
            <?php endif; ?>
          </button>
          <div class="zs-upload-status" data-campaign-file-name><?= !empty($campaignFormData['creative_path']) ? 'Materiał jest zapisany. Nowy plik zastąpi obecny.' : '' ?></div>
        </div>
      <?php elseif ($selectedType === 'sponsored_article'): ?>
        <label class="field full"><span>Wyszukaj artykuł</span><input type="search" placeholder="Wpisz tytuł..." data-content-search="article"></label>
        <label class="field full"><span>Opublikowany artykuł</span><select name="linked_article_id" required data-content-list="article"><option value="">Wybierz artykuł</option><?php foreach ($published_articles as $article): ?><option value="<?= (int)$article['id'] ?>" <?= (int)$campaignValue('linked_article_id',0)===(int)$article['id']?'selected':'' ?>><?= e((string)$article['title']) ?></option><?php endforeach; ?></select><small>Nie ma tekstu na liście? <a href="/admin/editorial">Przejdź do publikacji artykułu</a>, a potem wróć tutaj.</small></label>
        <input type="hidden" name="placement" value="campaigns">
      <?php else: ?>
        <label class="field full"><span>Wyszukaj ankietę</span><input type="search" placeholder="Wpisz tytuł..." data-content-search="survey"></label>
        <label class="field full"><span>Aktywna ankieta</span><select name="linked_survey_id" required data-content-list="survey"><option value="">Wybierz ankietę</option><?php foreach ($surveys as $survey): ?><option value="<?= (int)$survey['id'] ?>" <?= (int)$campaignValue('linked_survey_id',0)===(int)$survey['id']?'selected':'' ?>><?= e((string)$survey['title']) ?> — <?= e($surveyStatusLabels[(string)$survey['status']] ?? 'Nieznany stan') ?></option><?php endforeach; ?></select><small>Najpierw przygotuj pytania i uruchom ankietę w sekcji poniżej.</small></label>
        <input type="hidden" name="placement" value="campaigns">
      <?php endif; ?>
      <?php if ($selectedType === 'ad_click'): ?><label class="field full"><span>Strona reklamodawcy</span><input name="target_url" type="url" required value="<?= e((string)$campaignValue('target_url')) ?>" placeholder="https://..."></label><?php endif; ?>
      <label class="field full"><span>Opis dla użytkownika</span><textarea name="description" rows="4" placeholder="Krótko wyjaśnij, czego dotyczy kampania."><?= e((string)$campaignValue('description')) ?></textarea></label>
    </div>
  </details>

  <details class="zs-human-details" open>
    <summary><span>3</span><strong>Budżet, stawka i czas trwania</strong></summary>
    <div class="form-grid two">
      <label class="field"><span>Budżet kampanii</span><div class="zs-input-with-unit"><input name="budget" inputmode="decimal" required value="<?= e($moneyValue('budget_minor')) ?>"><b>PLN</b></div></label>
      <label class="field"><span><?= e($priceLabel) ?></span><div class="zs-input-with-unit"><input name="<?= e($priceName) ?>" inputmode="decimal" required value="<?= e($moneyValue($priceField)) ?>"><b>PLN</b></div></label>
      <label class="field"><span>Maksymalna liczba efektów</span><input name="max_verified_events" type="number" min="0" value="<?= (int)$campaignValue('max_verified_events',0) ?>"><small>0 oznacza: do wyczerpania budżetu.</small></label>
      <label class="field"><span>Stan kampanii</span><select name="status"><?php foreach ($statuses as $key=>$label): ?><option value="<?= e($key) ?>" <?= $selectedStatus===$key?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>Start</span><input name="starts_at" type="datetime-local" value="<?= e(!empty($campaignFormData['starts_at']) ? str_replace(' ','T',substr((string)$campaignFormData['starts_at'],0,16)) : '') ?>"></label>
      <label class="field"><span>Koniec</span><input name="ends_at" type="datetime-local" value="<?= e(!empty($campaignFormData['ends_at']) ? str_replace(' ','T',substr((string)$campaignFormData['ends_at'],0,16)) : '') ?>"></label>
      <label class="field zs-checkbox-field full"><input name="budget_confirmed" type="checkbox" value="1" <?= !empty($campaignFormData['budget_confirmed'])?'checked':'' ?>><span>Potwierdzam przyjęcie zlecenia i budżetu</span><small>Bez tego kampania nie rozpocznie emisji.</small></label>
    </div>
  </details>

  <aside class="zs-human-note"><strong><?= e((string)($type_definitions[$selectedType]['label'] ?? 'Kampania')) ?></strong><p><?= e((string)($type_definitions[$selectedType]['proof'] ?? '')) ?> Użytkownik otrzymuje <?= (int)($type_definitions[$selectedType]['talent_points'] ?? 0) ?> TT z Programu Talent. Reklamodawca rozlicza wyłącznie potwierdzony efekt w PLN.</p></aside>
  <div class="zs-campaign-form-actions"><button class="btn-red" type="submit"><?= e($campaignFormSubmit) ?></button><span>Przed uruchomieniem system sprawdzi budżet, materiał, stawkę i nagrodę TT.</span></div>
</form>
<script>
(function(){
 const input=document.getElementById('campaign-creative'); const select=document.querySelector('[data-campaign-file-select]');
 if(input&&select){
   const preview=()=>{const f=input.files&&input.files[0]; if(!f)return; const url=URL.createObjectURL(f); const img=document.querySelector('[data-campaign-image-preview]'); const vid=document.querySelector('[data-campaign-video-preview]'); document.querySelector('[data-campaign-file-name]').textContent=f.name; if(f.type.startsWith('image/')&&img){img.src=url;img.hidden=false;if(vid)vid.hidden=true;} if(f.type.startsWith('video/')&&vid){vid.src=url;vid.hidden=false;if(img)img.hidden=true;}};
   select.addEventListener('click',e=>{if(e.target.closest('video'))return; input.click();});
   select.addEventListener('dragover',e=>{e.preventDefault();select.classList.add('dragover');});
   select.addEventListener('dragleave',()=>select.classList.remove('dragover'));
   select.addEventListener('drop',e=>{e.preventDefault();select.classList.remove('dragover');if(!e.dataTransfer?.files?.length)return;const transfer=new DataTransfer();transfer.items.add(e.dataTransfer.files[0]);input.files=transfer.files;preview();});
   input.addEventListener('change',preview);
 }
 document.querySelectorAll('[data-content-search]').forEach(search=>{search.addEventListener('input',()=>{const list=document.querySelector('[data-content-list="'+search.dataset.contentSearch+'"]'); if(!list)return; const q=search.value.toLocaleLowerCase(); Array.from(list.options).forEach((option,index)=>{if(index===0)return; option.hidden=!option.text.toLocaleLowerCase().includes(q);});});});
})();
</script>

<?php
$campaignFormData = is_array($campaignFormData ?? null) ? $campaignFormData : [];
$campaignFormAction = (string)($campaignFormAction ?? '/admin/campaigns');
$campaignFormSubmit = (string)($campaignFormSubmit ?? t('admin.campaigns.zapisz_kampanie'));
$campaignValue = static fn(string $key, mixed $fallback = ''): mixed => $campaignFormData[$key] ?? $fallback;
$moneyValue = static fn(string $key): string => number_format(((int)($campaignFormData[$key] ?? 0)) / 100, 2, ',', '');
$selectedType = (string)($campaignFormType ?? $campaignValue('type', 'ad_click'));
$selectedStatus = (string)$campaignValue('status', 'draft');
$surveyStatusLabels = ['draft'=>t('article.status.draft'),'active'=>t('admin.surveys.status_active'),'paused'=>t('admin.surveys.status_paused'),'closed'=>t('admin.partials.campaign_form.zakonczona')];
$priceField = (string)($type_definitions[$selectedType]['cost_field'] ?? 'cost_per_view_minor');
$priceName = match ($priceField) {
  'cost_per_click_minor' => 'cost_per_click',
  'cost_per_completed_survey_minor' => 'cost_per_completed_survey',
  default => 'cost_per_view',
};
$priceLabel = match ($selectedType) {
  'ad_click' => t('admin.partials.campaign_form.cena_za_potwierdzone_przejscie'),
  'ad_view' => t('admin.partials.campaign_form.cena_za_ukonczone_obejrzenie'),
  'sponsored_article' => t('admin.partials.campaign_form.confirmed_read_price'),
  default => t('admin.partials.campaign_form.cena_za_ukonczona_ankiete'),
};
?>
<form method="post" action="<?= e($campaignFormAction) ?>" enctype="multipart/form-data" class="zs-campaign-form" data-campaign-form>
  <?= csrf_field() ?>
  <input type="hidden" name="type" value="<?= e($selectedType) ?>">
  <?php if (!empty($campaignFormData['id'])): ?><input type="hidden" name="id" value="<?= (int)$campaignFormData['id'] ?>"><?php endif; ?>

  <details class="zs-human-details" open>
    <summary><span>1</span><strong><?= e(t('admin.partials.campaign_form.zleceniodawca_i_nazwa_kampanii')) ?></strong></summary>
    <div class="form-grid two">
      <label class="field"><span><?= e(t('admin.partials.campaign_form.zleceniodawca')) ?></span><input name="client_name" required value="<?= e((string)$campaignValue('client_name')) ?>" placeholder="<?= e(t('admin.partials.campaign_form.nazwa_firmy_lub_organizacji')) ?>"></label>
      <label class="field"><span><?= e(t('admin.partials.campaign_form.e_mail_kontaktowy')) ?></span><input name="client_email" type="email" value="<?= e((string)$campaignValue('client_email')) ?>" placeholder="kampania@example.com"></label>
      <label class="field"><span><?= e(t('admin.partials.campaign_form.nazwa_widoczna_w_serwisie')) ?></span><input name="name" required value="<?= e((string)$campaignValue('name')) ?>" placeholder="<?= e(t('admin.partials.campaign_form.krotka_czytelna_nazwa')) ?>"></label>
      <label class="field"><span><?= e(t('admin.partials.campaign_form.numer_zlecenia_lub_umowy')) ?></span><input name="order_reference" value="<?= e((string)$campaignValue('order_reference')) ?>" placeholder="<?= e(t('admin.financial_approvals.opcjonalnie')) ?>"></label>
    </div>
  </details>

  <details class="zs-human-details" open>
    <summary><span>2</span><strong><?= e(t('admin.partials.campaign_form.materia_i_miejsce_emisji')) ?></strong></summary>
    <div class="form-grid two">
      <?php if (in_array($selectedType, ['ad_click','ad_view'], true)): ?>
        <label class="field"><span><?= e(t('admin.partials.campaign_form.miejsce_emisji')) ?></span><select name="placement"><?php foreach ($placements as $key => $label): ?><option value="<?= e($key) ?>" <?= (string)$campaignValue('placement','campaigns') === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
        <?php if ($selectedType === 'ad_view'): ?><label class="field"><span><?= e(t('admin.partials.campaign_form.wymagany_czas_ogladania')) ?></span><div class="zs-input-with-unit"><input type="number" min="1" max="600" name="minimum_view_seconds" value="<?= (int)$campaignValue('minimum_view_seconds',15) ?>"><b>s</b></div></label><?php endif; ?>
        <div class="zs-upload-module full">
    <span class="field-label"><?= e(t($selectedType === 'ad_click' ? 'admin.partials.campaign_form.banner_image' : 'admin.partials.campaign_form.campaign_video')) ?></span>
          <input type="file" name="creative" id="campaign-creative" accept="<?= $selectedType === 'ad_click' ? 'image/jpeg,image/png,image/webp' : 'video/mp4,video/webm' ?>" hidden>
          <button type="button" class="zs-upload-dropzone zs-simple-dropzone" data-campaign-file-select>
            <span><?= e(t('admin.partials.campaign_form.przeciagnij_plik_tutaj_albo_wybierz_go_z_komputera')) ?></span><strong><?= e(t('admin.partials.campaign_form.wybierz_plik')) ?></strong>
            <?php if (!empty($campaignFormData['creative_path'])): ?>
              <?php if ($selectedType === 'ad_click'): ?><img src="<?= e((string)$campaignFormData['creative_path']) ?>" alt="<?= e(t('admin.partials.campaign_form.podglad_banera')) ?>" data-campaign-image-preview><?php else: ?><video src="<?= e((string)$campaignFormData['creative_path']) ?>" controls muted data-campaign-video-preview></video><?php endif; ?>
            <?php else: ?>
              <img alt="" data-campaign-image-preview hidden><video controls muted data-campaign-video-preview hidden></video>
            <?php endif; ?>
          </button>
          <div class="zs-upload-status" data-campaign-file-name><?= !empty($campaignFormData['creative_path']) ? t('admin.partials.campaign_form.materia_jest_zapisany_nowy_plik_zastapi_obecny') : '' ?></div>
        </div>
      <?php elseif ($selectedType === 'sponsored_article'): ?>
        <label class="field full"><span><?= e(t('admin.partials.campaign_form.wyszukaj_artyku')) ?></span><input type="search" placeholder="<?= e(t('admin.partials.campaign_form.wpisz_tytu')) ?>" data-content-search="article"></label>
        <label class="field full"><span><?= e(t('admin.partials.campaign_form.opublikowany_artyku')) ?></span><select name="linked_article_id" required data-content-list="article"><option value=""><?= e(t('admin.partials.campaign_form.wybierz_artyku')) ?></option><?php foreach ($published_articles as $article): ?><option value="<?= (int)$article['id'] ?>" <?= (int)$campaignValue('linked_article_id',0)===(int)$article['id']?'selected':'' ?>><?= e((string)$article['title']) ?></option><?php endforeach; ?></select><small><?= e(t('admin.partials.campaign_form.nie_ma_tekstu_na_liscie')) ?> <a href="/admin/editorial"><?= e(t('admin.partials.campaign_form.przejdz_do_publikacji_artykuu')) ?></a><?= e(t('admin.partials.campaign_form.a_potem_wroc_tutaj')) ?></small></label>
        <input type="hidden" name="placement" value="campaigns">
      <?php else: ?>
        <label class="field full"><span><?= e(t('admin.partials.campaign_form.wyszukaj_ankiete')) ?></span><input type="search" placeholder="<?= e(t('admin.partials.campaign_form.wpisz_tytu')) ?>" data-content-search="survey"></label>
  <label class="field full"><span><?= e(t('admin.partials.campaign_form.aktywna_ankieta')) ?></span><select name="linked_survey_id" required data-content-list="survey"><option value=""><?= e(t('admin.partials.campaign_form.wybierz_ankiete')) ?></option><?php foreach ($surveys as $survey): ?><option value="<?= (int)$survey['id'] ?>" <?= (int)$campaignValue('linked_survey_id',0)===(int)$survey['id']?'selected':'' ?>><?= e((string)$survey['title']) ?> — <?= e($surveyStatusLabels[(string)$survey['status']] ?? t('admin.partials.campaign_form.unknown_status')) ?></option><?php endforeach; ?></select><small><?= e(t('admin.partials.campaign_form.najpierw_przygotuj_pytania_i_uruchom_ankiete_w_sekcji_ponizej')) ?></small></label>
        <input type="hidden" name="placement" value="campaigns">
      <?php endif; ?>
      <?php if ($selectedType === 'ad_click'): ?><label class="field full"><span><?= e(t('admin.partials.campaign_form.strona_reklamodawcy')) ?></span><input name="target_url" type="url" required value="<?= e((string)$campaignValue('target_url')) ?>" placeholder="https://..."></label><?php endif; ?>
      <label class="field full"><span><?= e(t('admin.partials.campaign_form.opis_dla_uzytkownika')) ?></span><textarea name="description" rows="4" placeholder="<?= e(t('admin.partials.campaign_form.krotko_wyjasnij_czego_dotyczy_kampania')) ?>"><?= e((string)$campaignValue('description')) ?></textarea></label>
    </div>
  </details>

  <details class="zs-human-details" open>
    <summary><span>3</span><strong><?= e(t('admin.partials.campaign_form.budzet_stawka_i_czas_trwania')) ?></strong></summary>
    <div class="form-grid two">
      <label class="field"><span><?= e(t('admin.partials.campaign_form.budzet_kampanii')) ?></span><div class="zs-input-with-unit"><input name="budget" inputmode="decimal" required value="<?= e($moneyValue('budget_minor')) ?>"><b>PLN</b></div></label>
      <label class="field"><span><?= e($priceLabel) ?></span><div class="zs-input-with-unit"><input name="<?= e($priceName) ?>" inputmode="decimal" required value="<?= e($moneyValue($priceField)) ?>"><b>PLN</b></div></label>
      <label class="field"><span><?= e(t('admin.partials.campaign_form.maksymalna_liczba_efektow')) ?></span><input name="max_verified_events" type="number" min="0" value="<?= (int)$campaignValue('max_verified_events',0) ?>"><small><?= e(t('admin.partials.campaign_form.0_oznacza_do_wyczerpania_budzetu')) ?></small></label>
      <label class="field"><span><?= e(t('admin.partials.campaign_form.stan_kampanii')) ?></span><select name="status"><?php foreach ($statuses as $key=>$label): ?><option value="<?= e($key) ?>" <?= $selectedStatus===$key?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span><?= e(t('admin.partials.campaign_form.start')) ?></span><input name="starts_at" type="datetime-local" value="<?= e(!empty($campaignFormData['starts_at']) ? str_replace(' ','T',substr((string)$campaignFormData['starts_at'],0,16)) : '') ?>"></label>
      <label class="field"><span><?= e(t('admin.partials.campaign_form.koniec')) ?></span><input name="ends_at" type="datetime-local" value="<?= e(!empty($campaignFormData['ends_at']) ? str_replace(' ','T',substr((string)$campaignFormData['ends_at'],0,16)) : '') ?>"></label>
      <label class="field zs-checkbox-field full"><input name="budget_confirmed" type="checkbox" value="1" <?= !empty($campaignFormData['budget_confirmed'])?'checked':'' ?>><span><?= e(t('admin.partials.campaign_form.potwierdzam_przyjecie_zlecenia_i_budzetu')) ?></span><small><?= e(t('admin.partials.campaign_form.bez_tego_kampania_nie_rozpocznie_emisji')) ?></small></label>
    </div>
  </details>

  <aside class="zs-human-note"><strong><?= e((string)($type_definitions[$selectedType]['label'] ?? t('campaign.type.campaign'))) ?></strong><p><?= e((string)($type_definitions[$selectedType]['proof'] ?? '')) ?> <?= e(str_replace('{points}', (string)(int)($type_definitions[$selectedType]['talent_points'] ?? 0), t('admin.campaigns.user_reward_and_billing'))) ?></p></aside>
  <div class="zs-campaign-form-actions"><button class="btn-red" type="submit"><?= e($campaignFormSubmit) ?></button><span><?= e(t('admin.partials.campaign_form.przed_uruchomieniem_system_sprawdzi_budzet_materia_staw_76a86328')) ?></span></div>
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

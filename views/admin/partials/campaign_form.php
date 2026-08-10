<?php
/** @var array<string,mixed>|null $campaignFormData */
$campaignFormData = is_array($campaignFormData ?? null) ? $campaignFormData : [];
$campaignFormAction = (string)($campaignFormAction ?? '/admin/campaigns');
$campaignFormSubmit = (string)($campaignFormSubmit ?? 'Utwórz kampanię');
$campaignValue = static fn(string $key, mixed $fallback = ''): mixed => $campaignFormData[$key] ?? $fallback;
$moneyValue = static fn(string $key): string => number_format(((int)($campaignFormData[$key] ?? 0)) / 100, 2, ',', '');
$selectedType = (string)$campaignValue('type', 'ad_click');
$selectedStatus = (string)$campaignValue('status', 'draft');
?>
<form method="post" action="<?= e($campaignFormAction) ?>" class="zs-campaign-form" data-campaign-form>
  <?= csrf_field() ?>
  <?php if (!empty($campaignFormData['id'])): ?>
    <input type="hidden" name="id" value="<?= (int)$campaignFormData['id'] ?>">
  <?php endif; ?>

  <div class="zs-campaign-form-section">
    <div class="zs-campaign-form-copy">
      <span>01</span><div><h3>Zlecenie</h3><p>Kto zamawia kampanię i jak rozpoznać ją w rozliczeniu.</p></div>
    </div>
    <div class="form-grid two">
      <label class="field"><span>Zleceniodawca</span><input name="client_name" required value="<?= e((string)$campaignValue('client_name')) ?>" placeholder="Nazwa firmy lub organizacji"></label>
      <label class="field"><span>E-mail kontaktowy</span><input name="client_email" type="email" value="<?= e((string)$campaignValue('client_email')) ?>" placeholder="kampania@example.com"></label>
      <label class="field"><span>Nazwa kampanii</span><input name="name" required value="<?= e((string)$campaignValue('name')) ?>" placeholder="Krótka nazwa widoczna w serwisie"></label>
      <label class="field"><span>Numer zlecenia / umowy</span><input name="order_reference" value="<?= e((string)$campaignValue('order_reference')) ?>" placeholder="Opcjonalny numer dokumentu"></label>
    </div>
  </div>

  <div class="zs-campaign-form-section">
    <div class="zs-campaign-form-copy">
      <span>02</span><div><h3>Efekt, za który płaci klient</h3><p>Jedna kampania ma jeden główny, mierzalny efekt.</p></div>
    </div>
    <div class="form-grid two">
      <label class="field"><span>Rodzaj kampanii</span><select name="type" data-campaign-type><?php foreach ($types as $key => $label): $ready = (bool)($type_definitions[$key]['ready'] ?? false); ?><option value="<?= e($key) ?>" <?= $selectedType === $key ? 'selected' : '' ?>><?= e($label) ?><?= $ready ? '' : ' · w przygotowaniu' ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>Status</span><select name="status"><?php foreach ($statuses as $key => $label): ?><option value="<?= e($key) ?>" <?= $selectedStatus === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
      <label class="field" data-campaign-price-field="cost_per_view_minor"><span>Cena za zweryfikowane obejrzenie</span><input name="cost_per_view" inputmode="decimal" value="<?= e($moneyValue('cost_per_view_minor')) ?>"></label>
      <label class="field" data-campaign-price-field="cost_per_click_minor"><span>Cena za zweryfikowane kliknięcie</span><input name="cost_per_click" inputmode="decimal" value="<?= e($moneyValue('cost_per_click_minor')) ?>"></label>
      <label class="field" data-campaign-price-field="cost_per_completed_survey_minor"><span>Cena za ukończoną ankietę</span><input name="cost_per_completed_survey" inputmode="decimal" value="<?= e($moneyValue('cost_per_completed_survey_minor')) ?>"></label>
      <label class="field"><span>Maksymalna liczba efektów</span><input name="max_verified_events" type="number" min="0" value="<?= (int)$campaignValue('max_verified_events', 0) ?>"><small>0 oznacza limit wyłącznie budżetem.</small></label>
    </div>
    <article class="zs-campaign-proof-card" data-campaign-proof aria-live="polite"></article>
  </div>

  <div class="zs-campaign-form-section">
    <div class="zs-campaign-form-copy">
      <span>03</span><div><h3>Budżet i bezpieczeństwo</h3><p>System nie aktywuje kampanii bez potwierdzonego zlecenia ani dodatniej marży.</p></div>
    </div>
    <div class="form-grid two">
      <label class="field"><span>Budżet kampanii PLN</span><input name="budget" inputmode="decimal" required value="<?= e($moneyValue('budget_minor')) ?>"></label>
      <label class="field zs-checkbox-field"><input name="budget_confirmed" type="checkbox" value="1" <?= !empty($campaignFormData['budget_confirmed']) ? 'checked' : '' ?>><span>Potwierdzam przyjęcie zlecenia i budżetu</span><small>Bez tego kampania może zostać zapisana tylko jako szkic lub wstrzymana.</small></label>
      <label class="field"><span>Start</span><input name="starts_at" type="datetime-local" value="<?= e(!empty($campaignFormData['starts_at']) ? str_replace(' ', 'T', substr((string)$campaignFormData['starts_at'], 0, 16)) : '') ?>"></label>
      <label class="field"><span>Koniec</span><input name="ends_at" type="datetime-local" value="<?= e(!empty($campaignFormData['ends_at']) ? str_replace(' ', 'T', substr((string)$campaignFormData['ends_at'], 0, 16)) : '') ?>"></label>
    </div>
  </div>

  <div class="zs-campaign-form-section">
    <div class="zs-campaign-form-copy">
      <span>04</span><div><h3>Treść kampanii</h3><p>Prosty komunikat: co użytkownik ma zrobić i dlaczego warto.</p></div>
    </div>
    <div class="form-grid two">
      <label class="field full"><span>Link docelowy</span><input name="target_url" type="url" value="<?= e((string)$campaignValue('target_url')) ?>" placeholder="https://..."></label>
      <label class="field full"><span>Opis dla użytkownika</span><textarea name="description" rows="5" placeholder="Wyjaśnij krótko cel kampanii."><?= e((string)$campaignValue('description')) ?></textarea></label>
    </div>
  </div>

  <div class="zs-campaign-form-actions">
    <button class="btn-red" type="submit"><?= e($campaignFormSubmit) ?></button>
    <span>PLN rozlicza kampania. TT nalicza wyłącznie istniejący Program Talent.</span>
  </div>
</form>

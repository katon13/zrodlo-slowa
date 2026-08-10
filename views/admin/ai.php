<?php
$statusMap = [
    'draft' => ['label' => t('article.status.draft'), 'class' => 'pending'],
    'ai_draft' => ['label' => t('admin.editorial_edit.ai_draft'), 'class' => 'pending'],
    'editor_review' => ['label' => t('admin.editorial_edit.proofreading'), 'class' => 'pending'],
    'planned' => ['label' => t('admin.ai.status_planned'), 'class' => 'pending'],
    'queued' => ['label' => t('admin.ai.status_queued'), 'class' => 'pending'],
    'running' => ['label' => t('admin.ai.status_running'), 'class' => 'pending'],
    'completed' => ['label' => t('admin.ai.zakonczone'), 'class' => 'paid'],
    'approved' => ['label' => t('article.status.approved'), 'class' => 'paid'],
    'failed' => ['label' => t('admin.user_delete.bad'), 'class' => 'failed'],
    'error' => ['label' => t('admin.user_delete.bad'), 'class' => 'failed'],
    'rejected' => ['label' => t('article.status.rejected'), 'class' => 'failed'],
    'cancelled' => ['label' => t('admin.ai.status_cancelled'), 'class' => 'cancelled'],
    'review' => ['label' => t('article.status.review'), 'class' => 'pending'],
    'published' => ['label' => t('article.status.published'), 'class' => 'paid'],
];
$getStatus = static fn($status) => $statusMap[$status] ?? ['label' => (string)$status, 'class' => 'default'];
$activePrompts = $ai_active_prompts ?? $ai_prompts ?? [];
$keyConfigured = !empty($openai_key_configured);
$lastTestStatus = (string)($ai_settings['ai.openai.last_test_status'] ?? 'never');
$lastTestAt = (string)($ai_settings['ai.openai.last_test_at'] ?? '');
$lastTestError = (string)($ai_settings['ai.openai.last_test_error'] ?? '');
?>
<style>
  .zs-ai-top-line{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin:16px 0}.zs-ai-mini-card{border:1px solid #e5e0da;border-radius:14px;padding:12px 14px;background:#fff}.zs-ai-mini-card span{display:block;font-size:10px;letter-spacing:.12em;text-transform:uppercase;color:#777}.zs-ai-mini-card strong{display:block;font-size:22px;line-height:1.1;margin-top:4px}.zs-ai-instruction-box textarea{width:100%;min-height:120px;font-family:inherit;font-size:15px;line-height:1.55}.zs-ai-connection-line{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.zs-ai-led{display:inline-flex;align-items:center;gap:8px;font-weight:700}.zs-ai-led::before{content:"";width:12px;height:12px;border-radius:999px;background:#b91c1c;box-shadow:0 0 0 4px rgba(185,28,28,.12)}.zs-ai-led.is-ok::before{background:#15803d;box-shadow:0 0 0 4px rgba(21,128,61,.12)}.zs-ai-compact-settings .zs-settings-grid{grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.zs-ai-compact-settings label span{font-size:10px}.zs-ai-hidden-architecture{display:none}.zs-ai-table-compact th,.zs-ai-table-compact td{padding-top:8px;padding-bottom:8px}@media(max-width:1000px){.zs-ai-top-line,.zs-ai-compact-settings .zs-settings-grid{grid-template-columns:1fr 1fr}}@media(max-width:640px){.zs-ai-top-line,.zs-ai-compact-settings .zs-settings-grid{grid-template-columns:1fr}}
</style>
<section class="admin-page-head zs-operator-page-head">
  <p class="kicker"><?= e(t('admin.ai.snajper_sowa_ai')) ?></p>
  <h1><?= e(t('admin.ai.fundament_ai_redakcyjnego')) ?></h1>
  <p><?= e(t('admin.ai.ustawienia_ai_dla_tumaczen_i_audytu_ai_nie_publikuje_ni_01718e8e')) ?></p>
</section>

<?php if ($m = ($_SESSION['_flash']['success'] ?? null)): unset($_SESSION['_flash']['success']); ?><div class="notice success"><?= e($m) ?></div><?php endif; ?>
<?php if ($m = ($_SESSION['_flash']['error'] ?? null)): unset($_SESSION['_flash']['error']); ?><div class="notice error"><?= e($m) ?></div><?php endif; ?>

<section class="admin-panel-block zs-ai-admin-block zs-ai-instruction-box">
  <div class="admin-section-head">
    <div><p class="kicker"><?= e(t('admin.ai.gowna_instrukcja_dla_tumaczen')) ?></p><h2><?= e(t('admin.ai.jak_ai_ma_tumaczyc_teksty')) ?></h2></div>
    <span class="zs-badge-info"><?= e(t('admin.ai.domyslna_instrukcja_dla_caego_serwisu')) ?></span>
  </div>
  <form method="post" action="/admin/ai/translation-instruction">
    <?= csrf_field() ?>
    <textarea name="ai_translation_default_instruction" placeholder="<?= e(t('admin.ai.opisz_styl_tumaczenia_ton_redakcyjny_wiernosc_sensowi_i_9b84a800')) ?>"><?= e((string)($ai_settings['ai.translation.default_instruction'] ?? '')) ?></textarea>
    <p class="zs-settings-note"><?= e(t('admin.ai.ta_instrukcja_dziaa_jako_zasada_domyslna_jesli_konkretn_9cb0c671')) ?></p>
    <button class="zs-btn-red" type="submit"><?= e(t('admin.ai.zapisz_instrukcje_tumaczenia')) ?></button>
  </form>
</section>

<section class="zs-ai-top-line" aria-label="<?= e(t('admin.ai.podsumowanie_ai')) ?>">
  <article class="zs-ai-mini-card"><span><?= e(t('admin.ai.praca_ai')) ?></span><strong><?= (int)($ai_summary['jobs_count'] ?? 0) ?></strong><small><?= (int)($ai_summary['planned_jobs_count'] ?? 0) ?> zaplanowanych</small></article>
  <article class="zs-ai-mini-card"><span><?= e(t('article.language_versions')) ?></span><strong><?= (int)($ai_summary['translations_count'] ?? 0) ?></strong><small><?= e(str_replace('{count}', (string)(int)($ai_summary['draft_translations_count'] ?? 0), t('admin.ai.drafts_count'))) ?></small></article>
  <article class="zs-ai-mini-card"><span><?= e(t('admin.ai.slad_pracy')) ?></span><strong><?= (int)($ai_summary['events_count'] ?? 0) ?></strong><small><?= e(str_replace('{count}', (string)(int)($ai_summary['prompts_count'] ?? 0), t('admin.ai.instructions_count'))) ?></small></article>
  <article class="zs-ai-mini-card"><span><?= e(t('admin.ai.dzisiaj_miesiac')) ?></span><strong><?= (int)($ai_summary['translation_jobs_today'] ?? 0) ?> / <?= (int)($ai_summary['translation_jobs_month'] ?? 0) ?></strong><small><?= e(t('admin.ai.tumaczenia_uruchomione_przez_redakcje')) ?></small></article>
</section>

<section class="admin-panel-block zs-ai-admin-block">
  <div class="zs-ai-connection-line">
    <div>
      <p class="kicker"><?= e(t('admin.ai.dostawca_aktywny_openai')) ?></p>
      <h2><?= e(t('admin.ai.poaczenie_z_ai')) ?></h2>
    </div>
    <span class="zs-ai-led <?= ($keyConfigured && $lastTestStatus === 'success') ? 'is-ok' : '' ?>"><?= ($keyConfigured && $lastTestStatus === 'success') ? t('admin.ai.ai_podaczona') : t('admin.ai.ai_niepodaczona') ?></span>
    <small><?= $lastTestAt !== '' ? 'Ostatni test: ' . e($lastTestAt) : 'Brak ostatniego testu' ?><?= $lastTestError !== '' ? ' · ' . e($lastTestError) : '' ?></small>
    <form method="post" action="/admin/ai/test" class="zs-ai-test-form">
      <?= csrf_field() ?>
      <input type="hidden" name="model" value="<?= e($ai_settings['ai.openai.model'] ?? 'gpt-5.5') ?>">
      <button class="zs-btn-line" type="submit" <?= $keyConfigured ? '' : 'disabled' ?>><?= e(t('admin.ai.sprawdz_poaczenie')) ?></button>
    </form>
  </div>
</section>

<section class="admin-panel-block zs-ai-admin-block zs-ai-compact-settings">
  <div class="admin-section-head">
    <div><p class="kicker"><?= e(t('admin.ai.ustawienia')) ?></p><h2><?= e(t('admin.ai.jak_dziaa_ai_w_redakcji')) ?></h2></div>
    <span class="zs-badge-info"><?= e(t('admin.ai.najwazniejsze_ustawienia')) ?></span>
  </div>
  <form method="post" action="/admin/ai/settings" class="zs-ai-settings-form">
    <?= csrf_field() ?>
    <div class="zs-settings-grid">
      <label><span><?= e(t('admin.ai.ai_w_redakcji')) ?></span><select name="ai.enabled"><option value="0" <?= ($ai_settings['ai.enabled'] ?? '0') === '0' ? 'selected' : '' ?>><?= e(t('admin.ai.wyaczone')) ?></option><option value="1" <?= ($ai_settings['ai.enabled'] ?? '0') === '1' ? 'selected' : '' ?>><?= e(t('admin.ai.waczone')) ?></option></select></label>
      <label><span><?= e(t('admin.ai.dostawca_ai')) ?></span><select name="ai.default_provider"><option value="openai" selected><?= e(t('admin.ai.openai')) ?></option></select></label>
      <label><span><?= e(t('admin.ai.model_ogolny')) ?></span><input name="ai.openai.model" value="<?= e($ai_settings['ai.openai.model'] ?? 'gpt-5.5') ?>"></label>
      <label><span><?= e(t('admin.ai.model_tumaczen')) ?></span><input name="ai.translation.model" value="<?= e($ai_settings['ai.translation.model'] ?? ($ai_settings['ai.openai.model'] ?? 'gpt-5.5')) ?>"></label>
      <label><span><?= e(t('editorial.editing.translations')) ?></span><select name="ai.translation.enabled"><option value="0" <?= ($ai_settings['ai.translation.enabled'] ?? '0') === '0' ? 'selected' : '' ?>><?= e(t('admin.ai.wyaczone')) ?></option><option value="1" <?= ($ai_settings['ai.translation.enabled'] ?? '0') === '1' ? 'selected' : '' ?>><?= e(t('admin.ai.waczone')) ?></option></select></label>
      <label><span><?= e(t('admin.ai.wywoania_ai')) ?></span><select name="ai.jobs.execute_api_enabled"><option value="0" <?= ($ai_settings['ai.jobs.execute_api_enabled'] ?? '0') === '0' ? 'selected' : '' ?>><?= e(t('admin.ai.wyaczone')) ?></option><option value="1" <?= ($ai_settings['ai.jobs.execute_api_enabled'] ?? '0') === '1' ? 'selected' : '' ?>><?= e(t('admin.ai.waczone')) ?></option></select></label>
      <label><span><?= e(t('admin.ai.dzienny_limit')) ?></span><input name="ai.translation.daily_jobs_limit" type="number" min="1" max="1000" value="<?= e($ai_settings['ai.translation.daily_jobs_limit'] ?? '20') ?>"></label>
      <label><span><?= e(t('admin.ai.limit_tekstu')) ?></span><input name="ai.translation.max_chars_per_job" type="number" min="1" max="200000" value="<?= e($ai_settings['ai.translation.max_chars_per_job'] ?? '60000') ?>"></label>
      <label><span><?= e(t('admin.ai.model_premium')) ?></span><input name="ai.translation.premium_model" value="<?= e($ai_settings['ai.translation.premium_model'] ?? ($ai_settings['ai.translation.model'] ?? 'gpt-5.5')) ?>"></label>
      <label><span><?= e(t('admin.ai.budzet_miesieczny_grosze')) ?></span><input name="ai.translation.monthly_budget_minor" type="number" min="1" max="1000000" value="<?= e($ai_settings['ai.translation.monthly_budget_minor'] ?? '5000') ?>"></label>
      <label><span><?= e(t('admin.ai.koszt_1000_znakow_grosze')) ?></span><input name="ai.translation.estimated_cost_per_1k_chars_minor" type="number" min="1" max="10000" value="<?= e($ai_settings['ai.translation.estimated_cost_per_1k_chars_minor'] ?? '5') ?>"></label>
      <label><span><?= e(t('admin.ai.jezyk_zrodowy_domyslny')) ?></span><input name="ai.translation.default_source_language" value="<?= e($ai_settings['ai.translation.default_source_language'] ?? 'pl') ?>"></label>
      <label><span><?= e(t('admin.ai.jezyk_testowy')) ?></span><input name="ai.translation.default_target_language" value="<?= e($ai_settings['ai.translation.default_target_language'] ?? 'en') ?>"></label>
      <input type="hidden" name="ai.storage.source_of_truth" value="database">
      <input type="hidden" name="ai.storage.raw_json_policy" value="<?= e($ai_settings['ai.storage.raw_json_policy'] ?? 'audit_only') ?>">
      <input type="hidden" name="ai.translation.require_editor_review" value="<?= e($ai_settings['ai.translation.require_editor_review'] ?? '1') ?>">
      <input type="hidden" name="ai.jobs.manual_planning_enabled" value="<?= e($ai_settings['ai.jobs.manual_planning_enabled'] ?? '1') ?>">
      <input type="hidden" name="ai.jobs.require_admin" value="<?= e($ai_settings['ai.jobs.require_admin'] ?? '1') ?>">
      <input type="hidden" name="ai.translation.default_instruction" value="<?= e((string)($ai_settings['ai.translation.default_instruction'] ?? '')) ?>">
    </div>
    <div class="zs-operator-savebar">
      <div><strong><?= e(t('admin.ai.potwierdz_ustawienia_ai')) ?></strong><span><?= e(t('admin.ai.zmiana_dotyczy_wyacznie_pracy_redakcji_i_zostanie_zapis_6338e656')) ?></span></div>
      <label><span><?= e(t('admin.ai.haso_administratora')) ?></span><input type="password" name="critical_password" required autocomplete="current-password" placeholder="<?= e(t('admin.ai.haso_chroniace_zmiane')) ?>"></label>
      <button class="zs-btn-red" type="submit"><?= e(t('admin.ai.zapisz_ustawienia_ai')) ?></button>
    </div>
  </form>
</section>

<section class="admin-panel-block zs-ai-admin-block">
  <div class="admin-section-head"><div><p class="kicker"><?= e(t('admin.ai.ostatnia_praca')) ?></p><h2><?= e(t('admin.ai.ostatnie_zadania_ai')) ?></h2></div><span class="zs-badge-info"><?= e(t('admin.ai.slad_operacyjny')) ?></span></div>
  <?php if (empty($ai_jobs)): ?>
    <div class="zs-empty-state"><h3><?= e(t('admin.ai.brak_zadan_ai')) ?></h3><p><?= e(t('admin.ai.po_uzyciu_ai_pojawia_sie_tutaj_ostatnie_zadania')) ?></p></div>
  <?php else: ?>
    <div class="zs-admin-table-wrapper"><table class="zs-admin-table zs-ai-table zs-ai-table-compact"><thead><tr><th>ID</th><th><?= e(t('admin.ai.zadanie')) ?></th><th><?= e(t('admin.ai.artyku')) ?></th><th><?= e(t('wallet.history.table.status')) ?></th><th><?= e(t('admin.ai.silnik_ai')) ?></th><th><?= e(t('admin.ai.zuzycie_wejscie_wyjscie')) ?></th><th><?= e(t('admin.ai.utworzono')) ?></th></tr></thead><tbody>
      <?php foreach ($ai_jobs as $job): $st = $getStatus($job['status']); ?>
      <tr><td class="zs-id-cell">#<?= (int)$job['id'] ?></td><td><strong><?= e($job['type']) ?></strong><small><?= e($job['public_id']) ?></small></td><td><?= e($job['article_title'] ?? '—') ?></td><td><span class="zs-status-badge is-<?= e($st['class']) ?> <?= e($st['class']) ?>"><?= e($st['label']) ?></span></td><td><?= e($job['provider']) ?><small><?= e($job['model'] ?? '') ?></small></td><td><?= (int)($job['tokens_input'] ?? 0) ?> / <?= (int)($job['tokens_output'] ?? 0) ?></td><td><?= date('d.m.Y H:i', strtotime($job['created_at'])) ?></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</section>

<section class="admin-panel-block zs-ai-admin-block">
  <div class="admin-section-head"><div><p class="kicker"><?= e(t('article.language_versions')) ?></p><h2><?= e(t('admin.ai.ostatnie_tumaczenia_zapisane_w_bazie')) ?></h2></div><span class="zs-badge-info"><?= e(t('admin.ai.teksty_do_redakcji')) ?></span></div>
  <?php $translation_legend_mode = 'workflow'; require __DIR__ . '/../partials/translation_status_legend.php'; unset($translation_legend_mode); ?>
  <?php if (empty($article_translations)): ?>
    <div class="zs-empty-state"><h3><?= e(t('admin.ai.brak_zapisanych_wersji_jezykowych')) ?></h3><p><?= e(t('admin.ai.to_jest_prawidowe_dopoki_redakcja_nie_zapisze_tumaczeni_658e51be')) ?></p></div>
  <?php else: ?>
    <div class="zs-admin-table-wrapper"><table class="zs-admin-table zs-ai-table"><thead><tr><th>ID</th><th><?= e(t('admin.ai.artyku')) ?></th><th><?= e(t('admin.ai.jezyk')) ?></th><th><?= e(t('wallet.history.table.status')) ?></th><th><?= e(t('admin.ai.silnik_ai')) ?></th><th><?= e(t('admin.ai.utworzono')) ?></th></tr></thead><tbody>
      <?php foreach ($article_translations as $tr): $st = $getStatus($tr['status']); ?>
      <tr><td class="zs-id-cell">#<?= (int)$tr['id'] ?></td><td><strong><?= e($tr['title']) ?></strong><small><?= e($tr['article_title'] ?? '') ?></small></td><td><?= e($tr['source_language']) ?> → <?= e($tr['target_language']) ?></td><td><span class="zs-status-badge is-<?= e($st['class']) ?> <?= e($st['class']) ?>"><?= e($st['label']) ?></span></td><td><?= e($tr['provider'] ?: '—') ?></td><td><?= date('d.m.Y H:i', strtotime($tr['created_at'])) ?></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</section>

<section class="admin-panel-block zs-ai-admin-block">
  <div class="admin-section-head"><div><p class="kicker"><?= e(t('admin.ai.szablony_pracy')) ?></p><h2><?= e(t('admin.ai.wewnetrzne_instrukcje_ai')) ?></h2></div><span class="zs-badge-info"><?= e(t('admin.ai.bez_publikacji')) ?></span></div>
  <div class="zs-admin-table-wrapper"><table class="zs-admin-table zs-ai-table"><thead><tr><th><?= e(t('admin.ai.identyfikator')) ?></th><th><?= e(t('admin.ai.zadanie')) ?></th><th><?= e(t('admin.ai.wersja')) ?></th><th><?= e(t('admin.ai.silnik_ai')) ?></th><th><?= e(t('safety_fund.status.active')) ?></th></tr></thead><tbody>
    <?php foreach ($ai_prompts as $prompt): ?>
      <tr><td><code><?= e($prompt['code']) ?></code></td><td><strong><?= e($prompt['name']) ?></strong><small><?= e($prompt['task_type']) ?></small></td><td><?= e($prompt['version']) ?></td><td><?= e($prompt['provider']) ?></td><td><?= e(t(!empty($prompt['is_active']) ? 'common.yes' : 'common.no')) ?></td></tr>
    <?php endforeach; ?>
  </tbody></table></div>
</section>

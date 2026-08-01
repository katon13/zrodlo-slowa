<?php
$statusMap = [
    'draft' => ['label' => 'Szkic', 'class' => 'pending'],
    'ai_draft' => ['label' => 'Szkic AI', 'class' => 'pending'],
    'editor_review' => ['label' => 'Do korekty', 'class' => 'pending'],
    'planned' => ['label' => 'Zaplanowane', 'class' => 'pending'],
    'queued' => ['label' => 'W kolejce', 'class' => 'pending'],
    'running' => ['label' => 'Pracuje', 'class' => 'pending'],
    'completed' => ['label' => 'Zakończone', 'class' => 'paid'],
    'approved' => ['label' => 'Zatwierdzone', 'class' => 'paid'],
    'failed' => ['label' => 'Błąd', 'class' => 'failed'],
    'error' => ['label' => 'Błąd', 'class' => 'failed'],
    'rejected' => ['label' => 'Odrzucone', 'class' => 'failed'],
    'cancelled' => ['label' => 'Anulowane', 'class' => 'cancelled'],
    'review' => ['label' => 'Do redakcji', 'class' => 'pending'],
    'published' => ['label' => 'Opublikowane', 'class' => 'paid'],
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
  <p class="kicker">SNAJPER SŁOWA / AI</p>
  <h1>Fundament AI redakcyjnego</h1>
  <p>Ustawienia AI dla tłumaczeń i audytu. AI nie publikuje, nie zmienia ceny, premium ani statusu tekstu.</p>
</section>

<?php if ($m = ($_SESSION['_flash']['success'] ?? null)): unset($_SESSION['_flash']['success']); ?><div class="notice success"><?= e($m) ?></div><?php endif; ?>
<?php if ($m = ($_SESSION['_flash']['error'] ?? null)): unset($_SESSION['_flash']['error']); ?><div class="notice error"><?= e($m) ?></div><?php endif; ?>

<section class="admin-panel-block zs-ai-admin-block zs-ai-instruction-box">
  <div class="admin-section-head">
    <div><p class="kicker">Główna instrukcja dla tłumaczeń</p><h2>Jak AI ma tłumaczyć teksty</h2></div>
    <span class="zs-badge-info">domyślna instrukcja dla całego serwisu</span>
  </div>
  <form method="post" action="/admin/ai/translation-instruction">
    <?= csrf_field() ?>
    <textarea name="ai_translation_default_instruction" placeholder="Opisz styl tłumaczenia, ton redakcyjny, wierność sensowi i zasady bezpieczeństwa językowego..."><?= e((string)($ai_settings['ai.translation.default_instruction'] ?? '')) ?></textarea>
    <p class="zs-settings-note">Ta instrukcja działa jako zasada domyślna. Jeśli konkretny artykuł ma własne wytyczne, AI użyje ich zamiast tej instrukcji.</p>
    <button class="zs-btn-red" type="submit">Zapisz instrukcję tłumaczenia</button>
  </form>
</section>

<section class="zs-ai-top-line" aria-label="Podsumowanie AI">
  <article class="zs-ai-mini-card"><span>Praca AI</span><strong><?= (int)($ai_summary['jobs_count'] ?? 0) ?></strong><small><?= (int)($ai_summary['planned_jobs_count'] ?? 0) ?> zaplanowanych</small></article>
  <article class="zs-ai-mini-card"><span>Wersje językowe</span><strong><?= (int)($ai_summary['translations_count'] ?? 0) ?></strong><small><?= (int)($ai_summary['draft_translations_count'] ?? 0) ?> szkiców</small></article>
  <article class="zs-ai-mini-card"><span>Ślad pracy</span><strong><?= (int)($ai_summary['events_count'] ?? 0) ?></strong><small><?= (int)($ai_summary['prompts_count'] ?? 0) ?> instrukcji roboczych</small></article>
  <article class="zs-ai-mini-card"><span>Dzisiaj / miesiąc</span><strong><?= (int)($ai_summary['translation_jobs_today'] ?? 0) ?> / <?= (int)($ai_summary['translation_jobs_month'] ?? 0) ?></strong><small>tłumaczenia uruchomione przez redakcję</small></article>
</section>

<section class="admin-panel-block zs-ai-admin-block">
  <div class="zs-ai-connection-line">
    <div>
      <p class="kicker">Dostawca aktywny: OpenAI</p>
      <h2>Połączenie z AI</h2>
    </div>
    <span class="zs-ai-led <?= ($keyConfigured && $lastTestStatus === 'success') ? 'is-ok' : '' ?>"><?= ($keyConfigured && $lastTestStatus === 'success') ? 'AI podłączona' : 'AI niepodłączona' ?></span>
    <small><?= $lastTestAt !== '' ? 'Ostatni test: ' . e($lastTestAt) : 'Brak ostatniego testu' ?><?= $lastTestError !== '' ? ' · ' . e($lastTestError) : '' ?></small>
    <form method="post" action="/admin/ai/test" class="zs-ai-test-form">
      <?= csrf_field() ?>
      <input type="hidden" name="model" value="<?= e($ai_settings['ai.openai.model'] ?? 'gpt-5.5') ?>">
      <button class="zs-btn-line" type="submit" <?= $keyConfigured ? '' : 'disabled' ?>>Sprawdź połączenie</button>
    </form>
  </div>
</section>

<section class="admin-panel-block zs-ai-admin-block zs-ai-compact-settings">
  <div class="admin-section-head">
    <div><p class="kicker">Ustawienia</p><h2>Jak działa AI w redakcji</h2></div>
    <span class="zs-badge-info">najważniejsze ustawienia</span>
  </div>
  <form method="post" action="/admin/ai/settings" class="zs-ai-settings-form">
    <?= csrf_field() ?>
    <div class="zs-settings-grid">
      <label><span>AI w redakcji</span><select name="ai.enabled"><option value="0" <?= ($ai_settings['ai.enabled'] ?? '0') === '0' ? 'selected' : '' ?>>wyłączone</option><option value="1" <?= ($ai_settings['ai.enabled'] ?? '0') === '1' ? 'selected' : '' ?>>włączone</option></select></label>
      <label><span>Dostawca AI</span><select name="ai.default_provider"><option value="openai" selected>OpenAI</option></select></label>
      <label><span>Model ogólny</span><input name="ai.openai.model" value="<?= e($ai_settings['ai.openai.model'] ?? 'gpt-5.5') ?>"></label>
      <label><span>Model tłumaczeń</span><input name="ai.translation.model" value="<?= e($ai_settings['ai.translation.model'] ?? ($ai_settings['ai.openai.model'] ?? 'gpt-5.5')) ?>"></label>
      <label><span>Tłumaczenia</span><select name="ai.translation.enabled"><option value="0" <?= ($ai_settings['ai.translation.enabled'] ?? '0') === '0' ? 'selected' : '' ?>>wyłączone</option><option value="1" <?= ($ai_settings['ai.translation.enabled'] ?? '0') === '1' ? 'selected' : '' ?>>włączone</option></select></label>
      <label><span>Wywołania AI</span><select name="ai.jobs.execute_api_enabled"><option value="0" <?= ($ai_settings['ai.jobs.execute_api_enabled'] ?? '0') === '0' ? 'selected' : '' ?>>wyłączone</option><option value="1" <?= ($ai_settings['ai.jobs.execute_api_enabled'] ?? '0') === '1' ? 'selected' : '' ?>>włączone</option></select></label>
      <label><span>Dzienny limit</span><input name="ai.translation.daily_jobs_limit" type="number" min="1" max="1000" value="<?= e($ai_settings['ai.translation.daily_jobs_limit'] ?? '20') ?>"></label>
      <label><span>Limit tekstu</span><input name="ai.translation.max_chars_per_job" type="number" min="1" max="200000" value="<?= e($ai_settings['ai.translation.max_chars_per_job'] ?? '60000') ?>"></label>
      <label><span>Model premium</span><input name="ai.translation.premium_model" value="<?= e($ai_settings['ai.translation.premium_model'] ?? ($ai_settings['ai.translation.model'] ?? 'gpt-5.5')) ?>"></label>
      <label><span>Budżet miesięczny (grosze)</span><input name="ai.translation.monthly_budget_minor" type="number" min="1" max="1000000" value="<?= e($ai_settings['ai.translation.monthly_budget_minor'] ?? '5000') ?>"></label>
      <label><span>Koszt / 1000 znaków (grosze)</span><input name="ai.translation.estimated_cost_per_1k_chars_minor" type="number" min="1" max="10000" value="<?= e($ai_settings['ai.translation.estimated_cost_per_1k_chars_minor'] ?? '5') ?>"></label>
      <label><span>Język źródłowy domyślny</span><input name="ai.translation.default_source_language" value="<?= e($ai_settings['ai.translation.default_source_language'] ?? 'pl') ?>"></label>
      <label><span>Język testowy</span><input name="ai.translation.default_target_language" value="<?= e($ai_settings['ai.translation.default_target_language'] ?? 'en') ?>"></label>
      <input type="hidden" name="ai.storage.source_of_truth" value="database">
      <input type="hidden" name="ai.storage.raw_json_policy" value="<?= e($ai_settings['ai.storage.raw_json_policy'] ?? 'audit_only') ?>">
      <input type="hidden" name="ai.translation.require_editor_review" value="<?= e($ai_settings['ai.translation.require_editor_review'] ?? '1') ?>">
      <input type="hidden" name="ai.jobs.manual_planning_enabled" value="<?= e($ai_settings['ai.jobs.manual_planning_enabled'] ?? '1') ?>">
      <input type="hidden" name="ai.jobs.require_admin" value="<?= e($ai_settings['ai.jobs.require_admin'] ?? '1') ?>">
      <input type="hidden" name="ai.translation.default_instruction" value="<?= e((string)($ai_settings['ai.translation.default_instruction'] ?? '')) ?>">
    </div>
    <div class="zs-operator-savebar">
      <div><strong>Potwierdź ustawienia AI</strong><span>Zmiana dotyczy wyłącznie pracy redakcji i zostanie zapisana w audycie.</span></div>
      <label><span>Hasło administratora</span><input type="password" name="critical_password" required autocomplete="current-password" placeholder="Hasło chroniące zmianę"></label>
      <button class="zs-btn-red" type="submit">Zapisz ustawienia AI</button>
    </div>
  </form>
</section>

<section class="admin-panel-block zs-ai-admin-block">
  <div class="admin-section-head"><div><p class="kicker">Ostatnia praca</p><h2>Ostatnie zadania AI</h2></div><span class="zs-badge-info">ślad operacyjny</span></div>
  <?php if (empty($ai_jobs)): ?>
    <div class="zs-empty-state"><h3>Brak zadań AI.</h3><p>Po użyciu AI pojawią się tutaj ostatnie zadania.</p></div>
  <?php else: ?>
    <div class="zs-admin-table-wrapper"><table class="zs-admin-table zs-ai-table zs-ai-table-compact"><thead><tr><th>ID</th><th>Zadanie</th><th>Artykuł</th><th>Status</th><th>Silnik AI</th><th>Zużycie wejście / wyjście</th><th>Utworzono</th></tr></thead><tbody>
      <?php foreach ($ai_jobs as $job): $st = $getStatus($job['status']); ?>
      <tr><td class="zs-id-cell">#<?= (int)$job['id'] ?></td><td><strong><?= e($job['type']) ?></strong><small><?= e($job['public_id']) ?></small></td><td><?= e($job['article_title'] ?? '—') ?></td><td><span class="zs-status-badge is-<?= e($st['class']) ?> <?= e($st['class']) ?>"><?= e($st['label']) ?></span></td><td><?= e($job['provider']) ?><small><?= e($job['model'] ?? '') ?></small></td><td><?= (int)($job['tokens_input'] ?? 0) ?> / <?= (int)($job['tokens_output'] ?? 0) ?></td><td><?= date('d.m.Y H:i', strtotime($job['created_at'])) ?></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</section>

<section class="admin-panel-block zs-ai-admin-block">
  <div class="admin-section-head"><div><p class="kicker">Wersje językowe</p><h2>Ostatnie tłumaczenia zapisane w bazie</h2></div><span class="zs-badge-info">teksty do redakcji</span></div>
  <?php $translation_legend_mode = 'workflow'; require __DIR__ . '/../partials/translation_status_legend.php'; unset($translation_legend_mode); ?>
  <?php if (empty($article_translations)): ?>
    <div class="zs-empty-state"><h3>Brak zapisanych wersji językowych.</h3><p>To jest prawidłowe, dopóki redakcja nie zapisze tłumaczenia albo Moderator nie uruchomi tłumaczenia AI.</p></div>
  <?php else: ?>
    <div class="zs-admin-table-wrapper"><table class="zs-admin-table zs-ai-table"><thead><tr><th>ID</th><th>Artykuł</th><th>Język</th><th>Status</th><th>Silnik AI</th><th>Utworzono</th></tr></thead><tbody>
      <?php foreach ($article_translations as $tr): $st = $getStatus($tr['status']); ?>
      <tr><td class="zs-id-cell">#<?= (int)$tr['id'] ?></td><td><strong><?= e($tr['title']) ?></strong><small><?= e($tr['article_title'] ?? '') ?></small></td><td><?= e($tr['source_language']) ?> → <?= e($tr['target_language']) ?></td><td><span class="zs-status-badge is-<?= e($st['class']) ?> <?= e($st['class']) ?>"><?= e($st['label']) ?></span></td><td><?= e($tr['provider'] ?: '—') ?></td><td><?= date('d.m.Y H:i', strtotime($tr['created_at'])) ?></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</section>

<section class="admin-panel-block zs-ai-admin-block">
  <div class="admin-section-head"><div><p class="kicker">Szablony pracy</p><h2>Wewnętrzne instrukcje AI</h2></div><span class="zs-badge-info">bez publikacji</span></div>
  <div class="zs-admin-table-wrapper"><table class="zs-admin-table zs-ai-table"><thead><tr><th>Identyfikator</th><th>Zadanie</th><th>Wersja</th><th>Silnik AI</th><th>Aktywna</th></tr></thead><tbody>
    <?php foreach ($ai_prompts as $prompt): ?>
      <tr><td><code><?= e($prompt['code']) ?></code></td><td><strong><?= e($prompt['name']) ?></strong><small><?= e($prompt['task_type']) ?></small></td><td><?= e($prompt['version']) ?></td><td><?= e($prompt['provider']) ?></td><td><?= !empty($prompt['is_active']) ? 'tak' : 'nie' ?></td></tr>
    <?php endforeach; ?>
  </tbody></table></div>
</section>

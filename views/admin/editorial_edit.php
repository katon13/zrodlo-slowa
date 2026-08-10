<?php
$configuredLanguages = is_array($languages['public_enabled'] ?? null)
    ? $languages['public_enabled']
    : ['pl', 'en', 'de', 'fr', 'it', 'es'];
$langs = array_values(array_unique(array_filter(
    array_map(static fn($language): string => strtolower(trim((string)$language)), $configuredLanguages),
    static fn(string $language): bool => $language !== ''
)));
$currentSourceLang = strtolower((string)($article['source_language'] ?? 'pl'));
if (!in_array($currentSourceLang, $langs, true)) {
    $currentSourceLang = $langs[0] ?? 'pl';
}
$selectedLanguage = strtolower(trim((string)($_GET['translation_lang'] ?? $currentSourceLang)));
if (!in_array($selectedLanguage, $langs, true)) {
    $selectedLanguage = $currentSourceLang;
}
$translationsMap = [];
foreach (($translations ?? []) as $t) {
    $language = strtolower((string)($t['language'] ?? ''));
    if ($language !== '') {
        $translationsMap[$language] = $t;
    }
}
$canReviewTranslations = !empty($can_review_translations);
$mainMedia = $media[0] ?? null;
$translationStatusLabels = [
    'draft' => t('article.status.draft'),
    'ai_draft' => t('admin.editorial_edit.ai_draft'),
    'editor_review' => t('admin.editorial_edit.proofreading'),
    'approved' => t('article.status.approved'),
    'published' => t('article.status.published'),
    'rejected' => t('article.status.rejected'),
    'error' => t('admin.editorial_edit.bad'),
];
$translationInstructions = '';
foreach ($langs as $l) {
    if (!empty($translationsMap[$l]['translation_instructions'])) {
        $translationInstructions = (string)$translationsMap[$l]['translation_instructions'];
        break;
    }
}
?>

<section class="admin-page-head">
  <p class="kicker"><?= e(t('admin.dashboard.wydawca')) ?></p>
  <h1><?= e(t('admin.editorial_edit.edycja_tekstu_i_wersji_jezykowych')) ?></h1>
  <p><?= e($article['title']) ?></p>
</section>

<div id="editorial-notice" class="u-mt-4">
  <?php if (!empty($flash_success)): ?>
    <div class="notice success"><?= e($flash_success) ?></div>
  <?php endif; ?>
  <?php if (!empty($flash_error)): ?>
    <div class="notice error"><?= e($flash_error) ?></div>
  <?php endif; ?>
</div>

<?php if (!empty($article['response_to_article_id'])): ?>
  <?php $depositStatusLabels = ['not_required' => t('admin.editorial_edit.deposit_not_required'), 'held' => t('admin.editorial_edit.deposit_held'), 'forfeited' => t('admin.editorial_edit.deposit_forfeited'), 'refunded' => t('admin.editorial_edit.deposit_refunded')]; ?>
  <section class="admin-notice-info editorial-response-note">
    <strong><?= e(str_replace('{id}', (string)(int)$article['response_to_article_id'], t('admin.editorial_edit.response_heading'))) ?></strong>
    <p><?= e(t('admin.editorial_edit.ten_tekst_pozostaje_bezpatny_talent_moze_przyznac_wyacz_8ff82848')) ?></p>
    <p><strong><?= e(t('admin.editorial_edit.talent_reward')) ?>:</strong> <?= e($article['response_reward_qualified'] === null ? t('admin.editorial_edit.reward_after_first_publication') : (!empty($article['response_reward_qualified']) ? str_replace('{points}', (string)(int)$article['response_reward_points'], t('admin.editorial_edit.reward_points_saved')) : t('admin.editorial_edit.reward_rule_inactive'))) ?></p>
    <p><strong><?= e(t('admin.editorial_edit.deposit')) ?>:</strong> <?= e($article['response_deposit_status'] === null ? t('admin.editorial_edit.deposit_not_collected') : ($depositStatusLabels[(string)$article['response_deposit_status']] ?? t('admin.common.status_unknown'))) ?><?php if ($article['response_deposit_points'] !== null): ?> · <?= (int)$article['response_deposit_points'] ?> TT<?php endif; ?></p>
  </section>
<?php endif; ?>

<?php if (!empty($article['proofread_at'])): ?>
  <section class="admin-notice-info editorial-proofread-note">
    <strong><?= e(t('admin.dashboard.korekta')) ?></strong> — <?= e(str_replace('{date}', date('d.m.Y H:i', strtotime((string)$article['proofread_at'])), t('admin.editorial_edit.last_proofreading'))) ?>
  </section>
<?php endif; ?>

<style>
.editorial-proofread-note { margin: 1rem 0; padding: .85rem 1rem; border: 1px solid #b91c1c; color: #7f1d1d; background: #fffafa; }

/* AJAX Messages */
.zs-local-msg { font-size: 11px; margin-top: 4px; padding: 2px 6px; border-radius: 4px; transition: opacity 0.5s; display: block; width: 100%; clear: both; }
.zs-local-msg.success { background: #f0fff4; color: #2f855a; border: 1px solid #c6f6d5; }
.zs-local-msg.error { background: #fff5f5; color: #c53030; border: 1px solid #feb2b2; }
</style>

<form method="post" action="/admin/editorial/update" enctype="multipart/form-data" class="editorial-form editorial-language-editor ajax-form">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int)$article['id'] ?>">

  <section id="content" class="editorial-section">
    <div class="section-head">
      <h2><?= e(t('admin.editorial_edit.pena_edycja_tekstu')) ?></h2>
      <p><?= e(t('admin.editorial_edit.jedno_miejsce_edycji_wybierz_jezyk_popraw_tytu_lead_i_t_079676d1')) ?></p>
    </div>

    <?php require __DIR__ . '/../partials/translation_status_legend.php'; ?>

    <div class="editorial-language-tabs" role="tablist" aria-label="<?= e(t('admin.editorial_edit.wersje_jezykowe_tekstu')) ?>">
      <?php foreach ($langs as $index => $l): ?>
        <?php
          $isSourceLanguage = $l === $currentSourceLang;
          $translation = !$isSourceLanguage && isset($translationsMap[$l]) ? $translationsMap[$l] : null;
          $translationStatus = is_array($translation) ? (string)($translation['status'] ?? 'draft') : '';
          $hasCompleteTranslation = is_array($translation)
              && trim((string)($translation['title'] ?? '')) !== ''
              && trim((string)($translation['body'] ?? '')) !== '';
          $tabStateClass = $isSourceLanguage
              ? ' is-source'
              : ($hasCompleteTranslation
                  ? ' has-version'
                  : (is_array($translation) ? ' is-incomplete' : ' is-missing'));
          if (in_array($translationStatus, ['error', 'rejected'], true)) {
              $tabStateClass = ' is-error';
          }
          $tabStatusLabel = $isSourceLanguage
              ? t('ui.partials.translation_status_legend.orygina')
              : ($hasCompleteTranslation
                  ? ($translationStatusLabels[$translationStatus] ?? t('admin.editorial_edit.tumaczenie'))
                  : (is_array($translation) ? t('admin.editorial_edit.incomplete') : t('admin.common.missing')));
        ?>
        <button type="button" class="editorial-language-tab<?= $l === $selectedLanguage ? ' is-active' : '' ?><?= e($tabStateClass) ?>" data-language-tab="<?= e($l) ?>" title="<?= e(strtoupper($l) . ': ' . $tabStatusLabel) ?>">
          <strong><?= e(strtoupper($l)) ?></strong>
          <span><?= e($tabStatusLabel) ?></span>
        </button>
      <?php endforeach; ?>
    </div>

    <?php foreach ($langs as $index => $l): ?>
      <?php
        $isSourceLanguage = $l === $currentSourceLang;
        if ($isSourceLanguage) {
            $version = [];
            $versionTitle = (string)($article['title'] ?? '');
            $versionLead = (string)($article['lead'] ?? '');
            $versionBody = (string)($article['body'] ?? '');
        } else {
            $version = $translationsMap[$l] ?? [];
            $versionTitle = (string)($version['title'] ?? '');
            $versionLead = (string)($version['lead'] ?? '');
            $versionBody = (string)($version['body'] ?? '');
        }
        $versionStatus = $isSourceLanguage ? 'source' : (string)($version['status'] ?? '');
        $hasCompleteVersion = !$isSourceLanguage
            && trim($versionTitle) !== ''
            && trim($versionBody) !== '';
        $workflowClass = in_array($versionStatus, ['published', 'approved'], true)
            ? 'paid'
            : (in_array($versionStatus, ['error', 'rejected'], true) ? 'failed' : 'pending');
        $canApproveVersion = $canReviewTranslations
            && $hasCompleteVersion
            && in_array($versionStatus, ['draft', 'ai_draft', 'editor_review', 'approved'], true);
      ?>
      <div id="translation-<?= e($l) ?>" class="editorial-language-panel<?= $l === $selectedLanguage ? ' is-active' : '' ?>" data-language-panel="<?= e($l) ?>" data-translation-id="<?= (int)($version['id'] ?? 0) ?>" data-translation-status="<?= e($versionStatus) ?>">
        <div class="language-panel-head">
          <div>
            <h3><?= e(strtoupper($l)) ?></h3>
            <?php if ($isSourceLanguage): ?>
              <span class="zs-status-badge zs-language-badge is-source"><?= e(t('admin.editorial_edit.orygina')) ?></span>
            <?php elseif ($hasCompleteVersion): ?>
              <span class="zs-status-badge <?= e($workflowClass) ?> translation-workflow-status"><?= e($translationStatusLabels[$versionStatus] ?? $versionStatus) ?></span>
            <?php else: ?>
              <span class="zs-status-badge"><?= e(t('admin.editorial_edit.brak_penego_tumaczenia')) ?></span>
            <?php endif; ?>
          </div>
          <div class="translation-review-actions">
            <?php if (!$isSourceLanguage && isset($translationsMap[$l])): ?>
              <a href="/article?id=<?= (int)$article['id'] ?>&lang=<?= e($l) ?>&preview_lang=<?= e($l) ?>" target="_blank" rel="noopener" class="text-link"><?= e(str_replace('{language}', strtoupper($l), t('admin.editorial_edit.preview_language'))) ?></a>
            <?php endif; ?>
            <span class="translation-review-decision">
              <?php if ($canApproveVersion): ?>
                <button type="submit" class="zs-btn-red zs-btn-compact" form="translation-approve-<?= (int)$version['id'] ?>">
                  <?= e($versionStatus === 'approved' ? t('admin.editorial_edit.publish') : t('admin.editorial_edit.zatwierdz_i_opublikuj')) ?>
                </button>
                <button type="submit" class="zs-btn-outline zs-btn-compact is-danger" form="translation-reject-<?= (int)$version['id'] ?>"><?= e(t('admin.editorial_edit.odrzuc_do_poprawy')) ?></button>
              <?php elseif ($versionStatus === 'published'): ?>
                <span class="translation-publisher-done"><?= e(t('admin.editorial_edit.sprawdzone_i_opublikowane_przez_wydawce')) ?></span>
              <?php elseif ($versionStatus === 'rejected'): ?>
                <span class="translation-publisher-rejected"><?= e(t('admin.editorial_edit.odrzucone_popraw_tresc_i_zapisz_wersje_ponownie')) ?></span>
              <?php elseif ($versionStatus === 'error'): ?>
                <span class="translation-publisher-rejected"><?= e(t('admin.editorial_edit.bad_tumaczenia_popraw_lub_wygeneruj_wersje_ponownie')) ?></span>
              <?php endif; ?>
            </span>
          </div>
        </div>
        <?php if ($canApproveVersion): ?>
          <div class="translation-publisher-note">
            <strong><?= e(t('admin.editorial_edit.decyzja_wydawcy')) ?></strong>
            <span><?= e(t('admin.editorial_edit.sprawdz_podglad_porownaj_tumaczenie_z_oryginaem_a_naste_3520c871')) ?></span>
          </div>
        <?php endif; ?>
        <div class="translation-review-feedback" aria-live="polite"></div>
        <div class="field-group">
          <label class="field">
            <span><?= e(t('author.article.title')) ?></span>
            <input name="language_versions[<?= e($l) ?>][title]" value="<?= e($versionTitle) ?>" <?= $isSourceLanguage ? 'required' : '' ?>>
          </label>
          <label class="field">
            <span><?= e(t('author.article.lead')) ?></span>
            <textarea name="language_versions[<?= e($l) ?>][lead]" rows="4"><?= e($versionLead) ?></textarea>
          </label>
          <label class="field">
            <span><?= e(t('admin.editorial_edit.tresc')) ?></span>
            <textarea name="language_versions[<?= e($l) ?>][body]" rows="18" <?= $isSourceLanguage ? 'required' : '' ?>><?= e($versionBody) ?></textarea>
          </label>
        </div>
      </div>
    <?php endforeach; ?>
  </section>

  <section id="settings" class="editorial-section u-mt-8">
    <div class="section-head"><h2><?= e(t('admin.editorial_edit.jezyk_oryginau_i_dyspozycja_do_tumaczenia')) ?></h2></div>
    <div class="field-group">
      <label class="field">
        <span><?= e(t('editorial.editing.source_language')) ?></span>
        <select name="source_language" required>
          <?php foreach ($langs as $l): ?>
            <option value="<?= e($l) ?>" <?= $l === $currentSourceLang ? 'selected' : '' ?>><?= e(strtoupper($l)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field">
        <span><?= e(t('admin.editorial_edit.dyspozycja_redakcyjna_do_tumaczenia')) ?></span>
        <textarea name="translation_instructions" rows="5" placeholder="<?= e(t('admin.editorial_edit.wpisz_zalecenia_dla_tumaczenia')) ?>"><?= e($translationInstructions) ?></textarea>
        <small class="field-help"><?= e(t('admin.editorial_edit.jezeli_artyku_ma_wasna_dyspozycje_ai_uzyje_jej_zamiast_3e7acdce')) ?></small>
      </label>
    </div>
  </section>

  <section id="image" class="editorial-section u-mt-8">
    <div class="section-head"><h2><?= e(t('admin.editorial_edit.zdjecie_wyrozniajace')) ?></h2></div>
    <div class="zs-upload-module zs-article-image-editor editorial-image-module-wide" id="editorial-image-module">
      <input type="file" name="image" id="editorial-image-input" accept="image/jpeg,image/png,image/webp" hidden>
      <input type="hidden" name="image_data" id="editorial-image-data" value="">
      <input type="hidden" name="image_name" id="editorial-image-name" value="">
      <input type="hidden" name="image_position" id="editorial-image-position" value="50">

      <div class="zs-upload-dropzone zs-article-crop-frame <?= $mainMedia ? 'has-image has-current-image' : '' ?>" id="editorial-image-dropzone">
        <div class="zs-upload-placeholder">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="zs-upload-icon"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
          <p><?= e(t('admin.editorial_edit.przeciagnij_zdjecie_albo_wybierz_plik')) ?></p>
          <button type="button" class="zs-btn-small" id="editorial-image-select"><?= e(t('response.form.image_change')) ?></button>
        </div>
        <?php if ($mainMedia): ?>
          <img src="<?= e($mainMedia['path']) ?>" alt="" class="zs-current-article-image" id="editorial-current-image">
        <?php else: ?>
          <img src="" alt="" class="zs-current-article-image" id="editorial-current-image" style="display:none">
        <?php endif; ?>
        <canvas id="editorial-image-canvas" width="1600" height="900" aria-label="<?= e(t('admin.editorial_edit.edytor_zdjecia_artykuu_wydawcy')) ?>"></canvas>
      </div>

      <div class="zs-image-adjuster zs-image-editor-controls" id="editorial-image-adjuster" style="<?= $mainMedia ? '' : 'display:none' ?>">
        <label><?= e(t('admin.editorial_edit.powiekszenie_zoom')) ?></label>
        <input type="range" min="1" max="5" step="0.01" value="1" class="zs-range" id="editorial-image-zoom">
        <div class="zs-image-editor-actions">
          <span class="file-name" id="editorial-image-file-name"><?= $mainMedia ? e($mainMedia['title'] ?? '') : '' ?></span>
          <button type="button" class="zs-btn-mini" id="editorial-image-change"><?= e(t('response.form.image_change')) ?></button>
        </div>
        <p class="field-help"><?= e(t('admin.editorial_edit.ten_ekran_uzywa_tego_samego_edytora_co_avatar_i_autor_d_7a243076')) ?></p>
      </div>

      <div id="editorial-image-status" class="zs-upload-status"></div>
    </div>
  </section>

  <section id="ranking" class="editorial-section u-mt-8">
    <div class="section-head"><h2><?= e(t('admin.editorial_edit.kolejnosc_i_waznosc')) ?></h2></div>
    <div class="field-group">
      <label class="field"><span><?= e(t('admin.editorial_edit.kolejnosc_wyswietlania')) ?></span><input type="number" name="display_order" value="<?= (int)($article['display_order'] ?? 0) ?>"></label>
      <label class="field"><span><?= e(t('editorial.editing.editorial_weight')) ?></span><input type="number" name="editorial_weight" value="<?= (int)($article['editorial_weight'] ?? 0) ?>"></label>
      <label class="field-checkbox">
        <input type="checkbox" name="is_featured" value="1" <?= !empty($article['is_featured']) ? 'checked' : '' ?>>
        <span><?= e(t('admin.editorial_edit.oznacz_jako_wazne_promowane')) ?></span>
      </label>
    </div>
  </section>

  <div class="editorial-form-footer u-mt-10">
    <button type="submit" class="zs-btn btn-large"><?= e(t('admin.editorial_edit.zapisz_tekst_i_wersje_jezykowe')) ?></button>
    <a href="/admin/editorial" class="text-link"><?= e(t('admin.editorial_edit.powrot_do_listy')) ?></a>
  </div>
</form>

<?php if ($canReviewTranslations): ?>
  <?php foreach ($translationsMap as $language => $translation): ?>
    <?php
      $translationId = (int)($translation['id'] ?? 0);
      if ($translationId <= 0 || $language === $currentSourceLang) {
          continue;
      }
    ?>
    <form id="translation-approve-<?= $translationId ?>" class="translation-review-form" data-language="<?= e($language) ?>" method="post" action="/admin/articles/translations/review" hidden>
      <?= csrf_field() ?>
      <input type="hidden" name="article_id" value="<?= (int)$article['id'] ?>">
      <input type="hidden" name="translation_id" value="<?= $translationId ?>">
      <input type="hidden" name="review_action" value="approve_publish">
    </form>
    <form id="translation-reject-<?= $translationId ?>" class="translation-review-form" data-language="<?= e($language) ?>" method="post" action="/admin/articles/translations/review" hidden>
      <?= csrf_field() ?>
      <input type="hidden" name="article_id" value="<?= (int)$article['id'] ?>">
      <input type="hidden" name="translation_id" value="<?= $translationId ?>">
      <input type="hidden" name="review_action" value="reject">
    </form>
  <?php endforeach; ?>
<?php endif; ?>

<style>
.editorial-language-editor { max-width: 1180px; margin-top: 2rem; }
.editorial-section { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 1.5rem; }
.section-head { border-bottom: 1px solid #edf2f7; padding-bottom: 0.75rem; margin-bottom: 1.25rem; }
.section-head h2 { font-size: 1.25rem; color: #2d3748; }
.section-head p { margin: .25rem 0 0; color: #718096; }
.field-group { display: flex; flex-direction: column; gap: 1.25rem; }
.editorial-language-tabs { display: flex; flex-wrap: wrap; gap: .5rem; margin-bottom: 1.25rem; }
.editorial-language-tab { border: 1px solid #e2e8f0; background: #fff; border-radius: 8px; padding: .7rem 1rem; cursor: pointer; min-width: 92px; text-align: left; }
.editorial-language-tab strong { display: block; letter-spacing: .08em; }
.editorial-language-tab span { display: block; font-size: .78rem; color: #718096; margin-top: .15rem; }
.editorial-language-tab.is-source { border-color: #9bb7d1; background: #edf5fc; color: #164e78; }
.editorial-language-tab.has-version { border-color: #a9d8b4; background: #effaf2; color: #176b34; }
.editorial-language-tab.is-missing { border-color: #e2e8f0; background: #f7f7f7; color: #718096; }
.editorial-language-tab.is-incomplete { border-color: #ead59c; background: #fff9e9; color: #7a5200; }
.editorial-language-tab.is-error { border-color: #e2a8ae; background: #fff1f2; color: #a31324; }
.editorial-language-tab.is-active { border-color: #c53030; background: #fff5f5; color: #9b2c2c; }
.editorial-language-panel { display: none; }
.editorial-language-panel.is-active { display: block; }
.language-panel-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; margin-bottom: 1rem; }
.language-panel-head h3 { margin: 0; font-size: 1rem; letter-spacing: .08em; text-transform: uppercase; }
.language-panel-head > div:first-child { display: flex; align-items: center; flex-wrap: wrap; gap: .6rem; }
.translation-review-actions { display: flex; align-items: center; justify-content: flex-end; flex-wrap: wrap; gap: .5rem; }
.translation-review-decision { display: inline-flex; align-items: center; justify-content: flex-end; flex-wrap: wrap; gap: .5rem; }
.translation-publisher-note { display: grid; gap: .25rem; margin: 0 0 1rem; padding: .85rem 1rem; border-left: 3px solid #d88a00; background: #fff9e9; color: #654500; }
.translation-publisher-note strong { font-size: .72rem; letter-spacing: .1em; }
.translation-publisher-note span { font-size: .82rem; line-height: 1.45; }
.translation-publisher-done { color: #176b34; font-size: .8rem; font-weight: 800; }
.translation-publisher-rejected { color: #a31324; font-size: .8rem; font-weight: 800; }
.translation-review-feedback { min-height: 0; margin-bottom: .75rem; }
.translation-review-feedback:empty { display: none; }
.editorial-image-module { display: flex; gap: 2rem; align-items: flex-start; flex-wrap: wrap; }
.editorial-image-module-wide { align-items: stretch; }
.editorial-image-dropzone { width: min(100%, 720px); border: 1px solid #e2e8f0; border-radius: 12px; background: #f7fafc; padding: .75rem; transition: border-color .15s ease, background .15s ease; }
.editorial-image-dropzone.dragover { border-color: #c53030; background: #fff5f5; }
.editorial-image-frame { position: relative; width: 100%; aspect-ratio: 16 / 9; min-height: 330px; overflow: hidden; border-radius: 10px; background: #edf2f7; cursor: grab; }
.editorial-image-frame:active { cursor: grabbing; }
.editorial-image-frame img { position: absolute; left: 50%; top: 50%; width: 100%; height: 100%; object-fit: contain; user-select: none; pointer-events: none; transform: translate(-50%, -50%) scale(1); transform-origin: center center; }
.editorial-image-controls { min-width: 260px; max-width: 360px; }
.image-placeholder { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; color: #a0aec0; text-align: center; padding: 2rem; }
.u-mt-8 { margin-top: 2rem; }
.u-mt-10 { margin-top: 3rem; }
.editorial-form-footer { display: flex; align-items: center; gap: 2rem; border-top: 1px solid #e2e8f0; padding-top: 2rem; }
.btn-large { padding: 1rem 2.5rem; font-size: 1.1rem; }

.editorial-image-module-wide { max-width: 760px; }
.editorial-image-module-wide .zs-article-crop-frame { min-height: 380px; height: min(54vw, 500px); }
.editorial-image-module-wide .zs-image-editor-controls { max-width: 760px; border-top: none; }
</style>

<script src="/assets/js/slowo-image-editor.js"></script>
<script>
  const editorialUi = <?= json_encode([
    'invalidImage' => t('upload.image.invalid_type'),
    'published' => t('admin.editorial_edit.sprawdzone_i_opublikowane_przez_wydawce'),
    'rejected' => t('admin.editorial_edit.odrzucone_popraw_tresc_i_zapisz_wersje_ponownie'),
    'statusPublished' => t('article.status.published'),
    'statusRejected' => t('article.status.rejected'),
    'decisionSaveError' => t('admin.editorial_edit.decision_save_error'),
    'decisionSaved' => t('admin.editorial_edit.decision_saved'),
    'connectionError' => t('common.connection_error'),
    'saveError' => t('common.save_error'),
    'saved' => t('common.saved'),
    'saveButton' => t('admin.role_panel.save_button'),
    'saving' => t('admin.role_panel.saving'),
    'publisherDecisionSaving' => t('admin.editorial_edit.publisher_decision_saving'),
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const escapeHtml = (value) => String(value).replace(/[&<>'"]/g, (character) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;'
  })[character]);
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.editorial-language-tab').forEach(function (tab) {
    tab.addEventListener('click', function () {
      var lang = tab.dataset.languageTab;
      document.querySelectorAll('.editorial-language-tab').forEach(function (item) { item.classList.remove('is-active'); });
      document.querySelectorAll('.editorial-language-panel').forEach(function (panel) { panel.classList.remove('is-active'); });
      tab.classList.add('is-active');
      var panel = document.querySelector('[data-language-panel="' + lang + '"]');
      if (panel) { panel.classList.add('is-active'); }
    });
  });

  var imageInput = document.getElementById('editorial-image-input');
  var dropzone = document.getElementById('editorial-image-dropzone');
  var adjuster = document.getElementById('editorial-image-adjuster');
  var currentImage = document.getElementById('editorial-current-image');
  var statusNode = document.getElementById('editorial-image-status');

  if (window.SlowoImageEditor && imageInput && dropzone) {
    var editor = new window.SlowoImageEditor({
      input: imageInput,
      dropzone: dropzone,
      canvas: document.getElementById('editorial-image-canvas'),
      zoom: document.getElementById('editorial-image-zoom'),
      hiddenData: document.getElementById('editorial-image-data'),
      hiddenName: document.getElementById('editorial-image-name'),
      fileNameNode: document.getElementById('editorial-image-file-name'),
      statusNode: statusNode,
      width: 1600,
      height: 900,
      outputType: 'image/webp',
      outputQuality: 0.88,
      invalidTypeMessage: editorialUi.invalidImage,
      onReady: function () {
        if (adjuster) { adjuster.style.display = 'block'; }
        if (currentImage) { currentImage.style.display = 'none'; }
        dropzone.classList.remove('has-current-image');
        dropzone.classList.add('has-image');
      },
      onClear: function () {
        if (currentImage && currentImage.getAttribute('src')) {
          currentImage.style.display = 'block';
          dropzone.classList.add('has-image', 'has-current-image');
          if (adjuster) { adjuster.style.display = 'block'; }
        } else if (adjuster) {
          adjuster.style.display = 'none';
        }
      }
    });

    var selectBtn = document.getElementById('editorial-image-select');
    var changeBtn = document.getElementById('editorial-image-change');
    if (selectBtn) { selectBtn.addEventListener('click', function () { imageInput.click(); }); }
    if (changeBtn) { changeBtn.addEventListener('click', function () { imageInput.click(); }); }
  }

  function showLocalMessage(element, text, type) {
    let container = element.parentNode;
    let msg = container.querySelector('.zs-local-msg');
    if (!msg) {
      msg = document.createElement('div');
      msg.className = 'zs-local-msg';
      container.appendChild(msg);
    }
    msg.textContent = text;
    msg.className = 'zs-local-msg ' + type;
    msg.style.opacity = '1';
    setTimeout(() => {
      msg.style.opacity = '0';
      setTimeout(() => msg.remove(), 500);
    }, 4000);
  }

  function showReviewMessage(panel, text, type) {
    const feedback = panel ? panel.querySelector('.translation-review-feedback') : null;
    if (!feedback) {
      return;
    }
    feedback.textContent = text;
    feedback.className = 'translation-review-feedback zs-local-msg ' + type;
  }

  function updateTranslationReviewState(panel, language, status) {
    if (!panel) {
      return;
    }

    panel.dataset.translationStatus = status;
    const statusBadge = panel.querySelector('.translation-workflow-status');
    const decision = panel.querySelector('.translation-review-decision');
    const note = panel.querySelector('.translation-publisher-note');
    const tab = document.querySelector('[data-language-tab="' + language + '"]');

    if (statusBadge) {
      statusBadge.textContent = status === 'published' ? editorialUi.statusPublished : editorialUi.statusRejected;
      statusBadge.className = 'zs-status-badge translation-workflow-status ' + (status === 'published' ? 'paid' : 'failed');
    }
    if (tab) {
      tab.classList.remove('has-version', 'is-incomplete', 'is-missing', 'is-error');
      tab.classList.add(status === 'published' ? 'has-version' : 'is-error');
      const label = tab.querySelector('span');
      if (label) {
        label.textContent = status === 'published' ? editorialUi.statusPublished : editorialUi.statusRejected;
      }
    }
    if (decision) {
      decision.innerHTML = status === 'published'
        ? '<span class="translation-publisher-done">' + escapeHtml(editorialUi.published) + '</span>'
        : '<span class="translation-publisher-rejected">' + escapeHtml(editorialUi.rejected) + '</span>';
    }
    if (note) {
      note.remove();
    }
  }

  document.querySelectorAll('.translation-review-form').forEach(function (form) {
    form.addEventListener('submit', async function (event) {
      event.preventDefault();
      const language = form.dataset.language || '';
      const panel = document.querySelector('[data-language-panel="' + language + '"]');
      const buttons = panel
        ? Array.from(panel.querySelectorAll('.translation-review-actions button'))
        : Array.from(document.querySelectorAll('button[form="' + form.id + '"]'));
      const clickedButton = event.submitter || document.querySelector('button[form="' + form.id + '"]');
      const originalText = clickedButton ? clickedButton.textContent : '';

      buttons.forEach(function (button) { button.disabled = true; });
      if (clickedButton) {
        clickedButton.textContent = editorialUi.saving;
      }
      showReviewMessage(panel, editorialUi.publisherDecisionSaving, 'success');

      try {
        const response = await fetch(form.action, {
          method: 'POST',
          headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': '<?php echo csrf_token(); ?>',
            'Accept': 'application/json'
          },
          body: new FormData(form)
        });
        const data = await response.json();
        if (!response.ok || !data.success) {
          throw new Error(data.message || editorialUi.decisionSaveError);
        }

        updateTranslationReviewState(panel, language, data.status || '');
        showReviewMessage(panel, data.message || editorialUi.decisionSaved, 'success');
      } catch (error) {
        buttons.forEach(function (button) { button.disabled = false; });
        if (clickedButton) {
          clickedButton.textContent = originalText;
        }
        showReviewMessage(panel, error.message || editorialUi.connectionError, 'error');
      }
    });
  });

  const editorialForm = document.querySelector('.ajax-form');
  if (editorialForm) {
    editorialForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      const button = editorialForm.querySelector('button[type="submit"]');
      const originalText = button ? button.textContent : editorialUi.saveButton;

      if (button) {
        button.disabled = true;
        button.textContent = editorialUi.saving;
      }

      try {
        const formData = new FormData(editorialForm);
        const response = await fetch(editorialForm.action, {
          method: 'POST',
          headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': '<?php echo csrf_token(); ?>'
          },
          body: formData
        });

        const data = await response.json();

        if (button) {
          button.disabled = false;
          button.textContent = originalText;
        }

        if (data.success) {
          showLocalMessage(button, data.message || editorialUi.saved, 'success');
          if (data.redirect) {
            setTimeout(() => window.location.href = data.redirect, 1000);
          }
        } else {
          showLocalMessage(button, data.message || editorialUi.saveError, 'error');
        }
      } catch (error) {
        if (button) {
          button.disabled = false;
          button.textContent = originalText;
          showLocalMessage(button, editorialUi.connectionError, 'error');
        }
      }
    });
  }
});
</script>

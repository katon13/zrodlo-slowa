<?php
$response = is_array($response ?? null) ? $response : null;
$source = is_array($source ?? null) ? $source : [];
$isEdit = $response !== null;
$language = public_language();
$action = public_language_url($language, $isEdit ? '/opinie/aktualizuj' : '/opinie');
$mediaItems = is_array($media ?? null) ? $media : [];
$currentImage = isset($mediaItems[0]) && is_array($mediaItems[0]) ? $mediaItems[0] : null;
$hasCurrentImage = $currentImage !== null && trim((string)($currentImage['path'] ?? '')) !== '';
$submissionDepositPoints = max(0, (int)($submission_deposit_points ?? 0));
?>

<section class="zs-response-form-page">
  <header class="zs-response-form-head">
    <p class="kicker"><?= e(t('response.form.kicker', $language)) ?></p>
    <h1><?= e(t($isEdit ? 'response.form.edit_title' : 'response.form.create_title', $language)) ?></h1>
    <p><?= e(t('response.form.intro', $language)) ?></p>
  </header>

  <aside class="zs-response-source-card">
    <span><?= e(t('response.form.responding_to', $language)) ?></span>
    <h2><?= e((string)($source['title'] ?? $response['source_title'] ?? '')) ?></h2>
    <p><?= e(mb_strimwidth((string)($source['lead'] ?? ''), 0, 240, '…', 'UTF-8')) ?></p>
    <?php if (!empty($source['id'])): ?><a href="<?= e(public_language_url($language, '/article?id=' . (int)$source['id'])) ?>" target="_blank" rel="noopener"><?= e(t('response.form.open_source', $language)) ?></a><?php endif; ?>
  </aside>

  <form method="post" action="<?= e($action) ?>" enctype="multipart/form-data" class="zs-response-editor">
    <?= csrf_field() ?>
    <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int)$response['id'] ?>"><?php else: ?><input type="hidden" name="source_article_id" value="<?= (int)$source['id'] ?>"><?php endif; ?>
    <label><span><?= e(t('response.form.title_label', $language)) ?></span><input name="title" required maxlength="255" value="<?= e((string)($response['title'] ?? '')) ?>" placeholder="<?= e(t('response.form.title_placeholder', $language)) ?>"></label>
    <label><span><?= e(t('response.form.lead_label', $language)) ?></span><textarea name="lead" rows="3" placeholder="<?= e(t('response.form.lead_placeholder', $language)) ?>"><?= e((string)($response['lead'] ?? '')) ?></textarea></label>
    <label><span><?= e(t('response.form.body_label', $language)) ?></span><textarea name="body" rows="18" required placeholder="<?= e(t('response.form.body_placeholder', $language)) ?>"><?= e((string)($response['body'] ?? '')) ?></textarea></label>
    <div class="zs-response-editor-row">
<label><span><?= e(t('response.form.source_language', $language)) ?></span><select name="source_language"><?php foreach (['pl','en','de','fr','it','es'] as $code): ?><option value="<?= e($code) ?>" <?= (string)($response['source_language'] ?? 'pl') === $code ? 'selected' : '' ?>><?= e(t('language.native.' . $code, $language)) ?></option><?php endforeach; ?></select></label>
      <div class="zs-response-image-field">
        <span class="zs-response-field-label"><?= e(t('response.form.image', $language)) ?></span>
        <input type="file" name="image" id="response-image-input" accept="image/jpeg,image/png,image/webp" hidden>
        <input type="hidden" name="image_position" value="50">
        <div
          class="zs-upload-dropzone zs-response-image-dropzone<?= $hasCurrentImage ? ' has-image' : '' ?>"
          id="response-image-dropzone"
          role="button"
          tabindex="0"
          aria-label="<?= e(t('response.form.image_drop', $language)) ?>"
        >
          <div class="zs-upload-placeholder">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="zs-upload-icon" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
            <p><?= e(t('response.form.image_drop', $language)) ?></p>
            <button type="button" class="zs-btn-small" id="response-image-select"><?= e(t('response.form.image_select', $language)) ?></button>
            <small><?= e(t('response.form.image_hint', $language)) ?></small>
          </div>
          <img
            id="response-image-preview"
            class="zs-response-image-preview"
            src="<?= $hasCurrentImage ? e((string)$currentImage['path']) : '' ?>"
            alt="<?= e(t('response.form.image_preview_alt', $language)) ?>"
            <?= $hasCurrentImage ? '' : 'hidden' ?>
          >
        </div>
        <div class="zs-response-image-selection" id="response-image-selection" <?= $hasCurrentImage ? '' : 'hidden' ?>>
          <span id="response-image-name"><?= $hasCurrentImage ? e((string)($currentImage['title'] ?? t('response.form.image_current', $language))) : '' ?></span>
          <button type="button" class="zs-btn-mini" id="response-image-change"><?= e(t('response.form.image_change', $language)) ?></button>
        </div>
        <p class="zs-upload-status<?= $hasCurrentImage ? ' success' : '' ?>" id="response-image-status" aria-live="polite"><?= $hasCurrentImage ? e(t('response.form.image_current', $language)) : '' ?></p>
      </div>
    </div>
    <div class="zs-response-editor-notice"><strong><?= e(t('response.form.talent_notice_title', $language)) ?></strong><span><?= e(t('response.form.talent_notice_body', $language)) ?></span></div>
    <?php if ($submissionDepositPoints > 0): ?>
      <div class="zs-response-editor-notice zs-response-deposit-notice">
        <strong><?= e(str_replace('{points}', (string)$submissionDepositPoints, t('response.form.deposit_notice_title', $language))) ?></strong>
        <span><?= e(t('response.form.deposit_notice_body', $language)) ?></span>
      </div>
    <?php endif; ?>
    <div class="zs-response-editor-actions">
      <button class="btn-line" type="submit" name="intent" value="draft"><?= e(t('response.form.save_draft', $language)) ?></button>
      <button class="btn-red" type="submit" name="intent" value="submit"><?= e(t('response.form.send', $language)) ?></button>
      <a href="<?= e(public_language_url($language, '/opinie')) ?>"><?= e(t('response.form.back', $language)) ?></a>
    </div>
  </form>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var input = document.getElementById('response-image-input');
  var dropzone = document.getElementById('response-image-dropzone');
  var preview = document.getElementById('response-image-preview');
  var selection = document.getElementById('response-image-selection');
  var fileName = document.getElementById('response-image-name');
  var status = document.getElementById('response-image-status');
  var selectButton = document.getElementById('response-image-select');
  var changeButton = document.getElementById('response-image-change');
  var previewUrl = '';

  if (!input || !dropzone || !preview || !selection || !fileName || !status || !selectButton || !changeButton) return;

  var messages = {
    ready: <?= json_encode(t('response.form.image_ready', $language), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    invalidType: <?= json_encode(t('response.form.image_type_error', $language), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    tooLarge: <?= json_encode(t('response.form.image_size_error', $language), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    dropUnsupported: <?= json_encode(t('response.form.image_drop_error', $language), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
  };
  var allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
  var maxSize = 5 * 1024 * 1024;

  function setStatus(message, type) {
    status.textContent = message;
    status.className = 'zs-upload-status' + (type ? ' ' + type : '');
  }

  function showPreview(file) {
    if (!file || allowedTypes.indexOf(file.type) === -1) {
      setStatus(messages.invalidType, 'error');
      return false;
    }
    if (file.size > maxSize) {
      setStatus(messages.tooLarge, 'error');
      return false;
    }
    if (previewUrl) URL.revokeObjectURL(previewUrl);
    previewUrl = URL.createObjectURL(file);
    preview.src = previewUrl;
    preview.hidden = false;
    dropzone.classList.add('has-image');
    selection.hidden = false;
    fileName.textContent = file.name || '';
    setStatus(messages.ready, 'success');
    return true;
  }

  input.addEventListener('change', function () {
    var file = input.files && input.files[0];
    if (!showPreview(file)) input.value = '';
  });

  selectButton.addEventListener('click', function (event) {
    event.stopPropagation();
    input.click();
  });
  changeButton.addEventListener('click', function () {
    input.click();
  });
  dropzone.addEventListener('click', function () {
    input.click();
  });
  dropzone.addEventListener('keydown', function (event) {
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      input.click();
    }
  });

  ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(function (eventName) {
    dropzone.addEventListener(eventName, function (event) {
      event.preventDefault();
      event.stopPropagation();
    });
  });
  ['dragenter', 'dragover'].forEach(function (eventName) {
    dropzone.addEventListener(eventName, function () {
      dropzone.classList.add('dragover');
    });
  });
  ['dragleave', 'drop'].forEach(function (eventName) {
    dropzone.addEventListener(eventName, function () {
      dropzone.classList.remove('dragover');
    });
  });
  dropzone.addEventListener('drop', function (event) {
    var file = event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files[0];
    if (!file || !showPreview(file)) return;
    try {
      var transfer = new DataTransfer();
      transfer.items.add(file);
      input.files = transfer.files;
    } catch (error) {
      input.value = '';
      setStatus(messages.dropUnsupported, 'error');
    }
  });

  window.addEventListener('beforeunload', function () {
    if (previewUrl) URL.revokeObjectURL(previewUrl);
  });
});
</script>

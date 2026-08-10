<section class="auth-panel zs-author-page">
  <div class="auth-copy">
    <p class="kicker"><?= t('author.dashboard.permission_writing') ?></p>
    <h1><?= t('author.article.new') ?></h1>
    <p><?= t('author.article.new_desc') ?></p>
  </div>
  <div class="form-card">
    <form method="post" action="<?= e(public_language_url($current_language, '/author/articles')) ?>" enctype="multipart/form-data" class="form-grid" id="article-form">
      <?= csrf_field() ?>
      <input type="hidden" name="_lang" value="<?= e($current_language) ?>">
      <label class="field"><span><?= t('author.article.title') ?></span><input name="title" required></label>
      <label class="field"><span><?= t('author.article.lead') ?></span><textarea name="lead"></textarea></label>

      <div class="zs-upload-module zs-article-image-editor" id="article-image-module">
        <p class="kicker"><?= t('author.article.featured_image') ?></p>
        <input type="file" id="image-input" accept="image/jpeg,image/png,image/webp" hidden>
        <input type="hidden" name="image_data" id="image-data" value="">
        <input type="hidden" name="image_name" id="image-name" value="">

        <div class="zs-upload-dropzone zs-article-crop-frame" id="dropzone">
          <div class="zs-upload-placeholder">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="zs-upload-icon"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
            <p><?= t('author.article.drag_drop') ?></p>
            <button type="button" class="zs-btn-small" id="select-image-btn"><?= t('author.article.select_image') ?></button>
          </div>
          <canvas id="article-image-canvas" width="1600" height="900" aria-label="<?= e(t('ui.author.create_article.edytor_zdjecia_artykuu')) ?>"></canvas>
        </div>

        <div class="zs-image-adjuster zs-image-editor-controls" id="image-adjuster" style="display:none">
          <label><?= e(t('admin.editorial_edit.powiekszenie_zoom')) ?></label>
          <input type="range" min="1" max="5" step="0.01" value="1" class="zs-range" id="image-zoom">
          <div class="zs-image-editor-actions">
            <span class="file-name" id="image-file-name"></span>
            <button type="button" class="zs-btn-mini" id="change-image-btn"><?= t('author.article.change_image') ?></button>
            <button type="button" class="zs-btn-mini btn-outline" id="clear-image-btn"><?= t('author.article.remove_image') ?></button>
          </div>
        </div>

        <div id="upload-status" class="zs-upload-status"></div>
      </div>

      <label class="field"><span><?= t('author.article.body') ?></span><textarea name="body" rows="14" required></textarea></label>

      <div class="zs-language-source-picker">
        <label class="field">
          <span><?= t('author.article.source_language.label') ?></span>
          <select name="source_language" required>
            <?php
              $currentLang = public_language();
              $langs = ['pl', 'en', 'de', 'fr', 'it', 'es'];
              foreach ($langs as $l):
            ?>
              <option value="<?= $l ?>" <?= $l === $currentLang ? 'selected' : '' ?>>
                <?= t('author.article.source_language.' . $l) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <small class="field-help"><?= t('author.article.source_language.help') ?></small>
        </label>
      </div>

      <div class="admin-note editorial-note"><?= t('author.article.editorial_note') ?></div>

      <div class="form-actions">
        <button class="btn-red" type="submit"><?= t('author.article.save_draft') ?></button>
        <a href="/author" class="text-link"><?= t('author.article.cancel') ?></a>
      </div>
    </form>
  </div>
</section>

<script src="/assets/js/slowo-image-editor.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const imageInput = document.getElementById('image-input');
    const uploadStatus = document.getElementById('upload-status');
    const adjuster = document.getElementById('image-adjuster');
    const editor = new SlowoImageEditor({
        input: imageInput,
        dropzone: document.getElementById('dropzone'),
        canvas: document.getElementById('article-image-canvas'),
        zoom: document.getElementById('image-zoom'),
        hiddenData: document.getElementById('image-data'),
        hiddenName: document.getElementById('image-name'),
        fileNameNode: document.getElementById('image-file-name'),
        statusNode: uploadStatus,
        width: 1600,
        height: 900,
        outputType: 'image/webp',
        outputQuality: 0.88,
        invalidTypeMessage: "<?= t('author.article.validation.image_type') ?>",
        onReady: function () {
            adjuster.style.display = 'block';
        },
        onClear: function () {
            adjuster.style.display = 'none';
        }
    });

    document.getElementById('select-image-btn').addEventListener('click', function () {
        imageInput.click();
    });
    document.getElementById('change-image-btn').addEventListener('click', function () {
        imageInput.click();
    });
    document.getElementById('clear-image-btn').addEventListener('click', function () {
        editor.clear();
    });

    document.getElementById('article-form').addEventListener('submit', function () {
        if (document.getElementById('image-data').value) {
            uploadStatus.textContent = "<?= t('author.article.uploading') ?>";
            uploadStatus.className = 'zs-upload-status processing';
        }
    });
});
</script>

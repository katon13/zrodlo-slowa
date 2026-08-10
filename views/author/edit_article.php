<section class="auth-panel zs-author-page">
  <div class="auth-copy">
    <p class="kicker"><?= t('author.dashboard.permission_writing') ?></p>
    <h1><?= t('author.article.edit') ?></h1>
    <p><?= t('author.article.edit_desc') ?></p>
  </div>
  <div class="form-card">
    <form method="post" action="<?= e(public_language_url($current_language, '/author/articles/update')) ?>" enctype="multipart/form-data" class="form-grid" id="article-form">
      <?= csrf_field() ?>
      <input type="hidden" name="_lang" value="<?= e($current_language) ?>">
      <input type="hidden" name="id" value="<?= (int)$article['id'] ?>">
      <label class="field"><span><?= t('author.article.title') ?></span><input name="title" value="<?= e($article['title']) ?>" required></label>
      <label class="field"><span><?= t('author.article.lead') ?></span><textarea name="lead"><?= e($article['lead'] ?? '') ?></textarea></label>

      <div class="zs-upload-module zs-article-image-editor" id="article-image-module" data-article-id="<?= (int)$article['id'] ?>" data-current-media-id="<?= !empty($media) ? (int)$media[0]['id'] : 0 ?>">
        <p class="kicker"><?= t('author.article.featured_image') ?></p>
        <input type="file" id="image-input" accept="image/jpeg,image/png,image/webp" hidden>
        <input type="hidden" name="image_data" id="image-data" value="">
        <input type="hidden" name="image_name" id="image-name" value="">

        <div class="zs-upload-dropzone zs-article-crop-frame <?= !empty($media) ? 'has-image has-current-image' : '' ?>" id="dropzone">
          <div class="zs-upload-placeholder">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="zs-upload-icon"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
            <p><?= t('author.article.drag_drop') ?></p>
            <button type="button" class="zs-btn-small" id="select-image-btn"><?= t('author.article.select_image') ?></button>
          </div>
          <?php if (!empty($media)): $m = $media[0]; ?>
            <img src="<?= e($m['path']) ?>" alt="" class="zs-current-article-image" id="current-image">
          <?php else: ?>
            <img src="" alt="" class="zs-current-article-image" id="current-image" style="display:none">
          <?php endif; ?>
          <canvas id="article-image-canvas" width="1600" height="900" aria-label="<?= e(t('ui.author.create_article.edytor_zdjecia_artykuu')) ?>"></canvas>
        </div>

        <div class="zs-image-adjuster zs-image-editor-controls" id="image-adjuster" style="<?= empty($media) ? 'display:none' : '' ?>">
          <label><?= e(t('admin.editorial_edit.powiekszenie_zoom')) ?></label>
          <input type="range" min="1" max="5" step="0.01" value="1" class="zs-range" id="image-zoom">
          <div class="zs-image-editor-actions">
            <span class="file-name" id="image-file-name"><?= !empty($media) ? e($media[0]['title']) : '' ?></span>
            <button type="button" class="zs-btn-mini" id="change-image-btn"><?= t('author.article.change_image') ?></button>
            <button type="button" class="zs-btn-mini btn-outline" id="clear-image-btn"><?= t('author.article.remove_image') ?></button>
            <button type="button" class="zs-btn-mini zs-btn-save-image" id="save-image-btn" style="display:none"><?= e(t('ui.author.edit_article.zapisz_zdjecie')) ?></button>
          </div>
        </div>

        <div id="upload-status" class="zs-upload-status"></div>
      </div>

      <label class="field"><span><?= t('author.article.body') ?></span><textarea name="body" rows="14" required><?= e($article['body']) ?></textarea></label>

      <div class="zs-language-source-picker">
        <label class="field">
          <span><?= t('author.article.source_language.label') ?></span>
          <select name="source_language" required>
            <?php
              $currentSourceLang = $article['source_language'] ?? 'pl';
              $langs = ['pl', 'en', 'de', 'fr', 'it', 'es'];
              foreach ($langs as $l):
            ?>
              <option value="<?= $l ?>" <?= $l === $currentSourceLang ? 'selected' : '' ?>>
                <?= t('author.article.source_language.' . $l) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <small class="field-help"><?= t('author.article.source_language.help') ?></small>
        </label>
      </div>

      <?php if (!empty($article['proofread_at'])): ?>
        <div class="admin-note editorial-note author-proofread-note">
          <strong><?= e(t('admin.dashboard.korekta')) ?></strong> — <?= e(str_replace('{date}', date('d.m.Y H:i', strtotime((string)$article['proofread_at'])), t('author.article.proofread_at'))) ?>
        </div>
      <?php endif; ?>

      <div class="admin-note editorial-note"><?= t('author.article.editorial_note') ?></div>

      <div class="form-actions">
        <button class="btn-red" type="submit"><?= t('author.article.save_changes') ?></button>
        <a href="/author" class="text-link"><?= t('author.article.back') ?></a>
      </div>
    </form>
  </div>
</section>

<style>
.author-proofread-note { border-color: #b91c1c; color: #7f1d1d; background: #fffafa; }

/* AJAX Messages */
.zs-local-msg { font-size: 11px; margin-top: 4px; padding: 2px 6px; border-radius: 4px; transition: opacity 0.5s; display: block; width: 100%; clear: both; }
.zs-local-msg.success { background: #f0fff4; color: #2f855a; border: 1px solid #c6f6d5; }
.zs-local-msg.error { background: #fff5f5; color: #c53030; border: 1px solid #feb2b2; }
</style>

<script src="/assets/js/slowo-image-editor.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const module = document.getElementById('article-image-module');
    const articleId = module.dataset.articleId;
    let currentMediaId = parseInt(module.dataset.currentMediaId || '0', 10);
    const csrf = document.querySelector('input[name="_csrf"]').value;
    const imageInput = document.getElementById('image-input');
    const uploadStatus = document.getElementById('upload-status');
    const adjuster = document.getElementById('image-adjuster');
    const saveImageBtn = document.getElementById('save-image-btn');
    const clearImageBtn = document.getElementById('clear-image-btn');
    const currentImage = document.getElementById('current-image');
    const dropzone = document.getElementById('dropzone');
    const uiText = <?= json_encode([
        'confirm_remove' => t('author.article.confirm_remove'),
        'remove' => t('author.article.remove_image'),
        'removing' => t('author.article.removing'),
        'remove_error' => t('author.article.remove_error'),
        'removed' => t('author.article.removed'),
        'connection_error' => t('author.article.connection_error'),
        'saving' => t('author.article.saving'),
        'saved_changes' => t('author.article.saved_changes'),
        'save_error' => t('author.dashboard.ajax.save_error'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    const editor = new SlowoImageEditor({
        input: imageInput,
        dropzone: dropzone,
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
            saveImageBtn.style.display = 'inline-flex';
            if (currentImage) currentImage.style.display = 'none';
            dropzone.classList.remove('has-current-image');
        },
        onClear: function () {
            saveImageBtn.style.display = 'none';
            if (currentMediaId > 0 && currentImage) {
                currentImage.style.display = 'block';
                dropzone.classList.add('has-image', 'has-current-image');
                adjuster.style.display = 'block';
            } else {
                adjuster.style.display = 'none';
            }
        }
    });

    document.getElementById('select-image-btn').addEventListener('click', function () {
        imageInput.click();
    });
    document.getElementById('change-image-btn').addEventListener('click', function () {
        imageInput.click();
    });

    clearImageBtn.addEventListener('click', function () {
        if (currentMediaId > 0 && !clearImageBtn.classList.contains('confirm-delete')) {
            clearImageBtn.dataset.originalText = clearImageBtn.textContent;
            clearImageBtn.textContent = uiText.confirm_remove;
            clearImageBtn.classList.add('confirm-delete');
            setTimeout(function () {
                clearImageBtn.textContent = clearImageBtn.dataset.originalText || uiText.remove;
                clearImageBtn.classList.remove('confirm-delete');
            }, 3000);
            return;
        }

        if (currentMediaId > 0) {
            deleteImage(currentMediaId);
            return;
        }

        editor.clear();
    });

    saveImageBtn.addEventListener('click', function () {
        const imageData = editor.getDataUrl();
        const imageName = document.getElementById('image-name').value || 'zdjecie-artykulu.webp';
        if (!imageData) return;

        const formData = new FormData();
        formData.append('image_data', imageData);
        formData.append('image_name', imageName);
        formData.append('article_id', articleId);
        formData.append('_csrf', csrf);

        uploadStatus.textContent = "<?= t('author.article.uploading_short') ?>";
        uploadStatus.className = 'zs-upload-status processing';
        saveImageBtn.disabled = true;

        fetch('/author/articles/upload-image', {
            method: 'POST',
            body: formData
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (!data.success) {
                throw new Error(data.message || "<?= t('author.article.upload_error') ?>");
            }

            currentMediaId = parseInt(data.media.id || '0', 10);
            module.dataset.currentMediaId = String(currentMediaId);
            uploadStatus.textContent = "<?= t('author.article.saved') ?>";
            uploadStatus.className = 'zs-upload-status success';
            saveImageBtn.style.display = 'none';
            saveImageBtn.disabled = false;

            if (currentImage) {
                currentImage.src = data.media.path + '?t=' + Date.now();
                currentImage.style.display = 'block';
            }

            dropzone.classList.add('has-image', 'has-current-image');
            editor.clear();
            adjuster.style.display = 'block';
            document.getElementById('image-file-name').textContent = data.media.title || '';
        })
        .catch(function (error) {
            uploadStatus.textContent = error.message || "<?= t('author.article.connection_error') ?>";
            uploadStatus.className = 'zs-upload-status error';
            saveImageBtn.disabled = false;
        });
    });

    function deleteImage(mediaId) {
        const formData = new FormData();
        formData.append('media_id', mediaId);
        formData.append('_csrf', csrf);

        uploadStatus.textContent = uiText.removing;
        uploadStatus.className = 'zs-upload-status processing';

        fetch('/author/articles/delete-image', {
            method: 'POST',
            body: formData
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (!data.success) {
                throw new Error(data.message || uiText.remove_error);
            }
            currentMediaId = 0;
            module.dataset.currentMediaId = '0';
            if (currentImage) currentImage.style.display = 'none';
            editor.clear();
            dropzone.classList.remove('has-image', 'has-current-image');
            adjuster.style.display = 'none';
            uploadStatus.textContent = uiText.removed;
            uploadStatus.className = 'zs-upload-status success';
            clearImageBtn.textContent = clearImageBtn.dataset.originalText || "<?= t('author.article.remove_image') ?>";
            clearImageBtn.classList.remove('confirm-delete');
        })
        .catch(function (error) {
            uploadStatus.textContent = error.message || uiText.connection_error;
            uploadStatus.className = 'zs-upload-status error';
        });
    }

    // Handle main article form via AJAX
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

    const articleForm = document.getElementById('article-form');
    if (articleForm) {
        articleForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            const btn = articleForm.querySelector('button[type="submit"]');
            const originalText = btn.textContent;
            
            btn.disabled = true;
            btn.textContent = uiText.saving;
            
            try {
                const formData = new FormData(articleForm);
                const response = await fetch(articleForm.action, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': '<?php echo csrf_token(); ?>'
                    },
                    body: formData
                });
                
                const data = await response.json();
                if (data.success) {
                    showLocalMessage(btn, data.message || uiText.saved_changes, 'success');
                } else {
                    showLocalMessage(btn, data.message || uiText.save_error, 'error');
                }
            } catch (err) {
                showLocalMessage(btn, uiText.connection_error, 'error');
            } finally {
                btn.disabled = false;
                btn.textContent = originalText;
            }
        });
    }
});
</script>

<?php
$currentLang = function_exists('public_language') ? public_language() : 'pl';
$accountSettingsUrl = function_exists('public_language_url') ? public_language_url($currentLang, '/account/settings') : '/account/settings';
$accountAvatarUrl = function_exists('public_language_url') ? public_language_url($currentLang, '/account/avatar') : '/account/avatar';
$readerUrl = function_exists('public_language_url') ? public_language_url($currentLang, '/reader') : '/reader';
$langs = $public_languages ?? ['pl'];
$labels = $language_labels ?? [];
$displayCurrency = $settings['display_currency'] ?? 'AUTO';
$savedInterfaceLanguage = $settings['interface_language'] ?? $currentLang;

// Avatar settings
$avatarPath = $current_user_avatar ?? ($settings['avatar_path'] ?? null);
$avatarUpdatedAt = $current_user_avatar_updated_at ?? ($settings['avatar_updated_at'] ?? null);
$displayName = $user_display_name ?? 'A'; // Zakładając że BaseController przekazuje lub musimy pobrać
// Inicjały dla fallbacku
$initials = 'A';
if (!empty($displayName)) {
    $words = explode(' ', $displayName);
    $initials = mb_strtoupper(mb_substr($words[0], 0, 1, 'UTF-8'), 'UTF-8');
    if (isset($words[1])) {
        $initials .= mb_strtoupper(mb_substr($words[1], 0, 1, 'UTF-8'), 'UTF-8');
    }
}
?>

<section class="admin-page-head">
  <p class="kicker"><?= mb_strtoupper(t('brand.name', $currentLang), 'UTF-8') ?></p>
  <h1><?= t('account.settings.title', $currentLang) ?></h1>
</section>

<div id="flash-messages">
  <?php if (!empty($flash_success)): ?>
    <div class="notice success"><?= e($flash_success) ?></div>
  <?php endif; ?>

  <?php if (!empty($flash_error)): ?>
    <div class="notice error"><?= e($flash_error) ?></div>
  <?php endif; ?>
</div>

<section class="zs-account-settings-grid">
  <!-- AVATAR SECTION -->
  <div class="admin-panel-block zs-account-settings-card zs-avatar-settings-card">
    <h3 style="margin-top: 0; margin-bottom: 0.5rem; font-size: 0.8rem; letter-spacing: 0.1em; color: #555;">
      <?= t('profile.avatar.title', $currentLang) ?>
    </h3>
    <p style="font-size: 0.9rem; color: #777; margin-bottom: 1.5rem;">
      <?= t('profile.avatar.description', $currentLang) ?>
    </p>

    <div class="avatar-editor-container" style="display: flex; flex-wrap: wrap; gap: 2rem; align-items: flex-start;">
      <!-- Avatar Preview/Circle -->
      <div id="avatar-preview-wrapper" style="position: relative; width: 160px; height: 160px; border-radius: 50%; background: #eee; overflow: hidden; border: 1px solid #ddd; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
        <?php if ($avatarPath): ?>
          <img id="avatar-img-main" src="<?= e($avatarPath) ?>?t=<?= strtotime($avatarUpdatedAt ?: 'now') ?>" alt="Avatar" style="width: 100%; height: 100%; object-fit: cover;">
        <?php else: ?>
          <div id="avatar-fallback" style="font-size: 3rem; font-weight: bold; color: #333;"><?= e($initials) ?></div>
        <?php endif; ?>
        
        <div id="avatar-dropzone" style="position: absolute; inset: 0; background: rgba(0,0,0,0.4); color: white; display: none; align-items: center; justify-content: center; text-align: center; padding: 10px; font-size: 0.8rem; cursor: pointer; transition: opacity 0.2s;">
          <?= t('profile.avatar.drop_here', $currentLang) ?>
        </div>
      </div>

      <!-- Editor Controls -->
      <div id="avatar-controls" style="flex: 1; min-width: 250px; display: none;">
        <div id="canvas-wrapper" style="position: relative; width: 300px; height: 300px; background: #000; overflow: hidden; cursor: move; border: 1px solid #ddd; margin-bottom: 1rem;">
          <canvas id="avatar-canvas" width="512" height="512" style="width: 300px; height: 300px;"></canvas>
          <div style="position: absolute; inset: 0; border-radius: 50%; box-shadow: 0 0 0 999px rgba(255,255,255,0.7); pointer-events: none; border: 1px solid #000;"></div>
        </div>
        
        <div style="margin-bottom: 1.5rem;">
          <label style="display: block; font-size: 0.7rem; font-weight: bold; text-transform: uppercase; margin-bottom: 0.5rem;"><?= t('profile.avatar.zoom', $currentLang) ?></label>
          <input type="range" id="avatar-zoom" min="1" max="3" step="0.01" value="1" style="width: 100%; accent-color: #000;">
        </div>

        <div style="display: flex; gap: 1rem;">
          <button type="button" id="save-avatar-btn" class="zs-btn-red">
            <?= t('profile.avatar.save', $currentLang) ?>
          </button>
          <button type="button" id="cancel-avatar-btn" class="zs-btn-secondary" style="background: none; border: 1px solid #ddd; padding: 0.8rem 1.5rem; cursor: pointer;">
            <?= t('author.article.cancel', $currentLang) ?>
          </button>
        </div>
      </div>

      <!-- Initial Upload Button -->
      <div id="avatar-init-upload" style="flex: 1; min-width: 250px;">
        <input type="file" id="avatar-input" accept="image/jpeg,image/png,image/webp" style="display: none;">
        <button type="button" class="zs-btn-black" onclick="document.getElementById('avatar-input').click()" style="margin-bottom: 1rem; background: #000; color: #fff; border: none; padding: 0.8rem 1.5rem; cursor: pointer; font-weight: bold; text-transform: uppercase; letter-spacing: 0.05em;">
          <?= t('profile.avatar.choose_file', $currentLang) ?>
        </button>
        <p style="font-size: 0.8rem; color: #999;"><?= t('profile.avatar.drop_here', $currentLang) ?></p>
      </div>
    </div>
  </div>

  <form id="account-settings-form" action="<?= e($accountSettingsUrl) ?>" method="POST">
    <?= csrf_field(); ?>
    
    <div class="admin-panel-block zs-account-settings-card">
      <div style="margin-bottom: 1.5rem;">
        <label style="display: block; font-weight: bold; margin-bottom: 0.5rem;">
          <?= t('account.settings.interface_language', $currentLang) ?>
        </label>
        <select name="interface_language" class="zs-input" style="width: 100%; max-width: 400px;">
          <?php foreach ($langs as $l): ?>
            <option value="<?= e($l) ?>" <?= $l === $savedInterfaceLanguage ? 'selected' : '' ?>>
              <?= e($labels[$l] ?? strtoupper($l)) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div style="margin-bottom: 1.5rem;">
        <label style="display: block; font-weight: bold; margin-bottom: 0.5rem;">
          <?= t('account.settings.display_currency', $currentLang) ?>
        </label>
        <p style="font-size: 0.9em; opacity: 0.8; margin-bottom: 0.8rem;">
          <?= t('account.settings.display_currency_desc', $currentLang) ?>
        </p>
        <select name="display_currency" class="zs-input" style="width: 100%; max-width: 400px;">
          <option value="AUTO" <?= $displayCurrency === 'AUTO' ? 'selected' : '' ?>>AUTO (<?= t('layout.menu.latest', $currentLang) ?>)</option>
          <option value="PLN" <?= $displayCurrency === 'PLN' ? 'selected' : '' ?>>PLN</option>
          <option value="EUR" <?= $displayCurrency === 'EUR' ? 'selected' : '' ?>>EUR</option>
          <option value="GBP" <?= $displayCurrency === 'GBP' ? 'selected' : '' ?>>GBP</option>
        </select>
      </div>

      <div>
        <button type="submit" class="zs-btn-red">
          <?= t('account.settings.save_btn', $currentLang) ?>
        </button>
      </div>
    </div>
  </form>
</section>

<div class="zs-panel-footer">
  <a href="<?= e($readerUrl) ?>" class="zs-link-aux"><?= t('wallet.back_to_wallet', $currentLang) ?></a>
  <span class="zs-sep">|</span>
  <a href="<?= e(public_language_url($currentLang, '/account/security')) ?>" class="zs-link-aux">Bezpieczeństwo konta</a>
</div>

<script src="/assets/js/slowo-image-editor.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('account-settings-form');
    const msgDiv = document.getElementById('flash-messages');

    if (form) {
        const submitBtn = form.querySelector('button[type="submit"]');
        const originalBtnText = submitBtn.innerText;

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            submitBtn.disabled = true;
            submitBtn.innerText = '...';

            fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(response => {
                if (!response.ok) {
                    return response.json().then(err => { throw err; });
                }
                return response.json();
            })
            .then(data => {
                msgDiv.innerHTML = `<div class="notice success">${data.message}</div>`;
                setTimeout(() => {
                    window.location.href = data.redirect || <?= json_encode($accountSettingsUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
                }, 1000);
            })
            .catch(error => {
                console.error('Save error:', error);
                const errorMsg = error.message || <?= json_encode(t('account.settings.save_error', $currentLang), JSON_UNESCAPED_UNICODE) ?>;
                msgDiv.innerHTML = `<div class="notice error">${errorMsg}</div>`;
                submitBtn.disabled = false;
                submitBtn.innerText = originalBtnText;
            });
        });
    }

    const avatarInput = document.getElementById('avatar-input');
    const avatarControls = document.getElementById('avatar-controls');
    const avatarInitUpload = document.getElementById('avatar-init-upload');
    const saveAvatarBtn = document.getElementById('save-avatar-btn');
    const cancelAvatarBtn = document.getElementById('cancel-avatar-btn');
    const msgDivAvatar = document.getElementById('flash-messages');

    if (window.SlowoImageEditor && avatarInput) {
        const avatarEditor = new window.SlowoImageEditor({
            input: avatarInput,
            dropzone: document.getElementById('avatar-preview-wrapper'),
            canvas: document.getElementById('avatar-canvas'),
            zoom: document.getElementById('avatar-zoom'),
            width: 512,
            height: 512,
            outputType: 'image/webp',
            outputQuality: 0.9,
            invalidTypeMessage: <?= json_encode(t('profile.avatar.invalid_type', $currentLang), JSON_UNESCAPED_UNICODE) ?>,
            fileTooLargeMessage: <?= json_encode(t('profile.avatar.file_too_large', $currentLang), JSON_UNESCAPED_UNICODE) ?>,
            onReady: function () {
                avatarControls.style.display = 'block';
                avatarInitUpload.style.display = 'none';
            },
            onClear: function () {
                avatarControls.style.display = 'none';
                avatarInitUpload.style.display = 'block';
            }
        });

        cancelAvatarBtn.addEventListener('click', function () {
            avatarEditor.clear();
        });

        saveAvatarBtn.addEventListener('click', function () {
            const finalImage = avatarEditor.getDataUrl();
            if (!finalImage) { return; }

            saveAvatarBtn.disabled = true;
            saveAvatarBtn.innerText = '...';

            fetch(<?= json_encode($accountAvatarUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>, {
                method: 'POST',
                body: JSON.stringify({ image: finalImage, _lang: <?= json_encode($currentLang) ?> }),
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('input[name="_csrf"]').value,
                    'X-ZS-Lang': <?= json_encode($currentLang) ?>
                }
            })
            .then(res => res.json())
            .then(data => {
                if (!data.ok) { throw new Error(data.message || <?= json_encode(t('profile.avatar.error', $currentLang), JSON_UNESCAPED_UNICODE) ?>); }

                const mainImg = document.getElementById('avatar-img-main');
                if (mainImg) {
                    mainImg.src = data.avatar_url;
                } else {
                    const fallback = document.getElementById('avatar-fallback');
                    if (fallback) { fallback.remove(); }
                    const img = document.createElement('img');
                    img.id = 'avatar-img-main';
                    img.src = data.avatar_url;
                    img.alt = 'Avatar';
                    img.style.cssText = 'width: 100%; height: 100%; object-fit: cover;';
                    document.getElementById('avatar-preview-wrapper').prepend(img);
                }

                document.querySelectorAll('.user-avatar-small').forEach(el => { el.src = data.avatar_url; });
                msgDivAvatar.innerHTML = `<div class="notice success"><?= t('profile.avatar.saved', $currentLang) ?></div>`;
                avatarEditor.clear();
            })
            .catch(err => {
                console.error(err);
                msgDivAvatar.innerHTML = `<div class="notice error">${err.message || <?= json_encode(t('profile.avatar.error', $currentLang), JSON_UNESCAPED_UNICODE) ?>}</div>`;
            })
            .finally(() => {
                saveAvatarBtn.disabled = false;
                saveAvatarBtn.innerText = <?= json_encode(t('profile.avatar.save', $currentLang), JSON_UNESCAPED_UNICODE) ?>;
            });
        });
    }
});
</script>

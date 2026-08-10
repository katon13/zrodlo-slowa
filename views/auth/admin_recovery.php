<section class="auth-panel">
  <div class="auth-copy">
    <p class="kicker"><?= e(dors3_t('recovery_web.kicker')) ?></p>
    <h1><?= e(dors3_t('recovery_web.title')) ?></h1>
    <p><?= e(dors3_t('recovery_web.description')) ?></p>
  </div>

  <div class="form-card">
    <?php if (!empty($flash_success)): ?>
      <div class="notice success"><?= e((string)$flash_success) ?></div>
    <?php endif; ?>
    <?php if (!empty($flash_error)): ?>
      <div class="notice error"><?= e((string)$flash_error) ?></div>
    <?php endif; ?>

    <?php if (!is_array($state)): ?>
      <div class="notice warning"><?= e(dors3_t('recovery_web.restricted_warning')) ?></div>
      <form method="post" action="/security/recovery/start">
        <?= csrf_field() ?>
        <div class="form-grid">
          <label class="field">
            <span><?= e(dors3_t('recovery_web.identifier')) ?></span>
            <input type="text" name="identifier" required autocomplete="username" maxlength="255">
          </label>
          <label class="field">
            <span><?= e(dors3_t('recovery_web.password')) ?></span>
            <input type="password" name="password" required autocomplete="current-password" maxlength="4096">
          </label>
          <label class="field">
            <span><?= e(dors3_t('recovery_web.recovery_code')) ?></span>
            <input type="text" name="recovery_code" required autocomplete="one-time-code" maxlength="64">
          </label>
        </div>
        <div class="form-actions">
          <button class="btn-red" type="submit"><?= e(dors3_t('recovery_web.start_button')) ?></button>
          <a class="text-link" href="/login"><?= e(dors3_t('recovery_web.back_to_login')) ?></a>
        </div>
      </form>
    <?php else: ?>
      <?php
        $admin = is_array($state['admin'] ?? null) ? $state['admin'] : [];
        $status = is_array($state['recovery_codes'] ?? null) ? $state['recovery_codes'] : [];
        $capability = is_array($state['capability'] ?? null) ? $state['capability'] : [];
      ?>
      <div class="notice warning"><?= e(dors3_t('recovery_web.no_admin_session')) ?></div>
      <p><strong><?= e(dors3_t('recovery_web.account')) ?>:</strong> <?= e((string)($admin['display_name'] ?? $admin['email'] ?? '')) ?></p>
      <p><strong><?= e(dors3_t('recovery_web.valid_until')) ?>:</strong> <?= e((string)($capability['expires_at'] ?? '')) ?> UTC</p>

      <hr>
      <h2><?= e(dors3_t('recovery_web.device_title')) ?></h2>
      <p><?= e(dors3_t('recovery_web.device_description')) ?></p>

      <?php if (is_array($enrollment)): ?>
        <?php
          $qrDataUri = '';
          try {
              $qrDataUri = \App\Security\Dors3\MobileEnrollmentQrCode::dataUri((array)($enrollment['qr_payload'] ?? []));
          } catch (\Throwable) {
          }
        ?>
        <div class="notice success">
          <p><strong><?= e(dors3_t('recovery_web.comparison_code')) ?>:</strong> <?= e((string)($enrollment['comparison_code'] ?? '')) ?></p>
          <?php if ($qrDataUri !== ''): ?>
            <img src="<?= e($qrDataUri) ?>" width="280" height="280" alt="<?= e(dors3_t('recovery_web.qr_alt')) ?>">
          <?php endif; ?>
          <details>
            <summary><?= e(dors3_t('recovery_web.emergency_payload')) ?></summary>
            <pre><?= e(json_encode($enrollment['qr_payload'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
          </details>
        </div>
      <?php endif; ?>

      <form method="post" action="/security/recovery/enrollment/start">
        <?= csrf_field() ?>
        <label class="field">
          <span><?= e(dors3_t('recovery_web.password_again')) ?></span>
          <input type="password" name="current_password" required autocomplete="current-password" maxlength="4096">
        </label>
        <div class="form-actions">
          <button class="btn-red" type="submit"><?= e(dors3_t('recovery_web.enroll_device')) ?></button>
        </div>
      </form>

      <?php $pending = is_array($state['pending_enrollments'] ?? null) ? $state['pending_enrollments'] : []; ?>
      <?php foreach ($pending as $item): ?>
        <div class="notice">
          <p><?= e(dors3_t('recovery_web.enrollment_status', [
              'device' => (string)($item['device_name'] ?? dors3_t('recovery_web.waiting_phone')),
              'status' => dors3_t('statuses.' . (string)($item['status'] ?? 'pending')),
          ])) ?></p>
          <?php if ((string)($item['status'] ?? '') === 'completed'): ?>
            <form method="post" action="/security/recovery/enrollments/<?= e((string)$item['public_id']) ?>/approve">
              <?= csrf_field() ?>
              <label class="field">
                <span><?= e(dors3_t('recovery_web.comparison_code')) ?></span>
                <input type="text" name="comparison_code" required inputmode="numeric" pattern="[0-9]{6}" maxlength="6">
              </label>
              <button class="btn-red" type="submit"><?= e(dors3_t('recovery_web.activate_device')) ?></button>
            </form>
          <?php endif; ?>
          <form method="post" action="/security/recovery/enrollments/<?= e((string)$item['public_id']) ?>/cancel">
            <?= csrf_field() ?>
            <button class="btn-ghost" type="submit"><?= e(dors3_t('recovery_web.cancel_enrollment')) ?></button>
          </form>
        </div>
      <?php endforeach; ?>

      <h3><?= e(dors3_t('recovery_web.devices')) ?></h3>
      <?php $devices = is_array($state['devices'] ?? null) ? $state['devices'] : []; ?>
      <?php if ($devices === []): ?>
        <p><?= e(dors3_t('recovery_web.no_devices')) ?></p>
      <?php else: ?>
        <ul>
          <?php foreach ($devices as $device): ?>
            <li>
              <?= e((string)($device['display_name'] ?? $device['public_id'] ?? '')) ?> —
              <?= e(dors3_t('statuses.' . (string)($device['status'] ?? 'pending'))) ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <hr>
      <h2><?= e(dors3_t('recovery_web.codes_title')) ?></h2>
      <p><?= e(dors3_t('recovery_web.codes_status', [
          'active' => (int)($status['active'] ?? 0),
          'confirmed' => (int)($status['confirmed'] ?? 0),
      ])) ?></p>

      <?php if (is_array($recovery_codes)): ?>
        <div class="notice warning">
          <strong><?= e(dors3_t('recovery_web.codes_show_once')) ?></strong>
          <ol>
            <?php foreach ((array)($recovery_codes['codes'] ?? []) as $code): ?>
              <li><code><?= e((string)$code) ?></code></li>
            <?php endforeach; ?>
          </ol>
          <form method="post" action="/security/recovery/codes/confirm">
            <?= csrf_field() ?>
            <input type="hidden" name="batch_public_id" value="<?= e((string)($recovery_codes['batch_public_id'] ?? '')) ?>">
            <label class="field">
              <span><input type="checkbox" name="codes_saved" value="1" required> <?= e(dors3_t('recovery_web.codes_saved')) ?></span>
            </label>
            <button class="btn-red" type="submit"><?= e(dors3_t('recovery_web.confirm_codes')) ?></button>
          </form>
        </div>
      <?php else: ?>
        <form method="post" action="/security/recovery/codes/generate">
          <?= csrf_field() ?>
          <button class="btn-red" type="submit"><?= e(dors3_t('recovery_web.generate_codes')) ?></button>
        </form>
      <?php endif; ?>

      <hr>
      <h2><?= e(dors3_t('recovery_web.finish_title')) ?></h2>
      <p><?= e(dors3_t('recovery_web.finish_description')) ?></p>
      <form method="post" action="/security/recovery/finish">
        <?= csrf_field() ?>
        <button class="btn-red" type="submit"><?= e(dors3_t('recovery_web.finish_button')) ?></button>
      </form>
      <form method="post" action="/logout">
        <?= csrf_field() ?>
        <button class="btn-ghost" type="submit"><?= e(dors3_t('recovery_web.abort_button')) ?></button>
      </form>
    <?php endif; ?>
  </div>
</section>

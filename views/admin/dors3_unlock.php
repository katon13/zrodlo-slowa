<section class="admin-page-head">
  <p class="kicker"><?= e(dors3_t('admin_messages.unlock_kicker')) ?></p>
  <h1><?= e(dors3_t('admin_messages.unlock_title')) ?></h1>
  <p><?= e(dors3_t('admin_messages.unlock_description')) ?></p>
</section>

<section class="admin-panel-block" style="max-width:680px">
  <h2><?= e(dors3_t('admin_messages.reconfirm_identity')) ?></h2>
  <form method="post" action="/admin/security/unlock" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="return_path" value="<?= e((string)($return_path ?? '/admin')) ?>">
    <label for="dors3-unlock-password"><?= e(dors3_t('admin_messages.current_admin_password')) ?></label>
    <input id="dors3-unlock-password" type="password" name="password" required autocomplete="current-password">
    <button class="btn btn-primary" type="submit"><?= e(dors3_t('admin_messages.unlock_button')) ?></button>
  </form>
</section>

<section class="auth-panel">
  <div class="auth-copy">
    <p class="kicker"><?= t('auth.reset.kicker') ?></p>
    <h1><?= t('auth.reset.title') ?></h1>
    <p><?= t('auth.reset.description') ?></p>
  </div>
  <form class="form-card form-grid" method="post" action="<?= e(public_language_url($current_language, '/password/reset')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= e($token ?? '') ?>">
    <label class="field"><span><?= t('auth.reset.password_label') ?></span><input type="password" name="password" required minlength="8"></label>
    <button class="btn-red" type="submit"><?= t('auth.reset.submit') ?></button>
  </form>
</section>

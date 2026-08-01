<section class="auth-panel">
  <div class="auth-copy">
    <p class="kicker"><?= t('auth.forgot.kicker') ?></p>
    <h1><?= t('auth.forgot.title') ?></h1>
    <p><?= t('auth.forgot.description') ?></p>
  </div>
  <form class="form-card form-grid" method="post" action="<?= e(public_language_url($current_language, '/password/forgot')) ?>">
    <?= csrf_field() ?>
    <label class="field"><span><?= t('auth.forgot.email_label') ?></span><input type="email" name="email" required></label>
    <button class="btn-red" type="submit"><?= t('auth.forgot.submit') ?></button>
  </form>
</section>

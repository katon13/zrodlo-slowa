<section class="auth-panel">
  <div class="auth-copy">
    <p class="kicker"><?= t('auth.register.kicker') ?></p>
    <h1><?= t('auth.register.title') ?></h1>
    <p><?= t('auth.register.description') ?></p>
  </div>

  <form class="form-card" method="post" action="<?= e(public_language_url($current_language, '/register')) ?>">
    <?= csrf_field() ?>

    <div class="form-grid two">
      <label class="field">
        <span><?= t('auth.register.name') ?></span>
        <input name="display_name" required autocomplete="name">
      </label>

      <label class="field">
        <span><?= t('auth.login.email') ?></span>
        <input type="email" name="email" required autocomplete="email">
      </label>

      <label class="field">
        <span><?= t('auth.register.phone') ?></span>
        <input name="phone" autocomplete="tel">
      </label>

      <label class="field">
        <span><?= t('auth.login.password') ?></span>
        <input type="password" name="password" minlength="8" required autocomplete="new-password">
      </label>
    </div>

    <div class="form-actions">
      <button class="btn-red" type="submit"><?= t('auth.register.submit') ?></button>
      <a class="text-link" href="/login"><?= t('auth.register.login_link') ?></a>
    </div>

    <?php 
    $oauthConfig = require dirname(__DIR__, 2) . '/config/oauth.php';
    if (($oauthConfig['google']['enabled'] ?? false) || ($oauthConfig['apple']['enabled'] ?? false)): 
    ?>
      <div class="auth-divider"><span><?= t('auth.register.oauth_divider') ?></span></div>
      <div class="oauth-buttons">
        <?php if ($oauthConfig['google']['enabled'] ?? false): ?>
          <a href="/auth/google" class="btn-oauth google">
            <?= zs_icon('google') ?>
            Google
          </a>
        <?php endif; ?>
        <?php if ($oauthConfig['apple']['enabled'] ?? false): ?>
          <a href="/auth/apple" class="btn-oauth apple">
            <?= zs_icon('apple') ?>
            Apple
          </a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </form>
</section>

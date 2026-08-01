<section class="auth-panel">
  <div class="auth-copy">
    <p class="kicker"><?= t('auth.login.kicker') ?></p>
    <h1><?= t('auth.login.title') ?></h1>
    <p><?= t('auth.login.description') ?></p>
  </div>

  <form class="form-card" method="post" action="<?= e(public_language_url($current_language, '/login')) ?>">
    <?= csrf_field() ?>

    <?php if ($m = ($_SESSION['_flash']['success'] ?? null)): unset($_SESSION['_flash']['success']); ?>
      <div class="notice success"><?= e($m) ?></div>
    <?php endif; ?>

    <?php if ($m = ($_SESSION['_flash']['error'] ?? null)): unset($_SESSION['_flash']['error']); ?>
      <div class="notice error"><?= e($m) ?></div>
    <?php endif; ?>

    <div class="form-grid two">
      <label class="field">
        <span><?= t('auth.login.identifier') ?></span>
        <input type="text" name="login" required autocomplete="username">
      </label>

      <label class="field">
        <span><?= t('auth.login.password') ?></span>
        <input type="password" name="password" required autocomplete="current-password">
      </label>
    </div>

    <div class="form-actions">
      <button class="btn-red" type="submit"><?= t('auth.login.submit') ?></button>
      <a class="text-link" href="<?= e(public_language_url($current_language, '/password/forgot')) ?>"><?= t('auth.login.forgot_password') ?></a>
      <a class="text-link" href="<?= e(public_language_url($current_language, '/register')) ?>"><?= t('auth.login.register_link') ?></a>
    </div>

    <?php 
    $oauthConfig = require dirname(__DIR__, 2) . '/config/oauth.php';
    if (($oauthConfig['google']['enabled'] ?? false) || ($oauthConfig['apple']['enabled'] ?? false)): 
    ?>
      <div class="auth-divider"><span><?= t('auth.login.oauth_divider') ?></span></div>
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

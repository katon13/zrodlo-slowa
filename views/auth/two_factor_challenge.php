<section class="auth-panel">
  <div class="auth-copy">
    <p class="kicker"><?= e(t('ui.auth.two_factor_challenge.snajper_sowa_bezpieczne_logowanie')) ?></p>
    <h1><?= e(t('ui.auth.two_factor_challenge.kod_2fa')) ?></h1>
    <p><?= e(t('ui.auth.two_factor_challenge.haso_zostao_przyjete_konto_ma_wysokie_uprawnienia_dlate_fc832592')) ?></p>
    <?php if (!empty($email)): ?>
      <p class="muted"><?= e(t('ui.auth.two_factor_challenge.konto')) ?> <strong><?= e($email) ?></strong></p>
    <?php endif; ?>
  </div>

  <form class="form-card" method="post" action="<?= e(public_language_url($current_language, '/login/2fa')) ?>">
    <?= csrf_field() ?>

    <?php if ($m = ($_SESSION['_flash']['success'] ?? null)): unset($_SESSION['_flash']['success']); ?>
      <div class="notice success"><?= e($m) ?></div>
    <?php endif; ?>

    <?php if ($m = ($_SESSION['_flash']['error'] ?? null)): unset($_SESSION['_flash']['error']); ?>
      <div class="notice error"><?= e($m) ?></div>
    <?php endif; ?>

    <label class="field">
      <span><?= e(t('ui.auth.two_factor_challenge.kod_2fa')) ?></span>
      <input type="text" name="code" required inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" autofocus placeholder="000000">
    </label>

    <div class="notice">
      <?= e(t('ui.auth.two_factor_challenge.ten_krok_nie_aduje_panelu_admina_ani_portfela_pena_sesj_23b06220')) ?>
    </div>

    <div class="form-actions">
      <button class="btn-red" type="submit"><?= e(t('ui.auth.two_factor_challenge.potwierdz_i_zaloguj')) ?></button>
      <a class="text-link" href="/login"><?= e(t('ui.auth.two_factor_challenge.wroc_do_logowania')) ?></a>
    </div>
  </form>
</section>

<section class="auth-panel">
  <div class="auth-copy">
    <p class="kicker">SNAJPER SŁOWA / BEZPIECZNE LOGOWANIE</p>
    <h1>Kod 2FA</h1>
    <p>Hasło zostało przyjęte. Konto ma wysokie uprawnienia, dlatego przed pełnym logowaniem wpisz sześciocyfrowy kod z aplikacji uwierzytelniającej.</p>
    <?php if (!empty($email)): ?>
      <p class="muted">Konto: <strong><?= e($email) ?></strong></p>
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
      <span>Kod 2FA</span>
      <input type="text" name="code" required inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" autofocus placeholder="000000">
    </label>

    <div class="notice">
      Ten krok nie ładuje panelu admina ani portfela. Pełna sesja powstaje dopiero po poprawnym kodzie.
    </div>

    <div class="form-actions">
      <button class="btn-red" type="submit">Potwierdź i zaloguj</button>
      <a class="text-link" href="/login">Wróć do logowania</a>
    </div>
  </form>
</section>

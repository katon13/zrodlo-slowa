<?php
$s = $security ?? [];
$u = $s['user'] ?? [];
$lang = (string)($current_language ?? (function_exists('public_language') ? public_language() : 'pl'));
$backUrl = (($_SESSION['role'] ?? '') === 'admin') ? '/admin' : public_language_url($lang, '/reader');
?>

<section class="admin-page-head zs-operator-page-head">
  <p class="kicker">BEZPIECZEŃSTWO KONTA</p>
  <h1>Bezpieczeństwo konta</h1>
  <p>Potwierdzenie adresu e-mail i logowanie 2FA chronią konto oraz dostęp do funkcji redakcyjnych i finansowych.</p>
</section>

<section class="zs-security-summary" aria-label="Status zabezpieczeń">
  <article class="zs-security-status-card">
    <span>Konto</span>
    <strong><?= e((string)(($u['display_name'] ?? '') ?: ($u['email'] ?? ''))) ?></strong>
    <small><?= e((string)($u['email'] ?? '')) ?></small>
  </article>
  <article class="zs-security-status-card <?= !empty($s['email_verified']) ? 'is-ready' : 'is-warning' ?>">
    <span>Potwierdzony e-mail</span>
    <strong><?= !empty($s['email_verified']) ? 'TAK' : 'BRAK' ?></strong>
  </article>
  <article class="zs-security-status-card <?= !empty($s['two_factor_enabled']) ? 'is-ready' : 'is-warning' ?>">
    <span>Logowanie 2FA</span>
    <strong><?= !empty($s['two_factor_enabled']) ? 'AKTYWNE' : 'NIEAKTYWNE' ?></strong>
  </article>
  <article class="zs-security-status-card <?= !empty($s['ready_for_high_roles']) ? 'is-ready' : 'is-warning' ?>">
    <span>Wysokie role</span>
    <strong><?= !empty($s['ready_for_high_roles']) ? 'DOSTĘPNE' : 'ZABLOKOWANE' ?></strong>
  </article>
</section>

<?php if (!empty($s['missing'])): ?>
  <div class="notice <?= !empty($s['is_system_admin']) ? 'warning' : 'error' ?>">
    <strong><?= !empty($s['is_system_admin']) ? 'Konto administratora wymaga uzupełnienia ochrony.' : 'Dostęp do wysokich ról jest ograniczony.' ?></strong>
    <span>Brakuje: <?= e(implode(', ', (array)$s['missing'])) ?>.</span>
  </div>
<?php endif; ?>

<section class="zs-security-actions">
  <article class="admin-panel-block">
    <div class="admin-section-head">
      <div>
        <p class="kicker">KROK 1</p>
        <h2>Potwierdzenie e-mail</h2>
      </div>
      <span><?= !empty($s['email_verified']) ? 'GOTOWE' : 'WYMAGANE' ?></span>
    </div>
    <p>Na adres konta wyślemy jednorazowy link potwierdzający.</p>
    <form action="<?= e(public_language_url($lang, '/account/security/email')) ?>" method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="_lang" value="<?= e($lang) ?>">
      <button class="zs-btn-outline" type="submit">WYŚLIJ LINK POTWIERDZENIA</button>
    </form>
  </article>

  <article class="admin-panel-block">
    <div class="admin-section-head">
      <div>
        <p class="kicker">KROK 2</p>
        <h2>Logowanie dwuetapowe</h2>
      </div>
      <span><?= !empty($s['two_factor_enabled']) ? 'AKTYWNE' : 'WYMAGANE' ?></span>
    </div>
    <p>Skonfiguruj aplikację Authenticator, aby chronić logowanie dodatkowym kodem.</p>

    <?php if (empty($secret)): ?>
      <form action="<?= e(public_language_url($lang, '/account/security/2fa/start')) ?>" method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="_lang" value="<?= e($lang) ?>">
        <button class="zs-btn-red" type="submit">ROZPOCZNIJ KONFIGURACJĘ 2FA</button>
      </form>
    <?php else: ?>
      <div class="zs-security-secret">
        <span>Sekret do aplikacji Authenticator</span>
        <code class="zs-secret-code"><?= e((string)$secret) ?></code>
      </div>
      <form action="<?= e(public_language_url($lang, '/account/security/2fa/enable')) ?>" method="post" class="zs-security-code-form">
        <?= csrf_field() ?>
        <input type="hidden" name="_lang" value="<?= e($lang) ?>">
        <label class="zs-field">
          <span>Kod sześciocyfrowy</span>
          <input type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" autocomplete="one-time-code" required>
        </label>
        <button class="zs-btn-red" type="submit">AKTYWUJ 2FA</button>
      </form>
    <?php endif; ?>
  </article>
</section>

<div class="zs-panel-footer">
  <a href="<?= e($backUrl) ?>" class="zs-link-aux">Powrót do panelu</a>
  <span class="zs-sep">|</span>
  <a href="<?= e(public_language_url($lang, '/account/settings')) ?>" class="zs-link-aux">Ustawienia konta</a>
</div>

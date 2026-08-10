<?php
$s = $security ?? [];
$u = $s['user'] ?? [];
$lang = (string)($current_language ?? (function_exists('public_language') ? public_language() : 'pl'));
$backUrl = (($_SESSION['role'] ?? '') === 'admin') ? '/admin' : public_language_url($lang, '/reader');
?>

<section class="admin-page-head zs-operator-page-head">
  <p class="kicker"><?= e(t('ui.account.security.bezpieczenstwo_konta')) ?></p>
  <h1><?= e(t('ui.account.security.bezpieczenstwo_konta_2')) ?></h1>
  <p><?= e(t('ui.account.security.potwierdzenie_adresu_e_mail_i_logowanie_2fa_chronia_kon_f76afbd0')) ?></p>
</section>

<section class="zs-security-summary" aria-label="<?= e(t('ui.account.security.status_zabezpieczen')) ?>">
  <article class="zs-security-status-card">
    <span><?= e(t('ui.account.security.konto')) ?></span>
    <strong><?= e((string)(($u['display_name'] ?? '') ?: ($u['email'] ?? ''))) ?></strong>
    <small><?= e((string)($u['email'] ?? '')) ?></small>
  </article>
  <article class="zs-security-status-card <?= !empty($s['email_verified']) ? 'is-ready' : 'is-warning' ?>">
    <span><?= e(t('ui.account.security.potwierdzony_e_mail')) ?></span>
    <strong><?= e(t(!empty($s['email_verified']) ? 'common.yes' : 'common.missing')) ?></strong>
  </article>
  <article class="zs-security-status-card <?= !empty($s['two_factor_enabled']) ? 'is-ready' : 'is-warning' ?>">
    <span><?= e(t('ui.account.security.logowanie_2fa')) ?></span>
    <strong><?= e(t(!empty($s['two_factor_enabled']) ? 'common.active' : 'common.inactive')) ?></strong>
  </article>
  <article class="zs-security-status-card <?= !empty($s['ready_for_high_roles']) ? 'is-ready' : 'is-warning' ?>">
    <span><?= e(t('ui.account.security.wysokie_role')) ?></span>
    <strong><?= e(t(!empty($s['ready_for_high_roles']) ? 'common.available' : 'common.blocked')) ?></strong>
  </article>
</section>

<?php if (!empty($s['missing'])): ?>
  <div class="notice <?= !empty($s['is_system_admin']) ? 'warning' : 'error' ?>">
    <strong><?= e(t(!empty($s['is_system_admin']) ? 'account.security.admin_protection_missing' : 'account.security.high_roles_limited')) ?></strong>
    <span><?= e(t('account.security.missing_prefix')) ?> <?= e(implode(', ', (array)$s['missing'])) ?>.</span>
  </div>
<?php endif; ?>

<section class="zs-security-actions">
  <article class="admin-panel-block">
    <div class="admin-section-head">
      <div>
        <p class="kicker"><?= e(t('ui.account.security.krok_1')) ?></p>
        <h2><?= e(t('ui.account.security.potwierdzenie_e_mail')) ?></h2>
      </div>
      <span><?= e(t(!empty($s['email_verified']) ? 'common.ready' : 'common.required')) ?></span>
    </div>
    <p><?= e(t('ui.account.security.na_adres_konta_wyslemy_jednorazowy_link_potwierdzajacy')) ?></p>
    <form action="<?= e(public_language_url($lang, '/account/security/email')) ?>" method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="_lang" value="<?= e($lang) ?>">
      <button class="zs-btn-outline" type="submit"><?= e(t('ui.account.security.wyslij_link_potwierdzenia')) ?></button>
    </form>
  </article>

  <article class="admin-panel-block">
    <div class="admin-section-head">
      <div>
        <p class="kicker"><?= e(t('ui.account.security.krok_2')) ?></p>
        <h2><?= e(t('ui.account.security.logowanie_dwuetapowe')) ?></h2>
      </div>
      <span><?= e(t(!empty($s['two_factor_enabled']) ? 'common.active' : 'common.required')) ?></span>
    </div>
    <p><?= e(t('ui.account.security.skonfiguruj_aplikacje_authenticator_aby_chronic_logowan_17a49f8f')) ?></p>

    <?php if (empty($secret)): ?>
      <form action="<?= e(public_language_url($lang, '/account/security/2fa/start')) ?>" method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="_lang" value="<?= e($lang) ?>">
        <button class="zs-btn-red" type="submit"><?= e(t('ui.account.security.rozpocznij_konfiguracje_2fa')) ?></button>
      </form>
    <?php else: ?>
      <div class="zs-security-secret">
        <span><?= e(t('ui.account.security.sekret_do_aplikacji_authenticator')) ?></span>
        <code class="zs-secret-code"><?= e((string)$secret) ?></code>
      </div>
      <form action="<?= e(public_language_url($lang, '/account/security/2fa/enable')) ?>" method="post" class="zs-security-code-form">
        <?= csrf_field() ?>
        <input type="hidden" name="_lang" value="<?= e($lang) ?>">
        <label class="zs-field">
          <span><?= e(t('ui.account.security.kod_szesciocyfrowy')) ?></span>
          <input type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" autocomplete="one-time-code" required>
        </label>
        <button class="zs-btn-red" type="submit"><?= e(t('ui.account.security.aktywuj_2fa')) ?></button>
      </form>
    <?php endif; ?>
  </article>
</section>

<div class="zs-panel-footer">
  <a href="<?= e($backUrl) ?>" class="zs-link-aux"><?= e(t('ui.account.security.powrot_do_panelu')) ?></a>
  <span class="zs-sep">|</span>
  <a href="<?= e(public_language_url($lang, '/account/settings')) ?>" class="zs-link-aux"><?= e(t('account.settings.title')) ?></a>
</div>

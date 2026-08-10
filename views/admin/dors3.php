<?php
$settings = is_array($dors3 ?? null) ? $dors3 : [];
$webAuthnStatus = is_array($webauthn ?? null) ? $webauthn : [];
$recoveryStatus = is_array($recovery ?? null) ? $recovery : [];
$readinessItems = is_array($operator_readiness ?? null) ? $operator_readiness : [];
$oneTimeCodes = is_array($new_recovery_codes ?? null) ? $new_recovery_codes : [];
$oneTimeBatch = is_string($new_recovery_batch ?? null) ? $new_recovery_batch : '';
$activeCodes = (int)($recoveryStatus['active'] ?? 0);
$confirmedCodes = (int)($recoveryStatus['confirmed'] ?? 0);
$readinessPassed = count(array_filter($readinessItems, static fn(array $item): bool => !empty($item['passed'])));
$readinessTotal = count($readinessItems);
$readinessPercent = $readinessTotal > 0 ? (int)round(($readinessPassed / $readinessTotal) * 100) : 0;
$mobileConfig = is_array($mobile_config ?? null) ? $mobile_config : [];
$adminDevices = array_values(array_filter($mobile_devices ?? [], static fn(array $device): bool => (string)($device['application_variant'] ?? '') === 'admin'));
$authorDevices = array_values(array_filter($mobile_devices ?? [], static fn(array $device): bool => (string)($device['application_variant'] ?? '') === 'author'));
$activeAdminDevices = count(array_filter($adminDevices, static fn(array $device): bool => (string)($device['status'] ?? '') === 'active'));
$activeAuthorDevices = count(array_filter($authorDevices, static fn(array $device): bool => (string)($device['status'] ?? '') === 'active'));
$accountSecurity = is_array($account_security ?? null) ? $account_security : [];
$accountUser = is_array($accountSecurity['user'] ?? null) ? $accountSecurity['user'] : [];
$roleSummary = is_array($role_summary ?? null) ? $role_summary : [];
$mobileStatusLabel = static fn(string $status): string => \App\Services\Dors3UiText::option('statuses', $status);
$mobileActionLabel = static fn(string $action): string => \App\Services\Dors3UiText::option('operations', $action);
$mobilePolicyLabel = static fn(string $policy): string => \App\Services\Dors3UiText::option('policies', $policy);
?>

<div class="zs-dors3-page">
  <section class="admin-page-head zs-dors3-page-head">
    <p class="kicker"><?= e(dors3_t('panel_general.head_kicker')) ?></p>
    <h1><?= e(dors3_t('panel_general.head_title')) ?></h1>
    <p><?= e(dors3_t('panel_general.head_description')) ?></p>
  </section>

  <nav class="zs-dors3-mobile-state" aria-label="<?= e(dors3_t('panel_general.nav_label')) ?>">
    <a href="#system-protection"><?= e(dors3_t('panel_general.nav_protection')) ?></a><a href="#accounts-roles"><?= e(dors3_t('panel_general.nav_accounts')) ?></a><a href="#mobile-devices"><?= e(dors3_t('panel_general.nav_mobile')) ?></a><a href="#fido2-keys"><?= e(dors3_t('panel_general.nav_fido')) ?></a><a href="#pending-authorizations"><?= e(dors3_t('panel_general.nav_authorizations')) ?></a><a href="#recovery-access"><?= e(dors3_t('panel_general.nav_recovery')) ?></a><a href="/admin/security/sentinel"><?= e(\App\Services\Dors3UiText::get('sentinel.card_title')) ?></a>
  </nav>

  <section class="zs-dors3-summary" id="system-protection" aria-label="<?= e(dors3_t('panel_general.summary_label')) ?>">
    <article class="zs-dors3-summary-card is-ready">
      <?php echo zs_icon('shield'); ?>
      <span><?= e(dors3_t('panel_general.protection_status')) ?></span>
      <strong><?= e(dors3_t('panel_general.working_correctly')) ?></strong>
      <small><?= e(dors3_t('panel_general.operations_protected')) ?></small>
    </article>
    <article class="zs-dors3-summary-card is-ready">
      <?php echo zs_icon('check-circle'); ?>
      <span><?= e(dors3_t('panel_general.important_changes')) ?></span>
      <strong><?= e((string)($operator_confirmation_label ?? dors3_t('common.admin_password'))) ?></strong>
      <small><?= e(dors3_t('panel_general.exact_operation_confirmation')) ?></small>
    </article>
    <article class="zs-dors3-summary-card is-pending">
      <?php echo zs_icon('admin'); ?>
      <span><?= e(dors3_t('panel_general.physical_keys')) ?></span>
      <strong><?= e(dors3_t('panel_general.production_unavailable')) ?></strong>
      <small><?= e(dors3_t('panel_general.fido_foundation_only')) ?></small>
    </article>
    <article class="zs-dors3-summary-card <?= $confirmedCodes === 10 ? 'is-ready' : 'is-warning' ?>">
      <?php echo zs_icon('clipboard'); ?>
      <span><?= e(dors3_t('panel_general.emergency_access')) ?></span>
      <strong><?= e(dors3_t($confirmedCodes === 10 ? 'panel_general.secured' : 'panel_general.requires_preparation')) ?></strong>
      <small><?= e(dors3_t('panel_general.confirmed_codes', ['count' => $confirmedCodes])) ?></small>
    </article>
    <article class="zs-dors3-summary-card <?= $activeAdminDevices > 0 ? 'is-ready' : 'is-pending' ?>"><span><?= e(\App\Services\Dors3UiText::option('app_variants', 'admin')) ?></span><strong><?= e(dors3_t($activeAdminDevices > 0 ? 'panel_general.active_upper' : 'panel_general.no_device_upper')) ?></strong><small><?= e(dors3_t('panel_general.active_devices', ['count' => $activeAdminDevices])) ?></small></article>
    <article class="zs-dors3-summary-card <?= $activeAuthorDevices > 0 ? 'is-ready' : 'is-pending' ?>"><span><?= e(\App\Services\Dors3UiText::option('app_variants', 'author')) ?></span><strong><?= e(dors3_t($activeAuthorDevices > 0 ? 'panel_general.active_upper' : 'panel_general.no_device_upper')) ?></strong><small><?= e(dors3_t('panel_general.active_author_devices', ['count' => $activeAuthorDevices])) ?></small></article>
  </section>

  <section class="zs-dors3-now">
    <div class="zs-dors3-now-icon"><?php echo zs_icon('shield'); ?></div>
    <div>
      <p class="kicker"><?= e(dors3_t('panel_general.protection_now')) ?></p>
      <h2><?= e((string)($operator_mode_label ?? \App\Services\Dors3UiText::option('events.modes', 'prepare'))) ?></h2>
      <p><?= e(dors3_t('panel_general.protection_now_description')) ?></p>
    </div>
    <span class="zs-dors3-state-pill is-active"><?= e(dors3_t('panel_general.active')) ?></span>
  </section>

  <section class="zs-dors3-doors" aria-label="<?= e(dors3_t('panel_general.layers_label')) ?>">
    <article>
      <span class="zs-dors3-door-number">01</span>
      <?php echo zs_icon('login'); ?>
      <h3><?= e(dors3_t('panel_general.safe_entry')) ?></h3>
      <p><?= e(dors3_t('panel_general.safe_entry_description')) ?></p>
      <span class="zs-dors3-state-pill is-active"><?= e(dors3_t('panel_general.works')) ?></span>
    </article>
    <article>
      <span class="zs-dors3-door-number">02</span>
      <?php echo zs_icon('check-circle'); ?>
      <h3><?= e(dors3_t('panel_general.decision_confirmation')) ?></h3>
      <p><?= e(dors3_t('panel_general.decision_confirmation_description')) ?></p>
      <span class="zs-dors3-state-pill is-active"><?= e(dors3_t('panel_general.works')) ?></span>
    </article>
    <article>
      <span class="zs-dors3-door-number">03</span>
      <?php echo zs_icon('history'); ?>
      <h3><?= e(dors3_t('panel_general.history_recovery')) ?></h3>
      <p><?= e(dors3_t('panel_general.history_recovery_description')) ?></p>
      <span class="zs-dors3-state-pill is-active"><?= e(dors3_t('panel_general.works')) ?></span>
    </article>
  </section>

  <section class="admin-panel-block" id="accounts-roles">
    <div class="zs-dors3-section-head"><div><p class="kicker"><?= e(dors3_t('panel_general.accounts_kicker')) ?></p><h2><?= e(dors3_t('panel_general.qualification_title')) ?></h2><p><?= e(dors3_t('panel_general.qualification_description')) ?></p></div></div>
    <div class="zs-dors3-summary">
      <article class="zs-dors3-summary-card"><span><?= e(dors3_t('panel_general.active_accounts')) ?></span><strong><?= (int)($roleSummary['active_accounts'] ?? 0) ?></strong></article>
      <article class="zs-dors3-summary-card"><span><?= e(dors3_t('panel_general.readers_without_dors3')) ?></span><strong><?= (int)($roleSummary['readers'] ?? 0) ?></strong></article>
      <article class="zs-dors3-summary-card"><span><?= e(dors3_t('panel_general.journalists_authors')) ?></span><strong><?= (int)($roleSummary['journalists'] ?? 0) ?></strong></article>
      <article class="zs-dors3-summary-card"><span><?= e(dors3_t('panel_general.administrators')) ?></span><strong><?= (int)($roleSummary['administrators'] ?? 0) ?></strong></article>
      <article class="zs-dors3-summary-card"><span><?= e(dors3_t('panel_general.payout_eligible')) ?></span><strong><?= (int)($roleSummary['payout_enabled'] ?? 0) ?></strong></article>
    </div>
    <div class="zs-security-summary" aria-label="<?= e(dors3_t('panel_general.current_account_protection')) ?>">
      <article class="zs-security-status-card"><span><?= e(dors3_t('panel_general.administrator_account')) ?></span><strong><?= e((string)(($accountUser['display_name'] ?? '') ?: ($accountUser['email'] ?? ''))) ?></strong><small><?= e((string)($accountUser['email'] ?? '')) ?></small></article>
      <article class="zs-security-status-card <?= !empty($accountSecurity['email_verified']) ? 'is-ready' : 'is-warning' ?>"><span><?= e(dors3_t('panel_general.verified_email')) ?></span><strong><?= e(dors3_t(!empty($accountSecurity['email_verified']) ? 'panel_general.yes' : 'panel_general.missing_upper')) ?></strong></article>
      <article class="zs-security-status-card <?= !empty($accountSecurity['two_factor_enabled']) ? 'is-ready' : 'is-warning' ?>"><span><?= e(dors3_t('panel_general.two_factor_login')) ?></span><strong><?= e(dors3_t(!empty($accountSecurity['two_factor_enabled']) ? 'panel_general.enabled_upper' : 'panel_general.disabled_upper')) ?></strong></article>
      <article class="zs-security-status-card <?= !empty($accountSecurity['ready_for_high_roles']) ? 'is-ready' : 'is-warning' ?>"><span><?= e(dors3_t('panel_general.high_roles')) ?></span><strong><?= e(dors3_t(!empty($accountSecurity['ready_for_high_roles']) ? 'panel_general.available_upper' : 'panel_general.blocked_upper')) ?></strong></article>
    </div>
    <div class="zs-security-actions">
      <article class="admin-panel-block"><h3><?= e(dors3_t('panel_general.email_confirmation')) ?></h3><p><?= e(dors3_t('panel_general.email_confirmation_description')) ?></p><form action="/account/security/email" method="post"><?= csrf_field() ?><button class="zs-btn-outline" type="submit"><?= e(dors3_t('panel_general.send_confirmation_link')) ?></button></form></article>
      <article class="admin-panel-block"><h3><?= e(dors3_t('panel_general.two_factor')) ?></h3><p><?= e(dors3_t('panel_general.two_factor_description')) ?></p><?php if (empty($account_security_secret)): ?><form action="/account/security/2fa/start" method="post"><?= csrf_field() ?><button class="zs-btn-red" type="submit"><?= e(dors3_t('panel_general.start_two_factor')) ?></button></form><?php else: ?><div class="zs-security-secret"><span><?= e(dors3_t('panel_general.authenticator_secret')) ?></span><code class="zs-secret-code"><?= e((string)$account_security_secret) ?></code></div><form action="/account/security/2fa/enable" method="post" class="zs-security-code-form"><?= csrf_field() ?><label class="zs-field"><span><?= e(dors3_t('panel_general.six_digit_auth_code')) ?></span><input type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" autocomplete="one-time-code" required></label><button class="zs-btn-red" type="submit"><?= e(dors3_t('panel_general.activate_two_factor')) ?></button></form><?php endif; ?></article>
    </div>
  </section>

  <?php if ($oneTimeCodes !== []): ?>
  <section class="zs-dors3-one-time" id="recovery-codes-once">
    <div class="zs-dors3-section-head">
      <div>
        <p class="kicker"><?= e(dors3_t('panel_general.show_once')) ?></p>
        <h2><?= e(dors3_t('panel_general.save_ten_codes')) ?></h2>
        <p><?= e(dors3_t('panel_general.save_codes_description')) ?></p>
      </div>
      <?php echo zs_icon('warning'); ?>
    </div>
    <ol class="dors3-recovery-codes">
      <?php foreach ($oneTimeCodes as $code): ?><li><?= e((string)$code) ?></li><?php endforeach; ?>
    </ol>
    <div class="zs-dors3-one-time-actions">
      <button class="btn btn-secondary" type="button" onclick="window.print()"><?= e(dors3_t('panel_general.print_codes')) ?></button>
      <form method="post" action="/admin/security/3dors/recovery/confirm" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="batch_public_id" value="<?= e($oneTimeBatch) ?>">
        <label class="zs-dors3-confirm-check"><input type="checkbox" name="saved_confirmation" value="yes" required> <span><?= e(dors3_t('panel_general.codes_saved_offline')) ?></span></label>
        <label for="confirm-recovery-password"><span><?= e(dors3_t('common.admin_password')) ?></span><input id="confirm-recovery-password" type="password" name="critical_password" required autocomplete="current-password"></label>
        <button class="btn btn-primary" type="submit"><?= e(dors3_t('panel_general.confirm_safe_storage')) ?></button>
      </form>
    </div>
  </section>
  <?php endif; ?>

  <section class="zs-dors3-workspace" id="recovery-access">
    <article class="admin-panel-block zs-dors3-recovery-panel">
      <div class="zs-dors3-panel-icon"><?php echo zs_icon('clipboard'); ?></div>
      <p class="kicker"><?= e(dors3_t('panel_general.emergency_kicker')) ?></p>
      <h2><?= e(dors3_t('panel_general.emergency_codes')) ?></h2>
      <p><?= e(dors3_t('panel_general.new_codes_description')) ?></p>
      <div class="zs-dors3-code-status">
        <div><strong><?= $activeCodes ?></strong><span><?= e(dors3_t('panel_general.active_count')) ?></span></div>
        <div><strong><?= $confirmedCodes ?></strong><span><?= e(dors3_t('panel_general.confirmed_count')) ?></span></div>
      </div>
      <form method="post" action="/admin/security/3dors/recovery/generate" autocomplete="off" class="zs-dors3-recovery-form">
        <?= csrf_field() ?>
        <label for="generate-recovery-password"><span><?= e(dors3_t('common.admin_password')) ?></span><input id="generate-recovery-password" type="password" name="critical_password" required autocomplete="current-password"></label>
        <button class="btn btn-primary" type="submit"><?= e(dors3_t('panel_general.create_ten_codes')) ?></button>
      </form>
      <small class="zs-dors3-caution"><?= e(dors3_t('panel_general.codes_once_warning')) ?></small>
    </article>

    <article class="admin-panel-block zs-dors3-alarm-panel">
      <div class="zs-dors3-panel-icon"><?php echo zs_icon('shield'); ?></div>
      <p class="kicker"><?= e(\App\Services\Dors3UiText::get('sentinel.kicker')) ?></p>
      <h2><?= e(\App\Services\Dors3UiText::get('sentinel.card_title')) ?></h2>
      <p><?= e(\App\Services\Dors3UiText::get('sentinel.card_description')) ?></p>
      <a class="btn btn-secondary" href="/admin/security/sentinel"><?= e(\App\Services\Dors3UiText::get('sentinel.open_panel')) ?></a>
    </article>
  </section>

  <section class="admin-panel-block zs-dors3-readiness">
    <div class="zs-dors3-section-head">
      <div>
        <p class="kicker"><?= e(dors3_t('panel_general.keys_plan')) ?></p>
        <h2><?= e(dors3_t('panel_general.keys_readiness')) ?></h2>
        <p><?= e(dors3_t('panel_general.keys_readiness_description')) ?></p>
      </div>
      <div class="zs-dors3-progress-number"><strong><?= $readinessPassed ?>/<?= $readinessTotal ?></strong><span><?= e(dors3_t('panel_general.conditions_ready')) ?></span></div>
    </div>
    <progress class="zs-dors3-progress" aria-label="<?= e(dors3_t('panel_general.readiness_percent', ['percent' => $readinessPercent])) ?>" max="100" value="<?= $readinessPercent ?>"><?= $readinessPercent ?>%</progress>
    <div class="zs-dors3-checklist">
      <?php foreach ($readinessItems as $item): ?>
        <div class="<?= !empty($item['passed']) ? 'is-complete' : '' ?>">
          <?php echo zs_icon(!empty($item['passed']) ? 'check-circle' : 'plus-circle'); ?>
          <span><?= e((string)$item['label']) ?></span>
          <strong><?= e(dors3_t(!empty($item['passed']) ? 'panel_general.ready' : 'panel_general.to_do')) ?></strong>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="zs-dors3-readiness-note">
      <?php echo zs_icon('warning'); ?>
      <p><?= e(dors3_t('panel_general.keys_readiness_note')) ?></p>
    </div>
  </section>

  <section class="zs-dors3-workspace">
    <article class="admin-panel-block" id="fido2-keys">
      <div class="zs-dors3-section-head is-compact">
        <div><p class="kicker"><?= e(dors3_t('panel_general.physical_security')) ?></p><h2><?= e(dors3_t('panel_general.administrator_keys')) ?></h2></div>
        <strong class="zs-dors3-count"><?= count($credentials ?? []) ?></strong>
      </div>
      <?php if (empty($credentials)): ?>
        <div class="zs-dors3-empty">
          <?php echo zs_icon('admin'); ?>
          <strong><?= e(dors3_t('panel_general.keys_not_bought')) ?></strong>
          <p><?= e(dors3_t('panel_general.keys_not_bought_description')) ?></p>
        </div>
      <?php else: ?>
        <div class="zs-dors3-key-list">
          <?php foreach ($credentials as $credential): ?>
            <div>
              <?php echo zs_icon('admin'); ?>
              <span><strong><?= e((string)$credential['display_name']) ?></strong><small><?= e((string)$credential['role_label']) ?> · <?= e((string)$credential['status_label']) ?></small></span>
              <span class="zs-dors3-state-pill <?= !empty($credential['tested_at']) ? 'is-active' : 'is-pending' ?>"><?= e(dors3_t(!empty($credential['tested_at']) ? 'panel_general.tested' : 'panel_general.waiting_test')) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </article>
  </section>

  <section class="admin-panel-block zs-dors3-mobile" id="mobile-devices">
    <div class="zs-dors3-section-head">
      <div>
        <p class="kicker"><?= e(dors3_t('panel.mobile_kicker')) ?></p>
        <h2><?= e(dors3_t('panel.mobile_title')) ?></h2>
        <p><?= e(dors3_t('panel.mobile_description')) ?></p>
      </div>
      <strong class="zs-dors3-count"><?= count($mobile_devices ?? []) ?></strong>
    </div>

    <div class="zs-dors3-mobile-state" role="status">
      <span class="zs-dors3-state-pill <?= !empty($mobileConfig['enabled']) ? 'is-active' : 'is-pending' ?>"><?= e(dors3_t(!empty($mobileConfig['enabled']) ? 'panel.module_enabled' : 'panel.module_disabled')) ?></span>
      <span><?= e(dors3_t('panel.mode')) ?>: <strong><?= e(\App\Services\Dors3UiText::option('events.modes', (string)($mobileConfig['mode'] ?? 'disabled'))) ?></strong></span>
      <span><?= e(\App\Services\Dors3UiText::option('app_variants', 'admin')) ?>: <strong><?= e(dors3_t(!empty($mobileConfig['admin_app_enabled']) ? 'panel.available' : 'panel.disabled')) ?></strong></span>
      <span><?= e(\App\Services\Dors3UiText::option('app_variants', 'author')) ?>: <strong><?= e(dors3_t(!empty($mobileConfig['author_app_enabled']) ? 'panel.available' : 'panel.disabled')) ?></strong></span>
      <span><?= e(dors3_t('panel.enforcement')) ?>: <strong><?= e(dors3_t((string)($mobileConfig['mode'] ?? '') === 'required' ? 'panel.enforcement_active' : 'panel.enforcement_test')) ?></strong></span>
    </div>

    <form method="get" class="zs-dors3-mobile-search" role="search">
      <label for="mobile-user-query"><?= e(dors3_t('panel.search_label')) ?></label>
      <div><input id="mobile-user-query" name="mobile_user_query" value="<?= e((string)($mobile_user_query ?? '')) ?>" maxlength="120" placeholder="<?= e(dors3_t('panel.search_placeholder')) ?>"><button type="submit"><?= e(dors3_t('panel.search')) ?></button></div>
    </form>

    <div class="zs-dors3-mobile-variants">
      <?php foreach ([
        ['variant' => 'admin', 'title' => \App\Services\Dors3UiText::option('app_variants', 'admin'), 'description' => dors3_t('panel.admin_description'), 'candidates' => $mobile_admin_candidates ?? [], 'devices' => $adminDevices],
        ['variant' => 'author', 'title' => \App\Services\Dors3UiText::option('app_variants', 'author'), 'description' => dors3_t('panel.author_description'), 'candidates' => $mobile_author_candidates ?? [], 'devices' => $authorDevices],
      ] as $mobileVariant): ?>
        <article class="zs-dors3-mobile-variant is-<?= e((string)$mobileVariant['variant']) ?>">
          <header><div><p class="kicker"><?= e(strtoupper((string)$mobileVariant['variant'])) ?></p><h3><?= e((string)$mobileVariant['title']) ?></h3><p><?= e((string)$mobileVariant['description']) ?></p></div><strong><?= count($mobileVariant['devices']) ?></strong></header>
          <form method="post" action="/admin/security/mobile/enrollment/start" autocomplete="off" class="zs-dors3-mobile-enroll">
            <?= csrf_field() ?>
            <input type="hidden" name="application_variant" value="<?= e((string)$mobileVariant['variant']) ?>">
            <label><span><?= e(dors3_t('panel.eligible_account')) ?></span><select name="user_id" required><option value=""><?= e(dors3_t('panel.choose_person')) ?></option><?php foreach ($mobileVariant['candidates'] as $candidate): ?><option value="<?= (int)$candidate['id'] ?>"><?= e((string)$candidate['display_name']) ?> — <?= e((string)($candidate['login_name'] ?: $candidate['email'])) ?><?= !empty($candidate['agreement_public_id']) ? ' · ' . e(dors3_t('panel.active_eligibility', ['id' => substr((string)$candidate['agreement_public_id'], 0, 8)])) . ((string)($candidate['terms_version'] ?? '') === 'legacy-v1' ? ' (' . e(dors3_t('panel.legacy_eligibility')) . ')' : '') : '' ?></option><?php endforeach; ?></select></label>
            <label><span><?= e(dors3_t('common.admin_password')) ?></span><input type="password" name="current_password" required autocomplete="current-password"></label>
            <button type="submit" <?= empty($mobileVariant['candidates']) ? 'disabled' : '' ?>><?= e(dors3_t('panel.create_enrollment')) ?></button>
            <?php if (empty($mobileVariant['candidates'])): ?><small><?= e(dors3_t('panel.no_eligible_accounts')) ?></small><?php endif; ?>
          </form>
        </article>
      <?php endforeach; ?>
    </div>

    <?php if (!empty($new_mobile_enrollment)): ?>
      <aside class="zs-dors3-one-time zs-dors3-mobile-one-time" aria-live="polite">
        <div><p class="kicker"><?= e(dors3_t('panel.one_time')) ?></p><h3><?= e(dors3_t('panel.one_time_title')) ?></h3><strong><?= e(dors3_t('panel.comparison_code', ['code' => (string)$new_mobile_enrollment['comparison_code']])) ?></strong><p><?= e(dors3_t('panel.one_time_description')) ?></p></div>
        <?php if (!empty($new_mobile_enrollment['qr_data_uri'])): ?><img src="<?= e((string)$new_mobile_enrollment['qr_data_uri']) ?>" width="360" height="360" alt="<?= e(dors3_t('panel.qr_alt')) ?>"><?php else: ?><p><strong><?= e(dors3_t('panel.qr_unavailable')) ?></strong></p><?php endif; ?>
        <details><summary><?= e(dors3_t('panel.emergency_data')) ?></summary><textarea readonly rows="8" aria-label="<?= e(dors3_t('panel.enrollment_payload')) ?>"><?= e(json_encode($new_mobile_enrollment['qr_payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></textarea></details>
      </aside>
    <?php endif; ?>

    <div class="zs-dors3-subsection">
      <div class="zs-dors3-section-head is-compact"><div><p class="kicker"><?= e(dors3_t('panel.registrations')) ?></p><h3><?= e(dors3_t('panel.pending_connections')) ?></h3></div><strong class="zs-dors3-count"><?= count($mobile_enrollments ?? []) ?></strong></div>
      <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th><?= e(dors3_t('panel.person')) ?></th><th><?= e(dors3_t('panel.application')) ?></th><th><?= e(dors3_t('panel.stage')) ?></th><th><?= e(dors3_t('panel.remaining')) ?></th><th><?= e(dors3_t('panel.device')) ?></th><th><?= e(dors3_t('panel.action')) ?></th></tr></thead><tbody>
      <?php foreach (($mobile_enrollments ?? []) as $enrollment): ?><tr><td><strong><?= e((string)$enrollment['owner_name']) ?></strong><small><?= e((string)$enrollment['email']) ?></small></td><td><?= e(\App\Services\Dors3UiText::option('app_variants', (string)$enrollment['application_variant'])) ?></td><td><?= e($mobileStatusLabel((string)$enrollment['status'])) ?><small><?= e(dors3_t((string)$enrollment['status'] === 'completed' ? 'panel.phone_ready' : 'panel.waiting_phone')) ?></small></td><td><?= e(dors3_t('common.seconds', ['count' => max(0, (int)$enrollment['ttl_seconds'])])) ?></td><td><?= !empty($enrollment['device_name']) ? e((string)$enrollment['device_name']) : e(dors3_t('panel.not_connected')) ?></td><td><div class="zs-dors3-device-actions"><?php if ((string)$enrollment['status'] === 'completed'): ?><form method="post" action="<?= e('/admin/security/mobile/enrollments/' . (string)$enrollment['public_id'] . '/approve') ?>" class="zs-dors3-inline-action"><?= csrf_field() ?><input type="text" name="comparison_code" required inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" placeholder="<?= e(dors3_t('panel.six_digit_code')) ?>"><input type="password" name="critical_password" required placeholder="<?= e(dors3_t('common.admin_password')) ?>" autocomplete="current-password"><button type="submit"><?= e(dors3_t('panel.activate')) ?></button></form><?php endif; ?><form method="post" action="<?= e('/admin/security/mobile/enrollments/' . (string)$enrollment['public_id'] . '/cancel') ?>" class="zs-dors3-inline-action"><?= csrf_field() ?><input type="hidden" name="reason" value="operator_cancelled_pending_enrollment"><input type="password" name="critical_password" required placeholder="<?= e(dors3_t('common.admin_password')) ?>" autocomplete="current-password"><button type="submit"><?= e(dors3_t('panel.cancel')) ?></button></form></div></td></tr><?php endforeach; ?>
      <?php if (empty($mobile_enrollments)): ?><tr><td colspan="6"><?= e(dors3_t('panel.no_pending_registrations')) ?></td></tr><?php endif; ?>
      </tbody></table></div>
    </div>

    <?php foreach ([['title' => dors3_t('panel.admin_devices'), 'devices' => $adminDevices], ['title' => dors3_t('panel.author_devices'), 'devices' => $authorDevices]] as $deviceGroup): ?>
      <div class="zs-dors3-subsection">
        <div class="zs-dors3-section-head is-compact"><div><h3><?= e((string)$deviceGroup['title']) ?></h3></div><strong class="zs-dors3-count"><?= count($deviceGroup['devices']) ?></strong></div>
        <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th><?= e(dors3_t('panel.owner')) ?></th><th><?= e(dors3_t('panel.phone_application')) ?></th><th><?= e(dors3_t('panel.protection')) ?></th><th><?= e(dors3_t('panel.activity')) ?></th><th><?= e(dors3_t('panel.status')) ?></th><th><?= e(dors3_t('panel.lifecycle_actions')) ?></th></tr></thead><tbody>
        <?php foreach ($deviceGroup['devices'] as $device): ?>
          <?php $status = (string)$device['status']; $actions = $status === 'active' ? ['suspend' => dors3_t('panel.suspend'), 'mark-lost' => dors3_t('panel.mark_lost'), 'revoke' => dors3_t('panel.revoke')] : ($status === 'suspended' ? ['resume' => dors3_t('panel.resume'), 'mark-lost' => dors3_t('panel.mark_lost'), 'revoke' => dors3_t('panel.revoke')] : ($status === 'pending' ? ['mark-lost' => dors3_t('panel.mark_lost'), 'revoke' => dors3_t('panel.revoke')] : [])); ?>
          <tr><td><strong><?= e((string)$device['owner_name']) ?></strong><small><?= e((string)$device['email']) ?><?= !empty($device['agreement_id']) ? ' · ' . e(dors3_t('panel.author_eligibility_number', ['id' => (int)$device['agreement_id']])) : '' ?></small></td><td><?= e((string)$device['device_model']) ?><small><?= e((string)$device['os_version']) ?> · <?= e(dors3_t('panel.application_version', ['version' => (string)$device['app_version']])) ?></small></td><td><?= e(dors3_t('panel.security_level', ['level' => (string)$device['security_level']])) ?><small><?= e(dors3_t(!empty($device['attestation_verified']) ? 'panel.attestation_verified' : 'panel.attestation_unverified')) ?> · <?= e(dors3_t('panel.credential_status', ['status' => $mobileStatusLabel((string)($device['credential_status'] ?? ''))])) ?></small></td><td><?= !empty($device['last_used_at']) ? e((string)$device['last_used_at']) : e(dors3_t('panel.never_used')) ?><small><?= e(dors3_t('panel.last_signature')) ?>: <?= !empty($device['last_signature_at']) ? e((string)$device['last_signature_at']) : e(dors3_t('panel.none')) ?></small></td><td><span class="zs-dors3-state-pill <?= $status === 'active' ? 'is-active' : ($status === 'pending' || $status === 'suspended' ? 'is-pending' : 'is-danger') ?>"><?= e($mobileStatusLabel($status)) ?></span></td><td><div class="zs-dors3-device-actions"><?php foreach ($actions as $action => $label): ?><form method="post" action="<?= e('/admin/security/mobile/devices/' . (string)$device['public_id'] . '/' . $action) ?>" class="zs-dors3-inline-action"><?= csrf_field() ?><input type="hidden" name="reason" value="operator_panel_decision"><input type="password" name="critical_password" required placeholder="<?= e(dors3_t('common.admin_password')) ?>" autocomplete="current-password"><button type="submit"><?= e($label) ?></button></form><?php endforeach; ?><?php if ($actions === []): ?><small><?= e(dors3_t('panel.terminal_state')) ?></small><?php endif; ?></div></td></tr>
        <?php endforeach; ?>
        <?php if (empty($deviceGroup['devices'])): ?><tr><td colspan="6"><?= e(dors3_t('panel.no_devices')) ?></td></tr><?php endif; ?>
        </tbody></table></div>
      </div>
    <?php endforeach; ?>
  </section>

  <section class="admin-panel-block zs-dors3-events" id="pending-authorizations">
    <div class="zs-dors3-section-head"><div><p class="kicker"><?= e(dors3_t('panel.decisions_kicker')) ?></p><h2><?= e(dors3_t('panel.pending_authorizations')) ?></h2><p><?= e(dors3_t('panel.pending_description')) ?></p></div><strong class="zs-dors3-count"><?= count($mobile_pending ?? []) ?></strong></div>
    <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th><?= e(dors3_t('panel.operation')) ?></th><th><?= e(dors3_t('panel.person')) ?></th><th><?= e(dors3_t('panel.application')) ?></th><th><?= e(dors3_t('panel.remaining')) ?></th><th><?= e(dors3_t('panel.environment')) ?></th><th><?= e(dors3_t('panel.policy')) ?></th></tr></thead><tbody>
    <?php foreach (($mobile_pending ?? []) as $request): ?><tr><td><strong><?= e($mobileActionLabel((string)$request['action_type'])) ?></strong><small><?= e(\App\Services\Dors3UiText::option('resources', (string)$request['resource_type'])) ?> #<?= e((string)$request['resource_id']) ?> · <?= e((string)$request['request_id']) ?></small></td><td><?= e((string)$request['owner_name']) ?></td><td><?= e(\App\Services\Dors3UiText::option('app_variants', (string)$request['application_variant'])) ?></td><td><?= e(dors3_t('common.seconds', ['count' => max(0, (int)$request['ttl_seconds'])])) ?></td><td><?= e((string)$request['environment']) ?></td><td><?= e($mobilePolicyLabel((string)($request['policy'] ?? 'mobile'))) ?> · <?= e(dors3_t(!empty($request['enforced']) ? 'panel.required' : 'panel.test')) ?></td></tr><?php endforeach; ?>
    <?php if (empty($mobile_pending)): ?><tr><td colspan="6"><?= e(dors3_t('panel.no_pending_decisions')) ?></td></tr><?php endif; ?>
    </tbody></table></div>
  </section>

  <details class="zs-dors3-diagnostics">
    <summary><span><?= e(dors3_t('panel_general.diagnostics')) ?></span><small><?= e(dors3_t('panel_general.diagnostics_description')) ?></small></summary>
    <div class="zs-dors3-diagnostic-grid">
      <div><span><?= e(dors3_t('panel_general.system_mode')) ?></span><code><?= e(\App\Services\Dors3UiText::option('events.modes', (string)($settings['mode'] ?? 'prepare'))) ?></code></div>
      <div><span><?= e(dors3_t('panel_general.confirmation_method')) ?></span><code><?= e(\App\Services\Dors3UiText::option('events.confirmations', (string)($settings['critical_step_up'] ?? 'password'))) ?></code></div>
      <div><span><?= e(dors3_t('panel_general.full_fido_authorization')) ?></span><code><?= e(\App\Services\Dors3UiText::option('events.technical', !empty($webAuthnStatus['authorization_ready']) ? 'ready' : 'unavailable')) ?></code></div>
      <div><span><?= e(dors3_t('panel_general.webauthn_foundation')) ?></span><code><?= e(\App\Services\Dors3UiText::option('events.technical', !empty($webAuthnStatus['library_ready']) ? 'present' : 'missing')) ?></code></div>
      <div><span><?= e(dors3_t('panel_general.fido_attestation')) ?></span><code><?= e(\App\Services\Dors3UiText::option('events.technical', !empty($webAuthnStatus['attestation_ready']) ? 'ready' : 'unavailable')) ?></code></div>
      <div><span><?= e(dors3_t('panel_general.application_address')) ?></span><code><?= e((string)($webAuthnStatus['origin'] ?? '')) ?></code></div>
      <div><span><?= e(dors3_t('panel_general.key_domain')) ?></span><code><?= e((string)($webAuthnStatus['rp_id'] ?? '')) ?></code></div>
      <div><span><?= e(dors3_t('panel_general.user_verification')) ?></span><code><?= e(\App\Services\Dors3UiText::option('events.technical', (string)($webAuthnStatus['user_verification'] ?? 'required'))) ?></code></div>
      <div><span><?= e(dors3_t('panel_general.maximum_session_time')) ?></span><code><?= e(dors3_t('common.seconds', ['count' => (int)($settings['admin_session_max_seconds'] ?? 0)])) ?></code></div>
    </div>
  </details>
</div>

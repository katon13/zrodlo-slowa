<div class="zs-roles-page">

<section class="admin-page-head">
  <p class="kicker"><?= e(t('admin.dashboard.snajper_sowa')) ?></p>
  <h1><?= e(t('admin.role_panel.role_i_uprawnienia')) ?></h1>
  <p><?= e(t('admin.roles.etap_3_role_redakcyjne_plus_wymog_potwierdzonego_e_mail_a1bc7b78')) ?></p>
</section>

<section class="admin-section">
  <h2><?= e(t('admin.roles.kafelki_rol')) ?></h2>
  <div class="admin-grid">
    <?php foreach (($panels ?? []) as $code => $panel): ?>
      <a class="zs-admin-card" href="<?php echo e($panel['route']); ?>">
        <?php echo zs_icon($panel['target'] === 'payouts' ? 'wallet' : 'article', 'zs-icon'); ?>
        <strong><?php echo e($panel['title']); ?></strong>
        <small><?php echo e($panel['description']); ?></small>
      </a>
    <?php endforeach; ?>
  </div>
</section>

<section class="admin-section">
  <h2><?= e(t('admin.roles.definicje_rol_redakcyjnych')) ?></h2>
  <table class="zs-admin-table">
    <thead>
      <tr>
        <th><?= e(t('admin.roles.rola')) ?></th>
        <th><?= e(t('admin.roles.kafelek')) ?></th>
        <th><?= e(t('safety_fund.admin.resource')) ?></th>
        <th><?= e(t('admin.roles.bezpieczenstwo')) ?></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach (($roles ?? []) as $code => $role): ?>
        <tr>
          <td>
            <div class="zs-user-info">
              <strong class="zs-user-name"><?php echo e($role['label']); ?></strong>
              <small class="zs-user-email"><?= e(t('admin.roles.key_prefix')) ?> <?php echo e($code); ?></small>
            </div>
          </td>
          <td><strong><?php echo e($role['tile']); ?></strong></td>
          <td><?php echo e($role['description']); ?></td>
          <td>
            <div class="zs-security-stack">
              <?php if (!empty($role['requires_verified_email'])): ?>
                <span class="zs-security-badge is-active"><?= e(t('admin.roles.e_mail_wymagany')) ?></span>
              <?php endif; ?>
              <?php if (!empty($role['requires_2fa'])): ?>
                <span class="zs-security-badge is-active"><?= e(t('admin.roles.2fa_wymagane')) ?></span>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>

<section class="admin-section">
  <h2><?= e(t('admin.roles.przydzia_rol_uzytkownikom')) ?></h2>
  <p class="editorial-note"><?= e(t('admin.roles.wysokie_role_operacyjne_sa_przypisane_administracyjnie_800641fa')) ?></p>

  <table class="zs-admin-table">
    <thead>
      <tr>
        <th><?= e(t('wallet.orders.table.user')) ?></th>
        <th><?= e(t('admin.roles.bezpieczenstwo')) ?></th>
        <th><?= e(t('admin.roles.role_redakcyjne')) ?></th>
        <th><?= e(t('admin.anti_fraud.akcja')) ?></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach (($users ?? []) as $user): ?>
        <?php $userRoles = array_filter(array_map('trim', explode(',', (string)($user['roles'] ?? '')))); ?>
        <tr id="user-<?php echo (int)$user['id']; ?>">
          <td style="width: 200px;">
            <div class="zs-user-info">
              <strong class="zs-user-name"><?php echo e($user['display_name'] ?: str_replace('{id}', (string)$user['id'], t('admin.roles.user_number'))); ?></strong>
              <small class="zs-user-email"><?php echo e($user['email']); ?></small>
              <small class="zs-user-email"><?= e(t('admin.roles.status_prefix')) ?> <?php echo e($user['status']); ?></small>
            </div>
          </td>
          <td style="width: 200px;">
            <div class="zs-security-stack">
              <?php if (!empty($user['email_verified_at'])): ?>
                <span class="zs-security-badge is-active"><?= e(t('admin.roles.e_mail_potwierdzony')) ?></span>
              <?php else: ?>
                <span class="zs-security-badge is-missing"><?= e(t('admin.roles.e_mail_niepotwierdzony')) ?></span>
              <?php endif; ?>

              <?php if (!empty($user['two_factor_enabled'])): ?>
                <span class="zs-security-badge is-active"><?= e(t('admin.roles.2fa_aktywne')) ?></span>
              <?php else: ?>
                <span class="zs-security-badge is-missing"><?= e(t('admin.roles.2fa_nieaktywne')) ?></span>
              <?php endif; ?>

              <?php if (!empty($user['force_2fa_setup'])): ?>
                <span class="zs-status-badge failed" style="margin-top:4px;"><?= e(t('admin.roles.wymuszone_2fa')) ?></span>
              <?php endif; ?>
            </div>
          </td>
          <td>
            <form action="/admin/roles/editorial" method="POST" id="form-user-<?php echo (int)$user['id']; ?>">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="user_id" value="<?php echo (int)$user['id']; ?>">
              <label class="zs-role-checkbox"><span><?= e(t('admin.roles.haso_3dors')) ?></span><input type="password" name="critical_password" required autocomplete="current-password"></label>
              <div class="zs-role-grid">
                <?php foreach (($roles ?? []) as $code => $role): ?>
                  <label class="zs-role-checkbox">
                    <input type="checkbox" name="roles[]" value="<?php echo e($code); ?>" <?php echo in_array($code, $userRoles, true) ? 'checked' : ''; ?>>
                    <?php echo e($role['label']); ?>
                  </label>
                <?php endforeach; ?>
              </div>
            </form>
          </td>
          <td style="width: 160px; text-align: right;">
            <button type="submit" form="form-user-<?php echo (int)$user['id']; ?>" class="btn btn-small btn-save-role"><?= e(t('admin.roles.zapisz_role')) ?></button>
            <?php if (!empty($user['two_factor_enabled'])): ?>
              <form action="/admin/roles/disable-2fa" method="POST" style="margin-top:8px;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="user_id" value="<?php echo (int)$user['id']; ?>">
                <input type="password" name="critical_password" placeholder="<?= e(t('admin.roles.haso_3dors')) ?>" required autocomplete="current-password" style="width:100%;margin-bottom:6px;">
                <button type="submit" class="btn btn-small btn-secondary" style="width: 100%;"><?= e(t('admin.roles.reset_2fa')) ?></button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="admin-actions editorial-note">
    <?php $prev = max(1, (int)($snajper_page ?? 1) - 1); $next = (int)($snajper_page ?? 1) + 1; ?>
    <a class="btn btn-secondary" href="/admin/roles?page=<?php echo $prev; ?>"><?= e(t('admin.roles.poprzednia_strona')) ?></a>
    <span><?php echo e(str_replace(['{page}','{limit}'], [(string)(int)($snajper_page ?? 1),(string)(int)($snajper_limit ?? 50)], t('admin.common.page_and_limit'))); ?></span>
    <a class="btn btn-secondary" href="/admin/roles?page=<?php echo $next; ?>"><?= e(t('admin.roles.nastepna_strona')) ?></a>
  </div>
</section>

<div class="admin-actions editorial-note" style="margin-top: 32px;">
  <a href="/admin" class="btn btn-secondary"><?= e(t('admin.role_panel.powrot_do_dashboardu')) ?></a>
</div>

</div><!-- .zs-roles-page -->

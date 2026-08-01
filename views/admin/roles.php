<div class="zs-roles-page">

<section class="admin-page-head">
  <p class="kicker">SNAJPER SŁOWA</p>
  <h1>Role i uprawnienia</h1>
  <p>Etap 3: role redakcyjne plus wymóg potwierdzonego e-maila i 2FA dla wysokich kafelków.</p>
</section>

<section class="admin-section">
  <h2>Kafelki ról</h2>
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
  <h2>Definicje ról redakcyjnych</h2>
  <table class="zs-admin-table">
    <thead>
      <tr>
        <th>Rola</th>
        <th>Kafelek</th>
        <th>Zakres</th>
        <th>Bezpieczeństwo</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach (($roles ?? []) as $code => $role): ?>
        <tr>
          <td>
            <div class="zs-user-info">
              <strong class="zs-user-name"><?php echo e($role['label']); ?></strong>
              <small class="zs-user-email">klucz: <?php echo e($code); ?></small>
            </div>
          </td>
          <td><strong><?php echo e($role['tile']); ?></strong></td>
          <td><?php echo e($role['description']); ?></td>
          <td>
            <div class="zs-security-stack">
              <?php if (!empty($role['requires_verified_email'])): ?>
                <span class="zs-security-badge is-active">E-mail wymagany</span>
              <?php endif; ?>
              <?php if (!empty($role['requires_2fa'])): ?>
                <span class="zs-security-badge is-active">2FA wymagane</span>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>

<section class="admin-section">
  <h2>Przydział ról użytkownikom</h2>
  <p class="editorial-note">Wysokie role operacyjne są przypisane administracyjnie. Wejście do kafelka wymaga spełnienia bramki bezpieczeństwa.</p>

  <table class="zs-admin-table">
    <thead>
      <tr>
        <th>Użytkownik</th>
        <th>Bezpieczeństwo</th>
        <th>Role redakcyjne</th>
        <th>Akcja</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach (($users ?? []) as $user): ?>
        <?php $userRoles = array_filter(array_map('trim', explode(',', (string)($user['roles'] ?? '')))); ?>
        <tr id="user-<?php echo (int)$user['id']; ?>">
          <td style="width: 200px;">
            <div class="zs-user-info">
              <strong class="zs-user-name"><?php echo e($user['display_name'] ?: 'Użytkownik #' . $user['id']); ?></strong>
              <small class="zs-user-email"><?php echo e($user['email']); ?></small>
              <small class="zs-user-email">status: <?php echo e($user['status']); ?></small>
            </div>
          </td>
          <td style="width: 200px;">
            <div class="zs-security-stack">
              <?php if (!empty($user['email_verified_at'])): ?>
                <span class="zs-security-badge is-active">E-mail potwierdzony</span>
              <?php else: ?>
                <span class="zs-security-badge is-missing">E-mail niepotwierdzony</span>
              <?php endif; ?>

              <?php if (!empty($user['two_factor_enabled'])): ?>
                <span class="zs-security-badge is-active">2FA aktywne</span>
              <?php else: ?>
                <span class="zs-security-badge is-missing">2FA nieaktywne</span>
              <?php endif; ?>

              <?php if (!empty($user['force_2fa_setup'])): ?>
                <span class="zs-status-badge failed" style="margin-top:4px;">Wymuszone 2FA</span>
              <?php endif; ?>
            </div>
          </td>
          <td>
            <form action="/admin/roles/editorial" method="POST" id="form-user-<?php echo (int)$user['id']; ?>">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="user_id" value="<?php echo (int)$user['id']; ?>">
              <label class="zs-role-checkbox"><span>Hasło 3DORS</span><input type="password" name="critical_password" required autocomplete="current-password"></label>
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
            <button type="submit" form="form-user-<?php echo (int)$user['id']; ?>" class="btn btn-small btn-save-role">Zapisz role</button>
            <?php if (!empty($user['two_factor_enabled'])): ?>
              <form action="/admin/roles/disable-2fa" method="POST" style="margin-top:8px;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="user_id" value="<?php echo (int)$user['id']; ?>">
                <input type="password" name="critical_password" placeholder="Hasło 3DORS" required autocomplete="current-password" style="width:100%;margin-bottom:6px;">
                <button type="submit" class="btn btn-small btn-secondary" style="width: 100%;">Reset 2FA</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="admin-actions editorial-note">
    <?php $prev = max(1, (int)($snajper_page ?? 1) - 1); $next = (int)($snajper_page ?? 1) + 1; ?>
    <a class="btn btn-secondary" href="/admin/roles?page=<?php echo $prev; ?>">Poprzednia strona</a>
    <span>Strona <?php echo (int)($snajper_page ?? 1); ?> / limit <?php echo (int)($snajper_limit ?? 50); ?></span>
    <a class="btn btn-secondary" href="/admin/roles?page=<?php echo $next; ?>">Następna strona</a>
  </div>
</section>

<div class="admin-actions editorial-note" style="margin-top: 32px;">
  <a href="/admin" class="btn btn-secondary">Powrót do dashboardu</a>
</div>

</div><!-- .zs-roles-page -->

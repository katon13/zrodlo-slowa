<?php
$pending = array_values(array_filter(
    $users,
    fn($u) => ($u['status'] ?? '') === 'pending_author' && str_contains((string)($u['roles'] ?? ''), 'author')
));

$lastUserId = (int)($flash_last_user_id ?? 0);
$flashSuccess = $flash_success ?? null;
$flashError = $flash_error ?? null;

$statusLabels = [
    'pending_author' => t('admin.users.status_pending'),
    'active' => t('admin.users.status_active'),
    'blocked' => t('admin.users.status_blocked'),
    'deleted' => t('admin.users.usuniety_ukryty'),
];

$roleLabels = [
    'reader' => t('admin.users.role_reader'),
    'commentator' => t('admin.users.role_commentator'),
    'author' => t('admin.users.role_author'),
    'editor' => t('admin.users.role_editor'),
    'chief_editor' => t('admin.users.role_chief_editor'),
    'publisher' => t('admin.users.role_publisher'),
    'moderator' => t('admin.users.role_moderator'),
    'proofreader' => t('admin.users.role_proofreader'),
    'accountant' => t('admin.users.ksiegowy'),
    'admin' => t('admin.users.role_admin'),
];

$permissionLabels = [
    'can_write' => ['label' => t('admin.users.permission_writing'), 'hint' => t('admin.users.permission_writing_hint')],
    'talent_enabled' => ['label' => t('admin.users.permission_talent'), 'hint' => t('admin.users.punkty_aktywnosci')],
    'wallet_enabled' => ['label' => t('admin.users.permission_wallet'), 'hint' => t('admin.users.permission_wallet_hint')],
    'payout_enabled' => ['label' => t('wallet.payout_active'), 'hint' => t('admin.users.realne_wypaty')],
];

$lastUserFound = false;
foreach ($users as $u) {
    if ($lastUserId > 0 && (int)$u['id'] === $lastUserId) {
        $lastUserFound = true;
        break;
    }
}
$visibleUsers = array_values(array_filter($users, static fn(array $user): bool => ($user['status'] ?? '') !== 'deleted'));
$activeUsers = count(array_filter($visibleUsers, static fn(array $user): bool => ($user['status'] ?? '') === 'active'));
$walletUsers = count(array_filter($visibleUsers, static fn(array $user): bool => !empty($user['wallet_id'])));
$authorUsers = count(array_filter($visibleUsers, static fn(array $user): bool => str_contains((string)($user['roles'] ?? ''), 'author')));
?>

<section class="admin-page-head admin-users-page zs-operator-page-head">
  <p class="kicker"><?= e(t('admin.users.redakcja_i_konta')) ?></p>
  <h1><?= e(t('admin.anti_fraud.uzytkownicy')) ?></h1>
  <p><?= e(t('admin.users.rola_uzytkownika_mowi_kim_jest_zgody_operacyjne_mowia_c_1ca2b919')) ?></p>
</section>

<section class="zs-operator-overview zs-users-overview" aria-label="<?= e(t('admin.users.podsumowanie_uzytkownikow')) ?>">
  <article><span><?= e(t('admin.users.widoczne_konta')) ?></span><strong><?= count($visibleUsers) ?></strong><small><?= e(t('admin.users.bez_kont_usunietych')) ?></small></article>
  <article class="is-ready"><span><?= e(t('admin.settings.aktywne')) ?></span><strong><?= $activeUsers ?></strong><small><?= e(t('admin.users.moga_korzystac_z_serwisu')) ?></small></article>
  <article><span><?= e(t('layout.menu.authors')) ?></span><strong><?= $authorUsers ?></strong><small><?= e(t('admin.users.konta_z_rola_autora')) ?></small></article>
  <article><span><?= e(t('admin.users.portfele')) ?></span><strong><?= $walletUsers ?></strong><small><?= e(t('admin.users.utworzone_konta_rozliczeniowe')) ?></small></article>
</section>

<?php if ($flashSuccess && !$lastUserFound): ?>
  <div class="inline-notice success u-mb-32"><strong><?= e(t('admin.user_delete.sukces')) ?></strong> <?= e($flashSuccess) ?></div>
<?php endif; ?>
<?php if ($flashError && !$lastUserFound): ?>
  <div class="inline-notice error u-mb-32"><strong><?= e(t('admin.user_delete.bad')) ?></strong> <?= e($flashError) ?></div>
<?php endif; ?>

<?php if (!empty($pending)): ?>
<section class="admin-panel-block admin-attention-block">
  <div>
    <p class="kicker"><?= e(t('admin.dashboard.autorzy_do_zatwierdzenia')) ?></p>
    <h2><?= e(str_replace('{count}', (string)count($pending), t(count($pending) === 1 ? 'admin.users.pending_one' : 'admin.users.pending_many'))) ?></h2>
    <p><?= e(t('admin.users.do_czasu_zatwierdzenia_autor_nie_moze_dodawac_ani_edyto_3be53ea7')) ?> <strong><?= e(t('author.dashboard.permission_writing')) ?></strong><?= e(t('admin.users.talent_wallet_i_wypaty_sa_osobnymi_decyzjami')) ?></p>
  </div>

  <div class="pending-author-list">
    <?php foreach ($pending as $u): ?>
      <article class="pending-author-card">
        <div>
          <strong><?= e($u['display_name']) ?></strong>
          <span><?= e($u['email']) ?></span>
        </div>
        <form method="post" action="/admin/users/approve-author">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
          <button class="btn-red" type="submit"><?= e(t('admin.users.zatwierdz_autora')) ?></button>
        </form>
      </article>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<section class="admin-panel-block zs-operator-panel zs-users-operator-panel">
  <div class="admin-section-head">
    <div>
      <p class="kicker"><?= e(t('admin.users.lista_kont')) ?></p>
      <h2><?= e(t('admin.users.wszyscy_uzytkownicy')) ?></h2>
    </div>
    <span><?= e(str_replace('{count}', (string)count($users), t('admin.common.items_count'))) ?></span>
  </div>

  <div class="admin-users-list">
    <?php foreach ($users as $u): ?>
      <?php
        $status = (string)($u['status'] ?? 'active');
        if ($status === 'deleted') continue;
        
        $roles = (string)($u['roles'] ?? '');
        $roleList = array_values(array_filter(array_map('trim', explode(',', $roles))));
        $primaryRole = 'reader';
        foreach (['admin', 'author', 'commentator', 'reader'] as $candidate) {
            if (in_array($candidate, $roleList, true)) {
                $primaryRole = $candidate;
                break;
            }
        }
        $editorialRoles = array_values(array_diff($roleList, ['reader', 'commentator', 'author', 'admin']));
        $hasWallet = !empty($u['wallet_id']);
      ?>
      <article class="admin-user-card status-row-<?= e($status) ?>" id="user-<?= (int)$u['id'] ?>">
        <?php if ($lastUserId === (int)$u['id']): ?>
          <div class="admin-user-inline-flash">
            <?php if ($flashSuccess): ?>
              <div class="inline-notice success"><strong><?= e(t('admin.user_delete.sukces')) ?></strong> <?= e($flashSuccess) ?></div>
            <?php endif; ?>
            <?php if ($flashError): ?>
              <div class="inline-notice error"><strong><?= e(t('admin.user_delete.bad')) ?></strong> <?= e($flashError) ?></div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <div class="admin-user-main">
          <span class="admin-id">#<?= (int)$u['id'] ?></span>
          <div>
            <strong class="admin-user-name"><?= e($u['display_name']) ?></strong>
            <span class="admin-user-email"><?= e($u['email']) ?></span>
          </div>
        </div>

        <div class="admin-user-meta">
          <span class="status-pill status-<?= e($status) ?>"><?= e($statusLabels[$status] ?? $status) ?></span>
          <span class="role-pill role-<?= e($primaryRole) ?>"><?= e($roleLabels[$primaryRole] ?? $primaryRole) ?></span>
          <?php foreach ($editorialRoles as $editorialRole): ?>
            <span class="role-pill role-<?= e($editorialRole) ?>"><?= e($roleLabels[$editorialRole] ?? $editorialRole) ?></span>
          <?php endforeach; ?>
              <span class="wallet-mini <?= $hasWallet ? 'is-on' : 'is-off' ?>"><?= e(t($hasWallet ? 'admin.users.wallet_exists' : 'admin.users.wallet_missing')) ?></span>
        </div>

        <form class="permissions-form" method="post" action="/admin/users/permissions">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
          <label class="zs-user-confirmation"><span><?= e(t('admin.users.potwierdz_uprawnienia_hasem_administratora')) ?></span><input type="password" name="critical_password" required autocomplete="current-password" placeholder="<?= e(t('admin.ai.haso_chroniace_zmiane')) ?>"></label>

          <div class="permission-switches">
            <?php foreach ($permissionLabels as $field => $meta): ?>
              <?php $checked = (int)($u[$field] ?? 0) === 1; ?>
              <?php $lockedForCommentator = $primaryRole === 'commentator' && in_array($field, ['can_write','payout_enabled'], true); ?>
              <label class="permission-toggle <?= $checked ? 'is-active' : '' ?>" data-permission="<?= e($field) ?>">
                <input type="hidden" name="<?= e($field) ?>" value="0">
                <input type="checkbox" name="<?= e($field) ?>" value="1" <?= $checked ? 'checked' : '' ?> <?= $lockedForCommentator ? 'disabled' : '' ?>>
                <span class="toggle-dot" aria-hidden="true"></span>
                <span>
                  <strong><?= e($meta['label']) ?></strong>
                  <small><?= e($lockedForCommentator ? ($field === 'can_write' ? t('admin.users.commentator_writing_hint') : t('admin.users.komentator_nie_ma_wypat_pln')) : $meta['hint']) ?></small>
                </span>
              </label>
            <?php endforeach; ?>
          </div>

          <div class="permissions-actions">
            <button class="btn-red compact" type="submit"><?= e(t('admin.users.zapisz_uprawnienia')) ?></button>
            <small><?= e(t('admin.users.system_zapisze_zgody_i_utworzy_potrzebne_zaplecze_dopie_eb6b3147')) ?></small>
          </div>
          <div class="js-permission-notice"></div>
        </form>

        <div class="admin-user-forms">
          <?php if ($status === 'pending_author' && str_contains($roles, 'author')): ?>
            <form class="admin-action-form zs-user-operation" data-title="<?= e(t('admin.users.akceptacja_autora')) ?>" method="post" action="/admin/users/approve-author">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
              <input type="password" name="critical_password" placeholder="<?= e(t('admin.ai.haso_administratora')) ?>" required autocomplete="current-password">
              <button class="btn-red compact" type="submit"><?= e(t('admin.users.zatwierdz_autora')) ?></button>
            </form>
          <?php endif; ?>

          <form class="admin-action-form zs-user-operation" data-title="<?= e(t('admin.users.status_konta')) ?>" method="post" action="/admin/users/status">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <input type="password" name="critical_password" placeholder="<?= e(t('admin.ai.haso_administratora')) ?>" required autocomplete="current-password">
            <label>
              <span><?= e(t('wallet.history.table.status')) ?></span>
              <select name="status">
                <?php foreach (['pending_author','active','blocked'] as $option): ?>
                  <option value="<?= e($option) ?>" <?= $status === $option ? 'selected' : '' ?>><?= e($statusLabels[$option] ?? $option) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <button class="btn-line compact" type="submit"><?= e(t('author.article.change_image')) ?></button>
          </form>

          <form class="admin-action-form zs-user-operation" data-title="<?= e(t('admin.users.typ_konta')) ?>" method="post" action="/admin/users/role">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <input type="password" name="critical_password" placeholder="<?= e(t('admin.ai.haso_administratora')) ?>" required autocomplete="current-password">
            <label>
              <span><?= e(t('admin.users.typ_konta')) ?></span>
              <select name="role">
                <?php foreach (['reader','commentator','author','admin'] as $role): ?>
                  <option value="<?= e($role) ?>" <?= $primaryRole === $role ? 'selected' : '' ?>><?= e($roleLabels[$role] ?? $role) ?></option>
                <?php endforeach; ?>
              </select>
              <small><?= e(t('admin.users.komentator_publikuje_wyacznie_opinie_i_polemiki_otrzymu_3bfe440e')) ?></small>
            </label>
            <button class="btn-line compact" type="submit"><?= e(t('author.article.change_image')) ?></button>
          </form>

          <form class="talent-action-form zs-user-operation zs-user-talent-operation" data-title="<?= e(t('admin.users.reczna_nagroda_talent')) ?>" method="post" action="/admin/users/talent-reward">
            <?= csrf_field() ?>
            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
            <input type="password" name="critical_password" placeholder="<?= e(t('admin.ai.haso_administratora')) ?>" required autocomplete="current-password">
            <label>
              <span><?= e(t('author.dashboard.permission_talent')) ?></span>
              <input type="number" name="points" placeholder="TT">
            </label>
            <label>
              <span><?= e(t('admin.partials.campaign_surveys.opis')) ?></span>
              <input type="text" name="description" placeholder="<?= e(t('admin.users.powod_naliczenia')) ?>">
            </label>
            <button class="btn-line compact" type="submit"><?= e(t('admin.users.dodaj_talent')) ?></button>
          </form>

          <a href="/admin/users/delete?id=<?= (int)$u['id'] ?>" class="btn-line compact zs-user-delete-report"><?= e(t('admin.users.raport_i_usuniecie_konta')) ?></a>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
</section>

<script>
const usersUi = <?= json_encode([
    'payoutNeedsWallet' => t('admin.users.payout_requires_wallet'),
    'saving' => t('common.saving'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.permissions-form').forEach(function (form) {
    var wallet = form.querySelector('input[type="checkbox"][name="wallet_enabled"]');
    var payout = form.querySelector('input[type="checkbox"][name="payout_enabled"]');
    var noticeBox = form.querySelector('.js-permission-notice');
    
    if (!wallet || !payout) {
      return;
    }

    wallet.addEventListener('change', function () {
      if (!wallet.checked && payout.checked) {
        payout.checked = false;
        payout.closest('.permission-toggle').classList.remove('is-active');
        if (noticeBox) {
          noticeBox.innerHTML = '<div class="inline-notice"></div>';
          noticeBox.querySelector('.inline-notice').textContent = usersUi.payoutNeedsWallet;
        }
      } else {
        if (noticeBox) noticeBox.innerHTML = '';
      }
    });

    form.querySelectorAll('input[type="checkbox"]').forEach(function(input) {
      input.addEventListener('change', function() {
        var label = input.closest('.permission-toggle');
        if (input.checked) {
          label.classList.add('is-active');
        } else {
          label.classList.remove('is-active');
        }
      });
    });

    form.addEventListener('submit', function() {
      var btn = form.querySelector('button[type="submit"]');
      if (btn) {
        btn.disabled = true;
        btn.innerText = usersUi.saving;
      }
    });
  });

  document.querySelectorAll('.admin-action-form, .talent-action-form').forEach(function(form) {
    form.addEventListener('submit', function() {
      var btn = form.querySelector('button[type="submit"]');
      if (btn) {
        btn.disabled = true;
        btn.innerHTML = '...';
      }
    });
  });
});
</script>

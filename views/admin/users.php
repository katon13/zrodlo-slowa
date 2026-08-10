<?php
$pending = array_values(array_filter(
    $users,
    fn($u) => ($u['status'] ?? '') === 'pending_author' && str_contains((string)($u['roles'] ?? ''), 'author')
));

$lastUserId = (int)($flash_last_user_id ?? 0);
$flashSuccess = $flash_success ?? null;
$flashError = $flash_error ?? null;

$statusLabels = [
    'pending_author' => 'oczekuje',
    'active' => 'aktywny',
    'blocked' => 'zablokowany',
    'deleted' => 'usunięty / ukryty',
];

$roleLabels = [
    'reader' => 'czytelnik',
    'commentator' => 'komentator',
    'author' => 'autor',
    'editor' => 'redaktor',
    'chief_editor' => 'redaktor naczelny',
    'publisher' => 'wydawca',
    'moderator' => 'moderator',
    'proofreader' => 'korektor',
    'accountant' => 'księgowy',
    'admin' => 'administrator',
];

$permissionLabels = [
    'can_write' => ['label' => 'Pisanie', 'hint' => 'panel autora i teksty'],
    'talent_enabled' => ['label' => 'Talent', 'hint' => 'punkty aktywności'],
    'wallet_enabled' => ['label' => 'Wallet', 'hint' => 'konto rozliczeniowe'],
    'payout_enabled' => ['label' => 'Wypłaty', 'hint' => 'realne wypłaty'],
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
  <p class="kicker">Redakcja i konta</p>
  <h1>Użytkownicy</h1>
  <p>Rola użytkownika mówi, kim jest. Zgody operacyjne mówią, co może realnie robić: pisać, zbierać Talent, mieć wallet i składać wnioski o wypłatę.</p>
</section>

<section class="zs-operator-overview zs-users-overview" aria-label="Podsumowanie użytkowników">
  <article><span>Widoczne konta</span><strong><?= count($visibleUsers) ?></strong><small>bez kont usuniętych</small></article>
  <article class="is-ready"><span>Aktywne</span><strong><?= $activeUsers ?></strong><small>mogą korzystać z serwisu</small></article>
  <article><span>Autorzy</span><strong><?= $authorUsers ?></strong><small>konta z rolą autora</small></article>
  <article><span>Portfele</span><strong><?= $walletUsers ?></strong><small>utworzone konta rozliczeniowe</small></article>
</section>

<?php if ($flashSuccess && !$lastUserFound): ?>
  <div class="inline-notice success u-mb-32"><strong>Sukces</strong> <?= e($flashSuccess) ?></div>
<?php endif; ?>
<?php if ($flashError && !$lastUserFound): ?>
  <div class="inline-notice error u-mb-32"><strong>Błąd</strong> <?= e($flashError) ?></div>
<?php endif; ?>

<?php if (!empty($pending)): ?>
<section class="admin-panel-block admin-attention-block">
  <div>
    <p class="kicker">Autorzy do zatwierdzenia</p>
    <h2><?= count($pending) ?> konto<?= count($pending) === 1 ? '' : ' / konta' ?> czeka na decyzję</h2>
    <p>Do czasu zatwierdzenia autor nie może dodawać ani edytować tekstów. Zatwierdzenie autora włącza tylko zgodę <strong>Pisanie</strong>. Talent, Wallet i Wypłaty są osobnymi decyzjami.</p>
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
          <button class="btn-red" type="submit">Zatwierdź autora</button>
        </form>
      </article>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<section class="admin-panel-block zs-operator-panel zs-users-operator-panel">
  <div class="admin-section-head">
    <div>
      <p class="kicker">Lista kont</p>
      <h2>Wszyscy użytkownicy</h2>
    </div>
    <span><?= count($users) ?> pozycji</span>
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
              <div class="inline-notice success"><strong>Sukces</strong> <?= e($flashSuccess) ?></div>
            <?php endif; ?>
            <?php if ($flashError): ?>
              <div class="inline-notice error"><strong>Błąd</strong> <?= e($flashError) ?></div>
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
          <span class="wallet-mini <?= $hasWallet ? 'is-on' : 'is-off' ?>"><?= $hasWallet ? 'wallet istnieje' : 'bez walleta' ?></span>
        </div>

        <form class="permissions-form" method="post" action="/admin/users/permissions">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
          <label class="zs-user-confirmation"><span>Potwierdź uprawnienia hasłem administratora</span><input type="password" name="critical_password" required autocomplete="current-password" placeholder="Hasło chroniące zmianę"></label>

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
                  <small><?= e($lockedForCommentator ? ($field === 'can_write' ? 'rola pisze tylko opinie i polemiki' : 'komentator nie ma wypłat PLN') : $meta['hint']) ?></small>
                </span>
              </label>
            <?php endforeach; ?>
          </div>

          <div class="permissions-actions">
            <button class="btn-red compact" type="submit">Zapisz uprawnienia</button>
            <small>System zapisze zgody i utworzy potrzebne zaplecze dopiero wtedy, gdy będzie wymagane.</small>
          </div>
          <div class="js-permission-notice"></div>
        </form>

        <div class="admin-user-forms">
          <?php if ($status === 'pending_author' && str_contains($roles, 'author')): ?>
            <form class="admin-action-form zs-user-operation" data-title="Akceptacja autora" method="post" action="/admin/users/approve-author">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
              <input type="password" name="critical_password" placeholder="Hasło administratora" required autocomplete="current-password">
              <button class="btn-red compact" type="submit">Zatwierdź autora</button>
            </form>
          <?php endif; ?>

          <form class="admin-action-form zs-user-operation" data-title="Status konta" method="post" action="/admin/users/status">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <input type="password" name="critical_password" placeholder="Hasło administratora" required autocomplete="current-password">
            <label>
              <span>Status</span>
              <select name="status">
                <?php foreach (['pending_author','active','blocked'] as $option): ?>
                  <option value="<?= e($option) ?>" <?= $status === $option ? 'selected' : '' ?>><?= e($statusLabels[$option] ?? $option) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <button class="btn-line compact" type="submit">Zmień</button>
          </form>

          <form class="admin-action-form zs-user-operation" data-title="Typ konta" method="post" action="/admin/users/role">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <input type="password" name="critical_password" placeholder="Hasło administratora" required autocomplete="current-password">
            <label>
              <span>Typ konta</span>
              <select name="role">
                <?php foreach (['reader','commentator','author','admin'] as $role): ?>
                  <option value="<?= e($role) ?>" <?= $primaryRole === $role ? 'selected' : '' ?>><?= e($roleLabels[$role] ?? $role) ?></option>
                <?php endforeach; ?>
              </select>
              <small>Komentator publikuje wyłącznie opinie i polemiki, otrzymuje TT, ale ma trwale wyłączone wypłaty.</small>
            </label>
            <button class="btn-line compact" type="submit">Zmień</button>
          </form>

          <form class="talent-action-form zs-user-operation zs-user-talent-operation" data-title="Ręczna nagroda Talent" method="post" action="/admin/users/talent-reward">
            <?= csrf_field() ?>
            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
            <input type="password" name="critical_password" placeholder="Hasło administratora" required autocomplete="current-password">
            <label>
              <span>Talent</span>
              <input type="number" name="points" placeholder="TT">
            </label>
            <label>
              <span>Opis</span>
              <input type="text" name="description" placeholder="Powód naliczenia">
            </label>
            <button class="btn-line compact" type="submit">Dodaj Talent</button>
          </form>

          <a href="/admin/users/delete?id=<?= (int)$u['id'] ?>" class="btn-line compact zs-user-delete-report">Raport i usunięcie konta</a>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
</section>

<script>
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
          noticeBox.innerHTML = '<div class="inline-notice">Wypłaty wymagają aktywnego portfela. Cofnięto również Wypłaty.</div>';
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
        btn.innerText = 'Zapisuję...';
      }
    });
  });

  // Obsługa wszystkich formularzy akcji dla wizualnego feedbacku
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

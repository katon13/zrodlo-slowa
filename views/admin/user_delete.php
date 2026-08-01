<?php
$user = $report['user'];
$sections = $report['sections'];
$total = (int)$report['total_dependencies'];
$hasFinancial = (bool)$report['has_financial_history'];
$hasPublication = (bool)($report['has_publication_history'] ?? false);
$canHardDelete = (bool)($report['can_hard_delete'] ?? false);
$status = (string)($user['status'] ?? '');
$deleted = $status === 'deleted';
?>
<div class="admin-container admin-user-delete-page">
  <section class="admin-page-head">
    <p class="kicker">ADMIN / UŻYTKOWNICY / USUWANIE</p>
    <h1>Bezpieczne usuwanie użytkownika</h1>
    <p>Najpierw raport zależności, potem decyzja: dezaktywacja i anonimizacja albo twarde czyszczenie tylko wtedy, gdy nie ma historii finansowej ani tekstów autora.</p>
  </section>

  <?php if ($flash_success ?? null): ?>
    <div class="inline-notice success u-mb-32"><strong>Sukces</strong> <?= e($flash_success) ?></div>
  <?php endif; ?>
  <?php if ($flash_error ?? null): ?>
    <div class="inline-notice error u-mb-32"><strong>Błąd</strong> <?= e($flash_error) ?></div>
  <?php endif; ?>

  <div id="action-confirmation" class="inline-notice error u-mb-32" style="display: none; border: 2px solid var(--accent-red);">
    <div style="display: flex; align-items: center; justify-content: space-between; gap: 20px; flex-wrap: wrap;">
      <div>
        <strong id="confirm-title" style="text-transform: uppercase; letter-spacing: 0.05em;">Potwierdź akcję</strong>
        <p id="confirm-text" class="u-mt-8" style="margin-bottom: 0;"></p>
      </div>
      <div style="display: flex; gap: 12px;">
        <button id="confirm-yes" class="btn-red">TAK, KONTYNUUJ</button>
        <button type="button" class="btn-line compact" onclick="closeConfirm()">ANULUJ</button>
      </div>
    </div>
  </div>

  <div class="admin-grid two-cols">
    <div class="admin-section card">
      <h2>Użytkownik</h2>
      <div class="wallet-row"><span>ID</span><strong>#<?= (int)$user['id'] ?></strong></div>
      <div class="wallet-row"><span>Nazwa</span><strong><?= e($user['display_name'] ?? '') ?></strong></div>
      <div class="wallet-row"><span>E-mail</span><strong><?= e($user['email'] ?? '') ?></strong></div>
      <div class="wallet-row"><span>Status</span><strong><?= e($status) ?></strong></div>
      <div class="wallet-row"><span>Zależności</span><strong><?= $total ?></strong></div>
      <div class="wallet-row"><span>Historia finansowa</span><strong><?= $hasFinancial ? 'TAK' : 'BRAK' ?></strong></div>
      <div class="wallet-row"><span>Historia publikacji</span><strong><?= $hasPublication ? 'TAK' : 'BRAK' ?></strong></div>
      
      <div class="u-mt-24">
        <?php if ($canHardDelete): ?>
          <div class="inline-notice success" style="margin: 0;">
            <strong>MOŻNA USUNĄĆ CAŁKOWICIE</strong>
            <p class="u-mt-4">Konto nie posiada historii finansowej ani publikacyjnej. Dane zostaną fizycznie usunięte z bazy.</p>
          </div>
        <?php else: ?>
          <div class="inline-notice error" style="margin: 0;">
            <strong>TYLKO ANONIMIZACJA</strong>
            <p class="u-mt-4">Wykryto historię finansową lub teksty autora. Twarde usunięcie jest zablokowane dla zachowania spójności księgowej.</p>
          </div>
        <?php endif; ?>
      </div>

      <?php if ($deleted): ?>
        <p class="notice success u-mt-20">Ten użytkownik jest już oznaczony jako usunięty / zanonimizowany.</p>
      <?php endif; ?>
      
      <div class="u-mt-32">
        <a class="btn-line compact" href="/admin/users">Wróć do listy użytkowników</a>
      </div>
    </div>

    <div class="admin-section card">
      <h2>Decyzja</h2>
      <p class="u-mb-24 text-muted text-small">Bezpieczny tryb produkcyjny to anonimizacja. Dane osobowe znikają, login jest blokowany, ale historia finansowa zostaje jako zapis księgowy.</p>
      
      <form id="form-anonymize" method="post" action="/admin/users/anonymize">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
        <label><span>Hasło 3DORS</span><input type="password" name="critical_password" required autocomplete="current-password"></label>
        <button class="btn-red btn-full" type="submit" <?= $deleted ? 'disabled' : '' ?>>Dezaktywuj i anonimizuj</button>
      </form>

      <div class="separator-dashed"></div>

      <p class="u-mb-16"><strong>Twarde czyszczenie</strong> całkowicie usuwa konto z bazy. Dostępne tylko dla kont bez historii.</p>
      
      <form id="form-hard-clean" method="post" action="/admin/users/hard-clean" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
        <div class="field"><span>Hasło 3DORS</span><input type="password" name="critical_password" required autocomplete="current-password"></div>
        
        <div class="field">
          <span style="font-size: 10px; text-transform: uppercase; color: var(--muted); display: block; margin-bottom: 4px;">Wpisz: USUŃ UŻYTKOWNIKA <?= (int)$user['id'] ?></span>
          <input name="confirmation" placeholder="Potwierdź wpisując frazę" <?= (!$canHardDelete || $deleted) ? 'disabled' : '' ?>>
        </div>
        
        <button class="btn-line btn-full" type="submit" <?= (!$canHardDelete || $deleted) ? 'disabled' : '' ?>>USUŃ CAŁKOWICIE Z BAZY</button>
      </form>
    </div>

    <div class="admin-section card full-width">
      <h2>Raport zależności</h2>
      <?php foreach ($sections as $group => $items): ?>
        <div class="u-mt-32">
          <p class="eyebrow text-dark"><?= e($group) ?></p>
          <div class="admin-table-wrap">
          <table class="admin-table admin-table-wide">
            <thead>
              <tr>
                <th>Obszar</th>
                <th>Rekordy</th>
                <th>Tabela</th>
                <th>Decyzja</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($items as $item): ?>
                <tr>
                  <td><?= e($item['label']) ?></td>
                  <td><strong><?= (int)$item['count'] ?></strong></td>
                  <td><code style="font-size: 12px; color: var(--muted);"><?= $item['exists'] ? e($item['table'] . '.' . $item['column']) : 'brak w bazie' ?></code></td>
                  <td><span class="admin-note"><?= e($item['action']) ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if (!empty($recent_reports)): ?>
      <div class="admin-section card full-width">
        <h2>Ostatnie raporty usunięć</h2>
        <div class="admin-table-wrap">
        <table class="admin-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Użytkownik</th>
              <th>Tryb</th>
              <th>Admin</th>
              <th>Data</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recent_reports as $r): ?>
              <tr>
                <td><?= (int)$r['id'] ?></td>
                <td>#<?= (int)$r['deleted_user_id'] ?></td>
                <td><span class="tag"><?= e($r['mode']) ?></span></td>
                <td><?= e($r['admin_name'] ?? '') ?></td>
                <td><?= e($r['created_at']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
function closeConfirm() {
  document.getElementById('action-confirmation').style.display = 'none';
}

function showConfirm(formId, title, text) {
  const box = document.getElementById('action-confirmation');
  const titleEl = document.getElementById('confirm-title');
  const textEl = document.getElementById('confirm-text');
  const yesBtn = document.getElementById('confirm-yes');
  
  titleEl.innerText = title;
  textEl.innerText = text;
  box.style.display = 'block';
  box.scrollIntoView({ behavior: 'smooth', block: 'start' });
  
  yesBtn.onclick = function() {
    document.getElementById(formId).submit();
  };
}

document.addEventListener('DOMContentLoaded', function() {
  const anonForm = document.getElementById('form-anonymize');
  if (anonForm) {
    anonForm.onsubmit = function(e) {
      e.preventDefault();
      showConfirm('form-anonymize', 'Potwierdź anonimizację', 'Czy na pewno chcesz zanonimizować użytkownika #<?= (int)$user['id'] ?>? Ta operacja jest nieodwracalna.');
    };
  }
  
  const hardForm = document.getElementById('form-hard-clean');
  if (hardForm) {
    hardForm.onsubmit = function(e) {
      const confInput = hardForm.querySelector('input[name="confirmation"]');
      const expected = "USUŃ UŻYTKOWNIKA <?= (int)$user['id'] ?>";
      
      if (confInput.value.trim() !== expected) {
        // Pozwól przejść do serwera, żeby pokazał błąd walidacji
        return;
      }
      
      e.preventDefault();
      showConfirm('form-hard-clean', 'POTWIERDŹ TWARDE USUNIĘCIE', 'UWAGA: Całkowite usunięcie użytkownika #<?= (int)$user['id'] ?> i wszystkich powiązanych danych. Nie można tego cofnąć!');
    };
  }
});
</script>

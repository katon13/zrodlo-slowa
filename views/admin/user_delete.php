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
    <p class="kicker"><?= e(t('admin.user_delete.admin_uzytkownicy_usuwanie')) ?></p>
    <h1><?= e(t('admin.user_delete.bezpieczne_usuwanie_uzytkownika')) ?></h1>
    <p><?= e(t('admin.user_delete.najpierw_raport_zaleznosci_potem_decyzja_dezaktywacja_i_14ea7dab')) ?></p>
  </section>

  <?php if ($flash_success ?? null): ?>
    <div class="inline-notice success u-mb-32"><strong><?= e(t('admin.user_delete.sukces')) ?></strong> <?= e($flash_success) ?></div>
  <?php endif; ?>
  <?php if ($flash_error ?? null): ?>
    <div class="inline-notice error u-mb-32"><strong><?= e(t('admin.user_delete.bad')) ?></strong> <?= e($flash_error) ?></div>
  <?php endif; ?>

  <div id="action-confirmation" class="inline-notice error u-mb-32" style="display: none; border: 2px solid var(--accent-red);">
    <div style="display: flex; align-items: center; justify-content: space-between; gap: 20px; flex-wrap: wrap;">
      <div>
        <strong id="confirm-title" style="text-transform: uppercase; letter-spacing: 0.05em;"><?= e(t('admin.user_delete.potwierdz_akcje')) ?></strong>
        <p id="confirm-text" class="u-mt-8" style="margin-bottom: 0;"></p>
      </div>
      <div style="display: flex; gap: 12px;">
        <button id="confirm-yes" class="btn-red"><?= e(t('admin.user_delete.tak_kontynuuj')) ?></button>
        <button type="button" class="btn-line compact" onclick="closeConfirm()"><?= e(t('admin.user_delete.anuluj')) ?></button>
      </div>
    </div>
  </div>

  <div class="admin-grid two-cols">
    <div class="admin-section card">
      <h2><?= e(t('wallet.orders.table.user')) ?></h2>
      <div class="wallet-row"><span>ID</span><strong>#<?= (int)$user['id'] ?></strong></div>
      <div class="wallet-row"><span><?= e(t('admin.categories.nazwa')) ?></span><strong><?= e($user['display_name'] ?? '') ?></strong></div>
      <div class="wallet-row"><span><?= e(t('admin.user_delete.e_mail')) ?></span><strong><?= e($user['email'] ?? '') ?></strong></div>
      <div class="wallet-row"><span><?= e(t('wallet.history.table.status')) ?></span><strong><?= e($status) ?></strong></div>
      <div class="wallet-row"><span><?= e(t('admin.user_delete.zaleznosci')) ?></span><strong><?= $total ?></strong></div>
        <div class="wallet-row"><span><?= e(t('admin.user_delete.historia_finansowa')) ?></span><strong><?= e(t($hasFinancial ? 'common.yes' : 'common.missing')) ?></strong></div>
        <div class="wallet-row"><span><?= e(t('admin.user_delete.historia_publikacji')) ?></span><strong><?= e(t($hasPublication ? 'common.yes' : 'common.missing')) ?></strong></div>
      
      <div class="u-mt-24">
        <?php if ($canHardDelete): ?>
          <div class="inline-notice success" style="margin: 0;">
            <strong><?= e(t('admin.user_delete.mozna_usunac_cakowicie')) ?></strong>
            <p class="u-mt-4"><?= e(t('admin.user_delete.konto_nie_posiada_historii_finansowej_ani_publikacyjnej_1887ded7')) ?></p>
          </div>
        <?php else: ?>
          <div class="inline-notice error" style="margin: 0;">
            <strong><?= e(t('admin.user_delete.tylko_anonimizacja')) ?></strong>
            <p class="u-mt-4"><?= e(t('admin.user_delete.wykryto_historie_finansowa_lub_teksty_autora_twarde_usu_650309b8')) ?></p>
          </div>
        <?php endif; ?>
      </div>

      <?php if ($deleted): ?>
        <p class="notice success u-mt-20"><?= e(t('admin.user_delete.ten_uzytkownik_jest_juz_oznaczony_jako_usuniety_zanonimizowany')) ?></p>
      <?php endif; ?>
      
      <div class="u-mt-32">
        <a class="btn-line compact" href="/admin/users"><?= e(t('admin.user_delete.wroc_do_listy_uzytkownikow')) ?></a>
      </div>
    </div>

    <div class="admin-section card">
      <h2><?= e(t('admin.payouts.decyzja')) ?></h2>
      <p class="u-mb-24 text-muted text-small"><?= e(t('admin.user_delete.bezpieczny_tryb_produkcyjny_to_anonimizacja_dane_osobow_d15314df')) ?></p>
      
      <form id="form-anonymize" method="post" action="/admin/users/anonymize">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
        <label><span><?= e(t('admin.roles.haso_3dors')) ?></span><input type="password" name="critical_password" required autocomplete="current-password"></label>
        <button class="btn-red btn-full" type="submit" <?= $deleted ? 'disabled' : '' ?>><?= e(t('admin.user_delete.dezaktywuj_i_anonimizuj')) ?></button>
      </form>

      <div class="separator-dashed"></div>

      <p class="u-mb-16"><strong><?= e(t('admin.user_delete.twarde_czyszczenie')) ?></strong> <?= e(t('admin.user_delete.cakowicie_usuwa_konto_z_bazy_dostepne_tylko_dla_kont_be_5a0754fa')) ?></p>
      
      <form id="form-hard-clean" method="post" action="/admin/users/hard-clean" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
        <div class="field"><span><?= e(t('admin.roles.haso_3dors')) ?></span><input type="password" name="critical_password" required autocomplete="current-password"></div>
        
        <div class="field">
          <span style="font-size: 10px; text-transform: uppercase; color: var(--muted); display: block; margin-bottom: 4px;"><?= e(str_replace('{phrase}', t('admin.user_delete.confirm_phrase') . ' ' . (int)$user['id'], t('admin.user_delete.type_phrase'))) ?></span>
          <input name="confirmation" placeholder="<?= e(t('admin.user_delete.potwierdz_wpisujac_fraze')) ?>" <?= (!$canHardDelete || $deleted) ? 'disabled' : '' ?>>
        </div>
        
        <button class="btn-line btn-full" type="submit" <?= (!$canHardDelete || $deleted) ? 'disabled' : '' ?>><?= e(t('admin.user_delete.usun_cakowicie_z_bazy')) ?></button>
      </form>
    </div>

    <div class="admin-section card full-width">
      <h2><?= e(t('admin.user_delete.raport_zaleznosci')) ?></h2>
      <?php foreach ($sections as $group => $items): ?>
        <div class="u-mt-32">
          <p class="eyebrow text-dark"><?= e($group) ?></p>
          <div class="admin-table-wrap">
          <table class="admin-table admin-table-wide">
            <thead>
              <tr>
                <th><?= e(t('admin.dashboard.obszar')) ?></th>
                <th><?= e(t('admin.user_delete.rekordy')) ?></th>
                <th><?= e(t('admin.user_delete.tabela')) ?></th>
                <th><?= e(t('admin.payouts.decyzja')) ?></th>
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
        <h2><?= e(t('admin.user_delete.ostatnie_raporty_usuniec')) ?></h2>
        <div class="admin-table-wrap">
        <table class="admin-table">
          <thead>
            <tr>
              <th>ID</th>
              <th><?= e(t('wallet.orders.table.user')) ?></th>
              <th><?= e(t('admin.user_delete.tryb')) ?></th>
              <th><?= e(t('layout.menu.admin')) ?></th>
              <th><?= e(t('wallet.history.table.date')) ?></th>
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
const userDeleteUi = <?= json_encode([
    'anonymizeTitle' => t('admin.user_delete.anonymize_title'),
    'anonymizeText' => str_replace('{id}', (string)(int)$user['id'], t('admin.user_delete.anonymize_confirm')),
    'confirmPhrase' => t('admin.user_delete.confirm_phrase') . ' ' . (int)$user['id'],
    'hardDeleteTitle' => t('admin.user_delete.hard_delete_title'),
    'hardDeleteText' => str_replace('{id}', (string)(int)$user['id'], t('admin.user_delete.hard_delete_confirm')),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
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
      showConfirm('form-anonymize', userDeleteUi.anonymizeTitle, userDeleteUi.anonymizeText);
    };
  }
  
  const hardForm = document.getElementById('form-hard-clean');
  if (hardForm) {
    hardForm.onsubmit = function(e) {
      const confInput = hardForm.querySelector('input[name="confirmation"]');
      const expected = userDeleteUi.confirmPhrase;
      
      if (confInput.value.trim() !== expected) {
        return;
      }
      
      e.preventDefault();
      showConfirm('form-hard-clean', userDeleteUi.hardDeleteTitle, userDeleteUi.hardDeleteText);
    };
  }
});
</script>

<?php
$money = static fn($minor) => number_format(((int)$minor) / 100, 2, ',', ' ') . ' PLN';
$statusLabels = [
    'requested' => 'OCZEKUJE',
    'approved' => 'ZATWIERDZONA',
    'paid' => 'WYPŁACONA',
    'rejected' => 'ODRZUCONA',
    'cancelled' => 'ANULOWANA',
];
?>
<section class="admin-page-head zs-operator-page-head">
  <p class="kicker">Portfel / wypłaty</p>
  <h1>Wypłaty i rozliczenia</h1>
  <p>Kontrola wypłat autora i użytkownika: rezerwacja środków, decyzja redakcji oraz końcowy zapis w dzienniku portfela.</p>
</section>

<?php if (!empty($flash_success)): ?><div class="notice success"><?= e($flash_success) ?></div><?php endif; ?>
<?php if (!empty($flash_error)): ?><div class="notice error"><?= e($flash_error) ?></div><?php endif; ?>

<section class="settlement-grid">
  <div class="settlement-card">
    <span>WNIOSKI</span>
    <strong><?= (int)($summary['total_count'] ?? 0) ?></strong>
    <small><?= $money($summary['total_amount'] ?? 0) ?></small>
  </div>
  <div class="settlement-card is-red">
    <span>DO DECYZJI</span>
    <strong><?= (int)($summary['requested_count'] ?? 0) ?></strong>
    <small><?= $money($summary['requested_amount'] ?? 0) ?></small>
  </div>
  <div class="settlement-card">
    <span>ZATWIERDZONE</span>
    <strong><?= (int)($summary['approved_count'] ?? 0) ?></strong>
    <small><?= $money($summary['approved_amount'] ?? 0) ?></small>
  </div>
  <div class="settlement-card">
    <span>WYPŁACONE</span>
    <strong><?= (int)($summary['paid_count'] ?? 0) ?></strong>
    <small><?= $money($summary['paid_amount'] ?? 0) ?></small>
  </div>
</section>

<section class="admin-panel-block zs-operator-panel">
  <div class="admin-section-head">
    <div>
      <p class="kicker">Lista wypłat</p>
      <h2>Wnioski użytkowników</h2>
    </div>
    <span><?= count($payouts) ?> pozycji</span>
  </div>

  <?php if (empty($payouts)): ?>
    <div class="empty-state"><h3>Brak wniosków o wypłatę.</h3><p>Gdy użytkownik złoży wniosek, pojawi się tutaj razem z rezerwacją środków.</p></div>
  <?php else: ?>
    <div class="admin-table-wrap">
      <table class="admin-table admin-table-wide">
        <thead>
          <tr><th>Typ</th><th>ID</th><th>Użytkownik</th><th>Kwota</th><th>Status</th><th>Metoda</th><th>Notatki</th><th>Decyzja</th></tr>
        </thead>
        <tbody>
          <?php foreach ($payouts as $p): ?>
            <?php 
              $status = (string)$p['status'];
              $icon = 'payout';
              if ($status === 'paid') $icon = 'bank';
              if ($status === 'rejected') $icon = 'warning';
            ?>
            <tr>
              <td class="zs-icon-cell"><?= zs_icon($icon) ?></td>
              <td class="admin-id">#<?= (int)$p['id'] ?></td>
              <td><strong class="admin-user-name"><?= e($p['display_name']) ?></strong><span class="admin-user-email"><?= e($p['email']) ?></span></td>
              <td><strong><?= $money($p['amount_minor']) ?></strong><small class="admin-note"><?= e($p['currency']) ?> · <?= e($p['requested_at']) ?></small></td>
              <td><span class="status-pill status-<?= e($status) ?>"><?= e($statusLabels[$status] ?? strtoupper($status)) ?></span></td>
              <td><strong><?= e($p['method_label'] ?: 'Brak metody') ?></strong><span class="admin-note"><?= e(trim(($p['method_type'] ?? '') . ' ' . ($p['account_ref'] ?? ''))) ?></span></td>
              <td><span class="admin-note"><?= e($p['note'] ?: '—') ?></span><?php if (!empty($p['admin_note'])): ?><span class="admin-note">Redakcja: <?= e($p['admin_note']) ?></span><?php endif; ?></td>
              <td class="admin-actions-cell">
                <?php if (in_array($status, ['paid','rejected','cancelled'], true)): ?>
                  <span class="admin-note">Zamknięte. Historia zostaje w księdze portfela.</span>
                <?php else: ?>
                  <form class="admin-action-form payout-action-form" method="post" action="/admin/payouts/status">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                    <label><span>Hasło administratora</span><input type="password" name="critical_password" required autocomplete="current-password" placeholder="Potwierdź decyzję"></label>
                    <label><span>Status</span><select name="status">
                      <?php if ($status === 'requested'): ?><option value="approved">Zatwierdź</option><?php endif; ?>
                      <?php if ($status === 'approved'): ?><option value="paid">Oznacz jako wypłacone</option><?php endif; ?>
                      <option value="rejected">Odrzuć</option>
                      <option value="cancelled">Anuluj</option>
                    </select></label>
                    <label><span>Notatka</span><input name="admin_note" placeholder="Decyzja redakcji"></label>
                    <button class="btn-red compact" type="submit">Zapisz</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

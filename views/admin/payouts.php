<?php
$money = static fn($minor) => number_format(((int)$minor) / 100, 2, ',', ' ') . ' PLN';
$statusLabels = [
    'requested' => t('admin.payouts.status_requested'),
    'approved' => t('admin.payouts.status_approved'),
    'paid' => t('admin.payouts.wypacona'),
    'rejected' => t('admin.payouts.status_rejected'),
    'cancelled' => t('admin.payouts.status_cancelled'),
];
?>
<section class="admin-page-head zs-operator-page-head">
  <p class="kicker"><?= e(t('admin.payouts.portfel_wypaty')) ?></p>
  <h1><?= e(t('admin.payouts.wypaty_i_rozliczenia')) ?></h1>
  <p><?= e(t('admin.payouts.kontrola_wypat_autora_i_uzytkownika_rezerwacja_srodkow_b19645fb')) ?></p>
</section>

<?php if (!empty($flash_success)): ?><div class="notice success"><?= e($flash_success) ?></div><?php endif; ?>
<?php if (!empty($flash_error)): ?><div class="notice error"><?= e($flash_error) ?></div><?php endif; ?>

<section class="settlement-grid">
  <div class="settlement-card">
    <span><?= e(t('admin.payouts.wnioski')) ?></span>
    <strong><?= (int)($summary['total_count'] ?? 0) ?></strong>
    <small><?= $money($summary['total_amount'] ?? 0) ?></small>
  </div>
  <div class="settlement-card is-red">
    <span><?= e(t('admin.payouts.do_decyzji')) ?></span>
    <strong><?= (int)($summary['requested_count'] ?? 0) ?></strong>
    <small><?= $money($summary['requested_amount'] ?? 0) ?></small>
  </div>
  <div class="settlement-card">
    <span><?= e(t('admin.payouts.zatwierdzone')) ?></span>
    <strong><?= (int)($summary['approved_count'] ?? 0) ?></strong>
    <small><?= $money($summary['approved_amount'] ?? 0) ?></small>
  </div>
  <div class="settlement-card">
    <span><?= e(t('admin.payouts.wypacone')) ?></span>
    <strong><?= (int)($summary['paid_count'] ?? 0) ?></strong>
    <small><?= $money($summary['paid_amount'] ?? 0) ?></small>
  </div>
</section>

<section class="admin-panel-block zs-operator-panel">
  <div class="admin-section-head">
    <div>
      <p class="kicker"><?= e(t('admin.payouts.lista_wypat')) ?></p>
      <h2><?= e(t('admin.payouts.wnioski_uzytkownikow')) ?></h2>
    </div>
    <span><?= e(str_replace('{count}', (string)count($payouts), t('admin.common.items_count'))) ?></span>
  </div>

  <?php if (empty($payouts)): ?>
    <div class="empty-state"><h3><?= e(t('admin.payouts.brak_wnioskow_o_wypate')) ?></h3><p><?= e(t('admin.payouts.gdy_uzytkownik_zozy_wniosek_pojawi_sie_tutaj_razem_z_re_d671456b')) ?></p></div>
  <?php else: ?>
    <div class="admin-table-wrap">
      <table class="admin-table admin-table-wide">
        <thead>
          <tr><th><?= e(t('wallet.withdrawals.method_type')) ?></th><th>ID</th><th><?= e(t('wallet.orders.table.user')) ?></th><th><?= e(t('wallet.history.table.amount')) ?></th><th><?= e(t('wallet.history.table.status')) ?></th><th><?= e(t('wallet.history.table.method')) ?></th><th><?= e(t('admin.payouts.notatki')) ?></th><th><?= e(t('admin.payouts.decyzja')) ?></th></tr>
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
              <td><strong><?= e($p['method_label'] ?: t('admin.payouts.no_method')) ?></strong><span class="admin-note"><?= e(trim(($p['method_type'] ?? '') . ' ' . ($p['account_ref'] ?? ''))) ?></span></td>
              <td><span class="admin-note"><?= e($p['note'] ?: '—') ?></span><?php if (!empty($p['admin_note'])): ?><span class="admin-note"><?= e(t('admin.payouts.editorial_prefix')) ?> <?= e($p['admin_note']) ?></span><?php endif; ?></td>
              <td class="admin-actions-cell">
                <?php if (in_array($status, ['paid','rejected','cancelled'], true)): ?>
                  <span class="admin-note"><?= e(t('admin.payouts.zamkniete_historia_zostaje_w_ksiedze_portfela')) ?></span>
                <?php else: ?>
                  <form class="admin-action-form payout-action-form" method="post" action="/admin/payouts/status">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                    <label><span><?= e(t('admin.ai.haso_administratora')) ?></span><input type="password" name="critical_password" required autocomplete="current-password" placeholder="<?= e(t('admin.financial_approvals.potwierdz_decyzje')) ?>"></label>
                    <label><span><?= e(t('wallet.history.table.status')) ?></span><select name="status">
                      <?php if ($status === 'requested'): ?><option value="approved"><?= e(t('admin.articles.zatwierdz')) ?></option><?php endif; ?>
                      <?php if ($status === 'approved'): ?><option value="paid"><?= e(t('admin.payouts.oznacz_jako_wypacone')) ?></option><?php endif; ?>
                      <option value="rejected"><?= e(t('admin.payouts.odrzuc')) ?></option>
                      <option value="cancelled"><?= e(t('author.article.cancel')) ?></option>
                    </select></label>
                    <label><span><?= e(t('article.support.form_note')) ?></span><input name="admin_note" placeholder="<?= e(t('admin.payouts.decyzja_redakcji')) ?>"></label>
                    <button class="btn-red compact" type="submit"><?= e(t('admin.categories.zapisz')) ?></button>
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

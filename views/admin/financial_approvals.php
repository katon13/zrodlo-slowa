<?php
$operationLabels = [
  'payout_status_update' => t('admin.financial_approvals.zmiana_statusu_wypaty'),
  'manual_reward' => t('admin.financial_approvals.reczna_nagroda_uzytkownika'),
];
?>
<section class="admin-page-head zs-operator-page-head">
  <p class="kicker"><?= e(t('admin.financial_approvals.finanse_dwuosobowa_kontrola')) ?></p>
  <h1><?= e(t('admin.financial_approvals.zlecenia_finansowe_do_zatwierdzenia')) ?></h1>
  <p><?= e(t('admin.financial_approvals.kazda_operacja_wymaga_dwoch_niezaleznych_osob_zgaszajac_bd9ed5ac')) ?></p>
</section>

<section class="admin-panel-block zs-approvals-panel zs-operator-panel">
  <div class="admin-section-head">
    <div>
      <p class="kicker"><?= e(t('admin.financial_approvals.kolejka_decyzji')) ?></p>
      <h2><?= e(t('admin.financial_approvals.oczekujace_operacje')) ?></h2>
    </div>
    <span><?= e(str_replace('{count}', (string)count($approvals ?? []), t('admin.common.items_count'))) ?></span>
  </div>

  <?php if (empty($approvals)): ?>
    <div class="empty-state zs-approval-empty">
      <?= zs_icon('check-circle') ?>
      <h3><?= e(t('admin.financial_approvals.brak_oczekujacych_zlecen')) ?></h3>
      <p><?= e(t('admin.financial_approvals.wszystkie_operacje_finansowe_zostay_rozpatrzone')) ?></p>
    </div>
  <?php else: ?>
    <div class="zs-approvals-list">
      <?php foreach ($approvals as $approval): ?>
        <?php
          $amountMinor = (int)($approval['amount'] ?? 0);
          $currency = (string)($approval['currency'] ?? 'PLN');
          $isOwnRequest = (int)($approval['requested_by'] ?? 0) === (int)$current_user_id;
        ?>
        <article class="zs-approval-card">
          <div class="zs-approval-card-head">
            <div>
              <p class="kicker"><?= e(str_replace('{id}', (string)(int)$approval['id'], t('admin.financial_approvals.operation_number'))) ?></p>
              <h3><?= e($operationLabels[(string)$approval['operation_type']] ?? (string)$approval['operation_type']) ?></h3>
            </div>
            <strong class="zs-approval-amount <?= $amountMinor >= 0 ? 'is-positive' : 'is-negative' ?>">
              <?= number_format($amountMinor / 100, 2, ',', ' ') ?> <?= e($currency) ?>
            </strong>
          </div>

          <div class="zs-approval-meta-grid">
            <div>
              <span><?= e(t('wallet.orders.table.user')) ?></span>
              <strong><?= e((string)$approval['display_name']) ?></strong>
              <small><?= e((string)$approval['email']) ?></small>
            </div>
            <div>
              <span><?= e(t('wallet.title')) ?></span>
              <strong>#<?= (int)$approval['wallet_id'] ?></strong>
            </div>
            <div>
              <span><?= e(t('admin.financial_approvals.zgoszone_przez')) ?></span>
              <strong><?= e((string)$approval['requester_name']) ?></strong>
              <small><?= e((string)$approval['requested_role']) ?></small>
            </div>
            <div>
              <span><?= e(t('wallet.history.table.date')) ?></span>
              <strong><?= e(date('d.m.Y H:i', strtotime((string)$approval['created_at']))) ?></strong>
            </div>
          </div>

          <div class="zs-approval-reason">
            <span><?= e(t('admin.financial_approvals.powod_operacji')) ?></span>
            <p><?= e((string)$approval['reason']) ?></p>
          </div>

          <?php if ($isOwnRequest): ?>
            <div class="notice error">
              <?= e(t('admin.financial_approvals.nie_mozesz_zatwierdzic_wasnego_zlecenia_musi_zrobic_to_de86ed99')) ?>
            </div>
          <?php endif; ?>

          <div class="zs-approval-actions">
            <?php if (!$isOwnRequest): ?>
              <form action="/admin/finance/approvals/execute" method="post" class="zs-approval-action-form">
                <?= csrf_field() ?>
                <input type="hidden" name="approval_id" value="<?= (int)$approval['id'] ?>">
                <label class="zs-field"><span><?= e(t('admin.ai.haso_administratora')) ?></span><input type="password" name="critical_password" required autocomplete="current-password" placeholder="<?= e(t('admin.financial_approvals.potwierdz_decyzje')) ?>"></label>
                <label class="zs-field">
                  <span><?= e(t('admin.financial_approvals.notatka_zatwierdzajacego')) ?></span>
                  <input type="text" name="admin_note" placeholder="<?= e(t('admin.financial_approvals.opcjonalnie')) ?>">
                </label>
                <button type="submit" class="zs-btn-red"><?= e(t('admin.financial_approvals.zatwierdz_i_wykonaj')) ?></button>
              </form>
            <?php endif; ?>

            <form action="/admin/finance/approvals/reject" method="post" class="zs-approval-action-form">
              <?= csrf_field() ?>
              <input type="hidden" name="approval_id" value="<?= (int)$approval['id'] ?>">
              <label class="zs-field"><span><?= e(t('admin.ai.haso_administratora')) ?></span><input type="password" name="critical_password" required autocomplete="current-password" placeholder="<?= e(t('admin.financial_approvals.potwierdz_decyzje')) ?>"></label>
              <label class="zs-field">
                <span><?= e(t('admin.financial_approvals.powod_odrzucenia')) ?></span>
                <input type="text" name="reject_reason" placeholder="<?= e(t('admin.financial_approvals.wymagany_przy_odrzuceniu')) ?>" required>
              </label>
              <button type="submit" class="zs-btn-outline is-danger"><?= e(t('admin.financial_approvals.odrzuc')) ?></button>
            </form>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<div class="zs-panel-footer">
  <a href="/admin" class="zs-link-aux"><?= e(t('ui.account.security.powrot_do_panelu')) ?></a>
  <span class="zs-sep">|</span>
  <a href="/admin/finance" class="zs-link-aux"><?= e(t('admin.finance_report.raport_finansowy')) ?></a>
</div>

<?php
$operationLabels = [
  'payout_status_update' => 'Zmiana statusu wypłaty',
  'manual_reward' => 'Ręczna nagroda użytkownika',
];
?>
<section class="admin-page-head zs-operator-page-head">
  <p class="kicker">FINANSE / DWUOSOBOWA KONTROLA</p>
  <h1>Zlecenia finansowe do zatwierdzenia</h1>
  <p>Każda operacja wymaga dwóch niezależnych osób: zgłaszającej oraz zatwierdzającej.</p>
</section>

<section class="admin-panel-block zs-approvals-panel zs-operator-panel">
  <div class="admin-section-head">
    <div>
      <p class="kicker">KOLEJKA DECYZJI</p>
      <h2>Oczekujące operacje</h2>
    </div>
    <span><?= count($approvals ?? []) ?> pozycji</span>
  </div>

  <?php if (empty($approvals)): ?>
    <div class="empty-state zs-approval-empty">
      <?= zs_icon('check-circle') ?>
      <h3>Brak oczekujących zleceń</h3>
      <p>Wszystkie operacje finansowe zostały rozpatrzone.</p>
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
              <p class="kicker">OPERACJA #<?= (int)$approval['id'] ?></p>
              <h3><?= e($operationLabels[(string)$approval['operation_type']] ?? (string)$approval['operation_type']) ?></h3>
            </div>
            <strong class="zs-approval-amount <?= $amountMinor >= 0 ? 'is-positive' : 'is-negative' ?>">
              <?= number_format($amountMinor / 100, 2, ',', ' ') ?> <?= e($currency) ?>
            </strong>
          </div>

          <div class="zs-approval-meta-grid">
            <div>
              <span>Użytkownik</span>
              <strong><?= e((string)$approval['display_name']) ?></strong>
              <small><?= e((string)$approval['email']) ?></small>
            </div>
            <div>
              <span>Portfel</span>
              <strong>#<?= (int)$approval['wallet_id'] ?></strong>
            </div>
            <div>
              <span>Zgłoszone przez</span>
              <strong><?= e((string)$approval['requester_name']) ?></strong>
              <small><?= e((string)$approval['requested_role']) ?></small>
            </div>
            <div>
              <span>Data</span>
              <strong><?= e(date('d.m.Y H:i', strtotime((string)$approval['created_at']))) ?></strong>
            </div>
          </div>

          <div class="zs-approval-reason">
            <span>Powód operacji</span>
            <p><?= e((string)$approval['reason']) ?></p>
          </div>

          <?php if ($isOwnRequest): ?>
            <div class="notice error">
              Nie możesz zatwierdzić własnego zlecenia. Musi zrobić to druga uprawniona osoba.
            </div>
          <?php endif; ?>

          <div class="zs-approval-actions">
            <?php if (!$isOwnRequest): ?>
              <form action="/admin/finance/approvals/execute" method="post" class="zs-approval-action-form">
                <?= csrf_field() ?>
                <input type="hidden" name="approval_id" value="<?= (int)$approval['id'] ?>">
                <label class="zs-field"><span>Hasło administratora</span><input type="password" name="critical_password" required autocomplete="current-password" placeholder="Potwierdź decyzję"></label>
                <label class="zs-field">
                  <span>Notatka zatwierdzającego</span>
                  <input type="text" name="admin_note" placeholder="Opcjonalnie">
                </label>
                <button type="submit" class="zs-btn-red">ZATWIERDŹ I WYKONAJ</button>
              </form>
            <?php endif; ?>

            <form action="/admin/finance/approvals/reject" method="post" class="zs-approval-action-form">
              <?= csrf_field() ?>
              <input type="hidden" name="approval_id" value="<?= (int)$approval['id'] ?>">
              <label class="zs-field"><span>Hasło administratora</span><input type="password" name="critical_password" required autocomplete="current-password" placeholder="Potwierdź decyzję"></label>
              <label class="zs-field">
                <span>Powód odrzucenia</span>
                <input type="text" name="reject_reason" placeholder="Wymagany przy odrzuceniu" required>
              </label>
              <button type="submit" class="zs-btn-outline is-danger">ODRZUĆ</button>
            </form>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<div class="zs-panel-footer">
  <a href="/admin" class="zs-link-aux">Powrót do panelu</a>
  <span class="zs-sep">|</span>
  <a href="/admin/finance" class="zs-link-aux">Raport finansowy</a>
</div>

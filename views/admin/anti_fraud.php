<?php
$statusLabels = [
    'normal' => t('admin.anti_fraud.status_normal'),
    'observe' => t('admin.anti_fraud.status_observe'),
    'suspect' => t('admin.anti_fraud.status_suspect'),
    'hold_payout' => t('admin.anti_fraud.wstrzymaj_wypate'),
];
$scoreClass = static function($score): string {
    $score = (int)$score;
    if ($score >= 80) return 'is-red';
    if ($score >= 60) return 'is-alert';
    return '';
};
?>
<section class="admin-page-head">
  <p class="kicker"><?= e(t('admin.anti_fraud.snajper_sowa_straznik')) ?></p>
  <h1><?= function_exists('zs_icon') ? zs_icon('shield', 'zs-title-icon') : '' ?>ANTYFRAUD</h1>
  <p><?= e(t('admin.anti_fraud.kontrola_reklam_ankiet_bonusow_i_wypat_ten_panel_nie_us_6d78a1bb')) ?></p>
</section>

<?php if (!empty($flash_success)): ?><div class="notice success"><?= e($flash_success) ?></div><?php endif; ?>
<?php if (!empty($flash_error)): ?><div class="notice error"><?= e($flash_error) ?></div><?php endif; ?>

<section class="settlement-grid">
  <div class="settlement-card zs-metric-card">
    <?= function_exists('zs_icon') ? zs_icon('warning') : '' ?>
    <span><?= e(t('admin.anti_fraud.zdarzenia_24h')) ?></span>
    <strong><?= (int)($summary['events_24h'] ?? 0) ?></strong>
    <small><?= e(t('admin.anti_fraud.wszystkie_wpisy_straznika')) ?></small>
  </div>
  <div class="settlement-card is-red zs-metric-card">
    <?= function_exists('zs_icon') ? zs_icon('shield') : '' ?>
    <span><?= e(t('admin.anti_fraud.podejrzane_24h')) ?></span>
    <strong><?= (int)($summary['suspect_24h'] ?? 0) ?></strong>
    <small><?= e(t('admin.anti_fraud.suspect_hold_payout')) ?></small>
  </div>
  <div class="settlement-card zs-metric-card">
    <?= function_exists('zs_icon') ? zs_icon('payout') : '' ?>
    <span><?= e(t('admin.anti_fraud.wstrzymane_30d')) ?></span>
    <strong><?= (int)($summary['held_payouts_30d'] ?? 0) ?></strong>
    <small><?= e(t('admin.anti_fraud.wypaty_do_kontroli')) ?></small>
  </div>
  <div class="settlement-card zs-metric-card">
    <?= function_exists('zs_icon') ? zs_icon('snajper') : '' ?>
    <span><?= e(t('admin.anti_fraud.max_risk_30d')) ?></span>
    <strong><?= (int)($summary['max_risk_30d'] ?? 0) ?></strong>
    <small><?= e(t('admin.anti_fraud.najwyzszy_risk_score')) ?></small>
  </div>
</section>

<section class="admin-panel-block">
  <div class="admin-section-head">
    <div>
      <p class="kicker"><?= e(t('admin.anti_fraud.skan')) ?></p>
      <h2><?= e(t('admin.anti_fraud.reczne_sprawdzenie_bazy')) ?></h2>
    </div>
    <form method="post" action="/admin/anti-fraud/scan" class="inline">
      <?= csrf_field() ?>
      <button class="btn-red compact" type="submit"><?= e(t('admin.anti_fraud.uruchom_skan')) ?></button>
    </form>
  </div>
  <p class="admin-note"><?= e(t('admin.anti_fraud.skan_analizuje_ostatnie_bonusy_reklamy_ankiety_i_wypaty_710fc7fa')) ?> <code><?= e(t('admin.anti_fraud.fraud_events')) ?></code>.</p>
</section>

<section class="admin-panel-block">
  <div class="admin-section-head">
    <div><p class="kicker"><?= e(t('admin.anti_fraud.uzytkownicy')) ?></p><h2><?= e(t('admin.anti_fraud.najwyzsze_ryzyko')) ?></h2></div>
    <span>LIMIT <?= (int)$snajper_limit ?></span>
  </div>

  <?php if (empty($risk_users)): ?>
    <div class="empty-state"><h3><?= e(t('admin.anti_fraud.brak_uzytkownikow_oznaczonych_ryzykiem')) ?></h3><p><?= e(t('admin.anti_fraud.snajper_nie_wykry_jeszcze_zdarzen_wymagajacych_kontroli')) ?></p></div>
  <?php else: ?>
    <div class="admin-table-wrap">
      <table class="admin-table admin-table-wide">
        <thead><tr><th><?= e(t('wallet.orders.table.user')) ?></th><th><?= e(t('admin.anti_fraud.risk')) ?></th><th><?= e(t('admin.anti_fraud.zdarzenia')) ?></th><th><?= e(t('admin.anti_fraud.ostatnie')) ?></th></tr></thead>
        <tbody>
          <?php foreach ($risk_users as $u): ?>
            <tr class="<?= e($scoreClass($u['risk_score'] ?? 0)) ?>">
              <td><strong class="admin-user-name"><?= e($u['display_name'] ?? '—') ?></strong><span class="admin-user-email"><?= e($u['email'] ?? '') ?></span></td>
              <td><strong><?= (int)($u['risk_score'] ?? 0) ?></strong></td>
              <td><?= (int)($u['events_count'] ?? 0) ?></td>
              <td><span class="admin-note"><?= e((string)($u['last_event_at'] ?? '—')) ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<section class="admin-panel-block">
  <div class="admin-section-head">
    <div><p class="kicker"><?= e(t('admin.anti_fraud.zdarzenia')) ?></p><h2><?= e(t('admin.anti_fraud.ostatnie_alerty_antyfraudowe')) ?></h2></div>
  </div>

  <?php if (empty($events)): ?>
    <div class="empty-state"><h3><?= e(t('admin.anti_fraud.brak_zdarzen')) ?></h3><p><?= e(t('admin.anti_fraud.po_reklamach_ankietach_skanach_i_probach_wypat_pojawia_f70dfbf6')) ?></p></div>
  <?php else: ?>
    <div class="admin-table-wrap">
      <table class="admin-table admin-table-wide">
        <thead><tr><th><?= e(t('admin.anti_fraud.czas')) ?></th><th><?= e(t('wallet.orders.table.user')) ?></th><th><?= e(t('admin.anti_fraud.akcja')) ?></th><th><?= e(t('admin.anti_fraud.risk')) ?></th><th><?= e(t('wallet.history.table.status')) ?></th><th><?= e(t('admin.anti_fraud.powody')) ?></th></tr></thead>
        <tbody>
          <?php foreach ($events as $eRow): ?>
            <?php $reasons = json_decode((string)($eRow['reasons_json'] ?? '[]'), true) ?: []; ?>
            <tr class="<?= e($scoreClass($eRow['risk_score'] ?? 0)) ?>">
              <td><span class="admin-note"><?= e((string)$eRow['created_at']) ?></span></td>
              <td><strong><?= e($eRow['display_name'] ?? '—') ?></strong><span class="admin-user-email"><?= e($eRow['email'] ?? '') ?></span></td>
              <td><strong><?= e($eRow['event_type']) ?></strong><span class="admin-note"><?= e(trim(($eRow['reference_type'] ?? '') . ' #' . ($eRow['reference_id'] ?? ''))) ?></span></td>
              <td><strong><?= (int)$eRow['risk_score'] ?></strong></td>
              <td><span class="status-pill status-<?= e($eRow['status']) ?>"><?= e($statusLabels[$eRow['status']] ?? $eRow['status']) ?></span></td>
              <td><span class="admin-note"><?= e($reasons ? implode(' / ', $reasons) : '—') ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

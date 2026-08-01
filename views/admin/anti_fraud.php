<?php
$statusLabels = [
    'normal' => 'NORMAL',
    'observe' => 'OBSERWUJ',
    'suspect' => 'PODEJRZANY',
    'hold_payout' => 'WSTRZYMAJ WYPŁATĘ',
];
$scoreClass = static function($score): string {
    $score = (int)$score;
    if ($score >= 80) return 'is-red';
    if ($score >= 60) return 'is-alert';
    return '';
};
?>
<section class="admin-page-head">
  <p class="kicker">SNAJPER SŁOWA / STRAŻNIK</p>
  <h1><?= function_exists('zs_icon') ? zs_icon('shield', 'zs-title-icon') : '' ?>ANTYFRAUD</h1>
  <p>Kontrola reklam, ankiet, bonusów i wypłat. Ten panel nie usuwa automatycznie użytkowników — oznacza ryzyko, blokuje podejrzane wypłaty do kontroli i zostawia ślad.</p>
</section>

<?php if (!empty($flash_success)): ?><div class="notice success"><?= e($flash_success) ?></div><?php endif; ?>
<?php if (!empty($flash_error)): ?><div class="notice error"><?= e($flash_error) ?></div><?php endif; ?>

<section class="settlement-grid">
  <div class="settlement-card zs-metric-card">
    <?= function_exists('zs_icon') ? zs_icon('warning') : '' ?>
    <span>ZDARZENIA 24H</span>
    <strong><?= (int)($summary['events_24h'] ?? 0) ?></strong>
    <small>wszystkie wpisy strażnika</small>
  </div>
  <div class="settlement-card is-red zs-metric-card">
    <?= function_exists('zs_icon') ? zs_icon('shield') : '' ?>
    <span>PODEJRZANE 24H</span>
    <strong><?= (int)($summary['suspect_24h'] ?? 0) ?></strong>
    <small>suspect + hold_payout</small>
  </div>
  <div class="settlement-card zs-metric-card">
    <?= function_exists('zs_icon') ? zs_icon('payout') : '' ?>
    <span>WSTRZYMANE 30D</span>
    <strong><?= (int)($summary['held_payouts_30d'] ?? 0) ?></strong>
    <small>wypłaty do kontroli</small>
  </div>
  <div class="settlement-card zs-metric-card">
    <?= function_exists('zs_icon') ? zs_icon('snajper') : '' ?>
    <span>MAX RISK 30D</span>
    <strong><?= (int)($summary['max_risk_30d'] ?? 0) ?></strong>
    <small>najwyższy risk_score</small>
  </div>
</section>

<section class="admin-panel-block">
  <div class="admin-section-head">
    <div>
      <p class="kicker">Skan</p>
      <h2>Ręczne sprawdzenie bazy</h2>
    </div>
    <form method="post" action="/admin/anti-fraud/scan" class="inline">
      <?= csrf_field() ?>
      <button class="btn-red compact" type="submit">Uruchom skan</button>
    </form>
  </div>
  <p class="admin-note">Skan analizuje ostatnie bonusy, reklamy, ankiety i wypłaty. Wyniki zapisuje w <code>fraud_events</code>.</p>
</section>

<section class="admin-panel-block">
  <div class="admin-section-head">
    <div><p class="kicker">Użytkownicy</p><h2>Najwyższe ryzyko</h2></div>
    <span>LIMIT <?= (int)$snajper_limit ?></span>
  </div>

  <?php if (empty($risk_users)): ?>
    <div class="empty-state"><h3>Brak użytkowników oznaczonych ryzykiem.</h3><p>SNAJPER nie wykrył jeszcze zdarzeń wymagających kontroli.</p></div>
  <?php else: ?>
    <div class="admin-table-wrap">
      <table class="admin-table admin-table-wide">
        <thead><tr><th>Użytkownik</th><th>Risk</th><th>Zdarzenia</th><th>Ostatnie</th></tr></thead>
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
    <div><p class="kicker">Zdarzenia</p><h2>Ostatnie alerty antyfraudowe</h2></div>
  </div>

  <?php if (empty($events)): ?>
    <div class="empty-state"><h3>Brak zdarzeń.</h3><p>Po reklamach, ankietach, skanach i próbach wypłat pojawią się tu wpisy.</p></div>
  <?php else: ?>
    <div class="admin-table-wrap">
      <table class="admin-table admin-table-wide">
        <thead><tr><th>Czas</th><th>Użytkownik</th><th>Akcja</th><th>Risk</th><th>Status</th><th>Powody</th></tr></thead>
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

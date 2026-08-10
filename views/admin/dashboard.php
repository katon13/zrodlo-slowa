<div class="zs-operator-page zs-dashboard-operator-page">
<section class="admin-page-head">
  <p class="kicker">Panel administracyjny</p>
  <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 5px;">
    <h1 style="margin: 0;">Admin</h1>
    <button id="clear-cache-btn" style="border: 1px solid #e2e8f0; background: #fff; color: #64748b; padding: 4px 12px; border-radius: 4px; font-size: 13px; cursor: pointer; transition: all 0.2s;">
        Wyczyść cache strony
    </button>
    <span id="cache-status" style="font-size: 13px; font-weight: 500;"></span>
  </div>
  <p>Zarządzanie redakcją, użytkownikami, płatnościami i ustawieniami systemu.</p>
</section>

<section class="admin-grid">
  <a class="admin-card zs-admin-card is-highlight" href="/admin/articles">
    <?= function_exists('zs_icon') ? zs_icon('editorial') : '' ?>
    <span>REDAKCJA GŁÓWNA</span>
    <small>Przyjęcie materiału i skierowanie tekstu do dalszej pracy</small>
  </a>

  <a class="admin-card zs-admin-card is-highlight" href="/admin/editorial">
    <?= function_exists('zs_icon') ? zs_icon('editorial') : '' ?>
    <span>WYDAWCA</span>
    <small>Kolejność, ważność, edycja tekstów i praca wydawnicza</small>
  </a>

  <a class="admin-card zs-admin-card is-highlight" href="/admin/role-panel?panel=moderator">
    <?= function_exists('zs_icon') ? zs_icon('editorial') : '' ?>
    <span>MODERATOR</span>
    <small>Cena, premium, dostęp, promocja i zgoda na AI</small>
  </a>


  <a class="admin-card zs-admin-card is-highlight" href="/admin/role-panel?panel=proofreader">
    <?= function_exists('zs_icon') ? zs_icon('editorial') : '' ?>
    <span>KOREKTA</span>
    <small>Korekta tytułu, leadu i treści tekstu</small>
  </a>

  <a class="admin-card zs-admin-card<?= !empty($pending_authors_count) ? ' is-alert' : '' ?>" href="/admin/users">
    <?= function_exists('zs_icon') ? zs_icon('author') : '' ?>
    <span>UŻYTKOWNICY</span>
    <?php if (!empty($pending_authors_count)): ?>
      <b class="admin-card-badge"><?= (int)$pending_authors_count ?></b>
      <small>Autorzy do zatwierdzenia</small>
    <?php else: ?>
      <small>Role, statusy, autorzy</small>
    <?php endif; ?>
  </a>

  <a class="admin-card zs-admin-card" href="/admin/categories">
    <?= function_exists('zs_icon') ? zs_icon('article') : '' ?>
    <span>KATEGORIE</span>
    <small>Dziedziny i sekcje</small>
  </a>

  <a class="admin-card zs-admin-card" href="/admin/campaigns">
    <?= function_exists('zs_icon') ? zs_icon('ad') : '' ?>
    <span>KAMPANIE I ZAANGAŻOWANIE</span>
    <small>Banery, filmy, artykuły sponsorowane, ankiety i zgłoszenia</small>
  </a>

  <a class="admin-card zs-admin-card" href="/admin/payouts">
    <?= function_exists('zs_icon') ? zs_icon('payout') : '' ?>
    <span>WYPŁATY</span>
    <small>Rozliczenia autorów</small>
  </a>

  <a class="admin-card zs-admin-card is-highlight" href="/admin/safety-fund">
    <?= function_exists('zs_icon') ? zs_icon('shield') : '' ?>
    <span><?= e(t('safety_fund.admin.dashboard_title', 'pl')) ?></span>
    <small><?= e(t('safety_fund.admin.dashboard_description', 'pl')) ?></small>
  </a>

  <a class="admin-card zs-admin-card" href="/admin/payments">
    <?= function_exists('zs_icon') ? zs_icon('wallet') : '' ?>
    <span>PŁATNOŚCI</span>
    <small>Wpływy i transakcje</small>
  </a>

  <a class="admin-card zs-admin-card" href="/admin/ai">
    <?= function_exists('zs_icon') ? zs_icon('snajper') : '' ?>
    <span>AI REDAKCYJNE</span>
    <small>Tłumaczenia, historia pracy i limity kosztów</small>
  </a>

  <a class="admin-card zs-admin-card" href="/admin/ledger">
    <?= function_exists('zs_icon') ? zs_icon('history') : '' ?>
    <span>DZIENNIK PORTFELI</span>
    <small>Historia operacji finansowych</small>
  </a>

  <a class="admin-card zs-admin-card" href="/admin/mails">
    <?= function_exists('zs_icon') ? zs_icon('mail') : '' ?>
    <span>WYSYŁKA WIADOMOŚCI</span>
    <small>Kolejki i doręczenia systemowe</small>
  </a>

  <a class="admin-card zs-admin-card" href="/admin/finance">
    <?= function_exists('zs_icon') ? zs_icon('finance') : '' ?>
    <span>RAPORT FINANSOWY</span>
    <small>Premium, portfele, raporty</small>
  </a>

  <a class="admin-card zs-admin-card" href="/admin/finance/approvals" style="position:relative;">
    <?= function_exists('zs_icon') ? zs_icon('payout') : '' ?>
    <span>ZATWIERDZENIA FINANSOWE</span>
    <small>Dwuosobowa kontrola decyzji</small>
    <?php if (($pending_approvals_count ?? 0) > 0): ?>
        <span style="position:absolute; top:-5px; right:-5px; background:var(--zs-danger, #dc3545); color:white; border-radius:12px; padding:2px 8px; font-size:11px; font-weight:bold; box-shadow: 0 2px 4px rgba(0,0,0,0.2);"><?= (int)$pending_approvals_count ?></span>
    <?php endif; ?>
  </a>

  <a class="admin-card zs-admin-card" href="/admin/anti-fraud">
    <?= function_exists('zs_icon') ? zs_icon('shield') : '' ?>
    <span>KONTROLA RYZYKA</span>
    <small>Reklamy, ankiety, bonusy, wypłaty</small>
  </a>

  <a class="admin-card zs-admin-card" href="/admin/settings">
    <?= function_exists('zs_icon') ? zs_icon('admin') : '' ?>
    <span>USTAWIENIA I TALENT</span>
    <small>Reguły punktowe</small>
  </a>

  <a class="admin-card zs-admin-card" href="/admin/roles">
    <?= function_exists('zs_icon') ? zs_icon('editorial') : '' ?>
    <span>ROLE I UPRAWNIENIA</span>
    <small>Naczelny, Edytor, Wydawca, Korektor, Księgowy</small>
  </a>

  <a class="admin-card zs-admin-card" href="/admin/settings#slowo-snajper">
    <?= function_exists('zs_icon') ? zs_icon('snajper') : '' ?>
    <span>SNAJPER SŁOWA</span>
    <small><?= !empty($slowo_snajper['enabled']) ? 'Aktywny: limity i audyt' : 'Wyłączony w konfiguracji' ?></small>
  </a>

  <a class="admin-card zs-admin-card is-highlight" href="/admin/security/3dors">
    <?= function_exists('zs_icon') ? zs_icon('shield') : '' ?>
    <span>BEZPIECZEŃSTWO I 3DORS</span>
    <small>Konto administratora, 2FA, urządzenia, recovery i konfiguracja ochrony</small>
  </a>

  <a class="admin-card zs-admin-card is-highlight<?= !empty($sentinel_open_alerts) ? ' is-alert' : '' ?>" href="/admin/security/sentinel">
    <?= function_exists('zs_icon') ? zs_icon('history') : '' ?>
    <span><?= e(\App\Services\Dors3UiText::get('sentinel.card_title')) ?></span>
    <small><?= e(\App\Services\Dors3UiText::get('sentinel.card_description')) ?></small>
    <?php if (!empty($sentinel_open_alerts)): ?><b class="admin-card-badge"><?= (int)$sentinel_open_alerts ?></b><?php endif; ?>
  </a>

  <a class="admin-card zs-admin-card is-highlight" href="/admin/main-banner">
    <?= function_exists('zs_icon') ? zs_icon('article') : '' ?>
    <span>BANER GŁÓWNY</span>
    <small>Stała treść górnego baneru strony głównej</small>
  </a>
</section>

<?php
$diagnostics = is_array($earnings_diagnostics ?? null) ? $earnings_diagnostics : [];
$earningsWorker = is_array($diagnostics['earnings_worker'] ?? null) ? $diagnostics['earnings_worker'] : [];
$notificationsWorker = is_array($diagnostics['notifications_worker'] ?? null) ? $diagnostics['notifications_worker'] : [];
$earningsState = is_array($earningsWorker['state'] ?? null) ? $earningsWorker['state'] : [];
$notificationsState = is_array($notificationsWorker['state'] ?? null) ? $notificationsWorker['state'] : [];
$earningsMetrics = is_array($earningsState['metrics'] ?? null) ? $earningsState['metrics'] : [];
$notificationsMetrics = is_array($notificationsState['metrics'] ?? null) ? $notificationsState['metrics'] : [];
$latency = is_array($diagnostics['latency_ms'] ?? null) ? $diagnostics['latency_ms'] : [];
$queueStats = is_array($diagnostics['queues'] ?? null) ? $diagnostics['queues'] : [];
$decisionStats = is_array($diagnostics['decisions'] ?? null) ? $diagnostics['decisions'] : [];
$ruleStats = is_array($diagnostics['rules'] ?? null) ? $diagnostics['rules'] : [];
$queueLabels = [
    'earnings.critical' => ['Naliczanie nagród', 'Operacje zmieniające saldo użytkownika'],
    'earnings.notifications' => ['Powiadomienia o nagrodach', 'Komunikaty wysyłane po naliczeniu'],
];
$decisionLabels = [
    'inactive_rule' => 'Reguła jest wyłączona',
    'anonymous_user' => 'Użytkownik nie jest zalogowany',
    'user_offline' => 'Użytkownik nie jest już aktywny',
    'daily_limit' => 'Osiągnięto limit dzienny',
    'duplicate' => 'Zdarzenie zostało już obsłużone',
    'fraud_risk' => 'Zdarzenie zatrzymał antyfraud',
];
$formatAge = static function (mixed $seconds): string {
    return is_int($seconds) || ctype_digit((string)$seconds) ? (int)$seconds . ' s temu' : 'brak danych';
};
?>
<section class="admin-panel-block zs-earnings-diagnostics">
  <div class="zs-earnings-head">
    <div>
      <p class="kicker">SNAJPER SŁOWA · DIAGNOSTYKA ZAROBKÓW</p>
      <h2>Kolejki, decyzje i opóźnienia</h2>
      <p>Kontrola pracy mechanizmu nagród. Ten widok nie aktywuje reguł i nie zmienia żadnego salda.</p>
    </div>
    <div class="zs-earnings-overall <?= !empty($earningsWorker['healthy']) ? 'is-ready' : 'is-warning' ?>">
      <span>Status naliczania</span>
      <strong><?= !empty($earningsWorker['healthy']) ? 'SYSTEM DZIAŁA' : 'WYMAGA KONTROLI' ?></strong>
      <small><?= !empty($earningsWorker['healthy']) ? 'Ostatni sygnał odebrano prawidłowo' : 'Brak aktualnego sygnału workera' ?></small>
    </div>
  </div>

  <div class="zs-earnings-metrics">
    <article class="zs-earnings-metric <?= !empty($earningsWorker['healthy']) ? 'is-ready' : 'is-warning' ?>">
      <span>NALICZANIE NAGRÓD</span>
      <strong><?= !empty($earningsWorker['healthy']) ? 'OK' : 'UWAGA' ?></strong>
      <small>Ostatni sygnał: <?= e($formatAge($earningsWorker['heartbeat_age_seconds'] ?? null)) ?></small>
      <small>Serie zdarzeń: <?= (int)($earningsMetrics['signal_batches'] ?? 0) ?> · kontrole: <?= (int)($earningsMetrics['safety_sweeps'] ?? 0) ?></small>
    </article>
    <article class="zs-earnings-metric <?= !empty($notificationsWorker['healthy']) ? 'is-ready' : 'is-warning' ?>">
      <span>POWIADOMIENIA</span>
      <strong><?= !empty($notificationsWorker['healthy']) ? 'OK' : 'UWAGA' ?></strong>
      <small>Ostatni sygnał: <?= e($formatAge($notificationsWorker['heartbeat_age_seconds'] ?? null)) ?></small>
      <small>Serie zdarzeń: <?= (int)($notificationsMetrics['signal_batches'] ?? 0) ?> · kontrole: <?= (int)($notificationsMetrics['safety_sweeps'] ?? 0) ?></small>
    </article>
    <article class="zs-earnings-metric">
      <span>CZAS OBSŁUGI / CEL</span>
      <strong><?= isset($latency['p95']) ? (int)$latency['p95'] . ' ms' : '—' ?></strong>
      <small>95% zadań kończy się w tym czasie</small>
      <small>Limit operatorski: <?= isset($latency['target']) ? (int)$latency['target'] . ' ms' : '—' ?></small>
    </article>
    <article class="zs-earnings-metric">
      <span>REGUŁY NAGRÓD</span>
      <strong><?= (int)($ruleStats['active'] ?? 0) ?> / <?= (int)($ruleStats['total'] ?? 0) ?></strong>
      <small>Aktywne / wszystkie</small>
      <small>Bez ustalonej wartości: <?= (int)($ruleStats['active_zero_value'] ?? 0) ?></small>
    </article>
  </div>

  <div class="zs-earnings-detail-grid">
    <section class="zs-earnings-detail-card">
      <div class="zs-earnings-detail-head"><div><span>Przepływ zadań</span><h3>Stany kolejek</h3></div><small>na żywo</small></div>
      <div class="admin-table-wrap">
      <table class="admin-table zs-earnings-table">
        <thead><tr><th>Obszar</th><th>Oczekuje</th><th>Ponowienie</th><th>Zatrzymane</th></tr></thead>
        <tbody>
        <?php foreach ($queueLabels as $queueName => $queueCopy): $row = is_array($queueStats[$queueName] ?? null) ? $queueStats[$queueName] : []; ?>
          <tr>
            <td><strong><?= e($queueCopy[0]) ?></strong><small><?= e($queueCopy[1]) ?></small></td>
            <td><?= (int)($row['queued'] ?? 0) ?></td>
            <td><?= (int)($row['retry'] ?? 0) ?></td>
            <td><?= (int)($row['dead_letter'] ?? 0) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </section>
    <section class="zs-earnings-detail-card">
      <div class="zs-earnings-detail-head"><div><span>Kontrola reguł</span><h3>Decyzje z ostatnich 24 godzin</h3></div><small><?= array_sum(array_map('intval', $decisionStats)) ?> zdarzeń</small></div>
      <?php if ($decisionStats === []): ?>
        <div class="zs-earnings-empty"><strong>Brak decyzji w tym okresie</strong><span>System nie zarejestrował zakończonych ocen reguł.</span></div>
      <?php else: ?>
        <div class="admin-table-wrap">
        <table class="admin-table zs-earnings-table">
          <thead><tr><th>Powód</th><th>Liczba</th></tr></thead>
          <tbody>
          <?php arsort($decisionStats); foreach ($decisionStats as $reason => $total): ?>
            <tr><td><strong><?= e($decisionLabels[(string)$reason] ?? (string)$reason) ?></strong></td><td><?= (int)$total ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php endif; ?>
    </section>
  </div>
</section>

</div>

<script>
document.getElementById('clear-cache-btn').addEventListener('click', function() {
    const btn = this;
    const status = document.getElementById('cache-status');
    const originalText = btn.innerText;

    btn.disabled = true;
    btn.innerText = 'Czyszczenie...';
    status.innerText = '';
    status.style.display = 'none';

    fetch('/admin/cache/clear', {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
        }
    })
    .then(response => response.json())
    .then(data => {
        btn.disabled = false;
        btn.innerText = originalText;
        status.innerText = data.message;
        status.style.display = 'inline';
        status.style.color = data.success ? '#2f855a' : '#c53030';
        
        if (data.success) {
            setTimeout(() => {
                status.style.opacity = '0';
                setTimeout(() => {
                    status.style.display = 'none';
                    status.style.opacity = '1';
                }, 500);
            }, 3000);
        }
    })
    .catch(error => {
        btn.disabled = false;
        btn.innerText = originalText;
        status.innerText = 'Wystąpił błąd podczas czyszczenia cache.';
        status.style.display = 'inline';
        status.style.color = '#c53030';
    });
});
</script>

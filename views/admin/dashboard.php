<div class="zs-operator-page zs-dashboard-operator-page">
<section class="admin-page-head">
  <p class="kicker"><?= e(t('admin.dashboard.panel_administracyjny')) ?></p>
  <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 5px;">
    <h1 style="margin: 0;"><?= e(t('layout.menu.admin')) ?></h1>
    <button id="clear-cache-btn" style="border: 1px solid #e2e8f0; background: #fff; color: #64748b; padding: 4px 12px; border-radius: 4px; font-size: 13px; cursor: pointer; transition: all 0.2s;">
        <?= e(t('admin.dashboard.wyczysc_cache_strony')) ?>
    </button>
    <span id="cache-status" style="font-size: 13px; font-weight: 500;"></span>
  </div>
  <p><?= e(t('admin.dashboard.zarzadzanie_redakcja_uzytkownikami_patnosciami_i_ustawi_54eeb5a7')) ?></p>
</section>

<section class="admin-grid">
  <a class="admin-card zs-admin-card is-highlight" href="/admin/articles">
    <?= function_exists('zs_icon') ? zs_icon('editorial') : '' ?>
    <span><?= e(t('admin.articles.redakcja_gowna')) ?></span>
    <small><?= e(t('admin.dashboard.przyjecie_materiau_i_skierowanie_tekstu_do_dalszej_pracy')) ?></small>
  </a>

  <a class="admin-card zs-admin-card is-highlight" href="/admin/editorial">
    <?= function_exists('zs_icon') ? zs_icon('editorial') : '' ?>
    <span><?= e(t('admin.dashboard.wydawca')) ?></span>
    <small><?= e(t('admin.dashboard.kolejnosc_waznosc_edycja_tekstow_i_praca_wydawnicza')) ?></small>
  </a>

  <a class="admin-card zs-admin-card is-highlight" href="/admin/role-panel?panel=moderator">
    <?= function_exists('zs_icon') ? zs_icon('editorial') : '' ?>
    <span><?= e(t('admin.dashboard.moderator')) ?></span>
    <small><?= e(t('admin.dashboard.cena_premium_dostep_promocja_i_zgoda_na_ai')) ?></small>
  </a>


  <a class="admin-card zs-admin-card is-highlight" href="/admin/role-panel?panel=proofreader">
    <?= function_exists('zs_icon') ? zs_icon('editorial') : '' ?>
    <span><?= e(t('admin.dashboard.korekta')) ?></span>
    <small><?= e(t('admin.dashboard.korekta_tytuu_leadu_i_tresci_tekstu')) ?></small>
  </a>

  <a class="admin-card zs-admin-card<?= !empty($pending_authors_count) ? ' is-alert' : '' ?>" href="/admin/users">
    <?= function_exists('zs_icon') ? zs_icon('author') : '' ?>
    <span><?= e(t('admin.dashboard.uzytkownicy')) ?></span>
    <?php if (!empty($pending_authors_count)): ?>
      <b class="admin-card-badge"><?= (int)$pending_authors_count ?></b>
      <small><?= e(t('admin.dashboard.autorzy_do_zatwierdzenia')) ?></small>
    <?php else: ?>
      <small><?= e(t('admin.dashboard.role_statusy_autorzy')) ?></small>
    <?php endif; ?>
  </a>

  <a class="admin-card zs-admin-card" href="/admin/categories">
    <?= function_exists('zs_icon') ? zs_icon('article') : '' ?>
    <span><?= e(t('admin.dashboard.kategorie')) ?></span>
    <small><?= e(t('admin.dashboard.dziedziny_i_sekcje')) ?></small>
  </a>

  <a class="admin-card zs-admin-card" href="/admin/campaigns">
    <?= function_exists('zs_icon') ? zs_icon('ad') : '' ?>
    <span><?= e(t('admin.dashboard.kampanie_i_zaangazowanie')) ?></span>
    <small><?= e(t('admin.dashboard.banery_filmy_artykuy_sponsorowane_ankiety_i_zgoszenia')) ?></small>
  </a>

  <a class="admin-card zs-admin-card" href="/admin/payouts">
    <?= function_exists('zs_icon') ? zs_icon('payout') : '' ?>
    <span><?= e(t('admin.dashboard.wypaty')) ?></span>
    <small><?= e(t('admin.dashboard.rozliczenia_autorow')) ?></small>
  </a>

  <a class="admin-card zs-admin-card is-highlight" href="/admin/safety-fund">
    <?= function_exists('zs_icon') ? zs_icon('shield') : '' ?>
    <span><?= e(t('safety_fund.admin.dashboard_title', 'pl')) ?></span>
    <small><?= e(t('safety_fund.admin.dashboard_description', 'pl')) ?></small>
  </a>

  <a class="admin-card zs-admin-card" href="/admin/payments">
    <?= function_exists('zs_icon') ? zs_icon('wallet') : '' ?>
    <span><?= e(t('admin.dashboard.patnosci')) ?></span>
    <small><?= e(t('admin.dashboard.wpywy_i_transakcje')) ?></small>
  </a>

  <a class="admin-card zs-admin-card" href="/admin/ai">
    <?= function_exists('zs_icon') ? zs_icon('snajper') : '' ?>
    <span><?= e(t('admin.dashboard.ai_redakcyjne')) ?></span>
    <small><?= e(t('admin.dashboard.tumaczenia_historia_pracy_i_limity_kosztow')) ?></small>
  </a>

  <a class="admin-card zs-admin-card" href="/admin/ledger">
    <?= function_exists('zs_icon') ? zs_icon('history') : '' ?>
    <span><?= e(t('admin.dashboard.dziennik_portfeli')) ?></span>
    <small><?= e(t('admin.dashboard.historia_operacji_finansowych')) ?></small>
  </a>

  <a class="admin-card zs-admin-card" href="/admin/mails">
    <?= function_exists('zs_icon') ? zs_icon('mail') : '' ?>
    <span><?= e(t('admin.dashboard.wysyka_wiadomosci')) ?></span>
    <small><?= e(t('admin.dashboard.kolejki_i_doreczenia_systemowe')) ?></small>
  </a>

  <a class="admin-card zs-admin-card" href="/admin/finance">
    <?= function_exists('zs_icon') ? zs_icon('finance') : '' ?>
    <span><?= e(t('admin.dashboard.raport_finansowy')) ?></span>
    <small><?= e(t('admin.dashboard.premium_portfele_raporty')) ?></small>
  </a>

  <a class="admin-card zs-admin-card" href="/admin/finance/approvals" style="position:relative;">
    <?= function_exists('zs_icon') ? zs_icon('payout') : '' ?>
    <span><?= e(t('admin.dashboard.zatwierdzenia_finansowe')) ?></span>
    <small><?= e(t('admin.dashboard.dwuosobowa_kontrola_decyzji')) ?></small>
    <?php if (($pending_approvals_count ?? 0) > 0): ?>
        <span style="position:absolute; top:-5px; right:-5px; background:var(--zs-danger, #dc3545); color:white; border-radius:12px; padding:2px 8px; font-size:11px; font-weight:bold; box-shadow: 0 2px 4px rgba(0,0,0,0.2);"><?= (int)$pending_approvals_count ?></span>
    <?php endif; ?>
  </a>

  <a class="admin-card zs-admin-card" href="/admin/anti-fraud">
    <?= function_exists('zs_icon') ? zs_icon('shield') : '' ?>
    <span><?= e(t('admin.dashboard.kontrola_ryzyka')) ?></span>
    <small><?= e(t('admin.dashboard.reklamy_ankiety_bonusy_wypaty')) ?></small>
  </a>

  <a class="admin-card zs-admin-card" href="/admin/settings">
    <?= function_exists('zs_icon') ? zs_icon('admin') : '' ?>
    <span><?= e(t('admin.dashboard.ustawienia_i_talent')) ?></span>
    <small><?= e(t('admin.dashboard.reguy_punktowe')) ?></small>
  </a>

  <a class="admin-card zs-admin-card" href="/admin/roles">
    <?= function_exists('zs_icon') ? zs_icon('editorial') : '' ?>
    <span><?= e(t('admin.dashboard.role_i_uprawnienia')) ?></span>
    <small><?= e(t('admin.dashboard.naczelny_edytor_wydawca_korektor_ksiegowy')) ?></small>
  </a>

  <a class="admin-card zs-admin-card" href="/admin/settings#slowo-snajper">
    <?= function_exists('zs_icon') ? zs_icon('snajper') : '' ?>
    <span><?= e(t('admin.dashboard.snajper_sowa')) ?></span>
    <small><?= !empty($slowo_snajper['enabled']) ? 'Aktywny: limity i audyt' : t('admin.dashboard.wyaczony_w_konfiguracji') ?></small>
  </a>

  <a class="admin-card zs-admin-card is-highlight" href="/admin/security/3dors">
    <?= function_exists('zs_icon') ? zs_icon('shield') : '' ?>
    <span><?= e(t('admin.dashboard.bezpieczenstwo_i_3dors')) ?></span>
    <small><?= e(t('admin.dashboard.konto_administratora_2fa_urzadzenia_recovery_i_konfigur_8b8fe546')) ?></small>
  </a>

  <a class="admin-card zs-admin-card is-highlight<?= !empty($sentinel_open_alerts) ? ' is-alert' : '' ?>" href="/admin/security/sentinel">
    <?= function_exists('zs_icon') ? zs_icon('history') : '' ?>
    <span><?= e(\App\Services\Dors3UiText::get('sentinel.card_title')) ?></span>
    <small><?= e(\App\Services\Dors3UiText::get('sentinel.card_description')) ?></small>
    <?php if (!empty($sentinel_open_alerts)): ?><b class="admin-card-badge"><?= (int)$sentinel_open_alerts ?></b><?php endif; ?>
  </a>

  <a class="admin-card zs-admin-card is-highlight" href="/admin/main-banner">
    <?= function_exists('zs_icon') ? zs_icon('article') : '' ?>
    <span><?= e(t('admin.dashboard.baner_gowny')) ?></span>
    <small><?= e(t('admin.dashboard.staa_tresc_gornego_baneru_strony_gownej')) ?></small>
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
    'earnings.critical' => [t('admin.dashboard.naliczanie_nagrod_2'), t('admin.dashboard.operacje_zmieniajace_saldo_uzytkownika')],
    'earnings.notifications' => ['Powiadomienia o nagrodach', t('admin.dashboard.komunikaty_wysyane_po_naliczeniu')],
];
$decisionLabels = [
    'inactive_rule' => t('admin.dashboard.regua_jest_wyaczona'),
    'anonymous_user' => t('admin.dashboard.uzytkownik_nie_jest_zalogowany'),
    'user_offline' => t('admin.dashboard.uzytkownik_nie_jest_juz_aktywny'),
    'daily_limit' => t('admin.dashboard.osiagnieto_limit_dzienny'),
    'duplicate' => t('admin.dashboard.zdarzenie_zostao_juz_obsuzone'),
    'fraud_risk' => t('admin.dashboard.zdarzenie_zatrzyma_antyfraud'),
];
$formatAge = static function (mixed $seconds): string {
    return is_int($seconds) || ctype_digit((string)$seconds)
        ? str_replace('{seconds}', (string)(int)$seconds, t('admin.dashboard.seconds_ago'))
        : t('admin.common.no_data');
};
?>
<section class="admin-panel-block zs-earnings-diagnostics">
  <div class="zs-earnings-head">
    <div>
      <p class="kicker"><?= e(t('admin.dashboard.snajper_sowa_diagnostyka_zarobkow')) ?></p>
      <h2><?= e(t('admin.dashboard.kolejki_decyzje_i_opoznienia')) ?></h2>
      <p><?= e(t('admin.dashboard.kontrola_pracy_mechanizmu_nagrod_ten_widok_nie_aktywuje_09ebec63')) ?></p>
    </div>
    <div class="zs-earnings-overall <?= !empty($earningsWorker['healthy']) ? 'is-ready' : 'is-warning' ?>">
      <span><?= e(t('admin.dashboard.status_naliczania')) ?></span>
      <strong><?= e(!empty($earningsWorker['healthy']) ? t('admin.dashboard.system_dziaa') : t('admin.dashboard.requires_attention')) ?></strong>
      <small><?= e(!empty($earningsWorker['healthy']) ? t('admin.dashboard.ostatni_sygna_odebrano_prawidowo') : t('admin.dashboard.no_current_processing_signal')) ?></small>
    </div>
  </div>

  <div class="zs-earnings-metrics">
    <article class="zs-earnings-metric <?= !empty($earningsWorker['healthy']) ? 'is-ready' : 'is-warning' ?>">
      <span><?= e(t('admin.dashboard.naliczanie_nagrod')) ?></span>
      <strong><?= e(!empty($earningsWorker['healthy']) ? t('admin.common.ok') : t('admin.common.attention')) ?></strong>
      <small><?= e(str_replace('{age}', $formatAge($earningsWorker['heartbeat_age_seconds'] ?? null), t('admin.dashboard.last_signal'))) ?></small>
      <small><?= e(str_replace(['{events}','{checks}'], [(string)(int)($earningsMetrics['signal_batches'] ?? 0),(string)(int)($earningsMetrics['safety_sweeps'] ?? 0)], t('admin.dashboard.event_batches_and_checks'))) ?></small>
    </article>
    <article class="zs-earnings-metric <?= !empty($notificationsWorker['healthy']) ? 'is-ready' : 'is-warning' ?>">
      <span><?= e(t('admin.dashboard.powiadomienia')) ?></span>
      <strong><?= e(!empty($notificationsWorker['healthy']) ? t('admin.common.ok') : t('admin.common.attention')) ?></strong>
      <small><?= e(str_replace('{age}', $formatAge($notificationsWorker['heartbeat_age_seconds'] ?? null), t('admin.dashboard.last_signal'))) ?></small>
      <small><?= e(str_replace(['{events}','{checks}'], [(string)(int)($notificationsMetrics['signal_batches'] ?? 0),(string)(int)($notificationsMetrics['safety_sweeps'] ?? 0)], t('admin.dashboard.event_batches_and_checks'))) ?></small>
    </article>
    <article class="zs-earnings-metric">
      <span><?= e(t('admin.dashboard.czas_obsugi_cel')) ?></span>
      <strong><?= isset($latency['p95']) ? (int)$latency['p95'] . ' ms' : '—' ?></strong>
      <small><?= e(t('admin.dashboard.95_zadan_konczy_sie_w_tym_czasie')) ?></small>
      <small><?= e(str_replace('{value}', isset($latency['target']) ? (int)$latency['target'] . ' ms' : '—', t('admin.dashboard.operator_limit'))) ?></small>
    </article>
    <article class="zs-earnings-metric">
      <span><?= e(t('admin.dashboard.reguy_nagrod')) ?></span>
      <strong><?= (int)($ruleStats['active'] ?? 0) ?> / <?= (int)($ruleStats['total'] ?? 0) ?></strong>
      <small><?= e(t('admin.dashboard.aktywne_wszystkie')) ?></small>
      <small><?= e(str_replace('{count}', (string)(int)($ruleStats['active_zero_value'] ?? 0), t('admin.dashboard.rules_without_value'))) ?></small>
    </article>
  </div>

  <div class="zs-earnings-detail-grid">
    <section class="zs-earnings-detail-card">
      <div class="zs-earnings-detail-head"><div><span><?= e(t('admin.dashboard.przepyw_zadan')) ?></span><h3><?= e(t('admin.dashboard.stany_kolejek')) ?></h3></div><small><?= e(t('admin.dashboard.na_zywo')) ?></small></div>
      <div class="admin-table-wrap">
      <table class="admin-table zs-earnings-table">
        <thead><tr><th><?= e(t('admin.dashboard.obszar')) ?></th><th><?= e(t('wallet.status.pending')) ?></th><th><?= e(t('admin.dashboard.ponowienie')) ?></th><th><?= e(t('admin.dashboard.zatrzymane')) ?></th></tr></thead>
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
      <div class="zs-earnings-detail-head"><div><span><?= e(t('admin.dashboard.kontrola_regu')) ?></span><h3><?= e(t('admin.dashboard.decyzje_z_ostatnich_24_godzin')) ?></h3></div><small><?= e(str_replace('{count}', (string)array_sum(array_map('intval', $decisionStats)), t('admin.dashboard.events_count'))) ?></small></div>
      <?php if ($decisionStats === []): ?>
        <div class="zs-earnings-empty"><strong><?= e(t('admin.dashboard.brak_decyzji_w_tym_okresie')) ?></strong><span><?= e(t('admin.dashboard.system_nie_zarejestrowa_zakonczonych_ocen_regu')) ?></span></div>
      <?php else: ?>
        <div class="admin-table-wrap">
        <table class="admin-table zs-earnings-table">
          <thead><tr><th><?= e(t('admin.dashboard.powod')) ?></th><th><?= e(t('admin.dashboard.liczba')) ?></th></tr></thead>
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
const dashboardUi = <?= json_encode([
    'clearing' => t('admin.dashboard.clearing_cache'),
    'clearError' => t('admin.dashboard.clear_cache_error'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
document.getElementById('clear-cache-btn').addEventListener('click', function() {
    const btn = this;
    const status = document.getElementById('cache-status');
    const originalText = btn.innerText;

    btn.disabled = true;
    btn.innerText = dashboardUi.clearing;
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
        status.innerText = dashboardUi.clearError;
        status.style.display = 'inline';
        status.style.color = '#c53030';
    });
});
</script>

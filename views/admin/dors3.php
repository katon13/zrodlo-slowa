<?php
$settings = is_array($dors3 ?? null) ? $dors3 : [];
$webAuthnStatus = is_array($webauthn ?? null) ? $webauthn : [];
$recoveryStatus = is_array($recovery ?? null) ? $recovery : [];
$readinessItems = is_array($operator_readiness ?? null) ? $operator_readiness : [];
$oneTimeCodes = is_array($new_recovery_codes ?? null) ? $new_recovery_codes : [];
$oneTimeBatch = is_string($new_recovery_batch ?? null) ? $new_recovery_batch : '';
$activeCodes = (int)($recoveryStatus['active'] ?? 0);
$confirmedCodes = (int)($recoveryStatus['confirmed'] ?? 0);
$readinessPassed = count(array_filter($readinessItems, static fn(array $item): bool => !empty($item['passed'])));
$readinessTotal = count($readinessItems);
$readinessPercent = $readinessTotal > 0 ? (int)round(($readinessPassed / $readinessTotal) * 100) : 0;
$hasSecurityAlarm = !empty($last_alarm);
?>

<div class="zs-dors3-page">
  <section class="admin-page-head zs-dors3-page-head">
    <p class="kicker">CENTRUM OCHRONY ADMINISTRACJI</p>
    <h1>Ochrona panelu administratora</h1>
    <p>3DORS chroni logowanie, wymaga ponownego potwierdzenia ważnych zmian i zapisuje historię operacji. System jest przygotowany na późniejsze podłączenie fizycznych kluczy.</p>
  </section>

  <section class="zs-dors3-summary" aria-label="Stan ochrony panelu administratora">
    <article class="zs-dors3-summary-card is-ready">
      <?php echo zs_icon('shield'); ?>
      <span>Stan ochrony</span>
      <strong>Działa prawidłowo</strong>
      <small>Logowanie i ważne operacje są chronione</small>
    </article>
    <article class="zs-dors3-summary-card is-ready">
      <?php echo zs_icon('check-circle'); ?>
      <span>Ważne zmiany</span>
      <strong><?= e((string)($operator_confirmation_label ?? 'Hasło administratora')) ?></strong>
      <small>Potwierdzenie dotyczy jednej, dokładnie wskazanej operacji</small>
    </article>
    <article class="zs-dors3-summary-card <?= !empty($settings['fido2_enabled']) ? 'is-ready' : 'is-pending' ?>">
      <?php echo zs_icon('admin'); ?>
      <span>Klucze fizyczne</span>
      <strong><?= !empty($settings['fido2_enabled']) ? 'Włączone' : 'Oczekują na zakup' ?></strong>
      <small><?= !empty($settings['fido2_enabled']) ? 'Ochrona kluczem jest dostępna' : 'Fundament jest gotowy, funkcja pozostaje bezpiecznie wyłączona' ?></small>
    </article>
    <article class="zs-dors3-summary-card <?= $confirmedCodes === 10 ? 'is-ready' : 'is-warning' ?>">
      <?php echo zs_icon('clipboard'); ?>
      <span>Dostęp awaryjny</span>
      <strong><?= $confirmedCodes === 10 ? 'Zabezpieczony' : 'Wymaga przygotowania' ?></strong>
      <small><?= $confirmedCodes ?> z 10 kodów zapisanych i potwierdzonych</small>
    </article>
  </section>

  <section class="zs-dors3-now">
    <div class="zs-dors3-now-icon"><?php echo zs_icon('shield'); ?></div>
    <div>
      <p class="kicker">OCHRONA OBECNIE</p>
      <h2><?= e((string)($operator_mode_label ?? 'Ochrona hasłem — przygotowanie do kluczy')) ?></h2>
      <p>Administrator loguje się hasłem. Przed zmianą finansów, ról, ustawień AI lub programu Talent system ponownie prosi o hasło i wiąże zgodę tylko z tą konkretną operacją.</p>
    </div>
    <span class="zs-dors3-state-pill is-active">Aktywna</span>
  </section>

  <section class="zs-dors3-doors" aria-label="Trzy warstwy ochrony 3DORS">
    <article>
      <span class="zs-dors3-door-number">01</span>
      <?php echo zs_icon('login'); ?>
      <h3>Bezpieczne wejście</h3>
      <p>Kontrola prób logowania, automatyczna blokada po bezczynności i ograniczony czas sesji administratora.</p>
      <span class="zs-dors3-state-pill is-active">Działa</span>
    </article>
    <article>
      <span class="zs-dors3-door-number">02</span>
      <?php echo zs_icon('check-circle'); ?>
      <h3>Potwierdzenie decyzji</h3>
      <p>Każda ważna zmiana wymaga osobnego potwierdzenia. Zgody nie można użyć do innej operacji.</p>
      <span class="zs-dors3-state-pill is-active">Działa</span>
    </article>
    <article>
      <span class="zs-dors3-door-number">03</span>
      <?php echo zs_icon('history'); ?>
      <h3>Historia i odzyskanie</h3>
      <p>Zdarzenia są zapisywane w audycie, a dostęp można odzyskać kontrolowaną procedurą awaryjną.</p>
      <span class="zs-dors3-state-pill is-active">Działa</span>
    </article>
  </section>

  <?php if ($oneTimeCodes !== []): ?>
  <section class="zs-dors3-one-time" id="recovery-codes-once">
    <div class="zs-dors3-section-head">
      <div>
        <p class="kicker">POKAŻEMY JE TYLKO TERAZ</p>
        <h2>Zapisz 10 kodów awaryjnych</h2>
        <p>Wydrukuj je lub przepisz i przechowuj poza komputerem. Nie wysyłaj ich pocztą ani komunikatorem.</p>
      </div>
      <?php echo zs_icon('warning'); ?>
    </div>
    <ol class="dors3-recovery-codes">
      <?php foreach ($oneTimeCodes as $code): ?><li><?= e((string)$code) ?></li><?php endforeach; ?>
    </ol>
    <div class="zs-dors3-one-time-actions">
      <button class="btn btn-secondary" type="button" onclick="window.print()">Drukuj kody</button>
      <form method="post" action="/admin/security/3dors/recovery/confirm" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="batch_public_id" value="<?= e($oneTimeBatch) ?>">
        <label class="zs-dors3-confirm-check"><input type="checkbox" name="saved_confirmation" value="yes" required> <span>Kody zostały zapisane w bezpiecznym miejscu poza komputerem</span></label>
        <label for="confirm-recovery-password"><span>Hasło administratora</span><input id="confirm-recovery-password" type="password" name="critical_password" required autocomplete="current-password"></label>
        <button class="btn btn-primary" type="submit">Potwierdź bezpieczny zapis</button>
      </form>
    </div>
  </section>
  <?php endif; ?>

  <section class="zs-dors3-workspace">
    <article class="admin-panel-block zs-dors3-recovery-panel">
      <div class="zs-dors3-panel-icon"><?php echo zs_icon('clipboard'); ?></div>
      <p class="kicker">DOSTĘP AWARYJNY</p>
      <h2>Kody awaryjne</h2>
      <p>Nowy komplet zastąpi i unieważni wszystkie poprzednie kody. Każde utworzenie zestawu zostanie zapisane w historii ochrony.</p>
      <div class="zs-dors3-code-status">
        <div><strong><?= $activeCodes ?></strong><span>aktywnych</span></div>
        <div><strong><?= $confirmedCodes ?></strong><span>potwierdzonych</span></div>
      </div>
      <form method="post" action="/admin/security/3dors/recovery/generate" autocomplete="off" class="zs-dors3-recovery-form">
        <?= csrf_field() ?>
        <label for="generate-recovery-password"><span>Hasło administratora</span><input id="generate-recovery-password" type="password" name="critical_password" required autocomplete="current-password"></label>
        <button class="btn btn-primary" type="submit">Utwórz nowy zestaw 10 kodów</button>
      </form>
      <small class="zs-dors3-caution">Po utworzeniu kody pojawią się tylko jeden raz.</small>
    </article>

    <article class="admin-panel-block zs-dors3-alarm-panel <?= $hasSecurityAlarm ? 'has-alarm' : '' ?>">
      <div class="zs-dors3-panel-icon"><?php echo zs_icon($hasSecurityAlarm ? 'warning' : 'check-circle'); ?></div>
      <p class="kicker">OSTATNI ALARM</p>
      <?php if (!$hasSecurityAlarm): ?>
        <h2>Brak alarmów</h2>
        <p>Nie odnotowano zablokowanych ani nieudanych operacji o wysokim poziomie ryzyka.</p>
        <span class="zs-dors3-state-pill is-active">Spokojnie</span>
      <?php else: ?>
        <h2><?= e((string)($last_alarm['action_label'] ?? 'Zdarzenie bezpieczeństwa')) ?></h2>
        <p><?= e((string)($last_alarm['reason_label'] ?? 'Wymaga sprawdzenia')) ?></p>
        <time datetime="<?= e((string)$last_alarm['occurred_at']) ?>"><?= e(date('d.m.Y H:i:s', strtotime((string)$last_alarm['occurred_at']))) ?></time>
        <span class="zs-dors3-state-pill is-danger">Sprawdź</span>
      <?php endif; ?>
    </article>
  </section>

  <section class="admin-panel-block zs-dors3-readiness">
    <div class="zs-dors3-section-head">
      <div>
        <p class="kicker">PLAN PO ZAKUPIE KLUCZY</p>
        <h2>Gotowość do włączenia kluczy</h2>
        <p>System nie pozwoli wymusić klucza, dopóki wszystkie testy i zabezpieczenia nie zostaną potwierdzone.</p>
      </div>
      <div class="zs-dors3-progress-number"><strong><?= $readinessPassed ?>/<?= $readinessTotal ?></strong><span>warunków gotowych</span></div>
    </div>
    <div class="zs-dors3-progress" aria-label="Gotowość: <?= $readinessPercent ?> procent"><span style="width:<?= $readinessPercent ?>%"></span></div>
    <div class="zs-dors3-checklist">
      <?php foreach ($readinessItems as $item): ?>
        <div class="<?= !empty($item['passed']) ? 'is-complete' : '' ?>">
          <?php echo zs_icon(!empty($item['passed']) ? 'check-circle' : 'plus-circle'); ?>
          <span><?= e((string)$item['label']) ?></span>
          <strong><?= !empty($item['passed']) ? 'Gotowe' : 'Do wykonania' ?></strong>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="zs-dors3-readiness-note">
      <?php echo zs_icon('warning'); ?>
      <p>Włączenie kluczy będzie osobnym, kontrolowanym etapem po zakupie klucza podstawowego i zapasowego. Na tym ekranie nie ma przypadkowego przełącznika, który mógłby odciąć dostęp.</p>
    </div>
  </section>

  <section class="zs-dors3-workspace">
    <article class="admin-panel-block">
      <div class="zs-dors3-section-head is-compact">
        <div><p class="kicker">DOSTĘP</p><h2>Aktywne logowania</h2></div>
        <strong class="zs-dors3-count"><?= count($sessions ?? []) ?></strong>
      </div>
      <?php if (empty($sessions)): ?>
        <p class="admin-note">Brak aktywnych sesji administratora.</p>
      <?php else: ?>
        <div class="zs-dors3-session-list">
          <?php foreach (($sessions ?? []) as $index => $session): ?>
            <div>
              <?php echo zs_icon('login'); ?>
              <span><strong>Logowanie <?= (int)$index + 1 ?></strong><small>Ostatnia aktywność: <?= date('d.m.Y H:i:s', (int)$session['last_activity']) ?></small></span>
              <details><summary>Identyfikator</summary><code><?= e((string)$session['public_id']) ?></code></details>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </article>

    <article class="admin-panel-block">
      <div class="zs-dors3-section-head is-compact">
        <div><p class="kicker">FIZYCZNE ZABEZPIECZENIA</p><h2>Klucze administratora</h2></div>
        <strong class="zs-dors3-count"><?= count($credentials ?? []) ?></strong>
      </div>
      <?php if (empty($credentials)): ?>
        <div class="zs-dors3-empty">
          <?php echo zs_icon('admin'); ?>
          <strong>Klucze nie są jeszcze kupione</strong>
          <p>To prawidłowy i bezpieczny stan. Rejestracja pojawi się dopiero w kontrolowanym etapie testowym.</p>
        </div>
      <?php else: ?>
        <div class="zs-dors3-key-list">
          <?php foreach ($credentials as $credential): ?>
            <div>
              <?php echo zs_icon('admin'); ?>
              <span><strong><?= e((string)$credential['display_name']) ?></strong><small><?= e((string)$credential['role_label']) ?> · <?= e((string)$credential['status_label']) ?></small></span>
              <span class="zs-dors3-state-pill <?= !empty($credential['tested_at']) ? 'is-active' : 'is-pending' ?>"><?= !empty($credential['tested_at']) ? 'Sprawdzony' : 'Czeka na test' ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </article>
  </section>

  <section class="admin-panel-block zs-dors3-events">
    <div class="zs-dors3-section-head">
      <div>
        <p class="kicker">AUDYT OPERACJI</p>
        <h2>Historia ochrony</h2>
        <p>Ostatnie logowania, potwierdzenia ważnych decyzji i zdarzenia wymagające uwagi.</p>
      </div>
    </div>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead><tr><th>Kiedy</th><th>Co się wydarzyło</th><th>Obszar</th><th>Ryzyko</th><th>Wynik</th><th>Informacja</th></tr></thead>
        <tbody>
        <?php foreach (($events ?? []) as $event): ?>
          <tr>
            <td><time datetime="<?= e((string)$event['occurred_at']) ?>"><?= e(date('d.m.Y H:i:s', strtotime((string)$event['occurred_at']))) ?></time></td>
            <td><strong><?= e((string)$event['action_label']) ?></strong></td>
            <td><?= e((string)$event['resource_label']) ?></td>
            <td><span class="zs-dors3-event-badge is-<?= e((string)$event['risk_class']) ?>"><?= e((string)$event['risk_label']) ?></span></td>
            <td><span class="zs-dors3-event-badge is-<?= e((string)$event['result_class']) ?>"><?= e((string)$event['result_label']) ?></span></td>
            <td><?= e((string)$event['reason_label']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($events)): ?><tr><td colspan="6">Historia jest pusta.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <details class="zs-dors3-diagnostics">
    <summary><span>Diagnostyka dla wdrożenia</span><small>Parametry techniczne potrzebne dopiero przy instalacji kluczy</small></summary>
    <div class="zs-dors3-diagnostic-grid">
      <div><span>Tryb systemowy</span><code><?= e((string)($settings['mode'] ?? 'prepare')) ?></code></div>
      <div><span>Metoda potwierdzenia</span><code><?= e((string)($settings['critical_step_up'] ?? 'password')) ?></code></div>
      <div><span>Obsługa kluczy</span><code><?= !empty($settings['fido2_enabled']) ? 'enabled' : 'disabled' ?></code></div>
      <div><span>Biblioteka kluczy</span><code><?= !empty($webAuthnStatus['library_ready']) ? 'ready' : 'missing' ?></code></div>
      <div><span>Adres aplikacji</span><code><?= e((string)($webAuthnStatus['origin'] ?? '')) ?></code></div>
      <div><span>Domena kluczy</span><code><?= e((string)($webAuthnStatus['rp_id'] ?? '')) ?></code></div>
      <div><span>Weryfikacja użytkownika</span><code><?= e((string)($webAuthnStatus['user_verification'] ?? 'required')) ?></code></div>
      <div><span>Maksymalny czas sesji</span><code><?= (int)($settings['admin_session_max_seconds'] ?? 0) ?> s</code></div>
    </div>
  </details>
</div>

<style>
@media print {
  body * { visibility: hidden; }
  #recovery-codes-once, #recovery-codes-once * { visibility: visible; }
  #recovery-codes-once { position: absolute; inset: 0; border: 0 !important; }
  #recovery-codes-once form, #recovery-codes-once button { display: none !important; }
}
</style>

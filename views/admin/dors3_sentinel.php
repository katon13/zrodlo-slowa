<?php
use App\Services\Dors3UiText;

$data = is_array($sentinel ?? null) ? $sentinel : [];
$languages = ['pl', 'en', 'de', 'fr', 'it', 'es'];
$language = in_array((string)($ui_language ?? ''), $languages, true) ? (string)$ui_language : 'pl';
$tr = static fn(string $key, array $params = []): string => Dors3UiText::get('sentinel.' . $key, $params, $language);
$option = static fn(string $section, string $value): string => Dors3UiText::option($section, $value, $language);
$summary = is_array($data['summary'] ?? null) ? $data['summary'] : [];
$system = is_array($data['system_status'] ?? null) ? $data['system_status'] : ['status' => 'unknown', 'reason' => 'instances_unknown'];
$filters = is_array($data['filters'] ?? null) ? $data['filters'] : [];
$view = in_array((string)($data['view'] ?? ''), $sentinel_views ?? [], true) ? (string)$data['view'] : 'active';
$pagination = is_array($data['pagination'] ?? null) ? $data['pagination'] : ['page' => 1, 'pages' => 1, 'total' => 0];
$statusClass = static fn(string $status): string => in_array($status, ['ok', 'warning', 'medium', 'high', 'critical', 'unknown', 'error', 'inactive'], true) ? $status : 'unknown';
$statusLabel = static fn(string $status): string => $tr('status_' . ($status === 'medium' ? 'warning' : $status));
$formatTime = static fn(mixed $time): string => trim((string)$time) !== '' ? date('d.m.Y H:i:s', strtotime((string)$time)) : '—';
$formatShortTime = static fn(mixed $time): string => trim((string)$time) !== '' ? date('H:i', strtotime((string)$time)) : '—';
$formatBytes = static function (mixed $bytes) use ($tr): string {
    $value = max(0, (int)$bytes);
    $units = array_map(static fn(string $unit): string => $tr('storage_unit_' . $unit), ['b', 'kb', 'mb', 'gb', 'tb']);
    $unit = 0;
    while ($value >= 1024 && $unit < count($units) - 1) {
        $value /= 1024;
        ++$unit;
    }
    return number_format($value, $unit === 0 ? 0 : 1, ',', ' ') . ' ' . $units[$unit];
};
$panelQuery = static function (array $changes = []) use ($filters, $language, $view): string {
    $query = array_filter([
        'lang' => $language,
        'view' => $view,
        'filter' => $filters['filter'] ?? 'all',
        'q' => $filters['q'] ?? '',
        'date_from' => $filters['date_from'] ?? '',
        'date_to' => $filters['date_to'] ?? '',
        'actor_id' => $filters['actor_id'] ?? '',
        'page' => 1,
    ], static fn(mixed $value): bool => $value !== null && $value !== '');
    return '/admin/security/sentinel?' . http_build_query(array_replace($query, $changes));
};
$showsAlerts = in_array($view, ['active', 'open', 'acknowledged', 'resolved', 'archive'], true);
$showsLogs = in_array($view, ['logs', 'archive'], true);
?>

<div class="zs-sentinel-page">
  <section class="zs-sentinel-head">
    <div>
      <p class="kicker"><?= e($tr('kicker')) ?></p>
      <h1><?= e($tr('title')) ?></h1>
      <p><?= e($tr('description')) ?></p>
    </div>
    <nav class="zs-sentinel-language" aria-label="<?= e($tr('language_label')) ?>">
      <?php foreach ($languages as $languageOption): ?>
        <a class="<?= $language === $languageOption ? 'is-active' : '' ?>" href="<?= e($panelQuery(['lang' => $languageOption])) ?>"><?= e($tr('language_' . $languageOption)) ?></a>
      <?php endforeach; ?>
    </nav>
  </section>

  <section class="zs-sentinel-status is-<?= e($statusClass((string)$system['status'])) ?>" aria-label="<?= e($tr('protection_status')) ?>">
    <div>
      <span><?= e($tr('protection_status')) ?></span>
      <strong><?= e($statusLabel((string)$system['status'])) ?></strong>
      <p><?= e($tr('reason_' . (string)$system['reason'])) ?></p>
    </div>
    <div class="zs-sentinel-metrics">
      <?php foreach ([
        ['open_alerts', 'open_alerts'],
        ['attention_24h', 'attention_24h'],
        ['events_24h', 'events_24h'],
        ['high_24h', 'high_24h'],
        ['critical_24h', 'critical_24h'],
      ] as [$key, $label]): ?>
        <article><strong><?= (int)($summary[$key] ?? 0) ?></strong><span><?= e($tr($label)) ?></span></article>
      <?php endforeach; ?>
    </div>
  </section>

  <nav class="zs-sentinel-tabs" aria-label="<?= e($tr('sections_label')) ?>">
    <?php foreach (($sentinel_views ?? []) as $panelView): ?>
      <a class="<?= $view === $panelView ? 'is-active' : '' ?>" href="<?= e($panelQuery(['view' => $panelView, 'page' => 1])) ?>">
        <?= e($tr('view_' . $panelView)) ?>
        <?php if ($panelView === 'open' && (int)($summary['open_alerts'] ?? 0) > 0): ?><b><?= (int)$summary['open_alerts'] ?></b><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>

  <?php if ($showsAlerts): ?>
    <section class="zs-sentinel-panel zs-sentinel-alerts">
      <header class="zs-sentinel-section-head">
        <div><p class="kicker"><?= e($tr('view_' . $view)) ?></p><h2><?= e($tr('alerts_title')) ?></h2><p><?= e($tr('alerts_human_description')) ?></p></div>
      </header>
      <div class="zs-sentinel-alert-list">
        <?php foreach (($data['alerts'] ?? []) as $alert): ?>
          <?php $alertSeverity = $statusClass((string)($alert['severity'] ?? 'high')); ?>
          <article class="is-<?= e($alertSeverity) ?>">
            <div class="zs-sentinel-alert-main">
              <span class="zs-sentinel-pill is-<?= e($alertSeverity) ?>"><?= e($option('events.risks', (string)($alert['severity'] ?? 'high'))) ?></span>
              <div class="zs-sentinel-alert-copy">
                <strong><?= e((string)$alert['action_label']) ?> <span aria-hidden="true">—</span> <?= e((string)$alert['resource_label']) ?></strong>
                <p><?= e((string)($alert['alert_reason_label'] ?? '')) ?></p>
                <small><?= e((string)($alert['actor'] ?? $tr('system_actor'))) ?> · <?= e($formatTime($alert['opened_at'] ?? null)) ?> · <?= e((string)($alert['confirmation_label'] ?? '')) ?></small>
              </div>
              <div class="zs-sentinel-alert-controls">
                <b><?= e($tr('alert_status_' . (string)$alert['status'])) ?></b>
                <?php if ((string)$alert['status'] !== 'resolved'): ?>
                  <div class="zs-sentinel-alert-actions">
                    <?php if ((string)$alert['status'] === 'open'): ?>
                      <form method="post" action="<?= e('/admin/security/sentinel/alerts/' . rawurlencode((string)$alert['public_id']) . '/acknowledge') ?>">
                        <?= csrf_field() ?><input type="hidden" name="lang" value="<?= e($language) ?>"><input type="hidden" name="return_view" value="<?= e($view) ?>">
                        <button type="submit"><?= e($tr('acknowledge')) ?></button>
                      </form>
                    <?php endif; ?>
                    <button type="button" class="is-resolve" data-resolve-action="<?= e('/admin/security/sentinel/alerts/' . rawurlencode((string)$alert['public_id']) . '/resolve') ?>"><?= e($tr('resolve')) ?></button>
                  </div>
                <?php endif; ?>
              </div>
            </div>
            <details class="zs-sentinel-details">
              <summary><?= e($tr('details')) ?> · <?= e($tr('stage_count', ['count' => max(1, count((array)($alert['stages'] ?? [])))])) ?></summary>
              <?php if (!empty($alert['stages'])): ?>
                <ol class="zs-sentinel-stage-list">
                  <?php foreach ($alert['stages'] as $stage): ?>
                    <li><time><?= e($formatTime($stage['occurred_at'] ?? null)) ?></time><span><?= e((string)$stage['action_label']) ?></span><b><?= e((string)$stage['result_label']) ?></b></li>
                  <?php endforeach; ?>
                </ol>
              <?php endif; ?>
              <div class="zs-sentinel-identifiers">
                <span><?= e($tr('request_id')) ?><code><?= e((string)($alert['request_id'] ?? '—')) ?></code></span>
                <span><?= e($tr('correlation_id')) ?><code><?= e((string)($alert['correlation_id'] ?? '—')) ?></code></span>
                <span><?= e($tr('instance_id')) ?><code><?= e((string)($alert['instance_id'] ?? '—')) ?></code></span>
              </div>
              <?php if ((string)$alert['status'] === 'resolved' && (string)($alert['resolution_code'] ?? '') !== ''): ?>
                <p class="zs-sentinel-resolution"><strong><?= e($tr('resolution')) ?>:</strong> <?= e($tr('resolution_' . (string)$alert['resolution_code'])) ?><?php if ((string)($alert['resolution_note'] ?? '') !== (string)$alert['resolution_code']): ?> · <?= e((string)$alert['resolution_note']) ?><?php endif; ?></p>
              <?php endif; ?>
            </details>
          </article>
        <?php endforeach; ?>
        <?php if (empty($data['alerts'])): ?>
          <div class="zs-sentinel-clear-state"><span aria-hidden="true">✓</span><strong><?= e($tr('no_alerts_title')) ?></strong><p><?= e($tr('no_alerts')) ?></p></div>
        <?php endif; ?>
      </div>
    </section>
  <?php endif; ?>

  <?php if ($view === 'active'): ?>
    <section class="zs-sentinel-grid zs-sentinel-activity-grid">
      <article class="zs-sentinel-panel">
        <header><div><p class="kicker"><?= e($tr('sessions_title')) ?></p><small><?= e($tr('sessions_description')) ?></small></div></header>
        <div class="zs-sentinel-compact-log">
          <?php foreach (($data['sessions'] ?? []) as $session): ?>
            <div><time><?= e(date('d.m H:i', (int)($session['last_activity'] ?? 0))) ?></time><span><strong><?= e((string)($session['account'] ?? $tr('system_actor'))) ?></strong><small><?= e((string)($session['email_masked'] ?? '')) ?></small></span><b class="is-active-session"><?= e($tr('session_active')) ?></b></div>
          <?php endforeach; ?>
          <?php if (empty($data['sessions'])): ?><p class="zs-sentinel-empty"><?= e($tr('no_sessions')) ?></p><?php endif; ?>
        </div>
        <?php if (!empty($data['ended_sessions'])): ?>
          <details class="zs-sentinel-details"><summary><?= e($tr('ended_sessions')) ?></summary>
            <div class="zs-sentinel-compact-log">
              <?php foreach ($data['ended_sessions'] as $session): ?><div><time><?= e($formatTime($session['occurred_at'] ?? null)) ?></time><span><strong><?= e((string)($session['actor'] ?? $tr('system_actor'))) ?></strong><small><?= e((string)$session['action_label']) ?></small></span><b><?= e((string)$session['result_label']) ?></b></div><?php endforeach; ?>
            </div>
          </details>
        <?php endif; ?>
      </article>

      <article class="zs-sentinel-panel">
        <header><div><p class="kicker"><?= e($tr('logins_title')) ?></p><small><?= e($tr('logins_grouped_description')) ?></small></div></header>
        <div class="zs-sentinel-compact-log">
          <?php foreach (($data['login_events'] ?? []) as $login): ?>
            <details class="zs-sentinel-login-group">
              <summary><time><?= e($formatShortTime($login['first_at'] ?? null)) ?>–<?= e($formatShortTime($login['last_at'] ?? null)) ?></time><span><strong><?= e((string)($login['account'] ?? $tr('system_actor'))) ?></strong><small><?= e($tr('login_attempts', ['count' => (int)($login['attempt_count'] ?? 0)])) ?></small></span><b><?= e($option('login_results', (string)($login['result'] ?? ''))) ?></b></summary>
              <ol><?php foreach ((array)($login['samples'] ?? []) as $sample): ?><li><time><?= e($formatTime($sample['created_at'] ?? null)) ?></time><span><?= e($option('login_results', (string)($sample['result'] ?? ''))) ?></span></li><?php endforeach; ?></ol>
            </details>
          <?php endforeach; ?>
          <?php if (empty($data['login_events'])): ?><p class="zs-sentinel-empty"><?= e($tr('no_logins')) ?></p><?php endif; ?>
        </div>
      </article>
    </section>

    <details class="zs-sentinel-infrastructure">
      <summary><?= e($tr('infrastructure_title')) ?><small><?= e($tr('infrastructure_description')) ?></small></summary>
      <section class="zs-sentinel-grid">
        <article class="zs-sentinel-panel">
          <header><div><p class="kicker"><?= e($tr('instances_title')) ?></p><small><?= e($tr('instances_description')) ?></small></div></header>
          <div class="zs-sentinel-instance-list">
            <?php foreach (($data['instances'] ?? []) as $instance): ?>
              <?php $instanceStatus = $statusClass((string)($instance['status'] ?? 'unknown')); ?>
              <div><span class="zs-sentinel-dot is-<?= e($instanceStatus) ?>"></span><span><strong><?= e((string)$instance['instance_id']) ?></strong><small><?= e($tr('last_seen')) ?>: <?= $instance['age_seconds'] !== null ? e($tr('seconds_ago', ['count' => (int)$instance['age_seconds']])) : e($tr('no_heartbeat')) ?></small></span><b class="zs-sentinel-pill is-<?= e($instanceStatus) ?>"><?= e($statusLabel($instanceStatus)) ?></b></div>
            <?php endforeach; ?>
          </div>
        </article>
        <article class="zs-sentinel-panel">
          <header><div><p class="kicker"><?= e($tr('readiness_title')) ?></p><small><?= e($tr('readiness_description')) ?></small></div></header>
          <div class="zs-sentinel-readiness-list">
            <?php foreach (($data['readiness'] ?? []) as $item): ?>
              <?php $itemStatus = $statusClass((string)($item['status'] ?? 'unknown')); ?>
              <div><span><strong><?= e($tr('readiness_' . (string)$item['key'])) ?></strong><small><?php if (str_starts_with((string)$item['key'], 'dors3_')): ?><?= e($tr('active_devices', ['count' => (int)($item['active_devices'] ?? 0)])) ?> · <?= e($tr('ready_policies', ['ready' => (int)($item['ready_policies'] ?? 0), 'total' => (int)($item['total_policies'] ?? 0)])) ?><?php elseif ((string)$item['key'] === 'mobile'): ?><?= e($tr('mode_value', ['mode' => $option('events.modes', (string)($item['mode'] ?? 'disabled'))])) ?><?php else: ?><?= e($tr('foundation_only')) ?><?php endif; ?></small></span><b class="zs-sentinel-pill is-<?= e($itemStatus) ?>"><?= e($statusLabel($itemStatus)) ?></b></div>
            <?php endforeach; ?>
          </div>
        </article>
      </section>
    </details>
  <?php endif; ?>

  <?php if ($showsLogs): ?>
    <section class="zs-sentinel-panel zs-sentinel-history">
      <header class="zs-sentinel-section-head"><div><p class="kicker"><?= e($tr('view_' . $view)) ?></p><h2><?= e($tr($view === 'archive' ? 'archive_title' : 'history_title')) ?></h2><p><?= e($tr($view === 'archive' ? 'archive_description' : 'history_description')) ?></p></div></header>
      <form method="get" class="zs-sentinel-filters">
        <input type="hidden" name="lang" value="<?= e($language) ?>"><input type="hidden" name="view" value="<?= e($view) ?>">
        <label><span><?= e($tr('filter')) ?></span><select name="filter"><?php foreach (($sentinel_filters ?? []) as $filter): ?><option value="<?= e((string)$filter) ?>" <?= (string)($filters['filter'] ?? 'all') === (string)$filter ? 'selected' : '' ?>><?= e($tr('filter_' . (string)$filter)) ?></option><?php endforeach; ?></select></label>
        <label><span><?= e($tr('user_filter')) ?></span><select name="actor_id"><option value=""><?= e($tr('all_users')) ?></option><?php foreach (($data['actors'] ?? []) as $actor): ?><option value="<?= (int)$actor['id'] ?>" <?= (int)($filters['actor_id'] ?? 0) === (int)$actor['id'] ? 'selected' : '' ?>><?= e((string)$actor['label']) ?></option><?php endforeach; ?></select></label>
        <label class="is-search"><span><?= e($tr('search')) ?></span><input type="search" name="q" maxlength="120" value="<?= e((string)($filters['q'] ?? '')) ?>" placeholder="<?= e($tr('search_placeholder')) ?>"></label>
        <label><span><?= e($tr('date_from')) ?></span><input type="date" name="date_from" value="<?= e((string)($filters['date_from'] ?? '')) ?>"></label>
        <label><span><?= e($tr('date_to')) ?></span><input type="date" name="date_to" value="<?= e((string)($filters['date_to'] ?? '')) ?>"></label>
        <button type="submit"><?= e($tr('apply_filters')) ?></button><a href="<?= e($panelQuery(['view' => $view, 'filter' => 'all', 'q' => '', 'date_from' => '', 'date_to' => '', 'actor_id' => '', 'page' => 1])) ?>"><?= e($tr('clear_filters')) ?></a>
      </form>
      <div class="admin-table-wrap">
        <table class="admin-table zs-sentinel-table">
          <thead><tr><th><?= e($tr('when')) ?></th><th><?= e($tr('event')) ?></th><th><?= e($tr('actor_source')) ?></th><th><?= e($tr('risk')) ?></th><th><?= e($tr('result')) ?></th><th><?= e($tr('technical_context')) ?></th></tr></thead>
          <tbody>
          <?php foreach (($data['events'] ?? []) as $event): ?>
            <tr><td><time><?= e($formatTime($event['occurred_at'] ?? null)) ?></time></td><td><strong><?= e((string)$event['action_label']) ?></strong><small><?= e((string)$event['resource_label']) ?></small></td><td><?= e((string)($event['actor'] ?? $tr('system_actor'))) ?><small><?= e((string)($event['email_masked'] ?? '')) ?></small></td><td><span class="zs-dors3-event-badge is-<?= e((string)$event['risk_class']) ?>"><?= e((string)$event['risk_label']) ?></span></td><td><span class="zs-dors3-event-badge is-<?= e((string)$event['result_class']) ?>"><?= e((string)$event['result_label']) ?></span></td><td><details class="zs-sentinel-details"><summary><?= e($tr('details')) ?></summary><div class="zs-sentinel-identifiers"><span><?= e($tr('request_id')) ?><code><?= e((string)($event['request_id'] ?? '—')) ?></code></span><span><?= e($tr('correlation_id')) ?><code><?= e((string)($event['correlation_id'] ?? '—')) ?></code></span><span><?= e($tr('instance_id')) ?><code><?= e((string)($event['instance_id'] ?? '—')) ?></code></span><span><?= e($tr('authentication')) ?><code><?= e((string)($event['authentication_level'] ?? '—')) ?></code></span><span><?= e($tr('network')) ?><code><?= e((string)($event['ip_masked'] ?? '—')) ?></code></span></div></details></td></tr>
          <?php endforeach; ?>
          <?php if (empty($data['events'])): ?><tr><td colspan="6"><?= e($tr('no_events')) ?></td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
      <nav class="zs-sentinel-pagination" aria-label="<?= e($tr('pagination_label')) ?>"><span><?= e($tr('records', ['count' => (string)(int)$pagination['total'] . (!empty($pagination['total_capped']) ? '+' : '')])) ?></span><span><?= e($tr('page', ['page' => (int)$pagination['page'], 'pages' => (int)$pagination['pages']])) ?></span><div><?php if ((int)$pagination['page'] > 1): ?><a href="<?= e($panelQuery(['page' => (int)$pagination['page'] - 1])) ?>"><?= e($tr('previous')) ?></a><?php endif; ?><?php if ((int)$pagination['page'] < (int)$pagination['pages']): ?><a href="<?= e($panelQuery(['page' => (int)$pagination['page'] + 1])) ?>"><?= e($tr('next')) ?></a><?php endif; ?></div></nav>
    </section>
  <?php endif; ?>

  <?php if ($view === 'archive'): ?>
    <section class="zs-sentinel-panel zs-sentinel-archive-control">
      <header><div><p class="kicker"><?= e($tr('archive_control_kicker')) ?></p><h2><?= e($tr('archive_control_title')) ?></h2><p><?= e($tr('archive_control_description')) ?></p></div><span class="zs-sentinel-protected"><?= e($tr('protected_by_3dors')) ?></span></header>
      <form method="post" action="/admin/security/sentinel/archive" class="zs-sentinel-archive-form">
        <?= csrf_field() ?><input type="hidden" name="lang" value="<?= e($language) ?>">
        <label><span><?= e($tr('archive_before')) ?></span><input type="date" name="cutoff_date" required max="<?= e(gmdate('Y-m-d', strtotime('-30 days'))) ?>" value="<?= e((string)($data['archive_cutoff_default'] ?? '')) ?>"></label>
        <label><span><?= e($tr('critical_password')) ?></span><input type="password" name="critical_password" required autocomplete="current-password"></label>
        <button type="submit"><?= e($tr('archive_action')) ?></button>
      </form>
      <?php if (!empty($data['archive_batches'])): ?>
        <details class="zs-sentinel-details"><summary><?= e($tr('recent_archive_runs')) ?></summary><div class="zs-sentinel-compact-log"><?php foreach ($data['archive_batches'] as $batch): ?><div><time><?= e($formatTime($batch['completed_at'] ?? $batch['created_at'] ?? null)) ?></time><span><strong><?= e((string)($batch['actor'] ?? $tr('system_actor'))) ?></strong><small><?= e($tr('archive_batch_counts', ['events' => (int)$batch['security_event_count'], 'logins' => (int)$batch['login_event_count']])) ?></small></span><code><?= e((string)$batch['public_id']) ?></code></div><?php endforeach; ?></div></details>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <?php $storage = is_array($data['storage'] ?? null) ? $data['storage'] : []; ?>
  <?php if ($storage !== []): ?>
    <?php $storageStatus = $statusClass((string)($storage['status'] ?? 'unknown')); $storagePercent = min(100, (int)round(((int)($storage['total_bytes'] ?? 0) / max(1, (int)($storage['critical_bytes'] ?? 1))) * 100)); ?>
    <section class="zs-sentinel-storage is-<?= e($storageStatus) ?>" aria-label="<?= e($tr('storage_title')) ?>">
      <header><div><p class="kicker"><?= e($tr('storage_kicker')) ?></p><h2><?= e($tr('storage_title')) ?></h2><p><?= e($tr('storage_description')) ?></p></div><div class="zs-sentinel-storage-total"><strong><?= e($formatBytes($storage['total_bytes'] ?? 0)) ?></strong><span><?= e($statusLabel($storageStatus)) ?></span></div></header>
      <div class="zs-sentinel-storage-meter" aria-hidden="true"><i style="width:<?= $storagePercent ?>%"></i></div>
      <div class="zs-sentinel-storage-grid">
        <?php foreach ((array)($storage['tables'] ?? []) as $table): ?>
          <article><span><?= e($tr('storage_table_' . (string)$table['name'])) ?></span><strong><?= e($formatBytes($table['bytes'] ?? 0)) ?></strong><small><?= e($tr('estimated_entries', ['count' => number_format((int)($table['rows'] ?? 0), 0, ',', ' ')])) ?></small></article>
        <?php endforeach; ?>
      </div>
      <footer><?= e($tr('storage_thresholds', ['warning' => $formatBytes($storage['warning_bytes'] ?? 0), 'critical' => $formatBytes($storage['critical_bytes'] ?? 0)])) ?></footer>
    </section>
  <?php endif; ?>

  <dialog class="zs-sentinel-dialog" id="sentinel-resolve-dialog" aria-labelledby="sentinel-resolve-title">
    <form method="post" id="sentinel-resolve-form">
      <?= csrf_field() ?><input type="hidden" name="lang" value="<?= e($language) ?>"><input type="hidden" name="return_view" value="<?= e($view) ?>">
      <header><div><p class="kicker"><?= e($tr('resolve_kicker')) ?></p><h2 id="sentinel-resolve-title"><?= e($tr('resolve_title')) ?></h2><p><?= e($tr('resolve_description')) ?></p></div><button type="button" data-dialog-close aria-label="<?= e($tr('close')) ?>"><?= e($tr('close_symbol')) ?></button></header>
      <label><span><?= e($tr('resolution_reason')) ?></span><select name="reason_code" required><?php foreach (($sentinel_resolution_reasons ?? []) as $reason): ?><option value="<?= e((string)$reason) ?>"><?= e($tr('resolution_' . (string)$reason)) ?></option><?php endforeach; ?></select></label>
      <label><span><?= e($tr('optional_note')) ?></span><textarea name="note" maxlength="300" rows="3" placeholder="<?= e($tr('optional_note_placeholder')) ?>"></textarea></label>
      <footer><button type="button" data-dialog-close><?= e($tr('cancel')) ?></button><button type="submit" class="is-resolve"><?= e($tr('resolve')) ?></button></footer>
    </form>
  </dialog>
</div>

<script>
(() => {
  const dialog = document.getElementById('sentinel-resolve-dialog');
  const form = document.getElementById('sentinel-resolve-form');
  if (!(dialog instanceof HTMLDialogElement) || !(form instanceof HTMLFormElement)) return;
  document.querySelectorAll('[data-resolve-action]').forEach((button) => button.addEventListener('click', () => {
    form.action = button.getAttribute('data-resolve-action') || '';
    dialog.showModal();
  }));
  dialog.querySelectorAll('[data-dialog-close]').forEach((button) => button.addEventListener('click', () => dialog.close()));
  dialog.addEventListener('click', (event) => { if (event.target === dialog) dialog.close(); });
})();
</script>

<?php
use App\Services\Dors3UiText;

$data = is_array($sentinel ?? null) ? $sentinel : [];
$language = in_array((string)($ui_language ?? ''), ['pl', 'en'], true) ? (string)$ui_language : 'pl';
$tr = static fn(string $key, array $params = []): string => Dors3UiText::get('sentinel.' . $key, $params, $language);
$option = static fn(string $section, string $value): string => Dors3UiText::option($section, $value, $language);
$summary = is_array($data['summary'] ?? null) ? $data['summary'] : [];
$system = is_array($data['system_status'] ?? null) ? $data['system_status'] : ['status' => 'unknown', 'reason' => 'instances_unknown'];
$filters = is_array($data['filters'] ?? null) ? $data['filters'] : [];
$pagination = is_array($data['pagination'] ?? null) ? $data['pagination'] : ['page' => 1, 'pages' => 1, 'total' => 0];
$statusClass = static fn(string $status): string => in_array($status, ['ok', 'warning', 'high', 'critical', 'unknown', 'error', 'inactive'], true) ? $status : 'unknown';
$statusLabel = static fn(string $status): string => $tr('status_' . $status);
$formatTime = static fn(mixed $time): string => trim((string)$time) !== '' ? date('d.m.Y H:i:s', strtotime((string)$time)) : '—';
$historyQuery = static function (array $changes = []) use ($filters, $language): string {
    $query = array_filter([
        'lang' => $language,
        'filter' => $filters['filter'] ?? 'all',
        'q' => $filters['q'] ?? '',
        'date_from' => $filters['date_from'] ?? '',
        'date_to' => $filters['date_to'] ?? '',
        'alert_status' => $filters['alert_status'] ?? 'active',
        'page' => 1,
    ], static fn(mixed $value): bool => $value !== null && $value !== '');
    return '/admin/security/sentinel?' . http_build_query(array_replace($query, $changes));
};
?>

<div class="zs-sentinel-page">
  <section class="zs-sentinel-head">
    <div>
      <p class="kicker"><?= e($tr('kicker')) ?></p>
      <h1><?= e($tr('title')) ?></h1>
      <p><?= e($tr('description')) ?></p>
    </div>
    <div class="zs-sentinel-language" aria-label="<?= e($tr('language_label')) ?>">
      <a class="<?= $language === 'pl' ? 'is-active' : '' ?>" href="<?= e($historyQuery(['lang' => 'pl'])) ?>"><?= e($tr('language_pl')) ?></a>
      <a class="<?= $language === 'en' ? 'is-active' : '' ?>" href="<?= e($historyQuery(['lang' => 'en'])) ?>"><?= e($tr('language_en')) ?></a>
    </div>
  </section>

  <div class="zs-sentinel-observer-note"><?= e($tr('observer_only')) ?></div>

  <section class="zs-sentinel-status is-<?= e($statusClass((string)$system['status'])) ?>" aria-label="<?= e($tr('protection_status')) ?>">
    <div><span><?= e($tr('protection_status')) ?></span><strong><?= e($statusLabel((string)$system['status'])) ?></strong><p><?= e($tr('reason_' . (string)$system['reason'])) ?></p></div>
    <div class="zs-sentinel-metrics">
      <?php foreach ([
        ['events_24h', 'events_24h'],
        ['attention_24h', 'attention_24h'],
        ['high_24h', 'high_24h'],
        ['critical_24h', 'critical_24h'],
        ['open_alerts', 'open_alerts'],
      ] as [$key, $label]): ?>
        <article><strong><?= (int)($summary[$key] ?? 0) ?></strong><span><?= e($tr($label)) ?></span></article>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="zs-sentinel-grid">
    <article class="zs-sentinel-panel">
      <header><div><p class="kicker"><?= e($tr('instances_title')) ?></p><small><?= e($tr('instances_description')) ?></small></div></header>
      <div class="zs-sentinel-instance-list">
        <?php foreach (($data['instances'] ?? []) as $instance): ?>
          <?php $instanceStatus = $statusClass((string)($instance['status'] ?? 'unknown')); ?>
          <div>
            <span class="zs-sentinel-dot is-<?= e($instanceStatus) ?>"></span>
            <span><strong><?= e((string)$instance['instance_id']) ?></strong><small><?= e($tr('last_seen')) ?>: <?= $instance['age_seconds'] !== null ? e($tr('seconds_ago', ['count' => (int)$instance['age_seconds']])) : e($tr('no_heartbeat')) ?></small></span>
            <b class="zs-sentinel-pill is-<?= e($instanceStatus) ?>"><?= e($statusLabel($instanceStatus)) ?></b>
          </div>
        <?php endforeach; ?>
      </div>
    </article>

    <article class="zs-sentinel-panel">
      <header><div><p class="kicker"><?= e($tr('readiness_title')) ?></p><small><?= e($tr('readiness_description')) ?></small></div></header>
      <div class="zs-sentinel-readiness-list">
        <?php foreach (($data['readiness'] ?? []) as $item): ?>
          <?php $itemStatus = $statusClass((string)($item['status'] ?? 'unknown')); ?>
          <div>
            <span><strong><?= e($tr('readiness_' . (string)$item['key'])) ?></strong>
              <small>
                <?php if (str_starts_with((string)$item['key'], 'dors3_')): ?>
                  <?= e($tr('active_devices', ['count' => (int)($item['active_devices'] ?? 0)])) ?> · <?= e($tr('ready_policies', ['ready' => (int)($item['ready_policies'] ?? 0), 'total' => (int)($item['total_policies'] ?? 0)])) ?>
                <?php elseif ((string)$item['key'] === 'mobile'): ?>
                  <?= e($tr('mode_value', ['mode' => $option('events.modes', (string)($item['mode'] ?? 'disabled'))])) ?>
                <?php else: ?><?= e($tr('foundation_only')) ?><?php endif; ?>
              </small>
            </span>
            <b class="zs-sentinel-pill is-<?= e($itemStatus) ?>"><?= e($statusLabel($itemStatus)) ?></b>
          </div>
        <?php endforeach; ?>
      </div>
    </article>

    <article class="zs-sentinel-panel">
      <header><p class="kicker"><?= e($tr('logins_title')) ?></p></header>
      <div class="zs-sentinel-compact-log">
        <?php foreach (($data['login_events'] ?? []) as $login): ?>
          <div><time><?= e($formatTime($login['created_at'] ?? null)) ?></time><span><strong><?= e((string)($login['account'] ?? '')) ?></strong><small><?= e((string)($login['email_masked'] ?? '')) ?></small></span><b><?= e($option('login_results', (string)($login['result'] ?? ''))) ?></b></div>
        <?php endforeach; ?>
        <?php if (empty($data['login_events'])): ?><p class="zs-sentinel-empty"><?= e($tr('no_logins')) ?></p><?php endif; ?>
      </div>
    </article>

    <article class="zs-sentinel-panel">
      <header><p class="kicker"><?= e($tr('sessions_title')) ?></p></header>
      <div class="zs-sentinel-compact-log">
        <?php foreach (($data['sessions'] ?? []) as $session): ?>
          <div><time><?= e(date('d.m H:i', (int)($session['last_activity'] ?? 0))) ?></time><span><strong><?= e((string)($session['account'] ?? '')) ?></strong><small><?= e((string)($session['email_masked'] ?? '')) ?></small></span><code><?= e((string)($session['public_id'] ?? '')) ?></code></div>
        <?php endforeach; ?>
        <?php if (empty($data['sessions'])): ?><p class="zs-sentinel-empty"><?= e($tr('no_sessions')) ?></p><?php endif; ?>
      </div>
    </article>
  </section>

  <section class="zs-sentinel-panel zs-sentinel-alerts">
    <header class="zs-sentinel-section-head"><div><p class="kicker"><?= e($tr('alerts_title')) ?></p><p><?= e($tr('alerts_description')) ?></p></div>
      <form method="get"><input type="hidden" name="lang" value="<?= e($language) ?>"><select name="alert_status" aria-label="<?= e($tr('alerts_title')) ?>"><?php foreach (($sentinel_alert_statuses ?? []) as $alertStatus): ?><option value="<?= e((string)$alertStatus) ?>" <?= (string)($filters['alert_status'] ?? 'active') === (string)$alertStatus ? 'selected' : '' ?>><?= e($tr('alert_status_' . (string)$alertStatus)) ?></option><?php endforeach; ?></select><button type="submit"><?= e($tr('apply_filters')) ?></button></form>
    </header>
    <div class="zs-sentinel-alert-list">
      <?php foreach (($data['alerts'] ?? []) as $alert): ?>
        <?php $alertSeverity = $statusClass((string)($alert['severity'] ?? 'high')); ?>
        <article class="is-<?= e($alertSeverity) ?>">
          <div class="zs-sentinel-alert-main">
            <span class="zs-sentinel-pill is-<?= e($alertSeverity) ?>"><?= e($option('events.risks', (string)($alert['severity'] ?? 'high'))) ?></span>
            <span><strong><?= e((string)$alert['action_label']) ?></strong><small><?= e((string)$alert['resource_label']) ?> · <?= e($formatTime($alert['opened_at'] ?? null)) ?></small></span>
            <b><?= e($tr('alert_status_' . (string)$alert['status'])) ?></b>
          </div>
          <details><summary><?= e($tr('details')) ?> · <?= e($tr('transition_count', ['count' => (int)($alert['transition_count'] ?? 0)])) ?></summary><div class="zs-sentinel-identifiers"><span><?= e($tr('request_id')) ?><code><?= e((string)($alert['request_id'] ?? '—')) ?></code></span><span><?= e($tr('correlation_id')) ?><code><?= e((string)($alert['correlation_id'] ?? '—')) ?></code></span><span><?= e($tr('instance_id')) ?><code><?= e((string)($alert['instance_id'] ?? '—')) ?></code></span></div></details>
          <?php if ((string)$alert['status'] !== 'resolved'): ?>
            <div class="zs-sentinel-alert-actions">
              <?php if ((string)$alert['status'] === 'open'): ?><form method="post" action="<?= e('/admin/security/sentinel/alerts/' . rawurlencode((string)$alert['public_id']) . '/acknowledge') ?>"><?= csrf_field() ?><input type="hidden" name="lang" value="<?= e($language) ?>"><input type="text" name="reason" minlength="5" maxlength="500" required placeholder="<?= e($tr('reason_placeholder')) ?>"><button type="submit"><?= e($tr('acknowledge')) ?></button></form><?php endif; ?>
              <form method="post" action="<?= e('/admin/security/sentinel/alerts/' . rawurlencode((string)$alert['public_id']) . '/resolve') ?>"><?= csrf_field() ?><input type="hidden" name="lang" value="<?= e($language) ?>"><input type="text" name="reason" minlength="5" maxlength="500" required placeholder="<?= e($tr('reason_placeholder')) ?>"><button type="submit" class="is-resolve"><?= e($tr('resolve')) ?></button></form>
            </div>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
      <?php if (empty($data['alerts'])): ?><p class="zs-sentinel-empty"><?= e($tr('no_alerts')) ?></p><?php endif; ?>
    </div>
  </section>

  <section class="zs-sentinel-panel zs-sentinel-history">
    <header class="zs-sentinel-section-head"><div><p class="kicker"><?= e($tr('history_title')) ?></p><p><?= e($tr('history_description')) ?></p></div></header>
    <form method="get" class="zs-sentinel-filters">
      <input type="hidden" name="lang" value="<?= e($language) ?>">
      <label><span><?= e($tr('filter')) ?></span><select name="filter"><?php foreach (($sentinel_filters ?? []) as $filter): ?><option value="<?= e((string)$filter) ?>" <?= (string)($filters['filter'] ?? 'all') === (string)$filter ? 'selected' : '' ?>><?= e($tr('filter_' . (string)$filter)) ?></option><?php endforeach; ?></select></label>
      <label class="is-search"><span><?= e($tr('search')) ?></span><input type="search" name="q" maxlength="120" value="<?= e((string)($filters['q'] ?? '')) ?>" placeholder="<?= e($tr('search_placeholder')) ?>"></label>
      <label><span><?= e($tr('date_from')) ?></span><input type="date" name="date_from" value="<?= e((string)($filters['date_from'] ?? '')) ?>"></label>
      <label><span><?= e($tr('date_to')) ?></span><input type="date" name="date_to" value="<?= e((string)($filters['date_to'] ?? '')) ?>"></label>
      <button type="submit"><?= e($tr('apply_filters')) ?></button><a href="/admin/security/sentinel?lang=<?= e($language) ?>"><?= e($tr('clear_filters')) ?></a>
    </form>
    <div class="admin-table-wrap">
      <table class="admin-table zs-sentinel-table">
        <thead><tr><th><?= e($tr('when')) ?></th><th><?= e($tr('event')) ?></th><th><?= e($tr('actor_source')) ?></th><th><?= e($tr('risk')) ?></th><th><?= e($tr('result')) ?></th><th><?= e($tr('technical_context')) ?></th></tr></thead>
        <tbody>
        <?php foreach (($data['events'] ?? []) as $event): ?>
          <tr><td><time><?= e($formatTime($event['occurred_at'] ?? null)) ?></time></td><td><strong><?= e((string)$event['action_label']) ?></strong><small><?= e((string)$event['resource_label']) ?></small></td><td><?= e((string)($event['actor'] ?? '—')) ?><small><?= e((string)($event['email_masked'] ?? '')) ?></small></td><td><span class="zs-dors3-event-badge is-<?= e((string)$event['risk_class']) ?>"><?= e((string)$event['risk_label']) ?></span></td><td><span class="zs-dors3-event-badge is-<?= e((string)$event['result_class']) ?>"><?= e((string)$event['result_label']) ?></span></td><td><details><summary><?= e($tr('details')) ?></summary><div class="zs-sentinel-identifiers"><span><?= e($tr('request_id')) ?><code><?= e((string)($event['request_id'] ?? '—')) ?></code></span><span><?= e($tr('correlation_id')) ?><code><?= e((string)($event['correlation_id'] ?? '—')) ?></code></span><span><?= e($tr('instance_id')) ?><code><?= e((string)($event['instance_id'] ?? '—')) ?></code></span><span><?= e($tr('authentication')) ?><code><?= e((string)($event['authentication_level'] ?? '—')) ?></code></span><span><?= e($tr('network')) ?><code><?= e((string)($event['ip_masked'] ?? '—')) ?></code></span></div></details></td></tr>
        <?php endforeach; ?>
        <?php if (empty($data['events'])): ?><tr><td colspan="6"><?= e($tr('no_events')) ?></td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <nav class="zs-sentinel-pagination" aria-label="<?= e($tr('pagination_label')) ?>"><span><?= e($tr('records', ['count' => (int)$pagination['total']])) ?></span><span><?= e($tr('page', ['page' => (int)$pagination['page'], 'pages' => (int)$pagination['pages']])) ?></span><div><?php if ((int)$pagination['page'] > 1): ?><a href="<?= e($historyQuery(['page' => (int)$pagination['page'] - 1])) ?>"><?= e($tr('previous')) ?></a><?php endif; ?><?php if ((int)$pagination['page'] < (int)$pagination['pages']): ?><a href="<?= e($historyQuery(['page' => (int)$pagination['page'] + 1])) ?>"><?= e($tr('next')) ?></a><?php endif; ?></div></nav>
  </section>
</div>

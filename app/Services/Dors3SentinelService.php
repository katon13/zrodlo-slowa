<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** Read-only, bounded projection for the existing 3DORS Sentinel. */
final class Dors3SentinelService
{
    private const HOT_DAYS = 30;
    private const LOGIN_WINDOW_HOURS = 48;
    private const MAX_LIST_COUNT = 10000;
    private const MAX_OVERVIEW_COUNT = 100000;
    private const ACTIVE_ALERT_PREVIEW = 10;
    private const ACTIVITY_PREVIEW = 20;

    public const FILTERS = [
        'all', 'logins', 'dors3', 'recovery', 'mobile', 'fido2', 'finances', 'warnings', 'critical',
    ];
    public const VIEWS = ['active', 'open', 'acknowledged', 'resolved', 'sessions', 'login_attempts', 'logs', 'archive'];
    public const NAV_VIEWS = ['active', 'open', 'acknowledged', 'resolved', 'logs', 'archive'];
    public const ALERT_STATUSES = ['active', 'open', 'acknowledged', 'resolved'];
    public const ALERT_SEVERITIES = ['all', 'medium', 'high', 'critical'];
    public const SESSION_STATUSES = ['all', 'active', 'ended'];
    public const SESSION_SORTS = ['desc', 'asc'];
    public const LOGIN_RESULTS = ['all', 'success', 'failed', 'blocked', 'new_context'];
    public const PAGE_SIZES = [25, 50, 100];

    public function __construct(private readonly Database $db) {}

    /** @return array{fingerprint:string,open_alerts:int,capped:bool} */
    public function pulse(): array
    {
        $row = $this->db->tableExists('security_alerts')
            ? ($this->db->one(
                'SELECT COUNT(*) AS open_alerts,COALESCE(MAX(id),0) AS latest_id
                 FROM (
                    SELECT id FROM security_alerts
                    WHERE status=\'open\'
                    ORDER BY id DESC
                    LIMIT ' . (self::MAX_LIST_COUNT + 1) . '
                 ) bounded_open_alerts',
            ) ?? [])
            : [];
        $measured = (int)($row['open_alerts'] ?? 0);
        $open = min(self::MAX_LIST_COUNT, $measured);
        return [
            'fingerprint' => hash('sha256', (string)($row['latest_id'] ?? 0) . ':' . $open),
            'open_alerts' => $open,
            'capped' => $measured > self::MAX_LIST_COUNT,
        ];
    }

    /**
     * @param array<string,mixed> $query
     * @param list<array<string,mixed>> $readiness
     * @return array<string,mixed>
     */
    public function dashboard(int $adminId, array $query, array $readiness): array
    {
        $view = in_array((string)($query['view'] ?? ''), self::VIEWS, true)
            ? (string)$query['view']
            : 'active';
        $filter = in_array((string)($query['filter'] ?? ''), self::FILTERS, true)
            ? (string)$query['filter']
            : 'all';
        $search = mb_substr(trim((string)($query['q'] ?? '')), 0, 120);
        $dateFrom = $this->date((string)($query['date_from'] ?? ''));
        $dateTo = $this->date((string)($query['date_to'] ?? ''));
        $actorId = ctype_digit((string)($query['actor_id'] ?? '')) ? (int)$query['actor_id'] : null;
        $page = max(1, min(500, (int)($query['page'] ?? 1)));
        $perPage = $this->pageSize($query['per_page'] ?? 25);

        $alertPage = max(1, min(500, (int)($query['alert_page'] ?? 1)));
        $alertPerPage = $this->pageSize($query['alert_per_page'] ?? 25);
        $alertSeverity = in_array((string)($query['alert_severity'] ?? ''), self::ALERT_SEVERITIES, true)
            ? (string)$query['alert_severity']
            : 'all';
        $alertFilter = in_array((string)($query['alert_filter'] ?? ''), self::FILTERS, true)
            ? (string)$query['alert_filter']
            : 'all';
        $alertSearch = mb_substr(trim((string)($query['alert_q'] ?? '')), 0, 120);
        $alertUser = mb_substr(trim((string)($query['alert_user'] ?? '')), 0, 120);
        $alertDateFrom = $this->date((string)($query['alert_date_from'] ?? ''));
        $alertDateTo = $this->date((string)($query['alert_date_to'] ?? ''));

        $sessionPage = max(1, min(500, (int)($query['session_page'] ?? 1)));
        $sessionPerPage = $this->pageSize($query['session_per_page'] ?? 25);
        $sessionStatus = in_array((string)($query['session_status'] ?? ''), self::SESSION_STATUSES, true)
            ? (string)$query['session_status']
            : 'all';
        $sessionSort = in_array((string)($query['session_sort'] ?? ''), self::SESSION_SORTS, true)
            ? (string)$query['session_sort']
            : 'desc';
        $sessionUser = mb_substr(trim((string)($query['session_user'] ?? '')), 0, 120);
        $sessionDateFrom = $this->date((string)($query['session_date_from'] ?? ''));
        $sessionDateTo = $this->date((string)($query['session_date_to'] ?? ''));

        $loginPage = max(1, min(500, (int)($query['login_page'] ?? 1)));
        $loginPerPage = $this->pageSize($query['login_per_page'] ?? 25);
        $loginResult = in_array((string)($query['login_result'] ?? ''), self::LOGIN_RESULTS, true)
            ? (string)$query['login_result']
            : 'all';
        $loginUser = mb_substr(trim((string)($query['login_user'] ?? '')), 0, 120);
        $loginSearch = mb_substr(trim((string)($query['login_q'] ?? '')), 0, 120);
        $loginDateFrom = $this->date((string)($query['login_date_from'] ?? ''))
            ?? gmdate('Y-m-d', strtotime('-7 days'));
        $loginDateTo = $this->date((string)($query['login_date_to'] ?? '')) ?? gmdate('Y-m-d');

        $events = [];
        $pagination = ['page' => 1, 'pages' => 1, 'per_page' => $perPage, 'total' => 0, 'total_capped' => false];
        if (in_array($view, ['logs', 'archive'], true)) {
            [$from, $baseParams] = $this->eventSource($view === 'archive');
            [$where, $params] = $this->eventWhere($filter, $search, $dateFrom, $dateTo, $actorId);
            $params = array_merge($baseParams, $params);
            $measuredTotal = (int)$this->db->cell(
                'SELECT COUNT(*) FROM (
                    SELECT 1 ' . $from . ' LEFT JOIN users u ON u.id=e.actor_id ' . $where . '
                    LIMIT ' . (self::MAX_LIST_COUNT + 1) . '
                 ) bounded_events',
                $params,
            );
            $totalCapped = $measuredTotal > self::MAX_LIST_COUNT;
            $total = min(self::MAX_LIST_COUNT, $measuredTotal);
            $pages = max(1, min(500, (int)ceil($total / $perPage)));
            $page = min($page, $pages);
            $offset = ($page - 1) * $perPage;
            $rows = $this->db->all(
                'SELECT e.event_id,e.occurred_at,e.action,e.resource_type,e.resource_id,e.result,e.reason,
                        e.risk_level,e.request_id,e.correlation_id,e.instance_id,e.ip,e.authentication_level,
                        u.display_name,u.email
                 ' . $from . '
                 LEFT JOIN users u ON u.id=e.actor_id ' . $where . '
                 ORDER BY e.occurred_at DESC,e.sort_id DESC
                 LIMIT ' . $perPage . ' OFFSET ' . $offset,
                $params,
            );
            $events = array_map(fn(array $event): array => $this->safeEvent($event), $rows);
            $pagination = [
                'page' => $page,
                'pages' => $pages,
                'per_page' => $perPage,
                'total' => $total,
                'total_capped' => $totalCapped,
            ];
        }

        $alertStatus = $view === 'archive'
            ? 'archived'
            : ($view === 'active' ? 'open' : (in_array($view, self::ALERT_STATUSES, true) ? $view : 'open'));
        $showAlerts = in_array($view, [...self::ALERT_STATUSES, 'archive'], true);
        $summary = $this->summary();
        $instances = $this->instances();
        $storage = $this->storageStats();
        $pulse = $this->pulse();
        $alerts = [];
        $alertPagination = $this->emptyPagination($alertPerPage);
        if ($showAlerts) {
            if ($view === 'active') {
                $alerts = $this->alertRows('open', 'all', 'all', '', '', null, null, self::ACTIVE_ALERT_PREVIEW, 0);
                $alertPagination = [
                    'page' => 1,
                    'pages' => max(1, (int)ceil((int)$summary['open_alerts'] / self::ACTIVE_ALERT_PREVIEW)),
                    'per_page' => self::ACTIVE_ALERT_PREVIEW,
                    'total' => (int)$summary['open_alerts'],
                    'total_capped' => !empty($summary['active_alerts_capped']),
                ];
            } else {
                [$alerts, $alertPagination] = $this->alertPage(
                    $alertStatus,
                    $alertSeverity,
                    $alertFilter,
                    $alertSearch,
                    $alertUser,
                    $alertDateFrom,
                    $alertDateTo,
                    $alertPage,
                    $alertPerPage,
                );
            }
        }

        $sessionOverview = $view === 'active' ? $this->activeSessionOverview() : [];
        $sessions = $view === 'active' ? $this->sessionPreview() : [];
        $sessionPagination = $this->emptyPagination($sessionPerPage);
        if ($view === 'sessions') {
            [$sessions, $sessionPagination] = $this->sessionPage(
                $sessionStatus,
                $sessionUser,
                $sessionDateFrom,
                $sessionDateTo,
                $sessionSort,
                $sessionPage,
                $sessionPerPage,
            );
        }

        $loginOverview = $view === 'active' ? $this->loginOverview() : [];
        $loginEvents = $view === 'active' ? $this->loginPreview() : [];
        $loginPagination = $this->emptyPagination($loginPerPage);
        if ($view === 'login_attempts') {
            [$loginEvents, $loginPagination] = $this->loginPage(
                $loginResult,
                $loginUser,
                $loginSearch,
                $loginDateFrom,
                $loginDateTo,
                $loginPage,
                $loginPerPage,
            );
        }

        return [
            'admin_id' => $adminId,
            'view' => $view,
            'summary' => $summary,
            'system_status' => $this->systemStatus($summary, $instances, $storage),
            'instances' => $instances,
            'readiness' => $readiness,
            'login_events' => $loginEvents,
            'login_overview' => $loginOverview,
            'login_pagination' => $loginPagination,
            'sessions' => $sessions,
            'session_overview' => $sessionOverview,
            'session_pagination' => $sessionPagination,
            'ended_sessions' => [],
            'alerts' => $alerts,
            'alert_pagination' => $alertPagination,
            'events' => $events,
            'actors' => in_array($view, ['logs', 'archive'], true) ? $this->actors() : [],
            'archive_batches' => $view === 'archive'
                ? $this->safeArchiveBatches((new Dors3SentinelArchiveService($this->db))->recentBatches())
                : [],
            'archive_cutoff_default' => gmdate('Y-m-d', strtotime('-90 days')),
            'storage' => $storage,
            'pulse' => $pulse,
            'filters' => [
                'view' => $view,
                'filter' => $filter,
                'q' => $search,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'actor_id' => $actorId,
                'alert_status' => $alertStatus,
                'alert_severity' => $alertSeverity,
                'alert_filter' => $alertFilter,
                'alert_q' => $alertSearch,
                'alert_user' => $alertUser,
                'alert_date_from' => $alertDateFrom,
                'alert_date_to' => $alertDateTo,
                'alert_page' => $alertPage,
                'alert_per_page' => $alertPerPage,
                'session_status' => $sessionStatus,
                'session_sort' => $sessionSort,
                'session_user' => $sessionUser,
                'session_date_from' => $sessionDateFrom,
                'session_date_to' => $sessionDateTo,
                'session_page' => $sessionPage,
                'session_per_page' => $sessionPerPage,
                'login_result' => $loginResult,
                'login_user' => $loginUser,
                'login_q' => $loginSearch,
                'login_date_from' => $loginDateFrom,
                'login_date_to' => $loginDateTo,
                'login_page' => $loginPage,
                'login_per_page' => $loginPerPage,
            ],
            'pagination' => $pagination,
        ];
    }

    /**
     * @param array<string,mixed> $settings
     * @param array<string,mixed> $webAuthn
     * @param list<array<string,mixed>> $policies
     * @param list<array<string,mixed>> $devices
     * @return list<array<string,mixed>>
     */
    public function readiness(array $settings, array $webAuthn, array $policies, array $devices): array
    {
        $mobile = is_array($settings['mobile'] ?? null) ? $settings['mobile'] : [];
        $items = [];
        foreach (['admin', 'author'] as $variant) {
            $enabled = !empty($mobile[$variant . '_app_enabled']);
            $activeDevices = count(array_filter(
                $devices,
                static fn(array $device): bool => (string)($device['application_variant'] ?? '') === $variant
                    && (string)($device['status'] ?? '') === 'active',
            ));
            $variantPolicies = array_values(array_filter(
                $policies,
                static fn(array $policy): bool => (string)($policy['application_variant'] ?? '') === $variant,
            ));
            $readyPolicies = count(array_filter(
                $variantPolicies,
                static fn(array $policy): bool => !empty($policy['ready']),
            ));
            $status = !$enabled
                ? 'inactive'
                : ($activeDevices > 0 && $readyPolicies === count($variantPolicies) && $variantPolicies !== [] ? 'ok' : 'warning');
            $items[] = [
                'key' => 'dors3_' . $variant,
                'status' => $status,
                'active_devices' => $activeDevices,
                'ready_policies' => $readyPolicies,
                'total_policies' => count($variantPolicies),
            ];
        }
        $items[] = [
            'key' => 'mobile',
            'status' => empty($mobile['enabled']) ? 'inactive' : 'ok',
            'mode' => (string)($mobile['mode'] ?? 'disabled'),
        ];
        $items[] = [
            'key' => 'fido2',
            'status' => !empty($webAuthn['authorization_ready']) ? 'ok' : 'inactive',
            'library_ready' => !empty($webAuthn['library_ready']),
            'attestation_ready' => !empty($webAuthn['attestation_ready']),
        ];
        return $items;
    }

    /** @return array<string,int> */
    private function summary(): array
    {
        $row = $this->db->one(
            'SELECT
                COUNT(*) AS events_24h,
                COUNT(*) FILTER (WHERE risk_level=\'high\') AS high_24h,
                COUNT(*) FILTER (WHERE risk_level=\'critical\') AS critical_24h,
                COUNT(*) FILTER (WHERE result IN (\'failure\',\'blocked\',\'rejected\',\'warning\')) AS attention_24h
             FROM (
                SELECT risk_level,result
                FROM security_events
                WHERE occurred_at>=NOW()-INTERVAL \'24 hours\'
                ORDER BY occurred_at DESC,id DESC
                LIMIT ' . (self::MAX_OVERVIEW_COUNT + 1) . '
             ) bounded_events',
        ) ?? [];
        $alerts = $this->db->tableExists('security_alerts')
            ? ($this->db->one(
                'SELECT
                    COUNT(*) FILTER (WHERE status=\'open\') AS open_alerts,
                    COUNT(*) FILTER (WHERE status=\'acknowledged\') AS acknowledged_alerts,
                    COUNT(*) FILTER (WHERE status<>\'resolved\' AND severity=\'critical\') AS active_critical,
                    COUNT(*) FILTER (WHERE status<>\'resolved\' AND severity=\'high\') AS active_high,
                    COUNT(*) FILTER (WHERE status<>\'resolved\' AND severity=\'medium\') AS active_medium,
                    COALESCE(MAX(id),0) AS latest_active_alert_id,
                    COALESCE(MAX(id) FILTER (WHERE status=\'open\'),0) AS latest_open_alert_id
                 FROM (
                    SELECT id,status,severity
                    FROM security_alerts
                    WHERE status<>\'resolved\'
                    ORDER BY status_changed_at DESC,id DESC
                    LIMIT ' . (self::MAX_LIST_COUNT + 1) . '
                 ) bounded_alerts',
            ) ?? [])
            : [];
        $eventsMeasured = (int)($row['events_24h'] ?? 0);
        $activeMeasured = (int)($alerts['open_alerts'] ?? 0) + (int)($alerts['acknowledged_alerts'] ?? 0);
        return [
            'events_24h' => min(self::MAX_OVERVIEW_COUNT, $eventsMeasured),
            'events_24h_capped' => $eventsMeasured > self::MAX_OVERVIEW_COUNT ? 1 : 0,
            'high_24h' => (int)($row['high_24h'] ?? 0),
            'critical_24h' => (int)($row['critical_24h'] ?? 0),
            'attention_24h' => (int)($row['attention_24h'] ?? 0),
            'open_alerts' => (int)($alerts['open_alerts'] ?? 0),
            'acknowledged_alerts' => (int)($alerts['acknowledged_alerts'] ?? 0),
            'active_critical' => (int)($alerts['active_critical'] ?? 0),
            'active_high' => (int)($alerts['active_high'] ?? 0),
            'active_medium' => (int)($alerts['active_medium'] ?? 0),
            'active_alerts_capped' => $activeMeasured > self::MAX_LIST_COUNT ? 1 : 0,
            'latest_active_alert_id' => (int)($alerts['latest_active_alert_id'] ?? 0),
            'latest_open_alert_id' => (int)($alerts['latest_open_alert_id'] ?? 0),
        ];
    }

    /** @return array{status:string,reason:string} */
    private function systemStatus(array $summary, array $instances, array $storage): array
    {
        $missing = count(array_filter($instances, static fn(array $item): bool => (string)$item['status'] === 'unknown'));
        $failed = count(array_filter($instances, static fn(array $item): bool => (string)$item['status'] === 'error'));
        if ($summary['active_critical'] > 0 || $failed > 0) {
            return ['status' => 'critical', 'reason' => $summary['active_critical'] > 0 ? 'critical_alerts' : 'instance_error'];
        }
        if ((string)($storage['status'] ?? '') === 'critical') {
            return ['status' => 'critical', 'reason' => 'storage_critical'];
        }
        if ($summary['active_high'] > 0) {
            return ['status' => 'high', 'reason' => 'high_alerts'];
        }
        if ($missing === count($instances) && $instances !== []) {
            return ['status' => 'unknown', 'reason' => 'instances_unknown'];
        }
        if ($missing > 0 || $summary['acknowledged_alerts'] > 0 || $summary['active_medium'] > 0
            || (string)($storage['status'] ?? '') === 'warning') {
            $reason = $missing > 0
                ? 'instance_stale'
                : ((string)($storage['status'] ?? '') === 'warning' ? 'storage_warning' : 'alerts_acknowledged');
            return ['status' => 'warning', 'reason' => $reason];
        }
        return ['status' => 'ok', 'reason' => 'no_active_alerts'];
    }

    /** @return list<array<string,mixed>> */
    private function instances(): array
    {
        $expected = $this->expectedInstances();
        $rows = $this->db->tableExists('security_instance_heartbeats')
            ? $this->db->all(
                'SELECT instance_id,instance_role,expected,ready,last_seen_at,last_ready_at,
                        EXTRACT(EPOCH FROM (NOW()-last_seen_at))::integer AS age_seconds
                 FROM security_instance_heartbeats
                 WHERE expected=TRUE OR instance_id IN (' . implode(',', array_fill(0, count($expected), '?')) . ')
                 ORDER BY instance_id',
                $expected,
            )
            : [];
        $byId = [];
        foreach ($rows as $row) {
            $byId[(string)$row['instance_id']] = $row;
        }
        $result = [];
        $staleAfter = max(20, min(300, (int)env('SENTINEL_HEARTBEAT_STALE_SECONDS', 45)));
        foreach ($expected as $instanceId) {
            $row = $byId[$instanceId] ?? null;
            $age = $row !== null ? (int)$row['age_seconds'] : null;
            $status = $row === null || $age === null || $age > $staleAfter
                ? 'unknown'
                : (!empty($row['ready']) ? 'ok' : 'error');
            $result[] = [
                'instance_id' => $instanceId,
                'role' => 'application',
                'status' => $status,
                'ready' => $row !== null && !empty($row['ready']),
                'age_seconds' => $age,
                'last_seen_at' => $row['last_seen_at'] ?? null,
                'last_ready_at' => $row['last_ready_at'] ?? null,
            ];
        }
        return $result;
    }

    /**
     * @return array{0:list<array<string,mixed>>,1:array{page:int,pages:int,per_page:int,total:int,total_capped:bool}}
     */
    private function alertPage(
        string $status,
        string $severity,
        string $filter,
        string $search,
        string $user,
        ?string $dateFrom,
        ?string $dateTo,
        int $page,
        int $perPage,
    ): array {
        if (!$this->db->tableExists('security_alerts')) {
            return [[], $this->emptyPagination($perPage)];
        }
        [$where, $params] = $this->alertWhere($status, $severity, $filter, $search, $user, $dateFrom, $dateTo);
        $measured = (int)$this->db->cell(
            'SELECT COUNT(*) FROM (
                SELECT 1
                FROM security_alerts a
                JOIN security_events e ON e.id=a.source_event_id
                LEFT JOIN users u ON u.id=e.actor_id
                ' . $where . '
                LIMIT ' . (self::MAX_LIST_COUNT + 1) . '
             ) bounded_alerts',
            $params,
        );
        $capped = $measured > self::MAX_LIST_COUNT;
        $total = min(self::MAX_LIST_COUNT, $measured);
        $pages = max(1, min(500, (int)ceil($total / $perPage)));
        $page = min($page, $pages);
        $rows = $this->alertRows(
            $status,
            $severity,
            $filter,
            $search,
            $user,
            $dateFrom,
            $dateTo,
            $perPage,
            ($page - 1) * $perPage,
        );
        return [$rows, [
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
            'total' => $total,
            'total_capped' => $capped,
        ]];
    }

    /** @return list<array<string,mixed>> */
    private function alertRows(
        string $status,
        string $severity,
        string $filter,
        string $search,
        string $user,
        ?string $dateFrom,
        ?string $dateTo,
        int $limit,
        int $offset,
    ): array {
        if (!$this->db->tableExists('security_alerts')) {
            return [];
        }
        [$where, $params] = $this->alertWhere($status, $severity, $filter, $search, $user, $dateFrom, $dateTo);
        $rows = $this->db->all(
            'SELECT a.id,a.public_id,a.operation_key,a.severity,a.status,a.opened_at,a.acknowledged_at,a.resolved_at,
                    a.resolution_note,a.resolution_code,a.status_changed_at,a.event_count,a.last_event_at,
                    e.event_id,e.occurred_at,e.action,COALESCE(NULLIF(e.metadata->>\'operation\',\'\'),e.action) AS operation_action,
                    e.resource_type,e.resource_id,e.result,e.reason,e.risk_level,e.request_id,e.correlation_id,
                    e.instance_id,e.authentication_level,u.display_name,u.email,
                    acknowledged.display_name AS acknowledged_by_name,resolved.display_name AS resolved_by_name,
                    (SELECT COUNT(*) FROM security_alert_transitions t WHERE t.alert_id=a.id) AS transition_count
             FROM security_alerts a
             JOIN security_events e ON e.id=a.source_event_id
             LEFT JOIN users u ON u.id=e.actor_id
             LEFT JOIN users acknowledged ON acknowledged.id=a.acknowledged_by
             LEFT JOIN users resolved ON resolved.id=a.resolved_by
             ' . $where . '
             ORDER BY CASE a.severity WHEN \'critical\' THEN 0 WHEN \'high\' THEN 1 ELSE 2 END,
                      a.status_changed_at DESC,a.id DESC
             LIMIT ' . max(1, min(100, $limit)) . ' OFFSET ' . max(0, $offset),
            $params,
        );
        $stages = $this->alertStages($rows);
        foreach ($rows as &$row) {
            $correlation = (string)($row['correlation_id'] ?? '');
            $row['stages'] = $correlation !== '' ? ($stages[$correlation] ?? []) : [];
            $row = $this->safeEvent($row);
        }
        unset($row);
        return $rows;
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function alertWhere(
        string $status,
        string $severity,
        string $filter,
        string $search,
        string $user,
        ?string $dateFrom,
        ?string $dateTo,
    ): array {
        $conditions = [match ($status) {
            'resolved' => 'a.status=\'resolved\' AND a.resolved_at>=NOW()-INTERVAL \'30 days\'',
            'archived' => 'a.status=\'resolved\' AND a.resolved_at<NOW()-INTERVAL \'30 days\'',
            'acknowledged' => 'a.status=\'acknowledged\'',
            default => 'a.status=\'open\'',
        }];
        $params = [];
        if ($severity !== 'all') {
            $conditions[] = 'a.severity=:alert_severity';
            $params['alert_severity'] = $severity;
        }
        $conditions[] = match ($filter) {
            'logins' => '(LOWER(e.action) LIKE \'%login%\' OR e.resource_type=\'session\')',
            'dors3' => '(LOWER(e.action) LIKE \'mobile.%\' OR LOWER(e.action) LIKE \'security.%\')',
            'recovery' => '(LOWER(e.action) LIKE \'%recovery%\' OR LOWER(COALESCE(e.resource_type,\'\')) LIKE \'%recovery%\')',
            'mobile' => '(LOWER(e.action) LIKE \'mobile.%\' OR LOWER(COALESCE(e.resource_type,\'\')) LIKE \'mobile_%\')',
            'fido2' => '(LOWER(e.action) LIKE \'%fido%\' OR LOWER(e.action) LIKE \'%webauthn%\')',
            'finances' => '(LOWER(e.action) ~ \'payout|payment|wallet|financial|safety_fund\' OR LOWER(COALESCE(e.resource_type,\'\')) ~ \'payout|payment|wallet|financial|safety_fund\')',
            'warnings' => 'a.severity IN (\'medium\',\'high\',\'critical\')',
            'critical' => 'a.severity=\'critical\'',
            default => '1=1',
        };
        if ($search !== '') {
            $conditions[] = 'LOWER(CONCAT_WS(\' \',a.public_id,a.operation_key,e.action,e.resource_type,e.resource_id,e.request_id,e.correlation_id,u.display_name,u.email)) LIKE :alert_search';
            $params['alert_search'] = '%' . mb_strtolower($search) . '%';
        }
        if ($user !== '') {
            if (ctype_digit($user)) {
                $conditions[] = 'e.actor_id=:alert_actor_id';
                $params['alert_actor_id'] = (int)$user;
            } else {
                $conditions[] = '(LOWER(COALESCE(u.display_name,\'\')) LIKE :alert_user OR LOWER(COALESCE(u.email,\'\')) LIKE :alert_user)';
                $params['alert_user'] = mb_strtolower($user) . '%';
            }
        }
        if ($dateFrom !== null) {
            $conditions[] = 'COALESCE(a.last_event_at,a.opened_at)>=:alert_date_from';
            $params['alert_date_from'] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo !== null) {
            $conditions[] = 'COALESCE(a.last_event_at,a.opened_at)<CAST(:alert_date_to AS timestamp)+INTERVAL \'1 day\'';
            $params['alert_date_to'] = $dateTo . ' 00:00:00';
        }
        return ['WHERE ' . implode(' AND ', $conditions), $params];
    }

    /** @param list<array<string,mixed>> $alerts @return array<string,list<array<string,mixed>>> */
    private function alertStages(array $alerts): array
    {
        $correlations = array_values(array_unique(array_filter(array_map(
            static fn(array $row): string => trim((string)($row['correlation_id'] ?? '')),
            $alerts,
        ))));
        if ($correlations === []) {
            return [];
        }
        $rows = $this->db->all(
            'SELECT * FROM (
                SELECT e.event_id,e.occurred_at,e.action,e.resource_type,e.resource_id,e.result,e.reason,
                       e.risk_level,e.request_id,e.correlation_id,e.instance_id,e.authentication_level,
                       u.display_name,u.email,
                       ROW_NUMBER() OVER (PARTITION BY e.correlation_id ORDER BY e.occurred_at,e.id) AS stage_number
                FROM security_events e
                LEFT JOIN users u ON u.id=e.actor_id
                WHERE e.correlation_id IN (' . implode(',', array_fill(0, count($correlations), '?')) . ')
             ) stages
             WHERE stage_number<=20
             ORDER BY correlation_id,occurred_at,stage_number',
            $correlations,
        );
        $result = [];
        foreach ($rows as $row) {
            $result[(string)$row['correlation_id']][] = $this->safeEvent($row);
        }
        return $result;
    }

    /** @return array{attempts:int,capped:bool} */
    private function loginOverview(): array
    {
        $measured = (int)$this->db->cell(
            'SELECT COUNT(*) FROM (
                SELECT 1 FROM auth_login_events
                WHERE created_at>=NOW()-INTERVAL \'' . self::LOGIN_WINDOW_HOURS . ' hours\'
                ORDER BY created_at DESC,id DESC
                LIMIT ' . (self::MAX_OVERVIEW_COUNT + 1) . '
             ) bounded_logins',
        );
        return [
            'attempts' => min(self::MAX_OVERVIEW_COUNT, $measured),
            'capped' => $measured > self::MAX_OVERVIEW_COUNT,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function loginPreview(): array
    {
        return $this->loginRows(
            'all',
            '',
            '',
            gmdate('Y-m-d H:i:s', time() - (self::LOGIN_WINDOW_HOURS * 3600)),
            gmdate('Y-m-d H:i:s', time() + 60),
            self::ACTIVITY_PREVIEW,
            0,
        );
    }

    /**
     * @return array{0:list<array<string,mixed>>,1:array{page:int,pages:int,per_page:int,total:int,total_capped:bool}}
     */
    private function loginPage(
        string $result,
        string $user,
        string $search,
        string $dateFrom,
        string $dateTo,
        int $page,
        int $perPage,
    ): array {
        [$cte, $params, $groupWhere] = $this->loginGroupCte(
            $result,
            $user,
            $search,
            $dateFrom . ' 00:00:00',
            gmdate('Y-m-d H:i:s', strtotime($dateTo . ' +1 day')),
        );
        $measured = (int)$this->db->cell(
            $cte . ' SELECT COUNT(*) FROM (
                SELECT 1 FROM login_groups g ' . $groupWhere . '
                LIMIT ' . (self::MAX_LIST_COUNT + 1) . '
             ) bounded_login_groups',
            $params,
        );
        $capped = $measured > self::MAX_LIST_COUNT;
        $total = min(self::MAX_LIST_COUNT, $measured);
        $pages = max(1, min(500, (int)ceil($total / $perPage)));
        $page = min($page, $pages);
        $rows = $this->loginRows(
            $result,
            $user,
            $search,
            $dateFrom . ' 00:00:00',
            gmdate('Y-m-d H:i:s', strtotime($dateTo . ' +1 day')),
            $perPage,
            ($page - 1) * $perPage,
        );
        return [$rows, [
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
            'total' => $total,
            'total_capped' => $capped,
        ]];
    }

    /** @return list<array<string,mixed>> */
    private function loginRows(
        string $result,
        string $user,
        string $search,
        string $dateFrom,
        string $dateTo,
        int $limit,
        int $offset,
    ): array {
        [$cte, $params, $groupWhere] = $this->loginGroupCte($result, $user, $search, $dateFrom, $dateTo);
        $rows = $this->db->all(
            $cte . ', page_groups AS (
                SELECT g.* FROM login_groups g ' . $groupWhere . '
                ORDER BY g.last_at DESC,g.bucket DESC
                LIMIT ' . max(1, min(100, $limit)) . ' OFFSET ' . max(0, $offset) . '
             )
             SELECT g.*,
                    (
                        SELECT jsonb_agg(jsonb_build_object(\'created_at\',sample.created_at,\'result\',sample.result) ORDER BY sample.created_at DESC)
                        FROM (
                            SELECT l2.created_at,l2.result
                            FROM auth_login_events l2
                            WHERE l2.created_at>=g.bucket AND l2.created_at<g.bucket+INTERVAL \'5 minutes\'
                              AND l2.result=g.result
                              AND l2.user_id IS NOT DISTINCT FROM g.user_id
                              AND l2.email IS NOT DISTINCT FROM g.email
                              AND l2.ip_hash IS NOT DISTINCT FROM g.ip_hash
                              AND l2.user_agent_hash IS NOT DISTINCT FROM g.user_agent_hash
                            ORDER BY l2.created_at DESC,l2.id DESC
                            LIMIT 20
                        ) sample
                    ) AS samples
             FROM page_groups g
             ORDER BY g.last_at DESC,g.bucket DESC',
            $params,
        );
        foreach ($rows as &$row) {
            $row['account'] = trim((string)($row['display_name'] ?? ''))
                ?: $this->maskEmail((string)($row['email'] ?? ''));
            $row['email_masked'] = $this->maskEmail((string)($row['email'] ?? ''));
            $row['context_changed'] = !empty($row['context_changed']);
            $row['samples'] = is_string($row['samples'] ?? null)
                ? (json_decode((string)$row['samples'], true) ?: [])
                : (is_array($row['samples'] ?? null) ? $row['samples'] : []);
            unset($row['email'], $row['display_name'], $row['ip_hash'], $row['user_agent_hash'], $row['user_id']);
        }
        unset($row);
        return $rows;
    }

    /** @return array{0:string,1:array<string,mixed>,2:string} */
    private function loginGroupCte(
        string $result,
        string $user,
        string $search,
        string $dateFrom,
        string $dateTo,
    ): array {
        $conditions = [
            'l.created_at>=:login_from',
            'l.created_at<CAST(:login_to AS timestamp)',
        ];
        $params = ['login_from' => $dateFrom, 'login_to' => $dateTo];
        if ($result === 'success') {
            $conditions[] = "LOWER(l.result) IN ('password_ok','success','ok')";
        } elseif ($result === 'failed') {
            $conditions[] = "LOWER(l.result) ~ 'fail|reject|invalid'";
        } elseif ($result === 'blocked') {
            $conditions[] = "LOWER(l.result) ~ 'block|lock|limit'";
        }
        if ($user !== '') {
            if (ctype_digit($user)) {
                $conditions[] = 'l.user_id=:login_actor_id';
                $params['login_actor_id'] = (int)$user;
            } else {
                $conditions[] = '(LOWER(COALESCE(u.display_name,\'\')) LIKE :login_user OR LOWER(COALESCE(u.email,l.email,\'\')) LIKE :login_user)';
                $params['login_user'] = mb_strtolower($user) . '%';
            }
        }
        if ($search !== '') {
            $conditions[] = 'LOWER(CONCAT_WS(\' \',u.display_name,u.email,l.email,l.result)) LIKE :login_search';
            $params['login_search'] = '%' . mb_strtolower($search) . '%';
        }
        $groupWhere = $result === 'new_context' ? 'WHERE g.context_changed=TRUE' : '';
        $bucket = "date_bin(INTERVAL '5 minutes',l.created_at,TIMESTAMP '2001-01-01')";
        $cte = 'WITH grouped_logins AS (
            SELECT ' . $bucket . ' AS bucket,l.result,l.user_id,l.email,l.ip_hash,l.user_agent_hash,u.display_name,
                   COUNT(*) AS attempt_count,MIN(l.created_at) AS first_at,MAX(l.created_at) AS last_at
            FROM auth_login_events l
            LEFT JOIN users u ON u.id=l.user_id
            WHERE ' . implode(' AND ', $conditions) . '
            GROUP BY bucket,l.result,l.user_id,l.email,l.ip_hash,l.user_agent_hash,u.display_name
        ), login_groups AS (
            SELECT grouped.*,
                   EXISTS (
                       SELECT 1 FROM security_events se
                       WHERE grouped.user_id IS NOT NULL AND se.actor_id=grouped.user_id
                         AND se.action=\'security.login.new_context\'
                         AND se.occurred_at>=grouped.bucket
                         AND se.occurred_at<grouped.bucket+INTERVAL \'5 minutes\'
                       LIMIT 1
                   ) AS context_changed
            FROM grouped_logins grouped
        )';
        return [$cte, $params, $groupWhere];
    }

    /** @return array{active:int,capped:bool} */
    private function activeSessionOverview(): array
    {
        $minimum = $this->activeSessionMinimum();
        $measured = (int)$this->db->cell(
            'SELECT COUNT(*) FROM (
                SELECT 1 FROM sessions s
                WHERE s.user_id IS NOT NULL AND s.last_activity>=:minimum
                ORDER BY s.last_activity DESC,s.id
                LIMIT ' . (self::MAX_OVERVIEW_COUNT + 1) . '
             ) bounded_sessions',
            ['minimum' => $minimum],
        );
        return [
            'active' => min(self::MAX_OVERVIEW_COUNT, $measured),
            'capped' => $measured > self::MAX_OVERVIEW_COUNT,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function sessionPreview(): array
    {
        $rows = $this->db->all(
            'SELECT s.id,s.last_activity,u.display_name,u.email
             FROM sessions s
             LEFT JOIN users u ON u.id=s.user_id
             WHERE s.user_id IS NOT NULL AND s.last_activity>=:minimum
             ORDER BY s.last_activity DESC,s.id
             LIMIT ' . self::ACTIVITY_PREVIEW,
            ['minimum' => $this->activeSessionMinimum()],
        );
        foreach ($rows as &$row) {
            $row['public_id'] = substr(hash('sha256', (string)$row['id']), 0, 16);
            $row['account'] = trim((string)($row['display_name'] ?? ''))
                ?: $this->maskEmail((string)($row['email'] ?? ''));
            $row['email_masked'] = $this->maskEmail((string)($row['email'] ?? ''));
            $row['session_status'] = 'active';
            unset($row['id'], $row['email'], $row['display_name']);
        }
        unset($row);
        return $rows;
    }

    /**
     * @return array{0:list<array<string,mixed>>,1:array{page:int,pages:int,per_page:int,total:int,total_capped:bool}}
     */
    private function sessionPage(
        string $status,
        string $user,
        ?string $dateFrom,
        ?string $dateTo,
        string $sort,
        int $page,
        int $perPage,
    ): array {
        [$source, $params] = $this->sessionSource($status);
        $conditions = ['1=1'];
        if ($user !== '') {
            if (ctype_digit($user)) {
                $conditions[] = 'session_rows.actor_id=:session_actor_id';
                $params['session_actor_id'] = (int)$user;
            } else {
                $conditions[] = '(LOWER(COALESCE(u.display_name,\'\')) LIKE :session_user OR LOWER(COALESCE(u.email,\'\')) LIKE :session_user)';
                $params['session_user'] = mb_strtolower($user) . '%';
            }
        }
        if ($dateFrom !== null) {
            $conditions[] = 'session_rows.occurred_at>=:session_date_from';
            $params['session_date_from'] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo !== null) {
            $conditions[] = 'session_rows.occurred_at<CAST(:session_date_to AS timestamp)+INTERVAL \'1 day\'';
            $params['session_date_to'] = $dateTo . ' 00:00:00';
        }
        $where = 'WHERE ' . implode(' AND ', $conditions);
        $measured = (int)$this->db->cell(
            'SELECT COUNT(*) FROM (
                SELECT 1 FROM (' . $source . ') session_rows
                LEFT JOIN users u ON u.id=session_rows.actor_id
                ' . $where . '
                LIMIT ' . (self::MAX_LIST_COUNT + 1) . '
             ) bounded_sessions',
            $params,
        );
        $capped = $measured > self::MAX_LIST_COUNT;
        $total = min(self::MAX_LIST_COUNT, $measured);
        $pages = max(1, min(500, (int)ceil($total / $perPage)));
        $page = min($page, $pages);
        $direction = $sort === 'asc' ? 'ASC' : 'DESC';
        $rows = $this->db->all(
            'SELECT session_rows.*,u.display_name,u.email
             FROM (' . $source . ') session_rows
             LEFT JOIN users u ON u.id=session_rows.actor_id
             ' . $where . '
             ORDER BY session_rows.occurred_at ' . $direction . ',session_rows.row_key ' . $direction . '
             LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
            $params,
        );
        foreach ($rows as &$row) {
            $row['public_id'] = substr(hash('sha256', (string)$row['row_key']), 0, 16);
            $row['account'] = trim((string)($row['display_name'] ?? ''))
                ?: $this->maskEmail((string)($row['email'] ?? ''));
            $row['email_masked'] = $this->maskEmail((string)($row['email'] ?? ''));
            unset($row['row_key'], $row['actor_id'], $row['display_name'], $row['email']);
        }
        unset($row);
        return [$rows, [
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
            'total' => $total,
            'total_capped' => $capped,
        ]];
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function sessionSource(string $status): array
    {
        $parts = [];
        $params = [];
        if (in_array($status, ['all', 'active'], true)) {
            $parts[] = 'SELECT CONCAT(\'active:\',s.id) AS row_key,\'active\'::text AS session_status,
                               (TO_TIMESTAMP(s.last_activity) AT TIME ZONE \'UTC\')::timestamp AS occurred_at,
                               s.user_id AS actor_id
                        FROM sessions s
                        WHERE s.user_id IS NOT NULL AND s.last_activity>=:session_minimum';
            $params['session_minimum'] = $this->activeSessionMinimum();
        }
        if (in_array($status, ['all', 'ended'], true)) {
            $parts[] = 'SELECT CONCAT(\'ended:\',e.event_id) AS row_key,\'ended\'::text AS session_status,
                               e.occurred_at,e.actor_id
                        FROM security_events e
                        WHERE e.action IN (\'security.admin_session.ended\',\'security.admin_session.max_expired\')';
        }
        return [implode(' UNION ALL ', $parts), $params];
    }

    private function activeSessionMinimum(): int
    {
        return time() - max(300, (int)env('SESSION_TTL_SECONDS', 86400));
    }

    /** @return list<array{id:int,label:string}> */
    private function actors(): array
    {
        $rows = $this->db->all(
            'SELECT DISTINCT u.id,u.display_name,u.email
             FROM security_events e
             JOIN users u ON u.id=e.actor_id
             WHERE e.occurred_at>=NOW()-INTERVAL \'180 days\'
             ORDER BY u.display_name,u.id LIMIT 100',
        );
        return array_map(fn(array $row): array => [
            'id' => (int)$row['id'],
            'label' => trim((string)($row['display_name'] ?? '')) ?: $this->maskEmail((string)($row['email'] ?? '')),
        ], $rows);
    }

    /** @return array{status:string,total_bytes:int,warning_bytes:int,critical_bytes:int,tables:list<array{name:string,rows:int,bytes:int}>} */
    private function storageStats(): array
    {
        $names = ['security_events', 'auth_login_events', 'security_events_archive', 'auth_login_events_archive'];
        $rows = $this->db->all(
            'SELECT relname,n_live_tup::bigint AS estimated_rows,pg_total_relation_size(relid)::bigint AS total_bytes
             FROM pg_stat_user_tables
             WHERE schemaname=current_schema() AND relname IN (' . implode(',', array_fill(0, count($names), '?')) . ')
             ORDER BY relname',
            $names,
        );
        $byName = [];
        foreach ($rows as $row) {
            $byName[(string)$row['relname']] = $row;
        }
        $tables = [];
        $totalBytes = 0;
        foreach ($names as $name) {
            $row = $byName[$name] ?? [];
            $bytes = (int)($row['total_bytes'] ?? 0);
            $totalBytes += $bytes;
            $tables[] = [
                'name' => $name,
                'rows' => max(0, (int)($row['estimated_rows'] ?? 0)),
                'bytes' => max(0, $bytes),
            ];
        }
        $warningBytes = max(104857600, (int)env('SENTINEL_LOG_STORAGE_WARNING_BYTES', 1073741824));
        $criticalBytes = max($warningBytes + 1, (int)env('SENTINEL_LOG_STORAGE_CRITICAL_BYTES', 5368709120));
        $status = $totalBytes >= $criticalBytes ? 'critical' : ($totalBytes >= $warningBytes ? 'warning' : 'ok');
        return [
            'status' => $status,
            'total_bytes' => $totalBytes,
            'warning_bytes' => $warningBytes,
            'critical_bytes' => $criticalBytes,
            'tables' => $tables,
        ];
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function eventSource(bool $archive): array
    {
        if (!$archive) {
            return [
                'FROM (
                    SELECT live.id::text AS sort_id,live.event_id,live.occurred_at,live.actor_id,live.action,
                           live.resource_type,live.resource_id,live.request_id,live.correlation_id,live.instance_id,
                           live.ip,live.authentication_level,live.result,live.reason,live.risk_level
                    FROM security_events live
                    WHERE live.occurred_at>=NOW()-INTERVAL \'' . self::HOT_DAYS . ' days\'
                 ) e',
                [],
            ];
        }
        $archiveUnion = $this->db->tableExists('security_events_archive')
            ? 'UNION ALL
               SELECT CONCAT(\'a\',cold.archive_id::text),cold.event_id,cold.occurred_at,cold.actor_id,cold.action,
                      cold.resource_type,cold.resource_id,cold.request_id,cold.correlation_id,cold.instance_id,
                      cold.ip,cold.authentication_level,cold.result,cold.reason,cold.risk_level
               FROM security_events_archive cold'
            : '';
        return [
            'FROM (
                SELECT CONCAT(\'l\',live.id::text) AS sort_id,live.event_id,live.occurred_at,live.actor_id,live.action,
                       live.resource_type,live.resource_id,live.request_id,live.correlation_id,live.instance_id,
                       live.ip,live.authentication_level,live.result,live.reason,live.risk_level
                FROM security_events live
                WHERE live.occurred_at<NOW()-INTERVAL \'' . self::HOT_DAYS . ' days\'
                ' . $archiveUnion . '
             ) e',
            [],
        ];
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function eventWhere(
        string $filter,
        string $search,
        ?string $dateFrom,
        ?string $dateTo,
        ?int $actorId,
    ): array {
        $conditions = ['1=1'];
        $params = [];
        $conditions[] = match ($filter) {
            'logins' => '(LOWER(e.action) LIKE \'%login%\' OR e.resource_type=\'session\')',
            'dors3' => '(LOWER(e.action) LIKE \'mobile.%\' OR LOWER(e.action) LIKE \'security.%\')',
            'recovery' => '(LOWER(e.action) LIKE \'%recovery%\' OR LOWER(COALESCE(e.resource_type,\'\')) LIKE \'%recovery%\')',
            'mobile' => '(LOWER(e.action) LIKE \'mobile.%\' OR LOWER(COALESCE(e.resource_type,\'\')) LIKE \'mobile_%\')',
            'fido2' => '(LOWER(e.action) LIKE \'%fido%\' OR LOWER(e.action) LIKE \'%webauthn%\' OR LOWER(COALESCE(e.resource_type,\'\')) LIKE \'%webauthn%\')',
            'finances' => '(LOWER(e.action) ~ \'payout|payment|wallet|financial|safety_fund\' OR LOWER(COALESCE(e.resource_type,\'\')) ~ \'payout|payment|wallet|financial|safety_fund\')',
            'warnings' => '(e.risk_level IN (\'medium\',\'high\',\'critical\') OR e.result IN (\'failure\',\'blocked\',\'rejected\',\'warning\'))',
            'critical' => 'e.risk_level=\'critical\'',
            default => '1=1',
        };
        if ($search !== '') {
            $conditions[] = 'LOWER(CONCAT_WS(\' \',e.event_id,e.action,e.resource_type,e.resource_id,e.request_id,e.correlation_id,e.instance_id,u.display_name,u.email)) LIKE :search';
            $params['search'] = '%' . mb_strtolower($search) . '%';
        }
        if ($dateFrom !== null) {
            $conditions[] = 'e.occurred_at>=:date_from';
            $params['date_from'] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo !== null) {
            $conditions[] = 'e.occurred_at<CAST(:date_to AS timestamp)+INTERVAL \'1 day\'';
            $params['date_to'] = $dateTo . ' 00:00:00';
        }
        if ($actorId !== null && $actorId > 0) {
            $conditions[] = 'e.actor_id=:actor_id';
            $params['actor_id'] = $actorId;
        }
        return ['WHERE ' . implode(' AND ', $conditions), $params];
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function safeArchiveBatches(array $rows): array
    {
        foreach ($rows as &$row) {
            $row['actor'] = trim((string)($row['display_name'] ?? '')) ?: $this->maskEmail((string)($row['email'] ?? ''));
            unset($row['display_name'], $row['email']);
        }
        unset($row);
        return $rows;
    }

    /** @param array<string,mixed> $event @return array<string,mixed> */
    private function safeEvent(array $event): array
    {
        $event['actor'] = trim((string)($event['display_name'] ?? '')) ?: $this->maskEmail((string)($event['email'] ?? ''));
        $event['email_masked'] = $this->maskEmail((string)($event['email'] ?? ''));
        $event['ip_masked'] = $this->maskIp((string)($event['ip'] ?? ''));
        foreach (['event_id', 'request_id', 'correlation_id', 'instance_id', 'resource_id'] as $field) {
            if (array_key_exists($field, $event)) {
                $event[$field] = $this->safeIdentifier($event[$field]);
            }
        }
        $reason = (string)($event['reason'] ?? '');
        $event['reason'] = preg_match('/^[A-Za-z0-9_.:-]{1,120}$/D', $reason) === 1 ? $reason : 'details_redacted';
        unset($event['display_name'], $event['email'], $event['ip'], $event['id']);
        return $event;
    }

    /** @return list<string> */
    private function expectedInstances(): array
    {
        $raw = (string)env('SENTINEL_EXPECTED_INSTANCES', 'app-1,app-2');
        $items = array_values(array_unique(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn(string $item): bool => preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,79}$/D', $item) === 1,
        )));
        return $items !== [] ? $items : ['app-1', 'app-2'];
    }

    private function date(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }

    private function pageSize(mixed $value): int
    {
        $size = (int)$value;
        return in_array($size, self::PAGE_SIZES, true) ? $size : 25;
    }

    /** @return array{page:int,pages:int,per_page:int,total:int,total_capped:bool} */
    private function emptyPagination(int $perPage): array
    {
        return ['page' => 1, 'pages' => 1, 'per_page' => $perPage, 'total' => 0, 'total_capped' => false];
    }

    private function safeIdentifier(mixed $value): ?string
    {
        if ($value === null || trim((string)$value) === '') {
            return null;
        }
        $value = trim((string)$value);
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:@-]{0,127}$/D', $value) === 1 ? $value : '[REDACTED]';
    }

    private function maskEmail(string $email): string
    {
        $email = strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return '';
        }
        [$local, $domain] = explode('@', $email, 2);
        $domainParts = explode('.', $domain);
        $domainName = array_shift($domainParts) ?: '';
        $suffix = $domainParts !== [] ? '.' . implode('.', $domainParts) : '';
        return mb_substr($local, 0, 1) . '***@' . mb_substr($domainName, 0, 1) . '***' . $suffix;
    }

    private function maskIp(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $parts = explode('.', $ip);
            return $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.xxx';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $parts = array_values(array_filter(explode(':', $ip), static fn(string $part): bool => $part !== ''));
            return implode(':', array_slice($parts, 0, 2)) . ':…';
        }
        return '';
    }
}

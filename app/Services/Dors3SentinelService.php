<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** Read-only projection for the 3DORS Sentinel administration panel. */
final class Dors3SentinelService
{
    public const FILTERS = [
        'all', 'logins', 'dors3', 'recovery', 'mobile', 'fido2', 'finances', 'warnings', 'critical',
    ];
    public const ALERT_STATUSES = ['active', 'open', 'acknowledged', 'resolved', 'all'];

    public function __construct(private readonly Database $db) {}

    /**
     * @param array<string,mixed> $query
     * @param list<array<string,mixed>> $readiness
     * @return array<string,mixed>
     */
    public function dashboard(int $adminId, array $query, array $readiness): array
    {
        $filter = in_array((string)($query['filter'] ?? ''), self::FILTERS, true)
            ? (string)$query['filter']
            : 'all';
        $alertStatus = in_array((string)($query['alert_status'] ?? ''), self::ALERT_STATUSES, true)
            ? (string)$query['alert_status']
            : 'active';
        $search = mb_substr(trim((string)($query['q'] ?? '')), 0, 120);
        $dateFrom = $this->date((string)($query['date_from'] ?? ''));
        $dateTo = $this->date((string)($query['date_to'] ?? ''));
        $page = max(1, (int)($query['page'] ?? 1));
        $perPage = max(10, min(100, (int)($query['per_page'] ?? 25)));

        [$where, $params] = $this->eventWhere($filter, $search, $dateFrom, $dateTo);
        $total = (int)$this->db->cell(
            'SELECT COUNT(*)
             FROM security_events e
             LEFT JOIN users u ON u.id=e.actor_id ' . $where,
            $params,
        );
        $pages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;
        $events = $this->db->all(
            'SELECT e.event_id,e.occurred_at,e.action,e.resource_type,e.resource_id,e.result,e.reason,
                    e.risk_level,e.request_id,e.correlation_id,e.instance_id,e.ip,e.authentication_level,
                    u.display_name,u.email
             FROM security_events e
             LEFT JOIN users u ON u.id=e.actor_id ' . $where . '
             ORDER BY e.occurred_at DESC,e.id DESC
             LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params,
        );

        return [
            'admin_id' => $adminId,
            'summary' => $this->summary(),
            'system_status' => $this->systemStatus(),
            'instances' => $this->instances(),
            'readiness' => $readiness,
            'login_events' => $this->loginEvents(),
            'sessions' => $this->sessions(),
            'alerts' => $this->alerts($alertStatus),
            'events' => array_map(fn(array $event): array => $this->safeEvent($event), $events),
            'filters' => [
                'filter' => $filter,
                'q' => $search,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'alert_status' => $alertStatus,
            ],
            'pagination' => [
                'page' => $page,
                'pages' => $pages,
                'per_page' => $perPage,
                'total' => $total,
            ],
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
                COUNT(*) FILTER (WHERE occurred_at>=NOW()-INTERVAL \'24 hours\') AS events_24h,
                COUNT(*) FILTER (WHERE occurred_at>=NOW()-INTERVAL \'24 hours\' AND risk_level=\'high\') AS high_24h,
                COUNT(*) FILTER (WHERE occurred_at>=NOW()-INTERVAL \'24 hours\' AND risk_level=\'critical\') AS critical_24h,
                COUNT(*) FILTER (WHERE occurred_at>=NOW()-INTERVAL \'24 hours\' AND result IN (\'failure\',\'blocked\',\'rejected\',\'warning\')) AS attention_24h
             FROM security_events',
        ) ?? [];
        $alerts = $this->db->tableExists('security_alerts')
            ? ($this->db->one(
                'SELECT
                    COUNT(*) FILTER (WHERE status=\'open\') AS open_alerts,
                    COUNT(*) FILTER (WHERE status=\'acknowledged\') AS acknowledged_alerts,
                    COUNT(*) FILTER (WHERE status<>\'resolved\' AND severity=\'critical\') AS active_critical,
                    COUNT(*) FILTER (WHERE status<>\'resolved\' AND severity=\'high\') AS active_high
                 FROM security_alerts',
            ) ?? [])
            : [];
        return [
            'events_24h' => (int)($row['events_24h'] ?? 0),
            'high_24h' => (int)($row['high_24h'] ?? 0),
            'critical_24h' => (int)($row['critical_24h'] ?? 0),
            'attention_24h' => (int)($row['attention_24h'] ?? 0),
            'open_alerts' => (int)($alerts['open_alerts'] ?? 0),
            'acknowledged_alerts' => (int)($alerts['acknowledged_alerts'] ?? 0),
            'active_critical' => (int)($alerts['active_critical'] ?? 0),
            'active_high' => (int)($alerts['active_high'] ?? 0),
        ];
    }

    /** @return array{status:string,reason:string} */
    private function systemStatus(): array
    {
        $summary = $this->summary();
        $instances = $this->instances();
        $missing = count(array_filter($instances, static fn(array $item): bool => (string)$item['status'] === 'unknown'));
        $failed = count(array_filter($instances, static fn(array $item): bool => (string)$item['status'] === 'error'));
        if ($summary['active_critical'] > 0 || $failed > 0) {
            return ['status' => 'critical', 'reason' => $summary['active_critical'] > 0 ? 'critical_alerts' : 'instance_error'];
        }
        if ($summary['active_high'] > 0) {
            return ['status' => 'high', 'reason' => 'high_alerts'];
        }
        if ($missing === count($instances) && $instances !== []) {
            return ['status' => 'unknown', 'reason' => 'instances_unknown'];
        }
        if ($missing > 0 || $summary['acknowledged_alerts'] > 0) {
            return ['status' => 'warning', 'reason' => $missing > 0 ? 'instance_stale' : 'alerts_acknowledged'];
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

    /** @return list<array<string,mixed>> */
    private function alerts(string $status): array
    {
        if (!$this->db->tableExists('security_alerts')) {
            return [];
        }
        $condition = match ($status) {
            'active' => 'a.status<>\'resolved\'',
            'all' => '1=1',
            default => 'a.status=:status',
        };
        $params = in_array($status, ['active', 'all'], true) ? [] : ['status' => $status];
        $rows = $this->db->all(
            'SELECT a.public_id,a.severity,a.status,a.opened_at,a.acknowledged_at,a.resolved_at,
                    a.resolution_note,a.status_changed_at,
                    e.event_id,e.occurred_at,e.action,e.resource_type,e.resource_id,e.result,e.reason,
                    e.risk_level,e.request_id,e.correlation_id,e.instance_id,e.authentication_level,
                    u.display_name,u.email,
                    (SELECT COUNT(*) FROM security_alert_transitions t WHERE t.alert_id=a.id) AS transition_count
             FROM security_alerts a
             JOIN security_events e ON e.id=a.source_event_id
             LEFT JOIN users u ON u.id=e.actor_id
             WHERE ' . $condition . '
             ORDER BY CASE a.severity WHEN \'critical\' THEN 0 ELSE 1 END,a.status_changed_at DESC,a.id DESC
             LIMIT 50',
            $params,
        );
        return array_map(fn(array $row): array => $this->safeEvent($row), $rows);
    }

    /** @return list<array<string,mixed>> */
    private function loginEvents(): array
    {
        $rows = $this->db->all(
            'SELECT l.created_at,l.result,l.email,u.display_name
             FROM auth_login_events l
             LEFT JOIN users u ON u.id=l.user_id
             ORDER BY l.created_at DESC,l.id DESC LIMIT 12',
        );
        foreach ($rows as &$row) {
            $row['account'] = trim((string)($row['display_name'] ?? ''))
                ?: $this->maskEmail((string)($row['email'] ?? ''));
            $row['email_masked'] = $this->maskEmail((string)($row['email'] ?? ''));
            unset($row['email'], $row['display_name']);
        }
        unset($row);
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function sessions(): array
    {
        $minimum = time() - 86400;
        $rows = $this->db->all(
            'SELECT s.id,s.last_activity,u.display_name,u.email
             FROM sessions s
             LEFT JOIN users u ON u.id=s.user_id
             WHERE s.last_activity>=:minimum
             ORDER BY s.last_activity DESC LIMIT 12',
            ['minimum' => $minimum],
        );
        foreach ($rows as &$row) {
            $row['public_id'] = substr(hash('sha256', (string)$row['id']), 0, 16);
            $row['account'] = trim((string)($row['display_name'] ?? ''))
                ?: $this->maskEmail((string)($row['email'] ?? ''));
            $row['email_masked'] = $this->maskEmail((string)($row['email'] ?? ''));
            unset($row['id'], $row['email'], $row['display_name']);
        }
        unset($row);
        return $rows;
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function eventWhere(string $filter, string $search, ?string $dateFrom, ?string $dateTo): array
    {
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
        return ['WHERE ' . implode(' AND ', $conditions), $params];
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
        unset($event['display_name'], $event['email'], $event['ip']);
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

<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** Jeden, współdzielony odczyt danych dla administracyjnego panelu bezpieczeństwa. */
final class AdminSecurityPanelService
{
    /** @var list<string> */
    public const HISTORY_FILTERS = [
        'all', 'accounts', 'logins', 'sessions', 'dors3_admin', 'dors3_author',
        'fido2', 'devices', 'finances', 'editorial', 'alarms',
    ];

    public function __construct(private readonly Database $db) {}

    /** @return array<string,mixed> */
    public function snapshot(int $adminId, string $historyFilter = 'all'): array
    {
        if (!in_array($historyFilter, self::HISTORY_FILTERS, true)) {
            $historyFilter = 'all';
        }
        $configuration = $this->configurationSnapshot($adminId);
        $sessions = $this->db->all(
            'SELECT id,last_activity FROM sessions
             WHERE user_id=:user AND last_activity>=:minimum
             ORDER BY last_activity DESC LIMIT 50',
            ['user' => $adminId, 'minimum' => time() - 86400],
        );
        foreach ($sessions as &$session) {
            $session['public_id'] = substr(hash('sha256', (string)$session['id']), 0, 16);
            unset($session['id']);
        }
        unset($session);

        $events = $this->db->all(
            'SELECT event_id,occurred_at,actor_id,action,resource_type,resource_id,result,reason,
                    risk_level,authentication_level,metadata
             FROM security_events ORDER BY occurred_at DESC,id DESC LIMIT 250',
        );
        $lastAlarm = null;
        foreach ($events as $event) {
            if (
                in_array((string)$event['risk_level'], ['high', 'critical'], true)
                && in_array((string)$event['result'], ['failure', 'blocked', 'rejected', 'warning'], true)
            ) {
                $lastAlarm = $event;
                break;
            }
        }
        $filteredEvents = array_values(array_filter(
            $events,
            fn(array $event): bool => $this->eventMatches($event, $historyFilter),
        ));

        $loginEvents = $this->db->all(
            'SELECT l.created_at,l.result,l.email,u.display_name
             FROM auth_login_events l
             LEFT JOIN users u ON u.id=l.user_id
             ORDER BY l.created_at DESC,l.id DESC LIMIT 50',
        );
        return $configuration + [
            'sessions' => $sessions,
            'events' => $filteredEvents,
            'last_alarm' => $lastAlarm,
            'login_events' => $loginEvents,
            'history_filter' => $historyFilter,
        ];
    }

    /** @return array{credentials:list<array<string,mixed>>,role_summary:array<string,mixed>} */
    public function configurationSnapshot(int $adminId): array
    {
        $credentials = $this->db->all(
            'SELECT public_id,display_name,credential_role,status,tested_at,last_used_at,created_at
             FROM webauthn_credentials WHERE user_id=:user ORDER BY created_at,id',
            ['user' => $adminId],
        );
        $roles = $this->db->one(
            'SELECT
                COUNT(*) FILTER (WHERE u.status=\'active\') AS active_accounts,
                COUNT(*) FILTER (WHERE u.status=\'active\' AND u.can_write=1
                    AND EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id=u.id AND ur.role=\'author\')) AS journalists,
                COUNT(*) FILTER (WHERE u.status=\'active\'
                    AND EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id=u.id AND ur.role=\'admin\')) AS administrators,
                COUNT(*) FILTER (WHERE u.status=\'active\' AND u.payout_enabled=1) AS payout_enabled,
                COUNT(*) FILTER (WHERE u.status=\'active\'
                    AND NOT EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id=u.id AND ur.role IN (\'author\',\'admin\'))) AS readers
             FROM users u',
        ) ?? [];
        return ['credentials' => $credentials, 'role_summary' => $roles];
    }

    /** @param array<string,mixed> $event */
    private function eventMatches(array $event, string $filter): bool
    {
        if ($filter === 'all') {
            return true;
        }
        $action = strtolower((string)($event['action'] ?? ''));
        $resource = strtolower((string)($event['resource_type'] ?? ''));
        $metadata = $event['metadata'] ?? [];
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true) ?: [];
        }
        $variant = is_array($metadata) ? strtolower((string)($metadata['application_variant'] ?? '')) : '';
        return match ($filter) {
            'accounts' => $resource === 'user' || str_contains($action, 'user.') || str_contains($action, 'role') || str_contains($action, 'author.'),
            'logins' => str_contains($action, 'login'),
            'sessions' => $resource === 'session' || str_contains($action, 'session'),
            'dors3_admin' => str_starts_with($action, 'mobile.') && $variant === 'admin',
            'dors3_author' => str_starts_with($action, 'mobile.') && $variant === 'author',
            'fido2' => str_contains($action, 'fido') || str_contains($action, 'webauthn') || str_contains($resource, 'webauthn'),
            'devices' => str_contains($action, 'mobile.device') || str_contains($action, 'mobile.enrollment') || str_contains($resource, 'mobile_device'),
            'finances' => in_array($resource, ['payout', 'payment', 'wallet', 'financial_approval'], true)
                || preg_match('/payout|payment|wallet|financial/', $action) === 1,
            'editorial' => $resource === 'article' || preg_match('/article|editorial|author/', $action) === 1,
            'alarms' => in_array((string)($event['risk_level'] ?? ''), ['high', 'critical'], true)
                || in_array((string)($event['result'] ?? ''), ['failure', 'blocked', 'rejected', 'warning'], true),
            default => true,
        };
    }
}

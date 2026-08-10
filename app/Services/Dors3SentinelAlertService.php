<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\RequestContext;
use App\Security\Dors3\SecurityId;

/**
 * Alerty Wartownika są osobną projekcją niezmiennych security_events.
 * Ten serwis nie podejmuje decyzji bezpieczeństwa i nie uczestniczy w wykonaniu operacji.
 */
final class Dors3SentinelAlertService
{
    public function __construct(private readonly Database $db) {}

    /** @return array<string,mixed>|null */
    public function captureForEvent(string $eventPublicId): ?array
    {
        if (!$this->db->tableExists('security_alerts') || !$this->db->tableExists('security_sentinel_state')) {
            return null;
        }
        $event = $this->db->one(
            'SELECT id,event_id,risk_level,result FROM security_events WHERE event_id=:event LIMIT 1',
            ['event' => trim($eventPublicId)],
        );
        if ($event === null || !$this->requiresAlert($event)) {
            return null;
        }

        $this->db->query(
            'INSERT INTO security_alerts(
                public_id,source_event_id,severity,status,opened_at,status_changed_at,created_at,updated_at
             ) VALUES(:public_id,:event_id,:severity,\'open\',NOW(),NOW(),NOW(),NOW())
             ON CONFLICT(source_event_id) DO NOTHING',
            [
                'public_id' => SecurityId::uuid(),
                'event_id' => (int)$event['id'],
                'severity' => (string)$event['risk_level'],
            ],
        );
        $alert = $this->db->one(
            'SELECT * FROM security_alerts WHERE source_event_id=:event_id LIMIT 1',
            ['event_id' => (int)$event['id']],
        );
        if ($alert === null) {
            return null;
        }
        $this->prepareRecipients((int)$alert['id']);
        return $alert;
    }

    /** @return array{scanned:int,created:int} */
    public function synchronize(int $limit = 250): array
    {
        if (!$this->db->tableExists('security_alerts')) {
            return ['scanned' => 0, 'created' => 0];
        }
        $limit = max(1, min(1000, $limit));
        $events = $this->db->all(
            'SELECT e.event_id
             FROM security_events e
             LEFT JOIN security_alerts a ON a.source_event_id=e.id
             CROSS JOIN security_sentinel_state state
             WHERE a.id IS NULL
               AND state.singleton_id=1
               AND e.occurred_at>=state.activated_at
               AND e.risk_level IN (\'high\',\'critical\')
             ORDER BY e.id ASC LIMIT ' . $limit,
        );
        $created = 0;
        foreach ($events as $event) {
            if ($this->captureForEvent((string)$event['event_id']) !== null) {
                ++$created;
            }
        }
        return ['scanned' => count($events), 'created' => $created];
    }

    /** @return array{queued:int,failed:int} */
    public function dispatchPendingNotifications(MailService $mail, int $limit = 50): array
    {
        if (!$this->db->tableExists('security_alert_notifications')) {
            return ['queued' => 0, 'failed' => 0];
        }
        $limit = max(1, min(200, $limit));
        $this->db->query(
            'UPDATE security_alert_notifications
             SET status=\'failed\',locked_at=NULL,last_error=\'Wygasła dzierżawa powiadomienia Wartownika.\',
                 next_attempt_at=NOW(),updated_at=NOW()
             WHERE status=\'processing\' AND locked_at < ' . $this->db->nowMinus(15, 'minute'),
        );

        $queued = 0;
        $failed = 0;
        for ($index = 0; $index < $limit; ++$index) {
            $delivery = $this->claimNotification();
            if ($delivery === null) {
                break;
            }
            try {
                $language = in_array((string)($delivery['interface_language'] ?? ''), ['pl', 'en'], true)
                    ? (string)$delivery['interface_language']
                    : 'pl';
                $event = Dors3OperatorPresenter::event($delivery, $language);
                $severity = Dors3UiText::option('events.risks', (string)$delivery['severity'], $language);
                $panelUrl = rtrim((string)env('APP_URL', ''), '/') . '/admin/security/sentinel?alert='
                    . rawurlencode((string)$delivery['alert_public_id']);
                $subject = Dors3UiText::get('sentinel.notification_subject', ['severity' => $severity], $language);
                $body = Dors3UiText::get('sentinel.notification_body', [
                    'severity' => $severity,
                    'action' => (string)$event['action_label'],
                    'time' => (string)$delivery['occurred_at'],
                    'alert_id' => (string)$delivery['alert_public_id'],
                    'correlation_id' => (string)($delivery['correlation_id'] ?? Dors3UiText::get('common.not_specified', [], $language)),
                    'url' => $panelUrl,
                ], $language);
                $mailId = $mail->queue(
                    (int)$delivery['recipient_user_id'],
                    (string)$delivery['email'],
                    $subject,
                    $body,
                    5,
                    'sentinel-alert:' . (int)$delivery['alert_id'] . ':admin:' . (int)$delivery['recipient_user_id'],
                );
                $this->db->query(
                    'UPDATE security_alert_notifications
                     SET status=\'queued\',mail_queue_id=:mail,queued_at=NOW(),locked_at=NULL,
                         last_error=NULL,updated_at=NOW()
                     WHERE id=:id AND status=\'processing\'',
                    ['mail' => $mailId, 'id' => (int)$delivery['notification_id']],
                );
                ++$queued;
            } catch (\Throwable $error) {
                $attempts = (int)$delivery['attempts'];
                $delay = min(3600, 30 * (2 ** max(0, $attempts - 1)));
                $this->db->query(
                    'UPDATE security_alert_notifications
                     SET status=\'failed\',locked_at=NULL,last_error=:error,
                         next_attempt_at=' . $this->db->nowPlus($delay, 'second') . ',updated_at=NOW()
                     WHERE id=:id',
                    [
                        'error' => mb_substr($error->getMessage(), 0, 1000),
                        'id' => (int)$delivery['notification_id'],
                    ],
                );
                ++$failed;
            }
        }
        return ['queued' => $queued, 'failed' => $failed];
    }

    /** @return array<string,mixed> */
    public function transition(string $alertPublicId, int $adminId, string $targetStatus, string $reason): array
    {
        if (!in_array($targetStatus, ['acknowledged', 'resolved'], true)) {
            throw new \InvalidArgumentException('Nieprawidłowy status alertu Wartownika.');
        }
        $reason = (new AuditArtifactSanitizer())->sanitize($reason);
        if (!mb_check_encoding($reason, 'UTF-8')) {
            throw new \InvalidArgumentException('Uzasadnienie ma nieprawidłowe kodowanie.');
        }
        $reason = trim((string)preg_replace('/[[:space:]]+/', ' ', $reason));
        if (mb_strlen($reason, 'UTF-8') < 5 || mb_strlen($reason, 'UTF-8') > 500) {
            throw new \InvalidArgumentException('Uzasadnienie musi mieć od 5 do 500 znaków.');
        }

        return $this->db->transaction(function (Database $db) use ($alertPublicId, $adminId, $targetStatus, $reason): array {
            $alert = $db->one(
                'SELECT * FROM security_alerts WHERE public_id=:public_id FOR UPDATE',
                ['public_id' => trim($alertPublicId)],
            );
            if ($alert === null) {
                throw new \RuntimeException('Alert Wartownika nie istnieje.');
            }
            $fromStatus = (string)$alert['status'];
            $allowed = $targetStatus === 'acknowledged'
                ? $fromStatus === 'open'
                : in_array($fromStatus, ['open', 'acknowledged'], true);
            if (!$allowed) {
                throw new \RuntimeException('Alert ma już stan końcowy albo przeszedł ten etap.');
            }

            if ($targetStatus === 'acknowledged') {
                $db->query(
                    'UPDATE security_alerts
                     SET status=\'acknowledged\',acknowledged_at=NOW(),acknowledged_by=:actor,
                         status_changed_at=NOW(),updated_at=NOW()
                     WHERE id=:id',
                    ['actor' => $adminId, 'id' => (int)$alert['id']],
                );
            } else {
                $db->query(
                    'UPDATE security_alerts
                     SET status=\'resolved\',resolved_at=NOW(),resolved_by=:actor,resolution_note=:reason,
                         status_changed_at=NOW(),updated_at=NOW()
                     WHERE id=:id',
                    ['actor' => $adminId, 'reason' => $reason, 'id' => (int)$alert['id']],
                );
            }

            $requestId = RequestContext::requestId();
            $db->query(
                'INSERT INTO security_alert_transitions(
                    alert_id,from_status,to_status,actor_id,reason,request_id,correlation_id,instance_id,occurred_at
                 ) VALUES(:alert,:from_status,:to_status,:actor,:reason,:request_id,:correlation_id,:instance_id,NOW())',
                [
                    'alert' => (int)$alert['id'],
                    'from_status' => $fromStatus,
                    'to_status' => $targetStatus,
                    'actor' => $adminId,
                    'reason' => $reason,
                    'request_id' => $requestId,
                    'correlation_id' => $this->correlationId($requestId),
                    'instance_id' => trim((string)env('APP_INSTANCE_ID', '')) ?: null,
                ],
            );
            return $db->one('SELECT * FROM security_alerts WHERE id=:id', ['id' => (int)$alert['id']]) ?? [];
        });
    }

    /** @param array<string,mixed> $event */
    private function requiresAlert(array $event): bool
    {
        return in_array((string)($event['risk_level'] ?? ''), ['high', 'critical'], true);
    }

    private function prepareRecipients(int $alertId): void
    {
        $this->db->query(
            'INSERT INTO security_alert_notifications(
                alert_id,recipient_user_id,channel,status,attempts,next_attempt_at,created_at,updated_at
             )
             SELECT :alert,u.id,\'email\',\'pending\',0,NOW(),NOW(),NOW()
             FROM users u
             JOIN user_roles ur ON ur.user_id=u.id AND ur.role=\'admin\'
             WHERE u.status=\'active\'
             ON CONFLICT(alert_id,recipient_user_id,channel) DO NOTHING',
            ['alert' => $alertId],
        );
    }

    /** @return array<string,mixed>|null */
    private function claimNotification(): ?array
    {
        return $this->db->transaction(function (Database $db): ?array {
            $row = $db->one(
                'SELECT n.id AS notification_id,n.alert_id,n.recipient_user_id,n.attempts,
                        a.public_id AS alert_public_id,a.severity,
                        e.action,e.resource_type,e.resource_id,e.result,e.reason,e.risk_level,
                        e.occurred_at,e.request_id,e.correlation_id,
                        u.email,u.interface_language
                 FROM security_alert_notifications n
                 JOIN security_alerts a ON a.id=n.alert_id
                 JOIN security_events e ON e.id=a.source_event_id
                 JOIN users u ON u.id=n.recipient_user_id AND u.status=\'active\'
                 WHERE n.status IN (\'pending\',\'failed\')
                   AND n.attempts<5 AND n.next_attempt_at<=NOW()
                 ORDER BY CASE a.severity WHEN \'critical\' THEN 0 ELSE 1 END,a.opened_at,n.id
                 LIMIT 1 FOR UPDATE OF n SKIP LOCKED',
            );
            if ($row === null) {
                return null;
            }
            $db->query(
                'UPDATE security_alert_notifications
                 SET status=\'processing\',attempts=attempts+1,locked_at=NOW(),updated_at=NOW()
                 WHERE id=:id',
                ['id' => (int)$row['notification_id']],
            );
            $row['attempts'] = (int)$row['attempts'] + 1;
            return $row;
        });
    }

    private function correlationId(string $fallback): string
    {
        $incoming = trim((string)($_SERVER['HTTP_X_CORRELATION_ID'] ?? ''));
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{7,127}$/D', $incoming) === 1
            ? $incoming
            : $fallback;
    }
}

<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\RequestContext;
use App\Jobs\Dors3SentinelArchiveJobHandler;
use App\Services\Dors3OperatorPresenter;
use App\Services\Dors3SentinelAlertService;
use App\Services\Dors3SentinelArchiveService;
use App\Services\Dors3SentinelService;
use App\Services\DurableJobQueue;
use App\Services\DurableJobWorker;
use App\Services\MailService;
use App\Services\SecurityEventService;

final class Dors3SentinelIntegrationTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RequestContext::resetForTests();
        $_SERVER['REMOTE_ADDR'] = '192.0.2.44';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit Wartownik';
        $_SERVER['HTTP_X_REQUEST_ID'] = 'phpunit-sentinel-request-0001';
        $_SERVER['HTTP_X_CORRELATION_ID'] = 'phpunit-sentinel-correlation-0001';
        $_ENV['APP_INSTANCE_ID'] = 'app-1';
        $_ENV['SENTINEL_EXPECTED_INSTANCES'] = 'app-1,app-2';
    }

    public function testHighRiskEventCreatesOneSeparateAlertAndOneNotificationPerAdministrator(): void
    {
        $adminId = $this->adminId();
        $eventId = (new SecurityEventService($this->database))->record(
            $adminId,
            'security.login.blocked',
            'blocked',
            'high',
            'session',
            'sensitive-session-id',
            null,
            null,
            'admin_login_lock_active',
            null,
            ['secret' => 'must-not-be-presented'],
        );

        $alert = $this->database->one(
            'SELECT a.id,a.status,a.severity,e.event_id,e.metadata
             FROM security_alerts a JOIN security_events e ON e.id=a.source_event_id
             WHERE e.event_id=:event',
            ['event' => $eventId],
        );
        self::assertNotNull($alert);
        self::assertSame('open', $alert['status']);
        self::assertSame('high', $alert['severity']);
        self::assertStringContainsString('must-not-be-presented', (string)$alert['metadata']);

        $activeAdmins = (int)$this->database->cell(
            'SELECT COUNT(DISTINCT u.id) FROM users u JOIN user_roles ur ON ur.user_id=u.id AND ur.role=\'admin\' WHERE u.status=\'active\'',
        );
        self::assertSame($activeAdmins, (int)$this->database->cell(
            'SELECT COUNT(*) FROM security_alert_notifications WHERE alert_id=:alert',
            ['alert' => (int)$alert['id']],
        ));

        $service = new Dors3SentinelAlertService($this->database);
        $service->captureForEvent($eventId);
        self::assertSame(1, (int)$this->database->cell(
            'SELECT COUNT(*) FROM security_alerts WHERE source_event_id=(SELECT id FROM security_events WHERE event_id=:event)',
            ['event' => $eventId],
        ));

        (new SecurityEventService($this->database))->record($adminId, 'security.login.success', 'success', 'low');
        self::assertSame(1, (int)$this->database->cell('SELECT COUNT(*) FROM security_alerts'));
    }

    public function testAlertLifecycleIsAuditedWithoutChangingSourceEvent(): void
    {
        $adminId = $this->adminId();
        $eventId = (new SecurityEventService($this->database))->record(
            $adminId,
            'mobile.signature.invalid',
            'rejected',
            'critical',
            'mobile_approval',
            'request-public-id',
            null,
            null,
            'approval_mismatch',
        );
        $before = $this->database->one('SELECT * FROM security_events WHERE event_id=:event', ['event' => $eventId]);
        $alertId = (string)$this->database->cell(
            'SELECT a.public_id FROM security_alerts a JOIN security_events e ON e.id=a.source_event_id WHERE e.event_id=:event',
            ['event' => $eventId],
        );
        $service = new Dors3SentinelAlertService($this->database);

        self::assertSame('acknowledged', $service->transition($alertId, $adminId, 'acknowledged', 'Sprawdzono kontekst żądania.')['status']);
        $dashboard = new Dors3SentinelService($this->database);
        self::assertNotContains($alertId, array_column(
            $dashboard->dashboard($adminId, ['view' => 'active'], [])['alerts'],
            'public_id',
        ));
        self::assertContains($alertId, array_column(
            $dashboard->dashboard($adminId, ['view' => 'acknowledged'], [])['alerts'],
            'public_id',
        ));
        self::assertSame('resolved', $service->transition($alertId, $adminId, 'resolved', 'Potwierdzono odrzucenie błędnego podpisu.')['status']);
        self::assertSame(2, (int)$this->database->cell(
            'SELECT COUNT(*) FROM security_alert_transitions t JOIN security_alerts a ON a.id=t.alert_id WHERE a.public_id=:alert',
            ['alert' => $alertId],
        ));
        self::assertSame($before, $this->database->one('SELECT * FROM security_events WHERE event_id=:event', ['event' => $eventId]));

        $this->expectException(\RuntimeException::class);
        $service->transition($alertId, $adminId, 'resolved', 'Ponowne rozwiązanie nie jest dozwolone.');
    }

    public function testOneCriticalOperationProducesOneHumanAlert(): void
    {
        $adminId = $this->adminId();
        $events = new SecurityEventService($this->database);
        $events->record(
            $adminId,
            'security.step_up.started',
            'success',
            'high',
            'settings_group',
            'talent',
            null,
            null,
            null,
            null,
            ['operation' => 'earnings.rules.update'],
        );
        $events->record(
            $adminId,
            'security.step_up.approved',
            'success',
            'high',
            'settings_group',
            'talent',
            null,
            null,
            null,
            null,
            ['operation' => 'earnings.rules.update'],
        );
        $events->record(
            $adminId,
            'sentinel.archive.completed',
            'success',
            'high',
            'settings_group',
            'talent',
            null,
            null,
            'protected_archive_completed',
            null,
            ['operation' => 'earnings.rules.update'],
        );

        self::assertSame(1, (int)$this->database->cell(
            "SELECT COUNT(*) FROM security_alerts WHERE operation_key LIKE 'operation:%|earnings.rules.update|%'",
        ));
        $alert = $this->database->one(
            "SELECT a.event_count,e.action,e.metadata->>'operation' AS operation
             FROM security_alerts a JOIN security_events e ON e.id=a.source_event_id
             WHERE a.operation_key LIKE 'operation:%|earnings.rules.update|%'",
        );
        self::assertSame(2, (int)$alert['event_count']);
        self::assertSame('sentinel.archive.completed', $alert['action']);
        self::assertSame('earnings.rules.update', $alert['operation']);
    }

    public function testNewLoginContextCreatesAlertButNormalLoginDoesNot(): void
    {
        $adminId = $this->adminId();
        $events = new SecurityEventService($this->database);
        $events->record($adminId, 'security.login.success', 'success', 'low', 'user', (string)$adminId);
        $events->record($adminId, 'security.login.new_context', 'warning', 'medium', 'user', (string)$adminId, null, null, 'new_device');

        self::assertSame(0, (int)$this->database->cell(
            "SELECT COUNT(*) FROM security_alerts a JOIN security_events e ON e.id=a.source_event_id WHERE e.action='security.login.success'",
        ));
        self::assertSame(1, (int)$this->database->cell(
            "SELECT COUNT(*) FROM security_alerts a JOIN security_events e ON e.id=a.source_event_id WHERE e.action='security.login.new_context'",
        ));
    }

    public function testSynchronizationStartsAtActivationWatermarkAndNotificationIsIdempotent(): void
    {
        $adminId = $this->adminId();
        $this->database->query('UPDATE security_sentinel_state SET activated_at=NOW() WHERE singleton_id=1');
        $this->insertRawEvent('00000000-0000-4000-8000-000000000001', $adminId, '-1 hour');

        $service = new Dors3SentinelAlertService($this->database);
        self::assertSame(['scanned' => 0, 'created' => 0], $service->synchronize());

        $this->insertRawEvent('00000000-0000-4000-8000-000000000002', $adminId, '+1 second');
        self::assertSame(['scanned' => 1, 'created' => 1], $service->synchronize());
        $first = $service->dispatchPendingNotifications(new MailService($this->database));
        self::assertGreaterThanOrEqual(1, $first['queued']);
        self::assertSame(['queued' => 0, 'failed' => 0], $service->dispatchPendingNotifications(new MailService($this->database)));
        self::assertSame(
            (int)$this->database->cell('SELECT COUNT(*) FROM security_alert_notifications WHERE status=\'queued\''),
            (int)$this->database->cell("SELECT COUNT(*) FROM mail_queue WHERE idempotency_key LIKE 'sentinel-alert:%'"),
        );
    }

    public function testDashboardUsesSqlFiltersMasksDataAndReportsBothInstances(): void
    {
        $adminId = $this->adminId();
        $this->database->query(
            'INSERT INTO security_instance_heartbeats(instance_id,instance_role,expected,ready,last_seen_at,last_ready_at,created_at,updated_at)
             VALUES(\'app-1\',\'application\',TRUE,TRUE,NOW(),NOW(),NOW(),NOW()),
                   (\'app-2\',\'application\',TRUE,TRUE,NOW(),NOW(),NOW(),NOW())
             ON CONFLICT(instance_id) DO UPDATE SET expected=TRUE,ready=TRUE,last_seen_at=NOW(),last_ready_at=NOW(),updated_at=NOW()',
        );
        $eventId = (new SecurityEventService($this->database))->record(
            $adminId,
            'payout.approve',
            'warning',
            'high',
            'payout',
            '42',
            null,
            null,
            'Opis zawiera dane operatora operator@example.test',
            null,
            ['token' => 'not-for-ui'],
        );

        $service = new Dors3SentinelService($this->database);
        $dashboard = $service->dashboard($adminId, ['view' => 'logs', 'filter' => 'finances', 'q' => $eventId, 'per_page' => 10], []);
        self::assertSame('high', $dashboard['system_status']['status']);
        self::assertCount(2, $dashboard['instances']);
        self::assertSame(1, $dashboard['pagination']['total']);
        $event = $dashboard['events'][0];
        self::assertArrayNotHasKey('email', $event);
        self::assertArrayNotHasKey('ip', $event);
        self::assertArrayNotHasKey('metadata', $event);
        self::assertSame('192.0.2.xxx', $event['ip_masked']);
        self::assertSame('details_redacted', $event['reason']);
        self::assertSame('phpunit-sentinel-request-0001', $event['request_id']);
        self::assertSame('phpunit-sentinel-correlation-0001', $event['correlation_id']);
        self::assertSame('app-1', $event['instance_id']);
        $storageTables = array_column($dashboard['storage']['tables'], null, 'name');
        self::assertTrue($storageTables['security_events']['rows_exact']);
        self::assertSame(
            (int)$this->database->cell('SELECT COUNT(*) FROM security_events'),
            $storageTables['security_events']['rows'],
        );

        $presented = Dors3OperatorPresenter::event($event, 'pl');
        self::assertSame('Szczegóły ukryto w widoku operatora', $presented['reason_label']);
    }

    public function testDashboardPaginatesLargeSessionLoginAndAlertCollectionsOnServer(): void
    {
        $adminId = $this->adminId();
        $this->database->query(
            "INSERT INTO sessions(id,user_id,payload,last_activity)
             SELECT CONCAT('phpunit-scale-session-',i),:actor,'{}',EXTRACT(EPOCH FROM NOW())::integer-i
             FROM generate_series(1,10050) AS i",
            ['actor' => $adminId],
        );
        $this->database->query(
            "INSERT INTO auth_login_events(user_id,email,result,ip_hash,user_agent_hash,created_at)
             SELECT :actor,'scale@example.test',
                    CASE WHEN i%3=0 THEN 'blocked' WHEN i%3=1 THEN 'failure' ELSE 'success' END,
                    MD5(CONCAT('ip-',i)),MD5(CONCAT('agent-',i)),NOW()-(i*INTERVAL '6 minutes')
             FROM generate_series(1,300) AS i",
            ['actor' => $adminId],
        );
        $this->database->query(
            "WITH inserted AS (
                INSERT INTO security_events(
                    event_id,occurred_at,actor_id,action,resource_type,resource_id,request_id,
                    correlation_id,instance_id,result,reason,risk_level,metadata
                )
                SELECT MD5(CONCAT('scale-event-',i))::uuid::text,NOW()-(i*INTERVAL '1 second'),:actor,
                       'security.login.blocked','session',CONCAT('scale-resource-',i),
                       CONCAT('scale-request-',i),CONCAT('scale-correlation-',i),'app-1',
                       'blocked','admin_login_lock_active','high','{}'::jsonb
                FROM generate_series(1,120) AS i
                RETURNING id,event_id,occurred_at
             )
             INSERT INTO security_alerts(
                public_id,source_event_id,severity,status,opened_at,status_changed_at,
                operation_key,last_event_at
             )
             SELECT MD5(CONCAT('scale-alert-',event_id))::uuid::text,id,'high','open',
                    occurred_at,occurred_at,CONCAT('event:',event_id),occurred_at
             FROM inserted",
            ['actor' => $adminId],
        );

        $service = new Dors3SentinelService($this->database);
        $overview = $service->dashboard($adminId, ['view' => 'active'], []);
        self::assertGreaterThanOrEqual(10050, $overview['session_overview']['active']);
        self::assertCount(20, $overview['sessions']);
        self::assertCount(10, $overview['alerts']);

        $sessions = $service->dashboard($adminId, [
            'view' => 'sessions',
            'session_status' => 'active',
            'session_user' => (string)$adminId,
            'session_page' => 2,
            'session_per_page' => 25,
        ], []);
        self::assertSame(2, $sessions['session_pagination']['page']);
        self::assertSame(10000, $sessions['session_pagination']['total']);
        self::assertTrue($sessions['session_pagination']['total_capped']);
        self::assertCount(25, $sessions['sessions']);

        $logins = $service->dashboard($adminId, [
            'view' => 'login_attempts',
            'login_user' => (string)$adminId,
            'login_page' => 2,
            'login_per_page' => 25,
        ], []);
        self::assertSame(2, $logins['login_pagination']['page']);
        self::assertGreaterThanOrEqual(300, $logins['login_pagination']['total']);
        self::assertCount(25, $logins['login_events']);

        $alerts = $service->dashboard($adminId, [
            'view' => 'open',
            'alert_q' => 'scale-resource-',
            'alert_page' => 2,
            'alert_per_page' => 25,
        ], []);
        self::assertSame(2, $alerts['alert_pagination']['page']);
        self::assertSame(120, $alerts['alert_pagination']['total']);
        self::assertCount(25, $alerts['alerts']);
        self::assertGreaterThanOrEqual(120, $service->pulse()['open_alerts']);
    }

    public function testProtectedArchiveMovesColdRowsWithoutLosingAuditData(): void
    {
        $adminId = $this->adminId();
        $eventId = '00000000-0000-4000-8000-000000000099';
        $this->database->query(
            "INSERT INTO security_events(event_id,occurred_at,actor_id,action,result,risk_level,metadata)
             VALUES(:event,NOW()-INTERVAL '120 days',:actor,'security.login.success','success','low','{}'::jsonb)",
            ['event' => $eventId, 'actor' => $adminId],
        );
        $loginId = $this->database->insert(
            "INSERT INTO auth_login_events(user_id,email,result,created_at)
             VALUES(:actor,'archive@example.test','success',NOW()-INTERVAL '120 days')",
            ['actor' => $adminId],
        );

        $result = (new Dors3SentinelArchiveService($this->database))->archiveBefore(
            gmdate('Y-m-d', strtotime('-90 days')),
            $adminId,
            '00000000-0000-4000-8000-000000000777',
        );

        self::assertGreaterThanOrEqual(1, $result['security_events']);
        self::assertGreaterThanOrEqual(1, $result['login_events']);
        self::assertSame(0, (int)$this->database->cell('SELECT COUNT(*) FROM security_events WHERE event_id=:event', ['event' => $eventId]));
        self::assertSame(1, (int)$this->database->cell('SELECT COUNT(*) FROM security_events_archive WHERE event_id=:event', ['event' => $eventId]));
        self::assertSame(0, (int)$this->database->cell('SELECT COUNT(*) FROM auth_login_events WHERE id=:id', ['id' => $loginId]));
        self::assertSame(1, (int)$this->database->cell('SELECT COUNT(*) FROM auth_login_events_archive WHERE original_id=:id', ['id' => $loginId]));
    }

    public function testProtectedArchiveRunsAsLowPriorityDurableBackgroundWork(): void
    {
        $adminId = $this->adminId();
        $eventId = '00000000-0000-4000-8000-000000000098';
        $requestId = '00000000-0000-4000-8000-000000000778';
        $authorizationId = '00000000-0000-4000-8000-000000000779';
        $cutoff = gmdate('Y-m-d', strtotime('-90 days'));
        $this->database->query(
            "INSERT INTO security_events(event_id,occurred_at,actor_id,action,result,risk_level,metadata)
             VALUES(:event,NOW()-INTERVAL '120 days',:actor,'security.login.success','success','low','{}'::jsonb)",
            ['event' => $eventId, 'actor' => $adminId],
        );
        $queue = new DurableJobQueue($this->database);
        $job = $queue->enqueue(
            Dors3SentinelArchiveJobHandler::QUEUE,
            Dors3SentinelArchiveJobHandler::JOB_TYPE,
            [
                'cutoff_date' => $cutoff,
                'actor_id' => $adminId,
                'authorization_public_id' => $authorizationId,
                'request_public_id' => $requestId,
                'sequence' => 1,
            ],
            'sentinel-archive:' . $requestId . ':chunk:1',
            -20,
            5,
            'automatic',
            $adminId,
        );
        self::assertSame(-20, (int)$job['priority']);

        $worker = new DurableJobWorker(
            $queue,
            new Dors3SentinelArchiveJobHandler($this->database, $queue),
            Dors3SentinelArchiveJobHandler::QUEUE,
            'phpunit-sentinel-worker',
            60,
        );
        $processed = $worker->runOne();

        self::assertSame(1, $processed['completed']);
        self::assertSame(1, (int)$this->database->cell(
            'SELECT COUNT(*) FROM security_events_archive WHERE event_id=:event',
            ['event' => $eventId],
        ));
        self::assertSame(1, (int)$this->database->cell(
            'SELECT COUNT(*) FROM security_event_archive_batches WHERE public_id=:public_id',
            ['public_id' => $requestId],
        ));
    }

    private function adminId(): int
    {
        return (int)$this->database->cell(
            'SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id=u.id AND ur.role=\'admin\' WHERE u.status=\'active\' ORDER BY u.id LIMIT 1',
        );
    }

    private function insertRawEvent(string $eventId, int $adminId, string $offset): void
    {
        if (preg_match('/^[+-]\d+ (second|minute|hour)$/D', $offset) !== 1) {
            throw new \InvalidArgumentException('Invalid test time offset.');
        }
        $this->database->query(
            'INSERT INTO security_events(event_id,occurred_at,actor_id,action,resource_type,resource_id,
                request_id,correlation_id,instance_id,ip,authentication_level,result,reason,risk_level,metadata)
             VALUES(:event,NOW()+CAST(:offset AS interval),:actor,\'security.login.blocked\',\'session\',\'raw\',
                :request,:correlation,\'app-1\',\'192.0.2.1\',\'password\',\'blocked\',\'admin_login_lock_active\',\'high\',\'{}\'::jsonb)',
            [
                'event' => $eventId,
                'offset' => $offset,
                'actor' => $adminId,
                'request' => 'phpunit-raw-request-' . substr($eventId, -4),
                'correlation' => 'phpunit-raw-correlation-' . substr($eventId, -4),
            ],
        );
    }
}

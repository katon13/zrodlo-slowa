<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Security\Dors3\SecurityId;

/** Moves cold operational rows into the immutable 3DORS archive. */
final class Dors3SentinelArchiveService
{
    public function __construct(private readonly Database $db) {}

    /** @return array{public_id:string,security_events:int,login_events:int,total_security_events:int,total_login_events:int,cutoff_at:string} */
    public function archiveBefore(
        string $cutoffDate,
        int $actorId,
        string $authorizationPublicId,
        int $limit = 1000,
        ?string $batchPublicId = null,
    ): array {
        $cutoff = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($cutoffDate), new \DateTimeZone('UTC'));
        $latestAllowed = new \DateTimeImmutable('-30 days', new \DateTimeZone('UTC'));
        if ($cutoff === false || $cutoff->format('Y-m-d') !== trim($cutoffDate) || $cutoff > $latestAllowed) {
            throw new \InvalidArgumentException('sentinel_archive_cutoff_invalid');
        }
        if (preg_match('/^[a-f0-9-]{36}$/D', $authorizationPublicId) !== 1) {
            throw new \InvalidArgumentException('sentinel_archive_authorization_invalid');
        }
        // A hard service-level cap protects user-facing database traffic even if
        // the service is ever called outside the dedicated maintenance worker.
        $limit = max(100, min(5000, $limit));
        $cutoffAt = $cutoff->format('Y-m-d 00:00:00');
        $publicId = $batchPublicId !== null ? trim($batchPublicId) : SecurityId::uuid();
        if (preg_match('/^[a-f0-9-]{36}$/D', $publicId) !== 1) {
            throw new \InvalidArgumentException('sentinel_archive_batch_invalid');
        }

        return $this->db->transaction(function (Database $db) use (
            $publicId,
            $cutoffAt,
            $actorId,
            $authorizationPublicId,
            $limit,
        ): array {
            // Maintenance yields quickly to user-facing traffic. The durable worker retries a timed-out chunk.
            $db->query("SET LOCAL lock_timeout = '250ms'");
            $db->query("SET LOCAL statement_timeout = '10s'");
            $db->query("SET LOCAL idle_in_transaction_session_timeout = '15s'");

            $db->query(
                'INSERT INTO security_event_archive_batches(
                    public_id,cutoff_at,archived_by,authorization_public_id,created_at
                 ) VALUES(:public_id,:cutoff,:actor,:authorization,NOW())
                 ON CONFLICT(public_id) DO NOTHING',
                [
                    'public_id' => $publicId,
                    'cutoff' => $cutoffAt,
                    'actor' => $actorId,
                    'authorization' => $authorizationPublicId,
                ],
            );
            $batch = $db->one(
                'SELECT id,cutoff_at,archived_by,authorization_public_id
                 FROM security_event_archive_batches
                 WHERE public_id=:public_id
                 FOR UPDATE',
                ['public_id' => $publicId],
            );
            if ($batch === null
                || (int)$batch['archived_by'] !== $actorId
                || (string)$batch['authorization_public_id'] !== $authorizationPublicId
                || substr((string)$batch['cutoff_at'], 0, 10) !== substr($cutoffAt, 0, 10)) {
                throw new \RuntimeException('sentinel_archive_batch_mismatch');
            }
            $batchId = (int)$batch['id'];

            $securityCount = $db->query(
                'WITH selected AS (
                    SELECT e.id
                    FROM security_events e
                    WHERE e.occurred_at<:cutoff
                      AND NOT EXISTS (SELECT 1 FROM security_alert_events ae WHERE ae.event_id=e.id)
                      AND NOT EXISTS (
                          SELECT 1
                          FROM security_alerts a
                          JOIN security_events source ON source.id=a.source_event_id
                          WHERE e.correlation_id IS NOT NULL
                            AND source.correlation_id=e.correlation_id
                      )
                    ORDER BY e.occurred_at,e.id
                    LIMIT ' . $limit . '
                    FOR UPDATE OF e SKIP LOCKED
                 ), moved AS (
                    INSERT INTO security_events_archive(
                        original_id,event_id,occurred_at,actor_id,action,resource_type,resource_id,
                        request_id,correlation_id,instance_id,ip,user_agent,authentication_level,
                        credential_public_id,before_state,after_state,result,reason,risk_level,metadata,
                        archive_batch_id,archived_at
                    )
                    SELECT e.id,e.event_id,e.occurred_at,e.actor_id,e.action,e.resource_type,e.resource_id,
                           e.request_id,e.correlation_id,e.instance_id,e.ip,e.user_agent,e.authentication_level,
                           e.credential_public_id,e.before_state,e.after_state,e.result,e.reason,e.risk_level,e.metadata,
                           :batch,NOW()
                    FROM security_events e
                    JOIN selected s ON s.id=e.id
                    ON CONFLICT(event_id) DO NOTHING
                    RETURNING original_id
                 )
                 DELETE FROM security_events e
                 USING moved m
                 WHERE e.id=m.original_id',
                ['cutoff' => $cutoffAt, 'batch' => $batchId],
            )->rowCount();

            $loginCount = $db->query(
                'WITH selected AS (
                    SELECT l.id
                    FROM auth_login_events l
                    WHERE l.created_at<:cutoff
                    ORDER BY l.created_at,l.id
                    LIMIT ' . $limit . '
                    FOR UPDATE OF l SKIP LOCKED
                 ), moved AS (
                    INSERT INTO auth_login_events_archive(
                        original_id,user_id,email,result,ip_hash,user_agent_hash,created_at,archive_batch_id,archived_at
                    )
                    SELECT l.id,l.user_id,l.email,l.result,l.ip_hash,l.user_agent_hash,l.created_at,:batch,NOW()
                    FROM auth_login_events l
                    JOIN selected s ON s.id=l.id
                    ON CONFLICT(original_id) DO NOTHING
                    RETURNING original_id
                 )
                 DELETE FROM auth_login_events l
                 USING moved m
                 WHERE l.id=m.original_id',
                ['cutoff' => $cutoffAt, 'batch' => $batchId],
            )->rowCount();

            $batchTotals = $db->one(
                'UPDATE security_event_archive_batches
                 SET security_event_count=security_event_count+:security_count,
                     login_event_count=login_event_count+:login_count,
                     completed_at=NOW()
                 WHERE id=:id
                 RETURNING security_event_count,login_event_count',
                [
                    'security_count' => $securityCount,
                    'login_count' => $loginCount,
                    'id' => $batchId,
                ],
            ) ?? [];

            return [
                'public_id' => $publicId,
                'security_events' => $securityCount,
                'login_events' => $loginCount,
                'total_security_events' => (int)($batchTotals['security_event_count'] ?? $securityCount),
                'total_login_events' => (int)($batchTotals['login_event_count'] ?? $loginCount),
                'cutoff_at' => $cutoffAt,
            ];
        });
    }

    public function hasArchivableRows(string $cutoffDate): bool
    {
        $cutoff = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($cutoffDate), new \DateTimeZone('UTC'));
        if ($cutoff === false || $cutoff->format('Y-m-d') !== trim($cutoffDate)) {
            return false;
        }
        $cutoffAt = $cutoff->format('Y-m-d 00:00:00');
        $security = (int)$this->db->cell(
            'SELECT 1
             FROM security_events e
             WHERE e.occurred_at<:cutoff
               AND NOT EXISTS (SELECT 1 FROM security_alert_events ae WHERE ae.event_id=e.id)
               AND NOT EXISTS (
                   SELECT 1 FROM security_alerts a
                   JOIN security_events source ON source.id=a.source_event_id
                   WHERE e.correlation_id IS NOT NULL AND source.correlation_id=e.correlation_id
               )
             LIMIT 1',
            ['cutoff' => $cutoffAt],
        );
        if ($security === 1) {
            return true;
        }
        return (int)$this->db->cell(
            'SELECT 1 FROM auth_login_events WHERE created_at<:cutoff LIMIT 1',
            ['cutoff' => $cutoffAt],
        ) === 1;
    }

    /** @return list<array<string,mixed>> */
    public function recentBatches(int $limit = 20): array
    {
        if (!$this->db->tableExists('security_event_archive_batches')) {
            return [];
        }
        return $this->db->all(
            'SELECT b.public_id,b.cutoff_at,b.security_event_count,b.login_event_count,b.created_at,b.completed_at,
                    u.display_name,u.email
             FROM security_event_archive_batches b
             LEFT JOIN users u ON u.id=b.archived_by
             ORDER BY b.created_at DESC,b.id DESC
             LIMIT ' . max(1, min(50, $limit)),
        );
    }
}

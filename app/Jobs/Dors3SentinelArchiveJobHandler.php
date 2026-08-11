<?php
declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\BackgroundJobHandlerInterface;
use App\Core\Database;
use App\Services\Dors3SentinelArchiveService;
use App\Services\DurableJobQueue;

final class Dors3SentinelArchiveJobHandler implements BackgroundJobHandlerInterface
{
    public const QUEUE = 'security-maintenance';
    public const JOB_TYPE = 'sentinel.archive_logs';

    public function __construct(
        private readonly Database $db,
        private readonly DurableJobQueue $queue,
    ) {}

    public function supports(string $jobType): bool
    {
        return $jobType === self::JOB_TYPE;
    }

    /** @return array<string,mixed> */
    public function handle(array $job): array
    {
        $payload = json_decode((string)$job['payload_json'], true, 32, JSON_THROW_ON_ERROR);
        $cutoff = trim((string)($payload['cutoff_date'] ?? ''));
        $actorId = (int)($payload['actor_id'] ?? 0);
        $authorizationId = trim((string)($payload['authorization_public_id'] ?? ''));
        $requestPublicId = trim((string)($payload['request_public_id'] ?? ''));
        $sequence = max(1, (int)($payload['sequence'] ?? 1));
        if ($actorId <= 0 || preg_match('/^[a-f0-9-]{36}$/D', $authorizationId) !== 1
            || preg_match('/^[a-f0-9-]{36}$/D', $requestPublicId) !== 1 || $sequence > 100000) {
            throw new NonRetryableJobException('sentinel_archive_job_payload_invalid');
        }

        $archive = new Dors3SentinelArchiveService($this->db);
        $chunk = max(100, min(5000, (int)env('SENTINEL_ARCHIVE_CHUNK_SIZE', 1000)));
        $result = $archive->archiveBefore($cutoff, $actorId, $authorizationId, $chunk, $requestPublicId);
        $hasMore = $archive->hasArchivableRows($cutoff);
        $this->db->query(
            'UPDATE security_event_archive_batches
             SET completed_at=CASE WHEN :has_more=1 THEN NULL ELSE NOW() END
             WHERE public_id=:public_id',
            ['has_more' => $hasMore ? 1 : 0, 'public_id' => $requestPublicId],
        );
        if ($hasMore) {
            $nextSequence = $sequence + 1;
            $this->queue->enqueue(
                self::QUEUE,
                self::JOB_TYPE,
                [
                    'cutoff_date' => $cutoff,
                    'actor_id' => $actorId,
                    'authorization_public_id' => $authorizationId,
                    'request_public_id' => $requestPublicId,
                    'sequence' => $nextSequence,
                ],
                'sentinel-archive:' . $requestPublicId . ':chunk:' . $nextSequence,
                -20,
                5,
                'automatic',
                $actorId,
            );
        }
        return [
            'finished' => !$hasMore,
            'security_events' => (int)$result['security_events'],
            'login_events' => (int)$result['login_events'],
            'sequence' => $sequence,
        ];
    }
}

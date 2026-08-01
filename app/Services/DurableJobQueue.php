<?php
declare(strict_types=1);

namespace App\Services;

use App\Contracts\QueueSignalInterface;
use App\Core\Database;

final class DurableJobQueue
{
    public function __construct(
        private readonly Database $db,
        private readonly ?QueueSignalInterface $signals = null,
    ) {}

    /** @param array<string,mixed> $payload
     *  @return array<string,mixed>
     */
    public function enqueue(
        string $queueName,
        string $jobType,
        array $payload,
        string $idempotencyKey,
        int $priority = 0,
        int $maxAttempts = 5,
        string $retryPolicy = 'automatic',
        ?int $actorUserId = null,
        ?string $requiredPermission = null,
        ?string $requestId = null,
        ?string $actorIp = null,
        bool $allowPayloadMismatchOnDuplicate = false,
    ): array {
        $queueName = $this->identifier($queueName, 80, 'kolejki');
        $jobType = $this->identifier($jobType, 100, 'typu zadania');
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 190) {
            throw new \InvalidArgumentException('Nieprawidłowy klucz idempotencji zadania.');
        }
        if (!in_array($retryPolicy, ['automatic', 'manual'], true)) {
            throw new \InvalidArgumentException('Nieprawidłowa polityka ponowień zadania.');
        }
        $maxAttempts = max(1, min(20, $maxAttempts));
        $payloadJson = $this->json($payload);

        $job = $this->db->transaction(function (Database $db) use (
            $queueName, $jobType, $payloadJson, $idempotencyKey, $priority, $maxAttempts,
            $retryPolicy, $actorUserId, $requiredPermission, $requestId, $actorIp,
            $allowPayloadMismatchOnDuplicate
        ): array {
            $publicId = $this->uuidV4();
            $commonSql = 'INSERT INTO background_jobs(
                    public_id,queue_name,job_type,status,priority,payload_json,idempotency_key,
                    actor_user_id,required_permission,request_id,actor_ip,retry_policy,
                    attempts,max_attempts,available_at,created_at,updated_at
                 ) VALUES(
                    :public_id,:queue_name,:job_type,\'queued\',:priority,:payload_json,:idempotency_key,
                    :actor_user_id,:required_permission,:request_id,:actor_ip,:retry_policy,
                    0,:max_attempts,NOW(),NOW(),NOW()
                 )';
            $params = [
                'public_id' => $publicId,
                'queue_name' => $queueName,
                'job_type' => $jobType,
                'priority' => $priority,
                'payload_json' => $payloadJson,
                'idempotency_key' => $idempotencyKey,
                'actor_user_id' => $actorUserId !== null && $actorUserId > 0 ? $actorUserId : null,
                'required_permission' => $requiredPermission,
                'request_id' => $requestId,
                'actor_ip' => $actorIp,
                'retry_policy' => $retryPolicy,
                'max_attempts' => $maxAttempts,
            ];
            if ($db->isPostgres()) {
                $db->insert($commonSql . ' ON CONFLICT(queue_name,idempotency_key) DO UPDATE SET idempotency_key=EXCLUDED.idempotency_key', $params);
            } else {
                $db->insert($commonSql . ' ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)', $params);
            }
            $row = $db->one(
                'SELECT * FROM background_jobs WHERE queue_name=:queue_name AND idempotency_key=:idempotency_key FOR UPDATE',
                ['queue_name' => $queueName, 'idempotency_key' => $idempotencyKey]
            );
            if ($row === null) {
                throw new \RuntimeException('Nie udało się odczytać zapisanego zadania.');
            }
            if ((string)$row['job_type'] !== $jobType) {
                throw new \LogicException('Klucz idempotencji został użyty dla innego typu zadania.');
            }
            if (
                !$allowPayloadMismatchOnDuplicate
                && $this->normalizeJson((string)$row['payload_json']) !== $this->normalizeJson($payloadJson)
            ) {
                throw new \LogicException('Klucz idempotencji został użyty dla innej treści zadania.');
            }
            if ((string)$row['public_id'] === $publicId) {
                $this->recordEvent($db, (int)$row['id'], 'enqueued', null, 'queued', null, ['queue' => $queueName]);
                $row['duplicate'] = false;
            } else {
                $row['duplicate'] = true;
            }
            return $row;
        });

        $this->signals?->notify($queueName, (string)$job['public_id']);
        return $job;
    }

    /** @return array<string,mixed>|null */
    public function claimOne(string $queueName, string $workerId, int $leaseSeconds = 300): ?array
    {
        $queueName = $this->identifier($queueName, 80, 'kolejki');
        $workerId = $this->identifier($workerId, 100, 'workera');
        $leaseSeconds = max(30, min(3600, $leaseSeconds));

        return $this->db->transaction(function (Database $db) use ($queueName, $workerId, $leaseSeconds): ?array {
            $this->recoverExpiredLeases($queueName);
            $job = $db->one(
                'SELECT * FROM background_jobs
                 WHERE queue_name=:queue_name AND status IN (\'queued\',\'retry\') AND available_at<=NOW()
                 ORDER BY priority DESC,available_at,id
                 LIMIT 1 FOR UPDATE SKIP LOCKED',
                ['queue_name' => $queueName]
            );
            if ($job === null) {
                return null;
            }
            $db->query(
                'UPDATE background_jobs
                 SET status=\'running\',attempts=attempts+1,locked_by=:worker,
                     lease_expires_at=' . $db->nowPlus($leaseSeconds, 'second') . ',
                     started_at=COALESCE(started_at,NOW()),updated_at=NOW()
                 WHERE id=:id',
                ['worker' => $workerId, 'id' => (int)$job['id']]
            );
            $this->recordEvent($db, (int)$job['id'], 'claimed', (string)$job['status'], 'running', $workerId, [
                'lease_seconds' => $leaseSeconds,
            ]);
            return $db->one('SELECT * FROM background_jobs WHERE id=:id', ['id' => (int)$job['id']]);
        });
    }

    /** @param array<string,mixed> $result */
    public function complete(int $jobId, string $workerId, array $result = []): void
    {
        $this->transitionOwned($jobId, $workerId, 'completed', 'completed', $result, null);
    }

    public function reject(int $jobId, string $workerId, string $reason): void
    {
        $this->transitionOwned($jobId, $workerId, 'rejected', 'rejected', [], $reason);
    }

    public function deadLetter(int $jobId, string $workerId, string $reason): void
    {
        $this->transitionOwned($jobId, $workerId, 'dead_letter', 'dead_lettered', [], $reason);
    }

    public function fail(int $jobId, string $workerId, \Throwable $error): string
    {
        return $this->db->transaction(function (Database $db) use ($jobId, $workerId, $error): string {
            $workerId = $this->identifier($workerId, 100, 'workera');
            $job = $db->one(
                'SELECT * FROM background_jobs WHERE id=:id AND status=\'running\' AND locked_by=:worker FOR UPDATE',
                ['id' => $jobId, 'worker' => $workerId]
            );
            if ($job === null) {
                throw new \RuntimeException("Worker utracił dzierżawę zadania #{$jobId}.");
            }
            $automatic = (string)$job['retry_policy'] === 'automatic';
            $canRetry = $automatic && (int)$job['attempts'] < (int)$job['max_attempts'];
            $status = $canRetry ? 'retry' : 'dead_letter';
            $delay = min(3600, 15 * (2 ** max(0, (int)$job['attempts'] - 1)));
            $db->query(
                'UPDATE background_jobs SET status=:status,last_error=:error,
                    available_at=CASE WHEN :retry=1 THEN ' . $db->nowPlus($delay, 'second') . ' ELSE available_at END,
                    lease_expires_at=NULL,locked_by=NULL,updated_at=NOW(),
                    dead_lettered_at=CASE WHEN :dead=1 THEN NOW() ELSE NULL END
                 WHERE id=:id',
                [
                    'status' => $status,
                    'error' => mb_substr($error->getMessage(), 0, 4000),
                    'retry' => $canRetry ? 1 : 0,
                    'dead' => $canRetry ? 0 : 1,
                    'id' => $jobId,
                ]
            );
            $this->recordEvent($db, $jobId, $canRetry ? 'retry_scheduled' : 'dead_lettered', 'running', $status, $workerId, [
                'error' => mb_substr($error->getMessage(), 0, 1000),
                'delay_seconds' => $canRetry ? $delay : null,
            ]);
            return $status;
        });
    }

    public function recoverExpiredLeases(?string $queueName = null): array
    {
        return $this->db->transaction(function (Database $db) use ($queueName): array {
            $params = [];
            $queueFilter = '';
            if ($queueName !== null) {
                $queueName = $this->identifier($queueName, 80, 'kolejki');
                $queueFilter = ' AND queue_name=:queue_name';
                $params['queue_name'] = $queueName;
            }
            $rows = $db->all(
                'SELECT * FROM background_jobs
                 WHERE status=\'running\' AND lease_expires_at<NOW()' . $queueFilter . '
                 FOR UPDATE SKIP LOCKED',
                $params
            );
            $result = ['retry' => 0, 'dead_letter' => 0];
            foreach ($rows as $job) {
                $canRetry = (string)$job['retry_policy'] === 'automatic'
                    && (int)$job['attempts'] < (int)$job['max_attempts'];
                $status = $canRetry ? 'retry' : 'dead_letter';
                $db->query(
                    'UPDATE background_jobs SET status=:status,locked_by=NULL,lease_expires_at=NULL,
                        available_at=NOW(),last_error=:error,updated_at=NOW(),
                        dead_lettered_at=CASE WHEN :dead=1 THEN NOW() ELSE NULL END
                     WHERE id=:id',
                    [
                        'status' => $status,
                        'error' => 'Wygasła dzierżawa workera; wynik poprzedniej próby jest nieznany.',
                        'dead' => $canRetry ? 0 : 1,
                        'id' => (int)$job['id'],
                    ]
                );
                $this->recordEvent($db, (int)$job['id'], 'lease_expired', 'running', $status, (string)($job['locked_by'] ?? ''), []);
                $result[$status]++;
            }
            return $result;
        });
    }

    /** @return array<string,int> */
    public function statistics(?string $queueName = null): array
    {
        $params = [];
        $where = '';
        if ($queueName !== null) {
            $where = ' WHERE queue_name=:queue_name';
            $params['queue_name'] = $this->identifier($queueName, 80, 'kolejki');
        }
        $result = [];
        foreach ($this->db->all('SELECT status,COUNT(*) AS total FROM background_jobs' . $where . ' GROUP BY status', $params) as $row) {
            $result[(string)$row['status']] = (int)$row['total'];
        }
        return $result;
    }

    private function transitionOwned(int $jobId, string $workerId, string $status, string $event, array $result, ?string $error): void
    {
        $this->db->transaction(function (Database $db) use ($jobId, $workerId, $status, $event, $result, $error): void {
            $workerId = $this->identifier($workerId, 100, 'workera');
            $job = $db->one(
                'SELECT id FROM background_jobs WHERE id=:id AND status=\'running\' AND locked_by=:worker FOR UPDATE',
                ['id' => $jobId, 'worker' => $workerId]
            );
            if ($job === null) {
                throw new \RuntimeException("Worker utracił dzierżawę zadania #{$jobId}.");
            }
            $db->query(
                'UPDATE background_jobs SET status=:status,result_json=:result,last_error=:error,
                    lease_expires_at=NULL,locked_by=NULL,updated_at=NOW(),
                    completed_at=CASE WHEN :completed=1 THEN NOW() ELSE completed_at END,
                    dead_lettered_at=CASE WHEN :dead=1 THEN NOW() ELSE dead_lettered_at END
                 WHERE id=:id',
                [
                    'status' => $status,
                    'result' => $this->json($result),
                    'error' => $error !== null ? mb_substr($error, 0, 4000) : null,
                    'completed' => $status === 'completed' ? 1 : 0,
                    'dead' => $status === 'dead_letter' ? 1 : 0,
                    'id' => $jobId,
                ]
            );
            $this->recordEvent($db, $jobId, $event, 'running', $status, $workerId, $error !== null ? ['error' => mb_substr($error, 0, 1000)] : $result);
        });
    }

    private function recordEvent(Database $db, int $jobId, string $event, ?string $from, string $to, ?string $workerId, array $details): void
    {
        $db->query(
            'INSERT INTO background_job_events(
                background_job_id,event_type,from_status,to_status,worker_id,details_json,created_at
             ) VALUES(:job,:event,:from_status,:to_status,:worker,:details,NOW())',
            [
                'job' => $jobId,
                'event' => $event,
                'from_status' => $from,
                'to_status' => $to,
                'worker' => $workerId !== '' ? $workerId : null,
                'details' => $this->json($details),
            ]
        );
    }

    private function identifier(string $value, int $maxLength, string $label): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > $maxLength || preg_match('/[^A-Za-z0-9_.:@-]/', $value) === 1) {
            throw new \InvalidArgumentException("Nieprawidłowy identyfikator {$label}.");
        }
        return $value;
    }

    private function json(array $value): string
    {
        return json_encode($this->canonicalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function normalizeJson(string $json): string
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return json_encode($this->canonicalize($decoded), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }
}

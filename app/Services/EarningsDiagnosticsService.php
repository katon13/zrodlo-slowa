<?php
declare(strict_types=1);

namespace App\Services;

use App\Contracts\ValkeyClientInterface;
use App\Core\Database;
use App\Core\SlowoSnajperConfig;

final class EarningsDiagnosticsService
{
    public function __construct(
        private readonly Database $db,
        private readonly ?ValkeyClientInterface $valkey,
        private readonly SlowoSnajperConfig $config,
    ) {}

    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        $queues = [EarningsQueueService::QUEUE, NotificationOutboxDispatcher::QUEUE];
        $counts = [];
        foreach ($this->db->all(
            'SELECT queue_name,status,COUNT(*) AS total
             FROM background_jobs
             WHERE queue_name IN (:earnings,:notifications)
             GROUP BY queue_name,status',
            ['earnings' => $queues[0], 'notifications' => $queues[1]]
        ) as $row) {
            $counts[(string)$row['queue_name']][(string)$row['status']] = (int)$row['total'];
        }

        $latencies = [];
        $decisions = [];
        $recent = $this->db->all(
            'SELECT queue_name,status,created_at,completed_at,result_json
             FROM background_jobs
             WHERE queue_name IN (:earnings,:notifications)
               AND created_at>=' . $this->db->nowMinus(1, 'day') . '
             ORDER BY id DESC LIMIT 1000',
            ['earnings' => $queues[0], 'notifications' => $queues[1]]
        );
        foreach ($recent as $row) {
            if ($row['completed_at'] !== null) {
                $start = $this->timestampMilliseconds((string)$row['created_at']);
                $end = $this->timestampMilliseconds((string)$row['completed_at']);
                if ($start !== null && $end !== null && $end >= $start) {
                    $latencies[] = (int)round($end - $start);
                }
            }
            if ((string)$row['queue_name'] !== EarningsQueueService::QUEUE) {
                continue;
            }
            $result = json_decode((string)($row['result_json'] ?? ''), true);
            $reason = is_array($result) ? trim((string)($result['reason'] ?? $result['decision'] ?? '')) : '';
            if ($reason !== '') {
                $decisions[$reason] = ($decisions[$reason] ?? 0) + 1;
            }
        }
        sort($latencies, SORT_NUMERIC);

        $oldest = [];
        foreach ($this->db->all(
            'SELECT queue_name,MIN(created_at) AS oldest_at
             FROM background_jobs
             WHERE queue_name IN (:earnings,:notifications) AND status IN (\'queued\',\'retry\',\'running\')
             GROUP BY queue_name',
            ['earnings' => $queues[0], 'notifications' => $queues[1]]
        ) as $row) {
            $oldest[(string)$row['queue_name']] = (string)$row['oldest_at'];
        }

        $earningsRuntime = (new EarningsWorkerRuntime(
            $this->valkey,
            $this->config->earningsHeartbeatSeconds() * 3,
        ))->read();
        $notificationsRuntime = (new EarningsWorkerRuntime(
            $this->valkey,
            $this->config->earningsHeartbeatSeconds() * 3,
            'notifications-worker:runtime',
        ))->read();

        return [
            'queues' => $counts,
            'oldest_pending_at' => $oldest,
            'recent_jobs' => count($recent),
            'latency_ms' => [
                'average' => $latencies !== [] ? (int)round(array_sum($latencies) / count($latencies)) : null,
                'p95' => $this->percentile($latencies, 0.95),
                'maximum' => $latencies !== [] ? max($latencies) : null,
                'target' => $this->config->earningsMaxJobLatencySeconds() * 1000,
            ],
            'decisions' => $decisions,
            'earnings_worker' => $this->runtimeStatus($earningsRuntime),
            'notifications_worker' => $this->runtimeStatus($notificationsRuntime),
            'rules' => [
                'total' => (int)$this->db->cell('SELECT COUNT(*) FROM activity_reward_rules'),
                'active' => (int)$this->db->cell('SELECT COUNT(*) FROM activity_reward_rules WHERE is_active=1'),
                'active_zero_value' => (int)$this->db->cell(
                    'SELECT COUNT(*) FROM activity_reward_rules
                     WHERE is_active=1 AND points_amount=0 AND amount_minor=0'
                ),
            ],
            'idle_database_polling' => $this->config->earningsIdleDatabasePolling(),
            'generated_at' => gmdate('c'),
        ];
    }

    /** @param list<int> $values */
    private function percentile(array $values, float $percentile): ?int
    {
        if ($values === []) {
            return null;
        }
        $index = (int)ceil(count($values) * $percentile) - 1;
        return $values[max(0, min(count($values) - 1, $index))];
    }

    private function timestampMilliseconds(string $value): ?float
    {
        try {
            return (float)(new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->format('U.u') * 1000;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed>|null $runtime @return array<string,mixed> */
    private function runtimeStatus(?array $runtime): array
    {
        $heartbeat = isset($runtime['heartbeat_at']) ? strtotime((string)$runtime['heartbeat_at']) : false;
        $age = $heartbeat !== false ? max(0, time() - $heartbeat) : null;
        return [
            'healthy' => $age !== null && $age <= $this->config->earningsHeartbeatSeconds() * 4,
            'heartbeat_age_seconds' => $age,
            'state' => $runtime,
        ];
    }
}

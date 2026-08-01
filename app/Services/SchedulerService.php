<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class SchedulerService
{
    public function __construct(
        private readonly Database $db,
        private readonly DurableJobQueue $jobs,
        private readonly MailService $mail,
        private readonly ?LedgerAnchorService $ledgerAnchors = null,
    ) {}

    /** @return array<string,mixed> */
    public function runMinute(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $slot = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:00');
        $inserted = false;
        $runId = 0;
        if ($this->db->isPostgres()) {
            $statement = $this->db->query(
                'INSERT INTO scheduler_runs(task_name,scheduled_for,status,created_at)
                 VALUES(\'maintenance.minute\',:slot,\'running\',NOW())
                 ON CONFLICT(task_name,scheduled_for) DO NOTHING RETURNING id',
                ['slot' => $slot]
            );
            $value = $statement->fetchColumn();
            if ($value !== false) {
                $inserted = true;
                $runId = (int)$value;
            }
        } else {
            $statement = $this->db->query(
                'INSERT IGNORE INTO scheduler_runs(task_name,scheduled_for,status,created_at)
                 VALUES(\'maintenance.minute\',:slot,\'running\',NOW())',
                ['slot' => $slot]
            );
            $inserted = $statement->rowCount() === 1;
            if ($inserted) {
                $runId = (int)$this->db->pdo()->lastInsertId();
            }
        }
        if (!$inserted) {
            $existing = $this->db->one(
                'SELECT id,status,result_json FROM scheduler_runs WHERE task_name=\'maintenance.minute\' AND scheduled_for=:slot',
                ['slot' => $slot]
            );
            return ['duplicate' => true, 'run' => $existing];
        }

        try {
            $result = [
                'jobs_recovered' => $this->jobs->recoverExpiredLeases(),
                'mail_recovered' => $this->mail->recoverExpiredLeases(),
            ];
            if ($now->setTimezone(new \DateTimeZone('UTC'))->format('i') === '00') {
                $result['ledger_anchor'] = ($this->ledgerAnchors ?? new LedgerAnchorService($this->db))
                    ->createHourly($now);
            }
            $this->db->query(
                'UPDATE scheduler_runs SET status=\'completed\',result_json=:result,completed_at=NOW() WHERE id=:id',
                ['result' => json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'id' => $runId]
            );
            return ['duplicate' => false, 'run_id' => $runId, 'result' => $result];
        } catch (\Throwable $error) {
            $this->db->query(
                'UPDATE scheduler_runs SET status=\'failed\',error=:error,completed_at=NOW() WHERE id=:id',
                ['error' => mb_substr($error->getMessage(), 0, 4000), 'id' => $runId]
            );
            throw $error;
        }
    }
}

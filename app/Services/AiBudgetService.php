<?php
namespace App\Services;

use App\Core\Database;

final class AiBudgetService
{
    public function __construct(private readonly Database $db) {}

    public function estimate(int $sourceCharacters, int $targetLanguageCount, int $ratePerThousandMinor): int
    {
        if ($sourceCharacters <= 0 || $targetLanguageCount <= 0) {
            throw new \InvalidArgumentException('Nie można oszacować pustego zadania AI.');
        }
        $ratePerThousandMinor = max(1, $ratePerThousandMinor);
        return max(1, (int)ceil(($sourceCharacters * $targetLanguageCount) / 1000) * $ratePerThousandMinor);
    }

    public function reserveAndRun(int $estimateMinor, int $monthlyBudgetMinor, callable $operation): mixed
    {
        if ($estimateMinor <= 0 || $monthlyBudgetMinor <= 0) {
            throw new \RuntimeException('Budżet i estymacja AI muszą być dodatnie.');
        }
        $period = date('Y-m-01');

        return $this->db->transaction(function (Database $db) use (
            $estimateMinor,
            $monthlyBudgetMinor,
            $period,
            $operation
        ): mixed {
            $sql = $db->isPostgres()
                ? 'INSERT INTO ai_budget_periods(
                       period_start,budget_minor,reserved_minor,spent_minor,currency,updated_at
                   ) VALUES(:period,:budget,0,0,\'PLN\',NOW())
                   ON CONFLICT (period_start) DO UPDATE
                   SET budget_minor=EXCLUDED.budget_minor,updated_at=NOW()'
                : 'INSERT INTO ai_budget_periods(
                       period_start,budget_minor,reserved_minor,spent_minor,currency,updated_at
                   ) VALUES(:period,:budget,0,0,\'PLN\',NOW())
                   ON DUPLICATE KEY UPDATE budget_minor=VALUES(budget_minor),updated_at=NOW()';
            $db->query($sql, ['period' => $period, 'budget' => $monthlyBudgetMinor]);
            $budget = $db->one(
                'SELECT * FROM ai_budget_periods WHERE period_start=:period FOR UPDATE',
                ['period' => $period]
            );
            if ($budget === null) {
                throw new \RuntimeException('Nie udało się zablokować budżetu AI.');
            }
            $used = (int)$budget['reserved_minor'] + (int)$budget['spent_minor'];
            if ($used + $estimateMinor > $monthlyBudgetMinor) {
                throw new \RuntimeException(sprintf(
                    'Miesięczny budżet AI zostałby przekroczony: użyto/rezerwowano %d, zadanie %d, limit %d groszy.',
                    $used,
                    $estimateMinor,
                    $monthlyBudgetMinor
                ));
            }
            $db->query(
                'UPDATE ai_budget_periods
                 SET reserved_minor=reserved_minor+:estimate,updated_at=NOW()
                 WHERE period_start=:period',
                ['estimate' => $estimateMinor, 'period' => $period]
            );
            return $operation($db, $estimateMinor, $period);
        });
    }

    public function settle(int $jobId, ?int $actualCostMinor = null): void
    {
        $this->db->transaction(function (Database $db) use ($jobId, $actualCostMinor): void {
            $job = $db->one(
                'SELECT id,estimated_cost_minor,budget_period,budget_status
                 FROM ai_jobs WHERE id=:id FOR UPDATE',
                ['id' => $jobId]
            );
            if ($job === null || $job['budget_status'] !== 'reserved') {
                return;
            }
            $estimate = (int)$job['estimated_cost_minor'];
            $actual = max(0, $actualCostMinor ?? $estimate);
            $period = (string)$job['budget_period'];
            $budget = $db->one(
                'SELECT period_start FROM ai_budget_periods WHERE period_start=:period FOR UPDATE',
                ['period' => $period]
            );
            if ($budget === null) {
                throw new \RuntimeException('Brak okresu budżetowego przypisanego do zadania AI.');
            }
            $db->query(
                'UPDATE ai_budget_periods
                 SET reserved_minor=GREATEST(0,reserved_minor-:estimate),
                     spent_minor=spent_minor+:actual,updated_at=NOW()
                 WHERE period_start=:period',
                ['estimate' => $estimate, 'actual' => $actual, 'period' => $period]
            );
            $db->query(
                'UPDATE ai_jobs SET actual_cost_minor=:actual,budget_status=\'spent\',updated_at=NOW()
                 WHERE id=:id',
                ['actual' => $actual, 'id' => $jobId]
            );
        });
    }

    public function current(): ?array
    {
        return $this->db->one(
            'SELECT * FROM ai_budget_periods WHERE period_start=:period',
            ['period' => date('Y-m-01')]
        );
    }
}

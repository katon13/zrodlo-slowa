<?php
declare(strict_types=1);

namespace App\Contracts;

interface FinancialReconciliationSourceInterface
{
    /**
     * Stable, provider-neutral totals used by a future automatic reconciler.
     *
     * @return array<string, int|string|null>
     */
    public function snapshot(): array;
}

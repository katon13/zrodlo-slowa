<?php
declare(strict_types=1);

namespace App\Services;

use App\Contracts\FinancialReconciliationSourceInterface;

final class FinancialReconciliationService
{
    /**
     * @return array{ok:bool,left:array<string,int|string|null>,right:array<string,int|string|null>,differences:array<string,array{left:mixed,right:mixed}>}
     */
    public function compare(
        FinancialReconciliationSourceInterface $left,
        FinancialReconciliationSourceInterface $right,
    ): array {
        $leftSnapshot = $left->snapshot();
        $rightSnapshot = $right->snapshot();
        $differences = [];
        $ignored = ['source', 'captured_at'];
        foreach (array_unique(array_merge(array_keys($leftSnapshot), array_keys($rightSnapshot))) as $key) {
            if (in_array($key, $ignored, true)) {
                continue;
            }
            $leftValue = $leftSnapshot[$key] ?? null;
            $rightValue = $rightSnapshot[$key] ?? null;
            if ($leftValue !== $rightValue) {
                $differences[$key] = ['left' => $leftValue, 'right' => $rightValue];
            }
        }
        return [
            'ok' => $differences === [],
            'left' => $leftSnapshot,
            'right' => $rightSnapshot,
            'differences' => $differences,
        ];
    }
}

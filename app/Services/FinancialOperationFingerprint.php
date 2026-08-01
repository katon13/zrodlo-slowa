<?php
declare(strict_types=1);

namespace App\Services;

final class FinancialOperationFingerprint
{
    /** @param array<string,mixed> $context */
    public static function calculate(
        int $userId,
        string $type,
        int $amountMinor,
        string $accountType,
        string $balanceType,
        string $sourceModule,
        string $status,
        array $context,
    ): string {
        $payload = [
            'account_type' => $accountType,
            'amount_minor' => $amountMinor,
            'balance_type' => $balanceType,
            'ref_id' => isset($context['ref_id']) ? (int)$context['ref_id'] : null,
            'ref_type' => isset($context['ref_type']) ? (string)$context['ref_type'] : null,
            'source_module' => $sourceModule,
            'status' => $status,
            'type' => $type,
            'user_id' => $userId,
        ];
        ksort($payload, SORT_STRING);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return hash('sha256', $json);
    }
}

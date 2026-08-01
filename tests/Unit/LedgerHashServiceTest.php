<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Services\LedgerHashService;
use PHPUnit\Framework\TestCase;

final class LedgerHashServiceTest extends TestCase
{
    public function testSignatureIsCanonicalAndDetectsTampering(): void
    {
        $service = new LedgerHashService(str_repeat('K', 48));
        $transaction = [
            'id' => 41,
            'wallet_id' => 7,
            'user_id' => 9,
            'source_module' => 'test',
            'type' => 'reward',
            'account_type' => 'points',
            'status' => 'posted',
            'amount_minor' => 25,
            'balance_before_minor' => 100,
            'balance_after_minor' => 125,
            'description' => null,
            'title_key' => 'bonus.title',
            'message_key' => null,
            'description_key' => null,
            'counterparty_user_id' => null,
            'ref_type' => 'test',
            'ref_id' => 3,
            'idempotency_key' => 'test:41',
            'meta_json' => ['z' => 2, 'a' => ['y' => 2, 'x' => 1]],
            'created_at' => '2026-07-25 12:00:00',
        ];

        $signature = $service->sign($transaction, 'pln', 'available', LedgerHashService::GENESIS_HASH);
        $reordered = $transaction;
        $reordered['meta_json'] = ['a' => ['x' => 1, 'y' => 2], 'z' => 2];
        self::assertSame(
            $signature,
            $service->sign($reordered, 'PLN', 'available', LedgerHashService::GENESIS_HASH)
        );

        $transaction['entry_hash'] = $signature;
        self::assertTrue($service->verify($transaction, 'PLN', 'available', LedgerHashService::GENESIS_HASH));
        $transaction['amount_minor'] = 26;
        self::assertFalse($service->verify($transaction, 'PLN', 'available', LedgerHashService::GENESIS_HASH));
    }

    public function testPlaceholderKeyIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        new LedgerHashService('change-this-example-key-at-once-123456789');
    }
}

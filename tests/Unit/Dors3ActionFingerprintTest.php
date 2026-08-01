<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Security\Dors3\ActionFingerprint;
use App\Security\Dors3\ApprovalContext;
use PHPUnit\Framework\TestCase;

final class Dors3ActionFingerprintTest extends TestCase
{
    public function testFingerprintIsStableForEquivalentKeyOrder(): void
    {
        $left = new ApprovalContext(
            'payout.approve',
            4,
            'payout',
            '91',
            ['currency' => 'PLN', 'amount_minor' => 5000, 'recipient_user_id' => 22],
            ['status' => 'requested'],
            ['status' => 'approved'],
        );
        $right = new ApprovalContext(
            'payout.approve',
            4,
            'payout',
            '91',
            ['recipient_user_id' => 22, 'amount_minor' => 5000, 'currency' => 'PLN'],
            ['status' => 'requested'],
            ['status' => 'approved'],
        );

        self::assertSame(
            ActionFingerprint::calculate($left, 'request-12345678', 1_900_000_000),
            ActionFingerprint::calculate($right, 'request-12345678', 1_900_000_000),
        );
    }

    public function testChangingAmountRecipientOrRequestInvalidatesFingerprint(): void
    {
        $base = new ApprovalContext(
            'payout.approve',
            4,
            'payout',
            '91',
            ['currency' => 'PLN', 'amount_minor' => 5000, 'recipient_user_id' => 22],
        );
        $changedAmount = new ApprovalContext(
            'payout.approve',
            4,
            'payout',
            '91',
            ['currency' => 'PLN', 'amount_minor' => 5001, 'recipient_user_id' => 22],
        );
        $fingerprint = ActionFingerprint::calculate($base, 'request-12345678', 1_900_000_000);

        self::assertNotSame($fingerprint, ActionFingerprint::calculate($changedAmount, 'request-12345678', 1_900_000_000));
        self::assertNotSame($fingerprint, ActionFingerprint::calculate($base, 'request-87654321', 1_900_000_000));
        self::assertNotSame($fingerprint, ActionFingerprint::calculate($base, 'request-12345678', 1_900_000_001));
    }
}

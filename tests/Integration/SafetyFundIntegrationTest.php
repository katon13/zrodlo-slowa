<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Services\ArticleEconomyService;
use App\Services\SafetyFundService;

final class SafetyFundIntegrationTest extends DatabaseTestCase
{
    public function testArticlePurchaseUsesOneVersioned404020SplitInExistingLedger(): void
    {
        $authorId = $this->createUser('author', true);
        $buyerId = $this->createUser('buyer', false);
        $this->createWallet($buyerId, 101);
        $articleId = $this->database->insert(
            'INSERT INTO articles(
                author_id,title,slug,lead,body,status,access_mode,price_minor,currency,
                is_premium,pricing_status,source_language,created_at,updated_at,published_at
             ) VALUES(
                :author,\'Test podziału 40/40/20\',:slug,\'Lead\',\'Treść\',\'published\',\'paid\',101,\'PLN\',
                1,\'priced\',\'pl\',NOW(),NOW(),NOW()
             )',
            ['author' => $authorId, 'slug' => 'safety-fund-' . bin2hex(random_bytes(5))],
        );
        $_SESSION['user_id'] = $buyerId;

        $purchaseId = (new ArticleEconomyService($this->database))->purchaseWithWallet($buyerId, $articleId);

        $purchase = $this->database->one(
            'SELECT * FROM article_purchases WHERE id=:id',
            ['id' => $purchaseId],
        );
        self::assertNotNull($purchase);
        self::assertSame(101, (int)$purchase['total_amount_minor']);
        self::assertSame(40, (int)$purchase['author_amount_minor']);
        self::assertSame(41, (int)$purchase['platform_amount_minor']);
        self::assertSame(20, (int)$purchase['safety_fund_amount_minor']);
        self::assertSame(4000, (int)$purchase['author_share_basis_points']);
        self::assertSame(4000, (int)$purchase['platform_share_basis_points']);
        self::assertSame(2000, (int)$purchase['safety_fund_share_basis_points']);
        self::assertSame(101, (int)$purchase['author_amount_minor'] + (int)$purchase['platform_amount_minor'] + (int)$purchase['safety_fund_amount_minor']);

        $policy = (new SafetyFundService($this->database))->currentPolicy();
        self::assertSame((int)$policy['id'], (int)$purchase['split_policy_id']);
        self::assertSame(1, (int)$this->database->cell(
            'SELECT COUNT(*) FROM safety_fund_allocations WHERE article_purchase_id=:purchase AND policy_id=:policy',
            ['purchase' => $purchaseId, 'policy' => $policy['id']],
        ));
        self::assertSame(0, (int)$this->database->cell(
            'SELECT slowo_available_minor FROM wallets WHERE user_id=:user',
            ['user' => $buyerId],
        ));
        self::assertSame(40, (int)$this->database->cell(
            'SELECT slowo_available_minor FROM wallets WHERE user_id=:user',
            ['user' => $authorId],
        ));
        self::assertSame(20, (new SafetyFundService($this->database))->balanceMinor());
        self::assertSame(4, (int)$this->database->cell(
            'SELECT COUNT(*) FROM wallet_transactions
             WHERE idempotency_key LIKE :prefix',
            ['prefix' => 'article-purchase:' . $buyerId . ':' . $articleId . ':%'],
        ));
    }

    public function testPolicyVersionsDoNotRecalculateHistoryAndDisbursementIsIdempotent(): void
    {
        $adminId = $this->createUser('admin', false);
        $service = new SafetyFundService($this->database);
        $fundId = $service->fundUserId();
        $this->createWallet($fundId, 0, 5000);

        $policyId = $service->activatePolicy($adminId, 4500, 3500, 2000, $this->uuid());
        $policy = $service->currentPolicy();
        self::assertSame($policyId, (int)$policy['id']);
        self::assertSame(2, (int)$policy['version']);
        self::assertSame('retired', (string)$this->database->cell(
            'SELECT status FROM revenue_split_policies WHERE version=1',
        ));

        $requestId = $this->uuid();
        $publicId = $this->uuid();
        $first = $service->executeDisbursement(
            $adminId,
            $publicId,
            1250,
            'legal_help',
            'Pomoc prawna dla autora publikacji.',
            'SPRAWA-PHPUNIT-1',
            $requestId,
            time(),
        );
        $second = $service->executeDisbursement(
            $adminId,
            $publicId,
            1250,
            'legal_help',
            'Pomoc prawna dla autora publikacji.',
            'SPRAWA-PHPUNIT-1',
            $requestId,
            time(),
        );
        self::assertSame($first, $second);
        self::assertSame(3750, $service->balanceMinor());
        self::assertSame(1, (int)$this->database->cell(
            'SELECT COUNT(*) FROM safety_fund_disbursements WHERE approval_request_public_id=:request',
            ['request' => $requestId],
        ));
    }

    public function testDisbursementAboveCurrentBalanceIsRejectedWithoutLedgerEntry(): void
    {
        $adminId = $this->createUser('admin-insufficient', false);
        $service = new SafetyFundService($this->database);
        $fundId = $service->fundUserId();
        $this->createWallet($fundId, 0, 100);
        $requestId = $this->uuid();

        try {
            $service->executeDisbursement(
                $adminId,
                $this->uuid(),
                101,
                'expertise',
                'Ekspertyza na potrzeby ochrony autora.',
                'SPRAWA-PHPUNIT-2',
                $requestId,
                time(),
            );
            self::fail('Wydatek ponad saldo powinien zostać odrzucony.');
        } catch (\RuntimeException $error) {
            self::assertSame('safety_fund.error.insufficient_balance', $error->getMessage());
        }

        self::assertSame(100, $service->balanceMinor());
        self::assertSame(0, (int)$this->database->cell(
            'SELECT COUNT(*) FROM safety_fund_disbursements WHERE approval_request_public_id=:request',
            ['request' => $requestId],
        ));
        self::assertSame(0, (int)$this->database->cell(
            'SELECT COUNT(*) FROM wallet_transactions WHERE idempotency_key=:key',
            ['key' => 'safety-fund-disbursement:' . $requestId],
        ));
    }

    private function createUser(string $label, bool $author): int
    {
        $id = $this->database->insert(
            'INSERT INTO users(
                email,password_hash,display_name,status,can_write,talent_enabled,wallet_enabled,payout_enabled,
                created_at,session_version
             ) VALUES(:email,:password,:name,\'active\',:can_write,0,1,:payout,NOW(),0)',
            [
                'email' => $label . '-' . bin2hex(random_bytes(6)) . '@phpunit.example',
                'password' => password_hash('not-used', PASSWORD_DEFAULT),
                'name' => 'PHPUnit ' . $label,
                'can_write' => $author ? 1 : 0,
                'payout' => $author ? 1 : 0,
            ],
        );
        $this->database->query(
            'INSERT INTO user_roles(user_id,role) VALUES(:user,:role)',
            ['user' => $id, 'role' => $author ? 'author' : ($label === 'admin' || str_starts_with($label, 'admin-') ? 'admin' : 'reader')],
        );
        return $id;
    }

    private function createWallet(int $userId, int $slowoMinor, int $mainMinor = 0): void
    {
        if ($this->database->one('SELECT id FROM wallets WHERE user_id=:user', ['user' => $userId]) !== null) {
            $this->database->query(
                'UPDATE wallets SET slowo_available_minor=:slowo,main_available_minor=:main,available_minor=:available WHERE user_id=:user',
                ['slowo' => $slowoMinor, 'main' => $mainMinor, 'available' => $slowoMinor + $mainMinor, 'user' => $userId],
            );
            return;
        }
        $this->database->query(
            'INSERT INTO wallets(
                user_id,main_available_minor,main_reserved_minor,slowo_available_minor,slowo_reserved_minor,
                available_minor,pending_minor,reserved_minor,points_balance,currency,created_at
             ) VALUES(:user,:main,0,:slowo,0,:available,0,0,0,\'PLN\',NOW())',
            ['user' => $userId, 'main' => $mainMinor, 'slowo' => $slowoMinor, 'available' => $slowoMinor + $mainMinor],
        );
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}

<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class SafetyFundService
{
    public const INITIAL_AUTHOR_BASIS_POINTS = 4000;
    public const INITIAL_PLATFORM_BASIS_POINTS = 4000;
    public const INITIAL_SAFETY_FUND_BASIS_POINTS = 2000;
    public const BASIS_POINTS_TOTAL = 10000;
    public const FUND_ACCOUNT_EMAIL = 'safety-fund@zrodlo-slowa.local';

    /** @var list<string> */
    public const CATEGORIES = [
        'legal_help',
        'proceedings',
        'expertise',
        'materials_protection',
        'other',
    ];

    public function __construct(private readonly Database $db) {}

    /** @return array<string,mixed> */
    public function currentPolicy(bool $forUpdate = false): array
    {
        $policy = $this->db->one(
            'SELECT * FROM revenue_split_policies
             WHERE status=\'active\' AND effective_from<=NOW()
             ORDER BY effective_from DESC,id DESC LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        if ($policy === null) {
            throw new \RuntimeException('safety_fund.error.active_policy_missing');
        }
        return $policy;
    }

    /**
     * @param array<string,mixed>|null $policy
     * @return array{author_amount_minor:int,platform_amount_minor:int,safety_fund_amount_minor:int}
     */
    public function splitAmount(int $totalAmountMinor, ?array $policy = null): array
    {
        if ($totalAmountMinor <= 0) {
            throw new \InvalidArgumentException('safety_fund.error.amount_positive');
        }
        $policy ??= $this->currentPolicy();
        $this->validatePolicy(
            (int)$policy['author_basis_points'],
            (int)$policy['platform_basis_points'],
            (int)$policy['safety_fund_basis_points'],
        );

        $author = intdiv($totalAmountMinor * (int)$policy['author_basis_points'], self::BASIS_POINTS_TOTAL);
        $safetyFund = intdiv($totalAmountMinor * (int)$policy['safety_fund_basis_points'], self::BASIS_POINTS_TOTAL);
        $platform = $totalAmountMinor - $author - $safetyFund;

        return [
            'author_amount_minor' => $author,
            'platform_amount_minor' => $platform,
            'safety_fund_amount_minor' => $safetyFund,
        ];
    }

    public function validatePolicy(int $author, int $platform, int $safetyFund): void
    {
        foreach ([$author, $platform, $safetyFund] as $value) {
            if ($value < 0 || $value > self::BASIS_POINTS_TOTAL) {
                throw new \InvalidArgumentException('safety_fund.error.invalid_split_value');
            }
        }
        if ($author + $platform + $safetyFund !== self::BASIS_POINTS_TOTAL) {
            throw new \InvalidArgumentException('safety_fund.error.invalid_split_total');
        }
    }

    public function fundUserId(): int
    {
        $existing = $this->db->one('SELECT id FROM users WHERE email=:email LIMIT 1', [
            'email' => self::FUND_ACCOUNT_EMAIL,
        ]);
        if ($existing !== null) {
            return (int)$existing['id'];
        }

        $sql = 'INSERT INTO users(
                    email,phone,password_hash,display_name,status,can_write,talent_enabled,
                    wallet_enabled,payout_enabled,permissions_updated_at,created_at
                ) VALUES(:email,NULL,:hash,:name,\'active\',0,0,1,0,NOW(),NOW())';
        $sql .= $this->db->isPostgres()
            ? ' ON CONFLICT (email) DO NOTHING'
            : ' ON DUPLICATE KEY UPDATE email=VALUES(email)';
        $this->db->query($sql, [
            'email' => self::FUND_ACCOUNT_EMAIL,
            'hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
            'name' => 'Safety Fund — ochrona autorów',
        ]);
        $created = $this->db->one('SELECT id FROM users WHERE email=:email LIMIT 1', [
            'email' => self::FUND_ACCOUNT_EMAIL,
        ]);
        if ($created === null) {
            throw new \RuntimeException('safety_fund.error.account_missing');
        }
        return (int)$created['id'];
    }

    public function balanceMinor(): int
    {
        $fundUserId = $this->fundUserId();
        $wallet = (new LedgerService($this->db, new FinancialService($this->db)))->walletForUser($fundUserId);
        return (int)$wallet['main_available_minor'];
    }

    public function recordAllocation(
        int $purchaseId,
        int $paymentId,
        int $articleId,
        array $policy,
        int $baseAmountMinor,
        int $safetyFundAmountMinor,
        string $currency,
        int $ledgerTransactionId,
    ): int {
        return $this->db->insert(
            'INSERT INTO safety_fund_allocations(
                article_purchase_id,payment_id,article_id,policy_id,base_amount_minor,
                safety_fund_amount_minor,safety_fund_basis_points,currency,
                ledger_transaction_id,created_at
             ) VALUES(
                :purchase,:payment,:article,:policy,:base_amount,:fund_amount,:fund_bps,
                :currency,:ledger_transaction,NOW()
             )',
            [
                'purchase' => $purchaseId,
                'payment' => $paymentId,
                'article' => $articleId,
                'policy' => (int)$policy['id'],
                'base_amount' => $baseAmountMinor,
                'fund_amount' => $safetyFundAmountMinor,
                'fund_bps' => (int)$policy['safety_fund_basis_points'],
                'currency' => $currency,
                'ledger_transaction' => $ledgerTransactionId,
            ]
        );
    }

    public function activatePolicy(
        int $adminId,
        int $authorBasisPoints,
        int $platformBasisPoints,
        int $safetyFundBasisPoints,
        string $approvalRequestPublicId,
    ): int {
        $this->validatePolicy($authorBasisPoints, $platformBasisPoints, $safetyFundBasisPoints);

        return $this->db->transaction(function (Database $db) use (
            $adminId,
            $authorBasisPoints,
            $platformBasisPoints,
            $safetyFundBasisPoints,
            $approvalRequestPublicId,
        ): int {
            $existing = $db->one(
                'SELECT id FROM revenue_split_policies WHERE approval_request_public_id=:request LIMIT 1',
                ['request' => $approvalRequestPublicId]
            );
            if ($existing !== null) {
                return (int)$existing['id'];
            }

            $before = $this->currentPolicy(true);
            if (
                (int)$before['author_basis_points'] === $authorBasisPoints
                && (int)$before['platform_basis_points'] === $platformBasisPoints
                && (int)$before['safety_fund_basis_points'] === $safetyFundBasisPoints
            ) {
                throw new \RuntimeException('safety_fund.error.policy_unchanged');
            }

            $version = (int)($db->cell('SELECT COALESCE(MAX(version),0)+1 FROM revenue_split_policies') ?: 1);
            $db->query(
                'UPDATE revenue_split_policies
                 SET status=\'retired\',retired_at=NOW()
                 WHERE id=:id AND status=\'active\'',
                ['id' => (int)$before['id']]
            );
            $policyId = $db->insert(
                'INSERT INTO revenue_split_policies(
                    version,author_basis_points,platform_basis_points,safety_fund_basis_points,
                    status,effective_from,created_by,activated_by,approval_request_public_id,
                    created_at,activated_at
                 ) VALUES(
                    :version,:author,:platform,:fund,\'active\',NOW(),:admin,:admin,:request,NOW(),NOW()
                 )',
                [
                    'version' => $version,
                    'author' => $authorBasisPoints,
                    'platform' => $platformBasisPoints,
                    'fund' => $safetyFundBasisPoints,
                    'admin' => $adminId,
                    'request' => $approvalRequestPublicId,
                ]
            );

            (new SecurityEventService($db))->record(
                $adminId,
                'safety_fund.policy.activated',
                'success',
                'high',
                'revenue_split_policy',
                (string)$policyId,
                [
                    'version' => (int)$before['version'],
                    'author_basis_points' => (int)$before['author_basis_points'],
                    'platform_basis_points' => (int)$before['platform_basis_points'],
                    'safety_fund_basis_points' => (int)$before['safety_fund_basis_points'],
                ],
                [
                    'version' => $version,
                    'author_basis_points' => $authorBasisPoints,
                    'platform_basis_points' => $platformBasisPoints,
                    'safety_fund_basis_points' => $safetyFundBasisPoints,
                ],
                null,
                null,
                ['approval_request_public_id' => $approvalRequestPublicId],
            );

            return $policyId;
        });
    }

    public function executeDisbursement(
        int $adminId,
        string $publicId,
        int $amountMinor,
        string $category,
        string $description,
        string $evidenceReference,
        string $approvalRequestPublicId,
        int $requestedAtEpoch,
    ): int {
        $description = trim($description);
        $evidenceReference = trim($evidenceReference);
        if ($amountMinor <= 0) {
            throw new \InvalidArgumentException('safety_fund.error.amount_positive');
        }
        if (!in_array($category, self::CATEGORIES, true)) {
            throw new \InvalidArgumentException('safety_fund.error.invalid_category');
        }
        if ($description === '' || mb_strlen($description) > 500) {
            throw new \InvalidArgumentException('safety_fund.error.description_required');
        }
        if ($evidenceReference === '' || mb_strlen($evidenceReference) > 255) {
            throw new \InvalidArgumentException('safety_fund.error.reference_required');
        }

        return $this->db->transaction(function (Database $db) use (
            $adminId,
            $publicId,
            $amountMinor,
            $category,
            $description,
            $evidenceReference,
            $approvalRequestPublicId,
            $requestedAtEpoch,
        ): int {
            $existing = $db->one(
                'SELECT id FROM safety_fund_disbursements WHERE approval_request_public_id=:request LIMIT 1',
                ['request' => $approvalRequestPublicId]
            );
            if ($existing !== null) {
                return (int)$existing['id'];
            }

            $fundUserId = $this->fundUserId();
            $ledger = new LedgerService($db, new FinancialService($db));
            $locked = $ledger->lockWalletsForUsers([$fundUserId]);
            $wallet = $locked[0] ?? null;
            if (!is_array($wallet)) {
                throw new \RuntimeException('safety_fund.error.account_missing');
            }
            $balanceBefore = (int)$wallet['main_available_minor'];
            if ($balanceBefore < $amountMinor) {
                (new SecurityEventService($db))->record(
                    $adminId,
                    'safety_fund.disbursement.blocked',
                    'blocked',
                    'high',
                    'safety_fund_disbursement',
                    $publicId,
                    ['balance_minor' => $balanceBefore],
                    ['requested_amount_minor' => $amountMinor],
                    'insufficient_balance',
                    null,
                    ['approval_request_public_id' => $approvalRequestPublicId],
                );
                throw new \RuntimeException('safety_fund.error.insufficient_balance');
            }

            $ledgerTransactionId = $ledger->post(
                $fundUserId,
                'safety_fund_disbursement',
                -$amountMinor,
                0,
                '',
                [
                    'source_module' => 'safety_fund',
                    'account_type' => 'main',
                    'ref_type' => 'safety_fund_disbursement',
                    'idempotency_key' => 'safety-fund-disbursement:' . $approvalRequestPublicId,
                    'meta' => [
                        'public_id' => $publicId,
                        'category' => $category,
                        'evidence_reference' => $evidenceReference,
                        'approval_request_public_id' => $approvalRequestPublicId,
                    ],
                ]
            );
            $balanceAfter = $balanceBefore - $amountMinor;
            $disbursementId = $db->insert(
                'INSERT INTO safety_fund_disbursements(
                    public_id,amount_minor,currency,category,description,evidence_reference,status,
                    requested_by,approval_request_public_id,ledger_transaction_id,
                    balance_before_minor,balance_after_minor,requested_at,executed_at,created_at
                 ) VALUES(
                    :public_id,:amount,\'PLN\',:category,:description,:reference,\'executed\',
                    :admin,:request,:ledger_transaction,:before,:after,:requested_at,NOW(),NOW()
                 )',
                [
                    'public_id' => $publicId,
                    'amount' => $amountMinor,
                    'category' => $category,
                    'description' => $description,
                    'reference' => $evidenceReference,
                    'admin' => $adminId,
                    'request' => $approvalRequestPublicId,
                    'ledger_transaction' => $ledgerTransactionId,
                    'before' => $balanceBefore,
                    'after' => $balanceAfter,
                    'requested_at' => gmdate('Y-m-d H:i:s', $requestedAtEpoch),
                ]
            );

            (new SecurityEventService($db))->record(
                $adminId,
                'safety_fund.disbursement.executed',
                'success',
                'high',
                'safety_fund_disbursement',
                (string)$disbursementId,
                ['balance_minor' => $balanceBefore],
                ['balance_minor' => $balanceAfter, 'amount_minor' => $amountMinor],
                null,
                null,
                [
                    'public_id' => $publicId,
                    'category' => $category,
                    'evidence_reference' => $evidenceReference,
                    'approval_request_public_id' => $approvalRequestPublicId,
                    'ledger_transaction_id' => $ledgerTransactionId,
                ],
            );

            return $disbursementId;
        });
    }

    /** @return array<string,mixed> */
    public function dashboard(): array
    {
        $policy = $this->currentPolicy();
        $summary = $this->db->one(
            'SELECT
                COALESCE((SELECT SUM(safety_fund_amount_minor) FROM safety_fund_allocations),0) AS total_inflow_minor,
                COALESCE((SELECT SUM(amount_minor) FROM safety_fund_disbursements WHERE status=\'executed\'),0) AS total_outflow_minor,
                (SELECT COUNT(*) FROM safety_fund_allocations) AS inflow_count,
                (SELECT COUNT(*) FROM safety_fund_disbursements WHERE status=\'executed\') AS outflow_count,
                (SELECT MAX(created_at) FROM safety_fund_allocations) AS last_inflow_at,
                (SELECT MAX(executed_at) FROM safety_fund_disbursements WHERE status=\'executed\') AS last_outflow_at'
        ) ?? [];

        return [
            'policy' => $policy,
            'balance_minor' => $this->balanceMinor(),
            'summary' => $summary,
            'allocations' => $this->db->all(
                'SELECT a.*,p.version,ar.title
                 FROM safety_fund_allocations a
                 JOIN revenue_split_policies p ON p.id=a.policy_id
                 JOIN articles ar ON ar.id=a.article_id
                 ORDER BY a.created_at DESC,a.id DESC LIMIT 100'
            ),
            'disbursements' => $this->db->all(
                'SELECT d.*,u.display_name AS administrator_name
                 FROM safety_fund_disbursements d
                 LEFT JOIN users u ON u.id=d.requested_by
                 ORDER BY d.created_at DESC,d.id DESC LIMIT 100'
            ),
            'policies' => $this->db->all(
                'SELECT p.*,u.display_name AS administrator_name
                 FROM revenue_split_policies p
                 LEFT JOIN users u ON u.id=p.activated_by
                 ORDER BY p.version DESC,p.id DESC'
            ),
            'approvals' => $this->db->all(
                'SELECT public_id,action_type,resource_type,resource_id,status,issued_at,expires_at,
                        approved_at,rejected_at,consumed_at,correlation_id
                 FROM security_mobile_approval_requests
                 WHERE action_type IN (\'financial_settings.change\',\'safety_fund.disbursement\')
                 ORDER BY created_at DESC,id DESC LIMIT 100'
            ),
            'audit' => $this->db->all(
                'SELECT event_id,occurred_at,actor_id,action,result,risk_level,resource_type,
                        resource_id,request_id,correlation_id,reason,metadata
                 FROM security_events
                 WHERE action LIKE \'safety_fund.%\'
                 ORDER BY occurred_at DESC,id DESC LIMIT 100'
            ),
        ];
    }
}

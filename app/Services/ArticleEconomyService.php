<?php
namespace App\Services;

use App\Core\Database;

final class ArticleEconomyService
{
    public const DEFAULT_AUTHOR_SHARE = 40.00;
    public const DEFAULT_PLATFORM_SHARE = 40.00;
    public const DEFAULT_SAFETY_FUND_SHARE = 20.00;

    public function __construct(
        private readonly Database $db,
        private readonly ?NotificationOutboxDispatcher $notificationOutbox = null,
    ) {}

    public function valueArticle(int $articleId, int $adminId, array $data): void
    {
        $article = $this->db->one('SELECT id,response_to_article_id FROM articles WHERE id=:id LIMIT 1', ['id' => $articleId]);
        if (!$article) {
            throw new \RuntimeException('Nie znaleziono tekstu do wyceny.');
        }
        if (!empty($article['response_to_article_id'])) {
            throw new \RuntimeException('Opinia lub polemika pozostaje bezpłatna i może otrzymać wyłącznie TT za publikację.');
        }

        $priceMinor = $this->parsePriceMinor((string)($data['price'] ?? '0'));
        $rawAccessMode = (string)($data['access_mode'] ?? 'free');
        $accessMode = in_array($rawAccessMode, ['free', 'paid'], true) ? $rawAccessMode : 'free';
        $isPremium = !empty($data['is_premium']);
        $isUnique = !empty($data['is_unique']);

        // Jeśli przełączamy na płatny, a is_premium nie został przesłany (np. ukryty checkbox),
        // to domyślnie ustawiamy go na true, aby tekst był widoczny jako Premium.
        if ($accessMode === 'paid' && !isset($data['is_premium'])) {
            $isPremium = true;
        }

        $currency = strtoupper(trim((string)($data['currency'] ?? 'PLN')));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            $currency = 'PLN';
        }

        if ($accessMode === 'paid' && $priceMinor <= 0) {
            throw new \InvalidArgumentException('Tekst płatny musi mieć cenę większą od zera.');
        }

        if ($accessMode === 'free') {
            $priceMinor = 0;
            $isPremium = false;
            $isUnique = false;
        }

        $splitPolicy = (new SafetyFundService($this->db))->currentPolicy();
        $authorShare = (int)$splitPolicy['author_basis_points'] / 100;
        $platformShare = (int)$splitPolicy['platform_basis_points'] / 100;
        $safetyFundShare = (int)$splitPolicy['safety_fund_basis_points'] / 100;
        $pricingStatus = $accessMode === 'paid' ? 'priced' : 'free';
        $note = trim((string)($data['editor_valuation_note'] ?? ''));
        $articleLabel = trim((string)($data['article_label'] ?? ''));

        $this->db->query('UPDATE articles SET access_mode=:access, price_minor=:price, currency=:currency, is_premium=:premium, is_unique=:unique_flag, article_label=:label, pricing_status=:pricing, author_share_percent=:author_share, platform_share_percent=:platform_share, editor_valuation_note=:note, valued_by_admin_id=:admin, valued_at=NOW(), updated_at=NOW() WHERE id=:id', [
            'access' => $accessMode,
            'price' => $priceMinor,
            'currency' => $currency,
            'premium' => $isPremium ? 1 : 0,
            'unique_flag' => $isUnique ? 1 : 0,
            'label' => $articleLabel !== '' ? $articleLabel : null,
            'pricing' => $pricingStatus,
            'author_share' => number_format($authorShare, 2, '.', ''),
            'platform_share' => number_format($platformShare, 2, '.', ''),
            'note' => $note !== '' ? $note : null,
            'admin' => $adminId,
            'id' => $articleId,
        ]);

        // Invalidacja cache etykiet jeśli zmieniono etykietę (uproszczone: zawsze przy wycenie)
        if (isset($GLOBALS['app']) && $GLOBALS['app'] instanceof \App\Core\App) {
            $GLOBALS['app']->cache->flushGroup('article_labels');
        }

        $this->db->query('INSERT INTO article_events(article_id,user_id,event,payload_json,created_at) VALUES(:article,:user,\'valued\',:payload,NOW())', [
            'article' => $articleId,
            'user' => $adminId,
            'payload' => json_encode([
                'access_mode' => $accessMode,
                'price_minor' => $priceMinor,
                'currency' => $currency,
                'is_premium' => $isPremium,
                'is_unique' => $isUnique,
                'article_label' => $articleLabel !== '' ? $articleLabel : null,
                'author_share_percent' => $authorShare,
                'platform_share_percent' => $platformShare,
                'safety_fund_share_percent' => $safetyFundShare,
                'split_policy_id' => (int)$splitPolicy['id'],
                'split_policy_version' => (int)$splitPolicy['version'],
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function purchaseWithWallet(int $buyerId, int $articleId): int
    {
        return $this->db->transaction(function(Database $db) use ($buyerId, $articleId): int {
            $articleService = new ArticleService($db);
            $article = $articleService->findPublished($articleId);
            if (!$article) {
                throw new \RuntimeException('Nie znaleziono opublikowanego tekstu.');
            }
            if (!empty($article['response_to_article_id'])) {
                throw new \RuntimeException('Opinia lub polemika nie jest sprzedawana.');
            }
            if ((int)$article['author_id'] === $buyerId) {
                throw new \RuntimeException('Autor ma dostęp do własnego tekstu bez zakupu.');
            }
            if (($article['access_mode'] ?? 'free') !== 'paid') {
                throw new \RuntimeException('Ten tekst nie jest płatny.');
            }
            if ((string)($article['pricing_status'] ?? 'not_priced') !== 'priced') {
                throw new \RuntimeException('Ten tekst nie ma jeszcze redakcyjnej wyceny.');
            }
            if ((int)($article['price_minor'] ?? 0) <= 0) {
                throw new \RuntimeException('Tekst płatny nie ma poprawnej ceny.');
            }
            if ($articleService->hasAccess($buyerId, $articleId)) {
                throw new \RuntimeException('Masz już aktywny dostęp do tego tekstu.');
            }
            $existing = $db->one('SELECT id FROM article_purchases WHERE buyer_user_id=:buyer AND article_id=:article AND status=\'paid\' LIMIT 1', [
                'buyer' => $buyerId,
                'article' => $articleId,
            ]);
            if ($existing) {
                throw new \RuntimeException('Ten tekst został już kupiony na tym koncie.');
            }

            $settings = $this->settings(['premium_access_hours']);
            $accessHours = max(1, (int)($settings['premium_access_hours'] ?? 12));
            $priceMinor = (int)$article['price_minor'];
            $authorId = (int)$article['author_id'];
            $safetyFund = new SafetyFundService($db);
            $splitPolicy = $safetyFund->currentPolicy();
            $split = $safetyFund->splitAmount($priceMinor, $splitPolicy);
            $authorBasisPoints = (int)$splitPolicy['author_basis_points'];
            $platformBasisPoints = (int)$splitPolicy['platform_basis_points'];
            $safetyFundBasisPoints = (int)$splitPolicy['safety_fund_basis_points'];
            $authorShare = $authorBasisPoints / 100;
            $platformShare = $platformBasisPoints / 100;
            $safetyFundShare = $safetyFundBasisPoints / 100;
            $authorAmount = $split['author_amount_minor'];
            $platformAmount = $split['platform_amount_minor'];
            $safetyFundAmount = $split['safety_fund_amount_minor'];
            $currency = (string)($article['currency'] ?? 'PLN');
            $accessUntil = date('Y-m-d H:i:s', time() + ($accessHours * 3600));

            $ledger = new LedgerService($db, new \App\Services\FinancialService($db));
            $platformId = $this->platformUserId();
            $safetyFundId = $safetyFund->fundUserId();
            $ledger->lockWalletsForUsers([$buyerId, $authorId, $platformId, $safetyFundId]);

            $idempotencyPrefix = 'article-purchase:' . $buyerId . ':' . $articleId;

            $ledger->post($buyerId, 'article_charge', -$priceMinor, 0, 'Zakup tekstu: ' . $article['title'], [
                'source_module' => 'article',
                'account_type' => 'slowo',
                'ref_type' => 'article',
                'ref_id' => $articleId,
                'counterparty_user_id' => $authorId,
                'idempotency_key' => $idempotencyPrefix . ':buyer',
                'meta' => [
                    'currency' => $currency,
                    'split_policy_id' => (int)$splitPolicy['id'],
                    'split_policy_version' => (int)$splitPolicy['version'],
                ],
            ]);

            $ledger->post($authorId, 'article_sale_author_share', $authorAmount, 0, '', [
                'source_module' => 'article',
                'account_type' => 'slowo',
                'ref_type' => 'article',
                'ref_id' => $articleId,
                'counterparty_user_id' => $buyerId,
                'idempotency_key' => $idempotencyPrefix . ':author',
                'meta' => [
                    'buyer_user_id' => $buyerId,
                    'currency' => $currency,
                    'share_basis_points' => $authorBasisPoints,
                    'split_policy_id' => (int)$splitPolicy['id'],
                    'split_policy_version' => (int)$splitPolicy['version'],
                ],
            ]);

            $ledger->post($platformId, 'article_sale_platform_share', $platformAmount, 0, '', [
                'source_module' => 'platform',
                'account_type' => 'main',
                'ref_type' => 'article',
                'ref_id' => $articleId,
                'counterparty_user_id' => $authorId,
                'idempotency_key' => $idempotencyPrefix . ':platform',
                'meta' => [
                    'buyer_user_id' => $buyerId,
                    'currency' => $currency,
                    'share_basis_points' => $platformBasisPoints,
                    'split_policy_id' => (int)$splitPolicy['id'],
                    'split_policy_version' => (int)$splitPolicy['version'],
                ],
            ]);

            $safetyFundLedgerTransactionId = $ledger->post($safetyFundId, 'article_sale_safety_fund_share', $safetyFundAmount, 0, '', [
                'source_module' => 'safety_fund',
                'account_type' => 'main',
                'ref_type' => 'article',
                'ref_id' => $articleId,
                'counterparty_user_id' => $authorId,
                'idempotency_key' => $idempotencyPrefix . ':safety-fund',
                'meta' => [
                    'buyer_user_id' => $buyerId,
                    'currency' => $currency,
                    'share_basis_points' => $safetyFundBasisPoints,
                    'split_policy_id' => (int)$splitPolicy['id'],
                    'split_policy_version' => (int)$splitPolicy['version'],
                ],
            ]);

            $paymentService = new PaymentService($db);
            $paymentId = $paymentService->createPayment($buyerId, 'wallet', 'article_payment', 'paid', $priceMinor, [
                'currency' => $currency,
                'note' => 'Zakup tekstu z portfela: ' . $article['title'],
                'completed_at' => date('Y-m-d H:i:s'),
            ]);
            $paymentService->addItem($paymentId, 'article', $articleId, 'Dostęp do: ' . $article['title'], $priceMinor, [
                'raw' => [
                    'author_amount_minor' => $authorAmount,
                    'platform_amount_minor' => $platformAmount,
                    'safety_fund_amount_minor' => $safetyFundAmount,
                    'author_share_percent' => $authorShare,
                    'platform_share_percent' => $platformShare,
                    'safety_fund_share_percent' => $safetyFundShare,
                    'author_share_basis_points' => $authorBasisPoints,
                    'platform_share_basis_points' => $platformBasisPoints,
                    'safety_fund_share_basis_points' => $safetyFundBasisPoints,
                    'split_policy_id' => (int)$splitPolicy['id'],
                    'split_policy_version' => (int)$splitPolicy['version'],
                    'access_hours' => $accessHours,
                ],
            ]);

            $purchaseId = $db->insert('INSERT INTO article_purchases(article_id,buyer_user_id,author_user_id,payment_id,total_amount_minor,author_amount_minor,platform_amount_minor,safety_fund_amount_minor,author_share_percent,platform_share_percent,author_share_basis_points,platform_share_basis_points,safety_fund_share_basis_points,split_policy_id,currency,status,access_granted_until,created_at) VALUES(:article,:buyer,:author,:payment,:total,:author_amount,:platform_amount,:fund_amount,:author_share,:platform_share,:author_bps,:platform_bps,:fund_bps,:policy,:currency,\'paid\',:until,NOW())', [
                'article' => $articleId,
                'buyer' => $buyerId,
                'author' => $authorId,
                'payment' => $paymentId,
                'total' => $priceMinor,
                'author_amount' => $authorAmount,
                'platform_amount' => $platformAmount,
                'fund_amount' => $safetyFundAmount,
                'author_share' => number_format($authorShare, 2, '.', ''),
                'platform_share' => number_format($platformShare, 2, '.', ''),
                'author_bps' => $authorBasisPoints,
                'platform_bps' => $platformBasisPoints,
                'fund_bps' => $safetyFundBasisPoints,
                'policy' => (int)$splitPolicy['id'],
                'currency' => $currency,
                'until' => $accessUntil,
            ]);

            $db->insert('INSERT INTO platform_revenues(payment_id, article_id, buyer_user_id, author_user_id, total_amount_minor, author_income_minor, publisher_fee_minor, safety_fund_amount_minor, publisher_fee_percent, author_share_basis_points, platform_share_basis_points, safety_fund_share_basis_points, split_policy_id, currency, created_at) VALUES(:payment,:article,:buyer,:author,:total,:author_amount,:platform_amount,:fund_amount,:platform_share,:author_bps,:platform_bps,:fund_bps,:policy,:currency,NOW())', [
                'payment' => $paymentId,
                'article' => $articleId,
                'buyer' => $buyerId,
                'author' => $authorId,
                'total' => $priceMinor,
                'author_amount' => $authorAmount,
                'platform_amount' => $platformAmount,
                'fund_amount' => $safetyFundAmount,
                'platform_share' => number_format($platformShare, 2, '.', ''),
                'author_bps' => $authorBasisPoints,
                'platform_bps' => $platformBasisPoints,
                'fund_bps' => $safetyFundBasisPoints,
                'policy' => (int)$splitPolicy['id'],
                'currency' => $currency,
            ]);

            $safetyFund->recordAllocation(
                $purchaseId,
                $paymentId,
                $articleId,
                $splitPolicy,
                $priceMinor,
                $safetyFundAmount,
                $currency,
                $safetyFundLedgerTransactionId,
            );

            $articleService->grantAccess($buyerId, $articleId, $paymentId, 'wallet', $accessHours);

            ($this->notificationOutbox ?? $this->fallbackOutbox($db))->articleSale(
                $authorId,
                $buyerId,
                $articleId,
                $purchaseId,
                $authorAmount,
            );

            return $purchaseId;
        });
    }

    private function fallbackOutbox(Database $db): NotificationOutboxDispatcher
    {
        return new NotificationOutboxDispatcher(
            $db,
            new DurableJobQueue($db),
            new \App\Infrastructure\Valkey\NullQueueSignal(),
            \App\Core\SlowoSnajperConfig::fromRoot(dirname(__DIR__, 2)),
        );
    }

    private function platformUserId(): int
    {
        $platform = $this->db->one('SELECT id FROM users WHERE email=\'platform@zrodlo-slowa.local\' LIMIT 1');
        if ($platform) {
            return (int)$platform['id'];
        }
        $sql = 'INSERT INTO users(email, phone, password_hash, display_name, status, can_write, talent_enabled, wallet_enabled, payout_enabled, permissions_updated_at, created_at) VALUES(\'platform@zrodlo-slowa.local\',NULL,:hash,\'Platforma ŹRÓDŁO SŁOWA\',\'active\',0,0,1,0,NOW(),NOW())';
        $sql .= $this->db->isPostgres()
            ? ' ON CONFLICT (email) DO NOTHING'
            : ' ON DUPLICATE KEY UPDATE email=VALUES(email)';
        $this->db->query($sql, [
            'hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
        ]);
        $created = $this->db->one('SELECT id FROM users WHERE email=\'platform@zrodlo-slowa.local\' LIMIT 1');
        if ($created === null) {
            throw new \RuntimeException('Nie udało się odczytać konta serwisu.');
        }
        return (int)$created['id'];
    }

    private function settings(array $names): array
    {
        if ($names === []) {
            return [];
        }
        $quoted = implode(',', array_fill(0, count($names), '?'));
        $rows = $this->db->all('SELECT name, value FROM settings WHERE name IN (' . $quoted . ')', $names);
        $settings = [];
        foreach ($rows as $row) {
            $settings[(string)$row['name']] = (string)$row['value'];
        }
        return $settings;
    }

    private function parsePriceMinor(string $price): int
    {
        $normalized = str_replace([' ', ','], ['', '.'], trim($price));
        if ($normalized === '') {
            return 0;
        }
        if (preg_match('/^\d+(?:\.\d{1,2})?$/D', $normalized) !== 1) {
            throw new \InvalidArgumentException('Cena musi być liczbą.');
        }
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
        if (strlen($whole) > 12) {
            throw new \InvalidArgumentException('Cena jest zbyt wysoka.');
        }
        return ((int)$whole * 100) + (int)str_pad($fraction, 2, '0');
    }
}

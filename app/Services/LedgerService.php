<?php
namespace App\Services;

use App\Core\Database;
final class LedgerService
{
    public const POINTS_PER_PLN = 10;

    public function __construct(
        private readonly Database $db,
        private readonly FinancialService $finance
    ) {}

    public static function typeMap(): array
    {
        return [
            'login_bonus' => 'Bonus za logowanie',
            'registration_bonus' => 'Bonus za rejestrację',
            'app_referral_bonus' => 'Bonus za polecenie aplikacji',
            'day_visit_bonus' => 'Bonus za dzisiejszą wizytę',
            'article_read_bonus' => 'Bonus za przeczytanie artykułu',
            'manual_reward' => 'Nagroda ręczna',
            'article_payment' => 'Zakup tekstu',
            'payout' => 'Wypłata',
            'wallet_topup' => 'Doładowanie portfela',
            'payout_request' => 'Wniosek o wypłatę',
            'payout_approved' => 'Wypłata zatwierdzona',
            'payout_paid' => 'Wypłata zrealizowana',
            'payout_rejected' => 'Wypłata odrzucona',
            'adjustment' => 'Korekta salda',
            'transfer_in' => 'Transfer przychodzący',
            'transfer_out' => 'Transfer wychodzący',
            'platform_fee' => 'Prowizja systemu',
            'survey_reward' => 'Nagroda za ankietę',
            'poll_answer_bonus' => 'Bonus za udział w ankiecie',
            'link_click_bonus' => 'Bonus za kliknięcie',
            'like_bonus' => 'Bonus za polubienie',
            'share_bonus' => 'Bonus za udostępnienie',
            'response_publication_bonus' => 'Talent za opublikowaną opinię lub polemikę',
            'response_submission_deposit_hold' => 'Kaucja za wysłanie opinii lub polemiki',
            'response_submission_deposit_refund' => 'Zwrot kaucji po publikacji',
            'response_submission_deposit_forfeit' => 'Przepadek kaucji po odrzuceniu',
            'response_submission_deposit_forfeit_reversal' => 'Cofnięcie przepadku kaucji',
            'bug_report_bonus' => 'Bonus za zgłoszenie błędu',
            'sponsored_article_read_bonus' => 'Bonus za czytanie (sponsorowane)',
            'ad_watch_bonus' => 'Bonus za obejrzenie reklamy',
            'ad_view_reward' => 'Bonus za obejrzenie reklamy',
            'ad_read_bonus' => 'Bonus za przeczytanie treści reklamowej',
            'ad_click_reward' => 'Bonus za kliknięcie reklamy',
            'newsletter_open_reward' => 'Nagroda za newsletter',
            'ppv_reward' => 'Nagroda PPV',
            'live_event_reward' => 'Nagroda za live',
            'article_charge' => 'Zakup dostępu do tekstu',
            'article_sale_author_share' => 'Udział autora ze sprzedaży tekstu',
            'article_sale_platform_share' => 'Udział serwisu ze sprzedaży tekstu',
            'article_sale_safety_fund_share' => 'Udział Safety Fund ze sprzedaży tekstu',
            'safety_fund_disbursement' => 'Wydatek Safety Fund',
        ];
    }

    public function synchronized(callable $operation): mixed
    {
        return $this->finance->synchronized($operation);
    }

    /** @param list<int> $userIds */
    public function lockWalletsForUsers(array $userIds): array
    {
        return $this->finance->lockWalletsForUsers($userIds);
    }

    public function walletForUser(int $userId, bool $forUpdate = false): array
    {
        $lock = $forUpdate ? ' FOR UPDATE' : '';
        $wallet = $this->db->one('SELECT * FROM wallets WHERE user_id=:id' . $lock, ['id' => $userId]);
        if (!$wallet) {
            $sql = 'INSERT INTO wallets(user_id,main_available_minor,main_reserved_minor,slowo_available_minor,slowo_reserved_minor,available_minor,pending_minor,reserved_minor,points_balance,currency,created_at) VALUES(:id,0,0,0,0,0,0,0,0,\'PLN\',NOW())';
            $sql .= $this->db->isPostgres()
                ? ' ON CONFLICT (user_id) DO NOTHING'
                : ' ON DUPLICATE KEY UPDATE user_id=VALUES(user_id)';
            $this->db->query($sql, ['id' => $userId]);
            $wallet = $this->db->one('SELECT * FROM wallets WHERE user_id=:id' . $lock, ['id' => $userId]);
        }
        if (!$wallet) {
            throw new \RuntimeException('Nie udało się utworzyć portfela użytkownika.');
        }
        return $wallet;
    }

    public function transactions(int $userId, int $limit = 80): array
    {
        return $this->db->all('SELECT * FROM wallet_transactions WHERE user_id=:id ORDER BY created_at DESC, id DESC LIMIT ' . (int)$limit, ['id' => $userId]);
    }

    public function pointsToMinor(int $points): int
    {
        $rate = (new \App\Services\PaymentRuntimeConfigService($this->db))->getTtPerPln();
        return (int)round($points * (100 / (max(1, $rate))));
    }

    public function post(int $userId, string $type, int $amountMinor, int $points, string $description, array $ctx = []): int
    {
        $accountType = $ctx['account_type'] ?? 'slowo';
        
        // Jeżeli to są punkty, delegujemy do FinancialService z odpowiednim typem konta
        if ($accountType === 'points') {
            return $this->finance->postTransaction($userId, $type, $points, 'points', $description, $ctx);
        }

        // Dla walut (main/slowo) delegujemy do FinancialService
        return $this->finance->postTransaction($userId, $type, $amountMinor, $accountType, $description, $ctx);
    }

    public function adjustmentTo(int $userId, int $targetPoints, string $description, array $ctx = []): ?int
    {
        return $this->db->transaction(function(Database $db) use ($userId, $targetPoints, $description, $ctx) {
            $wallet = $this->walletForUser($userId);
            $currentPoints = (int)$wallet['points_balance'];
            $deltaPoints = $targetPoints - $currentPoints;
            if ($deltaPoints === 0) return null;
            return $this->post($userId, 'adjustment', $this->pointsToMinor($deltaPoints), $deltaPoints, $description, $ctx + [
                'source_module' => $ctx['source_module'] ?? 'legacy_cm',
                'account_type' => 'points',
                'allow_negative' => true,
                'meta' => ($ctx['meta'] ?? []) + ['target_points' => $targetPoints, 'before_points' => $currentPoints],
            ]);
        });
    }

    public function approvePayout(int $userId, int $amountMinor, string $description, array $ctx = []): int
    {
        $accountType = $ctx['account_type'] ?? 'slowo';
        // Ta metoda w nowym modelu tylko odnotowuje fakt zatwierdzenia w ledgerze (bez zmiany salda, bo to robi reserve)
        return $this->finance->postTransaction($userId, 'payout_approved', 0, $accountType, $description, array_replace($ctx, [
            'balance_type' => 'reserved',
            'status' => 'reserved',
            'source_module' => 'payout'
        ]));
    }

    public function reserveForPayout(int $userId, int $amountMinor, string $description, array $ctx = []): int
    {
        $accountType = $ctx['account_type'] ?? 'slowo';
        return $this->db->transaction(function(Database $db) use ($userId, $amountMinor, $description, $ctx, $accountType) {
            // 1. Zdejmujemy z dostępnych
            $this->finance->postTransaction($userId, 'payout_request', -$amountMinor, $accountType, $description, array_replace($this->legContext($ctx, 'available-debit'), [
                'balance_type' => 'available',
                'status' => 'reserved',
                'source_module' => 'payout'
            ]));

            // 2. Dodajemy do zarezerwowanych
            return $this->finance->postTransaction($userId, 'payout_request', $amountMinor, $accountType, $description, array_replace($this->legContext($ctx, 'reserved-credit'), [
                'balance_type' => 'reserved',
                'status' => 'reserved',
                'source_module' => 'payout'
            ]));
        });
    }

    public function markReservedPaid(int $userId, int $amountMinor, string $description, array $ctx = []): int
    {
        $accountType = $ctx['account_type'] ?? 'slowo';
        return $this->finance->postTransaction($userId, 'payout_paid', -$amountMinor, $accountType, $description, array_replace($ctx, [
            'balance_type' => 'reserved',
            'status' => 'posted',
            'source_module' => 'payout'
        ]));
    }

    public function releaseReserved(int $userId, int $amountMinor, string $description, array $ctx = []): int
    {
        $accountType = $ctx['account_type'] ?? 'slowo';
        return $this->db->transaction(function(Database $db) use ($userId, $amountMinor, $description, $ctx, $accountType) {
            // 1. Zdejmujemy z zarezerwowanych
            $this->finance->postTransaction($userId, 'payout_rejected', -$amountMinor, $accountType, $description, array_replace($this->legContext($ctx, 'reserved-debit'), [
                'balance_type' => 'reserved',
                'status' => 'cancelled',
                'source_module' => 'payout'
            ]));

            // 2. Oddajemy do dostępnych
            return $this->finance->postTransaction($userId, 'payout_rejected', $amountMinor, $accountType, $description, array_replace($this->legContext($ctx, 'available-credit'), [
                'balance_type' => 'available',
                'status' => 'cancelled',
                'source_module' => 'payout'
            ]));
        });
    }

    private function legContext(array $context, string $leg): array
    {
        $key = trim((string)($context['idempotency_key'] ?? ''));
        if ($key !== '') {
            $context['idempotency_key'] = $key . ':' . $leg;
        }
        $context['meta'] = ['ledger_leg' => $leg] + (array)($context['meta'] ?? []);
        return $context;
    }
}

<?php
namespace App\Services;

use App\Core\Database;

final class EconomyMapService
{
    public function __construct(private readonly Database $db) {}

    public function publicFlows(): array
    {
        return [
            [
                'label' => 'Tekst premium / unikalny',
                'payer' => 'Czytelnik',
                'action' => 'Kupuje dostęp do tekstu',
                'receiver' => '70% Autor / 30% Serwis',
                'wallet' => [
                    'article_charge' => 'Zakup tekstu',
                    'article_sale_author_share' => 'Udział autora',
                    'article_sale_platform_share' => 'Udział serwisu'
                ],
                'note' => 'Tekst ma cenę ustaloną przez redakcję. Dostęp trwa domyślnie 12 godzin.',
                'icon' => 'article'
            ],
            [
                'label' => 'Aktywność użytkownika',
                'payer' => 'System / Budżet aktywności',
                'action' => 'Nagradza za rejestrację, wizyty i interakcje',
                'receiver' => 'Użytkownik',
                'wallet' => [
                    'activity_bonus' => 'Bonus aktywności',
                    'day_visit_bonus' => 'Wizyta dzienna'
                ],
                'note' => 'Każdy bonus trafia do portfela i buduje lojalność czytelnika.',
                'icon' => 'sun'
            ],
            [
                'label' => 'Ankieta / sondaż',
                'payer' => 'Zleceniodawca / Redakcja',
                'action' => 'Płaci za opinie i dane',
                'receiver' => 'Użytkownik + Serwis',
                'wallet' => [
                    'survey_reward' => 'Nagroda za ankietę'
                ],
                'note' => 'Ankiety są strategicznym modułem przychodowym, nie dodatkiem.',
                'icon' => 'survey'
            ],
            [
                'label' => 'Reklama i kampanie',
                'payer' => 'Reklamodawca',
                'action' => 'Płaci za zasięg i kliknięcia',
                'receiver' => 'Użytkownik + Serwis',
                'wallet' => [
                    'ad_view_reward' => 'Nagroda za obejrzenie',
                    'ad_click_reward' => 'Nagroda za kliknięcie',
                    'sponsored_article_read_bonus' => 'Treść sponsorowana'
                ],
                'note' => 'Każda akcja kampanii jest zapisana jako zdarzenie ekonomiczne.',
                'icon' => 'eye'
            ],
            [
                'label' => 'PPV / Wydarzenia live',
                'payer' => 'Uczestnik',
                'action' => 'Opłaca udział w wydarzeniu',
                'receiver' => 'Autor / Serwis',
                'wallet' => [
                    'ppv_reward' => 'Nagroda PPV',
                    'live_event_reward' => 'Nagroda za live'
                ],
                'note' => 'Model płatności za konkretne wydarzenia wysokiej jakości.',
                'icon' => 'video'
            ],
            [
                'label' => 'Wypłata środków',
                'payer' => 'Portfel systemu',
                'action' => 'Realizuje wypłatę na konto',
                'receiver' => 'Autor / Użytkownik',
                'wallet' => [
                    'payout_request' => 'Wniosek',
                    'payout_paid' => 'Wypłacono'
                ],
                'note' => 'Wypłata po osiągnięciu progu i weryfikacji redakcyjnej.',
                'icon' => 'payout'
            ],
        ];
    }

    public function adminSummary(): array
    {
        return [
            'article_sales' => $this->one('SELECT COUNT(*) cnt, COALESCE(SUM(total_amount_minor),0) total, COALESCE(SUM(author_income_minor),0) author, COALESCE(SUM(publisher_fee_minor),0) platform FROM platform_revenues'),
            'bonuses' => $this->one('SELECT COUNT(*) cnt, COALESCE(SUM(amount_minor),0) total, COALESCE(SUM(points_amount),0) points FROM activity_reward_logs'),
            'surveys' => $this->one('SELECT COUNT(*) cnt, COALESCE(SUM(reward_amount_minor),0) rewards FROM survey_responses'),
            'campaigns' => $this->one('SELECT COUNT(*) cnt, COALESCE(SUM(cost_minor),0) cost, COALESCE(SUM(reward_minor),0) rewards FROM campaign_events'),
            'payouts' => $this->one('SELECT COUNT(*) cnt, COALESCE(SUM(amount_minor),0) total FROM payouts'),
            'transactions' => $this->one('SELECT COUNT(*) cnt, COALESCE(SUM(ABS(amount_minor)),0) total FROM wallet_transactions'),
        ];
    }

    private function one(string $sql): array
    {
        try {
            return $this->db->one($sql) ?? [];
        } catch (\Throwable) {
            return [];
        }
    }
}

<?php
namespace App\Services;

use App\Core\Database;

final class EconomyMapService
{
    public function __construct(private readonly Database $db) {}

    public function publicFlows(string $language = 'pl'): array
    {
        $policy = (new SafetyFundService($this->db))->currentPolicy();
        $articleReceiver = $this->replace('economy.flow.article.receiver', $language, [
            'author' => $this->percent((int)$policy['author_basis_points']),
            'platform' => $this->percent((int)$policy['platform_basis_points']),
            'fund' => $this->percent((int)$policy['safety_fund_basis_points']),
        ]);
        $articleSplit = [
            [
                'label' => $this->text('economy.policy.author', $language),
                'basis_points' => (int)$policy['author_basis_points'],
                'percentage' => $this->percent((int)$policy['author_basis_points']),
            ],
            [
                'label' => $this->text('economy.policy.platform', $language),
                'basis_points' => (int)$policy['platform_basis_points'],
                'percentage' => $this->percent((int)$policy['platform_basis_points']),
            ],
            [
                'label' => $this->text('economy.policy.fund', $language),
                'basis_points' => (int)$policy['safety_fund_basis_points'],
                'percentage' => $this->percent((int)$policy['safety_fund_basis_points']),
            ],
        ];
        return [
            [
                'label' => $this->text('economy.flow.article.label', $language),
                'payer' => $this->text('economy.actor.reader', $language),
                'action' => $this->text('economy.flow.article.action', $language),
                'receiver' => $articleReceiver,
                'wallet' => [
                    'article_charge' => ActivityUiHelper::getLabel('article_charge', $language),
                    'article_sale_author_share' => ActivityUiHelper::getLabel('article_sale_author_share', $language),
                    'article_sale_platform_share' => ActivityUiHelper::getLabel('article_sale_platform_share', $language),
                    'article_sale_safety_fund_share' => ActivityUiHelper::getLabel('article_sale_safety_fund_share', $language),
                ],
                'note' => $this->text('economy.flow.article.note', $language),
                'split' => $articleSplit,
                'icon' => 'article'
            ],
            [
                'label' => $this->text('economy.flow.response.label', $language),
                'payer' => $this->text('economy.actor.response_funders', $language),
                'action' => $this->text('economy.flow.response.action', $language),
                'receiver' => $this->text('economy.actor.response_receiver', $language),
                'wallet' => [
                    'response_submission_deposit_hold' => ActivityUiHelper::getLabel('response_submission_deposit_hold', $language),
                    'response_submission_deposit_refund' => ActivityUiHelper::getLabel('response_submission_deposit_refund', $language),
                    'response_submission_deposit_forfeit' => ActivityUiHelper::getLabel('response_submission_deposit_forfeit', $language),
                    'response_publication_bonus' => ActivityUiHelper::getLabel('response_publication_bonus', $language),
                ],
                'note' => $this->text('economy.flow.response.note', $language),
                'icon' => 'article'
            ],
            [
                'label' => $this->text('economy.flow.referral.label', $language),
                'payer' => $this->text('economy.actor.talent_promotion_budget', $language),
                'action' => $this->text('economy.flow.referral.action', $language),
                'receiver' => $this->text('economy.actor.referral_parties', $language),
                'wallet' => [
                    'app_referral_bonus' => ActivityUiHelper::getLabel('app_referral_bonus', $language),
                ],
                'note' => $this->text('economy.flow.referral.note', $language),
                'icon' => 'share'
            ],
            [
                'label' => $this->text('economy.flow.bug.label', $language),
                'payer' => $this->text('economy.actor.talent_budget', $language),
                'action' => $this->text('economy.flow.bug.action', $language),
                'receiver' => $this->text('economy.actor.reporter', $language),
                'wallet' => [
                    'bug_report_bonus' => ActivityUiHelper::getLabel('bug_report_bonus', $language),
                ],
                'note' => $this->text('economy.flow.bug.note', $language),
                'icon' => 'bug'
            ],
            [
                'label' => $this->text('economy.flow.campaign.label', $language),
                'payer' => $this->text('economy.actor.advertiser', $language),
                'action' => $this->text('economy.flow.campaign.action', $language),
                'receiver' => $this->text('economy.actor.user_platform', $language),
                'wallet' => [
                    'ad_view_reward' => ActivityUiHelper::getLabel('ad_view_reward', $language),
                    'ad_click_reward' => ActivityUiHelper::getLabel('ad_click_reward', $language),
                    'sponsored_article_read_bonus' => ActivityUiHelper::getLabel('sponsored_article_read_bonus', $language),
                ],
                'note' => $this->text('economy.flow.campaign.note', $language),
                'icon' => 'eye'
            ],
            [
                'label' => $this->text('economy.flow.payout.label', $language),
                'payer' => $this->text('economy.actor.system_wallet', $language),
                'action' => $this->text('economy.flow.payout.action', $language),
                'receiver' => $this->text('economy.actor.author_user', $language),
                'wallet' => [
                    'payout_request' => ActivityUiHelper::getLabel('payout_request', $language),
                    'payout_paid' => ActivityUiHelper::getLabel('payout_paid', $language),
                ],
                'note' => $this->text('economy.flow.payout.note', $language),
                'icon' => 'payout'
            ],
        ];
    }

    public function adminSummary(): array
    {
        return [
            'article_sales' => $this->one('SELECT COUNT(*) cnt, COALESCE(SUM(total_amount_minor),0) total, COALESCE(SUM(author_income_minor),0) author, COALESCE(SUM(publisher_fee_minor),0) platform, COALESCE(SUM(safety_fund_amount_minor),0) safety_fund FROM platform_revenues'),
            'bonuses' => $this->one('SELECT COUNT(*) cnt, COALESCE(SUM(amount_minor),0) total, COALESCE(SUM(points_amount),0) points FROM activity_reward_logs'),
            'surveys' => $this->one('SELECT COUNT(*) cnt, COALESCE(SUM(reward_amount_minor),0) rewards FROM survey_responses'),
            'campaigns' => $this->one("SELECT COUNT(*) FILTER (WHERE verification_status='verified') cnt,
                COALESCE(SUM(cost_minor) FILTER (WHERE verification_status='verified'),0) cost,
                COALESCE(SUM(reward_points),0) reward_points FROM campaign_events"),
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

    private function text(string $key, string $language): string
    {
        return function_exists('t') ? t($key, $language) : $key;
    }

    /** @param array<string,string> $values */
    private function replace(string $key, string $language, array $values): string
    {
        $text = $this->text($key, $language);
        foreach ($values as $name => $value) {
            $text = str_replace('{' . $name . '}', $value, $text);
        }
        return $text;
    }

    private function percent(int $basisPoints): string
    {
        return number_format($basisPoints / 100, $basisPoints % 100 === 0 ? 0 : 2, ',', ' ') . '%';
    }
}

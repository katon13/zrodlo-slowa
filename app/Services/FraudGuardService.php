<?php
namespace App\Services;

use App\Core\Database;
use App\Core\SlowoSnajperConfig;

final class FraudGuardService
{
    public const STATUS_NORMAL = 'normal';
    public const STATUS_OBSERVE = 'observe';
    public const STATUS_SUSPECT = 'suspect';
    public const STATUS_HOLD_PAYOUT = 'hold_payout';

    public function __construct(
        private readonly Database $db,
        private readonly SlowoSnajperConfig $config,
    ) {}

    public function enabled(): bool
    {
        return $this->config->antiFraudFlag('enabled', true);
    }

    public function inspectCampaignEvent(int $userId, int $campaignId, string $eventType, int $watchSeconds = 0): array
    {
        if (!$this->enabled()) {
            return $this->result(true, 0, self::STATUS_NORMAL, []);
        }

        $reasons = [];
        $score = 0;
        $today = date('Y-m-d');

        $sameEventToday = (int)$this->db->cell(
            'SELECT COUNT(*) FROM campaign_events WHERE user_id=:user AND campaign_id=:campaign AND event_type=:type AND event_date=:day',
            ['user' => $userId, 'campaign' => $campaignId, 'type' => $eventType, 'day' => $today]
        );
        if ($sameEventToday >= $this->config->sensitivity('max_same_ad_reward_per_day', 1)) {
            $score = max($score, 85);
            $reasons[] = 'Ta kampania i akcja były już dziś nagrodzone dla tego użytkownika.';
        }

        if ($eventType === 'view' && $watchSeconds < $this->config->sensitivity('min_ad_watch_seconds', 15)) {
            $score = max($score, 80);
            $reasons[] = 'Za krótki czas oglądania reklamy.';
        }

        $fastActions = $this->recentRewardEvents($userId, 60);
        if ($fastActions >= $this->config->sensitivity('max_fast_actions_per_minute', 8)) {
            $score = max($score, 70);
            $reasons[] = 'Zbyt dużo akcji zarobkowych w jednej minucie.';
        }

        $dailyRewards = $this->dailyRewardEvents($userId);
        if ($dailyRewards >= $this->config->sensitivity('max_user_daily_bonus_events', 40)) {
            $score = max($score, 90);
            $reasons[] = 'Przekroczony dzienny limit zdarzeń bonusowych SNAJPERA.';
        }

        $score = max($score, $this->newAccountRisk($userId, $reasons));
        $status = $this->statusForScore($score);
        $allowed = $score < $this->config->sensitivity('risk_score_hold_payout', 80) || !$this->config->antiFraudFlag('block_suspicious_rewards', true);

        $this->logEvent($userId, 'campaign_' . $eventType, 'campaign', $campaignId, $score, $status, $reasons);
        return $this->result($allowed, $score, $status, $reasons);
    }

    public function inspectSurveySubmit(int $userId, int $surveyId, int $answerSeconds = 0): array
    {
        if (!$this->enabled()) {
            return $this->result(true, 0, self::STATUS_NORMAL, []);
        }

        $reasons = [];
        $score = 0;

        $exists = (int)$this->db->cell('SELECT COUNT(*) FROM survey_responses WHERE user_id=:user AND survey_id=:survey', [
            'user' => $userId,
            'survey' => $surveyId,
        ]);
        if ($exists > 0) {
            $score = max($score, 85);
            $reasons[] = 'Użytkownik już odpowiedział na tę ankietę.';
        }

        if ($answerSeconds > 0 && $answerSeconds < $this->config->sensitivity('min_survey_answer_seconds', 8)) {
            $score = max($score, 70);
            $reasons[] = 'Ankieta wypełniona zbyt szybko.';
        }

        $fastActions = $this->recentRewardEvents($userId, 60);
        if ($fastActions >= $this->config->sensitivity('max_fast_actions_per_minute', 8)) {
            $score = max($score, 70);
            $reasons[] = 'Zbyt dużo akcji zarobkowych w jednej minucie.';
        }

        $dailyRewards = $this->dailyRewardEvents($userId);
        if ($dailyRewards >= $this->config->sensitivity('max_user_daily_bonus_events', 40)) {
            $score = max($score, 90);
            $reasons[] = 'Przekroczony dzienny limit zdarzeń bonusowych SNAJPERA.';
        }

        $score = max($score, $this->newAccountRisk($userId, $reasons));
        $status = $this->statusForScore($score);
        $allowed = $score < $this->config->sensitivity('risk_score_hold_payout', 80) || !$this->config->antiFraudFlag('block_suspicious_rewards', true);

        $this->logEvent($userId, 'survey_submit', 'survey', $surveyId, $score, $status, $reasons);
        return $this->result($allowed, $score, $status, $reasons);
    }

    /** @param array<string,mixed> $context */
    public function inspectEarning(int $userId, string $activityType, array $context = []): array
    {
        if (!$this->enabled()) {
            return $this->result(true, 0, self::STATUS_NORMAL, []);
        }

        $reasons = [];
        $score = $this->userRiskScore($userId);
        if ($this->recentRewardEvents($userId, 60) >= $this->config->sensitivity('max_fast_actions_per_minute', 8)) {
            $score = max($score, 70);
            $reasons[] = 'Zbyt dużo naliczeń w jednej minucie.';
        }
        if ($this->dailyRewardEvents($userId) >= $this->config->sensitivity('max_user_daily_bonus_events', 40)) {
            $score = max($score, 90);
            $reasons[] = 'Przekroczony dzienny limit zdarzeń bonusowych SNAJPERA.';
        }
        $score = max($score, $this->newAccountRisk($userId, $reasons));
        if ($this->config->earningsRequiresPresence($activityType) && ($context['presence_verified'] ?? false) !== true) {
            $score = max($score, 100);
            $reasons[] = 'Brak świeżego dowodu obecności użytkownika.';
        }

        $status = $this->statusForScore($score);
        $allowed = $score < $this->config->sensitivity('risk_score_hold_payout', 80)
            || !$this->config->antiFraudFlag('block_suspicious_rewards', true);
        $this->logEvent($userId, 'earning_' . $activityType, 'earning', null, $score, $status, $reasons);
        return $this->result($allowed, $score, $status, $reasons);
    }

    public function assertPayoutAllowed(int $userId, ?int $payoutId = null): void
    {
        if (!$this->enabled() || !$this->config->antiFraudFlag('hold_payouts_on_high_risk', true)) {
            return;
        }

        $score = $this->userRiskScore($userId);
        if ($score >= $this->config->sensitivity('risk_score_hold_payout', 80)) {
            $this->logEvent($userId, 'payout_hold', 'payout', $payoutId, $score, self::STATUS_HOLD_PAYOUT, [
                'Wypłata zatrzymana do kontroli przez SNAJPERA SŁOWA.',
            ]);
            throw new \RuntimeException('Wypłata wymaga kontroli antyfraudowej. SNAJPER SŁOWA oznaczył konto wysokim risk_score.');
        }
    }

    public function userRiskScore(int $userId): int
    {
        $row = $this->db->one('SELECT MAX(risk_score) AS risk_score FROM fraud_events WHERE user_id=:user AND created_at >= ' . $this->db->nowMinus(30, 'day'), [
            'user' => $userId,
        ]);
        return (int)($row['risk_score'] ?? 0);
    }

    public function dashboard(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        return [
            'summary' => $this->summary(),
            'events' => $this->recentEvents($limit),
            'users' => $this->topRiskUsers($limit),
        ];
    }

    public function recentEvents(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        return $this->db->all('
            SELECT fe.*, u.display_name, u.email
            FROM fraud_events fe
            LEFT JOIN users u ON u.id=fe.user_id
            ORDER BY fe.created_at DESC, fe.id DESC
            LIMIT ' . $limit);
    }

    public function topRiskUsers(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        return $this->db->all('
            SELECT
                u.id,
                u.display_name,
                u.email,
                u.status,
                MAX(fe.risk_score) AS risk_score,
                COUNT(fe.id) AS events_count,
                MAX(fe.created_at) AS last_event_at
            FROM fraud_events fe
            JOIN users u ON u.id=fe.user_id
            WHERE fe.created_at >= ' . $this->db->nowMinus(30, 'day') . '
            GROUP BY u.id
            ORDER BY risk_score DESC, events_count DESC, last_event_at DESC
            LIMIT ' . $limit);
    }

    public function scan(int $limit = 200): array
    {
        if (!$this->enabled() || !$this->config->antiFraudFlag('scan_enabled', true)) {
            return ['scanned' => 0, 'flagged' => 0, 'events' => []];
        }

        $limit = max(1, min(1000, $limit));
        $rows = $this->db->all('
            SELECT
                u.id,
                u.email,
                u.display_name,
                u.created_at,
                COUNT(DISTINCT arl.id) AS reward_count,
                COUNT(DISTINCT ce.id) AS campaign_count,
                COUNT(DISTINCT sr.id) AS survey_count,
                COUNT(DISTINCT p.id) AS payout_count
            FROM users u
            LEFT JOIN activity_reward_logs arl ON arl.user_id=u.id AND arl.awarded_at >= ' . $this->db->nowMinus(24, 'hour') . '
            LEFT JOIN campaign_events ce ON ce.user_id=u.id AND ce.created_at >= ' . $this->db->nowMinus(24, 'hour') . '
            LEFT JOIN survey_responses sr ON sr.user_id=u.id AND sr.created_at >= ' . $this->db->nowMinus(24, 'hour') . '
            LEFT JOIN payouts p ON p.user_id=u.id AND p.requested_at >= ' . $this->db->nowMinus(24, 'hour') . '
            WHERE u.status != \'deleted\'
            GROUP BY u.id
            ORDER BY reward_count DESC, campaign_count DESC, survey_count DESC, payout_count DESC
            LIMIT ' . $limit);

        $flagged = [];
        foreach ($rows as $row) {
            $reasons = [];
            $score = 0;
            $rewardCount = (int)$row['reward_count'];
            $campaignCount = (int)$row['campaign_count'];
            $surveyCount = (int)$row['survey_count'];
            $payoutCount = (int)$row['payout_count'];

            if ($rewardCount >= $this->config->sensitivity('max_user_daily_bonus_events', 40)) {
                $score = max($score, 85);
                $reasons[] = 'Duża liczba bonusów w 24h: ' . $rewardCount;
            }
            if ($campaignCount >= 20) {
                $score = max($score, 75);
                $reasons[] = 'Duża liczba zdarzeń reklamowych w 24h: ' . $campaignCount;
            }
            if ($surveyCount >= 10) {
                $score = max($score, 65);
                $reasons[] = 'Duża liczba ankiet w 24h: ' . $surveyCount;
            }
            if ($payoutCount > 0 && ($rewardCount + $campaignCount + $surveyCount) >= 10) {
                $score = max($score, 80);
                $reasons[] = 'Wypłata po serii akcji zarobkowych.';
            }

            if ($score >= $this->config->sensitivity('risk_score_warn', 60)) {
                $status = $this->statusForScore($score);
                $this->logEvent((int)$row['id'], 'fraud_scan', 'user', (int)$row['id'], $score, $status, $reasons);
                $flagged[] = [
                    'user_id' => (int)$row['id'],
                    'email' => (string)$row['email'],
                    'risk_score' => $score,
                    'status' => $status,
                    'reasons' => $reasons,
                ];
            }
        }

        return [
            'scanned' => count($rows),
            'flagged' => count($flagged),
            'events' => $flagged,
        ];
    }

    private function summary(): array
    {
        return [
            'events_24h' => (int)$this->db->cell('SELECT COUNT(*) FROM fraud_events WHERE created_at >= ' . $this->db->nowMinus(24, 'hour')),
            'suspect_24h' => (int)$this->db->cell('SELECT COUNT(*) FROM fraud_events WHERE status IN (\'suspect\',\'hold_payout\') AND created_at >= ' . $this->db->nowMinus(24, 'hour')),
            'held_payouts_30d' => (int)$this->db->cell('SELECT COUNT(*) FROM fraud_events WHERE event_type=\'payout_hold\' AND created_at >= ' . $this->db->nowMinus(30, 'day')),
            'max_risk_30d' => (int)$this->db->cell('SELECT COALESCE(MAX(risk_score),0) FROM fraud_events WHERE created_at >= ' . $this->db->nowMinus(30, 'day')),
        ];
    }

    private function recentRewardEvents(int $userId, int $seconds): int
    {
        return (int)$this->db->cell('
            SELECT COUNT(*) FROM activity_reward_logs
            WHERE user_id=:user AND awarded_at >= ' . $this->db->nowMinus(max(1, $seconds), 'second') . '
        ', ['user' => $userId]);
    }

    private function dailyRewardEvents(int $userId): int
    {
        return (int)$this->db->cell('SELECT COUNT(*) FROM activity_reward_logs WHERE user_id=:user AND awarded_at >= CURRENT_DATE', [
            'user' => $userId,
        ]);
    }

    private function newAccountRisk(int $userId, array &$reasons): int
    {
        $hours = $this->config->sensitivity('new_account_hours', 24);
        $warnCount = $this->config->sensitivity('new_account_bonus_warn_count', 10);
        $row = $this->db->one('
            SELECT u.created_at, COUNT(arl.id) AS rewards
            FROM users u
            LEFT JOIN activity_reward_logs arl ON arl.user_id=u.id AND arl.awarded_at >= u.created_at
            WHERE u.id=:user
            GROUP BY u.id
            LIMIT 1
        ', ['user' => $userId]);

        if (!$row || empty($row['created_at'])) {
            return 0;
        }

        $ageSeconds = time() - strtotime((string)$row['created_at']);
        if ($ageSeconds <= $hours * 3600 && (int)$row['rewards'] >= $warnCount) {
            $reasons[] = 'Świeże konto z dużą liczbą akcji zarobkowych.';
            return 70;
        }
        return 0;
    }

    private function statusForScore(int $score): string
    {
        if ($score >= $this->config->sensitivity('risk_score_hold_payout', 80)) {
            return self::STATUS_HOLD_PAYOUT;
        }
        if ($score >= 70) {
            return self::STATUS_SUSPECT;
        }
        if ($score >= $this->config->sensitivity('risk_score_warn', 60)) {
            return self::STATUS_OBSERVE;
        }
        return self::STATUS_NORMAL;
    }

    private function result(bool $allowed, int $score, string $status, array $reasons): array
    {
        return [
            'allowed' => $allowed,
            'risk_score' => $score,
            'status' => $status,
            'reasons' => $reasons,
        ];
    }

    private function logEvent(?int $userId, string $eventType, ?string $referenceType, ?int $referenceId, int $riskScore, string $status, array $reasons): void
    {
        if (!$this->enabled() || !$this->config->antiFraudFlag('log_events', true)) {
            return;
        }

        try {
            $this->db->query('
                INSERT INTO fraud_events(user_id,event_type,reference_type,reference_id,risk_score,status,reasons_json,ip_hash,user_agent_hash,created_at)
                VALUES(:user,:event,:ref_type,:ref_id,:score,:status,:reasons,:ip,:ua,NOW())
            ', [
                'user' => $userId,
                'event' => $eventType,
                'ref_type' => $referenceType,
                'ref_id' => $referenceId,
                'score' => max(0, min(100, $riskScore)),
                'status' => $status,
                'reasons' => json_encode(array_values($reasons), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'ip' => $this->hash((string)($_SERVER['REMOTE_ADDR'] ?? '')),
                'ua' => $this->hash((string)($_SERVER['HTTP_USER_AGENT'] ?? '')),
            ]);
        } catch (\Throwable) {
            // Antyfraud nie może wywalić publicznej strony, jeśli migracja jeszcze nie została wykonana.
        }
    }

    private function hash(string $value): ?string
    {
        $value = trim($value);
        return $value === '' ? null : hash('sha256', 'zrodlo-slowa-fraud:' . $value);
    }
}

<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Jeden serwis kampanii: konfiguracja zlecenia, weryfikacja zdarzenia,
 * naliczenie kosztu reklamodawcy i przekazanie TT do istniejącego Talentu.
 */
final class CampaignService
{
    private const READY_TYPES = ['ad_click', 'display_ad', 'ad_view'];
    /** @var array<string,array<string,mixed>>|null */
    private ?array $typeDefinitionCache = null;

    public function __construct(
        private readonly Database $db,
        private readonly TalentService $talent,
        private readonly ?FraudGuardService $fraudGuard = null,
    ) {}

    /** @return array<string,string> */
    public function types(): array
    {
        return [
            'ad_click' => 'Kliknięcie w reklamę',
            'display_ad' => 'Obejrzenie reklamy display',
            'ad_view' => 'Obejrzenie reklamy',
            'sponsored_article' => 'Przeczytanie artykułu sponsorowanego',
            'survey_ad' => 'Ukończenie ankiety reklamowej',
            'ppv' => 'Dostęp PPV',
            'live' => 'Udział w transmisji live',
        ];
    }

    /** @return array<string,string> */
    public function statuses(): array
    {
        return [
            'draft' => 'Szkic',
            'active' => 'Aktywna',
            'paused' => 'Wstrzymana',
            'closed' => 'Zakończona',
        ];
    }

    /** @return array<string,array<string,mixed>> */
    public function typeDefinitions(): array
    {
        if ($this->typeDefinitionCache !== null) {
            return $this->typeDefinitionCache;
        }
        $definitions = [
            'ad_click' => [
                'event_type' => 'click',
                'activity_type' => 'ad_click_reward',
                'cost_field' => 'cost_per_click_minor',
                'ready' => true,
                'proof' => 'Przekierowanie wykonuje serwer. Liczymy najwyżej jedno zweryfikowane kliknięcie użytkownika dziennie.',
                'proof_key' => 'campaign.proof.ad_click',
            ],
            'display_ad' => [
                'event_type' => 'view',
                'activity_type' => 'ad_view_reward',
                'cost_field' => 'cost_per_view_minor',
                'ready' => true,
                'proof' => 'Serwer wydaje jednorazowy dowód, mierzy czas i odrzuca ukrytą lub zbyt krótką sesję.',
                'proof_key' => 'campaign.proof.ad_view',
            ],
            'ad_view' => [
                'event_type' => 'view',
                'activity_type' => 'ad_view_reward',
                'cost_field' => 'cost_per_view_minor',
                'ready' => true,
                'proof' => 'Serwer wydaje jednorazowy dowód, mierzy czas i odrzuca ukrytą lub zbyt krótką sesję.',
                'proof_key' => 'campaign.proof.ad_view',
            ],
            'sponsored_article' => [
                'event_type' => 'sponsored_read',
                'activity_type' => 'sponsored_article_read_bonus',
                'cost_field' => 'cost_per_view_minor',
                'ready' => false,
                'proof' => 'Wymaga przypięcia konkretnego artykułu do istniejącego dowodu przeczytania.',
            ],
            'survey_ad' => [
                'event_type' => 'survey_completed',
                'activity_type' => 'survey_reward',
                'cost_field' => 'cost_per_completed_survey_minor',
                'ready' => false,
                'proof' => 'Wymaga przypięcia konkretnej ankiety i jej kompletnej, przyjętej odpowiedzi.',
            ],
            'ppv' => [
                'event_type' => 'ppv_purchase',
                'activity_type' => 'ppv_reward',
                'cost_field' => 'cost_per_view_minor',
                'ready' => false,
                'proof' => 'Wymaga rzeczywistego zakupu i aktywnego prawa dostępu PPV.',
            ],
            'live' => [
                'event_type' => 'live_join',
                'activity_type' => 'live_event_reward',
                'cost_field' => 'cost_per_view_minor',
                'ready' => false,
                'proof' => 'Wymaga rzeczywistej sesji transmisji i minimalnego czasu obecności.',
            ],
        ];

        foreach ($definitions as $type => &$definition) {
            $rule = $this->talentRule((string)$definition['activity_type']);
            $definition['label'] = $this->types()[$type];
            $definition['talent_active'] = (int)($rule['is_active'] ?? 0) === 1;
            $definition['talent_points'] = (int)($rule['points_amount'] ?? 0);
            $definition['activation_ready'] = $definition['ready']
                && $definition['talent_active']
                && $definition['talent_points'] > 0;
        }
        unset($definition);

        return $this->typeDefinitionCache = $definitions;
    }

    /** @return list<array<string,mixed>> */
    public function allForAdmin(int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $rows = $this->db->all(
            "SELECT c.*,
                    COUNT(e.id) FILTER (WHERE e.verification_status='verified') AS verified_events_count,
                    COUNT(e.id) FILTER (WHERE e.verification_status='rejected') AS rejected_events_count,
                    COALESCE(SUM(e.cost_minor) FILTER (WHERE e.verification_status='verified'),0) AS spent_minor,
                    COALESCE(SUM(e.reward_points),0) AS rewarded_points
             FROM campaigns c
             LEFT JOIN campaign_events e ON e.campaign_id=c.id
             GROUP BY c.id
             ORDER BY c.created_at DESC,c.id DESC
             LIMIT {$limit} OFFSET {$offset}"
        );
        return array_map(fn(array $campaign): array => $this->enrich($campaign), $rows);
    }

    /** @return list<array<string,mixed>> */
    public function activeCampaigns(int $limit = 30): array
    {
        $limit = max(1, min(100, $limit));
        $rows = $this->db->all(
            "SELECT c.*,
                    COALESCE((SELECT SUM(e.cost_minor) FROM campaign_events e WHERE e.campaign_id=c.id AND e.verification_status='verified'),0) AS spent_minor,
                    COALESCE((SELECT COUNT(*) FROM campaign_events e WHERE e.campaign_id=c.id AND e.verification_status='verified'),0) AS verified_events_count
             FROM campaigns c
             WHERE c.status='active'
               AND c.budget_confirmed=TRUE
               AND (c.starts_at IS NULL OR c.starts_at<=NOW())
               AND (c.ends_at IS NULL OR c.ends_at>=NOW())
               AND (c.budget_minor=0 OR COALESCE((SELECT SUM(e.cost_minor) FROM campaign_events e WHERE e.campaign_id=c.id AND e.verification_status='verified'),0)<c.budget_minor)
               AND (c.max_verified_events=0 OR COALESCE((SELECT COUNT(*) FROM campaign_events e WHERE e.campaign_id=c.id AND e.verification_status='verified'),0)<c.max_verified_events)
             ORDER BY c.created_at DESC,c.id DESC
             LIMIT {$limit}"
        );

        return array_values(array_filter(
            array_map(fn(array $campaign): array => $this->enrich($campaign), $rows),
            static fn(array $campaign): bool => ($campaign['runtime_ready'] ?? false) === true,
        ));
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $campaign = $this->db->one(
            "SELECT c.*,
                    COALESCE((SELECT SUM(e.cost_minor) FROM campaign_events e WHERE e.campaign_id=c.id AND e.verification_status='verified'),0) AS spent_minor,
                    COALESCE((SELECT COUNT(*) FROM campaign_events e WHERE e.campaign_id=c.id AND e.verification_status='verified'),0) AS verified_events_count,
                    COALESCE((SELECT COUNT(*) FROM campaign_events e WHERE e.campaign_id=c.id AND e.verification_status='rejected'),0) AS rejected_events_count,
                    COALESCE((SELECT SUM(e.reward_points) FROM campaign_events e WHERE e.campaign_id=c.id),0) AS rewarded_points
             FROM campaigns c WHERE c.id=:id LIMIT 1",
            ['id' => $id],
        );
        return $campaign !== null ? $this->enrich($campaign) : null;
    }

    /** @return array<string,mixed>|null */
    public function findActive(int $id): ?array
    {
        $campaign = $this->find($id);
        if ($campaign === null
            || (string)$campaign['status'] !== 'active'
            || !(bool)$campaign['budget_confirmed']
            || ($campaign['runtime_ready'] ?? false) !== true
        ) {
            return null;
        }
        $now = time();
        if (!empty($campaign['starts_at']) && strtotime((string)$campaign['starts_at']) > $now) {
            return null;
        }
        if (!empty($campaign['ends_at']) && strtotime((string)$campaign['ends_at']) < $now) {
            return null;
        }
        if ((int)$campaign['budget_minor'] > 0 && (int)$campaign['spent_minor'] >= (int)$campaign['budget_minor']) {
            return null;
        }
        if ((int)$campaign['max_verified_events'] > 0
            && (int)$campaign['verified_events_count'] >= (int)$campaign['max_verified_events']
        ) {
            return null;
        }
        return $campaign;
    }

    /** @return array<string,mixed> */
    public function report(int $id): array
    {
        $campaign = $this->find($id);
        if ($campaign === null) {
            throw new \RuntimeException('Nie znaleziono kampanii.');
        }
        $events = $this->db->all(
            'SELECT event_type,verification_status,COUNT(*) cnt,
                    COALESCE(SUM(cost_minor),0) cost_minor,
                    COALESCE(SUM(reward_points),0) reward_points
             FROM campaign_events WHERE campaign_id=:id
             GROUP BY event_type,verification_status
             ORDER BY event_type,verification_status',
            ['id' => $id],
        );
        $recent = $this->db->all(
            'SELECT e.*,u.display_name AS user_name,u.email
             FROM campaign_events e
             LEFT JOIN users u ON u.id=e.user_id
             WHERE e.campaign_id=:id
             ORDER BY e.created_at DESC,e.id DESC LIMIT 80',
            ['id' => $id],
        );
        $summary = [
            'verified' => (int)($campaign['verified_events_count'] ?? 0),
            'rejected' => (int)($campaign['rejected_events_count'] ?? 0),
            'duplicates' => (int)($campaign['duplicate_attempts_count'] ?? 0),
            'spent_minor' => (int)($campaign['spent_minor'] ?? 0),
            'remaining_minor' => max(0, (int)$campaign['budget_minor'] - (int)($campaign['spent_minor'] ?? 0)),
            'rewarded_points' => (int)($campaign['rewarded_points'] ?? 0),
            'estimated_talent_cost_minor' => $this->pointsLiabilityMinor((int)($campaign['rewarded_points'] ?? 0)),
        ];
        $summary['estimated_margin_minor'] = $summary['spent_minor'] - $summary['estimated_talent_cost_minor'];

        return compact('campaign', 'events', 'recent', 'summary');
    }

    public function create(int $adminId, array $data): int
    {
        return $this->save(null, $adminId, $data);
    }

    public function update(int $id, int $adminId, array $data): void
    {
        if ($this->find($id) === null) {
            throw new \RuntimeException('Nie znaleziono kampanii.');
        }
        $this->save($id, $adminId, $data);
    }

    /** @return array<string,mixed>|null */
    public function recordClick(int $userId, int $campaignId): ?array
    {
        return $this->record(
            $userId,
            $campaignId,
            'click',
            'ad_click_reward',
            'server_redirect',
            'redirect-' . bin2hex(random_bytes(12)),
            0,
        );
    }

    /** @return array<string,mixed>|null */
    public function recordView(int $userId, int $campaignId, int $verifiedSeconds, string $proofReference): ?array
    {
        return $this->record(
            $userId,
            $campaignId,
            'view',
            'ad_view_reward',
            'server_timed_session',
            $proofReference,
            max(0, $verifiedSeconds),
        );
    }

    public function recordSponsoredRead(int $userId, int $campaignId): never
    {
        throw new \RuntimeException('Ta kampania czeka na powiązanie z dowodem przeczytania artykułu.');
    }

    public function recordPpv(int $userId, int $campaignId): never
    {
        throw new \RuntimeException('Ta kampania czeka na rzeczywiste potwierdzenie zakupu PPV.');
    }

    public function recordLiveJoin(int $userId, int $campaignId): never
    {
        throw new \RuntimeException('Ta kampania czeka na rzeczywistą sesję transmisji live.');
    }

    private function save(?int $id, int $adminId, array $data): int
    {
        $types = $this->types();
        $statuses = $this->statuses();
        $type = (string)($data['type'] ?? 'ad_click');
        $status = (string)($data['status'] ?? 'draft');
        if (!array_key_exists($type, $types)) {
            throw new \InvalidArgumentException('Wybierz prawidłowy typ kampanii.');
        }
        if (!array_key_exists($status, $statuses)) {
            throw new \InvalidArgumentException('Wybierz prawidłowy status kampanii.');
        }

        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Podaj nazwę kampanii.');
        }
        $clientName = trim((string)($data['client_name'] ?? ''));
        if ($clientName === '') {
            throw new \InvalidArgumentException('Podaj nazwę zleceniodawcy.');
        }
        $clientEmail = trim((string)($data['client_email'] ?? '')) ?: null;
        if ($clientEmail !== null && filter_var($clientEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Podaj poprawny adres e-mail zleceniodawcy.');
        }
        $targetUrl = trim((string)($data['target_url'] ?? '')) ?: null;
        if ($targetUrl !== null && filter_var($targetUrl, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException('Podaj poprawny pełny link docelowy kampanii.');
        }
        $startsAt = $this->dateOrNull($data['starts_at'] ?? null);
        $endsAt = $this->dateOrNull($data['ends_at'] ?? null);
        if ($startsAt !== null && $endsAt !== null && strtotime($endsAt) <= strtotime($startsAt)) {
            throw new \InvalidArgumentException('Koniec kampanii musi przypadać po jej rozpoczęciu.');
        }

        $payload = [
            'client_name' => $clientName,
            'client_email' => $clientEmail,
            'order_reference' => trim((string)($data['order_reference'] ?? '')) ?: null,
            'name' => $name,
            'type' => $type,
            'description' => trim((string)($data['description'] ?? '')) ?: null,
            'target_url' => $targetUrl,
            'budget' => $this->moneyToMinor($data['budget'] ?? '0'),
            'cpv' => $this->moneyToMinor($data['cost_per_view'] ?? '0'),
            'cpc' => $this->moneyToMinor($data['cost_per_click'] ?? '0'),
            'cps' => $this->moneyToMinor($data['cost_per_completed_survey'] ?? '0'),
            'status' => $status,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'budget_confirmed' => $this->postedBool($data, 'budget_confirmed'),
            'max_events' => max(0, min(100000000, (int)($data['max_verified_events'] ?? 0))),
            'admin' => $adminId,
        ];

        $definition = $this->typeDefinitions()[$type];
        $eventCost = match ((string)$definition['cost_field']) {
            'cost_per_click_minor' => $payload['cpc'],
            'cost_per_completed_survey_minor' => $payload['cps'],
            default => $payload['cpv'],
        };
        if ($payload['budget'] > 0 && $eventCost > $payload['budget']) {
            throw new \InvalidArgumentException('Cena jednego zweryfikowanego zdarzenia nie może przekraczać budżetu kampanii.');
        }

        if ($status === 'active') {
            if (!in_array($type, self::READY_TYPES, true) || ($definition['ready'] ?? false) !== true) {
                throw new \RuntimeException('Ten typ kampanii pozostaje w szkicu, dopóki nie ma wiarygodnego dowodu zdarzenia.');
            }
            if (!$payload['budget_confirmed']) {
                throw new \InvalidArgumentException('Przed aktywacją potwierdź przyjęcie zlecenia i budżetu reklamodawcy.');
            }
            if ($payload['budget'] <= 0 || $eventCost <= 0) {
                throw new \InvalidArgumentException('Aktywna kampania musi mieć dodatni budżet i cenę zweryfikowanego zdarzenia.');
            }
            if ($type === 'ad_click' && $targetUrl === null) {
                throw new \InvalidArgumentException('Kampania kliknięciowa wymaga linku docelowego.');
            }
            if (($definition['talent_active'] ?? false) !== true || (int)$definition['talent_points'] <= 0) {
                throw new \RuntimeException('Najpierw ustaw i włącz odpowiadającą regułę w Ustawienia → Program Talent.');
            }
            $talentCost = $this->pointsLiabilityMinor((int)$definition['talent_points']);
            if ($eventCost <= $talentCost) {
                throw new \InvalidArgumentException(
                    'Cena zdarzenia musi być wyższa od kosztu TT (' . $this->formatMoney($talentCost) . '), aby kampania generowała dodatnią marżę.'
                );
            }
        }

        if ($id === null) {
            return $this->db->insert(
                'INSERT INTO campaigns(
                    client_name,client_email,order_reference,name,type,description,target_url,
                    budget_minor,cost_per_view_minor,cost_per_click_minor,cost_per_completed_survey_minor,
                    reward_for_user_minor,status,starts_at,ends_at,budget_confirmed,max_verified_events,
                    duplicate_attempts_count,created_by_admin_id,created_at,updated_at
                 ) VALUES(
                    :client_name,:client_email,:order_reference,:name,:type,:description,:target_url,
                    :budget,:cpv,:cpc,:cps,0,:status,:starts_at,:ends_at,:budget_confirmed,:max_events,
                    0,:admin,NOW(),NOW()
                 )',
                $payload,
            );
        }

        $payload['id'] = $id;
        $this->db->query(
            'UPDATE campaigns SET
                client_name=:client_name,client_email=:client_email,order_reference=:order_reference,
                name=:name,type=:type,description=:description,target_url=:target_url,
                budget_minor=:budget,cost_per_view_minor=:cpv,cost_per_click_minor=:cpc,
                cost_per_completed_survey_minor=:cps,reward_for_user_minor=0,status=:status,
                starts_at=:starts_at,ends_at=:ends_at,budget_confirmed=:budget_confirmed,
                max_verified_events=:max_events,updated_at=NOW()
             WHERE id=:id',
            $payload,
        );
        return $id;
    }

    /** @return array<string,mixed>|null */
    private function record(
        int $userId,
        int $campaignId,
        string $eventType,
        string $activityType,
        string $proofType,
        string $proofReference,
        int $watchSeconds,
    ): ?array {
        return $this->db->transaction(function (Database $db) use (
            $userId,
            $campaignId,
            $eventType,
            $activityType,
            $proofType,
            $proofReference,
            $watchSeconds,
        ): ?array {
            $campaign = $db->one(
                "SELECT * FROM campaigns
                 WHERE id=:id AND status='active' AND budget_confirmed=TRUE
                   AND (starts_at IS NULL OR starts_at<=NOW())
                   AND (ends_at IS NULL OR ends_at>=NOW())
                 LIMIT 1 FOR UPDATE",
                ['id' => $campaignId],
            );
            if ($campaign === null) {
                throw new \RuntimeException('Ta kampania nie jest teraz aktywna.');
            }

            $definition = $this->typeDefinitions()[(string)$campaign['type']] ?? null;
            if ($definition === null
                || ($definition['ready'] ?? false) !== true
                || (string)$definition['event_type'] !== $eventType
                || (string)$definition['activity_type'] !== $activityType
            ) {
                throw new \RuntimeException('Ta akcja nie jest właściwa dla wybranej kampanii.');
            }

            $today = date('Y-m-d');
            $idempotencyKey = implode(':', ['campaign', $campaignId, $userId, $eventType, $today]);
            $existing = $db->one(
                'SELECT id FROM campaign_events WHERE idempotency_key=:key LIMIT 1',
                ['key' => $idempotencyKey],
            );
            if ($existing !== null) {
                $db->query(
                    'UPDATE campaigns SET duplicate_attempts_count=duplicate_attempts_count+1,updated_at=NOW() WHERE id=:id',
                    ['id' => $campaignId],
                );
                return null;
            }

            $guard = $this->fraudGuard?->inspectCampaignEvent($userId, $campaignId, $eventType, $watchSeconds) ?? [
                'allowed' => true,
                'risk_score' => 0,
                'status' => FraudGuardService::STATUS_NORMAL,
                'reasons' => [],
            ];
            $allowed = ($guard['allowed'] ?? false) === true;
            $rule = $this->talentRule($activityType);
            if ($allowed && ((int)($rule['is_active'] ?? 0) !== 1 || (int)($rule['points_amount'] ?? 0) <= 0)) {
                throw new \RuntimeException('Kampania została bezpiecznie zatrzymana, ponieważ jej reguła Talent nie jest aktywna.');
            }

            $eventCost = match ((string)$definition['cost_field']) {
                'cost_per_click_minor' => (int)$campaign['cost_per_click_minor'],
                'cost_per_completed_survey_minor' => (int)$campaign['cost_per_completed_survey_minor'],
                default => (int)$campaign['cost_per_view_minor'],
            };
            $points = $allowed ? (int)($rule['points_amount'] ?? 0) : 0;
            if ($allowed && $eventCost <= $this->pointsLiabilityMinor($points)) {
                throw new \RuntimeException('Kampania została zatrzymana, ponieważ aktualna stawka nie pokrywa kosztu TT.');
            }

            $verifiedCount = (int)$db->cell(
                "SELECT COUNT(*) FROM campaign_events WHERE campaign_id=:id AND verification_status='verified'",
                ['id' => $campaignId],
            );
            if ($allowed && (int)$campaign['max_verified_events'] > 0 && $verifiedCount >= (int)$campaign['max_verified_events']) {
                throw new \RuntimeException('Limit zweryfikowanych zdarzeń tej kampanii został osiągnięty.');
            }
            $spent = (int)$db->cell(
                "SELECT COALESCE(SUM(cost_minor),0) FROM campaign_events WHERE campaign_id=:id AND verification_status='verified'",
                ['id' => $campaignId],
            );
            if ($allowed && (int)$campaign['budget_minor'] > 0 && ($spent + $eventCost) > (int)$campaign['budget_minor']) {
                throw new \RuntimeException('Budżet tej kampanii został wykorzystany.');
            }

            $publicId = $this->uuidV4();
            $reasons = array_values(array_filter(array_map('strval', (array)($guard['reasons'] ?? []))));
            $verificationReason = $allowed ? 'verified_by_' . $proofType : ($reasons[0] ?? 'fraud_guard_rejected');
            $eventId = $db->insert(
                'INSERT INTO campaign_events(
                    public_id,campaign_id,user_id,event_type,event_date,cost_minor,reward_minor,reward_points,
                    ip_hash,user_agent,watch_seconds,is_rewarded,fraud_risk_score,fraud_status,
                    verification_status,proof_type,proof_reference,verified_at,idempotency_key,
                    talent_activity_type,talent_rule_qualified,talent_points_snapshot,verification_reason,metadata_json,created_at
                 ) VALUES(
                    :public_id,:campaign,:user,:event_type,:event_date,:cost,0,0,
                    :ip,:user_agent,:watch_seconds,0,:risk_score,:fraud_status,
                    :verification_status,:proof_type,:proof_reference,:verified_at,:idempotency_key,
                    :talent_activity_type,:talent_qualified,:talent_points,:verification_reason,CAST(:metadata AS jsonb),NOW()
                 )',
                [
                    'public_id' => $publicId,
                    'campaign' => $campaignId,
                    'user' => $userId,
                    'event_type' => $eventType,
                    'event_date' => $today,
                    'cost' => $allowed ? $eventCost : 0,
                    'ip' => $this->hashIp((string)($_SERVER['REMOTE_ADDR'] ?? '')),
                    'user_agent' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                    'watch_seconds' => $watchSeconds,
                    'risk_score' => (int)($guard['risk_score'] ?? 0),
                    'fraud_status' => (string)($guard['status'] ?? FraudGuardService::STATUS_NORMAL),
                    'verification_status' => $allowed ? 'verified' : 'rejected',
                    'proof_type' => $proofType,
                    'proof_reference' => mb_substr($proofReference, 0, 190),
                    'verified_at' => $allowed ? date('Y-m-d H:i:s') : null,
                    'idempotency_key' => $idempotencyKey,
                    'talent_activity_type' => $activityType,
                    'talent_qualified' => $allowed,
                    'talent_points' => $points,
                    'verification_reason' => mb_substr($verificationReason, 0, 190),
                    'metadata' => json_encode(['guard_reasons' => $reasons], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ],
            );

            if ($eventType === 'view') {
                $db->insert(
                    'INSERT INTO ad_views(campaign_id,user_id,campaign_event_id,viewed_at) VALUES(:campaign,:user,:event,NOW())',
                    ['campaign' => $campaignId, 'user' => $userId, 'event' => $eventId],
                );
            } elseif ($eventType === 'click') {
                $db->insert(
                    'INSERT INTO ad_clicks(campaign_id,user_id,campaign_event_id,clicked_at,target_url) VALUES(:campaign,:user,:event,NOW(),:url)',
                    ['campaign' => $campaignId, 'user' => $userId, 'event' => $eventId, 'url' => $campaign['target_url'] ?? null],
                );
            }

            if (!$allowed) {
                return ['event_id' => $eventId, 'campaign' => $this->enrich($campaign), 'award' => null, 'fraud' => $guard];
            }

            $award = $this->talent->queueAward(
                $userId,
                $activityType,
                'campaign_event',
                $eventId,
            );
            if (($award['queued'] ?? false) !== true || empty($award['public_id'])) {
                throw new \RuntimeException('Nie udało się atomowo zakolejkować należnych TT. Zdarzenie nie zostało rozliczone.');
            }
            $db->query(
                'UPDATE campaign_events SET talent_job_public_id=:job WHERE id=:id',
                ['job' => (string)$award['public_id'], 'id' => $eventId],
            );

            return ['event_id' => $eventId, 'campaign' => $this->enrich($campaign), 'award' => $award, 'fraud' => $guard];
        });
    }

    /** @return array<string,mixed> */
    private function enrich(array $campaign): array
    {
        $definition = $this->typeDefinitions()[(string)($campaign['type'] ?? '')] ?? null;
        $points = (int)($definition['talent_points'] ?? 0);
        $eventCost = match ((string)($definition['cost_field'] ?? '')) {
            'cost_per_click_minor' => (int)($campaign['cost_per_click_minor'] ?? 0),
            'cost_per_completed_survey_minor' => (int)($campaign['cost_per_completed_survey_minor'] ?? 0),
            default => (int)($campaign['cost_per_view_minor'] ?? 0),
        };
        $liability = $this->pointsLiabilityMinor($points);
        $campaign['talent_points'] = $points;
        $campaign['talent_activity_type'] = $definition['activity_type'] ?? null;
        $campaign['event_cost_minor'] = $eventCost;
        $campaign['talent_cost_minor'] = $liability;
        $campaign['margin_per_event_minor'] = $eventCost - $liability;
        $campaign['proof_description'] = $definition['proof'] ?? '';
        $campaign['proof_key'] = $definition['proof_key'] ?? 'campaign.proof.pending';
        $campaign['type_ready'] = ($definition['ready'] ?? false) === true;
        $campaign['talent_rule_active'] = ($definition['talent_active'] ?? false) === true;
        $campaign['runtime_ready'] = $campaign['type_ready']
            && $campaign['talent_rule_active']
            && $points > 0
            && $eventCost > $liability;
        return $campaign;
    }

    /** @return array<string,mixed>|null */
    private function talentRule(string $activityType): ?array
    {
        return $this->db->one(
            'SELECT activity_type,points_amount,amount_minor,daily_limit,is_active FROM activity_reward_rules WHERE activity_type=:type LIMIT 1',
            ['type' => $activityType],
        );
    }

    private function pointsLiabilityMinor(int $points): int
    {
        if ($points <= 0) {
            return 0;
        }
        return (int)ceil(($points * 100) / $this->ttPerPln());
    }

    private function ttPerPln(): int
    {
        $value = $this->db->cell("SELECT value FROM settings WHERE name='wallet.tt_per_pln' LIMIT 1");
        return max(1, (int)($value ?: LedgerService::POINTS_PER_PLN));
    }

    private function moneyToMinor(mixed $value): int
    {
        $normalized = str_replace([' ', ','], ['', '.'], trim((string)$value));
        if ($normalized === '') {
            return 0;
        }
        if (!is_numeric($normalized)) {
            throw new \InvalidArgumentException('Kwoty kampanii muszą być liczbami.');
        }
        return max(0, (int)round(((float)$normalized) * 100));
    }

    private function dateOrNull(mixed $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            throw new \InvalidArgumentException('Podano nieprawidłową datę kampanii.');
        }
        return date('Y-m-d H:i:s', $timestamp);
    }

    private function postedBool(array $data, string $key): bool
    {
        return isset($data[$key]) && in_array(strtolower((string)$data[$key]), ['1', 'on', 'true', 'yes'], true);
    }

    private function formatMoney(int $minor): string
    {
        return number_format($minor / 100, 2, ',', ' ') . ' PLN';
    }

    private function hashIp(string $ip): ?string
    {
        return $ip === '' ? null : hash('sha256', 'zrodlo-slowa:' . $ip);
    }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}

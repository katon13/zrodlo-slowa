<?php
namespace App\Services;

use App\Core\Database;

final class CampaignService
{
    public function __construct(
        private readonly Database $db,
        private readonly TalentService $talent,
        private readonly ?FraudGuardService $fraudGuard = null,
    ) {}

    public function types(): array { return ['ad_click'=>'Płać za kliknięcie','display_ad'=>'Reklama display','sponsored_article'=>'Artykuł sponsorowany','ad_view'=>'Oglądanie reklamy','ppv'=>'PPV','live'=>'Transmisja live','survey_ad'=>'Ankieta reklamowa']; }
    public function statuses(): array { return ['draft'=>'Szkic','active'=>'Aktywna','paused'=>'Pauza','closed'=>'Zamknięta']; }
    public function allForAdmin(int $limit = 50, int $offset = 0): array { $limit=max(1,min(200,$limit)); $offset=max(0,$offset); return $this->db->all('SELECT c.*, COUNT(e.id) events_count, COALESCE(SUM(e.cost_minor),0) spent_minor FROM campaigns c LEFT JOIN campaign_events e ON e.campaign_id=c.id GROUP BY c.id ORDER BY c.created_at DESC, c.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset); }
    public function activeCampaigns(int $limit = 30): array { $limit=max(1,min(100,$limit)); return $this->db->all("SELECT * FROM campaigns WHERE status='active' AND (starts_at IS NULL OR starts_at<=NOW()) AND (ends_at IS NULL OR ends_at>=NOW()) ORDER BY created_at DESC, id DESC LIMIT " . $limit); }
    public function find(int $id): ?array { return $id>0 ? $this->db->one('SELECT * FROM campaigns WHERE id=:id LIMIT 1',['id'=>$id]) : null; }

    public function report(int $id): array
    {
        $campaign = $this->find($id); if (!$campaign) throw new \RuntimeException('Nie znaleziono kampanii.');
        return [
            'campaign'=>$campaign,
            'events'=>$this->db->all('SELECT event_type,COUNT(*) cnt,COALESCE(SUM(cost_minor),0) cost_minor,COALESCE(SUM(reward_minor),0) reward_minor FROM campaign_events WHERE campaign_id=:id GROUP BY event_type ORDER BY event_type',['id'=>$id]),
            'recent'=>$this->db->all('SELECT e.*, u.display_name AS user_name, u.email FROM campaign_events e LEFT JOIN users u ON u.id=e.user_id WHERE e.campaign_id=:id ORDER BY e.created_at DESC,e.id DESC LIMIT 80',['id'=>$id]),
        ];
    }

    public function create(int $adminId, array $data): int { return $this->save(null,$adminId,$data); }
    public function update(int $id, int $adminId, array $data): void
    {
        if (!$this->find($id)) {
            throw new \RuntimeException('Nie znaleziono kampanii.');
        }
        $this->save($id,$adminId,$data);
    }

    private function save(?int $id, int $adminId, array $data): int
    {
        $types=$this->types(); $statuses=$this->statuses();
        $name=trim((string)($data['name']??'')); if ($name==='') throw new \InvalidArgumentException('Podaj nazwę kampanii.');
        $targetUrl = trim((string)($data['target_url'] ?? '')) ?: null;
        if ($targetUrl !== null && filter_var($targetUrl, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException('Podaj poprawny pełny link docelowy kampanii.');
        }
        $startsAt = $this->dateOrNull($data['starts_at'] ?? null);
        $endsAt = $this->dateOrNull($data['ends_at'] ?? null);
        if ($startsAt !== null && $endsAt !== null && strtotime($endsAt) <= strtotime($startsAt)) {
            throw new \InvalidArgumentException('Koniec kampanii musi przypadać po jej rozpoczęciu.');
        }

        $payload=[
            'client_name'=>trim((string)($data['client_name']??''))?:null,'name'=>$name,
            'type'=>array_key_exists((string)($data['type']??''),$types)?(string)$data['type']:'display_ad',
            'description'=>trim((string)($data['description']??''))?:null,'target_url'=>$targetUrl,
            'budget'=>$this->moneyToMinor($data['budget']??'0'),'cpv'=>$this->moneyToMinor($data['cost_per_view']??'0'),'cpc'=>$this->moneyToMinor($data['cost_per_click']??'0'),'cps'=>$this->moneyToMinor($data['cost_per_completed_survey']??'0'),'reward'=>$this->moneyToMinor($data['reward_for_user']??'0'),
            'status'=>array_key_exists((string)($data['status']??''),$statuses)?(string)$data['status']:'draft','starts_at'=>$startsAt,'ends_at'=>$endsAt,'admin'=>$adminId,
        ];
        $highestEventCost = max($payload['cpv'], $payload['cpc'], $payload['cps'], $payload['reward']);
        if ($payload['status'] === 'active' && $highestEventCost > 0 && $payload['budget'] <= 0) {
            throw new \InvalidArgumentException('Aktywna kampania z kosztami lub nagrodami musi mieć dodatni budżet.');
        }
        if ($payload['budget'] > 0 && $highestEventCost > $payload['budget']) {
            throw new \InvalidArgumentException('Koszt pojedynczego zdarzenia lub nagroda nie może przekraczać całego budżetu kampanii.');
        }
        if ($id===null) return $this->db->insert('INSERT INTO campaigns(client_name,name,type,description,target_url,budget_minor,cost_per_view_minor,cost_per_click_minor,cost_per_completed_survey_minor,reward_for_user_minor,status,starts_at,ends_at,created_by_admin_id,created_at,updated_at) VALUES(:client_name,:name,:type,:description,:target_url,:budget,:cpv,:cpc,:cps,:reward,:status,:starts_at,:ends_at,:admin,NOW(),NOW())',$payload);
        $payload['id']=$id; $this->db->query('UPDATE campaigns SET client_name=:client_name,name=:name,type=:type,description=:description,target_url=:target_url,budget_minor=:budget,cost_per_view_minor=:cpv,cost_per_click_minor=:cpc,cost_per_completed_survey_minor=:cps,reward_for_user_minor=:reward,status=:status,starts_at=:starts_at,ends_at=:ends_at,updated_at=NOW() WHERE id=:id',$payload); return $id;
    }

    public function recordView(int $userId,int $campaignId): ?array { return $this->record($userId,$campaignId,'view','ad_view_reward'); }
    public function recordClick(int $userId,int $campaignId): ?array { return $this->record($userId,$campaignId,'click','ad_click_reward'); }
    public function recordSponsoredRead(int $userId,int $campaignId): ?array { return $this->record($userId,$campaignId,'sponsored_read','sponsored_article_read_bonus'); }
    public function recordPpv(int $userId,int $campaignId): ?array { return $this->record($userId,$campaignId,'ppv_purchase','ppv_reward'); }
    public function recordLiveJoin(int $userId,int $campaignId): ?array { return $this->record($userId,$campaignId,'live_join','live_event_reward'); }

    private function record(int $userId,int $campaignId,string $eventType,string $activityType): ?array
    {
        $watchSeconds = max(0, (int)($_POST['watch_seconds'] ?? 0));

        return $this->db->transaction(function(Database $db) use($userId,$campaignId,$eventType,$activityType,$watchSeconds){
            $c=$db->one("SELECT * FROM campaigns WHERE id=:id AND status='active' AND (starts_at IS NULL OR starts_at<=NOW()) AND (ends_at IS NULL OR ends_at>=NOW()) LIMIT 1 FOR UPDATE",['id'=>$campaignId]);
            if(!$c) throw new \RuntimeException('Ta kampania nie jest aktywna.');
            $today=date('Y-m-d');
            $exists=$db->one('SELECT id FROM campaign_events WHERE campaign_id=:c AND user_id=:u AND event_type=:t AND event_date=:d LIMIT 1',['c'=>$campaignId,'u'=>$userId,'t'=>$eventType,'d'=>$today]);
            if($exists) return null;

            $guard = $this->fraudGuard?->inspectCampaignEvent($userId, $campaignId, $eventType, $watchSeconds) ?? [
                'allowed' => true,
                'risk_score' => 0,
                'status' => 'normal',
                'reasons' => [],
            ];

            $configuredCost = match ($eventType) {
                'click' => (int)$c['cost_per_click_minor'],
                'survey_completed' => (int)$c['cost_per_completed_survey_minor'],
                default => (int)$c['cost_per_view_minor'],
            };
            $reward=((bool)$guard['allowed']) ? (int)$c['reward_for_user_minor'] : 0;
            // Budżet jest limitem całej kampanii, a nie ceną jednego PPV/live.
            // Koszt zdarzenia musi obejmować co najmniej wypłacaną nagrodę.
            $cost = (bool)$guard['allowed'] ? max($configuredCost, $reward) : 0;
            $spent = (int)$db->cell('SELECT COALESCE(SUM(cost_minor),0) FROM campaign_events WHERE campaign_id=:id', ['id' => $campaignId]);
            $budget = (int)$c['budget_minor'];
            if ($budget > 0 && ($spent + $cost) > $budget) {
                throw new \RuntimeException('Budżet tej kampanii został wyczerpany.');
            }
            $eventId=$db->insert('INSERT INTO campaign_events(campaign_id,user_id,event_type,event_date,cost_minor,reward_minor,ip_hash,user_agent,watch_seconds,is_rewarded,fraud_risk_score,fraud_status,created_at) VALUES(:c,:u,:t,:d,:cost,:reward,:ip,:ua,:watch,:rewarded,:risk,:fraud_status,NOW())',[
                'c'=>$campaignId,
                'u'=>$userId,
                't'=>$eventType,
                'd'=>$today,
                'cost'=>$cost,
                'reward'=>0,
                'ip'=>$this->hashIp($_SERVER['REMOTE_ADDR']??''),
                'ua'=>substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,255),
                'watch'=>$watchSeconds,
                'rewarded'=>0,
                'risk'=>(int)$guard['risk_score'],
                'fraud_status'=>(string)$guard['status'],
            ]);
            if($eventType==='view') $db->insert('INSERT INTO ad_views(campaign_id,user_id,campaign_event_id,viewed_at) VALUES(:c,:u,:e,NOW())',['c'=>$campaignId,'u'=>$userId,'e'=>$eventId]);
            if($eventType==='click') $db->insert('INSERT INTO ad_clicks(campaign_id,user_id,campaign_event_id,clicked_at,target_url) VALUES(:c,:u,:e,NOW(),:url)',['c'=>$campaignId,'u'=>$userId,'e'=>$eventId,'url'=>$c['target_url']??null]);
            if($eventType==='sponsored_read') $db->insert('INSERT INTO sponsored_article_reads(campaign_id,user_id,campaign_event_id,read_at) VALUES(:c,:u,:e,NOW())',['c'=>$campaignId,'u'=>$userId,'e'=>$eventId]);
            if($eventType==='ppv_purchase') $db->insert('INSERT INTO ppv_events(campaign_id,title,description,price_minor,status,starts_at,ends_at,created_at,updated_at) VALUES(:c,:title,:description,0,\'active\',NOW(),NULL,NOW(),NOW())',['c'=>$campaignId,'title'=>$c['name'],'description'=>$c['description']??null]);
            if($eventType==='live_join') $db->insert('INSERT INTO live_events(campaign_id,title,description,status,starts_at,ends_at,created_at,updated_at) VALUES(:c,:title,:description,\'live\',NOW(),NULL,NOW(),NOW())',['c'=>$campaignId,'title'=>$c['name'],'description'=>$c['description']??null]);

            if(!((bool)$guard['allowed'])) {
                return ['event_id'=>$eventId,'campaign'=>$c,'award'=>null,'fraud'=>$guard];
            }

            $award=$this->talent->queueAward($userId,$activityType,'campaign_event',$eventId);
            return ['event_id'=>$eventId,'campaign'=>$c,'award'=>$award,'fraud'=>$guard];
        });
    }
    private function moneyToMinor(mixed $v): int
    {
        $normalized = str_replace([' ', ','], ['', '.'], trim((string)$v));
        if ($normalized === '') {
            return 0;
        }
        if (!is_numeric($normalized)) {
            throw new \InvalidArgumentException('Kwoty kampanii muszą być liczbami.');
        }
        return max(0, (int)round(((float)$normalized) * 100));
    }

    private function dateOrNull(mixed $v): ?string
    {
        $value = trim((string)$v);
        if ($value === '') {
            return null;
        }
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            throw new \InvalidArgumentException('Podano nieprawidłową datę kampanii.');
        }
        return date('Y-m-d H:i:s', $timestamp);
    }
    private function hashIp(string $ip): ?string { return $ip===''?null:hash('sha256','zrodlo-slowa:'.$ip); }
}

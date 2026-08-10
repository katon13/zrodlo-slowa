<?php
namespace App\Services;

use App\Core\Database;

final class PayoutMethodService
{
    public function __construct(private readonly Database $db) {}

    public function forUser(int $userId): array
    {
        return $this->db->all('SELECT * FROM payout_methods WHERE user_id=:id ORDER BY is_default DESC, id DESC', ['id'=>$userId]);
    }

    public function create(int $userId, string $type, string $label, string $accountRef): int
    {
        (new UserService($this->db))->assertPayoutAccountEligible($userId);
        if (!in_array($type, ['bank','blik','paypal','manual'], true)) $type = 'manual';
        if ($label === '' || $accountRef === '') throw new \InvalidArgumentException('Nazwa i dane wypłaty są wymagane.');
        return $this->db->transaction(function(Database $db) use ($userId, $type, $label, $accountRef) {
            $has = $db->one('SELECT id FROM payout_methods WHERE user_id=:id LIMIT 1', ['id'=>$userId]);
            $isDefault = $has ? 0 : 1;
            return $db->insert('INSERT INTO payout_methods(user_id,type,label,account_ref,is_default,created_at) VALUES(:user,:type,:label,:ref,:def,NOW())', [
                'user'=>$userId,'type'=>$type,'label'=>$label,'ref'=>$accountRef,'def'=>$isDefault
            ]);
        });
    }
}

<?php
namespace App\Services;

use App\Core\Database;

final class PaymentService
{
    public function __construct(private readonly Database $db) {}

    public function createManualDonation(?int $userId, array $data): int
    {
        $amount = max(0, (int)$data['amount_minor']);
        if ($amount < 100) throw new \InvalidArgumentException('Minimalna wpłata to 1 PLN.');
        $paymentId = $this->createPayment($userId, 'manual', 'donation', 'pending', $amount, [
            'payer_email' => $data['email'] ?: null,
            'note' => $data['note'] ?: null,
        ]);
        
        $campaignId = (int)($data['campaign_id'] ?? 0);
        if (!$campaignId) {
            $campaign = $this->db->one('SELECT id FROM donation_campaigns WHERE slug=\'budowa-zrodla-slowa\'');
            $campaignId = $campaign ? (int)$campaign['id'] : null;
        }

        $this->db->query('INSERT INTO donations(payment_id,campaign_id,campaign,created_at) VALUES(:payment,:campaign_id,\'Budowa Źródła Słowa\',NOW())', [
            'payment'=>$paymentId,
            'campaign_id'=>$campaignId
        ]);
        return $paymentId;
    }

    public function createPayment(?int $userId, string $provider, string $type, string $status, int $amountMinor, array $ctx = []): int
    {
        return $this->db->insert('INSERT INTO payments(legacy_source,legacy_id,user_id,provider,provider_status,type,status,amount_minor,currency,payer_email,external_id,payment_key,mode,note,raw_json,created_at,completed_at,updated_at) VALUES(:legacy_source,:legacy_id,:user,:provider,:provider_status,:type,:status,:amount,:currency,:email,:external_id,:payment_key,:mode,:note,:raw,NOW(),:completed,NOW())', [
            'legacy_source'=>$ctx['legacy_source'] ?? null,
            'legacy_id'=>$ctx['legacy_id'] ?? null,
            'user'=>$userId,
            'provider'=>$provider,
            'provider_status'=>$ctx['provider_status'] ?? null,
            'type'=>$type,
            'status'=>$status,
            'amount'=>$amountMinor,
            'currency'=>$ctx['currency'] ?? 'PLN',
            'email'=>$ctx['payer_email'] ?? null,
            'external_id'=>$ctx['external_id'] ?? null,
            'payment_key'=>$ctx['payment_key'] ?? null,
            'mode'=>$ctx['mode'] ?? null,
            'note'=>$ctx['note'] ?? null,
            'raw'=>isset($ctx['raw']) ? json_encode($ctx['raw'], JSON_UNESCAPED_UNICODE) : null,
            'completed'=>$ctx['completed_at'] ?? null,
        ]);
    }

    public function addItem(int $paymentId, string $itemType, ?int $itemId, string $title, int $amountMinor, array $ctx = []): int
    {
        return $this->db->insert('INSERT INTO payment_items(payment_id,legacy_source,legacy_id,item_type,item_id,title,quantity,amount_minor,raw_json) VALUES(:payment,:legacy_source,:legacy_id,:type,:item,:title,:qty,:amount,:raw)', [
            'payment'=>$paymentId, 'legacy_source'=>$ctx['legacy_source'] ?? null, 'legacy_id'=>$ctx['legacy_id'] ?? null, 'type'=>$itemType, 'item'=>$itemId, 'title'=>$title, 'qty'=>$ctx['quantity'] ?? 1, 'amount'=>$amountMinor, 'raw'=>isset($ctx['raw']) ? json_encode($ctx['raw'], JSON_UNESCAPED_UNICODE) : null
        ]);
    }

    public function markPaid(int $paymentId, ?string $externalId = null): void
    {
        if ($paymentId <= 0) {
            throw new \InvalidArgumentException('Brak prawidłowego ID płatności.');
        }

        $this->db->transaction(function (Database $db) use ($paymentId, $externalId): void {
            $payment = $db->one('SELECT * FROM payments WHERE id=:id FOR UPDATE', ['id'=>$paymentId]);
            if (!$payment) {
                throw new \RuntimeException('Nie znaleziono płatności.');
            }
            if ($payment['status'] === 'paid') {
                return;
            }

            $db->query('UPDATE payments SET status=\'paid\', external_id=COALESCE(:external, external_id), completed_at=COALESCE(completed_at,NOW()), updated_at=NOW() WHERE id=:id', ['id'=>$paymentId, 'external'=>$externalId]);
            $db->query('INSERT INTO payment_events(payment_id,event_type,payload_json,created_at) VALUES(:id,\'marked_paid\',NULL,NOW())', ['id'=>$paymentId]);

            if ($payment['type'] === 'article_payment') {
                $items = $db->all('SELECT * FROM payment_items WHERE payment_id=:id', ['id'=>$paymentId]);
                $articleService = new ArticleService($db);
                foreach ($items as $item) {
                    if ($item['item_type'] === 'article' && $item['item_id']) {
                        $articleService->grantAccess((int)$payment['user_id'], (int)$item['item_id'], $paymentId, 'payment');
                    }
                }
            }
        });
    }

    public function markRefunded(int $paymentId): void
    {
        $payment = $this->db->one('SELECT * FROM payments WHERE id=:id', ['id'=>$paymentId]);
        if (!$payment || $payment['status'] === 'refunded') return;

        $this->db->query('UPDATE payments SET status=\'refunded\', updated_at=NOW() WHERE id=:id', ['id'=>$paymentId]);
        $this->db->query('INSERT INTO payment_events(payment_id,event_type,payload_json,created_at) VALUES(:id,\'marked_refunded\',NULL,NOW())', ['id'=>$paymentId]);

        if ($payment['type'] === 'article_payment') {
            (new ArticleService($this->db))->revokeByPayment($paymentId);
        }
    }

    public function markCancelled(int $paymentId): void
    {
        $payment = $this->db->one('SELECT * FROM payments WHERE id=:id', ['id'=>$paymentId]);
        if (!$payment || $payment['status'] === 'cancelled') return;

        $this->db->query('UPDATE payments SET status=\'cancelled\', updated_at=NOW() WHERE id=:id', ['id'=>$paymentId]);
        $this->db->query('INSERT INTO payment_events(payment_id,event_type,payload_json,created_at) VALUES(:id,\'marked_cancelled\',NULL,NOW())', ['id'=>$paymentId]);

        if ($payment['type'] === 'article_payment') {
            (new ArticleService($this->db))->revokeByPayment($paymentId);
        }
    }

    public function listRecent(int $limit = 50): array
    {
        return $this->db->all('SELECT * FROM payments ORDER BY created_at DESC, id DESC LIMIT ' . (int)$limit);
    }
}

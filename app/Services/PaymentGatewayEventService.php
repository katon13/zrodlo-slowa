<?php
namespace App\Services;

use App\Core\Database;

final class PaymentGatewayEventService
{
    public function __construct(private readonly Database $db) {}

    public function recordReceived(string $provider, string $eventId, string $eventType, string $payloadJson, array $refs = []): array
    {
        $params = [
            'provider' => $provider,
            'event_id' => $eventId,
            'event_type' => $eventType,
            'order_id' => $refs['payment_order_id'] ?? null,
            'session_id' => $refs['stripe_session_id'] ?? null,
            'pi' => $refs['stripe_payment_intent_id'] ?? null,
            'payload' => $payloadJson,
        ];
        $baseSql = 'INSERT INTO payment_gateway_events(
                        provider,event_id,event_type,payment_order_id,stripe_session_id,
                        stripe_payment_intent_id,received_at,processing_status,payload_json
                    ) VALUES(
                        :provider,:event_id,:event_type,:order_id,:session_id,
                        :pi,NOW(),\'received\',:payload
                    )';

        if ($this->db->isPostgres()) {
            $statement = $this->db->query(
                $baseSql . ' ON CONFLICT (provider,event_id) DO NOTHING RETURNING id',
                $params
            );
            $insertedId = $statement->fetchColumn();
            if ($insertedId !== false) {
                return ['id' => (int)$insertedId, 'duplicate' => false, 'row' => null];
            }
        } else {
            $statement = $this->db->query(
                $baseSql . ' ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)',
                $params
            );
            $id = (int)$this->db->pdo()->lastInsertId();
            if ($statement->rowCount() === 1) {
                return ['id' => $id, 'duplicate' => false, 'row' => null];
            }
        }

        $existing = $this->db->one(
            'SELECT * FROM payment_gateway_events
             WHERE provider=:provider AND event_id=:event_id
             LIMIT 1',
            ['provider' => $provider, 'event_id' => $eventId]
        );
        if ($existing === null) {
            throw new \RuntimeException('Nie udało się odczytać zdeduplikowanego zdarzenia płatniczego.');
        }
        return ['id' => (int)$existing['id'], 'duplicate' => true, 'row' => $existing];
    }

    public function attachOrder(int $eventId, ?int $orderId, ?string $sessionId, ?string $paymentIntentId): void
    {
        $this->db->query('UPDATE payment_gateway_events SET payment_order_id=:order_id, stripe_session_id=:session_id, stripe_payment_intent_id=:pi WHERE id=:id', [
            'id' => $eventId,
            'order_id' => $orderId,
            'session_id' => $sessionId,
            'pi' => $paymentIntentId,
        ]);
    }

    public function markProcessed(int $eventId): void
    {
        $this->db->query('UPDATE payment_gateway_events SET processing_status=\'processed\', processed_at=NOW() WHERE id=:id', ['id' => $eventId]);
    }

    public function markIgnored(int $eventId, string $reason): void
    {
        $this->db->query('UPDATE payment_gateway_events SET processing_status=\'ignored\', processed_at=NOW(), error_message=:reason WHERE id=:id', ['id' => $eventId, 'reason' => $reason]);
    }

    public function markFailed(int $eventId, string $error): void
    {
        $this->db->query('UPDATE payment_gateway_events SET processing_status=\'failed\', processed_at=NOW(), error_message=:error WHERE id=:id', ['id' => $eventId, 'error' => mb_substr($error, 0, 4000)]);
    }
}

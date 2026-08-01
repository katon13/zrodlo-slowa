<?php
namespace App\Services;

use App\Contracts\QueueSignalInterface;
use App\Core\Database;

final class MailService
{
    public function __construct(
        private readonly Database $db,
        private readonly ?QueueSignalInterface $queueSignals = null,
    ) {}

    public function queue(
        ?int $userId,
        string $email,
        string $subject,
        string $body,
        int $maxAttempts = 5,
        ?string $idempotencyKey = null,
    ): int
    {
        $email = strtolower(trim($email));
        $subject = trim(str_replace(["\r", "\n"], ' ', $subject));
        $body = trim($body);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Nieprawidłowy adres odbiorcy wiadomości.');
        }
        if ($subject === '' || mb_strlen($subject) > 255) {
            throw new \InvalidArgumentException('Temat wiadomości jest pusty lub zbyt długi.');
        }
        if ($body === '' || strlen($body) > 1_000_000) {
            throw new \InvalidArgumentException('Treść wiadomości jest pusta lub zbyt długa.');
        }
        $maxAttempts = max(1, min(20, $maxAttempts));

        $idempotencyKey = $idempotencyKey !== null ? trim($idempotencyKey) : 'mail:' . bin2hex(random_bytes(16));
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 190) {
            throw new \InvalidArgumentException('Nieprawidłowy klucz idempotencji wiadomości.');
        }

        $sql =
            'INSERT INTO mail_queue(
                user_id,email,subject,body,idempotency_key,status,attempts,max_attempts,available_at,created_at,updated_at
             ) VALUES(:user,:email,:subject,:body,:idempotency_key,\'queued\',0,:max_attempts,NOW(),NOW(),NOW())';
        $params = [
            'user' => $userId !== null && $userId > 0 ? $userId : null,
            'email' => $email,
            'subject' => $subject,
            'body' => $body,
            'idempotency_key' => $idempotencyKey,
            'max_attempts' => $maxAttempts,
        ];
        if ($this->db->isPostgres()) {
            $this->db->insert($sql . ' ON CONFLICT(idempotency_key) DO UPDATE SET idempotency_key=EXCLUDED.idempotency_key', $params);
        } else {
            $this->db->insert($sql . ' ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)', $params);
        }
        $row = $this->db->one(
            'SELECT id,user_id,email,subject,body FROM mail_queue WHERE idempotency_key=:idempotency_key',
            ['idempotency_key' => $idempotencyKey]
        );
        $normalizedUserId = $userId !== null && $userId > 0 ? $userId : null;
        if ($row === null
            || (string)$row['email'] !== $email
            || (string)$row['subject'] !== $subject
            || (string)$row['body'] !== $body
            || ($row['user_id'] !== null ? (int)$row['user_id'] : null) !== $normalizedUserId
        ) {
            throw new \LogicException('Klucz idempotencji wiadomości został użyty dla innej treści.');
        }
        $id = (int)$row['id'];
        $this->queueSignals?->notify('email.transactional', (string)$id);
        return $id;
    }

    public function claimBatch(string $workerId, int $limit = 20): array
    {
        $workerId = $this->workerId($workerId);
        $limit = max(1, min(100, $limit));

        return $this->db->transaction(function (Database $db) use ($workerId, $limit): array {
            $this->recoverExpiredLeases();
            $candidates = $db->all(
                'SELECT id FROM mail_queue
                 WHERE status IN (\'queued\',\'retry\') AND available_at<=NOW()
                 ORDER BY available_at,id LIMIT ' . $limit . ' FOR UPDATE SKIP LOCKED'
            );
            $claimed = [];
            foreach ($candidates as $candidate) {
                $statement = $db->query(
                    'UPDATE mail_queue
                     SET status=\'sending\',attempts=attempts+1,locked_at=NOW(),locked_by=:worker,updated_at=NOW()
                     WHERE id=:id AND status IN (\'queued\',\'retry\') AND available_at<=NOW()',
                    ['worker' => $workerId, 'id' => (int)$candidate['id']]
                );
                if ($statement->rowCount() === 1) {
                    $claimed[] = (int)$candidate['id'];
                }
            }
            if ($claimed === []) {
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($claimed), '?'));
            return $db->query(
                "SELECT * FROM mail_queue WHERE id IN ({$placeholders}) AND locked_by=? ORDER BY id",
                [...$claimed, $workerId]
            )->fetchAll();
        });
    }

    public function markSent(int $id, string $workerId, ?string $messageId): void
    {
        $statement = $this->db->query(
            'UPDATE mail_queue
             SET status=\'sent\',message_id=:message_id,error=NULL,sent_at=NOW(),
                 locked_at=NULL,locked_by=NULL,updated_at=NOW()
             WHERE id=:id AND status=\'sending\' AND locked_by=:worker',
            [
                'message_id' => $messageId !== null ? mb_substr($messageId, 0, 255) : null,
                'id' => $id,
                'worker' => $this->workerId($workerId),
            ]
        );
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException("Worker utracił blokadę wiadomości #{$id}.");
        }
    }

    public function markFailed(int $id, string $workerId, \Throwable $error): string
    {
        return $this->db->transaction(function (Database $db) use ($id, $workerId, $error): string {
            $row = $db->one(
                'SELECT attempts,max_attempts FROM mail_queue
                 WHERE id=:id AND status=\'sending\' AND locked_by=:worker FOR UPDATE',
                ['id' => $id, 'worker' => $this->workerId($workerId)]
            );
            if ($row === null) {
                throw new \RuntimeException("Worker utracił blokadę wiadomości #{$id}.");
            }
            $attempts = (int)$row['attempts'];
            $terminal = $attempts >= (int)$row['max_attempts'];
            $status = $terminal ? 'dead_letter' : 'retry';
            $delay = min(3600, 30 * (2 ** max(0, $attempts - 1)));
            $db->query(
                'UPDATE mail_queue
                 SET status=:status,error=:error,
                     available_at=CASE WHEN :terminal_available=1 THEN available_at ELSE ' . $db->nowPlus($delay, 'second') . ' END,
                     failed_at=CASE WHEN :terminal_failed=1 THEN NOW() ELSE NULL END,
                     dead_lettered_at=CASE WHEN :terminal_dead=1 THEN NOW() ELSE NULL END,
                     locked_at=NULL,locked_by=NULL,updated_at=NOW()
                 WHERE id=:id',
                [
                    'status' => $status,
                    'error' => mb_substr($error->getMessage(), 0, 4000),
                    'terminal_available' => $terminal ? 1 : 0,
                    'terminal_failed' => $terminal ? 1 : 0,
                    'terminal_dead' => $terminal ? 1 : 0,
                    'id' => $id,
                ]
            );
            return $status;
        });
    }

    /** @return array{retry:int,dead_letter:int} */
    public function recoverExpiredLeases(): array
    {
        return $this->db->transaction(function (Database $db): array {
            $rows = $db->all(
                'SELECT id,attempts,max_attempts FROM mail_queue
                 WHERE status=\'sending\' AND locked_at < ' . $db->nowMinus(15, 'minute') . '
                 FOR UPDATE SKIP LOCKED'
            );
            $result = ['retry' => 0, 'dead_letter' => 0];
            foreach ($rows as $row) {
                $terminal = (int)$row['attempts'] >= (int)$row['max_attempts'];
                $status = $terminal ? 'dead_letter' : 'retry';
                $db->query(
                    'UPDATE mail_queue SET status=:status,locked_at=NULL,locked_by=NULL,
                        available_at=NOW(),error=:error,updated_at=NOW(),
                        failed_at=CASE WHEN :dead=1 THEN NOW() ELSE failed_at END,
                        dead_lettered_at=CASE WHEN :dead_again=1 THEN NOW() ELSE dead_lettered_at END
                     WHERE id=:id',
                    [
                        'status' => $status,
                        'error' => 'Wygasła dzierżawa workera poczty.',
                        'dead' => $terminal ? 1 : 0,
                        'dead_again' => $terminal ? 1 : 0,
                        'id' => (int)$row['id'],
                    ]
                );
                $result[$status]++;
            }
            return $result;
        });
    }

    public function latest(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        return $this->db->all('SELECT * FROM mail_queue ORDER BY created_at DESC,id DESC LIMIT ' . $limit);
    }

    public function recent(int $limit = 100): array
    {
        return $this->latest($limit);
    }

    public function statistics(): array
    {
        $rows = $this->db->all('SELECT status,COUNT(*) AS total FROM mail_queue GROUP BY status');
        $result = [];
        foreach ($rows as $row) {
            $result[(string)$row['status']] = (int)$row['total'];
        }
        return $result;
    }

    private function workerId(string $workerId): string
    {
        $workerId = trim($workerId);
        if ($workerId === '' || strlen($workerId) > 100 || preg_match('/[^A-Za-z0-9_.:@-]/', $workerId) === 1) {
            throw new \InvalidArgumentException('Nieprawidłowy identyfikator workera poczty.');
        }
        return $workerId;
    }
}

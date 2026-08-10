<?php
declare(strict_types=1);

namespace App\Infrastructure\Session;

use App\Contracts\ValkeyClientInterface;
use App\Core\Database;

final class SharedSessionHandler implements \SessionHandlerInterface, \SessionUpdateTimestampHandlerInterface
{
    /** @var array<string, string> */
    private array $prefetched = [];

    public function __construct(
        private readonly ?ValkeyClientInterface $valkey,
        private readonly Database $database,
        private readonly int $ttlSeconds,
        private readonly bool $readOnly = false,
    ) {}

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string
    {
        if (array_key_exists($id, $this->prefetched)) {
            $payload = $this->prefetched[$id];
            unset($this->prefetched[$id]);
            return $payload;
        }
        try {
            $payload = $this->valkey?->get($this->key($id));
            if ($payload !== null) {
                return $payload;
            }
        } catch (\Throwable $error) {
            error_log('Valkey session read failed; PostgreSQL fallback enabled: ' . $error->getMessage());
        }

        try {
            $payload = $this->database->cell(
                'SELECT payload FROM sessions WHERE id=:id AND last_activity>=:minimum',
                ['id' => $id, 'minimum' => time() - $this->ttl()]
            );
            if (!is_string($payload)) {
                return '';
            }
            if (!$this->readOnly) {
                try {
                    $this->valkey?->set($this->key($id), $payload, $this->ttl());
                } catch (\Throwable) {
                    // PostgreSQL pozostaje bezpiecznym, współdzielonym fallbackiem.
                }
            }
            return $payload;
        } catch (\Throwable $error) {
            error_log('PostgreSQL session fallback read failed: ' . $error->getMessage());
            return '';
        }
    }

    public function write(string $id, string $data): bool
    {
        if ($this->readOnly) {
            return true;
        }
        $valkeyWritten = false;
        try {
            if ($this->valkey !== null) {
                $valkeyWritten = $this->valkey->set($this->key($id), $data, $this->ttl());
            }
        } catch (\Throwable $error) {
            error_log('Valkey session write failed; PostgreSQL fallback enabled: ' . $error->getMessage());
        }

        $databaseWritten = false;
        try {
            $parameters = [
                'id' => $id,
                'user_id' => $this->currentUserId(),
                'payload' => $data,
                'last_activity' => time(),
            ];
            if ($this->database->isPostgres()) {
                $this->database->query(
                    'INSERT INTO sessions(id,user_id,payload,last_activity)
                     VALUES(:id,:user_id,:payload,:last_activity)
                     ON CONFLICT (id) DO UPDATE SET
                        user_id=EXCLUDED.user_id,
                        payload=EXCLUDED.payload,
                        last_activity=EXCLUDED.last_activity',
                    $parameters
                );
            } else {
                $this->database->query(
                    'INSERT INTO sessions(id,user_id,payload,last_activity)
                     VALUES(:id,:user_id,:payload,:last_activity)
                     ON DUPLICATE KEY UPDATE
                        user_id=VALUES(user_id),
                        payload=VALUES(payload),
                        last_activity=VALUES(last_activity)',
                    $parameters
                );
            }
            $databaseWritten = true;
        } catch (\Throwable $error) {
            error_log('PostgreSQL session shadow write failed: ' . $error->getMessage());
        }

        return $valkeyWritten || $databaseWritten;
    }

    public function destroy(string $id): bool
    {
        if ($this->readOnly) {
            return true;
        }
        $ok = true;
        try {
            $this->valkey?->delete($this->key($id));
        } catch (\Throwable $error) {
            $ok = false;
            error_log('Valkey session destroy failed: ' . $error->getMessage());
        }
        try {
            $this->deleteDatabaseRow($id);
        } catch (\Throwable $error) {
            $ok = false;
            error_log('PostgreSQL session fallback destroy failed: ' . $error->getMessage());
        }
        unset($this->prefetched[$id]);
        return $ok;
    }

    public function gc(int $max_lifetime): int|false
    {
        if ($this->readOnly) {
            return 0;
        }
        try {
            return $this->database->query(
                'DELETE FROM sessions WHERE last_activity<:minimum',
                ['minimum' => time() - max(60, $max_lifetime)]
            )->rowCount();
        } catch (\Throwable $error) {
            error_log('PostgreSQL session garbage collection failed: ' . $error->getMessage());
            return false;
        }
    }

    public function validateId(string $id): bool
    {
        try {
            $payload = $this->valkey?->get($this->key($id));
            if ($payload !== null) {
                $this->prefetched[$id] = $payload;
                return true;
            }
        } catch (\Throwable) {
            // Walidacja przechodzi do współdzielonego fallbacku PostgreSQL.
        }
        try {
            $payload = $this->database->cell(
                'SELECT payload FROM sessions WHERE id=:id AND last_activity>=:minimum',
                ['id' => $id, 'minimum' => time() - $this->ttl()]
            );
            if (!is_string($payload)) {
                return false;
            }
            $this->prefetched[$id] = $payload;
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        if ($this->readOnly) {
            return true;
        }
        return $this->write($id, $data);
    }

    private function deleteDatabaseRow(string $id): void
    {
        $this->database->query('DELETE FROM sessions WHERE id=:id', ['id' => $id]);
    }

    private function currentUserId(): ?int
    {
        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        return $userId > 0 ? $userId : null;
    }

    private function key(string $id): string
    {
        return 'session:v1:' . hash('sha256', $id);
    }

    private function ttl(): int
    {
        return max(300, $this->ttlSeconds);
    }
}

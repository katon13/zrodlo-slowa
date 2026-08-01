<?php
declare(strict_types=1);

namespace App\Infrastructure\Cache;

use App\Contracts\CacheStoreInterface;
use App\Contracts\ValkeyClientInterface;

final class ValkeyCacheStore implements CacheStoreInterface
{
    private const FORMAT_VERSION = 1;
    private const KEY_VERSION = 'v1';
    private ?int $globalVersion = null;
    /** @var array<string, int> */
    private array $groupVersions = [];

    public function __construct(private readonly ValkeyClientInterface $client) {}

    public function get(string $key): array
    {
        $storageKey = $this->storageKey($key);
        $payload = $this->client->get($storageKey);
        if ($payload === null) {
            return ['hit' => false, 'value' => null];
        }
        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->client->delete($storageKey);
            return ['hit' => false, 'value' => null];
        }
        if (!is_array($decoded) || ($decoded['format'] ?? null) !== self::FORMAT_VERSION) {
            $this->client->delete($storageKey);
            return ['hit' => false, 'value' => null];
        }
        return ['hit' => true, 'value' => $decoded['value'] ?? null];
    }

    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        $payload = json_encode(
            ['format' => self::FORMAT_VERSION, 'value' => $value],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        if (!$this->client->set($this->storageKey($key), $payload, max(1, $ttlSeconds))) {
            throw new \RuntimeException('Valkey odrzucił zapis cache.');
        }
    }

    public function forget(string $key): void
    {
        $this->client->delete($this->storageKey($key));
    }

    public function flushGroup(string $group): void
    {
        $group = trim($group);
        if ($group !== '') {
            $this->groupVersions[$group] = $this->client->increment($this->groupVersionKey($group));
        }
    }

    public function flushAll(): void
    {
        $this->globalVersion = $this->client->increment($this->globalVersionKey());
        $this->groupVersions = [];
    }

    private function storageKey(string $key): string
    {
        $globalVersion = $this->globalVersion
            ??= (int)($this->client->get($this->globalVersionKey()) ?? '0');
        $group = $this->group($key);
        $groupVersion = $group !== null
            ? ($this->groupVersions[$group]
                ??= (int)($this->client->get($this->groupVersionKey($group)) ?? '0'))
            : 0;
        return sprintf(
            'cache:%s:g%d:c%d:%s',
            self::KEY_VERSION,
            $globalVersion,
            $groupVersion,
            hash('sha256', $key)
        );
    }

    private function globalVersionKey(): string
    {
        return 'cache:' . self::KEY_VERSION . ':generation:global';
    }

    private function groupVersionKey(string $group): string
    {
        return 'cache:' . self::KEY_VERSION . ':generation:group:' . hash('sha256', $group);
    }

    private function group(string $key): ?string
    {
        $separator = strpos($key, ':');
        return $separator === false ? null : substr($key, 0, $separator);
    }
}

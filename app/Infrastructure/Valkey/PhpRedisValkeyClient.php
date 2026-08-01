<?php
declare(strict_types=1);

namespace App\Infrastructure\Valkey;

use App\Contracts\ValkeyClientInterface;

final class PhpRedisValkeyClient implements ValkeyClientInterface
{
    public function __construct(private readonly \Redis $client) {}

    public static function connect(array $config): self
    {
        if (!extension_loaded('redis') || !class_exists(\Redis::class)) {
            throw new \RuntimeException('Rozszerzenie PHP redis nie jest dostępne.');
        }

        $host = trim((string)($config['host'] ?? '127.0.0.1'));
        $port = (int)($config['port'] ?? 6379);
        $timeout = max(0.05, (float)($config['connect_timeout'] ?? 0.5));
        $readTimeout = max(0.05, (float)($config['read_timeout'] ?? 0.5));
        $persistentId = trim((string)($config['persistent_id'] ?? 'zrodlo-slowa'));
        if ((bool)($config['tls'] ?? false)) {
            $host = 'tls://' . preg_replace('#^tls://#', '', $host);
        }

        $redis = new \Redis();
        // A niedostępność Valkey jest oczekiwanym stanem przełączenia awaryjnego.
        // Tłumimy ostrzeżenie warstwy sieciowej i zamieniamy wynik na kontrolowany wyjątek.
        if (!@$redis->pconnect($host, $port, $timeout, $persistentId, 100, $readTimeout)) {
            throw new \RuntimeException('Nie udało się połączyć z Valkey.');
        }

        $password = (string)($config['password'] ?? '');
        if ($password !== '' && !$redis->auth($password)) {
            throw new \RuntimeException('Valkey odrzucił uwierzytelnienie.');
        }
        $database = max(0, (int)($config['database'] ?? 0));
        if ($database > 0 && !$redis->select($database)) {
            throw new \RuntimeException('Nie udało się wybrać bazy Valkey.');
        }

        $prefix = trim((string)($config['prefix'] ?? 'zrodlo-slowa'));
        $prefix = preg_replace('/[^A-Za-z0-9_.:-]+/', '-', $prefix) ?: 'zrodlo-slowa';
        $redis->setOption(\Redis::OPT_PREFIX, rtrim($prefix, ':') . ':');
        $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_NONE);

        return new self($redis);
    }

    public function ping(): bool
    {
        $response = $this->client->ping();
        return $response === true || strtoupper((string)$response) === 'PONG';
    }

    public function get(string $key): ?string
    {
        $value = $this->client->get($key);
        return is_string($value) ? $value : null;
    }

    public function set(string $key, string $value, int $ttlSeconds = 0): bool
    {
        if ($ttlSeconds > 0) {
            return $this->client->set($key, $value, ['ex' => $ttlSeconds]) === true;
        }
        return $this->client->set($key, $value) === true;
    }

    public function setIfAbsent(string $key, string $value, int $ttlMilliseconds): bool
    {
        return $this->client->set(
            $key,
            $value,
            ['nx', 'px' => max(1, $ttlMilliseconds)]
        ) === true;
    }

    public function delete(string $key): bool
    {
        return $this->client->del($key) > 0;
    }

    public function increment(string $key, int $amount = 1, int $ttlSeconds = 0): int
    {
        $script = <<<'LUA'
local current = redis.call('INCRBY', KEYS[1], ARGV[1])
if current == tonumber(ARGV[1]) and tonumber(ARGV[2]) > 0 then
    redis.call('EXPIRE', KEYS[1], ARGV[2])
end
return current
LUA;
        return (int)$this->client->eval($script, [$key, $amount, max(0, $ttlSeconds)], 1);
    }

    public function compareAndDelete(string $key, string $expectedValue): bool
    {
        $script = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
    return redis.call('DEL', KEYS[1])
end
return 0
LUA;
        return (int)$this->client->eval($script, [$key, $expectedValue], 1) === 1;
    }

    public function pushSignal(string $key, string $payload, int $maxLength, int $ttlSeconds): int
    {
        $script = <<<'LUA'
local length = redis.call('LPUSH', KEYS[1], ARGV[1])
redis.call('LTRIM', KEYS[1], 0, tonumber(ARGV[2]) - 1)
redis.call('EXPIRE', KEYS[1], tonumber(ARGV[3]))
return length
LUA;
        return (int)$this->client->eval(
            $script,
            [$key, $payload, max(1, $maxLength), max(1, $ttlSeconds)],
            1
        );
    }

    public function popSignal(string $key): ?string
    {
        $value = $this->client->rPop($key);
        return is_string($value) ? $value : null;
    }

    public function blockingPopSignal(string $key, int $timeoutSeconds): ?string
    {
        $timeoutSeconds = max(1, min(300, $timeoutSeconds));
        $previousReadTimeout = $this->client->getOption(\Redis::OPT_READ_TIMEOUT);
        $this->client->setOption(\Redis::OPT_READ_TIMEOUT, (float)$timeoutSeconds + 2.0);
        try {
            $value = $this->client->brPop([$key], $timeoutSeconds);
        } finally {
            $this->client->setOption(\Redis::OPT_READ_TIMEOUT, $previousReadTimeout);
        }
        if (!is_array($value) || count($value) < 2) {
            return null;
        }
        $payload = $value[1] ?? null;
        return is_string($payload) ? $payload : null;
    }
}

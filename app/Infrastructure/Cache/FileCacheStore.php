<?php
declare(strict_types=1);

namespace App\Infrastructure\Cache;

use App\Contracts\CacheStoreInterface;

final class FileCacheStore implements CacheStoreInterface
{
    private readonly string $cacheDirectory;
    private bool $available = true;

    public function __construct(string $basePath)
    {
        $preferred = rtrim($basePath, '/\\')
            . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . 'cache'
            . DIRECTORY_SEPARATOR . 'site';
        if ($this->prepareDirectory($preferred)) {
            $this->cacheDirectory = $preferred;
            return;
        }

        $fallback = rtrim(sys_get_temp_dir(), '/\\')
            . DIRECTORY_SEPARATOR . 'zrodlo-slowa'
            . DIRECTORY_SEPARATOR . 'cache'
            . DIRECTORY_SEPARATOR . hash('sha256', rtrim($basePath, '/\\'));
        $this->cacheDirectory = $fallback;
        $this->available = $this->prepareDirectory($fallback);
    }

    public function get(string $key): array
    {
        if (!$this->available) {
            return ['hit' => false, 'value' => null];
        }
        $path = $this->path($key);
        $entry = $this->readPath($path);
        if ($entry === null || (int)($entry['expires_at'] ?? 0) <= time()) {
            if ($entry !== null) {
                $this->forget($key);
            }
            return ['hit' => false, 'value' => null];
        }
        return ['hit' => true, 'value' => $entry['value'] ?? null];
    }

    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        if (!$this->available) {
            return;
        }
        $payload = json_encode([
            'format' => 1,
            'expires_at' => time() + max(1, $ttlSeconds),
            'value' => $value,
            'group' => $this->group($key),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $path = $this->path($key);
        $this->withLock($path, LOCK_EX, function () use ($path, $payload): void {
            $temporary = tempnam($this->cacheDirectory, 'cache_tmp_');
            if ($temporary === false) {
                throw new \RuntimeException('Nie udało się utworzyć pliku tymczasowego cache.');
            }
            try {
                $bytes = file_put_contents($temporary, $payload, LOCK_EX);
                if ($bytes !== strlen($payload)) {
                    throw new \RuntimeException('Nie udało się zapisać całego wpisu cache.');
                }
                if (!@rename($temporary, $path)) {
                    if (is_file($path) && !@unlink($path)) {
                        throw new \RuntimeException('Nie udało się zastąpić wpisu cache.');
                    }
                    if (!@rename($temporary, $path)) {
                        throw new \RuntimeException('Nie udało się atomowo zapisać cache.');
                    }
                }
            } finally {
                if (is_file($temporary)) {
                    @unlink($temporary);
                }
            }
        });
    }

    public function forget(string $key): void
    {
        if (!$this->available) {
            return;
        }
        $path = $this->path($key);
        $this->withLock($path, LOCK_EX, static function () use ($path): void {
            if (is_file($path)) {
                @unlink($path);
            }
        });
    }

    public function flushGroup(string $group): void
    {
        if (!$this->available || trim($group) === '') {
            return;
        }
        foreach ($this->files() as $path) {
            $entry = $this->readPath($path);
            if (($entry['group'] ?? null) === $group) {
                $this->withLock($path, LOCK_EX, static function () use ($path): void {
                    if (is_file($path)) {
                        @unlink($path);
                    }
                });
            }
        }
    }

    public function flushAll(): void
    {
        if (!$this->available) {
            return;
        }
        foreach ($this->files() as $path) {
            $this->withLock($path, LOCK_EX, static function () use ($path): void {
                if (is_file($path)) {
                    @unlink($path);
                }
            });
        }
    }

    private function readPath(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        try {
            $entry = $this->withLock($path, LOCK_SH, static function () use ($path): ?array {
                $payload = file_get_contents($path);
                if (!is_string($payload) || $payload === '') {
                    return null;
                }
                $decoded = json_decode($payload, true);
                return is_array($decoded) && ($decoded['format'] ?? null) === 1
                    ? $decoded
                    : null;
            });
            if ($entry === null) {
                @unlink($path);
            }
            return $entry;
        } catch (\Throwable) {
            @unlink($path);
            return null;
        }
    }

    private function withLock(string $path, int $operation, callable $callback): mixed
    {
        $handle = @fopen($path . '.lock', 'c+b');
        if ($handle === false) {
            throw new \RuntimeException('Nie udało się otworzyć blokady cache.');
        }
        try {
            if (!flock($handle, $operation)) {
                throw new \RuntimeException('Nie udało się zablokować wpisu cache.');
            }
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function files(): array
    {
        return glob($this->cacheDirectory . DIRECTORY_SEPARATOR . 'cache_*.json') ?: [];
    }

    private function path(string $key): string
    {
        return $this->cacheDirectory . DIRECTORY_SEPARATOR . 'cache_' . hash('sha256', $key) . '.json';
    }

    private function group(string $key): ?string
    {
        $separator = strpos($key, ':');
        return $separator === false ? null : substr($key, 0, $separator);
    }

    private function prepareDirectory(string $directory): bool
    {
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            return false;
        }
        $probe = @tempnam($directory, 'zs_cache_probe_');
        if ($probe === false) {
            return false;
        }
        $valid = realpath($directory) === realpath(dirname($probe));
        @unlink($probe);
        return $valid;
    }
}

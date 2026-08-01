<?php
declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\ObjectStorageInterface;
use App\Contracts\StoredObject;

final class InMemoryObjectStorage implements ObjectStorageInterface
{
    /** @var array<string, StoredObject> */
    private array $objects = [];

    public function putFile(string $objectKey, string $sourcePath, string $contentType): string
    {
        $contents = file_get_contents($sourcePath);
        if (!is_string($contents)) {
            throw new \RuntimeException('Brak pliku testowego.');
        }
        $reference = '/objects/' . rtrim(strtr(base64_encode($objectKey), '+/', '-_'), '=');
        $this->objects[$reference] = new StoredObject(
            $contents,
            $contentType,
            strlen($contents),
            '"' . hash('sha256', $contents) . '"',
        );
        return $reference;
    }

    public function read(string $reference): StoredObject
    {
        return $this->objects[$reference] ?? throw new \RuntimeException('Brak obiektu testowego.');
    }

    public function exists(string $reference): bool
    {
        return isset($this->objects[$reference]);
    }

    public function delete(string $reference): void
    {
        unset($this->objects[$reference]);
    }

    public function isPublicReference(string $reference): bool
    {
        $path = (string)(parse_url($reference, PHP_URL_PATH) ?? '');
        $token = str_starts_with($path, '/objects/') ? substr($path, strlen('/objects/')) : '';
        $padding = (4 - strlen($token) % 4) % 4;
        $key = base64_decode(strtr($token, '-_', '+/') . str_repeat('=', $padding), true);
        return is_string($key) && str_starts_with($key, 'public/');
    }

    public function healthCheck(): bool
    {
        return true;
    }
}

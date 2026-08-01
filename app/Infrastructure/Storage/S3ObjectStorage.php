<?php
declare(strict_types=1);

namespace App\Infrastructure\Storage;

use App\Contracts\ObjectStorageInterface;
use App\Contracts\StoredObject;
use Aws\Exception\AwsException;
use Aws\S3\S3ClientInterface;

final class S3ObjectStorage implements ObjectStorageInterface
{
    public function __construct(
        private readonly S3ClientInterface $client,
        private readonly string $bucket,
        private readonly string $referencePrefix = '/objects',
        private readonly int $maxReadBytes = 10_485_760,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/D', $bucket) !== 1) {
            throw new \InvalidArgumentException('Nieprawidłowa nazwa bucketu S3.');
        }
    }

    public function putFile(string $objectKey, string $sourcePath, string $contentType): string
    {
        $objectKey = $this->safeObjectKey($objectKey);
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new \RuntimeException('Brak czytelnego pliku źródłowego do zapisu.');
        }
        try {
            $parameters = [
                'Bucket' => $this->bucket,
                'Key' => $objectKey,
                'SourceFile' => $sourcePath,
                'ContentType' => $this->safeContentType($contentType),
                'Metadata' => [
                    'sha256' => hash_file('sha256', $sourcePath) ?: '',
                    'visibility' => str_starts_with($objectKey, 'public/') ? 'public' : 'private',
                ],
                'CacheControl' => str_starts_with($objectKey, 'public/')
                    ? 'public, max-age=31536000, immutable'
                    : 'private, no-store',
            ];
            $this->client->putObject($parameters);
        } catch (AwsException $error) {
            throw $this->storageError('zapisać', $error);
        }
        return $this->referenceForKey($objectKey);
    }

    public function read(string $reference): StoredObject
    {
        $objectKey = $this->keyFromReference($reference);
        try {
            $result = $this->client->getObject([
                'Bucket' => $this->bucket,
                'Key' => $objectKey,
            ]);
            $declaredLength = max(0, (int)($result['ContentLength'] ?? 0));
            if ($declaredLength > $this->maxReadBytes) {
                throw new \RuntimeException('Obiekt przekracza limit bezpiecznego odczytu.');
            }
            $contents = (string)$result['Body'];
            if (strlen($contents) > $this->maxReadBytes) {
                throw new \RuntimeException('Obiekt przekracza limit bezpiecznego odczytu.');
            }
            $lastModified = $result['LastModified'] ?? null;
            return new StoredObject(
                $contents,
                $this->safeContentType((string)($result['ContentType'] ?? 'application/octet-stream')),
                strlen($contents),
                isset($result['ETag']) ? (string)$result['ETag'] : null,
                $lastModified instanceof \DateTimeInterface ? $lastModified->format(DATE_RFC7231) : null,
            );
        } catch (AwsException $error) {
            throw $this->storageError('odczytać', $error);
        }
    }

    public function exists(string $reference): bool
    {
        $objectKey = $this->keyFromReference($reference);
        try {
            $this->client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $objectKey,
            ]);
            return true;
        } catch (AwsException $error) {
            if ($this->isNotFound($error)) {
                return false;
            }
            throw $this->storageError('sprawdzić', $error);
        }
    }

    public function delete(string $reference): void
    {
        $objectKey = $this->keyFromReference($reference);
        try {
            $this->client->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $objectKey,
            ]);
        } catch (AwsException $error) {
            throw $this->storageError('usunąć', $error);
        }
    }

    public function isPublicReference(string $reference): bool
    {
        try {
            return str_starts_with($this->keyFromReference($reference), 'public/');
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    public function healthCheck(): bool
    {
        try {
            $this->client->headBucket(['Bucket' => $this->bucket]);
            return true;
        } catch (AwsException) {
            return false;
        }
    }

    private function referenceForKey(string $objectKey): string
    {
        $token = rtrim(strtr(base64_encode($objectKey), '+/', '-_'), '=');
        return rtrim($this->referencePrefix, '/') . '/' . $token;
    }

    private function keyFromReference(string $reference): string
    {
        $path = (string)(parse_url($reference, PHP_URL_PATH) ?? '');
        $prefix = rtrim($this->referencePrefix, '/') . '/';
        if (!str_starts_with($path, $prefix)) {
            throw new \InvalidArgumentException('Odwołanie nie należy do magazynu S3.');
        }
        $token = substr($path, strlen($prefix));
        if ($token === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $token) !== 1) {
            throw new \InvalidArgumentException('Nieprawidłowe odwołanie do obiektu S3.');
        }
        $padding = (4 - strlen($token) % 4) % 4;
        $decoded = base64_decode(strtr($token, '-_', '+/') . str_repeat('=', $padding), true);
        if (!is_string($decoded)) {
            throw new \InvalidArgumentException('Nieprawidłowe odwołanie do obiektu S3.');
        }
        return $this->safeObjectKey($decoded);
    }

    private function safeObjectKey(string $objectKey): string
    {
        $objectKey = str_replace('\\', '/', trim($objectKey, '/'));
        if (
            $objectKey === ''
            || strlen($objectKey) > 900
            || str_contains($objectKey, '..')
            || preg_match('~^[A-Za-z0-9][A-Za-z0-9._/-]*$~D', $objectKey) !== 1
        ) {
            throw new \InvalidArgumentException('Nieprawidłowy klucz obiektu.');
        }
        return $objectKey;
    }

    private function safeContentType(string $contentType): string
    {
        $contentType = strtolower(trim(explode(';', $contentType, 2)[0]));
        if (preg_match('~^[a-z0-9][a-z0-9!#$&^_.+-]*/[a-z0-9][a-z0-9!#$&^_.+-]*$~D', $contentType) !== 1) {
            return 'application/octet-stream';
        }
        return $contentType;
    }

    private function isNotFound(AwsException $error): bool
    {
        return $error->getStatusCode() === 404
            || in_array((string)$error->getAwsErrorCode(), ['NoSuchKey', 'NotFound'], true);
    }

    private function storageError(string $operation, AwsException $error): \RuntimeException
    {
        $requestId = (string)($error->getAwsRequestId() ?? '');
        $suffix = $requestId !== '' ? ' Request ID: ' . $requestId . '.' : '';
        return new \RuntimeException('Nie udało się ' . $operation . ' obiektu S3.' . $suffix, 0, $error);
    }
}

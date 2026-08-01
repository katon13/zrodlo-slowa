<?php
declare(strict_types=1);

namespace App\Services;

use App\Contracts\ObjectStorageInterface;

final class PublicObjectResponder
{
    private const PUBLIC_CONTENT_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'image/avif',
    ];

    public function __construct(private readonly ObjectStorageInterface $storage) {}

    public function send(string $token): never
    {
        $reference = '/objects/' . $token;
        if ($token === '' || !$this->storage->isPublicReference($reference)) {
            $this->finish(404);
        }

        try {
            if (!$this->storage->exists($reference)) {
                $this->finish(404);
            }
            $object = $this->storage->read($reference);
        } catch (\Throwable $error) {
            error_log('Object storage public read failed: ' . $error->getMessage());
            header('Retry-After: 5');
            $this->finish(503);
        }

        if (!in_array($object->contentType, self::PUBLIC_CONTENT_TYPES, true)) {
            $this->finish(415);
        }

        $instanceId = trim((string)(getenv('APP_INSTANCE_ID') ?: 'unknown'));
        header('X-App-Instance: ' . preg_replace('/[^A-Za-z0-9_.-]/', '-', $instanceId));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=31536000, immutable');

        if ($object->etag !== null) {
            header('ETag: ' . $object->etag);
            $ifNoneMatch = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
            if ($ifNoneMatch !== '' && hash_equals($object->etag, $ifNoneMatch)) {
                $this->finish(304, false);
            }
        }
        if ($object->lastModified !== null) {
            header('Last-Modified: ' . $object->lastModified);
        }
        header('Content-Type: ' . $object->contentType);
        header('Content-Length: ' . $object->contentLength);
        echo $object->contents;
        exit;
    }

    private function finish(int $status, bool $noStore = true): never
    {
        http_response_code($status);
        if ($noStore) {
            header('Cache-Control: no-store');
        }
        exit;
    }
}

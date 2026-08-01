<?php
declare(strict_types=1);

namespace App\Contracts;

interface ObjectStorageInterface
{
    /**
     * Stores a local file and returns its application-visible reference.
     */
    public function putFile(string $objectKey, string $sourcePath, string $contentType): string;

    public function read(string $reference): StoredObject;

    public function exists(string $reference): bool;

    public function delete(string $reference): void;

    public function isPublicReference(string $reference): bool;

    public function healthCheck(): bool;
}

<?php
declare(strict_types=1);

namespace App\Contracts;

final class StoredObject
{
    public function __construct(
        public readonly string $contents,
        public readonly string $contentType,
        public readonly int $contentLength,
        public readonly ?string $etag = null,
        public readonly ?string $lastModified = null,
    ) {}
}

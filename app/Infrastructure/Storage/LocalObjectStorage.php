<?php
declare(strict_types=1);

namespace App\Infrastructure\Storage;

use App\Contracts\ObjectStorageInterface;
use App\Contracts\StoredObject;

final class LocalObjectStorage implements ObjectStorageInterface
{
    public function __construct(
        private readonly string $rootDirectory,
        private readonly string $publicPrefix,
    ) {}

    public function putFile(string $objectKey, string $sourcePath, string $contentType): string
    {
        $objectKey = $this->safeObjectKey($objectKey);
        if (!str_starts_with($objectKey, 'public/')) {
            throw new \InvalidArgumentException('Lokalny adapter obsługuje wyłącznie publiczne uploady.');
        }
        if (!is_file($sourcePath)) {
            throw new \RuntimeException('Brak pliku źródłowego do zapisu.');
        }
        if (
            !is_dir($this->rootDirectory)
            && !mkdir($this->rootDirectory, 0755, true)
            && !is_dir($this->rootDirectory)
        ) {
            throw new \RuntimeException('Nie udało się utworzyć katalogu obiektów.');
        }

        $diskKey = substr($objectKey, strlen('public/'));
        $destination = rtrim($this->rootDirectory, '/\\')
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $diskKey);
        $destinationDirectory = dirname($destination);
        if (
            !is_dir($destinationDirectory)
            && !mkdir($destinationDirectory, 0755, true)
            && !is_dir($destinationDirectory)
        ) {
            throw new \RuntimeException('Nie udało się utworzyć katalogu obiektu.');
        }
        if (!copy($sourcePath, $destination)) {
            throw new \RuntimeException('Nie udało się zapisać obiektu.');
        }
        return rtrim($this->publicPrefix, '/') . '/' . $diskKey;
    }

    public function read(string $reference): StoredObject
    {
        $path = $this->pathFromReference($reference);
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException('Obiekt nie istnieje.');
        }
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new \RuntimeException('Nie udało się odczytać obiektu.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $mtime = filemtime($path);
        return new StoredObject(
            $contents,
            is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream',
            strlen($contents),
            '"' . hash('sha256', $contents) . '"',
            $mtime !== false ? gmdate(DATE_RFC7231, $mtime) : null,
        );
    }

    public function exists(string $reference): bool
    {
        try {
            return is_file($this->pathFromReference($reference));
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    public function delete(string $reference): void
    {
        $path = $this->pathFromReference($reference);
        if (is_file($path) && !unlink($path)) {
            throw new \RuntimeException('Nie udało się usunąć obiektu.');
        }
    }

    public function isPublicReference(string $reference): bool
    {
        try {
            $this->pathFromReference($reference);
            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    public function healthCheck(): bool
    {
        if (
            !is_dir($this->rootDirectory)
            && !@mkdir($this->rootDirectory, 0755, true)
            && !is_dir($this->rootDirectory)
        ) {
            return false;
        }
        return is_readable($this->rootDirectory) && is_writable($this->rootDirectory);
    }

    private function safeObjectKey(string $objectKey): string
    {
        $objectKey = str_replace('\\', '/', trim($objectKey, '/'));
        if (
            $objectKey === ''
            || str_contains($objectKey, '..')
            || preg_match('~^[A-Za-z0-9][A-Za-z0-9._/-]*$~D', $objectKey) !== 1
        ) {
            throw new \InvalidArgumentException('Nieprawidłowy klucz obiektu.');
        }
        return $objectKey;
    }

    private function pathFromReference(string $reference): string
    {
        $path = (string)(parse_url($reference, PHP_URL_PATH) ?? '');
        $prefix = rtrim($this->publicPrefix, '/') . '/';
        if (!str_starts_with($path, $prefix)) {
            throw new \InvalidArgumentException('Odwołanie nie należy do tego magazynu obiektów.');
        }
        $diskKey = $this->safeObjectKey(substr($path, strlen($prefix)));
        return rtrim($this->rootDirectory, '/\\')
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $diskKey);
    }
}

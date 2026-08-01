<?php
declare(strict_types=1);

namespace App\Services;

use App\Contracts\ObjectStorageInterface;
use App\Core\Database;

final class LocalUploadMigrationService
{
    private const MIME_BY_EXTENSION = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    public function __construct(
        private readonly Database $database,
        private readonly ObjectStorageInterface $storage,
        private readonly string $rootPath,
        private readonly int $maxBytes = 26_214_400,
    ) {}

    public function migrate(bool $apply, bool $deleteSource = false): array
    {
        if ($deleteSource && !$apply) {
            throw new \InvalidArgumentException('--delete-source wymaga trybu --apply.');
        }

        $rows = $this->references();
        $report = [
            'mode' => $apply ? 'apply' : 'dry-run',
            'scanned' => count($rows),
            'pending' => 0,
            'migrated' => 0,
            'missing' => 0,
            'invalid' => 0,
            'failed' => 0,
            'verified' => 0,
            'deleted_sources' => 0,
            'errors' => [],
            'manifest' => [],
        ];
        $sourceTotals = [];
        $sourceSuccesses = [];

        foreach ($rows as $row) {
            $sourceReference = (string)$row['reference'];
            try {
                $sourcePath = $this->sourcePath($sourceReference);
                $sourceTotals[$sourcePath] = ($sourceTotals[$sourcePath] ?? 0) + 1;
                if (!is_file($sourcePath) || !is_readable($sourcePath)) {
                    $report['missing']++;
                    $report['errors'][] = $this->rowLabel($row) . ': brak czytelnego pliku źródłowego';
                    continue;
                }
                $mime = $this->validatedMime($sourcePath);
                $sourceHash = hash_file('sha256', $sourcePath);
                $sourceSize = filesize($sourcePath);
                if (!is_string($sourceHash) || $sourceSize === false) {
                    throw new \RuntimeException('Nie udało się obliczyć metadanych pliku źródłowego.');
                }
                $objectKey = $this->migrationObjectKey($row, $sourcePath);
                $manifestIndex = count($report['manifest']);
                $report['manifest'][] = [
                    'source_reference' => $sourceReference,
                    'source_path' => str_replace('\\', '/', $sourcePath),
                    'size_bytes' => $sourceSize,
                    'sha256' => $sourceHash,
                    'target_object_key' => $objectKey,
                    'resource_type' => (string)$row['table'],
                    'record_id' => (int)$row['id'],
                    'content_type' => $mime,
                    'target_reference' => null,
                    'read_verified' => false,
                ];
                if (!$apply) {
                    $report['pending']++;
                    continue;
                }

                $reference = $this->storage->putFile($objectKey, $sourcePath, $mime);
                $stored = $this->storage->read($reference);
                if (
                    strlen($reference) > 255
                    || !$this->storage->exists($reference)
                    || $stored->contentLength !== $sourceSize
                    || !hash_equals($sourceHash, hash('sha256', $stored->contents))
                    || $stored->contentType !== $mime
                ) {
                    $this->deleteQuietly($reference);
                    throw new \RuntimeException('Weryfikacja zapisanego obiektu nie powiodła się.');
                }
                try {
                    $this->updateReference($row, $reference);
                } catch (\Throwable $error) {
                    $this->deleteQuietly($reference);
                    throw $error;
                }
                $sourceSuccesses[$sourcePath] = ($sourceSuccesses[$sourcePath] ?? 0) + 1;
                $report['manifest'][$manifestIndex]['target_reference'] = $reference;
                $report['manifest'][$manifestIndex]['read_verified'] = true;
                $report['verified']++;
                $report['migrated']++;
            } catch (\InvalidArgumentException $error) {
                $report['invalid']++;
                $report['errors'][] = $this->rowLabel($row) . ': ' . $error->getMessage();
            } catch (\Throwable $error) {
                if (str_contains($error->getMessage(), 'nie istnieje') || str_contains($error->getMessage(), 'brak czytelnego')) {
                    $report['missing']++;
                } else {
                    $report['failed']++;
                }
                $report['errors'][] = $this->rowLabel($row) . ': ' . $error->getMessage();
            }
        }

        if ($deleteSource) {
            foreach ($sourceSuccesses as $sourcePath => $successCount) {
                if ($successCount !== ($sourceTotals[$sourcePath] ?? 0)) {
                    continue;
                }
                if (is_file($sourcePath) && @unlink($sourcePath)) {
                    $report['deleted_sources']++;
                }
            }
        }

        return $report;
    }

    private function references(): array
    {
        $definitions = [
            ['table' => 'media', 'column' => 'path'],
            ['table' => 'users', 'column' => 'avatar_path'],
            ['table' => 'user_profiles', 'column' => 'avatar_path'],
            ['table' => 'main_banners', 'column' => 'image_path'],
        ];
        $rows = [];
        foreach ($definitions as $definition) {
            $found = $this->database->all(
                'SELECT id,' . $definition['column'] . ' AS reference
                 FROM ' . $definition['table'] . '
                 WHERE ' . $definition['column'] . ' LIKE \'/uploads/%\''
            );
            foreach ($found as $row) {
                $row['table'] = $definition['table'];
                $row['column'] = $definition['column'];
                $rows[] = $row;
            }
        }
        return $rows;
    }

    private function sourcePath(string $reference): string
    {
        $path = (string)(parse_url($reference, PHP_URL_PATH) ?? '');
        if (!str_starts_with($path, '/uploads/')) {
            throw new \InvalidArgumentException('Referencja nie wskazuje lokalnego uploadu.');
        }
        $relative = str_replace('\\', '/', substr($path, strlen('/uploads/')));
        if (
            $relative === ''
            || str_contains($relative, '..')
            || preg_match('~^[A-Za-z0-9][A-Za-z0-9._/-]*$~D', $relative) !== 1
        ) {
            throw new \InvalidArgumentException('Nieprawidłowa ścieżka lokalnego uploadu.');
        }
        $root = realpath($this->rootPath . '/public/uploads');
        if ($root === false) {
            throw new \RuntimeException('Katalog public/uploads nie istnieje.');
        }
        $candidate = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
        if (
            $candidate === false
            || !str_starts_with(
                strtolower(str_replace('\\', '/', $candidate)),
                rtrim(strtolower(str_replace('\\', '/', $root)), '/') . '/'
            )
        ) {
            throw new \RuntimeException('Plik lokalnego uploadu nie istnieje.');
        }
        return $candidate;
    }

    private function validatedMime(string $sourcePath): string
    {
        $size = filesize($sourcePath);
        if ($size === false || $size <= 0 || $size > $this->maxBytes) {
            throw new \InvalidArgumentException('Plik ma niedozwolony rozmiar.');
        }
        $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
        $expected = self::MIME_BY_EXTENSION[$extension] ?? null;
        if ($expected === null) {
            throw new \InvalidArgumentException('Plik ma niebezpieczne rozszerzenie.');
        }
        $actual = (new \finfo(FILEINFO_MIME_TYPE))->file($sourcePath);
        if (!is_string($actual) || $actual !== $expected) {
            throw new \InvalidArgumentException('Rozszerzenie pliku nie zgadza się z MIME.');
        }
        return $actual;
    }

    private function migrationObjectKey(array $row, string $sourcePath): string
    {
        $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
        $identity = implode('|', [
            (string)$row['table'],
            (string)$row['id'],
            (string)$row['reference'],
            hash_file('sha256', $sourcePath) ?: '',
        ]);
        return sprintf(
            'public/migrated/%s/%d/%s.%s',
            (string)$row['table'],
            (int)$row['id'],
            hash('sha256', $identity),
            $extension
        );
    }

    private function updateReference(array $row, string $reference): void
    {
        $allowed = [
            'media' => 'path',
            'users' => 'avatar_path',
            'user_profiles' => 'avatar_path',
            'main_banners' => 'image_path',
        ];
        $table = (string)$row['table'];
        $column = (string)$row['column'];
        if (($allowed[$table] ?? null) !== $column) {
            throw new \LogicException('Niedozwolony cel migracji referencji.');
        }
        $statement = $this->database->query(
            'UPDATE ' . $table . '
             SET ' . $column . '=:new_reference
             WHERE id=:id AND ' . $column . '=:old_reference',
            [
                'new_reference' => $reference,
                'old_reference' => (string)$row['reference'],
                'id' => (int)$row['id'],
            ]
        );
        if ($statement->rowCount() !== 1) {
            $current = $this->database->cell(
                'SELECT ' . $column . ' FROM ' . $table . ' WHERE id=:id',
                ['id' => (int)$row['id']]
            );
            if (is_string($current) && hash_equals($reference, $current)) {
                return;
            }
            throw new \RuntimeException('Referencja zmieniła się równolegle; rekord pominięto.');
        }
    }

    private function deleteQuietly(string $reference): void
    {
        try {
            $this->storage->delete($reference);
        } catch (\Throwable) {
            // Najważniejsze jest zachowanie starej referencji w bazie.
        }
    }

    private function rowLabel(array $row): string
    {
        return (string)$row['table'] . '#' . (int)$row['id'];
    }
}

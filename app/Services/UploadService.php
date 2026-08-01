<?php
declare(strict_types=1);

namespace App\Services;

use App\Contracts\ObjectStorageInterface;
use App\Core\Database;

final class UploadService
{
    private array $config;
    public function __construct(
        private readonly Database $db,
        private readonly ObjectStorageInterface $storage,
    )
    {
        $this->config = require __DIR__ . '/../../config/uploads.php';
    }

    /**
     * Zapis zdjęcia artykułu jako WEBP.
     * Zasada: jedno zdjęcie główne artykułu = jeden aktualny rekord media.
     * Nowy obiekt dostaje unikalny klucz, a poprzedni jest usuwany dopiero po zmianie referencji w bazie.
     */
    public function uploadArticleImage(array $file, int $userId, ?int $articleId = null, int $position = 50, ?string $titleSeed = null, bool $replaceExisting = true): int
    {
        $conf = $this->config['articles'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Błąd przesyłania pliku (code: ' . ($file['error'] ?? 'brak') . ')');
        }

        $sourcePath = (string)($file['tmp_name'] ?? '');
        $actualSize = is_file($sourcePath) && is_readable($sourcePath) ? filesize($sourcePath) : false;
        if ($actualSize === false || $actualSize <= 0) {
            throw new \RuntimeException('Brak czytelnego pliku obrazu.');
        }
        if ($actualSize > (int)$conf['max_size']) {
            throw new \RuntimeException('Plik jest zbyt duży. Maksymalny rozmiar to ' . ((int)$conf['max_size'] / 1024 / 1024) . 'MB');
        }

        $extension = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($extension, $conf['allowed_extensions'], true)) {
            throw new \RuntimeException('Niedozwolone rozszerzenie pliku. Dozwolone: ' . implode(', ', $conf['allowed_extensions']));
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($sourcePath);
        $allowedMime = $conf['allowed_mime_types'] ?? ['image/jpeg', 'image/png', 'image/webp'];
        if (!in_array($mime, $allowedMime, true)) {
            throw new \RuntimeException('Plik musi być obrazem JPG, PNG albo WEBP.');
        }

        $existing = null;
        if ($articleId !== null && $articleId > 0 && $replaceExisting) {
            $existing = $this->db->one('SELECT * FROM media WHERE article_id=:article ORDER BY id DESC LIMIT 1', [
                'article' => $articleId,
            ]);
        }

        $base = $this->articleImageBaseName($titleSeed ?: (string)($file['name'] ?? 'zdjecie'), $articleId);
        $filename = $base . '.webp';
        $temporaryPath = $this->temporaryWebpPath();
        try {
            $this->writeWebpFromUpload($sourcePath, $mime, $temporaryPath, $conf['image'] ?? []);
            $relativeUrl = $this->storage->putFile(
                'public/articles/' . $filename,
                $temporaryPath,
                'image/webp'
            );
        } finally {
            @unlink($temporaryPath);
        }

        return $this->persistArticleImage(
            $existing,
            $relativeUrl,
            $filename,
            $userId,
            $articleId,
            $position
        );
    }

    /**
     * Zapis gotowego kadru z canvas jako WEBP.
     * Używane przez jeden wspólny edytor obrazu: avatar / autor / wydawca.
     */
    public function uploadArticleImageDataUrl(string $dataUrl, int $userId, int $articleId, ?string $titleSeed = null, int $position = 50, bool $replaceExisting = true): int
    {
        $conf = $this->config['articles'];
        if ($articleId <= 0) {
            throw new \RuntimeException('Nieprawidłowy ID artykułu.');
        }

        if (!preg_match('/^data:(image\/(?:jpeg|jpg|png|webp));base64,(.+)$/', $dataUrl, $matches)) {
            throw new \RuntimeException('Nieprawidłowy format obrazu.');
        }

        $binary = base64_decode($matches[2], true);
        if ($binary === false || $binary === '') {
            throw new \RuntimeException('Nie udało się odczytać obrazu.');
        }

        if (strlen($binary) > (int)$conf['max_size']) {
            throw new \RuntimeException('Plik jest zbyt duży. Maksymalny rozmiar to ' . ((int)$conf['max_size'] / 1024 / 1024) . 'MB');
        }
        $actualMime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($binary);
        if (!is_string($actualMime) || !in_array($actualMime, $conf['allowed_mime_types'], true)) {
            throw new \RuntimeException('Dane nie zawierają dozwolonego obrazu.');
        }

        $image = @imagecreatefromstring($binary);
        if (!$image) {
            throw new \RuntimeException('Nie udało się utworzyć obrazu z danych canvas.');
        }

        if (function_exists('imagepalettetotruecolor')) {
            imagepalettetotruecolor($image);
        }
        imagealphablending($image, true);
        imagesavealpha($image, true);

        $base = $this->articleImageBaseName($titleSeed ?: 'zdjecie-artykulu', $articleId);
        $filename = $base . '.webp';
        $temporaryPath = $this->temporaryWebpPath();
        $quality = (int)($conf['image']['webp_quality'] ?? 82);

        if (!imagewebp($image, $temporaryPath, max(1, min(100, $quality)))) {
            imagedestroy($image);
            @unlink($temporaryPath);
            throw new \RuntimeException('Nie udało się zapisać WEBP.');
        }
        imagedestroy($image);
        try {
            $relativeUrl = $this->storage->putFile(
                'public/articles/' . $filename,
                $temporaryPath,
                'image/webp'
            );
        } finally {
            @unlink($temporaryPath);
        }

        $existing = null;
        if ($replaceExisting) {
            $existing = $this->db->one('SELECT * FROM media WHERE article_id=:article ORDER BY id DESC LIMIT 1', [
                'article' => $articleId,
            ]);
        }

        return $this->persistArticleImage(
            $existing,
            $relativeUrl,
            $filename,
            $userId,
            $articleId,
            $position
        );
    }

    public function uploadAvatarDataUrl(string $dataUrl, int $userId): string
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('Nieprawidłowy użytkownik avatara.');
        }
        $conf = (array)$this->config['avatars'];
        if (!preg_match('/^data:(image\/(?:jpeg|jpg|png|webp));base64,(.+)$/', $dataUrl, $matches)) {
            throw new \RuntimeException('Nieprawidłowy format obrazu.');
        }
        $binary = base64_decode($matches[2], true);
        if (!is_string($binary) || $binary === '' || strlen($binary) > (int)$conf['max_size']) {
            throw new \RuntimeException('Nieprawidłowy rozmiar avatara.');
        }
        $actualMime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($binary);
        if (!is_string($actualMime) || !in_array($actualMime, $conf['allowed_mime_types'], true)) {
            throw new \RuntimeException('Avatar musi być obrazem JPG, PNG albo WEBP.');
        }
        $image = @imagecreatefromstring($binary);
        if (!$image) {
            throw new \RuntimeException('Nie udało się odczytać obrazu avatara.');
        }
        if (function_exists('imagepalettetotruecolor')) {
            imagepalettetotruecolor($image);
        }
        imagealphablending($image, true);
        imagesavealpha($image, true);
        $image = $this->resizeIfNeeded(
            $image,
            (int)($conf['image']['max_width'] ?? 1024),
            (int)($conf['image']['max_height'] ?? 1024)
        );

        $temporaryPath = $this->temporaryWebpPath();
        $quality = max(1, min(100, (int)($conf['image']['webp_quality'] ?? 86)));
        try {
            if (!imagewebp($image, $temporaryPath, $quality)) {
                throw new \RuntimeException('Nie udało się zapisać avatara WEBP.');
            }
            return $this->storage->putFile(
                'public/avatars/user-' . $userId . '/avatar-' . bin2hex(random_bytes(12)) . '.webp',
                $temporaryPath,
                'image/webp'
            );
        } finally {
            imagedestroy($image);
            @unlink($temporaryPath);
        }
    }

    public function deleteReference(string $reference): void
    {
        $this->storage->delete($reference);
    }

    public function deleteMedia(int $mediaId, int $userId): void
    {
        $media = $this->db->one(
            'SELECT m.*
             FROM media m JOIN articles a ON a.id=m.article_id
             WHERE m.id=:id AND m.owner_user_id=:owner AND a.author_id=:author
               AND a.status IN (\'draft\',\'rejected\')',
            ['id' => $mediaId, 'owner' => $userId, 'author' => $userId]
        );

        if (!$media) {
            throw new \RuntimeException('Nie znaleziono pliku lub brak uprawnień.');
        }

        $this->db->query('DELETE FROM media WHERE id = :id', ['id' => $mediaId]);
        $this->deleteQuietly((string)$media['path'], 'article_media_delete');
    }

    private function persistArticleImage(
        ?array $existing,
        string $reference,
        string $filename,
        int $userId,
        ?int $articleId,
        int $position,
    ): int {
        try {
            if ($existing) {
                $statement = $this->db->query(
                    'UPDATE media
                     SET path=:path, mime=:mime, title=:title, image_position=:pos
                     WHERE id=:id AND path=:old_path',
                    [
                        'id' => (int)$existing['id'],
                        'old_path' => (string)$existing['path'],
                        'path' => $reference,
                        'mime' => 'image/webp',
                        'title' => $filename,
                        'pos' => max(0, min(100, $position)),
                    ]
                );
                if ($statement->rowCount() !== 1) {
                    throw new \RuntimeException('Zdjęcie zostało równolegle zmienione; ponów operację.');
                }
                $mediaId = (int)$existing['id'];
            } else {
                $mediaId = $this->db->insert(
                    'INSERT INTO media (owner_user_id, article_id, path, mime, title, image_position, created_at)
                     VALUES (:user, :article, :path, :mime, :title, :pos, NOW())',
                    [
                        'user' => $userId,
                        'article' => $articleId,
                        'path' => $reference,
                        'mime' => 'image/webp',
                        'title' => $filename,
                        'pos' => max(0, min(100, $position)),
                    ]
                );
            }
        } catch (\Throwable $error) {
            $this->deleteQuietly($reference, 'rollback_new_object');
            throw $error;
        }

        $oldReference = (string)($existing['path'] ?? '');
        if ($oldReference !== '' && $oldReference !== $reference) {
            $this->deleteQuietly($oldReference, 'replace_old_object');
        }
        return $mediaId;
    }

    private function deleteQuietly(string $reference, string $operation): void
    {
        if ($reference === '') {
            return;
        }
        try {
            $this->storage->delete($reference);
        } catch (\Throwable $error) {
            error_log('Object cleanup failed [' . $operation . ']: ' . $error->getMessage());
        }
    }

    private function articleImageBaseName(string $seed, ?int $articleId): string
    {
        $seed = pathinfo($seed, PATHINFO_FILENAME);
        $seed = preg_replace('/\.(jpe?g|png|webp)$/i', '', $seed) ?? $seed;
        $slug = $this->slugify($seed);
        if ($slug === '') {
            $slug = 'zdjecie';
        }
        $suffix = bin2hex(random_bytes(4));
        $idPart = $articleId !== null && $articleId > 0 ? '-a' . $articleId : '';
        return substr($slug, 0, 50) . $idPart . '-' . $suffix;
    }

    private function slugify(string $value): string
    {
        $value = trim($value);
        $map = [
            'ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ż'=>'z','ź'=>'z',
            'Ą'=>'a','Ć'=>'c','Ę'=>'e','Ł'=>'l','Ń'=>'n','Ó'=>'o','Ś'=>'s','Ż'=>'z','Ź'=>'z',
            'ä'=>'a','ö'=>'o','ü'=>'u','ß'=>'ss','Ä'=>'a','Ö'=>'o','Ü'=>'u',
            'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','à'=>'a','â'=>'a','î'=>'i','ï'=>'i','ô'=>'o','ù'=>'u','û'=>'u','ç'=>'c',
            'É'=>'e','È'=>'e','Ê'=>'e','Ë'=>'e','À'=>'a','Â'=>'a','Î'=>'i','Ï'=>'i','Ô'=>'o','Ù'=>'u','Û'=>'u','Ç'=>'c',
            'á'=>'a','í'=>'i','ú'=>'u','ñ'=>'n','Á'=>'a','Í'=>'i','Ú'=>'u','Ñ'=>'n',
            'ì'=>'i','ò'=>'o','Ì'=>'i','Ò'=>'o',
        ];
        $value = strtr($value, $map);
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');
        return substr($value, 0, 90);
    }

    private function temporaryWebpPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'zs_image_');
        if ($path === false) {
            throw new \RuntimeException('Nie udało się utworzyć pliku tymczasowego obrazu.');
        }
        return $path;
    }

    private function writeWebpFromUpload(string $sourcePath, string $mime, string $destPath, array $imageConf): void
    {
        $image = match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($sourcePath),
            'image/png' => imagecreatefrompng($sourcePath),
            'image/webp' => imagecreatefromwebp($sourcePath),
            default => false,
        };

        if (!$image) {
            throw new \RuntimeException('Nie udało się odczytać obrazu.');
        }

        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $image = $this->applyJpegOrientation($image, $sourcePath);
        }

        if (function_exists('imagepalettetotruecolor')) {
            imagepalettetotruecolor($image);
        }
        imagealphablending($image, true);
        imagesavealpha($image, true);

        $maxWidth = (int)($imageConf['max_width'] ?? 1600);
        $maxHeight = (int)($imageConf['max_height'] ?? 1600);
        $quality = (int)($imageConf['webp_quality'] ?? 82);

        $image = $this->resizeIfNeeded($image, $maxWidth, $maxHeight);

        if (!imagewebp($image, $destPath, max(1, min(100, $quality)))) {
            imagedestroy($image);
            throw new \RuntimeException('Nie udało się zapisać WEBP.');
        }
        imagedestroy($image);
    }

    /** @param resource|\GdImage $image */
    private function resizeIfNeeded($image, int $maxWidth, int $maxHeight)
    {
        $width = imagesx($image);
        $height = imagesy($image);
        if ($width <= $maxWidth && $height <= $maxHeight) {
            return $image;
        }

        $ratio = min($maxWidth / $width, $maxHeight / $height);
        $newWidth = max(1, (int)round($width * $ratio));
        $newHeight = max(1, (int)round($height * $ratio));

        $resized = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($image);
        return $resized;
    }

    /** @param resource|\GdImage $image */
    private function applyJpegOrientation($image, string $sourcePath)
    {
        $exif = @exif_read_data($sourcePath);
        $orientation = (int)($exif['Orientation'] ?? 1);
        return match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };
    }
}

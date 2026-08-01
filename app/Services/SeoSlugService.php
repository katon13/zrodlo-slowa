<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class SeoSlugService
{
    private const DEFAULT_CONFIG = [
        'default_language' => 'pl',
        'slug' => [
            'max_length' => 120,
            'separator' => '-',
            'unique_suffix_start' => 2,
        ],
    ];

    /** @var array<string, mixed> */
    private array $config;

    /** @param array<string, mixed> $config */
    public function __construct(private readonly Database $db, array $config = [])
    {
        $this->config = array_replace_recursive(self::DEFAULT_CONFIG, $config);
    }

    public static function fromConfigFile(Database $db, string $rootPath): self
    {
        $path = rtrim($rootPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'seo_languages.json';
        $config = [];
        if (is_file($path)) {
            $content = @file_get_contents($path);
            $decoded = is_string($content) ? json_decode($content, true) : null;
            if (is_array($decoded)) {
                $config = $decoded;
            }
        }
        return new self($db, $config);
    }

    public function slugify(string $title): string
    {
        $separator = $this->separator();
        $value = trim($title);
        if ($value === '') {
            return 'artykul';
        }

        $value = $this->transliterate($value);
        $value = mb_strtolower($value, 'UTF-8');
        $value = preg_replace('/[^a-z0-9]+/u', $separator, $value) ?? '';
        $value = preg_replace('/' . preg_quote($separator, '/') . '+/', $separator, $value) ?? '';
        $value = trim($value, $separator);

        if ($value === '') {
            $value = 'artykul';
        }

        return $this->limitSlug($value);
    }

    public function uniqueArticleSlug(string $title, ?int $excludeArticleId = null): string
    {
        $base = $this->slugify($title);
        return $this->unique($base, function (string $candidate) use ($excludeArticleId): bool {
            $sql = 'SELECT id FROM articles WHERE slug=:slug';
            $params = ['slug' => $candidate];
            if ($excludeArticleId !== null && $excludeArticleId > 0) {
                $sql .= ' AND id<>:id';
                $params['id'] = $excludeArticleId;
            }
            $sql .= ' LIMIT 1';
            return $this->db->one($sql, $params) === null;
        });
    }

    public function uniqueTranslationSlug(string $title, string $language, int $articleId, ?int $excludeTranslationId = null): string
    {
        $language = strtolower(trim($language));
        $base = $this->slugify($title);
        return $this->unique($base, function (string $candidate) use ($language, $articleId, $excludeTranslationId): bool {
            $sql = 'SELECT id FROM article_translations WHERE language=:language AND slug=:slug';
            $params = ['language' => $language, 'slug' => $candidate];
            if ($excludeTranslationId !== null && $excludeTranslationId > 0) {
                $sql .= ' AND id<>:id';
                $params['id'] = $excludeTranslationId;
            }
            $sql .= ' LIMIT 1';
            if ($this->db->one($sql, $params) !== null) {
                return false;
            }

            if ($language === (string)($this->config['default_language'] ?? 'pl')) {
                $baseSql = 'SELECT id FROM articles WHERE slug=:slug';
                $baseParams = ['slug' => $candidate];
                if ($articleId > 0) {
                    $baseSql .= ' AND id<>:article_id';
                    $baseParams['article_id'] = $articleId;
                }
                $baseSql .= ' LIMIT 1';
                return $this->db->one($baseSql, $baseParams) === null;
            }

            return true;
        });
    }

    private function unique(string $base, callable $isFree): string
    {
        $candidate = $this->limitSlug($base);
        if ($isFree($candidate)) {
            return $candidate;
        }

        $suffix = max(2, (int)($this->config['slug']['unique_suffix_start'] ?? 2));
        while ($suffix < 10000) {
            $tail = $this->separator() . $suffix;
            $candidate = $this->limitSlug($base, mb_strlen($tail, 'UTF-8')) . $tail;
            if ($isFree($candidate)) {
                return $candidate;
            }
            $suffix++;
        }

        return $this->limitSlug($base . $this->separator() . bin2hex(random_bytes(3)));
    }

    private function transliterate(string $value): string
    {
        $map = [
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ż' => 'z', 'ź' => 'z',
            'Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ł' => 'L', 'Ń' => 'N', 'Ó' => 'O', 'Ś' => 'S', 'Ż' => 'Z', 'Ź' => 'Z',
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss',
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a', 'ā' => 'a', 'ă' => 'a', 'æ' => 'ae',
            'ç' => 'c', 'č' => 'c', 'ď' => 'd', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ė' => 'e', 'ě' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i', 'ñ' => 'n', 'ň' => 'n',
            'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ø' => 'o', 'ō' => 'o', 'œ' => 'oe',
            'ř' => 'r', 'š' => 's', 'ť' => 't', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ū' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'ž' => 'z',
            'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Å' => 'A', 'Ā' => 'A', 'Ă' => 'A', 'Æ' => 'AE',
            'Ç' => 'C', 'Č' => 'C', 'Ď' => 'D', 'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Ē' => 'E', 'Ė' => 'E', 'Ě' => 'E',
            'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I', 'Ī' => 'I', 'Ñ' => 'N', 'Ň' => 'N',
            'Ò' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ø' => 'O', 'Ō' => 'O', 'Œ' => 'OE',
            'Ř' => 'R', 'Š' => 'S', 'Ť' => 'T', 'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ū' => 'U', 'Ý' => 'Y', 'Ÿ' => 'Y', 'Ž' => 'Z',
        ];

        $value = strtr($value, $map);
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($converted) && $converted !== '') {
                $value = $converted;
            }
        }
        return $value;
    }

    private function limitSlug(string $slug, int $reservedLength = 0): string
    {
        $max = max(20, $this->maxLength() - max(0, $reservedLength));
        if (mb_strlen($slug, 'UTF-8') <= $max) {
            return trim($slug, $this->separator());
        }
        $cut = mb_substr($slug, 0, $max, 'UTF-8');
        return trim($cut, $this->separator());
    }

    private function maxLength(): int
    {
        return max(20, (int)($this->config['slug']['max_length'] ?? 120));
    }

    private function separator(): string
    {
        $separator = (string)($this->config['slug']['separator'] ?? '-');
        return $separator !== '' ? mb_substr($separator, 0, 1, 'UTF-8') : '-';
    }
}

<?php
declare(strict_types=1);

namespace App\Services;

final class ApplicationUrl
{
    public static function origin(): string
    {
        $configured = trim((string)\env('APP_URL', 'http://localhost:8080'));
        $parts = parse_url($configured);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = (string)($parts['host'] ?? '');

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new \RuntimeException('APP_URL musi zawierać poprawny origin HTTP lub HTTPS.');
        }

        $origin = $scheme . '://' . $host;
        if (isset($parts['port'])) {
            $origin .= ':' . (int)$parts['port'];
        }

        $basePath = rtrim((string)($parts['path'] ?? ''), '/');
        if ($basePath !== '' && $basePath !== '/') {
            $origin .= '/' . ltrim($basePath, '/');
        }

        return rtrim($origin, '/');
    }

    public static function absolute(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return self::origin() . '/';
        }
        if (preg_match('~^https?://~i', $path) === 1) {
            return $path;
        }

        return self::origin() . '/' . ltrim($path, '/');
    }

    public static function requestHost(): string
    {
        $forwarded = trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''))[0]);
        if ($forwarded !== '') {
            return $forwarded;
        }

        $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($host !== '') {
            return $host;
        }

        $parts = parse_url(self::origin());
        $fallback = (string)($parts['host'] ?? 'localhost');
        if (isset($parts['port'])) {
            $fallback .= ':' . (int)$parts['port'];
        }
        return $fallback;
    }
}

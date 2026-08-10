<?php
declare(strict_types=1);

namespace App\Services;

/** Wspólny katalog tekstów widocznych w 3DORS (panel, backend i przeglądarka). */
final class Dors3UiText
{
    /** @var array<string,mixed>|null */
    private static ?array $catalog = null;

    /** @param array<string,string|int|float> $parameters */
    public static function get(string $key, array $parameters = [], ?string $language = null): string
    {
        $language = self::language($language);
        $value = self::resolve([$language, ...explode('.', $key)]);
        if (!is_string($value)) {
            $value = self::resolve(['pl', ...explode('.', $key)]);
        }
        if (!is_string($value)) {
            return $key;
        }
        $replace = [];
        foreach ($parameters as $name => $parameter) {
            $replace['{' . $name . '}'] = (string)$parameter;
        }
        return strtr($value, $replace);
    }

    /** Pobiera tłumaczenie wartości maszynowej bez zmiany tej wartości w protokole. */
    public static function option(string $section, string $machineValue, ?string $language = null): string
    {
        $language = self::language($language);
        $value = self::resolve([$language, ...explode('.', $section), $machineValue]);
        if (!is_string($value)) {
            $value = self::resolve(['pl', ...explode('.', $section), $machineValue]);
        }
        return is_string($value) ? $value : self::humanize($machineValue);
    }

    /** @return array<string,mixed> */
    public static function languageCatalog(string $language): array
    {
        $catalog = self::catalog();
        $value = $catalog[self::language($language)] ?? $catalog['pl'] ?? [];
        return is_array($value) ? $value : [];
    }

    private static function language(?string $language): string
    {
        $language = strtolower(trim((string)$language));
        if ($language === '' && function_exists('is_admin_request') && is_admin_request()) {
            return 'pl';
        }
        if ($language === '' && function_exists('public_language')) {
            $language = (string)public_language();
        }
        return in_array($language, ['pl', 'en'], true) ? $language : 'pl';
    }

    /** @param list<string> $path */
    private static function resolve(array $path): mixed
    {
        $value = self::catalog();
        foreach ($path as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }
        return $value;
    }

    /** @return array<string,mixed> */
    private static function catalog(): array
    {
        if (self::$catalog !== null) {
            return self::$catalog;
        }
        $path = dirname(__DIR__, 2) . '/resources/lang/dors3.json';
        $decoded = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::$catalog = is_array($decoded) ? $decoded : [];
        return self::$catalog;
    }

    private static function humanize(string $value): string
    {
        $value = trim((string)preg_replace('/[._-]+/', ' ', trim($value)));
        return $value === '' ? self::get('common.not_specified') : ucfirst($value);
    }
}

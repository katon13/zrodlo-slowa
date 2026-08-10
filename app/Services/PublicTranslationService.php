<?php
namespace App\Services;

final class PublicTranslationService
{
    private string $rootPath;

    private PublicLanguageService $languageService;
    private bool $debug;

    /** @var array<string, array<string, string>>|null */
    private ?array $phrases = null;

    public function __construct(string $rootPath, PublicLanguageService $languageService, bool $debug = false)
    {
        $this->rootPath = rtrim($rootPath, '/\\');
        $this->languageService = $languageService;
        $this->debug = $debug;
    }

    public function translate(string $key, ?string $language = null, ?string $fallbackLanguage = null): string
    {
        $phrases = $this->loadPhrases();
        $entry = $phrases[$key] ?? null;
        $langs = [];
        if ($language !== null && $this->languageService->isEnabled($language)) {
            $langs[] = strtolower($language);
        }
        if ($fallbackLanguage !== null && $this->languageService->isEnabled($fallbackLanguage)) {
            $langs[] = strtolower($fallbackLanguage);
        }
        $langs[] = $this->languageService->current();
        $langs[] = 'pl';
        $langs[] = $this->languageService->default();

        if ($entry) {
            foreach (array_unique($langs) as $l) {
                if (isset($entry[$l]) && trim($entry[$l]) !== '') {
                    return $entry[$l];
                }
            }
        }

        return $this->handleMissingKey($key);
    }

    private function handleMissingKey(string $key): string
    {
        // brand.name always returns a safe value
        if ($key === 'brand.name') {
            $phrases = $this->loadPhrases();
            $pl = $phrases['brand.name']['pl'] ?? 'ŹRÓDŁO SŁOWA';
            return trim($pl) !== '' ? $pl : 'ŹRÓDŁO SŁOWA';
        }

        $isAdmin = function_exists('is_admin_request') && is_admin_request();

        if ($isAdmin) {
            return $this->humanizeKey($key);
        }

        if ($this->debug) {
            error_log('Missing public translation key: ' . $key);
        }

        // Public UI fallback
        return '';
    }

    private function humanizeKey(string $key): string
    {
        $parts = explode('.', $key);
        $last = end($parts);
        $text = str_replace(['_', '-'], ' ', $last);
        return ucfirst($text);
    }

    public function has(string $key): bool
    {
        return isset($this->loadPhrases()[$key]);
    }

    /**
     * @return array<string, string>
     */
    public function entry(string $key): array
    {
        return $this->loadPhrases()[$key] ?? [];
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function all(): array
    {
        return $this->loadPhrases();
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function loadPhrases(): array
    {
        if ($this->phrases !== null) {
            return $this->phrases;
        }

        $phrases = [];
        foreach (['public.json', 'safety_fund.json'] as $catalog) {
            $path = $this->rootPath . '/resources/lang/' . $catalog;
            if (!is_file($path)) {
                continue;
            }
            $raw = file_get_contents($path);
            $decoded = $raw !== false ? json_decode($raw, true) : null;
            if (!is_array($decoded)) {
                continue;
            }
            foreach ($decoded as $key => $values) {
                if (!is_string($key) || !is_array($values)) {
                    continue;
                }
                foreach ($values as $language => $value) {
                    if (is_string($language) && is_string($value)) {
                        $phrases[$key][$language] = $value;
                    }
                }
            }
        }
        $this->phrases = $phrases;
        return $this->phrases;
    }
}

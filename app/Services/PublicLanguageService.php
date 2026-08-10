<?php
namespace App\Services;

final class PublicLanguageService
{
    /** @var array<string, mixed> */
    private array $languages;

    private PublicSiteResolver $siteResolver;

    /**
     * @param array<string, mixed> $languages
     */
    public function __construct(array $languages, PublicSiteResolver $siteResolver)
    {
        $this->languages = $languages;
        $this->siteResolver = $siteResolver;
    }

    public function current(?string $host = null, ?string $requestedLanguage = null, ?string $uri = null): string
    {
        $uri ??= (string)($_SERVER['REQUEST_URI'] ?? '/');
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        // 1. Jawny język przekazany przez kod ma najwyższy priorytet.
        if ($requestedLanguage !== null && $this->isEnabled($requestedLanguage)) {
            return $this->rememberExplicit($requestedLanguage);
        }

        // 2. Jawny wybór w URL: ?lang=pl / ?lang=de.
        $queryLanguage = $_GET['lang'] ?? null;
        if (is_string($queryLanguage) && $this->isEnabled($queryLanguage)) {
            return $this->rememberExplicit($queryLanguage);
        }

        // 3. Jawny język bieżącego requestu z formularza/AJAX-a.
        // UWAGA: nie czytamy tu interface_language, bo to jest NOWA preferencja użytkownika
        // z formularza ustawień konta, a nie język bieżącej strony.
        $bodyLanguage = $_POST['_lang'] ?? $_POST['language'] ?? $_POST['lang'] ?? null;
        if (is_string($bodyLanguage) && $this->isEnabled($bodyLanguage)) {
            return $this->rememberExplicit($bodyLanguage);
        }

        // 4. Jawny język w JSON/AJAX.
        $jsonLanguage = $this->jsonLanguageFromRequest();
        if ($jsonLanguage !== null && $this->isEnabled($jsonLanguage)) {
            return $this->rememberExplicit($jsonLanguage);
        }

        // 5. Jawny nagłówek AJAX-a.
        $headerLanguage = $_SERVER['HTTP_X_ZS_LANG'] ?? $_SERVER['HTTP_X_PUBLIC_LANGUAGE'] ?? null;
        if (is_string($headerLanguage) && $this->isEnabled($headerLanguage)) {
            return $this->rememberExplicit($headerLanguage);
        }

        // 6. Prefiks URL jest źródłem prawdy: /pl, /de, /en, /fr, /it, /es.
        $normalizedUri = $this->siteResolver->normalizeUri($uri);
        $uriLanguage = $normalizedUri['language'] ?? null;
        if (is_string($uriLanguage) && $this->isEnabled($uriLanguage)) {
            return $this->rememberExplicit($uriLanguage);
        }

        // 7. POST/AJAX bez prefiksu może dziedziczyć język z referera, np. /de/wallet -> /wallet/post.
        if ($method !== 'GET') {
            $refererLanguage = $this->languageFromReferer();
            if ($refererLanguage !== null && $this->isEnabled($refererLanguage)) {
                return $this->rememberExplicit($refererLanguage);
            }
        }

        // 8. Domena jednojęzyczna może wymusić język. Na domenie wielojęzycznej brak prefiksu = default PL.
        $site = $this->siteResolver->current($host, $uri);
        $siteLanguage = (string)($site['language'] ?? '');
        $hasUriPrefix = is_string($uriLanguage) && $uriLanguage !== '';
        $isKnownSingleDomain = !empty($site['is_known_domain']) && !$this->isMultiLanguageHost($host);
        if ($hasUriPrefix || $isKnownSingleDomain) {
            if ($this->isEnabled($siteLanguage)) {
                return $this->normalize($siteLanguage);
            }
        }

        // 9. Panel operatora zachowuje jawnie wybrany język także pomiędzy zwykłymi
        // linkami /admin/*. Front publiczny nadal nie dziedziczy sesji dla GET, aby
        // wejście na / po stronie DE nie przełączało automatycznie strony głównej.
        $normalizedPath = (string)(parse_url($normalizedUri['path'], PHP_URL_PATH) ?: '/');
        $adminGetMayUseSession = $method === 'GET'
            && ($normalizedPath === '/admin' || str_starts_with($normalizedPath, '/admin/'));
        if ($method !== 'GET' || $adminGetMayUseSession) {
            $sessionLanguage = $_SESSION['interface_language'] ?? null;
            if (is_string($sessionLanguage) && $this->isEnabled($sessionLanguage)) {
                return $this->normalize($sessionLanguage);
            }
        }

        // 10. Brak prefiksu i brak jawnego języka = PL/default.
        return $this->default();
    }

    public function default(): string
    {
        $default = (string)($this->languages['default'] ?? 'pl');
        return $this->isEnabled($default) ? $this->normalize($default) : 'pl';
    }

    public function isEnabled(string $language): bool
    {
        return in_array($this->normalize($language), $this->enabled(), true);
    }

    /**
     * @return list<string>
     */
    public function enabled(): array
    {
        $enabled = $this->languages['public_enabled'] ?? ['pl'];
        if (!is_array($enabled)) {
            return ['pl'];
        }
        return array_values(array_unique(array_map(fn($lang): string => $this->normalize((string)$lang), $enabled)));
    }

    /** @return array<string, string> */
    public function labels(): array { return $this->stringMap('labels'); }

    /** @return array<string, string> */
    public function shortLabels(): array { return $this->stringMap('short_labels'); }

    /** @return array<string, string> */
    public function flagCodes(): array { return $this->stringMap('flag_codes'); }

    /** @return array<string, string> */
    public function locales(): array { return $this->stringMap('locales'); }

    /** @return array<string, string> */
    public function brandNames(): array { return $this->stringMap('brand_names'); }

    public function shortLabel(string $language): string
    {
        $language = $this->normalize($language);
        return $this->shortLabels()[$language] ?? strtoupper($language);
    }

    public function label(string $language): string
    {
        $language = $this->normalize($language);
        return $this->labels()[$language] ?? $this->shortLabel($language);
    }

    public function flagCode(string $language): string
    {
        $language = $this->normalize($language);
        return $this->flagCodes()[$language] ?? strtoupper($language);
    }

    public function brandName(string $language): string
    {
        $language = $this->normalize($language);
        return $this->brandNames()[$language] ?? 'ŹRÓDŁO SŁOWA';
    }

    public function locale(string $language): string
    {
        $language = $this->normalize($language);
        return $this->locales()[$language] ?? ($language . '_' . strtoupper($language));
    }

    private function rememberExplicit(string $language): string
    {
        $language = $this->normalize($language);
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['interface_language'] = $language;
        }
        return $language;
    }

    private function jsonLanguageFromRequest(): ?string
    {
        $contentType = (string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
        if (stripos($contentType, 'application/json') === false) {
            return null;
        }

        $raw = @file_get_contents('php://input');
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        foreach (['_lang', 'language', 'lang'] as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                return $data[$key];
            }
        }

        return null;
    }

    private function languageFromReferer(): ?string
    {
        $referer = (string)($_SERVER['HTTP_REFERER'] ?? '');
        if ($referer === '') {
            return null;
        }
        $path = parse_url($referer, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }
        $normalized = $this->siteResolver->normalizeUri($path);
        $language = $normalized['language'] ?? null;
        return is_string($language) ? $language : null;
    }

    private function isMultiLanguageHost(?string $host): bool
    {
        $host = $host ?? ApplicationUrl::requestHost();
        $host = $this->siteResolver->normalizeHost($host);
        $domainsByHost = $this->siteResolver->domainsByHost();
        return count($domainsByHost[$host] ?? []) > 1;
    }

    private function normalize(string $language): string
    {
        return trim(strtolower($language));
    }

    /**
     * @return array<string, string>
     */
    private function stringMap(string $key): array
    {
        $map = $this->languages[$key] ?? [];
        if (!is_array($map)) {
            return [];
        }
        $out = [];
        foreach ($map as $lang => $value) {
            $out[$this->normalize((string)$lang)] = (string)$value;
        }
        return $out;
    }
}

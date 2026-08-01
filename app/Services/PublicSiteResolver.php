<?php
namespace App\Services;

final class PublicSiteResolver
{
    /** @var array<string, mixed> */
    private array $config;

    /** @var array<string, mixed> */
    private array $languages;

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $languages
     */
    public function __construct(array $config, array $languages = [])
    {
        $this->config = $config;
        $this->languages = $languages;
    }

    /**
     * @return array<string, mixed>
     */
    public function current(?string $host = null, ?string $uri = null, ?string $requestedLanguage = null): array
    {
        $hostFull = $host ?? ApplicationUrl::requestHost();
        $hostNormalized = $this->normalizeHost($hostFull);
        $uri ??= (string)($_SERVER['REQUEST_URI'] ?? '/');
        
        // Normalizujemy URI - znajdujemy prefix i go zdejmujemy dla dalszej logiki (opcjonalnie)
        $normalized = $this->normalizeUri($uri);
        $path = $normalized['path'];
        $uriLanguage = $normalized['language'];

        $domainsByHost = $this->config['domains_by_host'] ?? [];
        $sites = $this->config['sites'] ?? [];

        // 1. Priorytet: requestedLanguage (np. przekazany z PublicLanguageService / ?lang)
        if ($requestedLanguage !== null) {
            $site = $this->siteForLanguage($requestedLanguage);
            if ($site) {
                return $this->withDomain($site, $hostFull, true);
            }
        }

        // 2. Priorytet: Język wykryty z prefixu URI
        if ($uriLanguage !== null) {
            $site = $this->siteForLanguage($uriLanguage);
            if ($site) {
                return $this->withDomain($site, $hostFull, true);
            }
        }

        // 3. Sprawdzamy czy domena jest jednoznacznie przypisana do konkretnego serwisu
        $hostToSearch = $hostNormalized;
        $matchingKeys = $domainsByHost[$hostToSearch] ?? [];
        if (empty($matchingKeys) && str_starts_with($hostNormalized, 'www.')) {
            $hostToSearch = substr($hostNormalized, 4);
            $matchingKeys = $domainsByHost[$hostToSearch] ?? [];
        }

        if (!empty($matchingKeys)) {
            // Jeżeli jesteśmy na domenie dedykowanej dla konkretnego języka (np. sourceofword.co.uk)
            if (count($matchingKeys) === 1) {
                return $this->withDomain($sites[$matchingKeys[0]], $hostFull, true);
            }

            // Jeżeli domena obsługuje wiele języków (np. localhost lub slowo-pisane.pl)
            // Przy braku prefixu (obsłużonego wyżej) wybieramy default_site (PL)
            $defaultKey = (string)($this->config['default_site'] ?? '');
            if (in_array($defaultKey, $matchingKeys)) {
                return $this->withDomain($sites[$defaultKey], $hostFull, true);
            }

            // Fallback do pierwszego site na tej domenie
            return $this->withDomain($sites[$matchingKeys[0]], $hostFull, true);
        }

        // 4. Fallback: default_site
        $defaultKey = (string)($this->config['default_site'] ?? 'pl_zrodlo_slowa');
        if (isset($sites[$defaultKey])) {
            return $this->withDomain($sites[$defaultKey], $hostFull, false);
        }

        return $this->defaultSite();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function domains(): array
    {
        $domains = $this->config['domains'] ?? [];
        return is_array($domains) ? $domains : [];
    }

    /**
     * @return array<string, list<string>>
     */
    public function domainsByHost(): array
    {
        $domainsByHost = $this->config['domains_by_host'] ?? [];
        return is_array($domainsByHost) ? $domainsByHost : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultSite(): array
    {
        $sites = $this->config['sites'] ?? [];
        $defaultKey = (string)($this->config['default_site'] ?? '');

        if (isset($sites[$defaultKey])) {
            $site = $sites[$defaultKey];
            return $this->withDomain($site, (string)($site['canonical_domain'] ?? $site['domain'] ?? 'slowo-pisane.pl'), false);
        }

        $domains = $this->domains();
        $firstDomain = array_key_first($domains);
        if ($firstDomain !== null) {
            return $this->withDomain($domains[$firstDomain], (string)$firstDomain, false);
        }

        return [
            'site_key' => 'pl_zrodlo_slowa',
            'brand_name' => $this->languages['brand_names']['pl'] ?? 'ŹRÓDŁO SŁOWA',
            'language' => $this->languages['default'] ?? 'pl',
            'flag_code' => $this->languages['flag_codes']['pl'] ?? 'PL',
            'canonical_domain' => 'zrodlo-slowa.pl',
            'domain' => 'zrodlo-slowa.pl',
            'is_known_domain' => false,
            'enabled_languages' => $this->languages['public_enabled'] ?? ['pl'],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function siteForLanguage(string $language): ?array
    {
        $language = $this->normalizeLanguage($language);
        $sites = $this->config['sites'] ?? [];
        
        // Najpierw szukamy site, który ma ten język i jest default_site
        $defaultKey = (string)($this->config['default_site'] ?? '');
        if (isset($sites[$defaultKey]) && $this->normalizeLanguage((string)($sites[$defaultKey]['language'] ?? '')) === $language) {
            return $this->withDomain($sites[$defaultKey], (string)($sites[$defaultKey]['canonical_domain'] ?? $sites[$defaultKey]['domain'] ?? ''), true);
        }

        foreach ($sites as $site) {
            if ($this->normalizeLanguage((string)($site['language'] ?? '')) === $language) {
                return $this->withDomain($site, (string)($site['canonical_domain'] ?? $site['domain'] ?? ''), true);
            }
        }
        return null;
    }

    public function languageUrl(string $language, ?string $currentUri = null, ?string $scheme = null): string
    {
        $site = $this->siteForLanguage($language);
        if ($site === null) {
            return '#';
        }

        $currentUri ??= (string)($_SERVER['REQUEST_URI'] ?? '/');
        $normalized = $this->normalizeUri($currentUri);
        $purePath = $normalized['path'];

        $domain = ApplicationUrl::requestHost();

        $parts = parse_url($purePath);
        $pathOnly = (string)($parts['path'] ?? '/');
        $query = (string)($parts['query'] ?? '');
        parse_str($query, $queryParams);
        unset($queryParams['lang']);

        $newPrefix = trim((string)($site['path_prefix'] ?? ''), '/');
        $isUniqueForDomain = false;
        $hostNormalized = $this->normalizeHost($domain);
        $matchingKeys = $this->config['domains_by_host'][$hostNormalized] ?? [];
        if (count($matchingKeys) === 1 && $matchingKeys[0] === ($site['site_key'] ?? '')) {
            $isUniqueForDomain = true;
        }

        $path = '/' . ltrim($pathOnly, '/');
        if ($newPrefix !== '' && !$isUniqueForDomain) {
            $path = '/' . $newPrefix . ($path === '/' ? '' : $path);
        }

        if ($queryParams !== []) {
            $path .= '?' . http_build_query($queryParams);
        }

        if ($scheme === null) {
            return $path;
        }

        return ApplicationUrl::absolute($path);
    }

    /**
     * Normalizuje URI, wyciągając prefix językowy i zwracając "czystą" ścieżkę dla routera.
     * @return array{path: string, language: string|null, prefix: string|null}
     */
    public function normalizeUri(string $uri): array
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $query = parse_url($uri, PHP_URL_QUERY);
        $path = '/' . ltrim($path, '/');
        
        $sites = $this->config['sites'] ?? [];
        foreach ($sites as $site) {
            $prefix = trim((string)($site['path_prefix'] ?? ''), '/');
            if ($prefix === '') continue;

            $prefixPath = '/' . $prefix;
            if ($path === $prefixPath || str_starts_with($path, $prefixPath . '/')) {
                $cleanPath = substr($path, strlen($prefixPath));
                if ($cleanPath === '') $cleanPath = '/';
                if (!str_starts_with($cleanPath, '/')) $cleanPath = '/' . $cleanPath;
                
                $fullCleanPath = $cleanPath . ($query ? '?' . $query : '');
                
                return [
                    'path' => $fullCleanPath,
                    'language' => (string)($site['language'] ?? 'pl'),
                    'prefix' => $prefix
                ];
            }
        }

        return [
            'path' => $path . ($query ? '?' . $query : ''),
            'language' => null,
            'prefix' => null
        ];
    }

    public function normalizeHost(string $host): string
    {
        $host = trim(strtolower($host));
        if ($host === '') {
            return '';
        }
        if (str_contains($host, ':')) {
            $host = explode(':', $host, 2)[0];
        }
        return trim($host, '.');
    }

    private function normalizeLanguage(string $language): string
    {
        return trim(strtolower($language));
    }

    /**
     * @param array<string, mixed> $site
     * @return array<string, mixed>
     */
    private function withDomain(array $site, string $domain, bool $isKnownDomain): array
    {
        $site['domain'] = $domain;
        $site['is_known_domain'] = $isKnownDomain;
        $site['canonical_domain'] = $site['canonical_domain'] ?? $domain;
        $site['language'] = $this->normalizeLanguage((string)($site['language'] ?? ($this->languages['default'] ?? 'pl')));
        $site['flag_code'] = (string)($site['flag_code'] ?? ($this->languages['flag_codes'][$site['language']] ?? strtoupper($site['language'])));
        $site['brand_name'] = (string)($site['brand_name'] ?? ($this->languages['brand_names'][$site['language']] ?? 'ŹRÓDŁO SŁOWA'));
        return $site;
    }
}

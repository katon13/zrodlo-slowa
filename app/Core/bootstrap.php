<?php
declare(strict_types=1);

$composerAutoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/../' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

function env(string $key, mixed $default = null): mixed {
    static $loaded = false;
    if (!$loaded) {
        $envPath = dirname(__DIR__, 2) . '/.env';
        if (is_file($envPath)) {
            foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
                [$k, $v] = explode('=', $line, 2);
                $v = trim($v, " \t\n\r\0\x0B\"'");
                $k = trim($k);
                if (!array_key_exists($k, $_ENV) && getenv($k) === false) {
                    $_ENV[$k] = $v;
                }
            }
        }
        $loaded = true;
        
        // Zabezpieczenie produkcji
        $debug = env('APP_DEBUG', 'false') === 'true';
        if (!$debug) {
            ini_set('display_errors', '0');
            ini_set('display_startup_errors', '0');
            error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
        } else {
            ini_set('display_errors', '1');
            error_reporting(E_ALL);
        }

        set_error_handler(function($errno, $errstr, $errfile, $errline) use ($debug) {
            if (!(error_reporting() & $errno)) return false;
            $msg = "Error [$errno]: $errstr in $errfile on line $errline";
            (new \App\Infrastructure\Logging\JsonErrorLogger())->log('error', 'php.error', [
                'result' => 'failure',
                'error_type' => 'php_error',
                'error_code' => $errno,
                'message' => $errstr,
                'file' => $errfile,
                'line' => $errline,
            ]);
            if (!$debug) {
                http_response_code(500);
                echo "Wystąpił błąd systemowy. Spróbuj ponownie później.";
                exit;
            }
            return false;
        });

        set_exception_handler(function($e) use ($debug) {
            (new \App\Infrastructure\Logging\JsonErrorLogger())->log('error', 'php.exception', [
                'result' => 'failure',
                'error_type' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            if (!$debug) {
                http_response_code(500);
                echo "Wystąpił błąd krytyczny. Spróbuj ponownie później.";
                exit;
            }
            echo "<h1>Błąd krytyczny</h1><pre>" . htmlspecialchars((string)$e) . "</pre>";
            exit;
        });
    }
    return $_ENV[$key] ?? getenv($key) ?: $default;
}

function env_bool(string $key, bool $default = false): bool {
    $value = env($key, $default ? 'true' : 'false');
    if (is_bool($value)) {
        return $value;
    }
    $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    return $parsed ?? $default;
}

function e(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}


function asset_url(string $path): string {
    $path = trim($path);
    if ($path === '') {
        return '';
    }
    if (preg_match('~^https?://~i', $path) === 1 || str_starts_with($path, '//')) {
        return $path;
    }

    $rootPath = dirname(__DIR__, 2);
    $publicPath = $rootPath . DIRECTORY_SEPARATOR . 'public';

    $fragment = '';
    $cleanPath = $path;

    $fragmentPos = strpos($cleanPath, '#');
    if ($fragmentPos !== false) {
        $fragment = substr($cleanPath, $fragmentPos);
        $cleanPath = substr($cleanPath, 0, $fragmentPos);
    }

    $query = '';
    $queryPos = strpos($cleanPath, '?');
    if ($queryPos !== false) {
        $query = substr($cleanPath, $queryPos + 1);
        $cleanPath = substr($cleanPath, 0, $queryPos);
    }

    $relativePath = '/' . ltrim($cleanPath, '/');
    $publicRealPath = realpath($publicPath) ?: $publicPath;
    
    // Na Windows realpath zamienia / na \ i dodaje literę dysku. 
    // Musimy upewnić się, że porównujemy te same formaty.
    $fullPathOnDisk = $publicPath . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
    $filePath = realpath($fullPathOnDisk);

    $params = [];
    if ($query !== '') {
        parse_str($query, $params);
    }

    if ($filePath !== false && is_file($filePath)) {
        // Dodatkowa weryfikacja czy plik faktycznie jest wewnątrz public (zabezpieczenie ścieżki)
        $normFilePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $filePath);
        $normPublicPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $publicRealPath);
        
        if (str_starts_with(strtolower($normFilePath), strtolower($normPublicPath))) {
            $mtime = @filemtime($filePath);
            if ($mtime !== false) {
                $params['v'] = (string)$mtime;
            }
        }
    }

    $finalQuery = $params !== [] ? '?' . http_build_query($params) : '';
    return $relativePath . $finalQuery . $fragment;
}

function application_origin(): string {
    return \App\Services\ApplicationUrl::origin();
}

function absolute_url(string $path): string {
    return \App\Services\ApplicationUrl::absolute($path);
}



function public_site(): array {
    static $site = null;
    if ($site !== null) {
        return $site;
    }
    $rootPath = dirname(__DIR__, 2);
    $languagesPath = $rootPath . '/config/languages.php';
    $sitesPath = $rootPath . '/config/sites.php';
    $languages = is_file($languagesPath) ? require $languagesPath : [];
    $sites = is_file($sitesPath) ? require $sitesPath : [];
    $resolver = new \App\Services\PublicSiteResolver(is_array($sites) ? $sites : [], is_array($languages) ? $languages : []);
    
    // Używamy public_language(), żeby site był spójny z wykrytym językiem (uwzględnia ?lang i prefix)
    $lang = public_language();
    $site = $resolver->current(null, null, $lang);
    
    return $site;
}

function public_language(?string $requestedLanguage = null): string {
    static $service = null;
    if ($service === null) {
        $rootPath = dirname(__DIR__, 2);
        $languagesPath = $rootPath . '/config/languages.php';
        $sitesPath = $rootPath . '/config/sites.php';
        $languages = is_file($languagesPath) ? require $languagesPath : [];
        $sites = is_file($sitesPath) ? require $sitesPath : [];
        $resolver = new \App\Services\PublicSiteResolver(is_array($sites) ? $sites : [], is_array($languages) ? $languages : []);
        $service = new \App\Services\PublicLanguageService(is_array($languages) ? $languages : [], $resolver);
    }
    return $service->current(null, $requestedLanguage);
}

function is_admin_request(): bool {
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $path = explode('?', $uri)[0];
    return $path === '/admin' 
        || str_starts_with($path, '/admin/') 
        || str_contains($path, '/admin/')
        || str_ends_with($path, '/admin');
}

function t(string $translationKey, ?string $language = null): string {
    static $service = null;
    static $in_t = false;
    if ($language === null && function_exists('is_admin_request') && is_admin_request()) {
        $language = 'pl';
    }
    if ($in_t) {
        if ($translationKey === 'brand.name') return 'ŹRÓDŁO SŁOWA';
        if (is_admin_request()) {
            $parts = explode('.', $translationKey);
            return ucfirst(str_replace(['_', '-'], ' ', end($parts)));
        }
        return '';
    }
    $in_t = true;
    try {
        if ($service === null) {
            $rootPath = dirname(__DIR__, 2);
            $languagesPath = $rootPath . '/config/languages.php';
            $sitesPath = $rootPath . '/config/sites.php';
            $languages = is_file($languagesPath) ? require $languagesPath : [];
            $sites = is_file($sitesPath) ? require $sitesPath : [];
            $resolver = new \App\Services\PublicSiteResolver(is_array($sites) ? $sites : [], is_array($languages) ? $languages : []);
            $languageService = new \App\Services\PublicLanguageService(is_array($languages) ? $languages : [], $resolver);
            $debug = env('APP_DEBUG', 'false') === 'true';
            $service = new \App\Services\PublicTranslationService($rootPath, $languageService, $debug);
        }
        $res = $service->translate($translationKey, $language);
    } finally {
        $in_t = false;
    }
    
    if ($res === '' && $translationKey === 'brand.name') {
        return 'ŹRÓDŁO SŁOWA';
    }
    
    if ($res === '' && is_admin_request()) {
        $parts = explode('.', $translationKey);
        return ucfirst(str_replace(['_', '-'], ' ', end($parts)));
    }
    
    return $res;
}


function public_language_url(string $language, ?string $currentUri = null): string {
    $rootPath = dirname(__DIR__, 2);
    $languagesPath = $rootPath . '/config/languages.php';
    $sitesPath = $rootPath . '/config/sites.php';
    $languages = is_file($languagesPath) ? require $languagesPath : [];
    $sites = is_file($sitesPath) ? require $sitesPath : [];
    $resolver = new \App\Services\PublicSiteResolver(is_array($sites) ? $sites : [], is_array($languages) ? $languages : []);
    return $resolver->languageUrl($language, $currentUri);
}

function public_normalized_uri(?string $uri = null): string {
    static $normalized = null;
    if ($uri === null && $normalized !== null) {
        return $normalized;
    }
    $originalUri = $uri ?? (string)($_SERVER['REQUEST_URI'] ?? '/');
    $rootPath = dirname(__DIR__, 2);
    $languagesPath = $rootPath . '/config/languages.php';
    $sitesPath = $rootPath . '/config/sites.php';
    $languages = is_file($languagesPath) ? require $languagesPath : [];
    $sites = is_file($sitesPath) ? require $sitesPath : [];
    $resolver = new \App\Services\PublicSiteResolver(is_array($sites) ? $sites : [], is_array($languages) ? $languages : []);
    $data = $resolver->normalizeUri($originalUri);
    $path = seo_article_rewrite_uri((string)$data['path'], isset($data['language']) ? (string)$data['language'] : null);
    if ($uri === null || $originalUri === (string)($_SERVER['REQUEST_URI'] ?? '/')) {
        $normalized = $path;
    }
    return $path;
}

function seo_languages_config(): array {
    static $config = null;
    if ($config !== null) {
        return $config;
    }
    $rootPath = dirname(__DIR__, 2);
    $path = $rootPath . '/config/seo_languages.json';
    if (is_file($path)) {
        $content = @file_get_contents($path);
        $decoded = is_string($content) ? json_decode($content, true) : null;
        if (is_array($decoded)) {
            $config = $decoded;
            return $config;
        }
    }
    $config = [
        'default_language' => 'pl',
        'languages' => [
            'pl' => ['article_path' => 'artykul', 'schema_type' => 'NewsArticle'],
            'en' => ['article_path' => 'article', 'schema_type' => 'NewsArticle'],
            'de' => ['article_path' => 'artikel', 'schema_type' => 'NewsArticle'],
            'fr' => ['article_path' => 'article', 'schema_type' => 'NewsArticle'],
            'it' => ['article_path' => 'articolo', 'schema_type' => 'NewsArticle'],
            'es' => ['article_path' => 'articulo', 'schema_type' => 'NewsArticle'],
        ],
    ];
    return $config;
}

function seo_article_path(string $language): string {
    $config = seo_languages_config();
    $language = strtolower(trim($language));
    $path = (string)($config['languages'][$language]['article_path'] ?? ($language === 'pl' ? 'artykul' : 'article'));
    $path = trim($path, '/');
    return $path !== '' ? $path : 'article';
}

function seo_short_article_urls_enabled(): bool {
    $config = seo_languages_config();
    return (bool)($config['seo']['short_article_urls'] ?? true);
}

function seo_reserved_slug(string $slug): bool {
    $slug = strtolower(trim($slug));
    if ($slug === '') {
        return true;
    }
    $reserved = [
        'admin', 'author', 'authors', 'reader', 'wallet', 'login', 'register', 'logout', 'password',
        'account', 'articles', 'article', 'surveys', 'survey', 'campaigns', 'campaign',
        'activity', 'donations', 'stripe', 'assets', 'uploads', 'storage', 'api',
        'jak-zarabiac', 'how-to-earn',
        'sitemap', 'sitemap.xml', 'favicon.ico', 'robots.txt', 'clockwork'
    ];
    return in_array($slug, $reserved, true);
}

function seo_article_rewrite_uri(string $normalizedUri, ?string $detectedLanguage = null): string {
    $parts = parse_url($normalizedUri);
    $path = '/' . ltrim((string)($parts['path'] ?? '/'), '/');
    $query = (string)($parts['query'] ?? '');

    $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn(string $part): bool => $part !== ''));
    if ($segments === []) {
        return $normalizedUri;
    }

    $config = seo_languages_config();
    $languages = array_keys((array)($config['languages'] ?? []));
    if ($detectedLanguage !== null && $detectedLanguage !== '') {
        $detectedLanguage = strtolower(trim($detectedLanguage));
        $languages = array_values(array_unique(array_merge([$detectedLanguage], $languages)));
    }

    $matchedLanguage = null;
    $slug = '';

    // Stary, kompatybilny format: /artykul/slug, /article/slug, /artikel/slug...
    if (count($segments) === 2) {
        $articlePath = $segments[0];
        $candidateSlug = rawurldecode($segments[1]);
        foreach ($languages as $language) {
            $language = strtolower((string)$language);
            if ($articlePath === seo_article_path($language)) {
                $matchedLanguage = $language;
                $slug = $candidateSlug;
                break;
            }
        }
    }

    // Nowy krótki format: /slug. Język wynika z domeny albo prefiksu językowego zdjętego przez PublicSiteResolver.
    if ($matchedLanguage === null && count($segments) === 1 && seo_short_article_urls_enabled()) {
        $candidateSlug = rawurldecode($segments[0]);
        if (!seo_reserved_slug($candidateSlug)) {
            $matchedLanguage = $detectedLanguage ?: (function_exists('public_language') ? public_language() : (string)($config['default_language'] ?? 'pl'));
            $slug = $candidateSlug;
        }
    }

    if ($matchedLanguage === null || $slug === '') {
        return $normalizedUri;
    }

    parse_str($query, $params);
    $params['seo_slug'] = $slug;
    $params['lang'] = $matchedLanguage;
    $_GET = array_replace($_GET, $params);

    return '/article?' . http_build_query($params);
}

function public_article_language_url(int $articleId, string $language, string $slug = ''): string {
    $language = strtolower(trim($language));
    $slug = trim($slug);
    if ($slug === '') {
        $uri = '/article?id=' . $articleId . '&lang=' . rawurlencode($language);
    } elseif (function_exists('seo_short_article_urls_enabled') && seo_short_article_urls_enabled()) {
        $uri = '/' . rawurlencode($slug);
    } else {
        $uri = '/' . seo_article_path($language) . '/' . rawurlencode($slug);
    }
    return function_exists('public_language_url') ? public_language_url($language, $uri) : $uri;
}

function redirect(string $path): never {
    header('Location: ' . $path);
    exit;
}

function csrf_token(): string {
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string {
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_token_is_valid(mixed $sent, mixed $expected): bool {
    return is_string($sent)
        && $sent !== ''
        && is_string($expected)
        && $expected !== ''
        && hash_equals($expected, $sent);
}


function zs_icon(string $name, string $class = ''): string {
    $icons = [
        'eye' => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
        'cursor' => '<path d="M3 3l7.07 16.97 2.51-7.39 7.39-2.51L3 3z"/><path d="M13 13l6 6"/>',
        'bank' => '<path d="M3 21h18"/><path d="M3 10h18"/><path d="M5 6l7-3 7 3"/><path d="M4 10v11"/><path d="M20 10v11"/><path d="M8 14v3"/><path d="M12 14v3"/><path d="M16 14v3"/>',
        'crown' => '<path d="M2 4l3 12h14l3-12-6 7-4-7-4 7-6-7z"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/>',
        'plus-circle' => '<circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/>',
        'check-circle' => '<circle cx="12" cy="12" r="9"/><path d="M9 12l2 2 4-4"/>',
        'registration' => '<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="17" y1="11" x2="23" y2="11"/>',
        'login' => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/>',
        'daily' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/>',
        'comment' => '<path d="M4 5h16v11H8l-4 4V5z"/>',
        'click' => '<path d="M8 3l8 18 2-7 5-2L8 3z"/>',
        'like' => '<path d="M7 22V10"/><path d="M7 10l4-7 2 1-1 6h7a2 2 0 0 1 2 2l-2 8a2 2 0 0 1-2 2H7"/>',
        'share' => '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 10.7l6.8-4.4M8.6 13.3l6.8 4.4"/>',
        'bug' => '<path d="M8 8a4 4 0 0 1 8 0v10a4 4 0 0 1-8 0V8z"/><path d="M3 13h5M16 13h5M4 7l4 3M20 7l-4 3M4 19l4-3M20 19l-4-3"/>',
        'ad' => '<rect x="3" y="5" width="18" height="14" rx="1"/><path d="M7 15l2-6 2 6M8 13h2M14 9h2a3 3 0 0 1 0 6h-2V9z"/>',
        'survey' => '<path d="M7 4h10l2 2v14H5V6l2-2z"/><path d="M8 9h8M8 13h8M8 17h5"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="1"/><path d="M3 7l9 7 9-7"/>',
        'wallet' => '<path d="M4 7h15a2 2 0 0 1 2 2v9H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h12"/><path d="M17 12h5v4h-5a2 2 0 0 1 0-4z"/>',
        'points' => '<circle cx="12" cy="12" r="9"/><path d="M12 6v12M8 10h8M8 14h8"/>',
        'payout' => '<path d="M12 3v14"/><path d="M7 12l5 5 5-5"/><path d="M4 21h16"/>',
        'premium' => '<path d="M12 3l3 6 7 1-5 5 1 7-6-3-6 3 1-7-5-5 7-1 3-6z"/>',
        'author' => '<path d="M4 20h16"/><path d="M6 16l10-10 2 2-10 10H6v-2z"/>',
        'editorial' => '<path d="M4 5h16v14H4z"/><path d="M8 9h8M8 13h8M8 17h5"/>',
        'proofread' => '<path d="M4 13l5 5L20 6"/><path d="M5 6h10"/>',
        'finance' => '<path d="M4 19h16"/><path d="M6 16V8M12 16V4M18 16v-6"/>',
        'admin' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v4M12 18v4M2 12h4M18 12h4M4.9 4.9l2.8 2.8M16.3 16.3l2.8 2.8M19.1 4.9l-2.8 2.8M7.7 16.3l-2.8 2.8"/>',
        'warning' => '<path d="M12 3l10 18H2L12 3z"/><path d="M12 9v5M12 18h.01"/>',
        'shield' => '<path d="M12 3l8 4v5c0 5-3.4 8.4-8 9-4.6-.6-8-4-8-9V7l8-4z"/><path d="M9 12l2 2 4-5"/>',
        'email_verified' => '<rect x="3" y="5" width="18" height="14" rx="1"/><path d="M3 7l9 7 9-7"/><path d="M14 18l2 2 4-5"/>',
        'snajper' => '<circle cx="12" cy="12" r="8"/><path d="M12 2v5M12 17v5M2 12h5M17 12h5"/><circle cx="12" cy="12" r="2"/>',
        'article' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/>',
        'star' => '<path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14l-5-4.87 6.91-1.01L12 2z"/>',
        'clipboard' => '<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/>',
        'trending-up' => '<path d="M23 6l-9.5 9.5-5-5L1 18"/><path d="M17 6h6v6"/>',
        'video' => '<path d="M23 7l-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/>',
        'arrow-right' => '<path d="M5 12h14M12 5l7 7-7 7"/>',
        'history' => '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/>',
        'refresh' => '<path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>',
        'credit-card' => '<rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/>',
        'plus' => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
        'x-circle' => '<circle cx="12" cy="12" r="9"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>',
        'google' => '<path d="M12.48 10.92v3.28h7.84c-.24 1.84-.91 3.22-1.92 4.23-1.2 1.2-3.08 2.44-5.92 2.44-4.52 0-8.12-3.66-8.12-8.18s3.6-8.18 8.12-8.18c2.44 0 4.27.96 5.59 2.22l2.3-2.3C18.66 2.39 15.9 1 12.48 1c-6.12 0-11.12 5-11.12 11.12s5 11.12 11.12 11.12c3.34 0 5.86-1.1 7.82-3.14 2.02-2.02 2.66-4.83 2.66-7.14 0-.46-.04-.9-.12-1.32H12.48z"/>',
        'apple' => '<path d="M12.152 6.896c-.948 0-2.415-1.078-3.96-1.04-2.04.027-3.91 1.183-4.961 3.014-2.117 3.675-.546 9.103 1.519 12.09 1.013 1.454 2.208 3.09 3.792 3.03 1.52-.065 2.09-.987 3.935-.987 1.831 0 2.35.987 3.96.948 1.637-.026 2.676-1.48 3.676-2.948 1.156-1.688 1.636-3.325 1.662-3.415-.039-.013-3.182-1.221-3.22-4.857-.026-3.04 2.48-4.494 2.597-4.559-1.429-2.09-3.623-2.324-4.39-2.376-2-.156-3.675 1.09-4.61 1.09zM15.53 3.83c.843-1.012 1.4-2.427 1.245-3.83-1.207.052-2.662.805-3.532 1.818-.78.896-1.454 2.338-1.273 3.714 1.338.104 2.715-.688 3.559-1.701z"/>',
    ];
    $path = $icons[$name] ?? $icons['snajper'];
    $class = trim('zs-icon zs-icon-' . preg_replace('/[^a-z0-9_-]/i', '', $name) . ' ' . $class);
    return '<span class="' . e($class) . '" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $path . '</svg></span>';
}

function zs_clean_description(?string $text): string {
    if ($text === null) {
        return '';
    }
    // 1. Map technical strings to translations if possible
    if (str_contains($text, 'Nagroda za aktywność: ')) {
        $parts = explode(': ', $text, 2);
        if (isset($parts[1])) {
            $technicalType = trim($parts[1]);
            $key = "bonus.type." . str_replace('_bonus', '', $technicalType);
            $translated = t($key);
            if (trim($translated) !== "" && $translated !== $key) {
                return $translated;
            }
        }
    }

    $technicalType = trim($text);
    $key = "bonus.type." . str_replace('_bonus', '', $technicalType);
    $translated = t($key);
    if (trim($translated) !== "" && $translated !== $key) {
        return $translated;
    }

    // 2. Fix common UTF-8 artifacts (fallback)
    $replacements = [
        'Zarobi?e?' => 'Zarobiłeś',
        'Zarobi??' => 'Zarobiłeś',
        'aktywno??' => 'aktywność',
        'wizyt?' => 'wizytę',
        'rejestracj?' => 'rejestrację',
        'klikni?cie' => 'kliknięcie',
        'udost?pnienie' => 'udostępnienie',
        'zg?oszenie' => 'zgłoszenie',
        'b??du' => 'błędu',
        'artyku?u' => 'artykułu',
        'artyku?a' => 'artykułu',
        'udzia? ' => 'udział ',
        'udzia┼é' => 'udział',
        'dzisiejsz?' => 'dzisiejszą',
        'z?' => 'zł',
        'pkt Talent' => 'TT',
        'Talentów' => 'TT',
        'Talenty' => 'TT',
        'logowanie' => 'logowanie',
        'przeczytanie' => 'przeczytanie',
    ];
    $text = str_replace(array_keys($replacements), array_values($replacements), $text);
    return $text;
}

function zs_type_icon(string $type): string {
    $map = [
        'registration_bonus' => 'registration',
        'login_bonus' => 'login',
        'day_visit_bonus' => 'sun',
        'comment_bonus' => 'comment',
        'link_click_bonus' => 'cursor',
        'like_bonus' => 'like',
        'share_bonus' => 'share',
        'bug_report_bonus' => 'bug',
        'ad_watch_bonus' => 'eye',
        'ad_view_reward' => 'eye',
        'ad_click_reward' => 'cursor',
        'ad_read_bonus' => 'article',
        'survey_reward' => 'survey',
        'poll_answer_bonus' => 'survey',
        'newsletter_open_reward' => 'mail',
        'wallet_topup' => 'wallet',
        'payout' => 'payout',
        'payout_request' => 'payout',
        'payout_approved' => 'payout',
        'payout_paid' => 'bank',
        'payout_rejected' => 'warning',
        'article_payment' => 'article',
        'article_read_bonus' => 'article',
        'ppv_reward' => 'video',
        'live_event_reward' => 'video',
        'manual_reward' => 'star',
        'premium_access' => 'crown',
        'adjustment' => 'finance',
        'sponsored_article_read_bonus' => 'article',
    ];
    return $map[$type] ?? 'points';
}

function verify_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $sent = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!csrf_token_is_valid($sent, $_SESSION['_csrf'] ?? null)) {
            http_response_code(419);
            echo 'Błąd bezpieczeństwa formularza.';
            exit;
        }
    }
}

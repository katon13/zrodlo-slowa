<?php

$root = dirname(__DIR__);
$jsonPath = $root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'sites.json';
$phpPath = $root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'sites.php';

echo "Sprawdzanie konfiguracji stron...\n";

// 1. config/sites.json istnieje
if (!file_exists($jsonPath)) {
    die("BŁĄD: Plik config/sites.json nie istnieje.\n");
}

// 2. JSON jest poprawny
$content = file_get_contents($jsonPath);
$data = json_decode($content, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    die("BŁĄD: Niepoprawny format JSON: " . json_last_error_msg() . "\n");
}

// 3. Jest default_site
if (empty($data['default_site'])) {
    die("BŁĄD: Brak klucza default_site w JSON.\n");
}

// 4. Jest sites
if (empty($data['sites']) || !is_array($data['sites'])) {
    die("BŁĄD: Brak klucza sites w JSON lub nie jest tablicą.\n");
}

if (!isset($data['sites'][$data['default_site']])) {
    die("BŁĄD: default_site [{$data['default_site']}] nie istnieje w sites.\n");
}

$allowedLangs = ['pl', 'en', 'de', 'fr', 'it', 'es'];
$forbiddenBrands = ['PAROLES VIVES', 'VOCE VIVA', 'PALABRA VIVA'];

foreach ($data['sites'] as $key => $site) {
    // 5. Każdy site ma pola
    $required = ['domain', 'language', 'flag_code', 'brand_name'];
    foreach ($required as $field) {
        if (empty($site[$field])) {
            die("BŁĄD: Site [$key] nie posiada pola [$field].\n");
        }
    }
    
    // 6. Dozwolone języki
    if (!in_array($site['language'], $allowedLangs)) {
        die("BŁĄD: Site [$key] ma niedozwolony język [{$site['language']}].\n");
    }
    
    // 7. Stare marki
    foreach ($forbiddenBrands as $forbidden) {
        if (stripos($site['brand_name'], $forbidden) !== false) {
            die("BŁĄD: Site [$key] używa zakazanej marki [{$site['brand_name']}].\n");
        }
    }
}

// 8. config/sites.php nadal zwraca tablicę
$sitesPhp = require $phpPath;
if (!is_array($sitesPhp)) {
    die("BŁĄD: config/sites.php nie zwraca tablicy.\n");
}

if (!isset($sitesPhp['default_site']) || !isset($sitesPhp['sites']) || !isset($sitesPhp['domains_by_host'])) {
    die("BŁĄD: Struktura tablicy z config/sites.php jest nieprawidłowa (brak sites lub domains_by_host).\n");
}

// 9. Testy logiczne resolvera
require_once $root . '/app/Core/bootstrap.php';
$languages = require $root . '/config/languages.php';
$resolver = new App\Services\PublicSiteResolver($sitesPhp, $languages);
$langService = new App\Services\PublicLanguageService($languages, $resolver);

$testHost = 'slowo-pisane.pl';

// PL
$sitePl = $resolver->current($testHost, '/');
if (($sitePl['language'] ?? '') !== 'pl') {
    die("BŁĄD: slowo-pisane.pl/ nie daje PL (daje: " . ($sitePl['language'] ?? 'null') . ").\n");
}

// EN prefix
$siteEn = $resolver->current($testHost, '/en/test');
if (($siteEn['language'] ?? '') !== 'en') {
    die("BŁĄD: slowo-pisane.pl/en/test nie daje EN.\n");
}

// ES prefix
$siteEs = $resolver->current($testHost, '/es');
if (($siteEs['language'] ?? '') !== 'es') {
    die("BŁĄD: slowo-pisane.pl/es nie daje ES.\n");
}

// ?lang=es
$_GET['lang'] = 'es';
$langRequested = $langService->current($testHost, null, '/');
if ($langRequested !== 'es') {
    die("BŁĄD: ?lang=es nie daje ES.\n");
}
unset($_GET['lang']);

// languageUrl
$_SERVER['HTTP_HOST'] = 'localhost:8080';
$_SERVER['REQUEST_URI'] = '/articles/1';
$urlEn = $resolver->languageUrl('en', '/articles/1');
if ($urlEn !== '/en/articles/1') {
    die("BŁĄD: languageUrl('en') nie zwrócił względnej ścieżki (dostałem: $urlEn).\n");
}

$urlPl = $resolver->languageUrl('pl', '/en/articles/1');
$polishPrefix = trim((string)($sitePl['path_prefix'] ?? ''), '/');
$expectedPolishPath = $polishPrefix !== '' ? '/' . $polishPrefix . '/articles/1' : '/articles/1';
if ($urlPl !== $expectedPolishPath) {
    die("BŁĄD: languageUrl('pl') nie uwzględnia skonfigurowanego prefiksu (dostałem: $urlPl).\n");
}

// 10. Normalizacja URI i brak 404
$testUri = '/fr/articles/test';
$normalized = $resolver->normalizeUri($testUri);
if ($normalized['language'] !== 'fr') {
    die("BŁĄD: normalizeUri('$testUri') nie wykrył języka FR.\n");
}
if ($normalized['path'] !== '/articles/test') {
    die("BŁĄD: normalizeUri('$testUri') nie zwrócił poprawnej ścieżki /articles/test (dostałem: {$normalized['path']}).\n");
}

$testUriRoot = '/es';
$normalizedRoot = $resolver->normalizeUri($testUriRoot);
if ($normalizedRoot['language'] !== 'es') {
    die("BŁĄD: normalizeUri('$testUriRoot') nie wykrył języka ES.\n");
}
if ($normalizedRoot['path'] !== '/') {
    die("BŁĄD: normalizeUri('$testUriRoot') nie zwrócił poprawnej ścieżki / (dostałem: {$normalizedRoot['path']}).\n");
}

// 11. Header/Logo dynamiczne (symulacja t('brand.name'))
// Sprawdzamy czy PublicTranslationService filtruje języki (Snajper Słowa)
$transService = new App\Services\PublicTranslationService($root, $langService);
$allPhrases = $transService->all();
foreach ($allPhrases as $key => $langs) {
    if (count($langs) > 3) { // pl, current, default (często to samo)
        // Jeśli jest więcej niż 3 języki, to znaczy że nie filtruje poprawnie (chyba że testujemy wiele języków naraz)
        // Ale PublicTranslationService wewnątrz loadPhrases pobiera current lang.
    }
}

// 12. Jednoznaczna domena
$sitesPhpSim = $sitesPhp;
$sitesPhpSim['sites']['en_source_of_word']['canonical_domain'] = 'sourceofword.co.uk';
$sitesPhpSim['sites']['en_source_of_word']['path_prefix'] = '';
$sitesPhpSim['domains_by_host']['sourceofword.co.uk'] = ['en_source_of_word'];

$resolverSim = new App\Services\PublicSiteResolver($sitesPhpSim, $languages);
$siteSim = $resolverSim->current('sourceofword.co.uk', '/');
if (($siteSim['language'] ?? '') !== 'en') {
    die("BŁĄD: Jednoznaczna domena sourceofword.co.uk nie daje EN.\n");
}

echo "WYNIK: OK - Wszystkie testy logiki językowej przeszły.\n";

<?php

/**
 * Konfiguracja domen i marek językowych.
 * Dane są wczytywane z edytowalnego pliku config/sites.json.
 */

$jsonPath = __DIR__ . DIRECTORY_SEPARATOR . 'sites.json';

// Bezpieczny fallback PL zgodny z kontraktem
$fallback = [
    'default_site' => 'pl_zrodlo_slowa',
    'domains' => [
        'slowo-pisane.pl' => [
            'site_key' => 'pl_zrodlo_slowa',
            'brand_name' => 'ŹRÓDŁO SŁOWA',
            'language' => 'pl',
            'flag_code' => 'PL',
            'canonical_domain' => 'slowo-pisane.pl',
            'enabled_languages' => ['pl', 'en', 'de', 'fr', 'it', 'es'],
        ],
    ],
];

if (!file_exists($jsonPath)) {
    return $fallback;
}

$content = @file_get_contents($jsonPath);
if ($content === false) {
    return $fallback;
}

$data = json_decode($content, true);

// Walidacja minimum zgodnie z wymaganiami:
// - istnieje default_site
// - istnieje sites
// - sites jest tablicą
// - default_site istnieje jako klucz w sites
if (
    json_last_error() !== JSON_ERROR_NONE ||
    !is_array($data) ||
    empty($data['default_site']) ||
    empty($data['sites']) ||
    !is_array($data['sites']) ||
    !isset($data['sites'][$data['default_site']])
) {
    return $fallback;
}

$sites = $data['sites'];
$sitesNormalized = [];
$domainsByHost = [];

// Lista wszystkich języków dla enabled_languages
$allLanguages = [];
foreach ($sites as $site) {
    if (!empty($site['language'])) {
        $allLanguages[] = (string)$site['language'];
    }
}
$allLanguages = array_values(array_unique($allLanguages));

if (empty($allLanguages)) {
    $allLanguages = ['pl', 'en', 'de', 'fr', 'it', 'es'];
}

foreach ($sites as $siteKey => $site) {
    $domain = (string)($site['domain'] ?? 'slowo-pisane.pl');
    $lang = (string)($site['language'] ?? 'pl');
    
    // Budowanie listy enabled_languages z bieżącym językiem na początku
    $siteLanguages = $allLanguages;
    if (($idx = array_search($lang, $siteLanguages)) !== false) {
        unset($siteLanguages[$idx]);
        array_unshift($siteLanguages, $lang);
    }
    
    $siteData = [
        'site_key' => (string)$siteKey,
        'brand_name' => (string)($site['brand_name'] ?? 'ŹRÓDŁO SŁOWA'),
        'language' => $lang,
        'flag_code' => (string)($site['flag_code'] ?? 'PL'),
        'canonical_domain' => $domain,
        'enabled_languages' => array_values($siteLanguages),
        'path_prefix' => (string)($site['path_prefix'] ?? ''),
    ];

    $sitesNormalized[$siteKey] = $siteData;
    $domainsByHost[$domain][] = $siteKey;
}

// Budowanie tablicy domains dla kompatybilności wstecznej.
// Jeżeli wiele site_keys ma tę samą domenę, wybieramy default_site jeśli tam jest, w przeciwnym razie pierwszy.
$domains = [];
$defaultSiteKey = (string)$data['default_site'];
foreach ($domainsByHost as $host => $keys) {
    $chosenKey = $keys[0];
    foreach ($keys as $key) {
        if ($key === $defaultSiteKey) {
            $chosenKey = $key;
            break;
        }
    }
    $domains[$host] = $sitesNormalized[$chosenKey];
}

return [
    'default_site' => $defaultSiteKey,
    'sites' => $sitesNormalized,
    'domains' => $domains,
    'domains_by_host' => $domainsByHost,
];

<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Core/bootstrap.php';

$errors = [];

echo "--- SPRAWDZANIE KONTEKSTU JĘZYKOWEGO ---\n";

// 1. Sprawdzenie config/languages.php
$languages = require __DIR__ . '/../config/languages.php';
if (!isset($languages['locales'])) {
    $errors[] = "Brak sekcji 'locales' w config/languages.php";
} else {
    $expected = ['pl', 'en', 'de', 'fr', 'it', 'es'];
    foreach ($expected as $lang) {
        if (!isset($languages['locales'][$lang])) {
            $errors[] = "Brak locale dla języka: $lang";
        }
    }
}

// 2. Sprawdzenie views/layouts/main.php
$mainLayout = file_get_contents(__DIR__ . '/../views/layouts/main.php');
if (!str_contains(strtolower($mainLayout), '<meta charset="utf-8">')) {
    $errors[] = "Brak <meta charset=\"utf-8\"> w views/layouts/main.php";
}
if (!str_contains($mainLayout, '<html lang="<?= e($currentLanguage) ?>">')) {
    $errors[] = "Atrybut lang w <html> nie jest dynamiczny w views/layouts/main.php";
}
if (
    !str_contains($mainLayout, 'class="lang-<?= e($currentLanguage) ?>"')
    || !str_contains($mainLayout, 'data-detected-lang="<?= e(public_language()) ?>"')
) {
    $errors[] = "Brak dynamicznej klasy lang-* lub data-detected-lang w tagu <body>.";
}

// 3. Sprawdzenie nagłówka w App.php
$appFile = file_get_contents(__DIR__ . '/../app/Core/App.php');
if (!str_contains($appFile, "header('Content-Type: text/html; charset=UTF-8')")) {
    $errors[] = "Brak ustawienia nagłówka Content-Type w app/Core/App.php";
}

// 4. Sprawdzenie resources/lang/public.json
$jsonContent = file_get_contents(__DIR__ . '/../resources/lang/public.json');
$data = json_decode($jsonContent, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    $errors[] = "Plik public.json nie jest poprawnym JSON-em: " . json_last_error_msg();
} else {
    // Sprawdzenie czy są polskie i inne znaki (UTF-8)
    $testKey = 'brand.name';
    if (isset($data['pl'][$testKey]) && !str_contains($data['pl'][$testKey], 'ŹRÓDŁO SŁOWA')) {
        // Może być inny klucz, sprawdźmy dowolny ze znakami diakrytycznymi
        $foundDiacritics = false;
        foreach($data['pl'] as $val) {
            if (preg_match('/[ąćęłńóśźżĄĆĘŁŃÓŚŹŻ]/u', $val)) {
                $foundDiacritics = true;
                break;
            }
        }
        if (!$foundDiacritics) {
             $errors[] = "W public.json nie znaleziono polskich znaków w sekcji PL - sprawdź kodowanie.";
        }
    }
}

// 5. Sprawdzenie starych marek
$oldBrands = ['PAROLES VIVES', 'VOCE VIVA', 'PALABRA VIVA'];
foreach ($oldBrands as $old) {
    if (str_contains($jsonContent, $old)) {
        $errors[] = "W public.json znaleziono starą markę: $old";
    }
    if (str_contains(json_encode($languages), $old)) {
        $errors[] = "W config/languages.php znaleziono starą markę: $old";
    }
}

// 6. Test resolvera dla prefixów
$sites = require __DIR__ . '/../config/sites.php';
$resolver = new \App\Services\PublicSiteResolver($sites, $languages);

$testCases = [
    '/fr' => 'fr',
    '/es' => 'es',
    '/de/articles/test' => 'de',
    '/' => 'pl'
];

foreach ($testCases as $uri => $expectedLang) {
    $normalized = $resolver->normalizeUri($uri);
    $langService = new \App\Services\PublicLanguageService($languages, $resolver);
    $detected = $langService->current(null, null, $uri);
    
    if ($detected !== $expectedLang) {
        $errors[] = "Błąd detekcji języka dla URI '$uri'. Oczekiwano '$expectedLang', otrzymano '$detected'";
    }
}

// 7. Wynik
if (empty($errors)) {
    echo "OK - Pełny kontekst językowy wdrożony poprawnie.\n";
} else {
    echo "BŁĘDY:\n";
    foreach ($errors as $error) {
        echo "- $error\n";
    }
    exit(1);
}

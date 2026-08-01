<?php
/**
 * scripts/zrodlo_missing_translation_keys_scan.php
 * Audyt brakujących i technicznych kluczy tłumaczeń w widokach.
 */

$root = dirname(__DIR__);
$publicJsonPath = $root . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'lang' . DIRECTORY_SEPARATOR . 'public.json';

if (!is_file($publicJsonPath)) {
    echo "BŁĄD: Brak pliku resources/lang/public.json\n";
    exit(1);
}

$rawJson = file_get_contents($publicJsonPath);
$publicJson = json_decode($rawJson, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo "BŁĄD: resources/lang/public.json nie jest poprawnym JSON-em: " . json_last_error_msg() . "\n";
    exit(1);
}

$requiredLangs = ['pl', 'en', 'de', 'fr', 'it', 'es'];
$missingKeys = [];
$technicalKeysInViews = [];
$incompleteTranslations = [];
$potentialRawKeys = []; // Klucze które wyglądają jak techniczne ale są w HTML (nie przez t())

// Lista plików widoków
$viewDir = $root . DIRECTORY_SEPARATOR . 'views';
$directoryIterator = new RecursiveDirectoryIterator($viewDir);
$iterator = new RecursiveIteratorIterator($directoryIterator);

foreach ($iterator as $file) {
    if ($file->isDir() || $file->getExtension() !== 'php') {
        continue;
    }

    $filePath = $file->getPathname();
    $relativePath = str_replace($root, '', $filePath);
    $content = file_get_contents($filePath);

    // 1. Szukanie t('key') lub t("key")
    if (preg_match_all('/(?<![a-zA-Z0-9_])t\([\'"](.+?)[\'"]/', $content, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $key = $match[1];

            // Ignorujemy klucze które wyglądają na niedokończone (np. kończą się kropką)
            // co często sugeruje dynamiczną budowę klucza w PHP
            if (str_ends_with($key, '.')) {
                continue;
            }
            if (strpos($key, '.') === 0) {
                $technicalKeysInViews[] = [
                    'key' => $key,
                    'file' => $relativePath
                ];
            }

            // Czy klucz istnieje w JSON?
            if (!isset($publicJson[$key])) {
                $missingKeys[$key][] = $relativePath;
            } else {
                // Czy ma wszystkie języki?
                foreach ($requiredLangs as $lang) {
                    if (!isset($publicJson[$key][$lang]) || (is_string($publicJson[$key][$lang]) && trim($publicJson[$key][$lang]) === '')) {
                        $incompleteTranslations[$key][] = $lang;
                    }
                }
            }
        }
    }

    // 3. Szukanie zabronionych identyfikatorów stron w kodzie (nie mogą być kluczami t())
    $siteIdentifiers = ['pl_zrodlo_slowa', 'en_source_of_word', 'de_wortquelle', 'fr_source_des_mots', 'it_fonte_di_parole', 'es_fuente_de_palabras'];
    foreach ($siteIdentifiers as $sid) {
        if (str_contains($content, $sid)) {
            if (preg_match('/t\([\'"]' . preg_quote($sid, '/') . '[\'"]/', $content)) {
                $technicalKeysInViews[] = [
                    'key' => $sid,
                    'file' => $relativePath . ' (ZABRONIONY IDENTYFIKATOR STRONY)'
                ];
            }
        }
    }

    // 2. Szukanie surowych kluczy w HTML (np. .wallet.actions)
    // Szukamy ciągów znaków które wyglądają jak klucze techniczne ale nie są w t()
    // To jest trudniejsze, spróbujmy szukać wzorców: wallet., author.article., layout., article.
    $patterns = [
        '/\.wallet\.[a-z0-9._]+/',
        '/\.author\.[a-z0-9._]+/',
        '/wallet\.[a-z0-9._]+/',
        '/author\.article\.[a-z0-9._]+/'
    ];

    foreach ($patterns as $pattern) {
        if (preg_match_all($pattern, $content, $rawMatches)) {
            foreach ($rawMatches[0] as $rawKey) {
                // Sprawdzamy czy ten ciąg nie jest częścią t('...') już złapanego wcześniej
                // Dla uproszczenia: jeśli nie ma go w public.json, to zgłaszamy jako podejrzany
                if (!isset($publicJson[$rawKey]) && !in_array($rawKey, array_keys($missingKeys))) {
                    // Wykluczamy klasy CSS (często zaczynają się od kropki)
                    // Jeśli klucz występuje w t(), to już go mamy w missingKeys.
                    // Jeśli występuje surowo, to może być błąd zapomnianego t().
                    
                    // Jeśli zawiera kropkę na początku i nie jest w t(), to bardzo podejrzane
                    if (strpos($rawKey, '.') === 0) {
                        $potentialRawKeys[] = [
                            'key' => $rawKey,
                            'file' => $relativePath
                        ];
                    }
                }
            }
        }
    }
}

// Raport
$hasErrors = false;

if (!empty($technicalKeysInViews)) {
    echo "\n!!! BŁĄD: ZNALEZIONO KLUCZE Z KROPKĄ NA POCZĄTKU W t() !!!\n";
    foreach ($technicalKeysInViews as $item) {
        echo "- {$item['key']} w {$item['file']}\n";
    }
    $hasErrors = true;
}

if (!empty($missingKeys)) {
    echo "\n!!! BŁĄD: BRAKUJĄCE KLUCZE W public.json !!!\n";
    foreach ($missingKeys as $key => $files) {
        echo "- {$key} (użyty w: " . implode(', ', array_unique($files)) . ")\n";
    }
    $hasErrors = true;
}

if (!empty($incompleteTranslations)) {
    echo "\n!!! OSTRZEŻENIE: NIEKOMPLETNE TŁUMACZENIA !!!\n";
    foreach ($incompleteTranslations as $key => $langs) {
        echo "- {$key} brakuje: " . implode(', ', array_unique($langs)) . "\n";
    }
    $hasErrors = true;
}

if (!empty($potentialRawKeys)) {
    echo "\n!!! OSTRZEŻENIE: POTENCJALNE SUROWE KLUCZE W HTML (poza t()) !!!\n";
    $uniquePotential = [];
    foreach ($potentialRawKeys as $item) {
        $uniquePotential[$item['key']][] = $item['file'];
    }
    foreach ($uniquePotential as $key => $files) {
         echo "- {$key} w: " . implode(', ', array_unique($files)) . "\n";
    }
    // Nie traktujemy tego jako twardy błąd blokujący, bo może to być klasa CSS, 
    // ale warto przejrzeć.
}

if (!$hasErrors) {
    echo "\nAUDYT ZAKOŃCZONY POMYŚLNIE: Wszystkie klucze t() są poprawne i kompletne.\n";
    exit(0);
} else {
    echo "\nAUDYT ZAKOŃCZONY Z PROBLEMAMI.\n";
    exit(1);
}

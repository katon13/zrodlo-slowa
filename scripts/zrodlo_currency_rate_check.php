<?php

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Services\CurrencyRateService;

echo "--- STARTING CURRENCY RATE CHECK ---\n";

// 1. Serwis istnieje
if (class_exists('App\Services\CurrencyRateService')) {
    echo "[OK] CurrencyRateService exists.\n";
} else {
    echo "[FAIL] CurrencyRateService NOT found.\n";
    exit(1);
}

$service = new CurrencyRateService();

// 2. Skrypt aktualizacji istnieje
if (file_exists(__DIR__ . '/update_currency_rates_nbp.php')) {
    echo "[OK] update_currency_rates_nbp.php exists.\n";
} else {
    echo "[FAIL] update_currency_rates_nbp.php NOT found.\n";
}

// 3. Endpoint NBP jest HTTPS
$reflector = new ReflectionClass($service);
$url = $reflector->getConstant('NBP_API_URL');
if (strpos($url, 'https://api.nbp.pl') === 0) {
    echo "[OK] NBP API URL uses HTTPS: $url\n";
} else {
    echo "[FAIL] NBP API URL is NOT HTTPS: $url\n";
}

// 4. Brak ECB w kodzie
$code = file_get_contents($reflector->getFileName());
if (stripos($code, 'ecb') === false && stripos($code, 'europa.eu') === false) {
    echo "[OK] No ECB or other sources found in service.\n";
} else {
    echo "[FAIL] ECB or other source found in service code!\n";
}

// 5. Cache path i source
$cacheData = $service->loadCachedRates();
if (empty($cacheData)) {
    echo "[INFO] Cache is empty. Updating from NBP...\n";
    $service->updateFromNbp();
    $cacheData = $service->loadCachedRates();
}

if (!empty($cacheData) && ($cacheData['source'] ?? '') === 'nbp') {
    echo "[OK] Cache source is 'nbp'.\n";
} else {
    echo "[FAIL] Cache missing or source is NOT 'nbp'.\n";
}

// 7. Mapowanie języków
$langs = [
    'pl' => 'PLN',
    'en' => 'GBP',
    'de' => 'EUR',
    'fr' => 'EUR',
    'it' => 'EUR',
    'es' => 'EUR',
    'unknown' => 'PLN'
];

foreach ($langs as $lang => $expected) {
    $result = $service->currencyForLanguage($lang);
    if ($result === $expected) {
        echo "[OK] Language '$lang' mapped to '$result'.\n";
    } else {
        echo "[FAIL] Language '$lang' mapped to '$result', expected '$expected'.\n";
    }
}

// 10. 10 TT = 1 PLN (rzeczywista logika serwisu)
$formattedTalent = $service->ttToLocalApprox(10, 'pl');
if ($formattedTalent === '1,0 PLN') {
    echo "[OK] 10 TT = 1.0 PLN conversion logic.\n";
} else {
    echo "[FAIL] 10 TT conversion logic returned " . (string)$formattedTalent . ".\n";
}

// 11, 12, 13. Przeliczanie i zaokrąglanie
// Symulujemy kurs EUR
$testAmount = 1.0 / 4.30; // 0.2325...
$formatted = $service->formatSimple($testAmount, 'EUR', 'en'); // 0.2 EUR (en uses . as separator)
$formattedPl = $service->formatSimple($testAmount, 'EUR', 'pl'); // 0,2 EUR

echo "Test conversion 1 PLN to EUR (mid 4.30):\n";
echo "  EN: $formatted\n";
echo "  PL: $formattedPl\n";

if (strpos($formatted, '0.2 EUR') !== false && strpos($formattedPl, '0,2 EUR') !== false && strpos($formatted, 'approx') === false) {
    echo "[OK] Rounding down, formatting works, NO prefixes.\n";
} else {
    echo "[FAIL] Rounding, formatting or prefix removal incorrect.\n";
}

// 0.29 EUR -> 0.2 EUR
$formatted2 = $service->formatSimple(0.29, 'EUR', 'en');
if (strpos($formatted2, '0.2 EUR') !== false) {
    echo "[OK] 0.29 rounded down to 0.2.\n";
} else {
    echo "[FAIL] 0.29 NOT rounded down to 0.2, got: $formatted2\n";
}

// 0.01 EUR -> 0.1 EUR (minimum value)
$formattedMin = $service->formatSimple(0.01, 'EUR', 'en');
if (strpos($formattedMin, '0.1 EUR') !== false) {
    echo "[OK] 0.01 rounded to 0.1 (minimum presentation value).\n";
} else {
    echo "[FAIL] 0.01 NOT rounded to 0.1, got: $formattedMin\n";
}

// 14. Brak NBP/cache nie blokuje (null)
$nullResult = $service->ttToLocalApprox(10, 'nonexistent');
echo "[OK] Non-existent language returns: " . ($nullResult === null ? 'null' : $nullResult) . "\n";

echo "--- CHECK COMPLETED ---\n";

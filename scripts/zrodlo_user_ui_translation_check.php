<?php
/**
 * Test weryfikujący tłumaczenia UI użytkownika i strukturę kategorii.
 */

$envPath = __DIR__ . '/../.env';
if (file_exists($envPath)) {
    $lines = file($envPath);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0 || !strpos($line, '=')) continue;
        list($name, $value) = explode('=', $line, 2);
        $_ENV[trim($name)] = trim($value, " \"\t\n\r\0\x0B");
    }
}

require_once __DIR__ . '/../app/Core/Database.php';

function t_mock($key, $lang = 'pl') {
    static $translations = null;
    if ($translations === null) {
        $translations = json_decode(file_get_contents(__DIR__ . '/../resources/lang/public.json'), true);
    }
    return $translations[$key][$lang] ?? $key;
}

$db = new \App\Core\Database([
    'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
    'port' => $_ENV['DB_PORT'] ?? '3306',
    'database' => $_ENV['DB_NAME'] ?? 'zrodlo_slowa',
    'username' => $_ENV['DB_USER'] ?? 'root',
    'password' => $_ENV['DB_PASS'] ?? '',
    'charset' => 'utf8mb4'
]);

echo "--- TEST TŁUMACZEŃ UI UŻYTKOWNIKA ---\n";

// 1. Sprawdzenie JSON
$json_raw = file_get_contents(__DIR__ . '/../resources/lang/public.json');
$json = json_decode($json_raw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo "FAIL: JSON is invalid: " . json_last_error_msg() . "\n";
    exit(1);
}
echo "OK: JSON is valid UTF-8.\n";

// 2. Sprawdzenie kluczy wallet
$wallet_keys = [
    'wallet.title', 'wallet.kicker', 'wallet.available_funds', 'wallet.total_balance',
    'wallet.from_tt_conversion', 'wallet.from_topups', 'wallet.how_it_works.rate_title',
    'wallet.withdrawals.methods_title', 'bonus.type.login', 'bonus.message.day_visit'
];
foreach ($wallet_keys as $key) {
    if (!isset($json[$key])) {
        echo "FAIL: Missing key $key\n";
        exit(1);
    }
    foreach (['pl', 'en', 'de', 'fr', 'it', 'es'] as $l) {
        if (empty($json[$key][$l])) {
            echo "FAIL: Missing translation for $key in $l\n";
            exit(1);
        }
    }
}
echo "OK: Wallet and bonus keys present and translated.\n";

// 3. Sprawdzenie kluczy author
$author_keys = [
    'author.article.new', 'author.article.edit', 'author.article.title', 'author.article.body',
    'author.article.source_language.label', 'author.article.source_language.pl'
];
foreach ($author_keys as $key) {
    if (!isset($json[$key])) {
        echo "FAIL: Missing key $key\n";
        exit(1);
    }
    foreach (['pl', 'en', 'de', 'fr', 'it', 'es'] as $l) {
        if (empty($json[$key][$l])) {
            echo "FAIL: Missing translation for $key in $l\n";
            exit(1);
        }
    }
}
echo "OK: Author keys present and translated.\n";

// 4. Sprawdzenie struktury tabeli kategorii
try {
    $db->query("SELECT * FROM category_translations LIMIT 1");
    echo "OK: Table category_translations exists.\n";
} catch (Exception $e) {
    echo "FAIL: Table category_translations missing or error: " . $e->getMessage() . "\n";
    exit(1);
}

// 5. Test dodania tłumaczenia kategorii
try {
    $cat = $db->one("SELECT id FROM categories LIMIT 1");
    if ($cat) {
        $cid = $cat['id'];
        $db->query("DELETE FROM category_translations WHERE category_id = :id AND language = 'en'", ['id' => $cid]);
        $db->query("INSERT INTO category_translations (category_id, language, name, slug, created_at, updated_at) VALUES (:id, 'en', 'Test Category', 'test-category', NOW(), NOW())", ['id' => $cid]);
        
        $check = $db->one("SELECT name FROM category_translations WHERE category_id = :id AND language = 'en'", ['id' => $cid]);
        if ($check && $check['name'] === 'Test Category') {
            echo "OK: Category translation saving works.\n";
        } else {
            echo "FAIL: Category translation saving failed.\n";
            exit(1);
        }
    } else {
        echo "SKIP: No categories found to test translations.\n";
    }
} catch (Exception $e) {
    echo "FAIL: Error testing category translations: " . $e->getMessage() . "\n";
    exit(1);
}

// 6. Sprawdzenie braku sztywnych tekstów (wyrywkowe)
$views_to_check = [
    'views/wallet/show.php',
    'views/author/dashboard.php',
    'views/author/create_article.php'
];

foreach ($views_to_check as $view) {
    $content = file_get_contents(__DIR__ . '/../' . $view);
    if (strpos($content, '<h1>Portfel</h1>') !== false || strpos($content, '<h1>Twoje teksty i konto</h1>') !== false) {
        echo "FAIL: Found hardcoded Polish text in $view\n";
        exit(1);
    }
}
echo "OK: No major hardcoded Polish texts found in checked views.\n";

echo "--- ALL TESTS PASSED ---\n";

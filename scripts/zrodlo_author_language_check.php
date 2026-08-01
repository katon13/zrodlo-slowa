<?php
/**
 * Test weryfikujący pole source_language w modelu i formularzu autora.
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

$db = new \App\Core\Database([
    'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
    'port' => $_ENV['DB_PORT'] ?? '3306',
    'database' => $_ENV['DB_NAME'] ?? 'zrodlo_slowa',
    'username' => $_ENV['DB_USER'] ?? 'root',
    'password' => $_ENV['DB_PASS'] ?? '',
    'charset' => 'utf8mb4'
]);

echo "--- TEST JĘZYKA ORYGINAŁU TEKSTU (SOURCE_LANGUAGE) ---\n";

// 1. Sprawdzenie kolumny w bazie
try {
    $cols = $db->all("SHOW COLUMNS FROM articles LIKE 'source_language'");
    if (count($cols) > 0) {
        echo "OK: Column source_language exists in articles table.\n";
    } else {
        echo "FAIL: Column source_language missing in articles table.\n";
        exit(1);
    }
} catch (Exception $e) {
    echo "FAIL: Error checking database: " . $e->getMessage() . "\n";
    exit(1);
}

// 2. Sprawdzenie obecności pola w widokach
$views = ['views/author/create_article.php', 'views/author/edit_article.php'];
foreach ($views as $view) {
    $content = file_get_contents(__DIR__ . '/../' . $view);
    if (strpos($content, 'name="source_language"') !== false) {
        echo "OK: source_language field found in $view\n";
    } else {
        echo "FAIL: source_language field missing in $view\n";
        exit(1);
    }
}

// 3. Sprawdzenie kluczy JSON
$json = json_decode(file_get_contents(__DIR__ . '/../resources/lang/public.json'), true);
$required_keys = [
    'author.article.source_language.label',
    'author.article.source_language.help',
    'author.article.source_language.pl',
    'author.article.source_language.en',
    'author.article.source_language.de',
    'author.article.source_language.fr',
    'author.article.source_language.it',
    'author.article.source_language.es'
];

foreach ($required_keys as $key) {
    if (!isset($json[$key])) {
        echo "FAIL: Missing JSON key $key\n";
        exit(1);
    }
    foreach (['pl', 'en', 'de', 'fr', 'it', 'es'] as $l) {
        if (empty($json[$key][$l])) {
            echo "FAIL: Missing translation for $key in $l\n";
            exit(1);
        }
    }
}
echo "OK: All JSON keys for source_language are present and translated.\n";

// 4. Test zapisu i odczytu (wymaga mockowania ArticleService lub bezpośredniego testu na DB)
try {
    // Znajdź testowego autora
    $author = $db->one("SELECT id FROM users LIMIT 1");
    if ($author) {
        $aid = $author['id'];
        $title = "Test source_language " . time();
        $db->query("INSERT INTO articles (author_id, title, slug, body, status, source_language, created_at, updated_at) 
                   VALUES (:author, :title, 'test-slug', 'test body', 'draft', 'es', NOW(), NOW())", 
                   ['author' => $aid, 'title' => $title]);
        
        $article = $db->one("SELECT source_language FROM articles WHERE title = :title", ['title' => $title]);
        if ($article && $article['source_language'] === 'es') {
            echo "OK: Database saving and reading source_language works.\n";
        } else {
            echo "FAIL: Database saving source_language failed.\n";
            exit(1);
        }
        // Cleanup
        $db->query("DELETE FROM articles WHERE title = :title", ['title' => $title]);
    }
} catch (Exception $e) {
    echo "FAIL: Error testing DB saving: " . $e->getMessage() . "\n";
    exit(1);
}

echo "--- ALL TESTS PASSED ---\n";

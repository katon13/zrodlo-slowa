<?php
declare(strict_types=1);

/**
 * ŹRÓDŁO SŁOWA — kontrola panelu korektora po patchu V3.
 * Uruchomienie z katalogu głównego repo:
 * php scripts/check_proofreader_panel.php
 */

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;

$config = require __DIR__ . '/../config/database.php';
$db = new Database($config['default']);

function proofreader_line(string $message = ''): void
{
    echo $message . PHP_EOL;
}

function proofreader_ok(string $message): void
{
    proofreader_line('OK - ' . $message);
}

function proofreader_fail(string $message): void
{
    proofreader_line('FAIL - ' . $message);
}

$failures = 0;

proofreader_line('ŹRÓDŁO SŁOWA — kontrola panelu KOREKTORA V3');
proofreader_line(str_repeat('=', 58));

try {
    $articles = (int)$db->cell('SELECT COUNT(*) FROM articles');
    proofreader_ok('Tabela articles działa, liczba tekstów: ' . $articles);
} catch (Throwable $e) {
    $failures++;
    proofreader_fail('Brak dostępu do tabeli articles: ' . $e->getMessage());
}

try {
    $events = (int)$db->cell('SELECT COUNT(*) FROM article_events');
    proofreader_ok('Tabela article_events działa, liczba zdarzeń: ' . $events);
} catch (Throwable $e) {
    $failures++;
    proofreader_fail('Brak tabeli article_events: ' . $e->getMessage());
}

try {
    $proofreads = (int)$db->cell('SELECT COUNT(*) FROM article_events WHERE event=\'proofread_saved\'');
    proofreader_ok('Zdarzenia proofread_saved w bazie: ' . $proofreads);
} catch (Throwable $e) {
    $failures++;
    proofreader_fail('Nie można odczytać proofread_saved: ' . $e->getMessage());
}

try {
    $sample = $db->all('SELECT a.id, a.title, a.status,
        (SELECT MAX(created_at) FROM article_events WHERE article_id=a.id AND event=\'proofread_saved\') AS proofread_at
        FROM articles a
        ORDER BY a.updated_at DESC, a.id DESC
        LIMIT 10');
    proofreader_ok('Zapytanie proofread_at dla listy działa, próbka: ' . count($sample));
} catch (Throwable $e) {
    $failures++;
    proofreader_fail('Zapytanie proofread_at nie działa: ' . $e->getMessage());
}

proofreader_line('');
if ($failures === 0) {
    proofreader_ok('Kontrola zakończona bez błędów. Panel korektora ma działającą bazę zdarzeń KOREKTA.');
    exit(0);
}

proofreader_fail('Kontrola wykryła błędy: ' . $failures);
exit(1);

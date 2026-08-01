<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\App;

$app = App::boot(dirname(__DIR__));
$db = $app->db;

try {
    $db->query("ALTER TABLE users MODIFY status ENUM('pending_author','active','blocked','deleted') NOT NULL DEFAULT 'active'");

    echo json_encode([
        'ok' => true,
        'changed' => ['users.status enum includes pending_author'],
        'next' => 'Nowe rejestracje autora będą oczekiwać na zatwierdzenie redakcji.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}

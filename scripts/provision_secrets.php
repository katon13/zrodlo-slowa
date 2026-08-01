<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Services\EnvironmentValidator;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Dostęp tylko przez CLI.";
    exit(1);
}

$options = getopt('', ['apply']);
$apply = isset($options['apply']);
$path = dirname(__DIR__) . '/.env';
if (!is_file($path) || !is_readable($path)) {
    fwrite(STDERR, "BŁĄD: brak czytelnego pliku .env.\n");
    exit(1);
}

$validator = new EnvironmentValidator();
$lines = file($path, FILE_IGNORE_NEW_LINES);
if (!is_array($lines)) {
    fwrite(STDERR, "BŁĄD: nie udało się odczytać pliku .env.\n");
    exit(1);
}

$required = ['APP_KEY', 'PASSWORD_PEPPER', 'FINANCE_HMAC_KEY'];
$found = [];
$changed = [];
foreach ($lines as $index => $line) {
    if (preg_match('/^\s*([A-Z0-9_]+)\s*=(.*)$/', $line, $match) !== 1) {
        continue;
    }
    $key = $match[1];
    if (!in_array($key, $required, true)) {
        continue;
    }
    $found[$key] = true;
    $current = trim($match[2], " \t\"'");
    if (!$validator->isStrongSecret($current)) {
        $lines[$index] = $key . '=' . base64_encode(random_bytes(48));
        $changed[] = $key;
    }
}
foreach ($required as $key) {
    if (!isset($found[$key])) {
        $lines[] = $key . '=' . base64_encode(random_bytes(48));
        $changed[] = $key;
    }
}

if (!$apply) {
    echo json_encode([
        'ok' => true,
        'dry_run' => true,
        'would_provision' => $changed,
        'preserved' => array_values(array_diff($required, $changed)),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

if ($changed !== []) {
    $payload = implode(PHP_EOL, $lines) . PHP_EOL;
    $bytes = file_put_contents($path, $payload, LOCK_EX);
    if ($bytes === false || $bytes !== strlen($payload)) {
        fwrite(STDERR, "BŁĄD: nie udało się bezpiecznie zapisać pliku .env.\n");
        exit(1);
    }
}

echo json_encode([
    'ok' => true,
    'provisioned' => $changed,
    'preserved' => array_values(array_diff($required, $changed)),
    'secret_values_printed' => false,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

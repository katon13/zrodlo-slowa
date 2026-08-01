<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;
use App\Services\AdminRecoveryService;
use App\Services\RecoveryCodeService;
use App\Services\SecurityEventService;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Dostęp wyłącznie przez lokalne CLI.\n");
    exit(1);
}

$options = getopt('', ['admin-id:', 'token-file:', 'confirm:', 'reason:']);
$adminId = (int)($options['admin-id'] ?? 0);
$tokenFile = trim((string)($options['token-file'] ?? ''));
$confirmation = (string)($options['confirm'] ?? '');
$reason = (string)($options['reason'] ?? '');

try {
    if ($adminId <= 0 || $tokenFile === '' || $confirmation === '' || $reason === '') {
        throw new RuntimeException(
            'Użycie: php scripts/security_recover_admin.php --admin-id=ID --token-file=PLIK --confirm="PEŁNY TEKST" --reason="POWÓD"'
        );
    }
    $resolvedTokenFile = realpath($tokenFile);
    if ($resolvedTokenFile === false || !is_file($resolvedTokenFile) || filesize($resolvedTokenFile) > 512) {
        throw new RuntimeException('Plik z kodem odzyskiwania nie istnieje albo ma nieprawidłowy rozmiar.');
    }
    $token = trim((string)file_get_contents($resolvedTokenFile));
    if ($token === '') {
        throw new RuntimeException('Plik z kodem odzyskiwania jest pusty.');
    }
    $config = require __DIR__ . '/../config/database.php';
    $db = new Database($config['default']);
    $events = new SecurityEventService($db);
    $service = new AdminRecoveryService(
        $db,
        new RecoveryCodeService($db, $events),
        $events,
        dirname(__DIR__) . '/storage/security-recovery',
    );
    $result = $service->recover($adminId, $token, $confirmation, $reason);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'BŁĄD ODZYSKIWANIA 3DORS: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

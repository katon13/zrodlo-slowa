<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;
use App\Core\SlowoSnajperConfig;
use App\Services\FraudGuardService;

$options = getopt('', [
    'limit::',
    'json',
]);

$config = require __DIR__ . '/../config/database.php';
$db = new Database($config['default']);
$snajper = SlowoSnajperConfig::fromRoot(dirname(__DIR__));
$limit = isset($options['limit']) ? (int)$options['limit'] : $snajper->limit('fraud_scan_users', 200, 1000);

try {
    $result = (new FraudGuardService($db, $snajper))->scan($limit);
    if (isset($options['json'])) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        echo "SNAJPER SŁOWA / ANTYFRAUD\n";
        echo "Sprawdzono użytkowników: " . (int)$result['scanned'] . "\n";
        echo "Oznaczono zdarzeń: " . (int)$result['flagged'] . "\n";
        foreach ($result['events'] as $event) {
            echo "- user #" . (int)$event['user_id'] . " " . $event['email'] . " risk=" . (int)$event['risk_score'] . " status=" . $event['status'] . "\n";
        }
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "BŁĄD SKANU ANTYFRAUD: " . $e->getMessage() . PHP_EOL);
    exit(1);
}

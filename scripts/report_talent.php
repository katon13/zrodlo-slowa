<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;

$config = require __DIR__ . '/../config/database.php';
$db = new Database($config['default']);

echo "============================================================\n";
echo "ŹRÓDŁO SŁOWA — RAPORT TALENTU (PUNKTY)\n";
echo "Data raportu: " . date('Y-m-d H:i:s') . "\n";
echo "============================================================\n\n";

// 1. Ogólne statystyki punktów
$stats = $db->one('SELECT COUNT(*) as users_count, SUM(points_balance) as total_points, AVG(points_balance) as avg_points FROM wallets');
echo "STATYSTYKI OGÓLNE:\n";
echo sprintf("- Łączna liczba Talentu w systemie: %d pkt\n", $stats['total_points'] ?? 0);
echo sprintf("- Liczba użytkowników z portfelem: %d\n", $stats['users_count']);
echo sprintf("- Średnia liczba na użytkownika:   %.2f pkt\n\n", $stats['avg_points'] ?? 0);

// 2. Naliczenia wg typu aktywności
$rewards = $db->all('SELECT activity_type, COUNT(*) as cnt, SUM(points_amount) as total FROM activity_reward_logs GROUP BY activity_type ORDER BY total DESC');
echo "NALICZENIA WG AKTYWNOŚCI:\n";
if (empty($rewards)) {
    echo "- Brak naliczeń w logach.\n\n";
} else {
    foreach ($rewards as $r) {
        echo sprintf("- [%-20s]: %5d operacji | Razem: %8d pkt\n", $r['activity_type'], $r['cnt'], $r['total']);
    }
    echo "\n";
}

// 3. Importy z myCred
$mycred = $db->one('SELECT COUNT(*) as cnt, SUM(points) as total FROM wallet_transactions WHERE source_module=\'legacy_mycred\'');
echo "IMPORTY MYCRED:\n";
echo sprintf("- Liczba zaimportowanych wpisów: %d\n", $mycred['cnt']);
echo sprintf("- Łączna suma z importu:        %d pkt\n\n", $mycred['total'] ?? 0);

// 4. Top użytkownicy wg Talentu
$top = $db->all('SELECT u.display_name, w.points_balance FROM wallets w JOIN users u ON u.id=w.user_id ORDER BY w.points_balance DESC LIMIT 10');
echo "TOP 10 UŻYTKOWNIKÓW WG TALENTU:\n";
foreach ($top as $i => $t) {
    echo sprintf("%2d. %-30s | %8d pkt\n", $i + 1, mb_substr($t['display_name'], 0, 30), $t['points_balance']);
}
echo "\n";

// 5. Ostatnie 10 nagród
$recent = $db->all('SELECT l.*, u.display_name FROM activity_reward_logs l JOIN users u ON u.id=l.user_id ORDER BY l.awarded_at DESC LIMIT 10');
echo "OSTATNIE 10 NAGRÓD:\n";
foreach ($recent as $r) {
    echo sprintf("- %s | %-20s | %5d pkt | %s\n", $r['awarded_at'], $r['activity_type'], $r['points_amount'], $r['display_name']);
}
echo "\n";

// Prepare JSON report
$reportData = [
    'timestamp' => date('c'),
    'stats' => $stats,
    'rewards' => $rewards,
    'mycred' => $mycred,
    'top' => $top,
    'recent' => $recent
];

$logPath = __DIR__ . '/../storage/logs/talent_report_' . date('Ymd_His') . '.json';
file_put_contents($logPath, json_encode($reportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "Raport JSON zapisany w: storage/logs/" . basename($logPath) . "\n";
echo "============================================================\n";

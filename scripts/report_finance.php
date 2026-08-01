<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;

$config = require __DIR__ . '/../config/database.php';
$db = new Database($config['default']);

echo "============================================================\n";
echo "ŹRÓDŁO SŁOWA — RAPORT FINANSOWY\n";
echo "Data raportu: " . date('Y-m-d H:i:s') . "\n";
echo "============================================================\n\n";

// 1. Wallets Summary
$wallets = $db->one('SELECT COUNT(*) as cnt, 
    SUM(main_available_minor) as sum_main_avail, 
    SUM(main_reserved_minor) as sum_main_res,
    SUM(slowo_available_minor) as sum_slowo_avail,
    SUM(slowo_reserved_minor) as sum_slowo_res,
    SUM(points_balance) as sum_points
FROM wallets');

echo "PORTFELE:\n";
echo sprintf("- Liczba portfeli: %d\n", $wallets['cnt']);
echo "  Konto Główne:\n";
echo sprintf("    - Dostępne:            %.2f PLN\n", ($wallets['sum_main_avail'] ?? 0) / 100);
echo sprintf("    - Zarezerwowane:       %.2f PLN\n", ($wallets['sum_main_res'] ?? 0) / 100);
echo "  Konto Słowo Pisane:\n";
echo sprintf("    - Dostępne:            %.2f PLN\n", ($wallets['sum_slowo_avail'] ?? 0) / 100);
echo sprintf("    - Zarezerwowane:       %.2f PLN\n", ($wallets['sum_slowo_res'] ?? 0) / 100);
echo "  Talent (punkty):\n";
echo sprintf("    - Łącznie:             %d pkt\n\n", $wallets['sum_points'] ?? 0);

// 2. Ledger Summary
$ledger = $db->one('SELECT COUNT(*) as cnt FROM wallet_transactions');
echo "KSIĘGA LEDGER:\n";
echo sprintf("- Liczba transakcji: %d\n\n", $ledger['cnt']);

// 3. Payouts by status
$payouts = $db->all('SELECT status, COUNT(*) as cnt, SUM(amount_minor) as sum_amount FROM payouts GROUP BY status');
echo "WYPŁATY:\n";
if (empty($payouts)) {
    echo "- Brak wypłat.\n\n";
} else {
    foreach ($payouts as $p) {
        echo sprintf("- Status [%-10s]: %3d szt. | Razem: %.2f PLN\n", $p['status'], $p['cnt'], $p['sum_amount'] / 100);
    }
    echo "\n";
}

// 4. Payments by status
$payments = $db->all('SELECT status, COUNT(*) as cnt, SUM(amount_minor) as sum_amount FROM payments GROUP BY status');
echo "PŁATNOŚCI:\n";
if (empty($payments)) {
    echo "- Brak płatności.\n\n";
} else {
    foreach ($payments as $p) {
        echo sprintf("- Status [%-10s]: %3d szt. | Razem: %.2f PLN\n", $p['status'], $p['cnt'], $p['sum_amount'] / 100);
    }
    echo "\n";
}

// 5. Donation Campaigns
$campaigns = $db->all('SELECT * FROM donation_campaigns WHERE active=1');
echo "KAMPANIE DAROWIZN:\n";
if (empty($campaigns)) {
    echo "- Brak aktywnych kampanii.\n\n";
} else {
    foreach ($campaigns as $c) {
        $stats = $db->one('
            SELECT SUM(p.amount_minor) as total 
            FROM donations d 
            JOIN payments p ON p.id = d.payment_id 
            WHERE d.campaign_id = :id AND p.status = \'paid\'
        ', ['id' => $c['id']]);
        $current = (int)($stats['total'] ?? 0);
        $progress = $c['target_amount_minor'] > 0 ? min(100, round(($current / $c['target_amount_minor']) * 100)) : 0;
        echo sprintf("- [%-20s]: %3d%% | Zebrano: %10.2f / %10.2f PLN\n", mb_substr($c['name'], 0, 20), $progress, $current / 100, $c['target_amount_minor'] / 100);
    }
    echo "\n";
}

// 6. Premium Articles
$premium = $db->one('SELECT 
    COUNT(*) as total_sales,
    SUM(total_amount_minor) as total_revenue,
    SUM(author_income_minor) as total_author_income,
    SUM(publisher_fee_minor) as total_publisher_fee,
    AVG(publisher_fee_percent) as avg_fee_percent
FROM platform_revenues');

$accessStats = $db->one('SELECT 
    COUNT(CASE WHEN status=\'active\' AND expires_at IS NOT NULL AND expires_at > NOW() THEN 1 END) as active_grants,
    COUNT(CASE WHEN expires_at <= NOW() THEN 1 END) as expired_grants
FROM article_access_grants');

echo "ARTYKUŁY PREMIUM:\n";
echo sprintf("- Liczba zakupów:          %d\n", $premium['total_sales'] ?? 0);
echo sprintf("- Suma sprzedaży:          %.2f PLN\n", ($premium['total_revenue'] ?? 0) / 100);
echo sprintf("- Przychód autorów:        %.2f PLN\n", ($premium['total_author_income'] ?? 0) / 100);
echo sprintf("- Prowizja serwisu:        %.2f PLN\n", ($premium['total_publisher_fee'] ?? 0) / 100);
echo sprintf("- Średni %% prowizji:       %.2f%%\n", $premium['avg_fee_percent'] ?? 0);
echo sprintf("- Aktywne dostępy:         %d\n", $accessStats['active_grants'] ?? 0);
echo sprintf("- Wygasłe dostępy:         %d\n\n", $accessStats['expired_grants'] ?? 0);

// 7. Summary
$reportData = [
    'timestamp' => date('c'),
    'wallets' => $wallets,
    'ledger' => $ledger,
    'payouts' => $payouts,
    'payments' => $payments,
    'premium' => $premium,
    'access' => $accessStats,
];

$logPath = __DIR__ . '/../storage/logs/finance_report_' . date('Ymd_His') . '.json';
file_put_contents($logPath, json_encode($reportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "Raport JSON zapisany w: storage/logs/" . basename($logPath) . "\n";
echo "============================================================\n";

<?php
/**
 * Skrypt testujący zabezpieczenia modelu bankowego.
 */

require_once __DIR__ . '/../app/Core/bootstrap.php';

$dbConfig = require __DIR__ . '/../config/database.php';
$db = new \App\Core\Database($dbConfig['default']);
$finance = new \App\Services\FinancialService($db);

echo "--- TESTY ZABEZPIECZEŃ MODELU BANKOWEGO ---\n";

// Test 1: Próba ujemnego salda (PHP)
echo "Test 1: Próba ujemnego salda (PHP Exception)... ";
try {
    $finance->postTransaction(1, 'adjustment', -1000000000, 'main', 'Test ujemnego salda PHP');
    echo "PORAŻKA: System pozwolił na ujemne saldo w PHP.\n";
} catch (\Exception $e) {
    echo "SUKCES: Wykryto błąd: " . $e->getMessage() . "\n";
}

// Test 2: Próba ujemnego salda (SQL Trigger)
echo "Test 2: Próba ujemnego salda (SQL Trigger)... ";
try {
    $db->query('UPDATE wallets SET main_available_minor = -100 WHERE user_id = 1');
    echo "PORAŻKA: SQL Trigger nie zablokował ujemnego salda.\n";
} catch (\Exception $e) {
    echo "SUKCES: Wykryto błąd SQL: " . $e->getMessage() . "\n";
}

// Test 3: Maker-Checker (Samozatwierdzenie)
echo "Test 3: Maker-Checker (Blokada samozatwierdzenia)... ";
$_SESSION['user_id'] = 1;
$_SESSION['user_role'] = 'admin';
$approvalId = $finance->requestApproval('manual_reward', 100, 'TT', 1, 1, ['points' => 100], 'Test MC');

try {
    $finance->approve($approvalId, 'Próba samozatwierdzenia');
    echo "PORAŻKA: System pozwolił na samozatwierdzenie zlecenia.\n";
} catch (\Exception $e) {
    echo "SUKCES: Wykryto błąd: " . $e->getMessage() . "\n";
}

// Czyszczenie po teście 3
$db->query('DELETE FROM financial_approvals WHERE id = :id', ['id' => $approvalId]);

echo "--- KONIEC TESTÓW ---\n";

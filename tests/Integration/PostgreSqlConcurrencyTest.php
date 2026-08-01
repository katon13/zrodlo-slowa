<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Services\PaymentGatewayEventService;
use App\Services\FinancialService;
use PHPUnit\Framework\TestCase;

final class PostgreSqlConcurrencyTest extends TestCase
{
    public function testConcurrentDoubleClickPostsExactlyOneWalletTransaction(): void
    {
        $config = require dirname(__DIR__, 2) . '/config/database.php';
        if (($config['default']['driver'] ?? '') !== 'pgsql' || !function_exists('pcntl_fork')) {
            self::markTestSkipped('Test wymaga PostgreSQL i rozszerzenia pcntl.');
        }

        $setupDatabase = new Database($config['default']);
        $suffix = bin2hex(random_bytes(8));
        $key = 'phpunit-double-click-' . $suffix;
        $userId = $setupDatabase->insert(
            'INSERT INTO users(email,password_hash,display_name,status,created_at)
             VALUES(:email,:hash,\'Concurrency Wallet\',\'active\',NOW())',
            ['email' => "wallet-$suffix@example.test", 'hash' => password_hash('test-password', PASSWORD_DEFAULT)]
        );
        $setupDatabase->query(
            'INSERT INTO wallets(user_id,main_available_minor,main_reserved_minor,slowo_available_minor,slowo_reserved_minor,available_minor,pending_minor,reserved_minor,points_balance,currency,created_at)
             VALUES(:user,0,0,0,0,0,0,0,0,\'PLN\',NOW())',
            ['user' => $userId]
        );
        $directory = sys_get_temp_dir() . '/zs_wallet_' . $suffix;
        mkdir($directory, 0700);
        $barrier = $directory . '/start';
        $children = [];
        unset($setupDatabase);

        try {
            for ($index = 0; $index < 2; $index++) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    self::fail('Nie udało się uruchomić procesu księgującego.');
                }
                if ($pid === 0) {
                    while (!is_file($barrier)) {
                        usleep(1_000);
                    }
                    try {
                        $childDb = new Database($config['default']);
                        $transactionId = (new FinancialService($childDb))->postTransaction(
                            $userId,
                            'phpunit_reward',
                            7,
                            'points',
                            'Równoległy test podwójnego kliknięcia',
                            ['source_module' => 'test', 'idempotency_key' => $key]
                        );
                        file_put_contents($directory . '/result-' . $index, (string)$transactionId);
                        exit(0);
                    } catch (\Throwable $error) {
                        file_put_contents($directory . '/error-' . $index, $error::class . ': ' . $error->getMessage());
                        exit(1);
                    }
                }
                $children[] = $pid;
            }

            touch($barrier);
            foreach ($children as $index => $pid) {
                pcntl_waitpid($pid, $status);
                self::assertSame(
                    0,
                    pcntl_wexitstatus($status),
                    is_file($directory . '/error-' . $index) ? (string)file_get_contents($directory . '/error-' . $index) : ''
                );
            }
            $first = (int)file_get_contents($directory . '/result-0');
            $second = (int)file_get_contents($directory . '/result-1');
            self::assertSame($first, $second);
            $database = new Database($config['default']);
            self::assertSame(1, (int)$database->cell('SELECT COUNT(*) FROM wallet_transactions WHERE idempotency_key=:key', ['key' => $key]));
            self::assertSame(7, (int)$database->cell('SELECT points_balance FROM wallets WHERE user_id=:user', ['user' => $userId]));
        } finally {
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $unused, WNOHANG);
            }
            try {
                $database ??= new Database($config['default']);
                $walletId = (int)$database->cell('SELECT id FROM wallets WHERE user_id=:user', ['user' => $userId]);
                $database->query('DELETE FROM financial_operations WHERE idempotency_key=:key', ['key' => $key]);
                $database->query('DELETE FROM financial_wallet_ledger_heads WHERE wallet_id=:wallet', ['wallet' => $walletId]);
                $database->query('DELETE FROM financial_audit_log WHERE user_id=:user', ['user' => $userId]);
                $database->query('DELETE FROM wallet_transactions WHERE wallet_id=:wallet', ['wallet' => $walletId]);
                $database->query('DELETE FROM users WHERE id=:user', ['user' => $userId]);
            } catch (\Throwable) {
            }
            foreach (glob($directory . '/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($directory);
        }
    }

    public function testConcurrentDeliveryCreatesExactlyOneGatewayEvent(): void
    {
        $config = require dirname(__DIR__, 2) . '/config/database.php';
        if (($config['default']['driver'] ?? '') !== 'pgsql' || !function_exists('pcntl_fork')) {
            self::markTestSkipped('Test wymaga PostgreSQL i rozszerzenia pcntl.');
        }

        $eventId = 'phpunit-concurrency-' . bin2hex(random_bytes(8));
        $directory = sys_get_temp_dir() . '/zs_concurrency_' . bin2hex(random_bytes(6));
        if (!mkdir($directory, 0700) && !is_dir($directory)) {
            self::fail('Nie udało się utworzyć katalogu bariery testowej.');
        }
        $barrier = $directory . '/start';
        $children = [];

        try {
            for ($index = 0; $index < 2; $index++) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    self::fail('Nie udało się uruchomić procesu testowego.');
                }
                if ($pid === 0) {
                    while (!is_file($barrier)) {
                        usleep(1_000);
                    }
                    try {
                        $database = new Database($config['default']);
                        $result = (new PaymentGatewayEventService($database))->recordReceived(
                            'phpunit',
                            $eventId,
                            'concurrency.test',
                            '{"ok":true}'
                        );
                        file_put_contents(
                            $directory . '/result-' . $index . '.json',
                            json_encode($result, JSON_THROW_ON_ERROR)
                        );
                        exit(0);
                    } catch (\Throwable $error) {
                        file_put_contents($directory . '/error-' . $index, $error::class . ': ' . $error->getMessage());
                        exit(1);
                    }
                }
                $children[] = $pid;
            }

            touch($barrier);
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                self::assertSame(0, pcntl_wexitstatus($status));
            }

            $results = [];
            for ($index = 0; $index < 2; $index++) {
                $path = $directory . '/result-' . $index . '.json';
                self::assertFileExists($path, is_file($directory . '/error-' . $index)
                    ? (string)file_get_contents($directory . '/error-' . $index)
                    : 'Brak wyniku procesu.');
                $results[] = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            }

            self::assertSame(1, count(array_filter($results, static fn(array $row): bool => $row['duplicate'] === false)));
            self::assertSame(1, count(array_filter($results, static fn(array $row): bool => $row['duplicate'] === true)));
            self::assertSame((int)$results[0]['id'], (int)$results[1]['id']);

            $database = new Database($config['default']);
            self::assertSame(1, (int)$database->cell(
                'SELECT COUNT(*) FROM payment_gateway_events WHERE provider=:provider AND event_id=:event',
                ['provider' => 'phpunit', 'event' => $eventId]
            ));
        } finally {
            try {
                $database ??= new Database($config['default']);
                $database->query(
                    'DELETE FROM payment_gateway_events WHERE provider=:provider AND event_id=:event',
                    ['provider' => 'phpunit', 'event' => $eventId]
                );
            } catch (\Throwable) {
            }
            foreach (glob($directory . '/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($directory);
        }
    }
}

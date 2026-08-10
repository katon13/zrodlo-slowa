<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use PHPUnit\Framework\TestCase;

final class MobileSessionEndpointIntegrationTest extends TestCase
{
    private Database $database;
    private int $userId = 0;
    private string $sessionId = '';
    private int $lastActivity = 0;
    private string $payload = '';

    protected function setUp(): void
    {
        $config = require dirname(__DIR__, 2) . '/config/database.php';
        $this->database = new Database($config['default']);
        $suffix = bin2hex(random_bytes(8));
        $this->userId = $this->database->insert(
            'INSERT INTO users(
                email,password_hash,display_name,status,can_write,talent_enabled,
                wallet_enabled,payout_enabled,session_version,created_at
             ) VALUES(
                :email,:password,:name,\'active\',1,1,1,0,4,NOW()
             )',
            [
                'email' => 'mobile-session-' . $suffix . '@example.test',
                'password' => password_hash('MobileSessionTest-2026!', PASSWORD_DEFAULT),
                'name' => 'Mobile Session PHPUnit',
            ]
        );
        $this->database->query(
            'INSERT INTO user_roles(user_id,role) VALUES(:user,\'author\')',
            ['user' => $this->userId]
        );

        $this->sessionId = bin2hex(random_bytes(16));
        $this->lastActivity = time() - 30;
        $this->payload = sprintf(
            'user_id|i:%d;role|s:6:"author";_session_version|i:4;',
            $this->userId
        );
        $this->database->query(
            'INSERT INTO sessions(id,user_id,payload,last_activity)
             VALUES(:id,:user,:payload,:activity)',
            [
                'id' => $this->sessionId,
                'user' => $this->userId,
                'payload' => $this->payload,
                'activity' => $this->lastActivity,
            ]
        );
    }

    protected function tearDown(): void
    {
        if ($this->sessionId !== '') {
            $this->database->query('DELETE FROM sessions WHERE id=:id', ['id' => $this->sessionId]);
        }
        if ($this->userId > 0) {
            $this->database->query('DELETE FROM user_roles WHERE user_id=:user', ['user' => $this->userId]);
            $this->database->query('DELETE FROM users WHERE id=:user', ['user' => $this->userId]);
        }
    }

    public function testRepeatedMobileSessionProbeDoesNotTouchPayloadActivityOrExpiry(): void
    {
        $expectedExpiry = $this->lastActivity + 600;
        $generations = [];

        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $response = $this->requestEndpoint();
            self::assertTrue($response['ok']);
            self::assertTrue($response['authenticated']);
            self::assertSame($this->userId, $response['user']['id']);
            self::assertTrue($response['user']['can_write']);
            self::assertTrue($response['user']['wallet_enabled']);
            self::assertFalse($response['user']['payout_enabled']);
            self::assertSame(4, $response['session']['version']);
            self::assertSame($expectedExpiry, $response['session']['session_expires_at']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $response['session']['generation']);
            $generations[] = $response['session']['generation'];

            $stored = $this->database->one(
                'SELECT payload,last_activity FROM sessions WHERE id=:id',
                ['id' => $this->sessionId]
            );
            self::assertNotNull($stored);
            self::assertSame($this->payload, $stored['payload']);
            self::assertSame($this->lastActivity, (int)$stored['last_activity']);
            self::assertStringNotContainsString('_mobile_session_generation', (string)$stored['payload']);
        }

        self::assertCount(1, array_unique($generations));
    }

    public function testCommentatorMobileSessionExposesTalentWalletWithoutAuthorOrPayoutRights(): void
    {
        $this->database->query('DELETE FROM user_roles WHERE user_id=:user', ['user' => $this->userId]);
        $this->database->query(
            "INSERT INTO user_roles(user_id,role) VALUES(:user,'commentator')",
            ['user' => $this->userId]
        );
        $this->database->query(
            'UPDATE users SET can_write=0,wallet_enabled=1,talent_enabled=1,payout_enabled=0 WHERE id=:user',
            ['user' => $this->userId]
        );
        $this->payload = sprintf(
            'user_id|i:%d;role|s:11:"commentator";_session_version|i:4;',
            $this->userId
        );
        $this->database->query(
            'UPDATE sessions SET payload=:payload WHERE id=:id',
            ['payload' => $this->payload, 'id' => $this->sessionId]
        );

        $response = $this->requestEndpoint();
        self::assertTrue($response['authenticated']);
        self::assertSame('commentator', $response['user']['primary_role']);
        self::assertContains('commentator', $response['user']['roles']);
        self::assertFalse($response['user']['can_write']);
        self::assertTrue($response['user']['wallet_enabled']);
        self::assertFalse($response['user']['payout_enabled']);
    }

    /** @return array<string,mixed> */
    private function requestEndpoint(): array
    {
        $root = dirname(__DIR__, 2);
        $database = require $root . '/config/database.php';
        $database = $database['default'];
        $valkey = require $root . '/config/valkey.php';
        $environment = getenv();
        $environment = array_merge($environment, [
            'APP_ENV' => 'testing',
            'APP_DEBUG' => 'false',
            'APP_KEY' => (string)env('APP_KEY', 'phpunit-mobile-session-key'),
            'PASSWORD_PEPPER' => (string)env('PASSWORD_PEPPER', 'phpunit-mobile-session-pepper'),
            'FINANCE_HMAC_KEY' => (string)env('FINANCE_HMAC_KEY', 'phpunit-mobile-session-finance'),
            'DB_DRIVER' => (string)$database['driver'],
            'DB_HOST' => (string)$database['host'],
            'DB_PORT' => (string)$database['port'],
            'DB_NAME' => (string)$database['database'],
            'DB_USER' => (string)$database['username'],
            'DB_PASS' => (string)$database['password'],
            'DB_SCHEMA' => (string)$database['schema'],
            'DB_SSLMODE' => (string)($database['sslmode'] ?? 'prefer'),
            'SESSION_NAME' => 'zrodlo_slowa_session',
            'SESSION_DRIVER' => 'valkey',
            'SESSION_TTL_SECONDS' => '600',
            'VALKEY_HOST' => (string)$valkey['host'],
            'VALKEY_PORT' => (string)$valkey['port'],
            'VALKEY_PASSWORD' => (string)$valkey['password'],
            'VALKEY_DATABASE' => (string)$valkey['database'],
            'VALKEY_PREFIX' => (string)$valkey['prefix'],
            'VALKEY_TLS' => $valkey['tls'] ? 'true' : 'false',
            'CACHE_DRIVER' => 'none',
            'RATE_LIMIT_DRIVER' => 'database',
            'LOCK_DRIVER' => 'none',
            'QUEUE_SIGNAL_DRIVER' => 'none',
            'OBJECT_STORAGE_DRIVER' => 'local',
        ]);

        $childCode = sprintf(
            '$_SERVER["REQUEST_METHOD"]="GET";'
            . '$_SERVER["REQUEST_URI"]="/api/mobile/session";'
            . '$_SERVER["REMOTE_ADDR"]="127.0.0.1";'
            . '$_COOKIE["zrodlo_slowa_session"]=%s;'
            . 'require %s;',
            var_export($this->sessionId, true),
            var_export($root . '/public/index.php', true),
        );
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-r', $childCode],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            $environment,
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        self::assertSame(0, $exitCode, $stderr ?: $stdout);

        $decoded = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        return $decoded;
    }
}

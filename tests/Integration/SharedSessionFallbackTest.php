<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\Session\SharedSessionHandler;
use Tests\Support\InMemoryValkeyClient;

final class SharedSessionFallbackTest extends DatabaseTestCase
{
    public function testPostgresqlFallbackSharesSessionWhenValkeyIsUnavailable(): void
    {
        $sessionId = 'phpunit_' . bin2hex(random_bytes(16));
        $firstInstance = new SharedSessionHandler(null, $this->database, 300);
        $secondInstance = new SharedSessionHandler(null, $this->database, 300);

        try {
            self::assertTrue($firstInstance->write($sessionId, 'flash|s:5:"hello";'));
            self::assertSame('flash|s:5:"hello";', $secondInstance->read($sessionId));
        } finally {
            $secondInstance->destroy($sessionId);
        }

        self::assertSame(
            0,
            (int)$this->database->cell('SELECT COUNT(*) FROM sessions WHERE id=:id', ['id' => $sessionId])
        );
    }

    public function testValkeySessionIsShadowedAndSurvivesALaterOutage(): void
    {
        $sessionId = 'phpunit_' . bin2hex(random_bytes(16));
        $payload = 'user_id|i:42;flash|s:5:"hello";';
        $valkey = new InMemoryValkeyClient();
        $primary = new SharedSessionHandler($valkey, $this->database, 300);

        try {
            self::assertTrue($primary->write($sessionId, $payload));
            self::assertSame(
                $payload,
                $this->database->cell('SELECT payload FROM sessions WHERE id=:id', ['id' => $sessionId])
            );

            $duringOutage = new SharedSessionHandler(null, $this->database, 300);
            self::assertSame($payload, $duringOutage->read($sessionId));

            $recoveredValkey = new InMemoryValkeyClient();
            $afterRecovery = new SharedSessionHandler($recoveredValkey, $this->database, 300);
            self::assertSame($payload, $afterRecovery->read($sessionId));

            $this->database->query('DELETE FROM sessions WHERE id=:id', ['id' => $sessionId]);
            self::assertSame(
                $payload,
                (new SharedSessionHandler($recoveredValkey, $this->database, 300))->read($sessionId)
            );
        } finally {
            $primary->destroy($sessionId);
        }
    }
}

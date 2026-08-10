<?php
declare(strict_types=1);

namespace Tests\Integration;

final class TestEnvironmentIsolationTest extends DatabaseTestCase
{
    public function testPhpUnitUsesAnEphemeralDatabaseNamespace(): void
    {
        self::assertSame('testing', env('APP_ENV'));

        $isolation = $GLOBALS['PHPUNIT_DATABASE_ISOLATION'] ?? null;
        self::assertIsArray($isolation);
        self::assertMatchesRegularExpression('/^[a-f0-9]{12}$/D', (string)$isolation['run_suffix']);

        if ($this->database->isPostgres()) {
            self::assertMatchesRegularExpression(
                '/^zrodlo_slowa_test_[a-f0-9]{12}$/D',
                $this->database->schema(),
            );
            self::assertSame(
                $this->database->schema(),
                (string)$this->database->cell('SELECT current_schema()'),
            );
            self::assertNotSame('public', $this->database->schema());
            return;
        }

        $databaseName = (string)$this->database->cell('SELECT DATABASE()');
        self::assertMatchesRegularExpression('/^zrodlo_slowa_test_[a-f0-9]{12}$/D', $databaseName);
        self::assertSame((string)$isolation['database'], $databaseName);
    }
}

<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use PHPUnit\Framework\TestCase;

abstract class DatabaseTestCase extends TestCase
{
    protected Database $database;

    protected function setUp(): void
    {
        // Integration tests share the PHP process, so session data left by a
        // previous test must not point at fixtures already rolled back.
        $_SESSION = [];

        $config = require dirname(__DIR__, 2) . '/config/database.php';
        $this->database = new Database($config['default']);
        $this->database->pdo()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->database->pdo()->inTransaction()) {
            $this->database->pdo()->rollBack();
        }

        $_SESSION = [];
    }
}

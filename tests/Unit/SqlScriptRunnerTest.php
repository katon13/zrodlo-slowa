<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Services\SqlScriptRunner;
use PHPUnit\Framework\TestCase;

final class SqlScriptRunnerTest extends TestCase
{
    public function testDelimiterKeepsTriggerBodyTogether(): void
    {
        $sql = <<<'SQL'
-- komentarz
CREATE TABLE demo (id int);
DELIMITER ;;
CREATE TRIGGER demo_guard BEFORE UPDATE ON demo FOR EACH ROW
BEGIN
  IF NEW.id < 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invalid';
  END IF;
END;;
DELIMITER ;
/*!40101 SET SQL_MODE='STRICT_ALL_TABLES' */;
SQL;
        $statements = (new SqlScriptRunner())->statements($sql);

        self::assertCount(3, $statements);
        self::assertStringContainsString("MESSAGE_TEXT = 'invalid';", $statements[1]);
        self::assertStringEndsWith('END', $statements[1]);
        self::assertStringStartsWith('/*!40101', $statements[2]);
    }

    public function testProjectSchemaAndEveryMigrationParseCompletely(): void
    {
        $root = dirname(__DIR__, 2);
        $runner = new SqlScriptRunner();
        self::assertGreaterThan(300, count($runner->statements((string)file_get_contents($root . '/database/zrodlo_slowa.sql'))));
        foreach (glob($root . '/database/migrations/*.sql') ?: [] as $migration) {
            self::assertNotEmpty($runner->statements((string)file_get_contents($migration)), basename($migration));
        }
    }

    public function testPostgresDollarQuotedFunctionRemainsOneStatement(): void
    {
        $sql = <<<'SQL'
CREATE FUNCTION demo_guard()
RETURNS trigger
LANGUAGE plpgsql
AS $function$
BEGIN
    IF NEW.amount < 0 THEN
        RAISE EXCEPTION 'invalid; amount';
    END IF;
    RETURN NEW;
END;
$function$;
CREATE TRIGGER demo_before_update BEFORE UPDATE ON demo
FOR EACH ROW EXECUTE FUNCTION demo_guard();
SQL;

        $statements = (new SqlScriptRunner())->statements($sql);

        self::assertCount(2, $statements);
        self::assertStringContainsString("RAISE EXCEPTION 'invalid; amount';", $statements[0]);
        self::assertStringStartsWith('CREATE TRIGGER', $statements[1]);
    }
}

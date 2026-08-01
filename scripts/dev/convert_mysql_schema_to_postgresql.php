<?php
declare(strict_types=1);

/**
 * Deterministycznie generuje baseline PostgreSQL z aktualnego schematu MySQL.
 *
 * Użycie:
 * php scripts/dev/convert_mysql_schema_to_postgresql.php
 */

$root = dirname(__DIR__, 2);
$sourceFile = $root . '/database/zrodlo_slowa.sql';
$targetDirectory = $root . '/database/postgresql';
$targetFile = $targetDirectory . '/schema.sql';

$source = file_get_contents($sourceFile);
if (!is_string($source)) {
    throw new RuntimeException("Nie udało się odczytać {$sourceFile}.");
}

preg_match_all(
    '/CREATE TABLE `([^`]+)` \(\R(.*?)\R\) ENGINE=[^;]+;/s',
    $source,
    $tableMatches,
    PREG_SET_ORDER
);
if (count($tableMatches) !== 74) {
    throw new RuntimeException(
        'Oczekiwano 74 tabel w schemacie MySQL, znaleziono: ' . count($tableMatches)
    );
}

$createStatements = [];
$indexStatements = [];
$foreignKeyStatements = [];

foreach ($tableMatches as $tableMatch) {
    $table = $tableMatch[1];
    $definitions = [];
    $lines = preg_split('/\R/', $tableMatch[2]) ?: [];

    foreach ($lines as $line) {
        $line = rtrim(trim($line), ',');
        if ($line === '') {
            continue;
        }

        if (preg_match('/^`([^`]+)`\s+(.+)$/', $line, $columnMatch) === 1) {
            $column = $columnMatch[1];
            $definition = convertColumnDefinition($table, $column, $columnMatch[2]);
            $definitions[] = quoteIdentifier($column) . ' ' . $definition;
            continue;
        }

        if (preg_match('/^PRIMARY KEY \((.+)\)$/i', $line, $primaryMatch) === 1) {
            $definitions[] = 'PRIMARY KEY (' . convertColumnList($primaryMatch[1]) . ')';
            continue;
        }

        if (preg_match('/^UNIQUE KEY `([^`]+)` \((.+)\)$/i', $line, $uniqueMatch) === 1) {
            $definitions[] = sprintf(
                'CONSTRAINT %s UNIQUE (%s)',
                quoteIdentifier(postgresName('uq_' . $table . '_' . $uniqueMatch[1])),
                convertColumnList($uniqueMatch[2])
            );
            continue;
        }

        if (preg_match('/^KEY `([^`]+)` \((.+)\)$/i', $line, $indexMatch) === 1) {
            $indexName = postgresName('idx_' . $table . '_' . $indexMatch[1]);
            $indexStatements[] = sprintf(
                'CREATE INDEX %s ON %s (%s);',
                quoteIdentifier($indexName),
                quoteIdentifier($table),
                convertColumnList($indexMatch[2])
            );
            continue;
        }

        if (preg_match('/^CONSTRAINT `([^`]+)` (FOREIGN KEY .+)$/i', $line, $foreignMatch) === 1) {
            $foreignKeyStatements[] = sprintf(
                'ALTER TABLE %s ADD CONSTRAINT %s %s;',
                quoteIdentifier($table),
                quoteIdentifier(postgresName($foreignMatch[1])),
                str_replace('`', '"', $foreignMatch[2])
            );
            continue;
        }

        throw new RuntimeException("Nieobsługiwana definicja w tabeli {$table}: {$line}");
    }

    $createStatements[] = sprintf(
        "CREATE TABLE %s (\n    %s\n);",
        quoteIdentifier($table),
        implode(",\n    ", $definitions)
    );
}

$header = <<<'SQL'
-- Wygenerowano z database/zrodlo_slowa.sql.
-- Nie edytuj ręcznie; użyj scripts/dev/convert_mysql_schema_to_postgresql.php.
-- Baseline zachowuje dotychczasowy globalny łańcuch księgi. Jego zmiana nie jest
-- częścią migracji PostgreSQL i wymaga osobnego uzgodnienia sald.

SET client_encoding = 'UTF8';
SET TIME ZONE 'UTC';
SET lock_timeout = '10s';
SET statement_timeout = '120s';

BEGIN;
SQL;

$ledgerSeed = <<<'SQL'
INSERT INTO "financial_ledger_head" (
    "id",
    "last_transaction_id",
    "last_entry_hash",
    "hash_version",
    "updated_at"
) VALUES (
    1,
    NULL,
    '0000000000000000000000000000000000000000000000000000000000000000',
    2,
    NOW()
);
SQL;

$walletTrigger = <<<'SQL'
CREATE OR REPLACE FUNCTION enforce_nonnegative_wallet_balances()
RETURNS trigger
LANGUAGE plpgsql
AS $function$
BEGIN
    IF NEW.main_available_minor < 0 THEN
        RAISE EXCEPTION 'Błąd: saldo główne nie może być ujemne.';
    END IF;
    IF NEW.slowo_available_minor < 0 THEN
        RAISE EXCEPTION 'Błąd: saldo SŁOWO nie może być ujemne.';
    END IF;
    IF NEW.main_reserved_minor < 0
       OR NEW.slowo_reserved_minor < 0
       OR NEW.reserved_minor < 0 THEN
        RAISE EXCEPTION 'Błąd: saldo zarezerwowane nie może być ujemne.';
    END IF;
    IF NEW.points_balance < 0 THEN
        RAISE EXCEPTION 'Błąd: saldo Talentów nie może być ujemne.';
    END IF;
    RETURN NEW;
END
$function$;

CREATE TRIGGER trg_wallets_before_update
BEFORE UPDATE ON "wallets"
FOR EACH ROW
EXECUTE FUNCTION enforce_nonnegative_wallet_balances();
SQL;

$output = implode(
    "\n\n",
    [
        $header,
        implode("\n\n", $createStatements),
        $ledgerSeed,
        implode("\n", $indexStatements),
        implode("\n", $foreignKeyStatements),
        $walletTrigger,
        'COMMIT;',
    ]
) . "\n";

if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
    throw new RuntimeException("Nie udało się utworzyć {$targetDirectory}.");
}
if (file_put_contents($targetFile, $output) === false) {
    throw new RuntimeException("Nie udało się zapisać {$targetFile}.");
}

fwrite(
    STDOUT,
    sprintf(
        "PostgreSQL baseline: %d tabel, %d indeksów, %d kluczy obcych.\n",
        count($createStatements),
        count($indexStatements),
        count($foreignKeyStatements)
    )
);

function convertColumnDefinition(string $table, string $column, string $definition): string
{
    $definition = preg_replace('/\s+COLLATE\s+[A-Za-z0-9_]+/i', '', $definition) ?? $definition;

    if (preg_match('/^enum\(([^)]+)\)(.*)$/i', $definition, $enumMatch) === 1) {
        $values = $enumMatch[1];
        $definition = 'text' . $enumMatch[2]
            . ' CHECK (' . quoteIdentifier($column) . ' IN (' . $values . '))';
    } else {
        $replacements = [
            '/\bbigint\s+unsigned\b/i' => 'bigint',
            '/\bint\s+unsigned\b/i' => 'bigint',
            '/\btinyint\s*\(\s*\d+\s*\)\s+unsigned\b/i' => 'smallint',
            '/\btinyint\s+unsigned\b/i' => 'smallint',
            '/\btinyint\s*\(\s*\d+\s*\)/i' => 'smallint',
            '/\btinyint\b/i' => 'smallint',
            '/\bmediumint\s+unsigned\b/i' => 'integer',
            '/\bmediumint\b/i' => 'integer',
            '/\bint\b/i' => 'integer',
            '/\bdatetime\b/i' => 'timestamp without time zone',
            '/\btimestamp\b(?!\s+without\s+time\s+zone)/i' => 'timestamp without time zone',
            '/\blongtext\b/i' => 'text',
            '/\bmediumtext\b/i' => 'text',
            '/\bjson\b/i' => 'jsonb',
            '/\bAUTO_INCREMENT\b/i' => 'GENERATED BY DEFAULT AS IDENTITY',
        ];
        foreach ($replacements as $pattern => $replacement) {
            $definition = preg_replace($pattern, $replacement, $definition) ?? $definition;
        }
    }

    $definition = preg_replace('/\s+ON UPDATE CURRENT_TIMESTAMP\b/i', '', $definition) ?? $definition;
    if (preg_match(
        '/\b(?:unsigned|enum|tinyint|mediumint|datetime|longtext|mediumtext|AUTO_INCREMENT|COLLATE)\b/i',
        $definition
    ) === 1) {
        throw new RuntimeException(
            "Nieprzenośna definicja kolumny {$table}.{$column}: {$definition}"
        );
    }

    return $definition;
}

function convertColumnList(string $columns): string
{
    $converted = str_replace('`', '"', $columns);
    return preg_replace('/"([^"]+)"\(\d+\)/', '"$1"', $converted) ?? $converted;
}

function quoteIdentifier(string $identifier): string
{
    return '"' . str_replace('"', '""', $identifier) . '"';
}

function postgresName(string $name): string
{
    if (strlen($name) <= 63) {
        return $name;
    }
    return substr($name, 0, 54) . '_' . substr(hash('sha256', $name), 0, 8);
}

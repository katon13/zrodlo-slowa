<?php
namespace App\Core;

use PDO;
use PDOStatement;

final class Database
{
    private ?PDO $pdo = null;
    private int $managedTransactionDepth = 0;
    /** @var list<callable():void> */
    private array $afterCommitCallbacks = [];

    public function __construct(private readonly array $config)
    {
        if (!in_array($this->driver(), ['mysql', 'pgsql'], true)) {
            throw new \InvalidArgumentException('Obsługiwane sterowniki bazy danych to mysql i pgsql.');
        }
    }

    public function driver(): string
    {
        return strtolower((string)($this->config['driver'] ?? 'mysql'));
    }

    public function isPostgres(): bool
    {
        return $this->driver() === 'pgsql';
    }

    public function schema(): string
    {
        return $this->validatedIdentifier((string)($this->config['schema'] ?? 'public'));
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ];

            if ($this->isPostgres()) {
                $dsn = sprintf(
                    'pgsql:host=%s;port=%s;dbname=%s;sslmode=%s;application_name=%s',
                    $this->config['host'],
                    $this->config['port'],
                    $this->config['database'],
                    $this->safeSslMode((string)($this->config['sslmode'] ?? 'prefer')),
                    rawurlencode((string)($this->config['application_name'] ?? 'zrodlo-slowa'))
                );
            } else {
                $charset = $this->safeCharset((string)($this->config['charset'] ?? 'utf8mb4'));
                $dsn = sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                    $this->config['host'],
                    $this->config['port'],
                    $this->config['database'],
                    $charset
                );
                $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES {$charset}";
            }

            $this->pdo = new PDO(
                $dsn,
                (string)$this->config['username'],
                (string)$this->config['password'],
                $options
            );
            if ($this->isPostgres()) {
                $this->pdo->exec("SET TIME ZONE 'UTC'");
                $this->pdo->exec('SET search_path TO ' . $this->quoteIdentifier($this->schema()));
            }
        }
        return $this->pdo;
    }

    public function query(string $sql, array $params = []): PDOStatement
    {
        if ($this->isPostgres() && str_contains($sql, '`')) {
            $sql = preg_replace_callback(
                '/`((?:``|[^`])+)`/',
                static fn(array $match): string => '"' . str_replace(
                    '"',
                    '""',
                    str_replace('``', '`', $match[1])
                ) . '"',
                $sql
            ) ?? $sql;
        }
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row ?: null;
    }

    public function cell(string $sql, array $params = []): mixed
    {
        return $this->query($sql, $params)->fetchColumn();
    }

    public function all(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function insert(string $sql, array $params = []): int
    {
        if ($this->isPostgres() && preg_match('/\bRETURNING\b/i', $sql) !== 1) {
            $statement = $this->query(rtrim(trim($sql), ';') . ' RETURNING id', $params);
            $id = $statement->fetchColumn();
            if ($id === false) {
                throw new \RuntimeException('PostgreSQL nie zwrócił identyfikatora nowego rekordu.');
            }
            return (int)$id;
        }

        $this->query($sql, $params);
        return (int)$this->pdo()->lastInsertId();
    }

    public function quoteIdentifier(string $identifier): string
    {
        $identifier = $this->validatedIdentifier($identifier);
        if ($this->isPostgres()) {
            return '"' . str_replace('"', '""', $identifier) . '"';
        }
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    public function nowMinus(int $amount, string $unit): string
    {
        return $this->dateIntervalExpression('-', $amount, $unit);
    }

    public function nowPlus(int $amount, string $unit): string
    {
        return $this->dateIntervalExpression('+', $amount, $unit);
    }

    public function currentMonthStart(): string
    {
        return $this->isPostgres()
            ? "DATE_TRUNC('month', CURRENT_DATE)"
            : "DATE_FORMAT(CURRENT_DATE, '%Y-%m-01')";
    }

    public function tableExists(string $table): bool
    {
        $table = $this->validatedIdentifier($table);
        $row = $this->one(
            'SELECT table_name
             FROM information_schema.tables
             WHERE table_schema=:schema AND table_name=:table
             LIMIT 1',
            ['schema' => $this->catalogSchema(), 'table' => $table]
        );
        return $row !== null;
    }

    public function columnExists(string $table, string $column): bool
    {
        $table = $this->validatedIdentifier($table);
        $column = $this->validatedIdentifier($column);
        $row = $this->one(
            'SELECT column_name
             FROM information_schema.columns
             WHERE table_schema=:schema AND table_name=:table AND column_name=:column
             LIMIT 1',
            ['schema' => $this->catalogSchema(), 'table' => $table, 'column' => $column]
        );
        return $row !== null;
    }

    public function columnNullable(string $table, string $column): bool
    {
        $table = $this->validatedIdentifier($table);
        $column = $this->validatedIdentifier($column);
        $value = $this->cell(
            'SELECT is_nullable
             FROM information_schema.columns
             WHERE table_schema=:schema AND table_name=:table AND column_name=:column
             LIMIT 1',
            ['schema' => $this->catalogSchema(), 'table' => $table, 'column' => $column]
        );
        return strtoupper((string)$value) === 'YES';
    }

    public function transaction(callable $fn): mixed
    {
        $pdo = $this->pdo();
        if ($pdo->inTransaction()) {
            if ($this->managedTransactionDepth <= 0) {
                return $fn($this);
            }
            $this->managedTransactionDepth++;
            try {
                return $fn($this);
            } finally {
                $this->managedTransactionDepth--;
            }
        }
        $this->managedTransactionDepth = 1;
        $this->afterCommitCallbacks = [];
        $pdo->beginTransaction();
        try {
            $result = $fn($this);
            $pdo->commit();
            $callbacks = $this->afterCommitCallbacks;
            $this->afterCommitCallbacks = [];
            $this->managedTransactionDepth = 0;
            // Callback rejestrowany jest wewnątrz przekazanego callable i nie jest widoczny dla analizy statycznej.
            // @phpstan-ignore foreach.emptyArray
            foreach ($callbacks as $callback) {
                try {
                    $callback();
                } catch (\Throwable $error) {
                    error_log('Operacja after-commit nie powiodła się: ' . $error->getMessage());
                }
            }
            return $result;
        } catch (\Throwable $e) {
            $this->rollBackIfActive($pdo);
            $this->afterCommitCallbacks = [];
            $this->managedTransactionDepth = 0;
            throw $e;
        }
    }

    public function afterCommit(callable $callback): bool
    {
        if ($this->managedTransactionDepth > 0) {
            $this->afterCommitCallbacks[] = $callback;
            return true;
        }
        if ($this->pdo()->inTransaction()) {
            // Zewnętrzna transakcja (np. izolacja testu) nie ma obserwowalnego COMMIT.
            return false;
        }
        try {
            $callback();
            return true;
        } catch (\Throwable $error) {
            error_log('Operacja after-commit nie powiodła się: ' . $error->getMessage());
            return false;
        }
    }

    private function rollBackIfActive(\PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    private function validatedIdentifier(string $identifier): string
    {
        if ($identifier === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_$-]*$/D', $identifier) !== 1) {
            throw new \InvalidArgumentException('Nieprawidłowy identyfikator SQL.');
        }
        return $identifier;
    }

    private function safeCharset(string $charset): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/D', $charset) !== 1) {
            throw new \InvalidArgumentException('Nieprawidłowy zestaw znaków bazy danych.');
        }
        return $charset;
    }

    private function safeSslMode(string $sslMode): string
    {
        $allowed = ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'];
        if (!in_array($sslMode, $allowed, true)) {
            throw new \InvalidArgumentException('Nieprawidłowy tryb TLS PostgreSQL.');
        }
        return $sslMode;
    }

    private function catalogSchema(): string
    {
        return $this->isPostgres()
            ? $this->schema()
            : (string)$this->config['database'];
    }

    private function dateIntervalExpression(string $operator, int $amount, string $unit): string
    {
        $unit = strtolower($unit);
        if ($amount < 0 || !in_array($unit, ['second', 'minute', 'hour', 'day'], true)) {
            throw new \InvalidArgumentException('Nieprawidłowy interwał SQL.');
        }
        if ($this->isPostgres()) {
            return sprintf("NOW() %s INTERVAL '%d %s'", $operator, $amount, $unit);
        }
        $function = $operator === '+' ? 'DATE_ADD' : 'DATE_SUB';
        return sprintf('%s(NOW(), INTERVAL %d %s)', $function, $amount, strtoupper($unit));
    }
}

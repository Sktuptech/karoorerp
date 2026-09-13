<?php

declare(strict_types=1);

namespace Karoor\Core;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

final class Database
{
    private ?PDO $connection = null;
    private int $transactionDepth = 0;

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
        if (($config['driver'] ?? null) !== 'mysql') {
            throw new RuntimeException('Only the MySQL database driver is supported.');
        }
    }

    public function connection(): PDO
    {
        if ($this->connection instanceof PDO) {
            return $this->connection;
        }

        $host = (string) ($this->config['host'] ?? '127.0.0.1');
        $port = (int) ($this->config['port'] ?? 3306);
        $database = (string) ($this->config['database'] ?? '');
        $charset = (string) ($this->config['charset'] ?? 'utf8mb4');

        if ($database === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $database)) {
            throw new RuntimeException('The configured database name is invalid.');
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $charset)) {
            throw new RuntimeException('The configured database charset is invalid.');
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $host,
            $port,
            $database,
            $charset
        );

        $this->connection = new PDO(
            $dsn,
            (string) ($this->config['username'] ?? ''),
            (string) ($this->config['password'] ?? ''),
            (array) ($this->config['options'] ?? [])
        );

        $collation = (string) ($this->config['collation'] ?? 'utf8mb4_unicode_ci');
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $collation)) {
            throw new RuntimeException('The configured database collation is invalid.');
        }

        $this->connection->exec(sprintf('SET NAMES %s COLLATE %s', $charset, $collation));

        $databaseTimezone = (string) ($this->config['timezone'] ?? '+03:00');
        if (!preg_match('/^[+-](?:0\d|1[0-4]):[0-5]\d$/', $databaseTimezone)) {
            throw new RuntimeException('DB_TIMEZONE must use a numeric offset such as +03:00.');
        }

        $timezoneStatement = $this->connection->prepare('SET time_zone = :timezone');
        $timezoneStatement->execute(['timezone' => $databaseTimezone]);

        return $this->connection;
    }

    /** @param array<string|int, mixed> $parameters */
    public function statement(string $sql, array $parameters = []): PDOStatement
    {
        [$sql, $parameters] = $this->expandRepeatedNamedParameters($sql, $parameters);
        $statement = $this->connection()->prepare($sql);
        foreach ($parameters as $key => $value) {
            $parameter = is_int($key) ? $key + 1 : ':' . ltrim((string) $key, ':');
            $type = match (true) {
                $value === null => PDO::PARAM_NULL,
                is_bool($value), is_int($value) => PDO::PARAM_INT,
                default => PDO::PARAM_STR,
            };
            $statement->bindValue($parameter, is_bool($value) ? (int) $value : $value, $type);
        }
        $statement->execute();

        return $statement;
    }

    /**
     * Native MySQL prepared statements require every named placeholder to be
     * unique. Keep query authors from having to duplicate equal parameter
     * values manually when a value is used more than once in a statement.
     *
     * @param array<string|int, mixed> $parameters
     * @return array{string, array<string|int, mixed>}
     */
    private function expandRepeatedNamedParameters(string $sql, array $parameters): array
    {
        if ($parameters === [] || array_filter(array_keys($parameters), 'is_int') !== []) {
            return [$sql, $parameters];
        }

        $values = [];
        foreach ($parameters as $key => $value) {
            $values[ltrim((string) $key, ':')] = $value;
        }

        $occurrences = [];
        $expanded = preg_replace_callback(
            '/(?<!:):([a-zA-Z_][a-zA-Z0-9_]*)/',
            static function (array $match) use (&$occurrences, &$values): string {
                $name = $match[1];
                $occurrences[$name] = ($occurrences[$name] ?? 0) + 1;
                if ($occurrences[$name] === 1 || !array_key_exists($name, $values)) {
                    return $match[0];
                }

                $expandedName = $name . '__' . $occurrences[$name];
                $values[$expandedName] = $values[$name];
                return ':' . $expandedName;
            },
            $sql
        );

        return [$expanded ?? $sql, $values];
    }

    /** @param array<string|int, mixed> $parameters
     *  @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $parameters = []): ?array
    {
        $result = $this->statement($sql, $parameters)->fetch();
        return $result === false ? null : $result;
    }

    /** @param array<string|int, mixed> $parameters
     *  @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $parameters = []): array
    {
        /** @var list<array<string, mixed>> $results */
        $results = $this->statement($sql, $parameters)->fetchAll();
        return $results;
    }

    /** @param array<string|int, mixed> $parameters */
    public function execute(string $sql, array $parameters = []): int
    {
        return $this->statement($sql, $parameters)->rowCount();
    }

    /** @param array<string, mixed> $values */
    public function insert(string $table, array $values): int
    {
        if ($values === []) {
            throw new RuntimeException('Cannot insert an empty row.');
        }

        $tableName = self::quoteIdentifier($table);
        $columns = array_keys($values);
        $quotedColumns = array_map(self::quoteIdentifier(...), $columns);
        $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $tableName,
            implode(', ', $quotedColumns),
            implode(', ', $placeholders)
        );
        $this->statement($sql, $values);

        return (int) $this->connection()->lastInsertId();
    }

    /**
     * @template T
     * @param callable(self): T $callback
     * @return T
     */
    public function transaction(callable $callback, int $attempts = 3): mixed
    {
        if ($attempts < 1) {
            throw new RuntimeException('A transaction must allow at least one attempt.');
        }

        if ($this->transactionDepth > 0) {
            return $this->nestedTransaction($callback);
        }

        $attempt = 0;
        beginning:
        $attempt++;
        $pdo = $this->connection();
        $pdo->beginTransaction();
        $this->transactionDepth = 1;

        try {
            $result = $callback($this);
            $pdo->commit();
            $this->transactionDepth = 0;
            return $result;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->transactionDepth = 0;

            if ($attempt < $attempts && $this->isRetryable($exception)) {
                usleep(random_int(20_000, 100_000) * $attempt);
                goto beginning;
            }

            throw $exception;
        }
    }

    public function inTransaction(): bool
    {
        return $this->transactionDepth > 0 && $this->connection()->inTransaction();
    }

    public static function quoteIdentifier(string $identifier): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier)) {
            throw new RuntimeException('Unsafe SQL identifier.');
        }

        return '`' . $identifier . '`';
    }

    /**
     * @template T
     * @param callable(self): T $callback
     * @return T
     */
    private function nestedTransaction(callable $callback): mixed
    {
        $savepoint = 'karoor_sp_' . $this->transactionDepth;
        $this->connection()->exec('SAVEPOINT ' . $savepoint);
        $this->transactionDepth++;

        try {
            $result = $callback($this);
            $this->transactionDepth--;
            $this->connection()->exec('RELEASE SAVEPOINT ' . $savepoint);
            return $result;
        } catch (Throwable $exception) {
            $this->transactionDepth--;
            $this->connection()->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            throw $exception;
        }
    }

    private function isRetryable(Throwable $exception): bool
    {
        if (!$exception instanceof PDOException) {
            return false;
        }

        $sqlState = (string) $exception->getCode();
        $driverCode = isset($exception->errorInfo[1]) ? (int) $exception->errorInfo[1] : 0;

        return $sqlState === '40001' || in_array($driverCode, [1205, 1213], true);
    }
}

<?php

declare(strict_types=1);

namespace Karoor\Core;

use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

final class Backup
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly Database $database,
        private readonly AuditLogger $audit,
        private readonly array $config
    ) {
    }

    /** @return array<string, mixed> */
    public function create(int $companyId, int $userId): array
    {
        if ($companyId < 1 || $userId < 1) {
            throw new RuntimeException('A company and user are required to create a backup.');
        }
        if (!function_exists('gzopen')) {
            throw new RuntimeException('The PHP zlib extension is required to create compressed backups.');
        }

        $directory = $this->backupDirectory();
        $filename = sprintf(
            'karoor-erp-%s-%s.sql.gz',
            date('Ymd-His'),
            bin2hex(random_bytes(5))
        );
        $finalPath = $directory . DIRECTORY_SEPARATOR . $filename;
        $temporaryPath = $finalPath . '.part';
        $emptyChecksum = str_repeat('0', 64);

        $backupId = $this->database->insert('backups', [
            'company_id' => $companyId,
            'filename' => $filename,
            'storage_path' => $finalPath,
            'file_size' => 0,
            'checksum_sha256' => $emptyChecksum,
            'status' => 'CREATING',
            'failure_message' => null,
            'created_by' => $userId,
        ]);

        $stream = null;
        $snapshotOpen = false;
        $finalized = false;

        try {
            $stream = gzopen($temporaryPath, 'wb9');
            if ($stream === false) {
                throw new RuntimeException('Unable to open the backup file for writing.');
            }
            chmod($temporaryPath, 0600);

            $this->write($stream, $this->header());
            $pdo = $this->database->connection();
            if ($pdo->inTransaction()) {
                throw new RuntimeException('A database backup cannot start inside another transaction.');
            }

            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
            $snapshotOpen = true;

            $tables = $this->tableNames();
            foreach ($tables as $table) {
                $this->writeTable($stream, $pdo, $table);
            }

            $this->write($stream, "COMMIT;\nSET FOREIGN_KEY_CHECKS = 1;\n");
            $pdo->commit();
            $snapshotOpen = false;

            gzclose($stream);
            $stream = null;
            if (!rename($temporaryPath, $finalPath)) {
                throw new RuntimeException('Unable to finalize the backup file.');
            }
            $finalized = true;

            $fileSize = filesize($finalPath);
            $checksum = hash_file('sha256', $finalPath);
            if ($fileSize === false || $checksum === false) {
                throw new RuntimeException('Unable to verify the completed backup.');
            }

            $this->database->transaction(function (Database $database) use (
                $backupId,
                $companyId,
                $userId,
                $filename,
                $fileSize,
                $checksum
            ): void {
                $database->execute(
                    'UPDATE backups
                     SET status = \'COMPLETED\', file_size = :file_size,
                         checksum_sha256 = :checksum, failure_message = NULL
                     WHERE id = :id AND status = \'CREATING\'',
                    ['file_size' => $fileSize, 'checksum' => $checksum, 'id' => $backupId]
                );
                $this->audit->log(
                    'BACKUP',
                    'system',
                    $backupId,
                    null,
                    ['filename' => $filename, 'file_size' => $fileSize, 'status' => 'COMPLETED'],
                    'backups',
                    $userId,
                    $companyId
                );
            });

            $record = $this->find($backupId, $companyId)
                ?? throw new RuntimeException('The completed backup record could not be loaded.');
            unset($record['storage_path']);
            return $record;
        } catch (Throwable $exception) {
            if ($snapshotOpen && $this->database->connection()->inTransaction()) {
                $this->database->connection()->rollBack();
            }
            if (is_resource($stream)) {
                gzclose($stream);
            }
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
            if ($finalized && is_file($finalPath)) {
                unlink($finalPath);
            }

            try {
                $this->database->execute(
                    'UPDATE backups
                     SET status = \'FAILED\', failure_message = :message
                     WHERE id = :id',
                    ['message' => substr($exception->getMessage(), 0, 500), 'id' => $backupId]
                );
            } catch (Throwable $recordingException) {
                error_log('Unable to record backup failure: ' . $recordingException->getMessage());
            }

            throw new RuntimeException('Unable to create the database backup.', 0, $exception);
        }
    }

    /** @return list<array<string, mixed>> */
    public function all(int $companyId): array
    {
        return $this->database->fetchAll(
            'SELECT id, filename, file_size, checksum_sha256, status,
                    failure_message, created_by, created_at
             FROM backups
             WHERE company_id = :company_id AND deleted_at IS NULL
             ORDER BY created_at DESC, id DESC',
            ['company_id' => $companyId]
        );
    }

    /** @return array<string, mixed>|null */
    private function find(int $backupId, int $companyId): ?array
    {
        return $this->database->fetchOne(
            'SELECT id, company_id, filename, storage_path, file_size,
                    checksum_sha256, status, failure_message, created_by, created_at
             FROM backups
             WHERE id = :id AND company_id = :company_id AND deleted_at IS NULL
             LIMIT 1',
            ['id' => $backupId, 'company_id' => $companyId]
        );
    }

    public function pathForDownload(int $backupId, int $companyId): string
    {
        $backup = $this->find($backupId, $companyId);
        if ($backup === null || $backup['status'] !== 'COMPLETED') {
            throw new RuntimeException('The requested backup is not available.');
        }

        return $this->verifiedStoredPath((string) $backup['storage_path']);
    }

    public function delete(int $backupId, int $companyId, int $userId): void
    {
        $backup = $this->find($backupId, $companyId);
        if ($backup === null) {
            throw new RuntimeException('The requested backup was not found.');
        }

        if ($backup['status'] === 'CREATING') {
            throw new RuntimeException('A backup that is still being created cannot be deleted.');
        }

        $path = null;
        $quarantinePath = null;
        if (is_file((string) $backup['storage_path'])) {
            $path = $this->verifiedStoredPath((string) $backup['storage_path']);
            $quarantinePath = $path . '.deleting-' . bin2hex(random_bytes(4));
            if (!rename($path, $quarantinePath)) {
                throw new RuntimeException('Unable to prepare the backup for deletion.');
            }
        } elseif ($backup['status'] === 'COMPLETED') {
            throw new RuntimeException('The completed backup file is missing.');
        }

        try {
            $this->database->transaction(function (Database $database) use (
                $backupId,
                $companyId,
                $userId,
                $backup
            ): void {
                $updated = $database->execute(
                    'UPDATE backups
                     SET deleted_at = NOW(), deleted_by = :deleted_by
                     WHERE id = :id AND company_id = :company_id AND deleted_at IS NULL',
                    ['deleted_by' => $userId, 'id' => $backupId, 'company_id' => $companyId]
                );
                if ($updated !== 1) {
                    throw new RuntimeException('The backup was already deleted.');
                }

                $this->audit->log(
                    'DELETE',
                    'system',
                    $backupId,
                    ['filename' => $backup['filename'], 'status' => $backup['status']],
                    ['deleted_at' => date('Y-m-d H:i:s')],
                    'backups',
                    $userId,
                    $companyId
                );
            });
        } catch (Throwable $exception) {
            if (is_string($quarantinePath) && is_string($path)) {
                rename($quarantinePath, $path);
            }
            throw $exception;
        }

        if (is_string($quarantinePath) && !unlink($quarantinePath)) {
            error_log('Unable to remove quarantined backup file: ' . basename($quarantinePath));
        }
    }

    /** @param resource $stream */
    private function writeTable($stream, PDO $pdo, string $table): void
    {
        $quotedTable = Database::quoteIdentifier($table);
        $createRow = $this->database->fetchOne('SHOW CREATE TABLE ' . $quotedTable);
        if ($createRow === null) {
            throw new RuntimeException('Unable to read the schema for a database table.');
        }

        $createSql = array_values($createRow)[1] ?? null;
        if (!is_string($createSql)) {
            throw new RuntimeException('The database returned an invalid table definition.');
        }

        $this->write($stream, "\n-- Table: {$table}\nDROP TABLE IF EXISTS {$quotedTable};\n{$createSql};\n");

        // Backup file metadata points to host-specific private paths and is not
        // portable. Restore the table structure but regenerate its records.
        if ($table === 'backups') {
            return;
        }

        $columnRows = $this->database->fetchAll('SHOW FULL COLUMNS FROM ' . $quotedTable);
        $columns = [];
        $types = [];
        foreach ($columnRows as $column) {
            if (str_contains(strtoupper((string) ($column['Extra'] ?? '')), 'GENERATED')) {
                continue;
            }
            $name = (string) $column['Field'];
            $columns[] = $name;
            $types[$name] = strtolower((string) $column['Type']);
        }

        if ($columns === []) {
            return;
        }

        $columnSql = implode(', ', array_map(Database::quoteIdentifier(...), $columns));
        $bufferedAttribute = defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')
            ? constant('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')
            : null;
        if (is_int($bufferedAttribute)) {
            $pdo->setAttribute($bufferedAttribute, false);
        }

        $statement = null;
        try {
            $statement = $pdo->query('SELECT ' . $columnSql . ' FROM ' . $quotedTable);
            if ($statement === false) {
                throw new RuntimeException('Unable to read table data for backup.');
            }

            $rows = [];
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $values = [];
                foreach ($columns as $column) {
                    $values[] = $this->sqlValue($pdo, $row[$column] ?? null, $types[$column]);
                }
                $rows[] = '(' . implode(', ', $values) . ')';

                if (count($rows) === 250) {
                    $this->writeInsert($stream, $quotedTable, $columnSql, $rows);
                    $rows = [];
                }
            }
            if ($rows !== []) {
                $this->writeInsert($stream, $quotedTable, $columnSql, $rows);
            }
        } finally {
            if ($statement instanceof PDOStatement) {
                $statement->closeCursor();
            }
            if (is_int($bufferedAttribute)) {
                $pdo->setAttribute($bufferedAttribute, true);
            }
        }
    }

    /** @param resource $stream
     *  @param list<string> $rows
     */
    private function writeInsert($stream, string $table, string $columns, array $rows): void
    {
        $this->write(
            $stream,
            sprintf("INSERT INTO %s (%s) VALUES\n%s;\n", $table, $columns, implode(",\n", $rows))
        );
    }

    private function sqlValue(PDO $pdo, mixed $value, string $type): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_resource($value)) {
            $contents = stream_get_contents($value);
            if ($contents === false) {
                throw new RuntimeException('Unable to read a binary database value.');
            }
            $value = $contents;
        }
        if (preg_match('/^(?:tinyint|smallint|mediumint|int|bigint|decimal|numeric|float|double|real|year)/', $type)) {
            $numeric = (string) $value;
            if (!preg_match('/^-?(?:\d+|\d*\.\d+)(?:[eE][+-]?\d+)?$/', $numeric)) {
                throw new RuntimeException('The database returned an invalid numeric value.');
            }
            return $numeric;
        }
        if (preg_match('/(?:binary|blob)/', $type)) {
            return 'X\'' . bin2hex((string) $value) . '\'';
        }

        $quoted = $pdo->quote((string) $value);
        if ($quoted === false) {
            throw new RuntimeException('Unable to quote a database value.');
        }
        return $quoted;
    }

    /** @return list<string> */
    private function tableNames(): array
    {
        $rows = $this->database->fetchAll("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        $tables = [];
        foreach ($rows as $row) {
            $table = array_values($row)[0] ?? null;
            if (!is_string($table)) {
                continue;
            }
            Database::quoteIdentifier($table);
            $tables[] = $table;
        }
        sort($tables, SORT_STRING);
        return $tables;
    }

    /** @param resource $stream */
    private function write($stream, string $contents): void
    {
        $length = strlen($contents);
        $written = 0;
        while ($written < $length) {
            $bytes = gzwrite($stream, substr($contents, $written));
            if ($bytes === false || $bytes === 0) {
                throw new RuntimeException('Unable to write the database backup.');
            }
            $written += $bytes;
        }
    }

    private function header(): string
    {
        return sprintf(
            "-- Karoor ERP database backup\n-- Created: %s\n-- MySQL 8 compatible\n\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSTART TRANSACTION;\n",
            date(DATE_ATOM)
        );
    }

    private function backupDirectory(): string
    {
        $directory = (string) ($this->config['paths']['backups'] ?? '');
        if ($directory === '' || !str_starts_with($directory, DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('The backup directory must be an absolute path.');
        }

        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the protected backup directory.');
        }
        chmod($directory, 0700);

        if (!is_writable($directory)) {
            throw new RuntimeException('The protected backup directory is not writable.');
        }

        return rtrim($directory, DIRECTORY_SEPARATOR);
    }

    private function verifiedStoredPath(string $path): string
    {
        $directory = realpath($this->backupDirectory());
        $resolvedPath = realpath($path);
        if (
            $directory === false
            || $resolvedPath === false
            || !is_file($resolvedPath)
            || !str_starts_with($resolvedPath, $directory . DIRECTORY_SEPARATOR)
        ) {
            throw new RuntimeException('The stored backup path is invalid.');
        }

        return $resolvedPath;
    }
}

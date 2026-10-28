<?php

declare(strict_types=1);

namespace RTIGLOBAL\Phpqdrv\Drivers;

use PDO;

/**
 * SQLite backed queue.
 *
 * SQLite has no row level locking, so writers are serialised with
 * `BEGIN IMMEDIATE`; the guarded UPDATE in the base class then guarantees that
 * a row is claimed exactly once even if several processes race.
 */
final class SQLiteQueue extends AbstractPdoQueue
{
    protected function configureConnection(): void
    {
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        // WAL allows readers and a writer to coexist. It is a no-op for
        // in-memory databases, hence the belt-and-braces try/catch.
        try {
            $this->pdo->exec('PRAGMA journal_mode = WAL');
        } catch (\PDOException) {
            // Non-fatal: fall back to the default rollback journal.
        }
    }

    /** @return list<string> */
    /** @return list<string> */
    protected function schemaStatements(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS queue_jobs (
                id TEXT PRIMARY KEY NOT NULL,
                queue TEXT NOT NULL,
                payload TEXT NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                status TEXT NOT NULL DEFAULT \'pending\',
                available_at INTEGER NOT NULL DEFAULT 0,
                reserved_at INTEGER NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )',
            'CREATE INDEX IF NOT EXISTS queue_jobs_pop_idx
                ON queue_jobs (queue, status, available_at, created_at)',
        ];
    }

    protected function beginTransaction(): void
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
    }

    protected function commit(): void
    {
        $this->pdo->exec('COMMIT');
    }

    protected function rollback(): void
    {
        try {
            $this->pdo->exec('ROLLBACK');
        } catch (\PDOException) {
            // No transaction was active.
        }
    }

    /** Handy for tests and tooling. */
    public function connection(): PDO
    {
        return $this->pdo;
    }
}

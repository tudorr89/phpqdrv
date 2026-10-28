<?php

declare(strict_types=1);

namespace RTIGLOBAL\Phpqdrv\Drivers;

use PDO;

/**
 * PostgreSQL backed queue. Uses `FOR UPDATE SKIP LOCKED` (PostgreSQL >= 9.5).
 *
 * The payload is stored as TEXT rather than JSONB so it round-trips byte for
 * byte: JSONB normalises key order, which would make the payload a user reads
 * back differ from the one they pushed. Subclass and override
 * `payloadSelectExpression()` / `payloadParameter()` to opt into JSONB.
 */
final class PostgreSQLQueue extends AbstractPdoQueue
{
    /** @return list<string> */
    protected function schemaStatements(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS queue_jobs (
                id VARCHAR(64) PRIMARY KEY NOT NULL,
                queue VARCHAR(191) NOT NULL,
                payload TEXT NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                status VARCHAR(16) NOT NULL DEFAULT \'pending\',
                available_at BIGINT NOT NULL DEFAULT 0,
                reserved_at BIGINT NULL,
                created_at BIGINT NOT NULL,
                updated_at BIGINT NOT NULL
            )',
            'CREATE INDEX IF NOT EXISTS queue_jobs_pop_idx
                ON queue_jobs (queue, status, available_at, created_at)',
        ];
    }

    protected function lockClause(): string
    {
        return ' FOR UPDATE SKIP LOCKED';
    }

    /** Handy for tests and tooling. */
    public function connection(): PDO
    {
        return $this->pdo;
    }
}

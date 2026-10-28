<?php

declare(strict_types=1);

namespace RTIGLOBAL\Phpqdrv\Drivers;

use PDO;

/**
 * MariaDB / MySQL backed queue.
 *
 * `SKIP LOCKED` needs MariaDB >= 10.6 or MySQL >= 8.0. On older servers the
 * driver falls back to a plain `FOR UPDATE`, which is still correct (the
 * guarded UPDATE keeps claims exclusive) but serialises concurrent workers.
 */
final class MariaDBQueue extends AbstractPdoQueue
{
    private ?bool $skipLockedSupported = null;

    /** @return list<string> */
    /** @return list<string> */
    protected function schemaStatements(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS queue_jobs (
                id VARCHAR(64) NOT NULL,
                queue VARCHAR(191) NOT NULL,
                payload LONGTEXT NOT NULL,
                attempts INT NOT NULL DEFAULT 0,
                status ENUM(\'pending\', \'processing\', \'completed\', \'failed\') NOT NULL DEFAULT \'pending\',
                available_at BIGINT NOT NULL DEFAULT 0,
                reserved_at BIGINT NULL,
                created_at BIGINT NOT NULL,
                updated_at BIGINT NOT NULL,
                PRIMARY KEY (id),
                KEY queue_jobs_pop_idx (queue, status, available_at, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ];
    }

    protected function lockClause(): string
    {
        return $this->supportsSkipLocked() ? ' FOR UPDATE SKIP LOCKED' : ' FOR UPDATE';
    }

    private function supportsSkipLocked(): bool
    {
        if ($this->skipLockedSupported !== null) {
            return $this->skipLockedSupported;
        }

        try {
            $version = (string) $this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        } catch (\PDOException) {
            return $this->skipLockedSupported = false;
        }

        if (preg_match('/(\d+)\.(\d+)\.(\d+)/', $version, $matches) !== 1) {
            return $this->skipLockedSupported = false;
        }

        $required = stripos($version, 'mariadb') !== false ? '10.6.0' : '8.0.0';

        return $this->skipLockedSupported = version_compare(
            sprintf('%d.%d.%d', $matches[1], $matches[2], $matches[3]),
            $required,
            '>='
        );
    }

    /** Handy for tests and tooling. */
    public function connection(): PDO
    {
        return $this->pdo;
    }
}

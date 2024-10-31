<?php

namespace Tudorr89\Phpqdrv\Drivers;

use PDO;
use Tudorr89\Phpqdrv\Contracts\JobInterface;
use Tudorr89\Phpqdrv\Contracts\QueueInterface;
use Tudorr89\Phpqdrv\Job;

class SQLiteQueue implements QueueInterface
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->createQueueTable();
    }

    private function createQueueTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS queue_jobs (
                id TEXT PRIMARY KEY,
                queue TEXT,
                payload TEXT,
                attempts INTEGER DEFAULT 0,
                status TEXT DEFAULT 'pending',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }

    public function push(string $queue, array $payload): JobInterface
    {
        $jobId = uniqid('job_', true);
        $stmt = $this->pdo->prepare(
            "INSERT INTO queue_jobs (id, queue, payload) VALUES (?, ?, ?)"
        );
        $stmt->execute([$jobId, $queue, json_encode($payload)]);

        return new Job($jobId, $queue, $payload);
    }

    public function pop(string $queue): ?JobInterface
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM queue_jobs
                WHERE queue = ? AND status = 'pending'
                ORDER BY created_at ASC
                LIMIT 1
            ");
            $stmt->execute([$queue]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$job) {
                $this->pdo->rollBack();
                return null;
            }

            $updateStmt = $this->pdo->prepare(
                "UPDATE queue_jobs SET attempts = attempts + 1, status = 'processing' WHERE id = ?"
            );
            $updateStmt->execute([$job['id']]);

            $this->pdo->commit();

            return new Job(
                $job['id'],
                $queue,
                json_decode($job['payload'], true),
                $job['attempts']
            );
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function ack(JobInterface $job): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE queue_jobs SET status = 'completed' WHERE id = ?"
        );
        $stmt->execute([$job->getId()]);
    }

    public function fail(JobInterface $job): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE queue_jobs SET status = 'failed' WHERE id = ?"
        );
        $stmt->execute([$job->getId()]);
    }

    public function count(string $queue): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM queue_jobs WHERE queue = ? AND status = 'pending'"
        );
        $stmt->execute([$queue]);
        return $stmt->fetchColumn();
    }
}
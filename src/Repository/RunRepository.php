<?php

declare(strict_types=1);

namespace Shinigami\Repository;

use PDO;

final class RunRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function start(string $command): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO runs (command, started_at, status) VALUES (:command, :started_at, \'running\')'
        );
        $stmt->execute([
            'command' => $command,
            'started_at' => self::now(),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, int> $stats
     */
    public function finish(int $runId, string $status, array $stats, ?string $error = null): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE runs
             SET finished_at = :finished_at,
                 status = :status,
                 projects_seen = :projects_seen,
                 projects_synced = :projects_synced,
                 projects_skipped = :projects_skipped,
                 chapters_new = :chapters_new,
                 chapters_done = :chapters_done,
                 chapters_failed = :chapters_failed,
                 chapters_skipped = :chapters_skipped,
                 images_downloaded = :images_downloaded,
                 bytes_downloaded = :bytes_downloaded,
                 error = :error
             WHERE id = :id'
        );

        $stmt->execute([
            'finished_at' => self::now(),
            'status' => $status,
            'projects_seen' => $stats['projects_seen'] ?? 0,
            'projects_synced' => $stats['projects_synced'] ?? 0,
            'projects_skipped' => $stats['projects_skipped'] ?? 0,
            'chapters_new' => $stats['chapters_new'] ?? 0,
            'chapters_done' => $stats['chapters_done'] ?? 0,
            'chapters_failed' => $stats['chapters_failed'] ?? 0,
            'chapters_skipped' => $stats['chapters_skipped'] ?? 0,
            'images_downloaded' => $stats['images_downloaded'] ?? 0,
            'bytes_downloaded' => $stats['bytes_downloaded'] ?? 0,
            'error' => $error,
            'id' => $runId,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function last(): ?array
    {
        $row = $this->pdo->query(
            'SELECT * FROM runs ORDER BY id DESC LIMIT 1'
        )->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 10): array
    {
        $limit = max(1, min(100, $limit));
        return $this->pdo->query('SELECT * FROM runs ORDER BY id DESC LIMIT ' . $limit)->fetchAll();
    }

    private static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}

<?php

declare(strict_types=1);

namespace Shinigami\Repository;

use PDO;
use Shinigami\Domain\ChapterSummary;
use Shinigami\Support\PathBuilder;
use Shinigami\Support\Slugger;

final class ChapterRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param list<ChapterSummary> $chapters
     * @return array{new: int, reset: int}
     */
    public function upsertMany(string $mangaId, array $chapters): array
    {
        $existingRows = $this->fetchExisting($mangaId);

        $existingById = [];
        $folderOwner = [];

        foreach ($existingRows as $row) {
            $existingById[(string) $row['chapter_id']] = $row;
            $folderOwner[(string) $row['folder']] = (string) $row['chapter_id'];
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO chapters (
                chapter_id, manga_id, chapter_number, title, folder, release_date,
                status, discovered_at, updated_at
            ) VALUES (
                :chapter_id, :manga_id, :chapter_number, :title, :folder, :release_date,
                \'pending\', :discovered_at, :updated_at
            )
            ON CONFLICT(chapter_id) DO UPDATE SET
                chapter_number = excluded.chapter_number,
                title = excluded.title,
                release_date = excluded.release_date,
                updated_at = excluded.updated_at'
        );

        $resetStatement = $this->pdo->prepare(
            'UPDATE chapters
             SET status = \'pending\',
                 dirty = 1,
                 image_count = 0,
                 downloaded_count = 0,
                 bytes = 0,
                 attempts = 0,
                 last_error = NULL,
                 completed_at = NULL,
                 updated_at = :updated_at
             WHERE chapter_id = :chapter_id'
        );

        $now = self::now();
        $newCount = 0;
        $resetCount = 0;
        $ownsTransaction = !$this->pdo->inTransaction();

        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            foreach ($chapters as $chapter) {
                if ($chapter->chapterId === '') {
                    continue;
                }

                $existing = $existingById[$chapter->chapterId] ?? null;

                if ($existing !== null) {
                    $folder = (string) $existing['folder'];
                } else {
                    $folder = PathBuilder::chapterFolderName($chapter->chapterNumber);

                    if (isset($folderOwner[$folder]) && $folderOwner[$folder] !== $chapter->chapterId) {
                        $folder .= '-' . Slugger::shortId($chapter->chapterId);
                    }

                    if (isset($folderOwner[$folder]) && $folderOwner[$folder] !== $chapter->chapterId) {
                        $folder .= '-' . strtolower($chapter->chapterId);
                    }

                    $folderOwner[$folder] = $chapter->chapterId;
                    $newCount++;
                }

                $insert->execute([
                    'chapter_id' => $chapter->chapterId,
                    'manga_id' => $mangaId,
                    'chapter_number' => $chapter->chapterNumber,
                    'title' => $chapter->title,
                    'folder' => $folder,
                    'release_date' => $chapter->releaseDate,
                    'discovered_at' => $now,
                    'updated_at' => $now,
                ]);

                $reset = $existing !== null
                    && in_array($existing['status'], ['done', 'empty'], true)
                    && $existing['release_date'] !== null
                    && $chapter->releaseDate !== null
                    && $existing['release_date'] !== $chapter->releaseDate;

                if ($reset) {
                    $resetStatement->execute([
                        'updated_at' => $now,
                        'chapter_id' => $chapter->chapterId,
                    ]);
                    $resetCount++;
                }
            }

            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        return ['new' => $newCount, 'reset' => $resetCount];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findForDownload(
        string $mangaId,
        ?int $latestLimit = null,
        bool $includeDone = false,
    ): array {
        $processable = "(status IN ('pending', 'failed', 'downloading') OR dirty = 1)";

        if ($latestLimit !== null && $latestLimit > 0) {
            $sql = 'SELECT * FROM (
                        SELECT * FROM chapters
                        WHERE manga_id = :manga_id
                        ORDER BY chapter_number DESC
                        LIMIT ' . (int) $latestLimit . '
                    ) AS recent';

            if (!$includeDone) {
                $sql .= ' WHERE ' . $processable;
            }

            $sql .= ' ORDER BY chapter_number DESC';
        } else {
            $sql = 'SELECT * FROM chapters WHERE manga_id = :manga_id';

            if (!$includeDone) {
                $sql .= ' AND ' . $processable;
            }

            $sql .= ' ORDER BY chapter_number DESC';
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['manga_id' => $mangaId]);

        return $stmt->fetchAll();
    }

    public function countProcessable(string $mangaId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM chapters
             WHERE manga_id = :manga_id
               AND (status IN (\'pending\', \'failed\', \'downloading\') OR dirty = 1)'
        );
        $stmt->execute(['manga_id' => $mangaId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param list<string> $chapterIds
     */
    public function markDownloading(array $chapterIds): void
    {
        if ($chapterIds === []) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE chapters
             SET status = \'downloading\', attempts = attempts + 1, updated_at = :updated_at
             WHERE chapter_id = :chapter_id'
        );

        $now = self::now();

        foreach ($chapterIds as $chapterId) {
            $stmt->execute(['updated_at' => $now, 'chapter_id' => $chapterId]);
        }
    }

    public function markDone(
        string $chapterId,
        int $imageCount,
        int $downloadedCount,
        int $bytes,
        ?string $sourceUpdatedAt,
        ?string $releaseDate,
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE chapters
             SET status = \'done\',
                 dirty = 0,
                 image_count = :image_count,
                 downloaded_count = :downloaded_count,
                 bytes = :bytes,
                 source_updated_at = :source_updated_at,
                 release_date = COALESCE(:release_date, release_date),
                 last_error = NULL,
                 completed_at = :completed_at,
                 updated_at = :updated_at
             WHERE chapter_id = :chapter_id'
        );

        $now = self::now();

        $stmt->execute([
            'image_count' => $imageCount,
            'downloaded_count' => $downloadedCount,
            'bytes' => $bytes,
            'source_updated_at' => $sourceUpdatedAt,
            'release_date' => $releaseDate,
            'completed_at' => $now,
            'updated_at' => $now,
            'chapter_id' => $chapterId,
        ]);
    }

    public function markFailed(string $chapterId, string $error): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE chapters
             SET status = \'failed\',
                 last_error = :last_error,
                 updated_at = :updated_at
             WHERE chapter_id = :chapter_id'
        );

        $now = self::now();

        $stmt->execute([
            'last_error' => mb_substr($error, 0, 2000),
            'updated_at' => $now,
            'chapter_id' => $chapterId,
        ]);
    }

    public function markEmpty(string $chapterId, string $reason, ?string $sourceUpdatedAt): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE chapters
             SET status = \'empty\',
                 dirty = 0,
                 last_error = :reason,
                 source_updated_at = :source_updated_at,
                 completed_at = :completed_at,
                 updated_at = :updated_at
             WHERE chapter_id = :chapter_id'
        );

        $now = self::now();

        $stmt->execute([
            'reason' => mb_substr($reason, 0, 2000),
            'source_updated_at' => $sourceUpdatedAt,
            'completed_at' => $now,
            'updated_at' => $now,
            'chapter_id' => $chapterId,
        ]);
    }

    public function resetForRework(string $chapterId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE chapters
             SET status = \'pending\',
                 dirty = 0,
                 image_count = 0,
                 downloaded_count = 0,
                 bytes = 0,
                 attempts = 0,
                 last_error = NULL,
                 source_updated_at = NULL,
                 completed_at = NULL,
                 updated_at = :updated_at
             WHERE chapter_id = :chapter_id'
        );

        $stmt->execute(['updated_at' => self::now(), 'chapter_id' => $chapterId]);
    }

    public function resetFailed(?string $mangaId = null): int
    {
        if ($mangaId === null) {
            $stmt = $this->pdo->prepare(
                'UPDATE chapters
                 SET status = \'pending\', attempts = 0, last_error = NULL, updated_at = :updated_at
                 WHERE status = \'failed\''
            );
            $stmt->execute(['updated_at' => self::now()]);

            return $stmt->rowCount();
        }

        $stmt = $this->pdo->prepare(
            'UPDATE chapters
             SET status = \'pending\', attempts = 0, last_error = NULL, updated_at = :updated_at
             WHERE status = \'failed\' AND manga_id = :manga_id'
        );
        $stmt->execute(['updated_at' => self::now(), 'manga_id' => $mangaId]);

        return $stmt->rowCount();
    }

    /**
     * @return array<string, array{chapters: int, images: int, bytes: int}>
     */
    public function statsByStatus(): array
    {
        $rows = $this->pdo->query(
            'SELECT status,
                    COUNT(*) AS chapters,
                    COALESCE(SUM(image_count), 0) AS images,
                    COALESCE(SUM(bytes), 0) AS bytes
             FROM chapters
             GROUP BY status
             ORDER BY status'
        )->fetchAll();

        $stats = [];

        foreach ($rows as $row) {
            $stats[(string) $row['status']] = [
                'chapters' => (int) $row['chapters'],
                'images' => (int) $row['images'],
                'bytes' => (int) $row['bytes'],
            ];
        }

        return $stats;
    }

    /**
     * @return array{chapters: int, images: int, bytes: int}
     */
    public function totals(): array
    {
        $row = $this->pdo->query(
            'SELECT COUNT(*) AS chapters,
                    COALESCE(SUM(image_count), 0) AS images,
                    COALESCE(SUM(bytes), 0) AS bytes
             FROM chapters'
        )->fetch();

        return [
            'chapters' => (int) ($row['chapters'] ?? 0),
            'images' => (int) ($row['images'] ?? 0),
            'bytes' => (int) ($row['bytes'] ?? 0),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchExisting(string $mangaId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM chapters WHERE manga_id = :manga_id');
        $stmt->execute(['manga_id' => $mangaId]);

        return $stmt->fetchAll();
    }

    private static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}

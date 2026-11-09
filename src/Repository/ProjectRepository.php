<?php

declare(strict_types=1);

namespace Shinigami\Repository;

use PDO;
use Shinigami\Domain\Project;
use Shinigami\Support\Slugger;

final class ProjectRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $mangaId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM projects WHERE manga_id = :manga_id LIMIT 1');
        $stmt->execute(['manga_id' => $mangaId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByIdOrSlug(string $needle): ?array
    {
        $row = $this->find($needle);

        if ($row !== null) {
            return $row;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM projects WHERE slug = :slug ORDER BY title LIMIT 1');
        $stmt->execute(['slug' => $needle]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @param list<Project> $projects
     * @return array<string, string> Map of manga_id => slug
     */
    public function upsertMany(array $projects): array
    {
        $slugs = [];
        $ownsTransaction = !$this->pdo->inTransaction();

        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            foreach ($projects as $project) {
                $slugs[$project->mangaId] = $this->upsert($project);
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

        return $slugs;
    }

    /**
     * Insert or refresh metadata. The slug and latest-chapter columns are
     * intentionally preserved: the slug keeps folders stable, and the latest
     * chapter is only advanced after the chapter list was fetched successfully.
     */
    public function upsert(Project $project): string
    {
        $existing = $this->find($project->mangaId);
        $slug = $existing['slug'] ?? $this->uniqueSlug($project);
        $now = self::now();

        $stmt = $this->pdo->prepare(
            'INSERT INTO projects (
                manga_id, title, slug, format, cover_url, cover_portrait_url,
                latest_chapter_id, latest_chapter_number, source_updated_at,
                payload, created_at, updated_at
            ) VALUES (
                :manga_id, :title, :slug, :format, :cover_url, :cover_portrait_url,
                :latest_chapter_id, :latest_chapter_number, :source_updated_at,
                :payload, :created_at, :updated_at
            )
            ON CONFLICT(manga_id) DO UPDATE SET
                title = excluded.title,
                cover_url = excluded.cover_url,
                cover_portrait_url = excluded.cover_portrait_url,
                source_updated_at = excluded.source_updated_at,
                payload = excluded.payload,
                updated_at = excluded.updated_at'
        );

        $stmt->execute([
            'manga_id' => $project->mangaId,
            'title' => $project->title,
            'slug' => $slug,
            'format' => $project->format,
            'cover_url' => $project->coverImageUrl,
            'cover_portrait_url' => $project->coverPortraitUrl,
            'latest_chapter_id' => $project->latestChapterId,
            'latest_chapter_number' => $project->latestChapterNumber,
            'source_updated_at' => $project->sourceUpdatedAt,
            'payload' => json_encode(
                $project->payload,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            ),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $slug;
    }

    public function markSynced(
        string $mangaId,
        ?string $latestChapterId,
        float $latestChapterNumber,
        ?string $sourceUpdatedAt,
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE projects
             SET latest_chapter_id = :latest_chapter_id,
                 latest_chapter_number = :latest_chapter_number,
                 source_updated_at = :source_updated_at,
                 last_synced_at = :last_synced_at,
                 updated_at = :updated_at
             WHERE manga_id = :manga_id'
        );

        $now = self::now();

        $stmt->execute([
            'latest_chapter_id' => $latestChapterId,
            'latest_chapter_number' => $latestChapterNumber,
            'source_updated_at' => $sourceUpdatedAt,
            'last_synced_at' => $now,
            'updated_at' => $now,
            'manga_id' => $mangaId,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->pdo->query('SELECT * FROM projects ORDER BY title')->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listWithStats(): array
    {
        return $this->pdo->query(
            'SELECT p.manga_id,
                    p.title,
                    p.slug,
                    p.format,
                    p.latest_chapter_number,
                    COUNT(c.chapter_id) AS chapters,
                    COALESCE(SUM(CASE WHEN c.status = \'done\' THEN 1 ELSE 0 END), 0) AS done,
                    COALESCE(SUM(CASE WHEN c.status = \'failed\' THEN 1 ELSE 0 END), 0) AS failed
             FROM projects p
             LEFT JOIN chapters c ON c.manga_id = p.manga_id
             GROUP BY p.manga_id
             ORDER BY p.title'
        )->fetchAll();
    }

    /**
     * @return array<string, int>
     */
    public function countByFormat(): array
    {
        $rows = $this->pdo->query(
            'SELECT format, COUNT(*) AS total FROM projects GROUP BY format ORDER BY format'
        )->fetchAll();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row['format']] = (int) $row['total'];
        }

        return $counts;
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn();
    }

    private function uniqueSlug(Project $project): string
    {
        $base = Slugger::slug($project->title);
        $candidates = [
            $base,
            $base . '-' . Slugger::shortId($project->mangaId),
            $base . '-' . Slugger::shortId($project->mangaId, 12),
        ];

        $suffix = 2;

        while (true) {
            foreach ($candidates as $candidate) {
                $stmt = $this->pdo->prepare(
                    'SELECT manga_id FROM projects WHERE format = :format AND slug = :slug LIMIT 1'
                );
                $stmt->execute(['format' => $project->format, 'slug' => $candidate]);

                if ($stmt->fetchColumn() === false) {
                    return $candidate;
                }
            }

            $candidates = [$base . '-' . $suffix];
            $suffix++;
        }
    }

    private static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}

<?php

declare(strict_types=1);

namespace Shinigami\Scraper;

use RuntimeException;
use Shinigami\Api\ShinigamiApi;
use Shinigami\Config;
use Shinigami\Domain\ChapterDetail;
use Shinigami\Domain\Project;
use Shinigami\Download\DownloadJob;
use Shinigami\Download\DownloadResult;
use Shinigami\Download\ImageDownloader;
use Shinigami\Repository\ChapterRepository;
use Shinigami\Repository\ProjectRepository;
use Shinigami\Repository\RunRepository;
use Shinigami\Support\Cancellation;
use Shinigami\Support\FileSystem;
use Shinigami\Support\Logger;
use Shinigami\Support\PathBuilder;

final class SyncService
{
    public function __construct(
        private readonly Config $config,
        private readonly ShinigamiApi $api,
        private readonly ProjectRepository $projects,
        private readonly ChapterRepository $chapters,
        private readonly RunRepository $runs,
        private readonly ImageDownloader $downloader,
        private readonly PathBuilder $paths,
        private readonly Logger $logger,
        private readonly Cancellation $cancellation,
    ) {
    }

    public function run(SyncOptions $options): RunStats
    {
        $stats = new RunStats();
        $runId = $this->runs->start($options->command);
        $status = 'success';
        $error = null;

        try {
            $projects = $this->api->listProjects();
            $stats->projectsSeen = count($projects);

            $projects = $this->filterProjects($projects, $options);
            $total = count($projects);

            $this->logger->info('Sync started', [
                'projects' => $total,
                'latest' => $options->latest,
                'force' => $options->force,
                'refresh' => $options->refresh,
                'dry_run' => $options->dryRun,
                'concurrency' => $options->concurrency ?? $this->config->downloadConcurrency,
            ]);

            // Persist all metadata first. Slug resolution needs to see every
            // project so duplicate titles are handled deterministically.
            $this->projects->upsertMany($projects);

            $index = 0;

            foreach ($projects as $project) {
                if ($this->cancellation->isRequested()) {
                    $status = 'cancelled';
                    break;
                }

                $index++;

                try {
                    $this->syncProject($project, $options, $stats, $index, $total);
                } catch (\Throwable $e) {
                    $this->logger->error('Project sync failed', [
                        'manga_id' => $project->mangaId,
                        'title' => $project->title,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            $status = 'failed';
            $error = $e->getMessage();
            $this->logger->error('Sync aborted', ['error' => $error]);
            throw $e;
        } finally {
            $this->runs->finish($runId, $status, $stats->toArray(), $error);
            $this->logger->info('Sync finished', array_merge($stats->toArray(), ['status' => $status]));
        }

        return $stats;
    }

    /**
     * @param list<Project> $projects
     * @return list<Project>
     */
    private function filterProjects(array $projects, SyncOptions $options): array
    {
        if ($options->projectFilter !== null) {
            $needle = $options->projectFilter;
            $filtered = array_values(array_filter(
                $projects,
                static fn (Project $project): bool => $project->mangaId === $needle
            ));

            // A slug only exists after the project was stored at least once,
            // so fall back to the database for slug lookups.
            if ($filtered === []) {
                $row = $this->projects->findByIdOrSlug($needle);

                if ($row !== null) {
                    $needle = (string) $row['manga_id'];
                    $filtered = array_values(array_filter(
                        $projects,
                        static fn (Project $project): bool => $project->mangaId === $needle
                    ));
                }
            }

            if ($filtered === []) {
                throw new RuntimeException('Project not found: ' . $options->projectFilter);
            }

            $projects = $filtered;
        }

        if ($options->typeFilter !== null) {
            $type = strtolower($options->typeFilter);

            if (!in_array($type, ['manga', 'manhwa', 'manhua'], true)) {
                throw new RuntimeException('Invalid type filter (expected manga, manhwa or manhua): ' . $type);
            }

            $projects = array_values(array_filter(
                $projects,
                static fn (Project $project): bool => $project->format === $type
            ));
        }

        if ($options->limitProjects !== null && $options->limitProjects > 0) {
            $projects = array_slice($projects, 0, $options->limitProjects);
        }

        return $projects;
    }

    private function syncProject(
        Project $project,
        SyncOptions $options,
        RunStats $stats,
        int $index,
        int $total,
    ): void {
        $row = $this->projects->find($project->mangaId);

        if ($row === null) {
            return;
        }

        $latestChanged = (string) ($row['latest_chapter_id'] ?? '') !== (string) ($project->latestChapterId ?? '');
        $hasProcessable = $this->chapters->countProcessable($project->mangaId) > 0;

        if (
            !$latestChanged
            && !$hasProcessable
            && !$options->force
            && !$options->refresh
            && $row['last_synced_at'] !== null
        ) {
            $stats->projectsSkipped++;
            $this->logger->debug('Project unchanged, skipped', [
                'manga_id' => $project->mangaId,
                'title' => $project->title,
            ]);

            return;
        }

        $needsChapterList = $row['last_synced_at'] === null
            || $latestChanged
            || $options->force
            || $options->refresh;

        if ($needsChapterList) {
            $summaries = $this->api->chapterList($project->mangaId);
            $upsert = $this->chapters->upsertMany($project->mangaId, $summaries);
            $stats->chaptersNew += $upsert['new'];

            if (!$options->dryRun) {
                $this->projects->markSynced(
                    $project->mangaId,
                    $project->latestChapterId,
                    $project->latestChapterNumber,
                    $project->sourceUpdatedAt,
                );
            }

            $this->logger->debug('Chapter list synced', [
                'manga_id' => $project->mangaId,
                'chapters' => count($summaries),
                'new' => $upsert['new'],
                'reset' => $upsert['reset'],
            ]);
        }

        $chapters = $this->chapters->findForDownload(
            $project->mangaId,
            $options->latest,
            $options->force || $options->refresh,
        );

        if ($chapters === []) {
            $stats->projectsSynced++;
            $this->logger->debug('No chapters to download', [
                'manga_id' => $project->mangaId,
                'title' => $project->title,
            ]);

            return;
        }

        if ($options->dryRun) {
            $stats->projectsSynced++;
            $this->logger->info(sprintf(
                '[%d/%d] DRY RUN %s (%s): %d chapter(s) would be processed',
                $index,
                $total,
                $project->title,
                $row['format'],
                count($chapters),
            ));

            return;
        }

        $this->ensureProjectArtifacts($project, $row);

        $projectStats = [
            'done' => 0,
            'failed' => 0,
            'skipped' => 0,
            'images' => 0,
            'bytes' => 0,
        ];

        foreach (array_chunk($chapters, $this->config->chapterChunk) as $chunk) {
            if ($this->cancellation->isRequested()) {
                break;
            }

            $this->processChunk($project, $row, $chunk, $options, $stats, $projectStats);
        }

        $stats->projectsSynced++;
        $this->logger->info(sprintf(
            '[%d/%d] %s (%s): %d done, %d failed, %d skipped, %d images, %s',
            $index,
            $total,
            $project->title,
            $row['format'],
            $projectStats['done'],
            $projectStats['failed'],
            $projectStats['skipped'],
            $projectStats['images'],
            FileSystem::humanBytes($projectStats['bytes']),
        ));
    }

    /**
     * @param array<string, mixed> $projectRow
     * @param list<array<string, mixed>> $chapterRows
     * @param array{done: int, failed: int, skipped: int, images: int, bytes: int} $projectStats
     */
    private function processChunk(
        Project $project,
        array $projectRow,
        array $chapterRows,
        SyncOptions $options,
        RunStats $stats,
        array &$projectStats,
    ): void {
        $format = (string) $projectRow['format'];
        $slug = (string) $projectRow['slug'];
        $chapterIds = array_map(static fn (array $row): string => (string) $row['chapter_id'], $chapterRows);
        $details = $this->api->chapterDetails(
            $chapterIds,
            $options->apiConcurrency ?? $this->config->apiConcurrency,
        );

        $pending = [];
        $jobs = [];

        foreach ($chapterRows as $chapterRow) {
            $chapterId = (string) $chapterRow['chapter_id'];
            $folder = (string) $chapterRow['folder'];
            $detailResult = $details[$chapterId] ?? ['ok' => false, 'error' => 'missing API response'];

            if (!$detailResult['ok'] || !($detailResult['detail'] ?? null) instanceof ChapterDetail) {
                // Do not destroy a completed chapter just because a refresh
                // request hit a transient API error.
                if ($chapterRow['status'] === 'done') {
                    $stats->chaptersSkipped++;
                    $projectStats['skipped']++;
                    $this->logger->warn('Chapter refresh failed, keeping existing files', [
                        'chapter_id' => $chapterId,
                        'error' => $detailResult['error'] ?? 'unknown error',
                    ]);

                    continue;
                }

                $this->chapters->markFailed($chapterId, (string) ($detailResult['error'] ?? 'unknown error'));
                $stats->chaptersFailed++;
                $projectStats['failed']++;
                $this->logger->warn('Chapter detail failed', [
                    'chapter_id' => $chapterId,
                    'error' => $detailResult['error'] ?? 'unknown error',
                ]);

                continue;
            }

            /** @var ChapterDetail $detail */
            $detail = $detailResult['detail'];
            $isDone = $chapterRow['status'] === 'done';

            $metadataPath = $this->paths->chapterMetadataPath($format, $slug, $folder);
            $needsRework = $options->force
                || (int) $chapterRow['dirty'] === 1
                || ($options->refresh && $isDone && (
                    $detail->updatedAt !== $chapterRow['source_updated_at']
                    || $detail->imageCount() !== (int) $chapterRow['image_count']
                    || !is_file($metadataPath)
                    || !$this->chapterFilesIntact($detail, $format, $slug, $folder)
                ));

            if ($isDone && !$needsRework) {
                $stats->chaptersSkipped++;
                $projectStats['skipped']++;

                continue;
            }

            if ($needsRework) {
                $this->chapters->resetForRework($chapterId);
                $this->paths->deleteChapterDir($format, $slug, $folder);
            }

            if ($detail->imageCount() === 0) {
                $this->chapters->markEmpty(
                    $chapterId,
                    'chapter has no images',
                    $detail->updatedAt,
                );
                $stats->chaptersSkipped++;
                $projectStats['skipped']++;

                continue;
            }

            $width = max(3, strlen((string) $detail->imageCount()));
            $destinations = [];

            foreach ($detail->images as $index => $sourceFile) {
                $extension = pathinfo($sourceFile, PATHINFO_EXTENSION);
                $destination = $this->paths->chapterImagePath(
                    $format,
                    $slug,
                    $folder,
                    $index + 1,
                    $width,
                    $extension,
                );
                $destinations[] = $destination;
                $jobs[] = new DownloadJob(
                    $detail->imageUrl($sourceFile),
                    $destination,
                    $chapterId,
                );
            }

            $pending[$chapterId] = [
                'row' => $chapterRow,
                'detail' => $detail,
                'destinations' => $destinations,
                'done' => 0,
                'new' => 0,
                'failed' => 0,
                'bytes' => 0,
                'error' => null,
            ];
        }

        if ($pending === []) {
            return;
        }

        if ($jobs === []) {
            foreach ($pending as $chapterId => $state) {
                $this->finalizeChapter(
                    $project,
                    $projectRow,
                    $chapterId,
                    $state,
                    $stats,
                    $projectStats,
                );
            }

            return;
        }

        $this->chapters->markDownloading(array_keys($pending));

        $onResult = static function (DownloadResult $result) use (&$pending, $stats): void {
            $chapterId = $result->job->chapterId;

            if (!isset($pending[$chapterId])) {
                return;
            }

            if ($result->ok) {
                if (!$result->skipped) {
                    $stats->imagesDownloaded++;
                    $stats->bytesDownloaded += $result->bytes;
                    $pending[$chapterId]['bytes'] += $result->bytes;
                    $pending[$chapterId]['new']++;
                }
                $pending[$chapterId]['done']++;

                return;
            }

            $pending[$chapterId]['failed']++;
            $pending[$chapterId]['error'] ??= $result->error;
        };

        $this->downloader->download(
            $jobs,
            $onResult,
        );

        if ($this->cancellation->isRequested()) {
            // Leave incomplete chapters in "downloading" so the next run
            // resumes exactly where this one stopped.
            foreach ($pending as $chapterId => $state) {
                if ($this->allFilesPresent($state['destinations'])) {
                    $this->finalizeChapter(
                        $project,
                        $projectRow,
                        $chapterId,
                        $state,
                        $stats,
                        $projectStats,
                    );
                }
            }

            return;
        }

        foreach ($pending as $chapterId => $state) {
            $this->finalizeChapter(
                $project,
                $projectRow,
                $chapterId,
                $state,
                $stats,
                $projectStats,
            );
        }
    }

    /**
     * @param array<string, mixed> $projectRow
     * @param array{row: array<string, mixed>, detail: ChapterDetail, destinations: list<string>, done: int, new: int, failed: int, bytes: int, error: ?string} $state
     * @param array{done: int, failed: int, skipped: int, images: int, bytes: int} $projectStats
     */
    private function finalizeChapter(
        Project $project,
        array $projectRow,
        string $chapterId,
        array $state,
        RunStats $stats,
        array &$projectStats,
    ): void {
        $format = (string) $projectRow['format'];
        $slug = (string) $projectRow['slug'];
        $folder = (string) $state['row']['folder'];
        $destinations = $state['destinations'];

        if (!$this->allFilesPresent($destinations)) {
            $missing = count(array_filter(
                $destinations,
                fn (string $path): bool => !is_file($path) || (int) @filesize($path) < $this->config->minImageBytes
            ));
            $error = $state['error'] ?? sprintf('%d of %d images missing', $missing, count($destinations));
            $this->chapters->markFailed($chapterId, $error);
            $stats->chaptersFailed++;
            $projectStats['failed']++;
            $this->logger->warn('Chapter download failed', [
                'chapter_id' => $chapterId,
                'title' => $project->title,
                'folder' => $folder,
                'error' => $error,
            ]);

            return;
        }

        $bytes = 0;
        $images = [];

        foreach ($destinations as $index => $path) {
            $size = (int) @filesize($path);
            $bytes += $size;
            $images[] = [
                'file' => basename($path),
                'source' => $state['detail']->images[$index] ?? null,
                'bytes' => $size,
            ];
        }

        $this->chapters->markDone(
            $chapterId,
            $state['detail']->imageCount(),
            count($destinations),
            $bytes,
            $state['detail']->updatedAt ?? $state['row']['source_updated_at'],
            $state['detail']->releaseDate ?? $state['row']['release_date'],
        );

        FileSystem::atomicWriteJson(
            $this->paths->chapterMetadataPath($format, $slug, $folder),
            [
                'chapter_id' => $chapterId,
                'manga_id' => $project->mangaId,
                'chapter_number' => $state['detail']->chapterNumber,
                'chapter_title' => $state['detail']->title !== '' ? $state['detail']->title : $state['row']['title'],
                'release_date' => $state['detail']->releaseDate ?? $state['row']['release_date'],
                'source_updated_at' => $state['detail']->updatedAt,
                'source' => [
                    'base_url' => $state['detail']->baseUrl,
                    'path' => $state['detail']->path,
                    'files' => $state['detail']->images,
                ],
                'images' => $images,
                'downloaded_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ],
        );

        $stats->chaptersDone++;
        $projectStats['done']++;
        $projectStats['images'] += $state['new'];
        $projectStats['bytes'] += $state['bytes'];

        $this->logger->debug('Chapter downloaded', [
            'chapter_id' => $chapterId,
            'number' => $state['detail']->chapterNumber,
            'images' => count($destinations),
            'bytes' => $bytes,
        ]);
    }

    /**
     * @param array<string, mixed> $projectRow
     */
    private function ensureProjectArtifacts(Project $project, array $projectRow): void
    {
        $format = (string) $projectRow['format'];
        $slug = (string) $projectRow['slug'];
        $projectDir = $this->paths->projectDir($format, $slug);
        FileSystem::ensureDir($projectDir);

        FileSystem::atomicWriteJson(
            $this->paths->projectMetadataPath($format, $slug),
            [
                'manga_id' => $project->mangaId,
                'title' => $project->title,
                'alternative_title' => $project->alternativeTitle,
                'format' => $format,
                'slug' => $slug,
                'latest_chapter_id' => $project->latestChapterId,
                'latest_chapter_number' => $project->latestChapterNumber,
                'source_updated_at' => $project->sourceUpdatedAt,
                'folder' => $this->paths->relative($projectDir),
                'scraped_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'api' => $project->payload,
            ],
        );

        if ($this->config->skipCovers) {
            return;
        }

        $coverJobs = [];

        if ($project->coverImageUrl !== null) {
            $coverJobs[] = new DownloadJob(
                $project->coverImageUrl,
                $this->paths->coverPath($format, $slug, false),
                '__cover__',
            );
        }

        if ($project->coverPortraitUrl !== null) {
            $coverJobs[] = new DownloadJob(
                $project->coverPortraitUrl,
                $this->paths->coverPath($format, $slug, true),
                '__cover__',
            );
        }

        if ($coverJobs === []) {
            return;
        }

        $this->downloader->download($coverJobs, function (DownloadResult $result): void {
            if (!$result->ok) {
                $this->logger->debug('Cover download failed', [
                    'url' => $result->job->url,
                    'error' => $result->error,
                ]);
            }
        });
    }

    /**
     * Verifies that every image referenced by the chapter detail already exists
     * on disk. Used by --refresh so accidentally deleted images are repaired
     * instead of being silently skipped because the chapter is marked done.
     */
    private function chapterFilesIntact(
        ChapterDetail $detail,
        string $format,
        string $slug,
        string $folder,
    ): bool {
        $width = max(3, strlen((string) $detail->imageCount()));

        foreach ($detail->images as $index => $sourceFile) {
            $extension = pathinfo($sourceFile, PATHINFO_EXTENSION);
            $path = $this->paths->chapterImagePath(
                $format,
                $slug,
                $folder,
                $index + 1,
                $width,
                $extension,
            );

            clearstatcache(true, $path);

            if (!is_file($path) || (int) @filesize($path) < $this->config->minImageBytes) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $destinations
     */
    private function allFilesPresent(array $destinations): bool
    {
        foreach ($destinations as $path) {
            clearstatcache(true, $path);

            if (!is_file($path) || (int) filesize($path) < $this->config->minImageBytes) {
                return false;
            }
        }

        return true;
    }
}

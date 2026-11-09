<?php

declare(strict_types=1);

namespace Shinigami\Api;

use Shinigami\Domain\ChapterDetail;
use Shinigami\Domain\ChapterSummary;
use Shinigami\Domain\Project;
use Shinigami\Http\CurlHttpClient;
use Shinigami\Support\Logger;

final class ShinigamiApi
{
    public function __construct(
        private readonly CurlHttpClient $client,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @return list<Project>
     */
    public function listProjects(): array
    {
        $projects = [];
        $page = 1;

        do {
            $payload = $this->client->getJson('/manga/list', [
                'type' => 'project',
                'page' => $page,
                'page_size' => 9999,
                'is_update' => 'true',
                'sort' => 'latest',
                'sort_order' => 'desc',
            ]);

            $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
            $totalPage = max(1, (int) ($payload['meta']['total_page'] ?? 1));

            foreach ($data as $row) {
                if (!is_array($row) || empty($row['manga_id'])) {
                    continue;
                }

                $project = Project::fromApi($row);
                $projects[$project->mangaId] = $project;
            }

            $page++;
        } while ($page <= $totalPage && $data !== []);

        $this->logger->info('Project list fetched', [
            'projects' => count($projects),
            'pages' => $page - 1,
        ]);

        return array_values($projects);
    }

    /**
     * @return list<ChapterSummary>
     */
    public function chapterList(string $mangaId): array
    {
        $chapters = [];
        $page = 1;

        do {
            $payload = $this->client->getJson(
                '/chapter/' . rawurlencode($mangaId) . '/list',
                [
                    'page' => $page,
                    'page_size' => 999,
                    'sort_by' => 'chapter_number',
                    'sort_order' => 'desc',
                ]
            );

            $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
            $totalPage = max(1, (int) ($payload['meta']['total_page'] ?? 1));

            foreach ($data as $row) {
                if (!is_array($row) || empty($row['chapter_id'])) {
                    continue;
                }

                $chapter = ChapterSummary::fromApi($row);
                $chapters[$chapter->chapterId] = $chapter;
            }

            $page++;
        } while ($page <= $totalPage && $data !== []);

        return array_values($chapters);
    }

    /**
     * @param list<string> $chapterIds
     * @return array<string, array{ok: bool, detail?: ChapterDetail, error?: string}>
     */
    public function chapterDetails(array $chapterIds, int $concurrency = 8): array
    {
        if ($chapterIds === []) {
            return [];
        }

        $paths = [];

        foreach ($chapterIds as $chapterId) {
            $paths[$chapterId] = '/chapter/detail/' . rawurlencode($chapterId);
        }

        $responses = $this->client->getJsonMany($paths, $concurrency);
        $details = [];

        foreach ($chapterIds as $chapterId) {
            $response = $responses[$chapterId] ?? null;

            if ($response === null || !$response['ok']) {
                $details[$chapterId] = [
                    'ok' => false,
                    'error' => $response['error'] ?? 'unknown error',
                ];
                continue;
            }

            $data = $response['data']['data'] ?? null;

            if (!is_array($data)) {
                $details[$chapterId] = [
                    'ok' => false,
                    'error' => 'chapter payload is empty',
                ];
                continue;
            }

            $details[$chapterId] = [
                'ok' => true,
                'detail' => ChapterDetail::fromApi($data),
            ];
        }

        return $details;
    }

    public function chapterDetail(string $chapterId): ChapterDetail
    {
        $payload = $this->client->getJson('/chapter/detail/' . rawurlencode($chapterId));
        $data = $payload['data'] ?? null;

        if (!is_array($data)) {
            throw new \RuntimeException('Chapter detail payload is empty for ' . $chapterId);
        }

        return ChapterDetail::fromApi($data);
    }
}

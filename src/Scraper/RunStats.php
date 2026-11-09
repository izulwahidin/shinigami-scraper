<?php

declare(strict_types=1);

namespace Shinigami\Scraper;

final class RunStats
{
    public int $projectsSeen = 0;
    public int $projectsSynced = 0;
    public int $projectsSkipped = 0;
    public int $chaptersNew = 0;
    public int $chaptersDone = 0;
    public int $chaptersFailed = 0;
    public int $chaptersSkipped = 0;
    public int $imagesDownloaded = 0;
    public int $bytesDownloaded = 0;

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'projects_seen' => $this->projectsSeen,
            'projects_synced' => $this->projectsSynced,
            'projects_skipped' => $this->projectsSkipped,
            'chapters_new' => $this->chaptersNew,
            'chapters_done' => $this->chaptersDone,
            'chapters_failed' => $this->chaptersFailed,
            'chapters_skipped' => $this->chaptersSkipped,
            'images_downloaded' => $this->imagesDownloaded,
            'bytes_downloaded' => $this->bytesDownloaded,
        ];
    }
}

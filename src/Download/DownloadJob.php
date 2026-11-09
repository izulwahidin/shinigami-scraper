<?php

declare(strict_types=1);

namespace Shinigami\Download;

final class DownloadJob
{
    public function __construct(
        public readonly string $url,
        public readonly string $destination,
        public readonly string $chapterId,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace Shinigami\Download;

final class DownloadResult
{
    public function __construct(
        public readonly DownloadJob $job,
        public readonly bool $ok,
        public readonly bool $skipped,
        public readonly int $bytes = 0,
        public readonly ?string $error = null,
        public readonly int $status = 0,
    ) {
    }
}

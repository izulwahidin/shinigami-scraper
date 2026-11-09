<?php

declare(strict_types=1);

namespace Shinigami\Scraper;

final class SyncOptions
{
    public function __construct(
        public readonly string $command = 'sync',
        public readonly ?string $projectFilter = null,
        public readonly ?string $typeFilter = null,
        public readonly ?int $latest = null,
        public readonly ?int $limitProjects = null,
        public readonly bool $force = false,
        public readonly bool $refresh = false,
        public readonly bool $dryRun = false,
        public readonly ?int $concurrency = null,
        public readonly ?int $apiConcurrency = null,
    ) {
    }
}

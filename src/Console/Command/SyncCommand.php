<?php

declare(strict_types=1);

namespace Shinigami\Console\Command;

use Shinigami\Console\Command;
use Shinigami\Console\Input;
use Shinigami\Container;
use Shinigami\Scraper\SyncOptions;
use Shinigami\Support\FileSystem;
use Shinigami\Support\Output;

final class SyncCommand implements Command
{
    public function execute(Input $input, Output $output, Container $container): int
    {
        $latest = $input->intOption('latest');
        $limit = $input->intOption('limit');

        $options = new SyncOptions(
            command: $input->command(),
            projectFilter: $input->option('project'),
            typeFilter: $input->option('type'),
            latest: $latest !== null && $latest > 0 ? $latest : null,
            limitProjects: $limit !== null && $limit > 0 ? $limit : null,
            force: $input->boolOption('force'),
            refresh: $input->boolOption('refresh'),
            dryRun: $input->boolOption('dry-run'),
            concurrency: $input->intOption('concurrency'),
            apiConcurrency: $input->intOption('api-concurrency'),
        );

        $lock = $container->lock();

        if (!$lock->acquire()) {
            $container->logger()->warn('Another scraper process is already running; exiting.');
            $output->warn('Another scraper process is already running.');

            return 0;
        }

        try {
            $stats = $container->syncService()->run($options);

            $output->success(sprintf(
                'Done. Projects: %d synced, %d skipped. Chapters: %d new, %d downloaded, %d failed, %d skipped. Images: %d (%s).',
                $stats->projectsSynced,
                $stats->projectsSkipped,
                $stats->chaptersNew,
                $stats->chaptersDone,
                $stats->chaptersFailed,
                $stats->chaptersSkipped,
                $stats->imagesDownloaded,
                FileSystem::humanBytes($stats->bytesDownloaded),
            ));

            return 0;
        } finally {
            $lock->release();
        }
    }
}

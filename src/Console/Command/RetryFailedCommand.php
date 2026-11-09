<?php

declare(strict_types=1);

namespace Shinigami\Console\Command;

use Shinigami\Console\Command;
use Shinigami\Console\Input;
use Shinigami\Container;
use Shinigami\Scraper\SyncOptions;
use Shinigami\Support\FileSystem;
use Shinigami\Support\Output;

final class RetryFailedCommand implements Command
{
    public function execute(Input $input, Output $output, Container $container): int
    {
        $projectFilter = $input->option('project');
        $mangaId = null;

        if ($projectFilter !== null) {
            $row = $container->projects()->findByIdOrSlug($projectFilter);

            if ($row === null) {
                $output->error('Project not found: ' . $projectFilter);

                return 1;
            }

            $mangaId = (string) $row['manga_id'];
        }

        $latest = $input->intOption('latest');

        $options = new SyncOptions(
            command: 'retry-failed',
            projectFilter: $projectFilter,
            typeFilter: $input->option('type'),
            latest: $latest !== null && $latest > 0 ? $latest : null,
            limitProjects: null,
            force: false,
            refresh: false,
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
            $reset = $container->chapters()->resetFailed($mangaId);
            $output->info(sprintf('Reset %d failed chapter(s).', $reset));

            if ($input->boolOption('reset-only')) {
                return 0;
            }

            $stats = $container->syncService()->run($options);

            $output->success(sprintf(
                'Retry done. %d chapter(s) downloaded, %d still failing, %d image(s) (%s).',
                $stats->chaptersDone,
                $stats->chaptersFailed,
                $stats->imagesDownloaded,
                FileSystem::humanBytes($stats->bytesDownloaded),
            ));

            return $stats->chaptersFailed > 0 ? 1 : 0;
        } finally {
            $lock->release();
        }
    }
}

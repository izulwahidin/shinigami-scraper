<?php

declare(strict_types=1);

namespace Shinigami\Console\Command;

use Shinigami\Console\Command;
use Shinigami\Console\Input;
use Shinigami\Container;
use Shinigami\Support\FileSystem;
use Shinigami\Support\Output;

final class StatsCommand implements Command
{
    public function execute(Input $input, Output $output, Container $container): int
    {
        $config = $container->config;
        $projectCounts = $container->projects()->countByFormat();
        $totals = $container->chapters()->totals();
        $byStatus = $container->chapters()->statsByStatus();
        $lastRun = $container->runs()->last();

        $totalProjects = array_sum($projectCounts);

        $output->line('Shinigami scraper statistics');
        $output->line(str_repeat('-', 52));
        $output->line(sprintf('Download folder : %s', $config->downloadDir));
        $output->line(sprintf('Database        : %s', $config->databasePath()));
        $output->line(sprintf('Projects        : %s', number_format($totalProjects)));

        foreach (['manhwa', 'manhua', 'manga'] as $format) {
            if (isset($projectCounts[$format])) {
                $output->line(sprintf('  %-13s : %s', $format, number_format($projectCounts[$format])));
            }
        }

        $output->line(sprintf('Chapters        : %s', number_format($totals['chapters'])));

        foreach (['done', 'pending', 'downloading', 'failed', 'empty'] as $status) {
            if (isset($byStatus[$status])) {
                $output->line(sprintf(
                    '  %-13s : %s',
                    $status,
                    number_format($byStatus[$status]['chapters']),
                ));
            }
        }

        $output->line(sprintf('Images          : %s', number_format($totals['images'])));
        $output->line(sprintf('Size (database) : %s', FileSystem::humanBytes($totals['bytes'])));

        if ($input->boolOption('disk')) {
            $output->line(sprintf(
                'Size (on disk)  : %s',
                FileSystem::humanBytes(FileSystem::directorySize($config->downloadDir)),
            ));
        }

        if ($lastRun !== null) {
            $output->line('');
            $output->line(sprintf(
                'Last run        : #%d %s [%s] %s',
                (int) $lastRun['id'],
                (string) $lastRun['command'],
                (string) $lastRun['status'],
                (string) $lastRun['started_at'],
            ));
            $output->line(sprintf(
                '  chapters      : %s new, %s downloaded, %s failed',
                number_format((int) $lastRun['chapters_new']),
                number_format((int) $lastRun['chapters_done']),
                number_format((int) $lastRun['chapters_failed']),
            ));
            $output->line(sprintf(
                '  images        : %s (%s)',
                number_format((int) $lastRun['images_downloaded']),
                FileSystem::humanBytes((int) $lastRun['bytes_downloaded']),
            ));

            if (!empty($lastRun['error'])) {
                $output->line('  error         : ' . $lastRun['error']);
            }
        }

        return 0;
    }
}

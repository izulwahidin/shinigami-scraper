<?php

declare(strict_types=1);

namespace Shinigami\Console\Command;

use Shinigami\Console\Command;
use Shinigami\Console\Input;
use Shinigami\Container;
use Shinigami\Support\Output;

final class ProjectsCommand implements Command
{
    public function execute(Input $input, Output $output, Container $container): int
    {
        $rows = $container->projects()->listWithStats();
        $type = $input->option('type');
        $onlyFailed = $input->boolOption('failed');
        $limit = $input->intOption('limit');

        if ($type !== null) {
            $type = strtolower($type);
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => $row['format'] === $type
            ));
        }

        if ($onlyFailed) {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => (int) $row['failed'] > 0
            ));
        }

        if ($limit !== null && $limit > 0) {
            $rows = array_slice($rows, 0, $limit);
        }

        if ($input->boolOption('json')) {
            $output->line((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return 0;
        }

        $output->line(sprintf(
            '%-36s  %-6s  %6s  %6s  %6s  %s',
            'MANGA ID',
            'FORMAT',
            'CHAPS',
            'DONE',
            'FAILED',
            'TITLE',
        ));
        $output->line(str_repeat('-', 110));

        foreach ($rows as $row) {
            $output->line(sprintf(
                '%-36s  %-6s  %6d  %6d  %6d  %s',
                $row['manga_id'],
                $row['format'],
                (int) $row['chapters'],
                (int) $row['done'],
                (int) $row['failed'],
                $row['title'],
            ));
        }

        $output->line(str_repeat('-', 110));
        $output->line(sprintf('Total: %d project(s)', count($rows)));

        return 0;
    }
}

<?php

declare(strict_types=1);

namespace Shinigami\Console;

use Shinigami\Config;
use Shinigami\Console\Command\ProjectsCommand;
use Shinigami\Console\Command\RetryFailedCommand;
use Shinigami\Console\Command\StatsCommand;
use Shinigami\Console\Command\SyncCommand;
use Shinigami\Container;
use Shinigami\Support\Output;

final class Application
{
    public const VERSION = '1.0.0';

    private const COMMANDS = [
        'sync' => SyncCommand::class,
        'scrape' => SyncCommand::class,
        'update' => SyncCommand::class,
        'stats' => StatsCommand::class,
        'projects' => ProjectsCommand::class,
        'retry-failed' => RetryFailedCommand::class,
    ];

    public function __construct(private readonly string $rootDir)
    {
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $input = Input::parse(array_slice($argv, 1));
        $output = new Output($input->isQuiet(), $input->verbosity());

        if ($input->flag('version')) {
            $output->line('shinigami-scraper ' . self::VERSION);

            return 0;
        }

        if ($input->command() === 'help' || $input->flag('help') || $input->flag('h')) {
            $this->printHelp($output);

            return 0;
        }

        try {
            $config = Config::load($this->rootDir);
            $container = new Container($config, $output);
            $container->cancellation()->bindSignals();

            $class = self::COMMANDS[$input->command()] ?? null;

            if ($class === null) {
                $output->error('Unknown command: ' . $input->command());
                $this->printHelp($output);

                return 1;
            }

            /** @var Command $command */
            $command = new $class();

            return $command->execute($input, $output, $container);
        } catch (\Throwable $e) {
            $output->error($e->getMessage());

            if ($input->verbosity() >= 2) {
                $output->error($e->getTraceAsString());
            }

            return 1;
        }
    }

    private function printHelp(Output $output): void
    {
        $output->line('shinigami-scraper ' . self::VERSION);
        $output->line('');
        $output->line('Pemakaian:');
        $output->line('  php bin/scrape.php sync [opsi]        Sinkronisasi project + download chapter baru');
        $output->line('  php bin/scrape.php update [opsi]      Alias sync (cocok untuk cron)');
        $output->line('  php bin/scrape.php stats [--disk]     Statistik database dan hasil download');
        $output->line('  php bin/scrape.php projects [opsi]    Daftar project beserta progres');
        $output->line('  php bin/scrape.php retry-failed       Reset chapter gagal lalu coba lagi');
        $output->line('');
        $output->line('Opsi sync/update:');
        $output->line('  --project=ID|SLUG     Batasi ke satu project');
        $output->line('  --type=manhwa         Batasi ke manga/manhwa/manhua');
        $output->line('  --latest=N            Hanya N chapter terbaru per project');
        $output->line('  --limit=N             Maksimal N project (untuk uji coba)');
        $output->line('  --force               Download ulang chapter yang sudah selesai');
        $output->line('  --refresh             Cek ulang chapter selesai bila sumber berubah');
        $output->line('  --dry-run             Tampilkan rencana tanpa download');
        $output->line('  --concurrency=N       Jumlah download paralel (default 16)');
        $output->line('  --api-concurrency=N   Jumlah request API paralel (default 8)');
        $output->line('  -q, --quiet           Hening (tetap tercatat di log)');
        $output->line('  -v, -vv               Verbose / debug');
        $output->line('');
        $output->line('Contoh:');
        $output->line('  php bin/scrape.php sync --type=manhwa --latest=5');
        $output->line('  php bin/scrape.php sync --project=f9b2bafd-64d6-4eb7-86df-0cc9684ffc56 --force');
        $output->line('  php bin/scrape.php projects --failed');
        $output->line('  php bin/scrape.php stats --disk');
    }
}

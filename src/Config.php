<?php

declare(strict_types=1);

namespace Shinigami;

use RuntimeException;

final class Config
{
    private function __construct(
        public readonly string $rootDir,
        public readonly string $apiBaseUrl,
        public readonly string $apiReferer,
        public readonly string $apiUserAgent,
        public readonly int $apiTimeout,
        public readonly int $apiConnectTimeout,
        public readonly int $apiRetries,
        public readonly int $apiRetryDelayMs,
        public readonly int $apiConcurrency,
        public readonly int $apiDelayMs,
        public readonly string $downloadDir,
        public readonly ?string $downloadStagingDir,
        public readonly bool $downloadFastMove,
        public readonly int $downloadConcurrency,
        public readonly int $downloadRetries,
        public readonly int $downloadRetryDelayMs,
        public readonly int $downloadTimeout,
        public readonly int $downloadConnectTimeout,
        public readonly int $minImageBytes,
        public readonly int $chapterChunk,
        public readonly bool $skipCovers,
        public readonly string $storageDir,
        public readonly string $logLevel,
        public readonly int $logMaxBytes,
    ) {
    }

    public static function load(string $rootDir): self
    {
        $file = $rootDir . '/config/config.php';

        if (!is_file($file)) {
            throw new RuntimeException(sprintf('Configuration file not found: %s', $file));
        }

        /** @var array<string, mixed> $config */
        $config = require $file;

        $api = $config['api'] ?? [];
        $download = $config['download'] ?? [];
        $storage = $config['storage'] ?? [];
        $log = $config['log'] ?? [];

        $downloadDir = self::normalizeDir((string) ($download['dir'] ?? $rootDir . '/downloads'));
        $storageDir = self::normalizeDir((string) ($storage['dir'] ?? $rootDir . '/storage'));
        $stagingDir = self::resolveStagingDir($downloadDir, $download['staging_dir'] ?? '');

        $configObject = new self(
            rootDir: $rootDir,
            apiBaseUrl: rtrim((string) ($api['base_url'] ?? 'https://api.shngm.io/v1'), '/'),
            apiReferer: (string) ($api['referer'] ?? 'https://11.shinigami.asia/'),
            apiUserAgent: (string) ($api['user_agent'] ?? 'Mozilla/5.0'),
            apiTimeout: max(1, (int) ($api['timeout'] ?? 30)),
            apiConnectTimeout: max(1, (int) ($api['connect_timeout'] ?? 10)),
            apiRetries: max(0, (int) ($api['retries'] ?? 3)),
            apiRetryDelayMs: max(0, (int) ($api['retry_delay_ms'] ?? 500)),
            apiConcurrency: max(1, (int) ($api['concurrency'] ?? 8)),
            apiDelayMs: max(0, (int) ($api['delay_ms'] ?? 25)),
            downloadDir: $downloadDir,
            downloadStagingDir: $stagingDir,
            downloadFastMove: (bool) ($download['fast_move'] ?? true),
            downloadConcurrency: max(1, (int) ($download['concurrency'] ?? 16)),
            downloadRetries: max(0, (int) ($download['retries'] ?? 3)),
            downloadRetryDelayMs: max(0, (int) ($download['retry_delay_ms'] ?? 400)),
            downloadTimeout: max(1, (int) ($download['timeout'] ?? 60)),
            downloadConnectTimeout: max(1, (int) ($download['connect_timeout'] ?? 10)),
            minImageBytes: max(1, (int) ($download['min_bytes'] ?? 256)),
            chapterChunk: max(1, (int) ($download['chapter_chunk'] ?? 40)),
            skipCovers: (bool) ($download['skip_covers'] ?? false),
            storageDir: $storageDir,
            logLevel: strtolower((string) ($log['level'] ?? 'info')),
            logMaxBytes: max(1024, (int) ($log['max_bytes'] ?? 10 * 1024 * 1024)),
        );

        $configObject->ensureDirectories();

        return $configObject;
    }

    public function databasePath(): string
    {
        return $this->storageDir . '/shinigami.sqlite';
    }

    public function logPath(): string
    {
        return $this->storageDir . '/logs/scraper.log';
    }

    public function lockPath(): string
    {
        return $this->storageDir . '/scraper.lock';
    }

    public function schemaPath(): string
    {
        return $this->rootDir . '/resources/schema.sql';
    }

    private function ensureDirectories(): void
    {
        $directories = [
            $this->downloadDir,
            $this->storageDir,
            $this->storageDir . '/logs',
        ];

        if ($this->downloadStagingDir !== null) {
            $directories[] = $this->downloadStagingDir;
        }

        foreach ($directories as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException(sprintf('Unable to create directory: %s', $dir));
            }

            if (!is_writable($dir)) {
                throw new RuntimeException(sprintf('Directory is not writable: %s', $dir));
            }
        }
    }

    private static function normalizeDir(string $dir): string
    {
        return rtrim($dir, '/\\');
    }

    /**
     * Downloads are written to the system temp folder when it lives on a
     * different device than the destination. This makes scrapers running
     * from WSL's /mnt/* drives several times faster, while normal Linux
     * installs keep writing directly to their destination.
     */
    private static function resolveStagingDir(string $downloadDir, mixed $configured): ?string
    {
        if (is_string($configured) && in_array(strtolower($configured), ['off', 'false', '0', 'no'], true)) {
            return null;
        }

        if (is_string($configured) && trim($configured) !== '') {
            return self::normalizeDir($configured);
        }

        if (!is_dir($downloadDir)) {
            @mkdir($downloadDir, 0775, true);
        }

        $tmpRoot = rtrim(sys_get_temp_dir(), '/\\');

        if ($tmpRoot === '') {
            return null;
        }

        if (self::sameDevice($downloadDir, $tmpRoot)) {
            return null;
        }

        $staging = $tmpRoot . '/shinigami-scraper-' . (function_exists('posix_geteuid') ? posix_geteuid() : 'user');

        if (!is_dir($staging) && !@mkdir($staging, 0775, true) && !is_dir($staging)) {
            return null;
        }

        return $staging;
    }

    private static function sameDevice(string $a, string $b): bool
    {
        $statA = @stat($a);
        $statB = @stat($b);

        return $statA !== false && $statB !== false && ($statA['dev'] ?? null) === ($statB['dev'] ?? null);
    }
}

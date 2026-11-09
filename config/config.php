<?php

declare(strict_types=1);

/**
 * Default configuration. Every value can be overridden with an environment
 * variable, which makes cron/systemd deployments easy without editing code.
 */

$env = static function (string $key, mixed $default): mixed {
    $value = getenv($key);

    if ($value === false || $value === '') {
        return $default;
    }

    return match (true) {
        is_bool($default) => filter_var($value, FILTER_VALIDATE_BOOL),
        is_int($default) => (int) $value,
        is_float($default) => (float) $value,
        default => $value,
    };
};

$root = dirname(__DIR__);

return [
    'api' => [
        'base_url' => $env('SHINIGAMI_API_BASE', 'https://api.shngm.io/v1'),
        'referer' => $env('SHINIGAMI_REFERER', 'https://11.shinigami.asia/'),
        'user_agent' => $env(
            'SHINIGAMI_USER_AGENT',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
        ),
        'timeout' => $env('SHINIGAMI_API_TIMEOUT', 30),
        'connect_timeout' => $env('SHINIGAMI_CONNECT_TIMEOUT', 10),
        'retries' => $env('SHINIGAMI_API_RETRIES', 3),
        'retry_delay_ms' => $env('SHINIGAMI_API_RETRY_DELAY_MS', 500),
        'concurrency' => $env('SHINIGAMI_API_CONCURRENCY', 8),
        'delay_ms' => $env('SHINIGAMI_API_DELAY_MS', 25),
    ],

    'download' => [
        'dir' => $env('SHINIGAMI_DOWNLOAD_DIR', $root . '/downloads'),
        // Temp download folder. Empty = auto (use system temp when it is on a
        // different/faster filesystem, e.g. WSL /mnt/d). Set to "off" to disable.
        'staging_dir' => $env('SHINIGAMI_STAGING_DIR', ''),
        // Use `mv` for cross-device staging moves when available. Native
        // copy (sendfile/copy_file_range) is much faster than PHP streams.
        'fast_move' => $env('SHINIGAMI_FAST_MOVE', true),
        'concurrency' => $env('SHINIGAMI_CONCURRENCY', 16),
        'retries' => $env('SHINIGAMI_DOWNLOAD_RETRIES', 3),
        'retry_delay_ms' => $env('SHINIGAMI_DOWNLOAD_RETRY_DELAY_MS', 400),
        'timeout' => $env('SHINIGAMI_DOWNLOAD_TIMEOUT', 60),
        'connect_timeout' => $env('SHINIGAMI_CONNECT_TIMEOUT', 10),
        'min_bytes' => $env('SHINIGAMI_MIN_IMAGE_BYTES', 256),
        'chapter_chunk' => $env('SHINIGAMI_CHAPTER_CHUNK', 40),
        'skip_covers' => $env('SHINIGAMI_SKIP_COVERS', false),
    ],

    'storage' => [
        'dir' => $env('SHINIGAMI_STORAGE_DIR', $root . '/storage'),
    ],

    'log' => [
        'level' => $env('SHINIGAMI_LOG_LEVEL', 'info'),
        'max_bytes' => $env('SHINIGAMI_LOG_MAX_BYTES', 10 * 1024 * 1024),
    ],
];

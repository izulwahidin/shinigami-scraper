<?php

declare(strict_types=1);

namespace Shinigami\Support;

final class Logger
{
    private const LEVELS = [
        'debug' => 100,
        'info' => 200,
        'warn' => 300,
        'error' => 400,
    ];

    private readonly int $minLevel;

    public function __construct(
        private readonly string $path,
        private readonly Output $output,
        string $level = 'info',
        private readonly int $maxBytes = 10 * 1024 * 1024,
    ) {
        $this->minLevel = self::LEVELS[strtolower($level)] ?? self::LEVELS['info'];
    }

    public function debug(string $message, array $context = []): void
    {
        $this->write('debug', $message, $context);
        $this->output->debug($this->formatContext($message, $context));
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
        $this->output->info($this->formatContext($message, $context));
    }

    public function warn(string $message, array $context = []): void
    {
        $this->write('warn', $message, $context);
        $this->output->warn($this->formatContext($message, $context));
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
        $this->output->error($this->formatContext($message, $context));
    }

    private function write(string $level, string $message, array $context): void
    {
        if ((self::LEVELS[$level] ?? 0) < $this->minLevel) {
            return;
        }

        $line = sprintf(
            '[%s] %-5s %s%s%s',
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            $context === [] ? '' : ' ' . $this->encodeContext($context),
            PHP_EOL,
        );

        $this->rotateIfNeeded();

        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        @file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX);
    }

    private function rotateIfNeeded(): void
    {
        if (!is_file($this->path)) {
            return;
        }

        clearstatcache(true, $this->path);

        if (filesize($this->path) < $this->maxBytes) {
            return;
        }

        @rename($this->path, $this->path . '.1');
    }

    private function formatContext(string $message, array $context): string
    {
        return $context === [] ? $message : $message . ' ' . $this->encodeContext($context);
    }

    private function encodeContext(array $context): string
    {
        $json = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? '{}' : $json;
    }
}

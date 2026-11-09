<?php

declare(strict_types=1);

namespace Shinigami\Support;

final class Output
{
    private bool $decorated;

    public function __construct(
        private readonly bool $quiet = false,
        private readonly int $verbosity = 0,
        ?bool $decorated = null,
    ) {
        $this->decorated = $decorated ?? (function_exists('stream_isatty') && @stream_isatty(STDOUT));
    }

    public function line(string $message = ''): void
    {
        if (!$this->quiet) {
            fwrite(STDOUT, $message . PHP_EOL);
        }
    }

    public function info(string $message): void
    {
        $this->line($message);
    }

    public function success(string $message): void
    {
        $this->line($this->color($message, '32'));
    }

    public function warn(string $message): void
    {
        fwrite(STDERR, $this->color('[warn] ' . $message, '33') . PHP_EOL);
    }

    public function error(string $message): void
    {
        fwrite(STDERR, $this->color('[error] ' . $message, '31') . PHP_EOL);
    }

    public function verbose(string $message): void
    {
        if ($this->verbosity >= 1) {
            $this->line($message);
        }
    }

    public function debug(string $message): void
    {
        if ($this->verbosity >= 2) {
            fwrite(STDERR, $this->color('[debug] ' . $message, '90') . PHP_EOL);
        }
    }

    public function isQuiet(): bool
    {
        return $this->quiet;
    }

    public function verbosity(): int
    {
        return $this->verbosity;
    }

    private function color(string $text, string $code): string
    {
        if (!$this->decorated) {
            return $text;
        }

        return "\033[" . $code . 'm' . $text . "\033[0m";
    }
}

<?php

declare(strict_types=1);

namespace Shinigami\Console;

use InvalidArgumentException;

final class Input
{
    /**
     * @param array<string, string> $options
     * @param array<string, bool> $flags
     * @param list<string> $arguments
     */
    private function __construct(
        private readonly ?string $command,
        private readonly array $options,
        private readonly array $flags,
        private readonly array $arguments,
        private readonly int $verbosity,
        private readonly bool $quiet,
    ) {
    }

    /**
     * @param list<string> $argv
     */
    public static function parse(array $argv): self
    {
        $command = null;
        $options = [];
        $flags = [];
        $arguments = [];
        $verbosity = 0;
        $quiet = false;
        $count = count($argv);

        for ($i = 0; $i < $count; $i++) {
            $arg = $argv[$i];

            if ($arg === '--') {
                for ($j = $i + 1; $j < $count; $j++) {
                    $arguments[] = $argv[$j];
                }
                break;
            }

            if (str_starts_with($arg, '--')) {
                $body = substr($arg, 2);

                if (str_contains($body, '=')) {
                    [$name, $value] = explode('=', $body, 2);
                    $options[$name] = $value;
                    continue;
                }

                $next = $argv[$i + 1] ?? null;

                if ($next !== null && !str_starts_with($next, '-')) {
                    $options[$body] = $next;
                    $i++;
                } else {
                    $flags[$body] = true;
                }

                continue;
            }

            if ($arg === '-q' || $arg === '--quiet') {
                $quiet = true;
                continue;
            }

            if (preg_match('/^-v+$/', $arg) === 1) {
                $verbosity += strlen($arg) - 1;
                continue;
            }

            if (str_starts_with($arg, '-')) {
                $flags[substr($arg, 1)] = true;
                continue;
            }

            if ($command === null) {
                $command = $arg;
            } else {
                $arguments[] = $arg;
            }
        }

        if (isset($flags['quiet'])) {
            $quiet = true;
        }

        if (isset($flags['verbose'])) {
            $verbosity = max(1, $verbosity);
        }

        return new self($command, $options, $flags, $arguments, $verbosity, $quiet);
    }

    public function command(): string
    {
        return $this->command ?? 'help';
    }

    public function option(string $name, ?string $default = null): ?string
    {
        return $this->options[$name] ?? $default;
    }

    public function intOption(string $name): ?int
    {
        $value = $this->option($name);

        if ($value === null || $value === '') {
            return null;
        }

        if (!preg_match('/^-?\d+$/', $value)) {
            throw new InvalidArgumentException(sprintf('Option --%s expects an integer, got "%s".', $name, $value));
        }

        return (int) $value;
    }

    public function boolOption(string $name): bool
    {
        if (isset($this->flags[$name])) {
            return true;
        }

        $value = strtolower((string) $this->option($name, ''));

        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    public function flag(string $name): bool
    {
        return isset($this->flags[$name]);
    }

    public function argument(int $index, ?string $default = null): ?string
    {
        return $this->arguments[$index] ?? $default;
    }

    /**
     * @return list<string>
     */
    public function arguments(): array
    {
        return $this->arguments;
    }

    public function verbosity(): int
    {
        return $this->verbosity;
    }

    public function isQuiet(): bool
    {
        return $this->quiet;
    }
}

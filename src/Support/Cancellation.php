<?php

declare(strict_types=1);

namespace Shinigami\Support;

final class Cancellation
{
    private bool $requested = false;

    public function bindSignals(): void
    {
        if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);

        foreach ([SIGINT, SIGTERM] as $signal) {
            pcntl_signal($signal, function (): void {
                $this->requested = true;
                fwrite(STDERR, PHP_EOL . '[warn] Stop requested, finishing in-flight work...' . PHP_EOL);
            });
        }
    }

    public function request(): void
    {
        $this->requested = true;
    }

    public function isRequested(): bool
    {
        return $this->requested;
    }
}

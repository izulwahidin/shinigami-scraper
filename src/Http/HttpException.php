<?php

declare(strict_types=1);

namespace Shinigami\Http;

use RuntimeException;

final class HttpException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly string $url = '',
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message);
    }
}

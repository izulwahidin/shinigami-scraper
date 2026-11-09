<?php

declare(strict_types=1);

namespace Shinigami\Console;

use Shinigami\Container;
use Shinigami\Support\Output;

interface Command
{
    public function execute(Input $input, Output $output, Container $container): int;
}

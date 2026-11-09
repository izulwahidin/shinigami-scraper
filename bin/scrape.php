#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = require dirname(__DIR__) . '/bootstrap.php';

$application = new Shinigami\Console\Application($root);

exit($application->run($_SERVER['argv']));

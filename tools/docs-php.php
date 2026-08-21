<?php

declare(strict_types=1);

namespace Greenlight;

use Greenlight\Documentation\PhpExample\Command;

require_once __DIR__ . '/../vendor/autoload.php';

/** @var list<string> $arguments */
$arguments = $_SERVER['argv'] ?? [];

exit(Command::run($arguments));

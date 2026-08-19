<?php

declare(strict_types=1);

namespace Greenlight;

use Greenlight\Documentation\PhpExample\Command;

require_once __DIR__ . '/../vendor/autoload.php';

exit(Command::run($argv));

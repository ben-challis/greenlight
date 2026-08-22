<?php

declare(strict_types=1);

use Greenlight\Config\GreenlightConfig;
use Greenlight\Tests\Fixture\HarnessDisposalRun\FailingRunServicePlugin;

return GreenlightConfig::create()
    ->paths([__DIR__ . '/../HarnessDisposalMatrix'])
    ->plugins(static fn(): FailingRunServicePlugin => new FailingRunServicePlugin());

<?php

declare(strict_types=1);

use Greenlight\Config\GreenlightConfig;
use Greenlight\Tests\Fixture\HarnessDisposalRun\FailingRunServicePlugin;

return GreenlightConfig::create()
    ->paths([__DIR__ . '/../HarnessDisposalMatrix'])
    ->workers(count: 2, recycleAfterTests: 1)
    ->plugins(static fn(): FailingRunServicePlugin => new FailingRunServicePlugin());

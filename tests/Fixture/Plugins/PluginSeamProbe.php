<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Plugins;

final readonly class PluginSeamProbe
{
    public function __construct(
        public string $workerProperty,
        public string $integrationResource,
    ) {}
}

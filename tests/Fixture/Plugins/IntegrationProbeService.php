<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Plugins;

final readonly class IntegrationProbeService
{
    public function __construct(
        public int $channel,
        public string $resourceFile,
        public string $secret,
    ) {}
}

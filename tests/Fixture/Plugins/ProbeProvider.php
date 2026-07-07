<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Plugins;

use Greenlight\Harness\Scope;
use Greenlight\Harness\ServiceDefinition;
use Greenlight\Plugin\HarnessProvider;
use Greenlight\Tests\Fixture\Lifecycle\Services\ServiceProbe;

final readonly class ProbeProvider implements HarnessProvider
{
    #[\Override]
    public function services(): array
    {
        return [
            new ServiceDefinition(ServiceProbe::class, Scope::PerTest, static fn(): ServiceProbe => new ServiceProbe()),
        ];
    }
}

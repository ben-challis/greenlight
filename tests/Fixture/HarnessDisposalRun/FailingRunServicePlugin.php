<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\HarnessDisposalRun;

use Greenlight\Harness\Scope;
use Greenlight\Harness\ServiceDefinition;
use Greenlight\Plugin\HarnessProvider;
use Greenlight\Tests\Fixture\HarnessDisposalMatrix\FailingHarnessService;

final readonly class FailingRunServicePlugin implements HarnessProvider
{
    #[\Override]
    public function services(): array
    {
        return [
            new ServiceDefinition(
                FailingHarnessService::class,
                Scope::PerWorker,
                static fn(): FailingHarnessService => new FailingHarnessService(),
            ),
        ];
    }
}

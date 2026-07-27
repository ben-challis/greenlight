<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Lifecycle\PerTestDisposeFails;

use Greenlight\Attribute\Test;

final readonly class PerTestDisposeFailsTest
{
    public function __construct(private FailingPerTestDisposal $probe) {}

    #[Test]
    public function passesBeforeDisposal(): void
    {
        $this->probe->touch();
    }
}

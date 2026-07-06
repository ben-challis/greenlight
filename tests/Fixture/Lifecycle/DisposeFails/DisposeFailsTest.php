<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Lifecycle\DisposeFails;

use Greenlight\Attribute\Test;

final readonly class DisposeFailsTest
{
    public function __construct(
        private FailingDisposalProbe $probe,
    ) {}

    #[Test]
    public function first(): void
    {
        $this->probe->touch();
    }

    #[Test]
    public function last(): void
    {
        $this->probe->touch();
    }
}
